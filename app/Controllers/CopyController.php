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
    private function safeReferer(string $default = '/admin/libri'): string
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
            return $response->withHeader('Location', $this->safeReferer('/admin/libri'))->withStatus(302);
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
            return $response->withHeader('Location', $this->safeReferer('/admin/libri'))->withStatus(302);
        }

        $libroId = (int) $copy['libro_id'];
        $statoCorrente = $copy['stato'];

        // Verifica se la copia è in prestito attivo
        $stmt = $db->prepare("
            SELECT id
            FROM prestiti
            WHERE copia_id = ? AND attivo = 1 AND stato IN ('in_corso', 'in_ritardo')
        ");
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $prestito = $result->fetch_assoc();
        $stmt->close();

        // GESTIONE CAMBIO STATO -> "PRESTATO"
        // Non permettere cambio diretto a "prestato", deve usare il sistema prestiti
        if ($stato === 'prestato' && $statoCorrente !== 'prestato') {
            $_SESSION['error_message'] = __('Per prestare una copia, utilizza il sistema Prestiti dalla sezione dedicata. Non è possibile impostare manualmente lo stato "Prestato".');
            return $response->withHeader('Location', url("/admin/libri/{$libroId}"))->withStatus(302);
        }

        // GESTIONE CAMBIO STATO DA "PRESTATO" A "DISPONIBILE"
        // Se c'è un prestito attivo e si vuole rendere disponibile, chiudi il prestito
        if ($prestito && $statoCorrente === 'prestato' && $stato === 'disponibile') {
            $prestitoId = (int) $prestito['id'];

            $db->begin_transaction();
            try {
                // Chiudi il prestito
                $stmt = $db->prepare("
                    UPDATE prestiti
                    SET stato = 'completato',
                        attivo = 0,
                        data_restituzione = CURDATE(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('i', $prestitoId);
                $stmt->execute();
                $stmt->close();

                // Aggiorna la copia nello stesso transaction
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
            // Blocca modifiche se c'è un prestito attivo (eccetto cambio a disponibile già gestito)
            if ($prestito) {
                $_SESSION['error_message'] = __('Impossibile modificare una copia attualmente in prestito. Prima termina il prestito o imposta lo stato su "Disponibile" per chiuderlo automaticamente.');
                return $response->withHeader('Location', url("/admin/libri/{$libroId}"))->withStatus(302);
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
            if (in_array($stato, ['perso', 'danneggiato', 'manutenzione', 'in_restauro'])) {
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

        $_SESSION['success_message'] = __('Stato della copia aggiornato con successo.');
        return $response->withHeader('Location', url("/admin/libri/{$libroId}"))->withStatus(302);
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
            return $response->withHeader('Location', $this->safeReferer('/admin/libri'))->withStatus(302);
        }

        $libroId = (int) $copy['libro_id'];
        $stato = $copy['stato'];

        // Verifica se la copia è in prestito attivo
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM prestiti
            WHERE copia_id = ? AND attivo = 1 AND stato IN ('in_corso', 'in_ritardo')
        ");
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $hasPrestito = (int) $result->fetch_assoc()['count'] > 0;
        $stmt->close();

        if ($hasPrestito) {
            $_SESSION['error_message'] = __('Impossibile eliminare una copia attualmente in prestito.');
            return $response->withHeader('Location', url("/admin/libri/{$libroId}"))->withStatus(302);
        }

        // Permetti eliminazione solo per copie perse, danneggiate o in manutenzione
        if (!in_array($stato, ['perso', 'danneggiato', 'manutenzione'])) {
            $_SESSION['error_message'] = __('Puoi eliminare solo copie perse, danneggiate o in manutenzione. Prima modifica lo stato della copia.');
            return $response->withHeader('Location', url("/admin/libri/{$libroId}"))->withStatus(302);
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
        return $response->withHeader('Location', url("/admin/libri/{$libroId}"))->withStatus(302);
    }
}
