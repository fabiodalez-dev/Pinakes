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
        $default = url($default);
        $referer = $_SERVER['HTTP_REFERER'] ?? $default;

        // Block CRLF injection
        if (strpos($referer, "\r") !== false || strpos($referer, "\n") !== false) {
            return $default;
        }

        // Allow relative internal URLs
        if (str_starts_with($referer, '/') && !str_starts_with($referer, '//')) {
            return $referer;
        }

        // For absolute URLs, only allow same host
        $parsed = parse_url($referer);
        if (!$parsed || empty($parsed['host'])) {
            return $default;
        }

        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        if ($parsed['host'] === $currentHost) {
            $path = $parsed['path'] ?? '/';
            if (!empty($parsed['query'])) {
                $path .= '?' . $parsed['query'];
            }
            return $path;
        }

        return $default;
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
     *   {found:true, copy_id, libro_id, titolo, stato, available:bool}
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
            SELECT c.id AS copy_id, c.libro_id, c.stato, l.titolo
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
            SELECT id
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
        // Se c'è un prestito in carico e si vuole rendere disponibile, chiudilo.
        if ($prestito && $statoCorrente === 'prestato' && $stato === 'disponibile') {
            $db->begin_transaction();
            try {
                // Ri-leggi e BLOCCA il prestito da chiudere dentro la transazione.
                $sel = $db->prepare("
                    SELECT id, stato, data_scadenza
                    FROM prestiti
                    WHERE copia_id = ? AND attivo = 1 AND stato IN ('in_corso','in_ritardo','da_ritirare')
                    ORDER BY data_prestito DESC
                    LIMIT 1
                    FOR UPDATE
                ");
                $sel->bind_param('i', $copyId);
                $sel->execute();
                $loanRow = $sel->get_result()->fetch_assoc();
                $sel->close();
                if (!$loanRow) {
                    $db->rollback();
                    $_SESSION['error_message'] = __('Il prestito associato non è più chiudibile. Ricarica la pagina.');
                    return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
                }
                $prestitoId = (int) $loanRow['id'];
                // Ritardo calcolato in PHP dalla riga bloccata: nell'UPDATE single-table
                // non si può riferire il vecchio `stato` dopo averlo riassegnato.
                $scadenza = (string) ($loanRow['data_scadenza'] ?? '');
                $wasLate = ($loanRow['stato'] === 'in_ritardo')
                    || ($scadenza !== '' && $scadenza < date('Y-m-d'));
                $lateFlag = $wasLate ? 1 : 0;

                // Chiudi come 'restituito' (MAI 'completato', I8); marca il ritardo (BUG5).
                $upd = $db->prepare("
                    UPDATE prestiti
                    SET stato = 'restituito',
                        restituito_in_ritardo = ?,
                        attivo = 0,
                        data_restituzione = CURDATE(),
                        updated_at = NOW()
                    WHERE id = ? AND attivo = 1
                ");
                $upd->bind_param('ii', $lateFlag, $prestitoId);
                $upd->execute();
                $affected = $upd->affected_rows;
                $upd->close();
                if ($affected !== 1) {
                    $db->rollback();
                    $_SESSION['error_message'] = __('Il prestito associato non è più chiudibile. Ricarica la pagina.');
                    return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
                }

                // Aggiorna la copia nella stessa transazione
                $stmt = $db->prepare("UPDATE copie SET stato = ?, note = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('ssi', $stato, $note, $copyId);
                $stmt->execute();
                $stmt->close();

                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }

            $_SESSION['success_message'] = __('Prestito chiuso automaticamente. La copia è ora disponibile.');
        } else {
            // GESTIONE ALTRI STATI
            // Fast-path: blocca subito se è già evidentemente trattenuta (HOLDING).
            if ($copyHeld) {
                $_SESSION['error_message'] = __('Impossibile modificare una copia attualmente impegnata in un prestito o una prenotazione. Prima liberala (chiudi o annulla il prestito) oppure impostala su "Disponibile".');
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

                if ($this->isCopyHeld($db, $copyId)) {
                    $db->rollback();
                    $_SESSION['error_message'] = __('Impossibile modificare una copia attualmente impegnata in un prestito o una prenotazione. Prima liberala (chiudi o annulla il prestito) oppure impostala su "Disponibile".');
                    return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
                }

                $stmt = $db->prepare("UPDATE copie SET stato = ?, note = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('ssi', $stato, $note, $copyId);
                $stmt->execute();
                $stmt->close();

                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }
        }

        // Case 2 & 9: Handle Copy Status Changes
        try {
            $reassignmentService = new \App\Services\ReservationReassignmentService($db);

            // Case 2: Copy became unavailable (lost/damaged/etc) -> Reassign any pending reservation
            if (in_array($stato, ['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'])) {
                $reassignmentService->reassignOnCopyLost($copyId);
            }
            // Case 9: Copy became available -> Assign to waiting reservation
            elseif ($stato === 'disponibile') {
                $reassignmentService->reassignOnReturn($copyId); // Layer 1: copy-less prenotato holds
                // Layer 2: promote queued waitlist reservations for this book (loop
                // until none convert). Both queues on every release path (D5/BUG10).
                $reservationManager = new \App\Controllers\ReservationManager($db);
                for ($promoGuard = 0; $promoGuard < 1000 && $reservationManager->processBookAvailability($libroId); $promoGuard++) {
                    // keep promoting while freed capacity converts the next queued reservation
                }
            }
        } catch (\Throwable $e) {
            SecureLogger::error(__('Errore gestione cambio stato copia'), [
                'copia_id' => $copyId,
                'stato' => $stato,
                'error' => $e->getMessage()
            ]);
        }

        // Ricalcola disponibilità del libro
        $integrity = new \App\Support\DataIntegrity($db);
        $integrity->recalculateBookAvailability($libroId);

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
