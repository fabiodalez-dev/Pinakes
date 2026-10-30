<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Controllers\CopyController;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffCopyReader;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Per-copy management (Mobile API 1.7.0): GET/PUT/DELETE /staff/copies/{id}.
 *
 * Update and delete are delegated to CopyController::updateCopy() /
 * deleteCopy(), which answer with a flash message only. The plugin first
 * runs the same refusals as those controllers so the app gets a stable code
 * (`copy_on_loan`, `copy_held`, `copy_in_circulation`, `copy_has_history`);
 * a residual refusal of the core (a race with the desk) is a 409 `conflict`
 * carrying the server text.
 */
final class StaffCopiesController
{
    /** States a copy can be MOVED to by an operator (loan states belong to circulation). */
    public const EDITABLE_STATES = StaffBooksController::CREATABLE_COPY_STATES;

    public function __construct(private mysqli $db)
    {
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $copy = (new StaffCopyReader($this->db))->copy($id);

            return $copy === null ? $this->notFound($response) : ResponseEnvelope::success($response, $copy);
        } catch (\Throwable $e) {
            return $this->fail($response, 'copy detail', $e);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        if (array_key_exists('status', $json) && (!is_string($json['status']) || !in_array($json['status'], self::EDITABLE_STATES, true))) {
            return ResponseEnvelope::error($response, 'validation_error', __('Stato non valido.'), 422, ['field' => 'status']);
        }
        if (array_key_exists('notes', $json) && $json['notes'] !== null && !is_string($json['notes'])) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'notes']);
        }
        if (array_key_exists('consultation_only', $json) && !is_bool($json['consultation_only'])) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'consultation_only']);
        }
        try {
            $reader = new StaffCopyReader($this->db);
            $row = $reader->row($id);
            if ($row === null) {
                return $this->notFound($response);
            }
            // Consultation only (Pinakes 0.9.0): the same toggle as the book
            // page, with its refusals (a copy held by a loan, a pickup or a
            // copy-bound request) and its queue promotion when it is lendable
            // again. Status and notes, when sent too, are applied after it.
            if (array_key_exists('consultation_only', $json)) {
                if (!$reader->consultationActive()) {
                    return ResponseEnvelope::error($response, 'feature_disabled', __('Funzione non disponibile su questa istanza.'), 404);
                }
                $refused = $this->setConsultation($id, $json['consultation_only'] === true);
                if ($refused !== null) {
                    return ResponseEnvelope::error($response, 'conflict', $refused, 409);
                }
                if (!array_key_exists('status', $json) && !array_key_exists('notes', $json)) {
                    $copy = $reader->copy($id);
                    return $copy === null ? $this->notFound($response) : ResponseEnvelope::success($response, $copy);
                }
                $row = $reader->row($id) ?? $row;
            }
            $current = (string) $row['stato'];
            $target = is_string($json['status'] ?? null) ? (string) $json['status'] : $current;
            $notes = array_key_exists('notes', $json) ? (string) ($json['notes'] ?? '') : (string) ($row['note'] ?? '');

            if ($current === 'prenotato' && $target === 'disponibile') {
                return $this->refuse($response, 'copy_held', __('Per annullare o spostare una prenotazione, utilizza il sistema Prestiti.'));
            }
            $physicallyOut = $this->scalar(
                "SELECT COUNT(*) FROM prestiti WHERE copia_id = ? AND attivo = 1 AND stato IN ('in_corso','in_ritardo')",
                $id
            ) > 0;
            // prestato → disponibile with an open loan is a return (the core
            // hands it to processReturn); anything else on a copy that is out
            // must go through the return workflow.
            if ($physicallyOut && !($current === 'prestato' && $target === 'disponibile')) {
                return $this->refuse($response, 'copy_on_loan', __('La copia è fisicamente in prestito: registra prima la restituzione o l’esito perso/danneggiato dal sistema Prestiti.'));
            }

            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new CopyController())->updateCopy(
                Delegation::request('POST', '/admin/books/copies/' . $id . '/update', ['stato' => $target, 'note' => $notes]),
                new SlimResponse(),
                $db,
                $id
            ));
            $error = $outcome->flash('error_message');
            if ($error !== null || $outcome->errorCode() !== null) {
                return ResponseEnvelope::error($response, 'conflict', $error ?? __('Operazione non consentita nello stato attuale.'), 409);
            }
            $copy = $reader->copy($id);

            return $copy === null ? $this->notFound($response) : ResponseEnvelope::success($response, $copy);
        } catch (\Throwable $e) {
            return $this->fail($response, 'copy update', $e);
        }
    }

    /**
     * Mark the copy consultation only, or lendable again, through
     * CopyController::toggleConsultation(). Returns the refusal message, or
     * null when the copy now has the requested flag.
     */
    private function setConsultation(int $id, bool $consultation): ?string
    {
        $db = $this->db;
        $outcome = Delegation::run(static fn (): ResponseInterface => (new CopyController())->toggleConsultation(
            Delegation::request('POST', '/admin/books/copies/' . $id . '/consultation', ['solo_consultazione' => $consultation ? '1' : '0']),
            new SlimResponse(),
            $db,
            $id
        ));
        $error = $outcome->flash('error_message');
        if ($error !== null || $outcome->errorCode() !== null) {
            return $error ?? __('Operazione non consentita nello stato attuale.');
        }
        return null;
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $reader = new StaffCopyReader($this->db);
            $copy = $reader->copy($id);
            if ($copy === null) {
                return $this->notFound($response);
            }
            if ($copy['held'] === true) {
                return $this->refuse($response, 'copy_held', __('Impossibile eliminare una copia attualmente impegnata in un prestito o una prenotazione.'));
            }
            if (!in_array($copy['status'], StaffCopyReader::OUT_OF_CIRCULATION, true)) {
                return $this->refuse($response, 'copy_in_circulation', __('Puoi eliminare solo copie fuori circolazione (perse, danneggiate, in manutenzione, in restauro o in trasferimento). Prima modifica lo stato della copia.'));
            }
            if ($this->scalar('SELECT COUNT(*) FROM prestiti WHERE copia_id = ?', $id) > 0) {
                return $this->refuse($response, 'copy_has_history', __('Impossibile eliminare la copia: ha uno storico prestiti. Puoi metterla fuori circolazione cambiandone lo stato.'));
            }

            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new CopyController())->deleteCopy(
                Delegation::request('POST', '/admin/books/copies/' . $id . '/delete', []),
                new SlimResponse(),
                $db,
                $id
            ));
            $error = $outcome->flash('error_message');
            if ($error !== null) {
                return ResponseEnvelope::error($response, 'conflict', $error, 409);
            }

            return ResponseEnvelope::success($response, ['id' => $id, 'deleted' => true]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'copy delete', $e);
        }
    }

    private function scalar(string $sql, int $id): int
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_row() : null;
        $stmt->close();

        return (int) ($row[0] ?? 0);
    }

    private function refuse(ResponseInterface $response, string $code, string $message): ResponseInterface
    {
        return ResponseEnvelope::error($response, $code, $message, 409, ['reason' => $code]);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', __('Copia non trovata.'), 404);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
