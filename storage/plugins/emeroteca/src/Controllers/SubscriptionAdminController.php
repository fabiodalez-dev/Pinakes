<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Controllers;

require_once __DIR__ . '/AbstractAdminController.php';
// SCADENZA_GIORNI (the renewal warning window) and LIST_PATH (where "back to
// the list of testate" has to land) live there and must stay a single source
// of truth: EmerotecaPlugin::dispatch() only loads the controller it routes
// to, so the sibling is required explicitly.
require_once __DIR__ . '/PeriodicalAdminController.php';

use App\Support\ActivityLog;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin CRUD for emeroteca_abbonamenti — the subscriptions of one testata
 * (supplier, cost, validity window, auto-renewal).
 *
 * Routes (registered by EmerotecaPlugin::registerRoutes) — admin paths are
 * English literals built with url(), never route_path() (decision #145):
 *   GET  /admin/periodicals/{id}/subscriptions                  → index
 *   POST /admin/periodicals/{id}/subscriptions                  → createSubmit
 *   GET  /admin/periodicals/{id}/subscriptions/{sid}/edit       → editForm
 *   POST /admin/periodicals/{id}/subscriptions/{sid}/edit       → editSubmit
 *   POST /admin/periodicals/{id}/subscriptions/{sid}/delete     → delete
 *
 * Every subscription is addressed through BOTH ids: the {sid} is always
 * verified to belong to the {id} in the path, so a guessed id from another
 * testata can never be edited or deleted through this controller.
 */
class SubscriptionAdminController extends AbstractAdminController
{
    /** ISO 4217: three letters. Free text would end up as "€" or "euro". */
    private const VALUTA_PATTERN = '/^[A-Za-z]{3}$/';

    /**
     * GET /admin/periodicals/{id}/subscriptions — list + create form.
     *
     * @param array<string,string> $args
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $testataId = (int) ($args['id'] ?? 0);
        $testata = $this->fetchTestata($testataId);
        if ($testata === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, PeriodicalAdminController::LIST_PATH);
        }

        return $this->renderView($response, 'subscriptions', [
            'testata'        => $testata,
            'abbonamenti'    => $this->fetchAll($testataId),
            'values'         => [],
            'errors'         => [],
            'giorni_preavviso' => PeriodicalAdminController::SCADENZA_GIORNI,
        ]);
    }

    /**
     * POST /admin/periodicals/{id}/subscriptions — validate + INSERT.
     *
     * @param array<string,string> $args
     */
    public function createSubmit(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware
        $testataId = (int) ($args['id'] ?? 0);
        $testata = $this->fetchTestata($testataId);
        if ($testata === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, PeriodicalAdminController::LIST_PATH);
        }
        $back = '/admin/periodicals/' . $testataId . '/subscriptions';

        [$values, $errors] = $this->validate((array) $request->getParsedBody());
        if ($errors !== []) {
            return $this->renderView($response, 'subscriptions', [
                'testata'          => $testata,
                'abbonamenti'      => $this->fetchAll($testataId),
                'values'           => $values,
                'errors'           => $errors,
                'giorni_preavviso' => PeriodicalAdminController::SCADENZA_GIORNI,
            ]);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO emeroteca_abbonamenti
                (testata_id, fornitore, costo, valuta, data_inizio, data_scadenza,
                 rinnovo_automatico, attivo, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] subscription insert prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante il salvataggio dell\'abbonamento.'));
            return $this->redirect($response, $back);
        }
        $stmt->bind_param(
            'isdsssiis',
            $testataId,
            $values['fornitore'],
            $values['costo'],
            $values['valuta'],
            $values['data_inizio'],
            $values['data_scadenza'],
            $values['rinnovo_automatico'],
            $values['attivo'],
            $values['note']
        );
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] subscription insert failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError(__('Errore durante il salvataggio dell\'abbonamento.'));
            return $this->redirect($response, $back);
        }
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        ActivityLog::recordEntityEvent(
            $this->db,
            'emeroteca_abbonamenti',
            $newId,
            'subscription.created',
            [],
            self::auditSnapshot($values + ['testata_id' => $testataId]),
            'inserimento',
            'admin'
        );

        $this->flashSuccess(__('Abbonamento creato.'));
        return $this->redirect($response, $back);
    }

    /**
     * GET /admin/periodicals/{id}/subscriptions/{sid}/edit — edit form.
     *
     * @param array<string,string> $args
     */
    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $testataId = (int) ($args['id'] ?? 0);
        $subId = (int) ($args['sid'] ?? 0);
        $testata = $this->fetchTestata($testataId);
        if ($testata === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, PeriodicalAdminController::LIST_PATH);
        }
        $existing = $this->fetchOne($subId, $testataId);
        if ($existing === null) {
            $this->flashError(__('Abbonamento non trovato.'));
            return $this->redirect($response, '/admin/periodicals/' . $testataId . '/subscriptions');
        }

        return $this->renderView($response, 'subscription-form', [
            'testata' => $testata,
            'id'      => $subId,
            'values'  => $existing,
            'errors'  => [],
        ]);
    }

    /**
     * POST /admin/periodicals/{id}/subscriptions/{sid}/edit — validate + UPDATE.
     *
     * @param array<string,string> $args
     */
    public function editSubmit(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware
        $testataId = (int) ($args['id'] ?? 0);
        $subId = (int) ($args['sid'] ?? 0);
        $testata = $this->fetchTestata($testataId);
        if ($testata === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, PeriodicalAdminController::LIST_PATH);
        }
        $back = '/admin/periodicals/' . $testataId . '/subscriptions';
        $existing = $this->fetchOne($subId, $testataId);
        if ($existing === null) {
            $this->flashError(__('Abbonamento non trovato.'));
            return $this->redirect($response, $back);
        }

        [$values, $errors] = $this->validate((array) $request->getParsedBody());
        if ($errors !== []) {
            return $this->renderView($response, 'subscription-form', [
                'testata' => $testata,
                'id'      => $subId,
                'values'  => $values,
                'errors'  => $errors,
            ]);
        }

        // The testata_id is in the WHERE, not in the SET: a subscription never
        // moves to another title, and pinning it here makes the ownership
        // check part of the write itself.
        $stmt = $this->db->prepare(
            'UPDATE emeroteca_abbonamenti SET
                fornitore = ?, costo = ?, valuta = ?, data_inizio = ?, data_scadenza = ?,
                rinnovo_automatico = ?, attivo = ?, note = ?
             WHERE id = ? AND testata_id = ?'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] subscription update prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante il salvataggio dell\'abbonamento.'));
            return $this->redirect($response, $back);
        }
        $stmt->bind_param(
            'sdsssiisii',
            $values['fornitore'],
            $values['costo'],
            $values['valuta'],
            $values['data_inizio'],
            $values['data_scadenza'],
            $values['rinnovo_automatico'],
            $values['attivo'],
            $values['note'],
            $subId,
            $testataId
        );
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] subscription update failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError(__('Errore durante il salvataggio dell\'abbonamento.'));
            return $this->redirect($response, $back);
        }
        $stmt->close();

        ActivityLog::recordEntityEvent(
            $this->db,
            'emeroteca_abbonamenti',
            $subId,
            'subscription.updated',
            self::auditSnapshot($existing),
            self::auditSnapshot($values + ['testata_id' => $testataId]),
            'aggiornamento',
            'admin'
        );

        $this->flashSuccess(__('Abbonamento aggiornato.'));
        return $this->redirect($response, $back);
    }

    /**
     * POST /admin/periodicals/{id}/subscriptions/{sid}/delete.
     *
     * @param array<string,string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware.
        // Destructive action: AdminAuthMiddleware also admits staff, so
        // re-check the role inline (internal security scan 2026-07-25).
        $testataId = (int) ($args['id'] ?? 0);
        $subId = (int) ($args['sid'] ?? 0);
        $back = '/admin/periodicals/' . $testataId . '/subscriptions';
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            $this->flashError(__('Operazione riservata agli amministratori.'));
            return $this->redirect($response, $back);
        }
        if ($this->fetchTestata($testataId) === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, PeriodicalAdminController::LIST_PATH);
        }
        $existing = $this->fetchOne($subId, $testataId);
        if ($existing === null) {
            $this->flashError(__('Abbonamento non trovato.'));
            return $this->redirect($response, $back);
        }

        $stmt = $this->db->prepare('DELETE FROM emeroteca_abbonamenti WHERE id = ? AND testata_id = ?');
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] subscription delete prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante l\'eliminazione dell\'abbonamento.'));
            return $this->redirect($response, $back);
        }
        $stmt->bind_param('ii', $subId, $testataId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] subscription delete failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError(__('Errore durante l\'eliminazione dell\'abbonamento.'));
            return $this->redirect($response, $back);
        }
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();

        if ($deleted) {
            ActivityLog::recordEntityEvent(
                $this->db,
                'emeroteca_abbonamenti',
                $subId,
                'subscription.deleted',
                self::auditSnapshot($existing),
                [],
                'cancellazione',
                'admin'
            );
            $this->flashSuccess(__('Abbonamento eliminato.'));
        } else {
            $this->flashError(__('Abbonamento non trovato.'));
        }
        return $this->redirect($response, $back);
    }

    // ── Internals ─────────────────────────────────────────────────────

    /**
     * Every subscription of a testata, newest validity window first, with a
     * per-row `in_scadenza` flag computed BY THE DATABASE (same engine that
     * stores the DATE, so no PHP/DB timezone skew) and mirroring
     * PeriodicalAdminController::expiringSubscriptions().
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAll(int $testataId): array
    {
        $stmt = $this->db->prepare(
            'SELECT *,
                    (attivo = 1
                     AND data_scadenza IS NOT NULL
                     AND data_scadenza <= DATE_ADD(CURDATE(), INTERVAL ? DAY)) AS in_scadenza,
                    (data_scadenza IS NOT NULL AND data_scadenza < CURDATE()) AS scaduto
               FROM emeroteca_abbonamenti
              WHERE testata_id = ?
              ORDER BY attivo DESC, data_scadenza IS NULL, data_scadenza DESC, id DESC'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] subscription list prepare failed: ' . $this->db->error);
            return [];
        }
        $days = PeriodicalAdminController::SCADENZA_GIORNI;
        $stmt->bind_param('ii', $days, $testataId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] subscription list failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $rows = [];
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();
        return $rows;
    }

    /**
     * One subscription, but only when it belongs to the given testata.
     *
     * @return array<string, mixed>|null
     */
    private function fetchOne(int $id, int $testataId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM emeroteca_abbonamenti WHERE id = ? AND testata_id = ? LIMIT 1'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] subscription fetch prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('ii', $id, $testataId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] subscription fetch failed: ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * Server-side validation + normalization of the subscription form.
     *
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validate(array $body): array
    {
        $errors = [];
        $str = static function (string $key, int $max) use ($body): ?string {
            $v = trim(strip_tags((string) ($body[$key] ?? '')));
            return $v === '' ? null : mb_substr($v, 0, $max);
        };

        $values = [
            'fornitore'          => $str('fornitore', 255) ?? '',
            'costo'              => null,
            'valuta'             => strtoupper((string) ($str('valuta', 3) ?? 'EUR')),
            'data_inizio'        => $str('data_inizio', 10),
            'data_scadenza'      => $str('data_scadenza', 10),
            'rinnovo_automatico' => isset($body['rinnovo_automatico']) ? 1 : 0,
            // 'attivo' defaults to 1 on a brand-new form (no checkbox posted
            // yet) but must be honoured as 0 when the operator unticks it, so
            // the hidden companion input below distinguishes the two cases.
            'attivo'             => isset($body['attivo']) ? 1 : 0,
            'note'               => $str('note', 65535),
        ];

        if ($values['fornitore'] === '') {
            $errors['fornitore'] = __('Il fornitore è obbligatorio.');
        }
        $costoRaw = trim((string) ($body['costo'] ?? ''));
        if ($costoRaw !== '') {
            $costoNorm = str_replace(',', '.', $costoRaw);
            if (preg_match('/^\d{1,8}(\.\d{1,2})?$/', $costoNorm) !== 1) {
                $errors['costo'] = __('Costo non valido (usa un numero con al massimo due decimali).');
                $values['costo'] = mb_substr($costoRaw, 0, 20);
            } else {
                $values['costo'] = (float) $costoNorm;
            }
        }
        if (preg_match(self::VALUTA_PATTERN, $values['valuta']) !== 1) {
            $errors['valuta'] = __('Valuta non valida: usa un codice ISO di tre lettere (es. EUR).');
        }
        foreach (['data_inizio', 'data_scadenza'] as $dateField) {
            if ($values[$dateField] !== null && !self::isValidDate($values[$dateField])) {
                $errors[$dateField] = __('Data non valida (formato AAAA-MM-GG).');
            }
        }
        if ($values['data_inizio'] !== null
            && $values['data_scadenza'] !== null
            && !isset($errors['data_inizio'])
            && !isset($errors['data_scadenza'])
            && $values['data_scadenza'] < $values['data_inizio']) {
            $errors['data_scadenza'] = __('La data di scadenza non può precedere quella di inizio.');
        }

        return [$values, $errors];
    }

    /** Strict Y-m-d, calendar-checked (2026-02-30 is not a date). */
    private static function isValidDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /**
     * Fields kept in the audit log. Timestamps and the derived list-only
     * flags (in_scadenza/scaduto) are dropped.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function auditSnapshot(array $row): array
    {
        $fields = [
            'testata_id', 'fornitore', 'costo', 'valuta', 'data_inizio',
            'data_scadenza', 'rinnovo_automatico', 'attivo',
        ];
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $out[$field] = $row[$field];
            }
        }
        return $out;
    }
}
