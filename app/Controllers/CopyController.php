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
        $stmt = $db->prepare("
            SELECT 1
            FROM prestiti
            WHERE copia_id = ?
              AND ( (attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                    OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL) )
            LIMIT 1
        ");
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $copyHeld = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

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
            // Blocca modifiche se la copia è trattenuta da un impegno HOLDING
            // (eccetto il cambio a disponibile già gestito sopra).
            if ($copyHeld) {
                $_SESSION['error_message'] = __('Impossibile modificare una copia attualmente impegnata in un prestito o una prenotazione. Prima liberala (chiudi o annulla il prestito) oppure impostala su "Disponibile".');
                return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
            }

            // Aggiorna la copia
            $stmt = $db->prepare("UPDATE copie SET stato = ?, note = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('ssi', $stato, $note, $copyId);
            $stmt->execute();
            $stmt->close();
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
                $reassignmentService->reassignOnReturn($copyId); // reassignOnReturn handles picking a waiting reservation
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
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM prestiti
            WHERE copia_id = ?
              AND ( (attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                    OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL) )
        ");
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $hasPrestito = (int) $result->fetch_assoc()['count'] > 0;
        $stmt->close();

        if ($hasPrestito) {
            $_SESSION['error_message'] = __('Impossibile eliminare una copia attualmente impegnata in un prestito o una prenotazione.');
            return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
        }

        // Permetti eliminazione solo per copie perse, danneggiate o in manutenzione
        if (!in_array($stato, ['perso', 'danneggiato', 'manutenzione'])) {
            $_SESSION['error_message'] = __('Puoi eliminare solo copie perse, danneggiate o in manutenzione. Prima modifica lo stato della copia.');
            return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
        }

        // Elimina la copia
        $stmt = $db->prepare("DELETE FROM copie WHERE id = ?");
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $stmt->close();

        // Ricalcola disponibilità del libro
        $integrity = new \App\Support\DataIntegrity($db);
        $integrity->recalculateBookAvailability($libroId);

        $_SESSION['success_message'] = __('Copia eliminata con successo.');
        return $response->withHeader('Location', url("/admin/books/{$libroId}"))->withStatus(302);
    }
}
