<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\SecureLogger;
use mysqli;

class CopyController
{
    /**
     * SECURITY: Validate and sanitize HTTP_REFERER to prevent open redirect
     */
    private function safeReferer(string $default = '/admin/books'): string
    {
        // Delegate to the single audited implementation. localPath() uses only
        // the referer's path (never its scheme/host), which is strictly safer
        // than the previous same-host comparison and port-agnostic.
        return \App\Support\RefererGuard::localPath((string) ($_SERVER['HTTP_REFERER'] ?? ''), $default);
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
        $code = trim((string) ($params['code'] ?? ''));

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

        $stato = $data['stato'] ?? 'disponibile';
        $note = $data['note'] ?? '';

        // Validazione stato (deve corrispondere all'enum in copie.stato)
        $statiValidi = ['disponibile', 'prestato', 'prenotato', 'manutenzione', 'in_restauro', 'perso', 'danneggiato', 'in_trasferimento'];
        if (!in_array($stato, $statiValidi)) {
            $_SESSION['error_message'] = __('Stato non valido.');
            return $response->withHeader('Location', $this->safeReferer('/admin/books'))->withStatus(302);
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
            return $response->withHeader('Location', $this->safeReferer('/admin/books'))->withStatus(302);
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
            return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
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
                'redirect_to' => "/admin/books/{$libroId}",
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
                return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
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
                    return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
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
                    return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
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
                return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
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
        return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
    }

    /**
     * Elimina una singola copia
     */
    public function deleteCopy(Request $request, Response $response, mysqli $db, int $copyId): Response
    {
        // CSRF validated by CsrfMiddleware

        // Recupera la copia per ottenere il libro_id e verificare lo stato
        $stmt = $db->prepare("SELECT libro_id, stato FROM copie WHERE id = ?");
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $copy = $result->fetch_assoc();
        $stmt->close();

        if (!$copy) {
            $_SESSION['error_message'] = __('Copia non trovata.');
            return $response->withHeader('Location', $this->safeReferer('/admin/books'))->withStatus(302);
        }

        $libroId = (int) $copy['libro_id'];
        $stato = $copy['stato'];

        // Verifica se la copia è trattenuta da QUALSIASI impegno HOLDING (prestito
        // attivo o pendente-con-copia, incluse prenotazioni future e ritiri in attesa).
        $hasPrestito = $this->isCopyHeld($db, $copyId);

        if ($hasPrestito) {
            $_SESSION['error_message'] = __('Impossibile eliminare una copia attualmente impegnata in un prestito o una prenotazione.');
            return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
        }

        // Permetti eliminazione solo per copie perse, danneggiate o in manutenzione
        if (!in_array($stato, ['perso', 'danneggiato', 'manutenzione'])) {
            $_SESSION['error_message'] = __('Puoi eliminare solo copie perse, danneggiate o in manutenzione. Prima modifica lo stato della copia.');
            return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
        }

        // Anche i prestiti CHIUSI referenziano copia_id e il FK fk_prestiti_copia
        // è ON DELETE RESTRICT: senza questo check la DELETE esplode con
        // mysqli_sql_exception (500). Una copia con storico non si elimina, si
        // mette fuori circolazione cambiandone lo stato.
        $stmt = $db->prepare("SELECT 1 FROM prestiti WHERE copia_id = ? LIMIT 1");
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $hasHistory = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        if ($hasHistory) {
            $_SESSION['error_message'] = __('Impossibile eliminare la copia: ha uno storico prestiti. Puoi metterla fuori circolazione cambiandone lo stato.');
            return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
        }

        // Elimina la copia. Difesa in profondità: un prestito creato tra il check
        // e la DELETE fa comunque scattare il FK — intercetta e degrada a errore
        // gestito invece di propagare un 500.
        try {
            $stmt = $db->prepare("DELETE FROM copie WHERE id = ?");
            $stmt->bind_param('i', $copyId);
            $stmt->execute();
            $stmt->close();
        } catch (\mysqli_sql_exception $e) {
            // 1451 = Cannot delete or update a parent row (vincolo FK)
            if ((int) $e->getCode() !== 1451) {
                throw $e;
            }
            $_SESSION['error_message'] = __('Impossibile eliminare la copia: ha uno storico prestiti. Puoi metterla fuori circolazione cambiandone lo stato.');
            return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
        }

        // Ricalcola disponibilità del libro
        $integrity = new \App\Support\DataIntegrity($db);
        $integrity->recalculateBookAvailability($libroId);

        $_SESSION['success_message'] = __('Copia eliminata con successo.');
        return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
    }
}
