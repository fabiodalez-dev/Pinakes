<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Controllers\ReservationManager;
use App\Models\CopyRepository;
use App\Services\ReservationReassignmentService;
use App\Support\DataIntegrity;
use App\Support\SecureLogger;
use mysqli;

class CopyController
{
    /**
     * SECURITY: Validate and sanitize HTTP_REFERER to prevent open redirect
     */
    private function safeReferer(?string $default = null): string
    {
        // Delegate to the single audited implementation. localPath() uses only
        // the referer's path (never its scheme/host), which is strictly safer
        // than the previous same-host comparison and port-agnostic.
        return \App\Support\RefererGuard::localPath(
            (string) ($_SERVER['HTTP_REFERER'] ?? ''),
            // Admin routes are fixed English literals — never routed through the
            // i18n system (CLAUDE.md rule #4 / decision #145).
            $default ?? '/admin/books'
        );
    }

    private function adminBookPath(int $bookId): string
    {
        // Fixed admin literal, not an i18n route (CLAUDE.md rule #4).
        return '/admin/books/' . $bookId;
    }

    /**
     * Whether a copy is currently "held" by any HOLDING commitment: an active loan
     * (prenotato/da_ritirare/in_corso/in_ritardo) or a copy-bound pending
     * reservation (attivo=0, stato='pendente', copia_id NOT NULL). Single source of
     * truth for the copy-availability predicate used across byCode()/updateCopy().
     */
    private function isCopyHeld(\mysqli $db, int $copyId): bool
    {
        $stmt = $db->prepare("
            SELECT 1 FROM prestiti
            WHERE copia_id = ?
              AND ( (attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                    OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL) )
            LIMIT 1
        ");
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $held = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $held;
    }

    /**
     * Resolve a copy by its numero_inventario (per-copy code) and report whether
     * it is loanable right now. Returns JSON:
     *   {found:false}                                  when no such code exists
     *   {found:true, copy_id, libro_id, titolo, sottotitolo, stato, available:bool}
     *
     * "available" mirrors the loan-availability rules used elsewhere: a copy is
     * loanable now only if its state is 'disponibile' AND no active/holding loan
     * (or copy-bound pending reservation) currently holds it.
     */
    public function byCode(Request $request, Response $response, mysqli $db): Response
    {
        $params = $request->getQueryParams();
        $rawCode = $params['code'] ?? '';
        $code = is_string($rawCode) ? trim($rawCode) : '';

        if ($code === '') {
            $response->getBody()->write((string) json_encode(['found' => false]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // `copie` has no deleted_at — filter the soft-delete on the joined book.
        $stmt = $db->prepare("
            SELECT c.id AS copy_id, c.libro_id, c.stato, l.titolo, l.sottotitolo
            FROM copie c
            JOIN libri l ON l.id = c.libro_id
            WHERE c.numero_inventario = ? AND l.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $response->getBody()->write((string) json_encode(['found' => false]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $copyId = (int) $row['copy_id'];
        $available = false;
        if ($row['stato'] === 'disponibile') {
            $available = !$this->isCopyHeld($db, $copyId);
        }

        $response->getBody()->write((string) json_encode([
            'found'    => true,
            'copy_id'  => $copyId,
            'libro_id' => (int) $row['libro_id'],
            'titolo'   => $row['titolo'],
            'sottotitolo' => (string) ($row['sottotitolo'] ?? ''),
            'stato'    => $row['stato'],
            'available' => $available,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Aggiorna lo stato di una singola copia
     */
    public function updateCopy(Request $request, Response $response, mysqli $db, int $copyId): Response
    {
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware

        $statoInput = $data['stato'] ?? 'disponibile';
        $noteInput = $data['note'] ?? '';
        if (!is_string($statoInput) || !is_string($noteInput)) {
            $_SESSION['error_message'] = __('Impossibile aggiornare la copia senza lasciare dati incoerenti. Nessuna modifica è stata salvata.');
            return $response->withHeader('Location', $this->safeReferer())->withStatus(302);
        }
        $stato = $statoInput;
        $note = $this->sanitizeNote($noteInput);

        // Validazione stato (deve corrispondere all'enum in copie.stato)
        $statiValidi = ['disponibile', 'prestato', 'prenotato', 'manutenzione', 'in_restauro', 'perso', 'danneggiato', 'in_trasferimento'];
        if (!in_array($stato, $statiValidi, true)) {
            $_SESSION['error_message'] = __('Stato non valido.');
            return $response->withHeader('Location', $this->safeReferer())->withStatus(302);
        }

        // Recupera la copia per ottenere il libro_id
        $stmt = $db->prepare("SELECT libro_id, stato FROM copie WHERE id = ?");
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $copy = $result->fetch_assoc();
        $stmt->close();

        if (!$copy) {
            $_SESSION['error_message'] = __('Copia non trovata.');
            return $response->withHeader('Location', $this->safeReferer())->withStatus(302);
        }

        $libroId = (int) $copy['libro_id'];
        $statoCorrente = $copy['stato'];

        // Prestito "in carico" su questa copia (in_corso/in_ritardo): usato per la
        // chiusura automatica quando la copia torna 'disponibile'.
        $stmt = $db->prepare("
            SELECT id, note
            FROM prestiti
            WHERE copia_id = ? AND attivo = 1 AND stato IN ('in_corso', 'in_ritardo')
        ");
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $prestito = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // La copia è "trattenuta" da QUALSIASI impegno HOLDING — prestito attivo
        // (prenotato/da_ritirare/in_corso/in_ritardo) o pendente-con-copia? Blocca il
        // passaggio a stati non prestabili senza prima liberarla (I10/BUG7a/D12):
        // anche un ritiro in attesa o una prenotazione futura trattengono la copia.
        $copyHeld = $this->isCopyHeld($db, $copyId);

        // GESTIONE CAMBIO STATO -> "PRESTATO"
        // Non permettere cambio diretto a "prestato", deve usare il sistema prestiti
        if ($stato === 'prestato' && $statoCorrente !== 'prestato') {
            $_SESSION['error_message'] = __('Per prestare una copia, utilizza il sistema Prestiti dalla sezione dedicata. Non è possibile impostare manualmente lo stato "Prestato".');
            return $response->withHeader('Location', url($this->adminBookPath($libroId)))->withStatus(302);
        }

        // GESTIONE CAMBIO STATO DA "PRESTATO" A "DISPONIBILE"
        // Se c'è un prestito in carico e si vuole rendere disponibile, delega al
        // SOLO flusso canonico di restituzione. Questo garantisce insieme: ordine
        // lock libro->prestito, riassegnazione copia, promozione coda, ricalcolo,
        // wishlist e mail di conferma, senza una seconda state machine divergente.
        if ($prestito && $statoCorrente === 'prestato' && $stato === 'disponibile') {
            // processReturn ALWAYS overwrites prestiti.note with the value passed.
            // The copy-edit form's `note` is a note about the COPY, not the loan —
            // forwarding it blindly (or an empty string) would silently wipe the
            // loan's own note. Preserve the loan note unless the operator actually
            // typed a return note here.
            $returnNote = ($note !== '') ? $note : (string) ($prestito['note'] ?? '');
            $delegated = $request->withParsedBody([
                'stato' => 'restituito',
                'note' => $returnNote,
                'redirect_to' => $this->adminBookPath($libroId),
                'csrf_token' => $data['csrf_token'] ?? '',
            ]);
            return (new PrestitiController())->processReturn($delegated, $response, $db, (int) $prestito['id']);
        } else {
            // GESTIONE ALTRI STATI
            // A copy physically outside the library must go through the return
            // workflow (which records lost/damaged outcomes). Future holds on a
            // copy still in the library may instead be reassigned atomically below.
            if ($copyHeld && $prestito) {
                $_SESSION['error_message'] = __('La copia è fisicamente in prestito: registra prima la restituzione o l’esito perso/danneggiato dal sistema Prestiti.');
                return $response->withHeader('Location', url($this->adminBookPath($libroId)))->withStatus(302);
            }

            // L'aggiornamento avviene sotto lock del libro (ordine di lock canonico,
            // come store/approveLoan) con ri-verifica HOLDING atomica: così una
            // creazione prestito/prenotazione concorrente non può inserirsi tra il
            // check e l'UPDATE lasciando la copia non-prestabile ma ancora impegnata.
            $db->begin_transaction();
            try {
                // Lock + soft-delete guard: su un libro rimosso dal catalogo NON si
                // committa stato operativo sulle copie (fail-closed, AND deleted_at IS NULL).
                $lockBook = $db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
                $lockBook->bind_param('i', $libroId);
                $lockBook->execute();
                $bookLocked = (bool) $lockBook->get_result()->fetch_row();
                $lockBook->close();
                if (!$bookLocked) {
                    $db->rollback();
                    $_SESSION['error_message'] = __('Libro non trovato o non più disponibile.');
                    return $response->withHeader('Location', url($this->adminBookPath($libroId)))->withStatus(302);
                }

                // Recheck only physical possession after the book lock. Scheduled
                // holds are deliberately allowed and will be moved below.
                $physicalStmt = $db->prepare("SELECT 1 FROM prestiti WHERE copia_id = ? AND attivo = 1 AND stato IN ('in_corso','in_ritardo') LIMIT 1 FOR UPDATE");
                $physicalStmt->bind_param('i', $copyId);
                $physicalStmt->execute();
                $physicallyOut = (bool) $physicalStmt->get_result()->fetch_row();
                $physicalStmt->close();
                if ($physicallyOut) {
                    $db->rollback();
                    $_SESSION['error_message'] = __('La copia è fisicamente in prestito: usa il flusso di restituzione.');
                    return $response->withHeader('Location', url($this->adminBookPath($libroId)))->withStatus(302);
                }

                $stmt = $db->prepare("UPDATE copie SET stato = ?, note = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('ssi', $stato, $note, $copyId);
                $stmt->execute();
                $stmt->close();

                $reassignmentService = new \App\Services\ReservationReassignmentService($db);
                $reassignmentService->setExternalTransaction(true);
                $reservationManager = null;

                if (in_array($stato, ['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'], true)) {
                    // One copy may host several non-overlapping future holds.
                    for ($guard = 0; $guard < 1000; $guard++) {
                        $held = $db->prepare("
                            SELECT 1 FROM prestiti
                            WHERE copia_id = ? AND (
                                (attivo = 1 AND stato IN ('prenotato','da_ritirare'))
                                OR (attivo = 0 AND stato = 'pendente' AND origine = 'prenotazione')
                            )
                            LIMIT 1
                        ");
                        $held->bind_param('i', $copyId);
                        $held->execute();
                        $stillHeld = (bool) $held->get_result()->fetch_row();
                        $held->close();
                        if (!$stillHeld) {
                            break;
                        }
                        $reassignmentService->reassignOnCopyLost($copyId);
                    }
                } elseif ($stato === 'disponibile') {
                    $reassignmentService->reassignOnReturn($copyId);
                    $reservationManager = new \App\Controllers\ReservationManager($db);
                    $reservationManager->setExternalTransaction(true);
                    for ($guard = 0; $guard < 1000 && $reservationManager->processBookAvailability($libroId); $guard++) {
                        // Promote every date-eligible reservation allowed by capacity.
                    }
                }

                $integrity = new \App\Support\DataIntegrity($db);
                if (!$integrity->recalculateBookAvailability($libroId, insideTransaction: true)) {
                    throw new \RuntimeException('Impossibile ricalcolare la disponibilità del libro.');
                }

                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                SecureLogger::error(__('Errore gestione cambio stato copia'), [
                    'copia_id' => $copyId,
                    'stato' => $stato,
                    'error' => $e->getMessage()
                ]);
                $_SESSION['error_message'] = __('Impossibile aggiornare la copia senza lasciare dati incoerenti. Nessuna modifica è stata salvata.');
                return $response->withHeader('Location', url($this->adminBookPath($libroId)))->withStatus(302);
            }

            try {
                $reassignmentService->flushDeferredNotifications();
                if ($reservationManager !== null) {
                    $reservationManager->flushDeferredNotifications();
                }
            } catch (\Throwable $e) {
                SecureLogger::warning(__('Invio notifica cambio stato copia fallito'), ['error' => $e->getMessage()]);
            }
        }

        if (!isset($_SESSION['success_message'])) {
            $_SESSION['success_message'] = __('Stato della copia aggiornato con successo.');
        }
        return $response->withHeader('Location', url($this->adminBookPath($libroId)))->withStatus(302);
    }

    /**
     * Crea una nuova copia fisica per un libro, direttamente dalla scheda.
     *
     * A copy is never *created* in a loan state here — 'prestato'/'prenotato'
     * belong to the Prestiti system — but creating an available copy may promote a
     * waiting reservation, which sets that new copy to 'prenotato' before commit.
     * A copy created out of circulation ('perso'/'danneggiato'/'manutenzione'/
     * 'in_restauro'/'in_trasferimento') is excluded from copie_totali by the
     * availability recalculation, so marking a copy lost reduces the book's total.
     */
    public function createCopy(Request $request, Response $response, mysqli $db, int $bookId): Response
    {
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware

        // Only physical statuses a copy can be created in — loan states are
        // managed by the Prestiti system, never set here.
        $statoInput = $data['stato'] ?? 'disponibile';
        $noteInput = $data['note'] ?? '';
        $numeroInput = $data['numero_inventario'] ?? '';
        if (!is_string($statoInput) || !is_string($noteInput) || !is_string($numeroInput)) {
            $_SESSION['error_message'] = __('Impossibile aggiungere la copia.');
            return $response->withHeader('Location', url($this->adminBookPath($bookId)))->withStatus(302);
        }
        $stato = $statoInput;
        $statiValidi = ['disponibile', 'manutenzione', 'in_restauro', 'perso', 'danneggiato', 'in_trasferimento'];
        if (!in_array($stato, $statiValidi, true)) {
            $_SESSION['error_message'] = __('Stato non valido.');
            return $response->withHeader('Location', url($this->adminBookPath($bookId)))->withStatus(302);
        }
        $note = $this->sanitizeNote($noteInput);

        // Inventory code: honour an explicit value (must be unique), otherwise
        // auto-allocate the next collision-free "{base}-C{N}" like book creation.
        $numero = trim($numeroInput);
        if ($numero !== '') {
            $numero = trim((string) preg_replace('/[\x00-\x1F]/', '', $numero));
            if (mb_strlen($numero) > 100) {
                $numero = mb_substr($numero, 0, 100);
            }
        }

        $repo = new CopyRepository($db);
        $reassignmentService = null;
        $reservationManager = null;
        $transactionStarted = false;

        try {
            // Keep the same canonical lock order used by circulation writes:
            // book first, then copies/loans. Copy creation, queue processing and
            // derived counters must become visible as one atomic change.
            $db->begin_transaction();
            $transactionStarted = true;

            $stmt = $db->prepare("SELECT id, numero_inventario FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $book = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$book) {
                $db->rollback();
                $transactionStarted = false;
                $_SESSION['error_message'] = __('Libro non trovato.');
                return $response->withHeader('Location', $this->safeReferer())->withStatus(302);
            }

            // Re-evaluate after sanitisation: an explicit value made only of
            // control characters must fall back to automatic allocation.
            if ($numero === '') {
                $base = !empty($book['numero_inventario']) ? (string) $book['numero_inventario'] : "LIB-{$bookId}";
                $newCopyId = $repo->createWithAllocatedInventoryCode(
                    $bookId,
                    $base,
                    $stato,
                    $note !== '' ? $note : null
                );
            } elseif ($repo->inventoryCodeExists($numero)) {
                $db->rollback();
                $transactionStarted = false;
                $_SESSION['error_message'] = __('Esiste già una copia con questo numero di inventario.');
                return $response->withHeader('Location', url($this->adminBookPath($bookId)))->withStatus(302);
            } else {
                $newCopyId = $repo->create($bookId, $numero, $stato, $note !== '' ? $note : null);
            }

            if ($newCopyId <= 0) {
                throw new \RuntimeException('Unable to create the physical copy.');
            }

            // A newly available copy is new circulation capacity. Mirror the
            // existing book-edit path: first repair blocked copy assignments,
            // then promote the next eligible wait-list entry.
            if ($stato === 'disponibile') {
                $reassignmentService = new ReservationReassignmentService($db);
                $reassignmentService->setExternalTransaction(true);
                $reassignmentService->reassignOnNewCopy($bookId, $newCopyId);

                $reservationManager = new ReservationManager($db);
                $reservationManager->setExternalTransaction(true);
                $reservationManager->processBookAvailability($bookId);
            }

            $integrity = new DataIntegrity($db);
            if (!$integrity->recalculateBookAvailability($bookId, insideTransaction: true)) {
                throw new \RuntimeException('Unable to recalculate book availability.');
            }

            if (!$db->commit()) {
                throw new \RuntimeException('Unable to commit physical-copy creation.');
            }
            $transactionStarted = false;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $db->rollback();
            }
            SecureLogger::error('[CopyController] createCopy failed', ['book' => $bookId, 'error' => $e->getMessage()]);
            $_SESSION['error_message'] = (int) $e->getCode() === 1062
                ? __('Esiste già una copia con questo numero di inventario.')
                : __('Impossibile aggiungere la copia.');
            return $response->withHeader('Location', url($this->adminBookPath($bookId)))->withStatus(302);
        }

        // Notifications are deliberately emitted only after the transaction that
        // made the new assignment/promotion durable.
        try {
            $reassignmentService?->flushDeferredNotifications();
            $reservationManager?->flushDeferredNotifications();
        } catch (\Throwable $e) {
            SecureLogger::warning(__('Invio notifica nuova copia fallito'), ['error' => $e->getMessage()]);
        }

        $_SESSION['success_message'] = __('Copia aggiunta con successo.');
        return $response->withHeader('Location', url($this->adminBookPath($bookId)))->withStatus(302);
    }

    /**
     * Normalize an administrator-entered copy note consistently in create/edit.
     */
    private function sanitizeNote(string $note): string
    {
        $note = trim($note);
        if ($note === '') {
            return '';
        }

        // Keep tab/newline for multi-line notes, drop other control characters.
        $note = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $note);
        return mb_strlen($note) > 500 ? mb_substr($note, 0, 500) : $note;
    }

    /**
     * Elimina una singola copia
     */
    public function deleteCopy(Request $request, Response $response, mysqli $db, int $copyId): Response
    {
        // CSRF validated by CsrfMiddleware

        // Resolve the parent first; the transaction below then follows the
        // canonical circulation lock order (book -> copy -> loans).
        $stmt = $db->prepare('SELECT libro_id FROM copie WHERE id = ?');
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $copy = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$copy) {
            $_SESSION['error_message'] = __('Copia non trovata.');
            return $response->withHeader('Location', $this->safeReferer())->withStatus(302);
        }

        $libroId = (int) $copy['libro_id'];
        $transactionStarted = false;
        try {
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('Unable to begin copy-delete transaction.');
            }
            $transactionStarted = true;

            $lockBook = $db->prepare('SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $lockBook->bind_param('i', $libroId);
            $lockBook->execute();
            $bookExists = (bool) $lockBook->get_result()->fetch_row();
            $lockBook->close();
            if (!$bookExists) {
                $db->rollback();
                $transactionStarted = false;
                $_SESSION['error_message'] = __('Libro non trovato o non più disponibile.');
                return $response->withHeader('Location', $this->safeReferer())->withStatus(302);
            }

            // Re-read under lock: state and commitments may have changed since
            // the initial parent lookup.
            $stmt = $db->prepare('SELECT stato FROM copie WHERE id = ? AND libro_id = ? FOR UPDATE');
            $stmt->bind_param('ii', $copyId, $libroId);
            $stmt->execute();
            $lockedCopy = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$lockedCopy) {
                $db->rollback();
                $transactionStarted = false;
                $_SESSION['error_message'] = __('Copia non trovata.');
                return $response->withHeader('Location', url($this->adminBookPath($libroId)))->withStatus(302);
            }

            // A copy with any current/future commitment or historical loan is
            // retained permanently; operators can only move it out of circulation.
            if ($this->isCopyHeld($db, $copyId)) {
                $db->rollback();
                $transactionStarted = false;
                $_SESSION['error_message'] = __('Impossibile eliminare una copia attualmente impegnata in un prestito o una prenotazione.');
                return $response->withHeader('Location', url($this->adminBookPath($libroId)))->withStatus(302);
            }
            if (!in_array($lockedCopy['stato'], ['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'], true)) {
                $db->rollback();
                $transactionStarted = false;
                $_SESSION['error_message'] = __('Puoi eliminare solo copie fuori circolazione (perse, danneggiate, in manutenzione, in restauro o in trasferimento). Prima modifica lo stato della copia.');
                return $response->withHeader('Location', url($this->adminBookPath($libroId)))->withStatus(302);
            }

            $stmt = $db->prepare('SELECT 1 FROM prestiti WHERE copia_id = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('i', $copyId);
            $stmt->execute();
            $hasHistory = (bool) $stmt->get_result()->fetch_row();
            $stmt->close();
            if ($hasHistory) {
                $db->rollback();
                $transactionStarted = false;
                $_SESSION['error_message'] = __('Impossibile eliminare la copia: ha uno storico prestiti. Puoi metterla fuori circolazione cambiandone lo stato.');
                return $response->withHeader('Location', url($this->adminBookPath($libroId)))->withStatus(302);
            }

            $stmt = $db->prepare('DELETE FROM copie WHERE id = ?');
            $stmt->bind_param('i', $copyId);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            if ($deleted !== 1) {
                throw new \RuntimeException('Physical copy delete did not affect exactly one row.');
            }

            if (!(new DataIntegrity($db))->recalculateBookAvailability($libroId, insideTransaction: true)) {
                throw new \RuntimeException('Unable to recalculate availability after copy delete.');
            }
            if (!$db->commit()) {
                throw new \RuntimeException('Unable to commit copy-delete transaction.');
            }
            $transactionStarted = false;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                try {
                    $db->rollback();
                } catch (\Throwable $rollbackError) {
                    // best-effort
                }
            }
            SecureLogger::error('[CopyController] deleteCopy failed', ['copy' => $copyId, 'error' => $e->getMessage()]);
            $_SESSION['error_message'] = (int) $e->getCode() === 1451
                ? __('Impossibile eliminare la copia: ha uno storico prestiti. Puoi metterla fuori circolazione cambiandone lo stato.')
                : __('Impossibile aggiornare la copia senza lasciare dati incoerenti. Nessuna modifica è stata salvata.');
            return $response->withHeader('Location', url($this->adminBookPath($libroId)))->withStatus(302);
        }

        $_SESSION['success_message'] = __('Copia eliminata con successo.');
        return $response->withHeader('Location', url($this->adminBookPath($libroId)))->withStatus(302);
    }
}
