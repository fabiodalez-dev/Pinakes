<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Controllers;

require_once __DIR__ . '/AbstractAdminController.php';

use App\Support\ActivityLog;
use App\Support\DateHelper;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin management of annate + fascicoli (+ spoglio) for one testata.
 *
 * Routes (registered by EmerotecaPlugin::registerRoutes):
 *   GET  /admin/periodicals/{id}/issues          → manage
 *   POST /admin/periodicals/{id}/issues          → manageSubmit (action switch)
 *   POST /admin/periodicals/{id}/issues/bulk     → bulkCreate
 *   POST /admin/periodicals/{id}/kardex/generate → kardexGenerate
 *   GET  /admin/periodicals/issue/{id}           → show
 *   POST /admin/periodicals/issue/{id}           → update
 *   POST /admin/periodicals/issue/{id}/delete    → delete
 */
class IssueAdminController extends AbstractAdminController
{
    private const ANNO_MIN = 1400;
    private const ANNO_MAX = 2100;

    /**
     * emeroteca_fascicoli.n_reclami is TINYINT UNSIGNED. The counter is
     * clamped instead of wrapping/erroring: past ~a handful of reminders the
     * exact number stops meaning anything, but an out-of-range write under
     * STRICT_TRANS_TABLES would fail the whole claim.
     */
    private const MAX_RECLAMI = 255;

    /**
     * Days of slack granted to an undated issue of the CURRENT year before a
     * bulk claim considers it late (review #140). It absorbs postal delay and
     * the ordinary drift of a publication schedule, on the principle that a
     * reminder sent too early costs more credibility with the supplier than
     * one sent a month late costs the library.
     */
    private const CLAIM_GRACE_DAYS = 30;

    /**
     * States a fascicolo can be claimed from: it was expected and never
     * arrived ('atteso'), or it was already claimed once and still has not
     * ('reclamato' — a second reminder is normal practice).
     *
     * @var list<string>
     */
    private const CLAIMABLE = ['atteso', 'reclamato'];

    // ── Manage page ───────────────────────────────────────────────────

    /**
     * GET /admin/periodicals/{id}/issues — testata header + annate with
     * quick forms (annata, fascicolo, bulk, kardex). Only ONE annata is
     * expanded per request (review #140: no unbounded fascicoli load):
     * the most recent by default, or the one selected via ?annata=ID;
     * the others render collapsed with per-annata counters and a
     * server-side link that reloads the page expanded on them.
     *
     * @param array<string,string> $args
     */
    public function manage(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $testataId = (int) ($args['id'] ?? 0);
        $testata = $this->fetchTestata($testataId);
        if ($testata === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, '/admin/periodicals');
        }

        $annate = [];
        $stmt = $this->db->prepare(
            'SELECT * FROM emeroteca_annate WHERE testata_id = ? ORDER BY anno DESC, volume'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $testataId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($row = $res->fetch_assoc()) {
                        $row['fascicoli'] = [];
                        $row['n_fascicoli'] = 0;
                        $row['n_attesi'] = 0;
                        $annate[(int) $row['id']] = $row;
                    }
                }
            } else {
                SecureLogger::error('[Emeroteca] annate query failed: ' . $stmt->error);
            }
            $stmt->close();
        } else {
            SecureLogger::error('[Emeroteca] annate prepare failed: ' . $this->db->error);
        }

        // Per-annata counters in one aggregated query (for the collapsed
        // headers), instead of loading every fascicolo of every annata.
        if ($annate !== []) {
            $stmt = $this->db->prepare(
                "SELECT f.annata_id,
                        COUNT(*) AS n_fascicoli,
                        COALESCE(SUM(f.stato = 'atteso'), 0) AS n_attesi
                   FROM emeroteca_fascicoli f
                   JOIN emeroteca_annate a ON f.annata_id = a.id
                  WHERE a.testata_id = ?
                  GROUP BY f.annata_id"
            );
            if ($stmt !== false) {
                $stmt->bind_param('i', $testataId);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    if ($res instanceof \mysqli_result) {
                        while ($row = $res->fetch_assoc()) {
                            $aid = (int) $row['annata_id'];
                            if (isset($annate[$aid])) {
                                $annate[$aid]['n_fascicoli'] = (int) $row['n_fascicoli'];
                                $annate[$aid]['n_attesi'] = (int) $row['n_attesi'];
                            }
                        }
                    }
                } else {
                    SecureLogger::error('[Emeroteca] annate counters query failed: ' . $stmt->error);
                }
                $stmt->close();
            } else {
                SecureLogger::error('[Emeroteca] annate counters prepare failed: ' . $this->db->error);
            }
        }

        // Expanded annata: ?annata=ID when it belongs to this testata,
        // otherwise the most recent one (first of the DESC ordering).
        $params = (array) $request->getQueryParams();
        $requestedAnnata = (int) ($params['annata'] ?? 0);
        $openAnnataId = isset($annate[$requestedAnnata])
            ? $requestedAnnata
            : (int) (array_key_first($annate) ?? 0);

        if ($openAnnataId > 0) {
            $stmt = $this->db->prepare(
                'SELECT f.* FROM emeroteca_fascicoli f
                 WHERE f.annata_id = ?
                 ORDER BY CAST(f.numero AS UNSIGNED), f.numero'
            );
            if ($stmt !== false) {
                $stmt->bind_param('i', $openAnnataId);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    if ($res instanceof \mysqli_result) {
                        while ($row = $res->fetch_assoc()) {
                            $annate[$openAnnataId]['fascicoli'][] = $row;
                        }
                    }
                } else {
                    SecureLogger::error('[Emeroteca] fascicoli query failed: ' . $stmt->error);
                }
                $stmt->close();
            } else {
                SecureLogger::error('[Emeroteca] fascicoli prepare failed: ' . $this->db->error);
            }
        }

        return $this->renderView($response, 'issues', [
            'testata'        => $testata,
            'annate'         => array_values($annate),
            'open_annata_id' => $openAnnataId,
            'consistenza'    => \EmerotecaPlugin::consistenzaTestata($this->db, $testataId),
            // Shelf list for the annata form (bound volumes live on a shelf
            // like a book); same helper the fascicolo form uses.
            'collocazioni'   => $this->fetchCollocazioni(),
        ]);
    }

    /**
     * POST /admin/periodicals/{id}/issues — small-action switch driven
     * by the hidden `action` field of the inline forms on the manage
     * page: add_annata, add_fascicolo, receive_issue, mark_missing.
     *
     * @param array<string,string> $args
     */
    public function manageSubmit(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware
        $testataId = (int) ($args['id'] ?? 0);
        if ($this->fetchTestata($testataId) === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, '/admin/periodicals');
        }
        $body = (array) $request->getParsedBody();
        $action = trim((string) ($body['action'] ?? ''));
        $back = '/admin/periodicals/' . $testataId . '/issues';

        switch ($action) {
            case 'add_annata':
                $this->addAnnata($testataId, $body);
                break;
            case 'update_annata':
                $this->updateAnnata($testataId, $body);
                break;
            case 'add_fascicolo':
                $this->addFascicolo($testataId, $body);
                break;
            case 'receive_issue':
                $this->receiveIssue($testataId, (int) ($body['fascicolo_id'] ?? 0));
                break;
            case 'claim_issue':
                $this->claimIssue($testataId, (int) ($body['fascicolo_id'] ?? 0));
                break;
            case 'claim_overdue':
                $this->claimOverdue($testataId, (int) ($body['annata_id'] ?? 0));
                break;
            case 'mark_missing':
                $this->markMissing($testataId, (int) ($body['annata_id'] ?? 0));
                break;
            default:
                $this->flashError(__('Azione non riconosciuta.'));
        }
        // Keep the annata the user was working in expanded after the
        // redirect (the manage page now lazy-loads one annata, #140).
        $backAnnata = (int) ($body['annata_id'] ?? 0);
        if ($backAnnata > 0 && $this->annataBelongsTo($backAnnata, $testataId)) {
            $back .= '?annata=' . $backAnnata;
        }
        return $this->redirect($response, $back);
    }

    /** @param array<string, mixed> $body */
    private function addAnnata(int $testataId, array $body): void
    {
        $anno = (int) trim((string) ($body['anno'] ?? ''));
        $volume = trim(strip_tags((string) ($body['volume'] ?? '')));
        $volume = mb_substr($volume, 0, 50);
        $rilegata = isset($body['rilegata']) ? 1 : 0;
        if ($anno < self::ANNO_MIN || $anno > self::ANNO_MAX) {
            $this->flashError(sprintf(__('Anno non plausibile (atteso tra %d e %d).'), self::ANNO_MIN, self::ANNO_MAX));
            return;
        }
        [$serie, $consistenza, $collocazioneId, $annataError] = $this->annataOptionalFields($body);
        if ($annataError !== null) {
            $this->flashError($annataError);
            return;
        }
        // Volume '' (not NULL) so the UNIQUE(testata_id, anno, volume)
        // actually bites for single-volume years (see DDL comment).
        $stmt = $this->db->prepare(
            'INSERT INTO emeroteca_annate
                (testata_id, anno, volume, serie, rilegata, collocazione_id, consistenza_dichiarata)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] addAnnata prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante la creazione dell\'annata.'));
            return;
        }
        $stmt->bind_param('iissiis', $testataId, $anno, $volume, $serie, $rilegata, $collocazioneId, $consistenza);
        if (!$stmt->execute()) {
            $dup = (int) $stmt->errno === 1062;
            SecureLogger::error('[Emeroteca] addAnnata insert failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError($dup
                ? __('Annata già esistente per questa testata (stesso anno e volume).')
                : __('Errore durante la creazione dell\'annata.'));
            return;
        }
        $stmt->close();
        $this->flashSuccess(sprintf(__('Annata %d creata.'), $anno));
    }

    /**
     * Update the descriptive fields of an existing annata (1.4.0). Anno and
     * volume are NOT editable here on purpose: they are the identity of the
     * annata inside UNIQUE(testata_id, anno, volume) and its fascicoli hang
     * off it — renaming them silently is how holdings get misfiled.
     *
     * @param array<string, mixed> $body
     */
    private function updateAnnata(int $testataId, array $body): void
    {
        $annataId = (int) ($body['annata_id'] ?? 0);
        if (!$this->annataBelongsTo($annataId, $testataId)) {
            $this->flashError(__('Annata non trovata per questa testata.'));
            return;
        }
        [$serie, $consistenza, $collocazioneId, $annataError] = $this->annataOptionalFields($body);
        if ($annataError !== null) {
            $this->flashError($annataError);
            return;
        }
        $rilegata = isset($body['rilegata']) ? 1 : 0;
        $stmt = $this->db->prepare(
            'UPDATE emeroteca_annate
                SET serie = ?, rilegata = ?, collocazione_id = ?, consistenza_dichiarata = ?
              WHERE id = ? AND testata_id = ?'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] updateAnnata prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante il salvataggio dell\'annata.'));
            return;
        }
        $stmt->bind_param('siisii', $serie, $rilegata, $collocazioneId, $consistenza, $annataId, $testataId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] updateAnnata failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError(__('Errore durante il salvataggio dell\'annata.'));
            return;
        }
        $stmt->close();
        $this->flashSuccess(__('Annata aggiornata.'));
    }

    /**
     * Parse and validate the optional annata fields shared by create and
     * update (1.4.0): serie, consistenza_dichiarata, collocazione_id.
     *
     * @param array<string, mixed> $body
     * @return array{0:?string, 1:?string, 2:?int, 3:?string} serie, consistenza, collocazione, error
     */
    private function annataOptionalFields(array $body): array
    {
        $serie = mb_substr(trim(strip_tags((string) ($body['serie'] ?? ''))), 0, 50);
        $consistenza = mb_substr(trim(strip_tags((string) ($body['consistenza_dichiarata'] ?? ''))), 0, 255);
        $collocazioneId = (int) ($body['collocazione_id'] ?? 0);
        $collocazioneId = $collocazioneId > 0 ? $collocazioneId : null;
        if ($collocazioneId !== null && !$this->collocazioneExists($collocazioneId)) {
            return [null, null, null, __('Collocazione non valida.')];
        }
        return [
            $serie === '' ? null : $serie,
            $consistenza === '' ? null : $consistenza,
            $collocazioneId,
            null,
        ];
    }

    /** @param array<string, mixed> $body */
    private function addFascicolo(int $testataId, array $body): void
    {
        $annataId = (int) ($body['annata_id'] ?? 0);
        if (!$this->annataBelongsTo($annataId, $testataId)) {
            $this->flashError(__('Annata non trovata per questa testata.'));
            return;
        }
        $numero = mb_substr(trim(strip_tags((string) ($body['numero'] ?? ''))), 0, 50);
        if ($numero === '') {
            $this->flashError(__('Il numero del fascicolo è obbligatorio.'));
            return;
        }
        $dataPub = trim((string) ($body['data_pubblicazione'] ?? ''));
        if ($dataPub !== '' && !$this->isValidDate($dataPub)) {
            $this->flashError(__('Data di pubblicazione non valida (formato AAAA-MM-GG).'));
            return;
        }
        $dataPubOrNull = $dataPub === '' ? null : $dataPub;
        // stato is validated, never coerced (review #140): it is the most
        // consequential column of the row (holdings, consistency, public
        // catalogue, claims), so an unknown value is an explicit error like
        // condizione/acquisizione/prezzo — not a silent slide into the most
        // optimistic state. Omitted entirely it is the documented default of
        // the quick form, which is harmless on a brand-new row.
        $stato = trim((string) ($body['stato'] ?? ''));
        if ($stato === '') {
            $stato = 'posseduto';
        } elseif (!array_key_exists($stato, \EmerotecaPlugin::STATI_FASCICOLO)) {
            $this->flashError(__('Stato del fascicolo non valido.'));
            return;
        }
        // The acquisition channel inherits the testata's default: typing the
        // same channel on every fascicolo is busywork.
        //
        // The barcode does NOT inherit barcode_base (review #140). That base
        // is the title's shared 977 EAN-13 and the column carries a
        // non-UNIQUE key, so copying it here would give EVERY fascicolo of a
        // title the same code: a scan at the desk would resolve to whichever
        // issue comes first (ExportAdminController::findIssueByBarcode orders
        // by id) — normally n. 1 — and the operator would record the arrival
        // on the wrong issue, leaving the real one 'atteso' and bound for the
        // supplier reminder. Nothing is lost by leaving it empty: the base is
        // already the third resolution step of the scan lookup and the
        // fallback of the label renderer. The column stays hand-fillable with
        // the FULL EAN including the add-on, which does identify the issue.
        $defaults = $this->testataDefaults($annataId);
        $acquisizione = $defaults['acquisizione_default'];
        $stmt = $this->db->prepare(
            'INSERT INTO emeroteca_fascicoli (annata_id, numero, data_pubblicazione, stato, acquisizione)
             VALUES (?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] addFascicolo prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante la creazione del fascicolo.'));
            return;
        }
        $stmt->bind_param('issss', $annataId, $numero, $dataPubOrNull, $stato, $acquisizione);
        if (!$stmt->execute()) {
            $duplicate = (int) $stmt->errno === 1062;
            SecureLogger::error('[Emeroteca] addFascicolo insert failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError($duplicate
                ? __('Un fascicolo con questo numero esiste già nell’annata.')
                : __('Errore durante la creazione del fascicolo.'));
            return;
        }
        $newId = (int) $this->db->insert_id;
        $stmt->close();
        ActivityLog::recordEntityEvent(
            $this->db,
            'emeroteca_fascicoli',
            $newId,
            'issue.created',
            [],
            [
                'annata_id'          => $annataId,
                'numero'             => $numero,
                'data_pubblicazione' => $dataPubOrNull,
                'stato'              => $stato,
                'acquisizione'       => $acquisizione,
            ],
            'inserimento',
            'admin'
        );
        $this->flashSuccess(sprintf(__('Fascicolo n. %s creato.'), $numero));
    }

    /**
     * Testata-level defaults inherited by a new fascicolo of the given
     * annata. Both entries are null on any failure — a missing default is
     * never a reason to refuse a fascicolo.
     *
     * @return array{barcode_base: ?string, acquisizione_default: ?string}
     */
    private function testataDefaults(int $annataId): array
    {
        $none = ['barcode_base' => null, 'acquisizione_default' => null];
        $stmt = $this->db->prepare(
            'SELECT t.barcode_base, t.acquisizione_default
               FROM emeroteca_annate a
               JOIN emeroteca_testate t ON a.testata_id = t.id
              WHERE a.id = ? LIMIT 1'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] testata defaults prepare failed: ' . $this->db->error);
            return $none;
        }
        $stmt->bind_param('i', $annataId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] testata defaults query failed: ' . $stmt->error);
            $stmt->close();
            return $none;
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            return $none;
        }
        $barcode = trim((string) ($row['barcode_base'] ?? ''));
        $acquisizione = trim((string) ($row['acquisizione_default'] ?? ''));
        return [
            'barcode_base' => $barcode === '' ? null : $barcode,
            'acquisizione_default' => $acquisizione === '' ? null : $acquisizione,
        ];
    }

    /**
     * Mark one awaited fascicolo of this testata as received.
     *
     * Accepts 'reclamato' as well as 'atteso' (1.4.0): a claimed issue that
     * finally arrives is the normal end of the claim cycle, and refusing it
     * here would leave the Kardex stuck on "reclamato" forever.
     *
     * The claim history (reclamato_il, n_reclami) is deliberately NOT reset:
     * "this issue took two reminders to arrive" is exactly the fact you want
     * when the subscription comes up for renewal.
     */
    private function receiveIssue(int $testataId, int $fascicoloId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE emeroteca_fascicoli f
              JOIN emeroteca_annate a ON f.annata_id = a.id
               SET f.stato = 'posseduto'
             WHERE f.id = ? AND a.testata_id = ? AND f.stato IN ('atteso', 'reclamato')"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] receiveIssue prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante l\'aggiornamento del fascicolo.'));
            return;
        }
        $stmt->bind_param('ii', $fascicoloId, $testataId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] receiveIssue failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError(__('Errore durante l\'aggiornamento del fascicolo.'));
            return;
        }
        $done = $stmt->affected_rows > 0;
        $stmt->close();
        if ($done) {
            ActivityLog::recordEntityEvent(
                $this->db,
                'emeroteca_fascicoli',
                $fascicoloId,
                'issue.updated',
                [],
                ['stato' => 'posseduto', 'ricevuto' => true],
                'aggiornamento',
                'admin'
            );
            $this->flashSuccess(__('Fascicolo marcato come posseduto.'));
        } else {
            $this->flashError(__('Nessun fascicolo atteso o reclamato da ricevere con questo id.'));
        }
    }

    /**
     * Kardex claim ("sollecito al fornitore") for ONE fascicolo: the issue
     * moves to 'reclamato', reclamato_il records the day of the reminder and
     * n_reclami counts how many have been sent.
     *
     * Eligible states are 'atteso' and 'reclamato' (a second reminder is
     * ordinary practice). The SELECT probe supplies the "before" snapshot of
     * the audit, but it does NOT authorise the write on its own: the eligible
     * states are repeated in the UPDATE's WHERE (review #140), so an issue
     * received at the desk between the probe and the UPDATE is not dragged
     * back to 'reclamato' — that would take a fascicolo physically on the
     * shelf out of the holdings and send a reminder for a copy already owned.
     *
     * affected_rows alone cannot drive the outcome: a re-claim on the same day
     * with n_reclami already at MAX_RECLAMI writes identical values and MySQL
     * reports zero changed rows. Zero is therefore disambiguated by re-reading
     * the row — still claimable means a harmless no-op, gone means the race.
     */
    private function claimIssue(int $testataId, int $fascicoloId): void
    {
        $current = $this->claimableIssue($testataId, $fascicoloId);
        if ($current === null) {
            $this->flashError(__('Nessun fascicolo atteso da sollecitare con questo id.'));
            return;
        }
        // Application timezone, not the DB session one: near midnight
        // CURDATE() and the library's "today" can disagree by a day.
        $today = DateHelper::today();
        $claimable = "'" . implode("','", self::CLAIMABLE) . "'";
        $stmt = $this->db->prepare(
            "UPDATE emeroteca_fascicoli
                SET stato = 'reclamato', reclamato_il = ?, n_reclami = LEAST(n_reclami + 1, ?)
              WHERE id = ? AND stato IN ({$claimable})"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] claimIssue prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante il sollecito del fascicolo.'));
            return;
        }
        $max = self::MAX_RECLAMI;
        $stmt->bind_param('sii', $today, $max, $fascicoloId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] claimIssue failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError(__('Errore durante il sollecito del fascicolo.'));
            return;
        }
        $changed = $stmt->affected_rows > 0;
        $stmt->close();

        if (!$changed) {
            if ($this->claimableIssue($testataId, $fascicoloId) === null) {
                $this->flashError(__('Il fascicolo ha cambiato stato nel frattempo: nessun sollecito è stato registrato.'));
                return;
            }
            // Idempotent repeat within the same day at MAX_RECLAMI: nothing
            // changed, so nothing is audited, but the operator's intent was
            // honoured — the issue IS claimed today.
            $this->flashSuccess(sprintf(__('Fascicolo n. %s sollecitato al fornitore.'), $current['numero']));
            return;
        }

        ActivityLog::recordEntityEvent(
            $this->db,
            'emeroteca_fascicoli',
            $fascicoloId,
            'issue.claimed',
            ['stato' => $current['stato'], 'n_reclami' => $current['n_reclami'], 'reclamato_il' => $current['reclamato_il']],
            ['stato' => 'reclamato', 'n_reclami' => min($current['n_reclami'] + 1, self::MAX_RECLAMI), 'reclamato_il' => $today],
            'aggiornamento',
            'admin'
        );
        $this->flashSuccess(sprintf(__('Fascicolo n. %s sollecitato al fornitore.'), $current['numero']));
    }

    /**
     * Bulk claim of every overdue awaited issue of ONE annata.
     *
     * "Overdue" means the issue should already be on the shelf:
     *   - its data_pubblicazione is in the past; or
     *   - the date is unknown (the norm for Kardex-generated issues) and the
     *     annata belongs to a closed year; or
     *   - the date is unknown, the annata IS the current year, but the issue's
     *     own slot in the publication schedule closed more than
     *     CLAIM_GRACE_DAYS ago (review #140). Without this last rule the bulk
     *     claim could never cover the running subscription year — precisely
     *     the year reminders exist for — because Kardex issues carry no date.
     *     It is deliberately narrow: it needs a known periodicita and a purely
     *     numeric numero, so a double issue ("1-2") or an irregular title is
     *     left to the per-issue claim rather than guessed at.
     *
     * Scope is deliberately ONE annata and ONLY stato='atteso' (never a
     * blanket re-claim of everything already claimed): a bulk that re-sends
     * reminders for issues claimed yesterday is how a library annoys its
     * supplier into ignoring the real ones. That same condition is repeated in
     * the UPDATE's WHERE, so an issue received at the desk while this request
     * was in flight is not dragged back out of the holdings, and the operator
     * is told how many rows really changed rather than how many the SELECT
     * had picked.
     */
    private function claimOverdue(int $testataId, int $annataId): void
    {
        // AdminAuthMiddleware also admits staff: a bulk claim rewrites a whole
        // annata in one statement and n_reclami is never decremented anywhere,
        // so there is no undo. Re-check the role inline, like delete().
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            $this->flashError(__('Operazione riservata agli amministratori.'));
            return;
        }
        if (!$this->annataBelongsTo($annataId, $testataId)) {
            $this->flashError(__('Annata non trovata per questa testata.'));
            return;
        }
        $today = DateHelper::today();
        $testata = $this->fetchTestata($testataId);
        $periodicita = (string) ($testata['periodicita'] ?? '');

        // Every awaited issue of the annata, with the fields the audit "before"
        // snapshot needs; the overdue decision itself is taken in PHP so the
        // schedule rule above stays readable. One annata is at most a year of
        // a daily (366 rows).
        /** @var array<int, array<string, mixed>> $candidates */
        $candidates = [];
        $stmt = $this->db->prepare(
            "SELECT f.id, f.numero, f.stato, f.n_reclami, f.reclamato_il,
                    f.data_pubblicazione, a.anno
               FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON f.annata_id = a.id
              WHERE f.annata_id = ? AND f.stato = 'atteso'"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] claimOverdue prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante il sollecito dei fascicoli.'));
            return;
        }
        $stmt->bind_param('i', $annataId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] claimOverdue select failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError(__('Errore durante il sollecito dei fascicoli.'));
            return;
        }
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                if ($this->isOverdue($row, $periodicita, $today)) {
                    $candidates[(int) $row['id']] = $row;
                }
            }
        }
        $stmt->close();

        if ($candidates === []) {
            $this->flashError(__('Nessun fascicolo atteso scaduto in questa annata.'));
            return;
        }

        $ids = array_keys($candidates);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $update = $this->db->prepare(
            "UPDATE emeroteca_fascicoli
                SET stato = 'reclamato', reclamato_il = ?, n_reclami = LEAST(n_reclami + 1, ?)
              WHERE id IN ({$placeholders}) AND stato = 'atteso'"
        );
        if ($update === false) {
            SecureLogger::error('[Emeroteca] claimOverdue update prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante il sollecito dei fascicoli.'));
            return;
        }
        $max = self::MAX_RECLAMI;
        $params = [$today, $max, ...$ids];
        $update->bind_param('si' . str_repeat('i', count($ids)), ...$params);
        if (!$update->execute()) {
            SecureLogger::error('[Emeroteca] claimOverdue update failed: ' . $update->error);
            $update->close();
            $this->flashError(__('Errore durante il sollecito dei fascicoli.'));
            return;
        }
        $claimed = (int) $update->affected_rows;
        $update->close();

        if ($claimed === 0) {
            $this->flashError(__('Nessun fascicolo atteso scaduto in questa annata.'));
            return;
        }

        // Audit as richly as the per-issue path (review #140): which issue,
        // from which state, with the claim counter before and after. Only the
        // rows that really moved are logged — the post-update re-read tells
        // them apart from the ones a concurrent receive took away.
        foreach ($this->claimedAfterBulk($ids, $today) as $claimedId => $after) {
            $before = $candidates[$claimedId] ?? null;
            if ($before === null) {
                continue;
            }
            ActivityLog::recordEntityEvent(
                $this->db,
                'emeroteca_fascicoli',
                $claimedId,
                'issue.claimed',
                [
                    'numero'       => (string) $before['numero'],
                    'stato'        => (string) $before['stato'],
                    'n_reclami'    => (int) $before['n_reclami'],
                    'reclamato_il' => $before['reclamato_il'] === null ? null : (string) $before['reclamato_il'],
                ],
                [
                    'numero'       => (string) $before['numero'],
                    'stato'        => (string) $after['stato'],
                    'n_reclami'    => (int) $after['n_reclami'],
                    'reclamato_il' => $after['reclamato_il'] === null ? null : (string) $after['reclamato_il'],
                    'bulk'         => true,
                ],
                'aggiornamento',
                'admin'
            );
        }
        $this->flashSuccess(sprintf(__('%d fascicoli attesi scaduti sollecitati al fornitore.'), $claimed));
    }

    /**
     * Is this awaited issue late enough to deserve a supplier reminder?
     *
     * @param array<string, mixed> $row id/numero/data_pubblicazione/anno of an
     *                                  awaited fascicolo
     */
    private function isOverdue(array $row, string $periodicita, string $today): bool
    {
        $dataPub = $row['data_pubblicazione'] === null ? '' : (string) $row['data_pubblicazione'];
        if ($dataPub !== '') {
            return $dataPub < $today;
        }
        $anno = (int) $row['anno'];
        $currentYear = (int) substr($today, 0, 4);
        if ($anno < $currentYear) {
            return true;
        }
        if ($anno !== $currentYear) {
            return false; // a future annata is not late, it is early
        }
        $numero = trim((string) $row['numero']);
        if ($periodicita === '' || preg_match('/^\d{1,4}$/', $numero) !== 1) {
            return false;
        }
        $due = $this->scheduledIssueDate($periodicita, $anno, (int) $numero);
        if ($due === null) {
            return false;
        }
        try {
            $deadline = (new \DateTimeImmutable($due))
                ->modify('+' . self::CLAIM_GRACE_DAYS . ' days')
                ->format('Y-m-d');
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] claim schedule deadline failed: ' . $e->getMessage());
            return false;
        }
        return $deadline < $today;
    }

    /**
     * Day the n-th issue of a year closes its slot, for a known periodicita:
     * the year is split into as many equal slots as the Kardex expects issues,
     * and issue n is due by the end of slot n. A monthly's n. 1 is therefore
     * due by 31 January, its n. 12 by 31 December.
     *
     * Null when the periodicita is unknown/irregolare or the number falls
     * outside the year's expected run — no schedule, no deadline, no claim.
     */
    private function scheduledIssueDate(string $periodicita, int $anno, int $numero): ?string
    {
        $perYear = self::kardexIssuesForYear($periodicita, $anno);
        if ($perYear === null || $perYear < 1 || $numero < 1 || $numero > $perYear) {
            return null;
        }
        try {
            $start = new \DateTimeImmutable(sprintf('%04d-01-01', $anno));
            $daysInYear = (int) $start->format('L') === 1 ? 366 : 365;
            $slotEnd = (int) ceil($numero * $daysInYear / $perYear);
            return $start->modify('+' . ($slotEnd - 1) . ' days')->format('Y-m-d');
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] claim schedule date failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Re-read the issues a bulk claim targeted and keep the ones that really
     * carry today's claim. The bulk UPDATE reports how many rows changed but
     * not which, and a row a concurrent receive pulled out of 'atteso' must
     * not be written into the log as claimed.
     *
     * @param  list<int> $ids
     * @return array<int, array{stato:string, n_reclami:int, reclamato_il:?string}>
     */
    private function claimedAfterBulk(array $ids, string $today): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, stato, n_reclami, reclamato_il
               FROM emeroteca_fascicoli
              WHERE id IN ({$placeholders}) AND stato = 'reclamato' AND reclamato_il = ?"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] claimOverdue audit re-read prepare failed: ' . $this->db->error);
            return [];
        }
        $params = [...$ids, $today];
        $stmt->bind_param(str_repeat('i', count($ids)) . 's', ...$params);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] claimOverdue audit re-read failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $out = [];
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[(int) $row['id']] = [
                    'stato'        => (string) $row['stato'],
                    'n_reclami'    => (int) $row['n_reclami'],
                    'reclamato_il' => $row['reclamato_il'] === null ? null : (string) $row['reclamato_il'],
                ];
            }
        }
        $stmt->close();
        return $out;
    }

    /**
     * The claimable state of one fascicolo of this testata, or null when the
     * id is unknown, belongs to another testata, or is not in a claimable
     * state.
     *
     * @return array{numero:string, stato:string, n_reclami:int, reclamato_il:?string}|null
     */
    private function claimableIssue(int $testataId, int $fascicoloId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT f.numero, f.stato, f.n_reclami, f.reclamato_il
               FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON f.annata_id = a.id
              WHERE f.id = ? AND a.testata_id = ? LIMIT 1'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] claimable probe prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('ii', $fascicoloId, $testataId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] claimable probe failed: ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row) || !in_array((string) $row['stato'], self::CLAIMABLE, true)) {
            return null;
        }
        return [
            'numero'       => (string) $row['numero'],
            'stato'        => (string) $row['stato'],
            'n_reclami'    => (int) $row['n_reclami'],
            'reclamato_il' => $row['reclamato_il'] === null ? null : (string) $row['reclamato_il'],
        ];
    }

    /**
     * End-of-year: every 'atteso' of an annata becomes 'mancante'.
     *
     * Destructive and one-way: a whole annata changes state in a single
     * statement and no action anywhere brings a 'mancante' back to 'atteso'.
     * AdminAuthMiddleware also admits staff, so the role is re-checked inline
     * (internal security scan 2026-07-25), like delete() and claimOverdue().
     */
    private function markMissing(int $testataId, int $annataId): void
    {
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            $this->flashError(__('Operazione riservata agli amministratori.'));
            return;
        }
        if (!$this->annataBelongsTo($annataId, $testataId)) {
            $this->flashError(__('Annata non trovata per questa testata.'));
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE emeroteca_fascicoli SET stato = 'mancante'
             WHERE annata_id = ? AND stato = 'atteso'"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] markMissing prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante l\'aggiornamento dei fascicoli.'));
            return;
        }
        $stmt->bind_param('i', $annataId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] markMissing failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError(__('Errore durante l\'aggiornamento dei fascicoli.'));
            return;
        }
        $n = $stmt->affected_rows;
        $stmt->close();
        $this->flashSuccess(sprintf(__('%d fascicoli attesi marcati come mancanti.'), $n));
    }

    // ── Bulk + Kardex ─────────────────────────────────────────────────

    /**
     * POST /admin/periodicals/{id}/issues/bulk — create a numbered
     * series of fascicoli (numero X..Y) inside the annata of the given
     * year, skipping numbers that already exist.
     *
     * @param array<string,string> $args
     */
    public function bulkCreate(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware
        $testataId = (int) ($args['id'] ?? 0);
        if ($this->fetchTestata($testataId) === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, '/admin/periodicals');
        }
        $back = '/admin/periodicals/' . $testataId . '/issues';
        $body = (array) $request->getParsedBody();

        $anno = (int) trim((string) ($body['anno'] ?? ''));
        $da   = (int) trim((string) ($body['numero_da'] ?? ''));
        $a    = (int) trim((string) ($body['numero_a'] ?? ''));
        // Validated, not coerced (review #140): see addFascicolo.
        $stato = trim((string) ($body['stato'] ?? ''));
        if ($stato === '') {
            $stato = 'posseduto';
        } elseif (!array_key_exists($stato, \EmerotecaPlugin::STATI_FASCICOLO)) {
            $this->flashError(__('Stato del fascicolo non valido.'));
            return $this->redirect($response, $back);
        }
        if ($anno < self::ANNO_MIN || $anno > self::ANNO_MAX) {
            $this->flashError(sprintf(__('Anno non plausibile (atteso tra %d e %d).'), self::ANNO_MIN, self::ANNO_MAX));
            return $this->redirect($response, $back);
        }
        if ($da < 1 || $a < $da || ($a - $da) >= 400) {
            $this->flashError(__('Intervallo numeri non valido (da ≥ 1, a ≥ da, massimo 400 fascicoli per volta).'));
            return $this->redirect($response, $back);
        }

        $annataId = $this->getOrCreateAnnata($testataId, $anno);
        if ($annataId === null) {
            $this->flashError(__('Errore durante la creazione dell\'annata.'));
            return $this->redirect($response, $back);
        }

        [$created, $skipped, $success] = $this->insertNumberedIssues($annataId, $da, $a, $stato);
        if (!$success) {
            $this->flashError(__('Errore durante la creazione della serie di fascicoli. Nessun fascicolo è stato aggiunto.'));
            return $this->redirect($response, $back);
        }
        $this->flashSuccess(sprintf(
            __('Serie creata: %d fascicoli aggiunti, %d già presenti saltati.'),
            $created,
            $skipped
        ));
        return $this->redirect($response, $back);
    }

    /**
     * POST /admin/periodicals/{id}/kardex/generate — for a testata with
     * a known periodicita, create the expected issues (stato 'atteso')
     * for the chosen year, skipping existing numbers.
     *
     * @param array<string,string> $args
     */
    public function kardexGenerate(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware
        $testataId = (int) ($args['id'] ?? 0);
        $testata = $this->fetchTestata($testataId);
        if ($testata === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, '/admin/periodicals');
        }
        $back = '/admin/periodicals/' . $testataId . '/issues';

        $periodicita = (string) ($testata['periodicita'] ?? '');
        if (!isset(\EmerotecaPlugin::kardexIssuesPerYear()[$periodicita])) {
            $this->flashError(__('Il Kardex richiede una periodicità nota (non irregolare): impostala nella scheda della testata.'));
            return $this->redirect($response, $back);
        }

        $body = (array) $request->getParsedBody();
        $anno = (int) trim((string) ($body['anno'] ?? ''));
        if ($anno < self::ANNO_MIN || $anno > self::ANNO_MAX) {
            $this->flashError(sprintf(__('Anno non plausibile (atteso tra %d e %d).'), self::ANNO_MIN, self::ANNO_MAX));
            return $this->redirect($response, $back);
        }
        // Calendar-aware count (#140): leap years and long ISO years
        // change the expected issues for dailies and weeklies.
        $expectedCount = self::kardexIssuesForYear($periodicita, $anno);
        if ($expectedCount === null) {
            $this->flashError(__('Il Kardex richiede una periodicità nota (non irregolare): impostala nella scheda della testata.'));
            return $this->redirect($response, $back);
        }

        $annataId = $this->getOrCreateAnnata($testataId, $anno);
        if ($annataId === null) {
            $this->flashError(__('Errore durante la creazione dell\'annata.'));
            return $this->redirect($response, $back);
        }

        [$created, $skipped, $success] = $this->insertNumberedIssues($annataId, 1, $expectedCount, 'atteso');
        if (!$success) {
            $this->flashError(__('Errore durante la generazione del Kardex. Nessun fascicolo è stato aggiunto.'));
            return $this->redirect($response, $back);
        }
        $this->flashSuccess(sprintf(
            __('Kardex %d generato: %d fascicoli attesi creati, %d già presenti saltati.'),
            $anno,
            $created,
            $skipped
        ));
        return $this->redirect($response, $back);
    }

    /**
     * Calendar-aware Kardex count for one year (review #140):
     * - quotidiano: 365 or 366 (Gregorian leap year);
     * - settimanale: 52 or 53 (ISO-8601 weeks — the ISO week number of
     *   December 28th, which always falls in the last ISO week);
     * - other known periodicita: the fixed EmerotecaPlugin value;
     * - unknown/irregolare: null (no expected issues can be generated).
     */
    public static function kardexIssuesForYear(string $periodicita, int $anno): ?int
    {
        $perYear = \EmerotecaPlugin::kardexIssuesPerYear();
        if (!isset($perYear[$periodicita])) {
            return null;
        }
        if ($periodicita === 'quotidiano') {
            $leap = ($anno % 4 === 0 && $anno % 100 !== 0) || $anno % 400 === 0;
            return $leap ? 366 : 365;
        }
        if ($periodicita === 'settimanale') {
            try {
                // 'W' = ISO-8601 week number; Dec 28 is always in the
                // last ISO week of its year (52 or 53).
                return (int) (new \DateTimeImmutable(sprintf('%04d-12-28', $anno)))->format('W');
            } catch (\Throwable $e) {
                return $perYear[$periodicita];
            }
        }
        return $perYear[$periodicita];
    }

    // ── Fascicolo detail ──────────────────────────────────────────────

    /**
     * GET /admin/periodicals/issue/{id} — full fascicolo form with
     * cover upload and spoglio (article rows).
     *
     * @param array<string,string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $fascicolo = $this->fetchFascicolo($id);
        if ($fascicolo === null) {
            $this->flashError(__('Fascicolo non trovato.'));
            return $this->redirect($response, '/admin/periodicals');
        }

        $articoli = [];
        $stmt = $this->db->prepare(
            'SELECT * FROM emeroteca_articoli WHERE fascicolo_id = ?
             ORDER BY pagina_inizio IS NULL, pagina_inizio, id'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($row = $res->fetch_assoc()) {
                        $articoli[] = $row;
                    }
                }
            } else {
                SecureLogger::error('[Emeroteca] articoli query failed: ' . $stmt->error);
            }
            $stmt->close();
        } else {
            SecureLogger::error('[Emeroteca] articoli prepare failed: ' . $this->db->error);
        }

        return $this->renderView($response, 'issue', [
            'fascicolo' => $fascicolo,
            'articoli'  => $articoli,
            'collocazioni' => $this->fetchCollocazioni(),
        ]);
    }

    /**
     * POST /admin/periodicals/issue/{id} — update every fascicolo field,
     * handle the optional cover upload, then replace the spoglio rows
     * with the posted ones (delete + reinsert in one transaction).
     *
     * @param array<string,string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware
        $id = (int) ($args['id'] ?? 0);
        $fascicolo = $this->fetchFascicolo($id);
        if ($fascicolo === null) {
            $this->flashError(__('Fascicolo non trovato.'));
            return $this->redirect($response, '/admin/periodicals');
        }
        $back = '/admin/periodicals/issue/' . $id;
        $body = (array) $request->getParsedBody();

        $str = static function (string $key, int $max) use ($body): ?string {
            $v = trim(strip_tags((string) ($body[$key] ?? '')));
            return $v === '' ? null : mb_substr($v, 0, $max);
        };

        $numero = $str('numero', 50);
        if ($numero === null) {
            $this->flashError(__('Il numero del fascicolo è obbligatorio.'));
            return $this->redirect($response, $back);
        }
        $dataPub = trim((string) ($body['data_pubblicazione'] ?? ''));
        if ($dataPub !== '' && !$this->isValidDate($dataPub)) {
            $this->flashError(__('Data di pubblicazione non valida (formato AAAA-MM-GG).'));
            return $this->redirect($response, $back);
        }
        $dataPubOrNull = $dataPub === '' ? null : $dataPub;
        // stato is the most consequential column of the row: it drives the
        // holdings, the consistency string, the public catalogue and the
        // claims. It is therefore validated like its neighbours (condizione,
        // acquisizione, prezzo) and NEVER coerced (review #140) — an unknown
        // value aborts the save instead of sliding the issue into the most
        // optimistic state. Absent from the POST it keeps the state the row
        // already has: editing an unrelated field must not silently turn a
        // 'mancante' fascicolo into a 'posseduto' one.
        $statoRaw = trim((string) ($body['stato'] ?? ''));
        if ($statoRaw === '') {
            $stato = (string) ($fascicolo['stato'] ?? 'posseduto');
        } elseif (array_key_exists($statoRaw, \EmerotecaPlugin::STATI_FASCICOLO)) {
            $stato = $statoRaw;
        } else {
            $this->flashError(__('Stato del fascicolo non valido.'));
            return $this->redirect($response, $back);
        }
        // Pages column is SMALLINT: values over 32767 are an explicit
        // validation error, not a silent NULL (#140). Checked before the
        // uploads so a rejected value never orphans a stored file.
        $pagineRaw = trim((string) ($body['pagine'] ?? ''));
        $pagine = null;
        if ($pagineRaw !== '') {
            if (preg_match('/^\d+$/', $pagineRaw) !== 1) {
                $this->flashError(__('Numero di pagine non valido.'));
                return $this->redirect($response, $back);
            }
            if ((int) $pagineRaw > 32767) {
                $this->flashError(__('Numero di pagine non valido: il massimo consentito è 32767.'));
                return $this->redirect($response, $back);
            }
            $pagine = (int) $pagineRaw;
        }
        // 1.4.0 fields. Validated here, BEFORE the uploads, for the same
        // reason as pagine: a rejected value must not leave a stored file
        // behind with no row pointing at it.
        $condizione = trim((string) ($body['condizione'] ?? ''));
        if ($condizione !== '' && !array_key_exists($condizione, \EmerotecaPlugin::COND_FASCICOLO)) {
            $this->flashError(__('Condizione del fascicolo non valida.'));
            return $this->redirect($response, $back);
        }
        $condizioneOrNull = $condizione === '' ? null : $condizione;
        $acquisizione = trim((string) ($body['acquisizione'] ?? ''));
        if ($acquisizione !== '' && !array_key_exists($acquisizione, \EmerotecaPlugin::TIPI_ACQUISIZIONE)) {
            $this->flashError(__('Modalità di acquisizione non valida.'));
            return $this->redirect($response, $back);
        }
        $acquisizioneOrNull = $acquisizione === '' ? null : $acquisizione;
        // DECIMAL(8,2); both decimal separators accepted (see the testata form).
        $prezzoRaw = trim((string) ($body['prezzo'] ?? ''));
        $prezzo = null;
        if ($prezzoRaw !== '') {
            $prezzoNorm = str_replace(',', '.', $prezzoRaw);
            if (preg_match('/^\d{1,6}(\.\d{1,2})?$/', $prezzoNorm) !== 1) {
                $this->flashError(__('Prezzo non valido (usa un numero con al massimo due decimali).'));
                return $this->redirect($response, $back);
            }
            $prezzo = (float) $prezzoNorm;
        }
        // Barcode: EAN-13 (13 digits) plus an optional add-on that encodes
        // the issue — up to 18 characters in total, which is why the column
        // is wider than a plain EAN-13.
        //
        // Left empty it STAYS empty (review #140). It used to be back-filled
        // with the testata's barcode_base, which handed every fascicolo of a
        // title the same code: the column has a non-UNIQUE key, so a scan at
        // the desk resolved to whichever issue came first — normally n. 1 —
        // and the operator recorded the arrival on the wrong issue while the
        // real one stayed 'atteso' and went into the supplier reminder.
        // Nothing is lost: the base is already the third resolution step of
        // the scan lookup and the fallback of the label renderer, so an empty
        // barcode still resolves to the title. What belongs HERE is the full
        // EAN with its add-on, which genuinely identifies the issue.
        $barcode = mb_substr(trim(strip_tags((string) ($body['barcode'] ?? ''))), 0, 18);
        if ($barcode !== '' && preg_match('/^\d{8,18}$/', $barcode) !== 1) {
            $this->flashError(__('Barcode non valido: sono ammesse solo cifre (EAN-13 con eventuale add-on).'));
            return $this->redirect($response, $back);
        }
        $barcodeOrNull = $barcode === '' ? null : $barcode;

        // Uppy feeds these hidden multipart inputs. Files are validated and
        // moved server-side only after the ordinary issue fields pass.
        $previousCover = (string) ($fascicolo['copertina_url'] ?? '');
        $copertinaUrl = $previousCover;
        $newCoverUploaded = false;
        $files = $request->getUploadedFiles();
        if (isset($files['copertina'])
            && $files['copertina'] instanceof \Psr\Http\Message\UploadedFileInterface
            && $files['copertina']->getError() === UPLOAD_ERR_OK) {
            $result = $this->storeManagedImage($files['copertina'], 'fascicolo');
            if (!$result['success']) {
                $this->flashError((string) $result['message']);
                return $this->redirect($response, $back);
            }
            $copertinaUrl = (string) $result['path'];
            $newCoverUploaded = true;
        } elseif (isset($files['copertina'])
            && $files['copertina'] instanceof \Psr\Http\Message\UploadedFileInterface
            && $files['copertina']->getError() !== UPLOAD_ERR_NO_FILE) {
            $this->flashError(__('Errore durante l\'upload.'));
            return $this->redirect($response, $back);
        }
        $copertinaOrNull = $copertinaUrl === '' ? null : $copertinaUrl;

        $previousPdf = (string) ($fascicolo['pdf_path'] ?? '');
        $pdfPath = $previousPdf;
        $pdfOriginalName = $fascicolo['pdf_nome_originale'] !== null
            ? (string) $fascicolo['pdf_nome_originale']
            : null;
        $pdfSize = $fascicolo['pdf_dimensione'] !== null
            ? (int) $fascicolo['pdf_dimensione']
            : null;
        $newPdfUploaded = false;
        $removePdf = isset($body['rimuovi_pdf']) && (string) $body['rimuovi_pdf'] === '1';
        if ($removePdf) {
            $pdfPath = '';
            $pdfOriginalName = null;
            $pdfSize = null;
        }
        if (isset($files['pdf_file'])
            && $files['pdf_file'] instanceof \Psr\Http\Message\UploadedFileInterface
            && $files['pdf_file']->getError() === UPLOAD_ERR_OK) {
            $pdfResult = $this->storeManagedPdf($files['pdf_file']);
            if (!$pdfResult['success']) {
                if ($newCoverUploaded) {
                    $this->deleteUploadedCover($copertinaUrl);
                }
                $this->flashError((string) $pdfResult['message']);
                return $this->redirect($response, $back);
            }
            $pdfPath = (string) $pdfResult['path'];
            $pdfOriginalName = (string) $pdfResult['original_name'];
            $pdfSize = (int) $pdfResult['size'];
            $newPdfUploaded = true;
        } elseif (isset($files['pdf_file'])
            && $files['pdf_file'] instanceof \Psr\Http\Message\UploadedFileInterface
            && $files['pdf_file']->getError() !== UPLOAD_ERR_NO_FILE) {
            if ($newCoverUploaded) {
                $this->deleteUploadedCover($copertinaUrl);
            }
            $this->flashError(__('Errore durante l\'upload.'));
            return $this->redirect($response, $back);
        }
        $pdfPathOrNull = $pdfPath === '' ? null : $pdfPath;
        // New scans are private by default. The checkbox must be explicitly
        // posted on each save to expose the protected public route.
        $pdfPubblico = $pdfPathOrNull !== null && isset($body['pdf_pubblico']) ? 1 : 0;

        $numeroProgressivo = $str('numero_progressivo', 50);
        $titoloFascicolo   = $str('titolo_fascicolo', 255);
        $dataCopertina     = $str('data_copertina', 100);
        $numeroInventario  = $str('numero_inventario', 100);
        $collocazioneId = array_key_exists('collocazione_id', $body)
            ? (int) ($body['collocazione_id'] ?? 0)
            : (int) ($fascicolo['collocazione_id'] ?? 0);
        $collocazioneId = $collocazioneId > 0 ? $collocazioneId : null;
        if ($collocazioneId !== null && !$this->collocazioneExists($collocazioneId)) {
            if ($newCoverUploaded) {
                $this->deleteUploadedCover($copertinaUrl);
            }
            if ($newPdfUploaded) {
                $this->deleteManagedPdfIfUnreferenced($pdfPath);
            }
            $this->flashError(__('Collocazione non valida.'));
            return $this->redirect($response, $back);
        }
        $supplementi       = $str('supplementi', 500);
        $note              = $str('note', 65535);

        // Never nest: begin_transaction() inside a caller's transaction
        // implicitly commits it, and an unconditional rollback() would undo
        // work this method does not own (review #140).
        $ownsTx = !$this->hasActiveTransaction();
        try {
            if ($ownsTx && !$this->db->begin_transaction()) {
                throw new \RuntimeException('could not start issue-save transaction');
            }
            $stmt = $this->db->prepare(
                'UPDATE emeroteca_fascicoli SET
                    numero = ?, numero_progressivo = ?, titolo_fascicolo = ?,
                    data_copertina = ?, data_pubblicazione = ?, pagine = ?,
                    copertina_url = ?, numero_inventario = ?, barcode = ?,
                    collocazione_id = ?, stato = ?, condizione = ?, acquisizione = ?,
                    prezzo = ?, supplementi = ?, note = ?, pdf_path = ?,
                    pdf_nome_originale = ?, pdf_dimensione = ?, pdf_pubblico = ?
                 WHERE id = ?'
            );
            if ($stmt === false) {
                throw new \RuntimeException('issue update prepare failed: ' . $this->db->error);
            }
            $stmt->bind_param(
                'sssssisssisssdssssiii',
                $numero,
                $numeroProgressivo,
                $titoloFascicolo,
                $dataCopertina,
                $dataPubOrNull,
                $pagine,
                $copertinaOrNull,
                $numeroInventario,
                $barcodeOrNull,
                $collocazioneId,
                $stato,
                $condizioneOrNull,
                $acquisizioneOrNull,
                $prezzo,
                $supplementi,
                $note,
                $pdfPathOrNull,
                $pdfOriginalName,
                $pdfSize,
                $pdfPubblico,
                $id
            );
            if (!$stmt->execute()) {
                $errno = (int) $stmt->errno;
                $error = $stmt->error;
                $stmt->close();
                // Carry the duplicate-key errno so the catch can show the
                // specific numero/annata message (same as addFascicolo).
                throw new \RuntimeException('issue update failed: ' . $error, $errno === 1062 ? 1062 : 0);
            }
            $stmt->close();
            $this->replaceArticles($id, $body);
            if ($ownsTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTx) {
                try {
                    $this->db->rollback();
                } catch (\Throwable $rollbackError) {
                    SecureLogger::error('[Emeroteca] issue save rollback failed: ' . $rollbackError->getMessage());
                }
            }
            // When a caller owns the transaction the undo is the caller's:
            // rolling back here would discard work outside this save. As a
            // route handler this branch cannot be reached — the log line is
            // the trace if it ever is.
            SecureLogger::error('[Emeroteca] atomic issue save failed: ' . $e->getMessage());
            if ($newCoverUploaded) {
                $this->deleteUploadedCover($copertinaUrl);
            }
            if ($newPdfUploaded) {
                $this->deleteManagedPdfIfUnreferenced($pdfPath);
            }
            $this->flashError($e->getCode() === 1062
                ? __('Un fascicolo con questo numero esiste già nell’annata.')
                : __('Errore durante il salvataggio del fascicolo. Nessuna modifica è stata applicata.'));
            return $this->redirect($response, $back);
        }

        // Audit: the ordinary field diff, plus a SEPARATE event whenever the
        // public visibility of the scan flips. The PDF toggle is the one
        // privacy-relevant action here — it publishes (or withdraws) a whole
        // document on the public catalogue — so it gets its own event
        // instead of hiding inside a 20-field diff.
        $previousPubblico = (int) ($fascicolo['pdf_pubblico'] ?? 0);
        ActivityLog::recordEntityEvent(
            $this->db,
            'emeroteca_fascicoli',
            $id,
            'issue.updated',
            self::auditSnapshot($fascicolo),
            self::auditSnapshot([
                'numero'             => $numero,
                'numero_progressivo' => $numeroProgressivo,
                'titolo_fascicolo'   => $titoloFascicolo,
                'data_copertina'     => $dataCopertina,
                'data_pubblicazione' => $dataPubOrNull,
                'pagine'             => $pagine,
                'numero_inventario'  => $numeroInventario,
                'barcode'            => $barcodeOrNull,
                'collocazione_id'    => $collocazioneId,
                'stato'              => $stato,
                'condizione'         => $condizioneOrNull,
                'acquisizione'       => $acquisizioneOrNull,
                'prezzo'             => $prezzo,
                // Carried through unchanged so the diff does not read as if
                // every save wiped the claim history (review #140): the
                // "before" snapshot has them, this form does not touch them,
                // and a field present on one side only looks like a deletion.
                'reclamato_il'       => $fascicolo['reclamato_il'] ?? null,
                'n_reclami'          => $fascicolo['n_reclami'] ?? null,
                'supplementi'        => $supplementi,
                'pdf_pubblico'       => $pdfPubblico,
            ]),
            'aggiornamento',
            'admin'
        );
        if ($previousPubblico !== $pdfPubblico) {
            ActivityLog::recordEntityEvent(
                $this->db,
                'emeroteca_fascicoli',
                $id,
                'issue.pdf_visibility',
                ['pdf_pubblico' => $previousPubblico],
                ['pdf_pubblico' => $pdfPubblico],
                'aggiornamento',
                'admin'
            );
        }

        // The UPDATE succeeded with a freshly uploaded cover: the old
        // file is now orphaned unless another row still references it.
        if ($newCoverUploaded && $previousCover !== '' && $previousCover !== $copertinaUrl) {
            $this->deleteManagedImageIfUnreferenced($previousCover);
        }
        if (($newPdfUploaded || $removePdf) && $previousPdf !== '' && $previousPdf !== $pdfPath) {
            $this->deleteManagedPdfIfUnreferenced($previousPdf);
        }

        $this->flashSuccess(__('Fascicolo salvato con successo.'));
        return $this->redirect($response, $back);
    }

    /**
     * POST /admin/periodicals/issue/{id}/delete — delete the fascicolo
     * (articles follow via CASCADE) and go back to the issues page.
     *
     * @param array<string,string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware.
        // Destructive action: AdminAuthMiddleware also admits staff, so
        // re-check the role inline (internal security scan 2026-07-25).
        $id = (int) ($args['id'] ?? 0);
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            $this->flashError(__('Operazione riservata agli amministratori.'));
            return $this->redirect($response, '/admin/periodicals/issue/' . $id);
        }
        $fascicolo = $this->fetchFascicolo($id);
        if ($fascicolo === null) {
            $this->flashError(__('Fascicolo non trovato.'));
            return $this->redirect($response, '/admin/periodicals');
        }
        $testataId = (int) $fascicolo['testata_id'];

        $stmt = $this->db->prepare('DELETE FROM emeroteca_fascicoli WHERE id = ?');
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] issue delete prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante l\'eliminazione del fascicolo.'));
            return $this->redirect($response, '/admin/periodicals/issue/' . $id);
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] issue delete failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError(__('Errore durante l\'eliminazione del fascicolo.'));
            return $this->redirect($response, '/admin/periodicals/issue/' . $id);
        }
        $stmt->close();

        ActivityLog::recordEntityEvent(
            $this->db,
            'emeroteca_fascicoli',
            $id,
            'issue.deleted',
            self::auditSnapshot($fascicolo),
            [],
            'cancellazione',
            'admin'
        );

        $this->deleteManagedImageIfUnreferenced((string) ($fascicolo['copertina_url'] ?? ''));
        $this->deleteManagedPdfIfUnreferenced((string) ($fascicolo['pdf_path'] ?? ''));

        $this->flashSuccess(__('Fascicolo eliminato.'));
        return $this->redirect($response, '/admin/periodicals/' . $testataId . '/issues');
    }

    // ── Internals ─────────────────────────────────────────────────────

    /**
     * Detect both autocommit(false) and an explicit begin_transaction() (the
     * latter leaves @@autocommit enabled), using the same disposable savepoint
     * probe as App\Models\GenereRepository.
     *
     * PRIVATE COPY of PeriodicalAdminController::hasActiveTransaction() — that
     * one is the original and the two must stay identical. It lives on the
     * concrete sibling class rather than on the shared
     * AbstractAdminController, which belongs to another workstream; when the
     * probe is eventually promoted to the parent, delete both copies rather
     * than letting them drift.
     */
    private function hasActiveTransaction(): bool
    {
        $result = $this->db->query('SELECT @@autocommit AS ac');
        if ($result instanceof \mysqli_result) {
            $row = $result->fetch_assoc();
            $result->free();
            if ((int) ($row['ac'] ?? 1) === 0) {
                return true;
            }
        }

        $probe = 'pinakes_emeroteca_probe_' . bin2hex(random_bytes(6));
        $probeCreated = false;
        try {
            if (!$this->db->query("SAVEPOINT {$probe}")) {
                return false;
            }
            $probeCreated = true;
            if (!$this->db->query("ROLLBACK TO SAVEPOINT {$probe}")) {
                return false;
            }
            return true;
        } catch (\mysqli_sql_exception) {
            return false;
        } finally {
            if ($probeCreated) {
                try {
                    $this->db->query("RELEASE SAVEPOINT {$probe}");
                } catch (\mysqli_sql_exception) {
                    // The caller still owns its transaction; a failed cleanup
                    // of this disposable probe must not change that.
                }
            }
        }
    }

    /**
     * Reduce a fascicolo row (or the freshly validated values) to the fields
     * worth auditing. Timestamps and internal file paths are left out: they
     * add noise, and pdf_path in particular is a private storage location
     * that has no business being duplicated into the log.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function auditSnapshot(array $row): array
    {
        $fields = [
            'annata_id', 'numero', 'numero_progressivo', 'titolo_fascicolo',
            'data_copertina', 'data_pubblicazione', 'pagine', 'numero_inventario',
            'barcode', 'collocazione_id', 'stato', 'condizione', 'acquisizione',
            'prezzo', 'reclamato_il', 'n_reclami', 'supplementi', 'pdf_pubblico',
        ];
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $out[$field] = $row[$field];
            }
        }
        return $out;
    }

    /**
     * Fascicolo + its annata/testata context (annata anno, testata id
     * and titolo), or null when unknown.
     *
     * @return array<string, mixed>|null
     */
    private function fetchFascicolo(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT f.*, a.anno, a.volume, a.testata_id, t.titolo AS testata_titolo,
                    t.barcode_base AS testata_barcode_base
               FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON f.annata_id = a.id
               JOIN emeroteca_testate t ON a.testata_id = t.id
              WHERE f.id = ?'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] fetchFascicolo prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] fetchFascicolo failed: ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    private function annataBelongsTo(int $annataId, int $testataId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c FROM emeroteca_annate WHERE id = ? AND testata_id = ?'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] annataBelongsTo prepare failed: ' . $this->db->error);
            return false;
        }
        $stmt->bind_param('ii', $annataId, $testataId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] annataBelongsTo failed: ' . $stmt->error);
            $stmt->close();
            return false;
        }
        $res = $stmt->get_result();
        $ok = $res instanceof \mysqli_result
            && ((int) ($res->fetch_assoc()['c'] ?? 0)) > 0;
        $stmt->close();
        return $ok;
    }

    /**
     * Annata id for (testata, anno, volume ''), created when missing.
     * Volume '' (not NULL) so UNIQUE(testata_id, anno, volume) bites.
     */
    private function getOrCreateAnnata(int $testataId, int $anno): ?int
    {
        $found = $this->findAnnataId($testataId, $anno);
        if ($found !== null) {
            return $found;
        }

        $empty = '';
        $ins = $this->db->prepare(
            'INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, ?, ?)'
        );
        if ($ins === false) {
            SecureLogger::error('[Emeroteca] getOrCreateAnnata insert prepare failed: ' . $this->db->error);
            return null;
        }
        $ins->bind_param('iis', $testataId, $anno, $empty);
        if (!$ins->execute()) {
            $dup = $this->db->errno === 1062;
            SecureLogger::error('[Emeroteca] getOrCreateAnnata insert failed: ' . $ins->error);
            $ins->close();
            if ($dup) {
                // Lost a race against a concurrent request that created
                // the same annata: fetch the winner's row.
                return $this->findAnnataId($testataId, $anno);
            }
            return null;
        }
        $newId = (int) $this->db->insert_id;
        $ins->close();
        return $newId;
    }

    /** Annata id for (testata, anno, volume ''/NULL), or null when absent. */
    private function findAnnataId(int $testataId, int $anno): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM emeroteca_annate
              WHERE testata_id = ? AND anno = ? AND (volume = '' OR volume IS NULL)
              ORDER BY id LIMIT 1"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] getOrCreateAnnata prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('ii', $testataId, $anno);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] getOrCreateAnnata select failed: ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? (int) $row['id'] : null;
    }

    /**
     * Insert issues numbered $from..$to into an annata with the given
     * stato, skipping numbers that already exist there.
     *
     * @return array{0:int, 1:int, 2:bool} [created, skipped, success]
     */
    private function insertNumberedIssues(int $annataId, int $from, int $to, string $stato): array
    {
        $created = 0;
        $skipped = 0;
        // See update(): never nest, never roll back a transaction owned by a
        // caller (review #140).
        $ownsTx = !$this->hasActiveTransaction();
        try {
            if ($ownsTx && !$this->db->begin_transaction()) {
                throw new \RuntimeException('could not start bulk-create transaction');
            }
            // Serialize generators for the same annata. Without this parent-row
            // lock, two concurrent Kardex requests can both pass the pre-check.
            $lock = $this->db->prepare('SELECT id FROM emeroteca_annate WHERE id = ? FOR UPDATE');
            if ($lock === false) {
                throw new \RuntimeException('annata lock prepare failed: ' . $this->db->error);
            }
            $lock->bind_param('i', $annataId);
            if (!$lock->execute() || $lock->get_result()->fetch_row() === null) {
                $error = $lock->error;
                $lock->close();
                throw new \RuntimeException('annata lock failed: ' . $error);
            }
            $lock->close();

            /** @var array<string, true> $existing */
            $existing = [];
            $stmt = $this->db->prepare('SELECT numero FROM emeroteca_fascicoli WHERE annata_id = ?');
            if ($stmt === false) {
                throw new \RuntimeException('issue lookup prepare failed: ' . $this->db->error);
            }
            $stmt->bind_param('i', $annataId);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new \RuntimeException('issue lookup failed: ' . $error);
            }
            $res = $stmt->get_result();
            if ($res instanceof \mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    $existing[(string) $row['numero']] = true;
                }
            }
            $stmt->close();

            $ins = $this->db->prepare(
                'INSERT INTO emeroteca_fascicoli (annata_id, numero, stato) VALUES (?, ?, ?)'
            );
            if ($ins === false) {
                throw new \RuntimeException('issue insert prepare failed: ' . $this->db->error);
            }
            for ($n = $from; $n <= $to; $n++) {
                $numero = (string) $n;
                if (isset($existing[$numero])) {
                    $skipped++;
                    continue;
                }
                $ins->bind_param('iss', $annataId, $numero, $stato);
                if (!$ins->execute()) {
                    $error = $ins->error;
                    $ins->close();
                    throw new \RuntimeException('issue insert failed for n. ' . $numero . ': ' . $error);
                }
                $existing[$numero] = true;
                $created++;
            }
            $ins->close();
            if ($ownsTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTx) {
                try {
                    $this->db->rollback();
                } catch (\Throwable $rollbackError) {
                    SecureLogger::error('[Emeroteca] bulk-create rollback failed: ' . $rollbackError->getMessage());
                }
            }
            SecureLogger::error('[Emeroteca] insertNumberedIssues: ' . $e->getMessage());
            return [0, 0, false];
        }
        return [$created, $skipped, true];
    }

    /**
     * Replace the spoglio of a fascicolo with the posted rows (parallel
     * arrays art_titolo[], art_autori[], art_pag_da[], art_pag_a[],
     * art_tipo[], art_keywords[] — one entry per row, aligned by index).
     * The caller owns the transaction around the issue update and this
     * delete + reinsert operation. Row ids churn, but no table references
     * emeroteca_articoli.id.
     *
     * @param array<string, mixed> $body
     */
    private function replaceArticles(int $fascicoloId, array $body): void
    {
        $titoli   = is_array($body['art_titolo'] ?? null) ? array_values($body['art_titolo']) : [];
        $autori   = is_array($body['art_autori'] ?? null) ? array_values($body['art_autori']) : [];
        $pagDa    = is_array($body['art_pag_da'] ?? null) ? array_values($body['art_pag_da']) : [];
        $pagA     = is_array($body['art_pag_a'] ?? null) ? array_values($body['art_pag_a']) : [];
        $tipi     = is_array($body['art_tipo'] ?? null) ? array_values($body['art_tipo']) : [];
        $keywords = is_array($body['art_keywords'] ?? null) ? array_values($body['art_keywords']) : [];

        $rows = [];
        foreach ($titoli as $i => $titolo) {
            $titolo = mb_substr(trim(strip_tags((string) $titolo)), 0, 500);
            if ($titolo === '') {
                continue; // empty rows (e.g. the blank template) are dropped
            }
            $tipo = trim((string) ($tipi[$i] ?? 'articolo'));
            if (!array_key_exists($tipo, \EmerotecaPlugin::TIPI_ARTICOLO)) {
                $tipo = 'articolo';
            }
            $pi = trim((string) ($pagDa[$i] ?? ''));
            $pf = trim((string) ($pagA[$i] ?? ''));
            $rows[] = [
                'titolo'        => $titolo,
                'autori'        => mb_substr(trim(strip_tags((string) ($autori[$i] ?? ''))), 0, 500),
                'pagina_inizio' => preg_match('/^\d+$/', $pi) ? min((int) $pi, 32767) : null,
                'pagina_fine'   => preg_match('/^\d+$/', $pf) ? min((int) $pf, 32767) : null,
                'tipo'          => $tipo,
                'keywords'      => mb_substr(trim(strip_tags((string) ($keywords[$i] ?? ''))), 0, 500),
            ];
        }

        $del = $this->db->prepare('DELETE FROM emeroteca_articoli WHERE fascicolo_id = ?');
        if ($del === false) {
            throw new \RuntimeException('delete prepare failed: ' . $this->db->error);
        }
        $del->bind_param('i', $fascicoloId);
        if (!$del->execute()) {
            $err = $del->error;
            $del->close();
            throw new \RuntimeException('delete failed: ' . $err);
        }
        $del->close();

        if ($rows === []) {
            return;
        }
        $ins = $this->db->prepare(
            'INSERT INTO emeroteca_articoli
                (fascicolo_id, titolo, autori, pagina_inizio, pagina_fine, tipo, keywords)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if ($ins === false) {
            throw new \RuntimeException('insert prepare failed: ' . $this->db->error);
        }
        foreach ($rows as $r) {
            $autoriVal = $r['autori'] === '' ? null : $r['autori'];
            $kwVal     = $r['keywords'] === '' ? null : $r['keywords'];
            $ins->bind_param(
                'issiiss',
                $fascicoloId,
                $r['titolo'],
                $autoriVal,
                $r['pagina_inizio'],
                $r['pagina_fine'],
                $r['tipo'],
                $kwVal
            );
            if (!$ins->execute()) {
                $err = $ins->error;
                $ins->close();
                throw new \RuntimeException('insert failed: ' . $err);
            }
        }
        $ins->close();
    }

    /** @return array<int, string> mensola id => human-readable location */
    private function fetchCollocazioni(): array
    {
        if (!$this->tableExists('mensole')) {
            return [];
        }
        $hasScaffali = $this->tableExists('scaffali');
        $select = $hasScaffali
            ? "SELECT m.id, m.numero_livello, m.descrizione, s.codice, s.nome
                 FROM mensole m LEFT JOIN scaffali s ON s.id = m.scaffale_id
                ORDER BY s.ordine, s.codice, m.ordine, m.numero_livello"
            : "SELECT m.id, m.numero_livello, m.descrizione, NULL AS codice, NULL AS nome
                 FROM mensole m ORDER BY m.ordine, m.numero_livello";
        $res = $this->db->query($select);
        if (!$res instanceof \mysqli_result) {
            SecureLogger::error('[Emeroteca] collocation list failed: ' . $this->db->error);
            return [];
        }
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $bookcase = trim((string) ($row['codice'] ?? ''));
            if ($bookcase === '') {
                $bookcase = trim((string) ($row['nome'] ?? ''));
            }
            $label = $bookcase !== '' ? $bookcase . '.' : '';
            $label .= (string) (int) $row['numero_livello'];
            if (!empty($row['descrizione'])) {
                $label .= ' · ' . trim((string) $row['descrizione']);
            }
            $out[(int) $row['id']] = $label;
        }
        $res->free();
        return $out;
    }

    private function collocazioneExists(int $id): bool
    {
        if (!$this->tableExists('mensole')) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM mensole WHERE id = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $res = $ok ? $stmt->get_result() : false;
        $exists = $res instanceof \mysqli_result && $res->fetch_row() !== null;
        $stmt->close();
        return $exists;
    }

    private function isValidDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function deleteUploadedCover(string $url): void
    {
        $this->deleteManagedImageIfUnreferenced($url);
    }

}
