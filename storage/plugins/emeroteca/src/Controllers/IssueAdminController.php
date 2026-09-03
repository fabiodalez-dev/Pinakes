<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Controllers;

require_once __DIR__ . '/AbstractAdminController.php';

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

    // ── Manage page ───────────────────────────────────────────────────

    /**
     * GET /admin/periodicals/{id}/issues — testata header + annate with
     * their fascicoli + quick forms (annata, fascicolo, bulk, kardex).
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

        if ($annate !== []) {
            $stmt = $this->db->prepare(
                'SELECT f.* FROM emeroteca_fascicoli f
                  JOIN emeroteca_annate a ON f.annata_id = a.id
                 WHERE a.testata_id = ?
                 ORDER BY CAST(f.numero AS UNSIGNED), f.numero'
            );
            if ($stmt !== false) {
                $stmt->bind_param('i', $testataId);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    if ($res instanceof \mysqli_result) {
                        while ($row = $res->fetch_assoc()) {
                            $aid = (int) $row['annata_id'];
                            if (isset($annate[$aid])) {
                                $annate[$aid]['fascicoli'][] = $row;
                            }
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
            'testata'     => $testata,
            'annate'      => array_values($annate),
            'consistenza' => \EmerotecaPlugin::consistenzaTestata($this->db, $testataId),
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
            case 'add_fascicolo':
                $this->addFascicolo($testataId, $body);
                break;
            case 'receive_issue':
                $this->receiveIssue($testataId, (int) ($body['fascicolo_id'] ?? 0));
                break;
            case 'mark_missing':
                $this->markMissing($testataId, (int) ($body['annata_id'] ?? 0));
                break;
            default:
                $this->flashError(__('Azione non riconosciuta.'));
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
        // Volume '' (not NULL) so the UNIQUE(testata_id, anno, volume)
        // actually bites for single-volume years (see DDL comment).
        $stmt = $this->db->prepare(
            'INSERT INTO emeroteca_annate (testata_id, anno, volume, rilegata) VALUES (?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] addAnnata prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante la creazione dell\'annata.'));
            return;
        }
        $stmt->bind_param('iisi', $testataId, $anno, $volume, $rilegata);
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
        if ($dataPub !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPub)) {
            $this->flashError(__('Data di pubblicazione non valida (formato AAAA-MM-GG).'));
            return;
        }
        $dataPubOrNull = $dataPub === '' ? null : $dataPub;
        $stato = trim((string) ($body['stato'] ?? 'posseduto'));
        if (!array_key_exists($stato, \EmerotecaPlugin::STATI_FASCICOLO)) {
            $stato = 'posseduto';
        }
        $stmt = $this->db->prepare(
            'INSERT INTO emeroteca_fascicoli (annata_id, numero, data_pubblicazione, stato)
             VALUES (?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] addFascicolo prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante la creazione del fascicolo.'));
            return;
        }
        $stmt->bind_param('isss', $annataId, $numero, $dataPubOrNull, $stato);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] addFascicolo insert failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError(__('Errore durante la creazione del fascicolo.'));
            return;
        }
        $stmt->close();
        $this->flashSuccess(sprintf(__('Fascicolo n. %s creato.'), $numero));
    }

    /** Mark one 'atteso' fascicolo of this testata as received. */
    private function receiveIssue(int $testataId, int $fascicoloId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE emeroteca_fascicoli f
              JOIN emeroteca_annate a ON f.annata_id = a.id
               SET f.stato = 'posseduto'
             WHERE f.id = ? AND a.testata_id = ? AND f.stato = 'atteso'"
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
            $this->flashSuccess(__('Fascicolo marcato come posseduto.'));
        } else {
            $this->flashError(__('Nessun fascicolo atteso da ricevere con questo id.'));
        }
    }

    /** End-of-year: every 'atteso' of an annata becomes 'mancante'. */
    private function markMissing(int $testataId, int $annataId): void
    {
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
        $stato = trim((string) ($body['stato'] ?? 'posseduto'));
        if (!array_key_exists($stato, \EmerotecaPlugin::STATI_FASCICOLO)) {
            $stato = 'posseduto';
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

        [$created, $skipped] = $this->insertNumberedIssues($annataId, $da, $a, $stato);
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

        $perYear = \EmerotecaPlugin::kardexIssuesPerYear();
        $periodicita = (string) ($testata['periodicita'] ?? '');
        if (!isset($perYear[$periodicita])) {
            $this->flashError(__('Il Kardex richiede una periodicità nota (non irregolare): impostala nella scheda della testata.'));
            return $this->redirect($response, $back);
        }

        $body = (array) $request->getParsedBody();
        $anno = (int) trim((string) ($body['anno'] ?? ''));
        if ($anno < self::ANNO_MIN || $anno > self::ANNO_MAX) {
            $this->flashError(sprintf(__('Anno non plausibile (atteso tra %d e %d).'), self::ANNO_MIN, self::ANNO_MAX));
            return $this->redirect($response, $back);
        }

        $annataId = $this->getOrCreateAnnata($testataId, $anno);
        if ($annataId === null) {
            $this->flashError(__('Errore durante la creazione dell\'annata.'));
            return $this->redirect($response, $back);
        }

        [$created, $skipped] = $this->insertNumberedIssues($annataId, 1, $perYear[$periodicita], 'atteso');
        $this->flashSuccess(sprintf(
            __('Kardex %d generato: %d fascicoli attesi creati, %d già presenti saltati.'),
            $anno,
            $created,
            $skipped
        ));
        return $this->redirect($response, $back);
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
        $smallint = static function (string $key) use ($body): ?int {
            $v = trim((string) ($body[$key] ?? ''));
            if ($v === '' || !preg_match('/^\d+$/', $v)) {
                return null;
            }
            $n = (int) $v;
            return ($n >= 0 && $n <= 32767) ? $n : null;
        };

        $numero = $str('numero', 50);
        if ($numero === null) {
            $this->flashError(__('Il numero del fascicolo è obbligatorio.'));
            return $this->redirect($response, $back);
        }
        $dataPub = trim((string) ($body['data_pubblicazione'] ?? ''));
        if ($dataPub !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPub)) {
            $this->flashError(__('Data di pubblicazione non valida (formato AAAA-MM-GG).'));
            return $this->redirect($response, $back);
        }
        $dataPubOrNull = $dataPub === '' ? null : $dataPub;
        $stato = trim((string) ($body['stato'] ?? 'posseduto'));
        if (!array_key_exists($stato, \EmerotecaPlugin::STATI_FASCICOLO)) {
            $stato = 'posseduto';
        }

        // Optional cover upload (classic file input; Uppy not wired
        // from plugins — kept intentionally simple).
        $copertinaUrl = (string) ($fascicolo['copertina_url'] ?? '');
        $files = $request->getUploadedFiles();
        if (isset($files['copertina'])
            && $files['copertina'] instanceof \Psr\Http\Message\UploadedFileInterface
            && $files['copertina']->getError() === UPLOAD_ERR_OK) {
            $result = $this->handleCoverUpload($files['copertina']);
            if (!$result['success']) {
                $this->flashError((string) $result['message']);
                return $this->redirect($response, $back);
            }
            $copertinaUrl = (string) $result['path'];
        }
        $copertinaOrNull = $copertinaUrl === '' ? null : $copertinaUrl;

        $numeroProgressivo = $str('numero_progressivo', 50);
        $titoloFascicolo   = $str('titolo_fascicolo', 255);
        $dataCopertina     = $str('data_copertina', 100);
        $pagine            = $smallint('pagine');
        $numeroInventario  = $str('numero_inventario', 100);
        $supplementi       = $str('supplementi', 500);
        $note              = $str('note', 65535);

        $stmt = $this->db->prepare(
            'UPDATE emeroteca_fascicoli SET
                numero = ?, numero_progressivo = ?, titolo_fascicolo = ?,
                data_copertina = ?, data_pubblicazione = ?, pagine = ?,
                copertina_url = ?, numero_inventario = ?, stato = ?,
                supplementi = ?, note = ?
             WHERE id = ?'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] issue update prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante il salvataggio del fascicolo.'));
            return $this->redirect($response, $back);
        }
        $stmt->bind_param(
            'sssssisssssi',
            $numero,
            $numeroProgressivo,
            $titoloFascicolo,
            $dataCopertina,
            $dataPubOrNull,
            $pagine,
            $copertinaOrNull,
            $numeroInventario,
            $stato,
            $supplementi,
            $note,
            $id
        );
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] issue update failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError(__('Errore durante il salvataggio del fascicolo.'));
            return $this->redirect($response, $back);
        }
        $stmt->close();

        if (!$this->saveArticles($id, $body)) {
            $this->flashError(__('Fascicolo salvato ma spoglio non aggiornato: errore nel salvataggio degli articoli.'));
            return $this->redirect($response, $back);
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
        // CSRF validated by CsrfMiddleware
        $id = (int) ($args['id'] ?? 0);
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

        $this->flashSuccess(__('Fascicolo eliminato.'));
        return $this->redirect($response, '/admin/periodicals/' . $testataId . '/issues');
    }

    // ── Internals ─────────────────────────────────────────────────────

    /**
     * Fascicolo + its annata/testata context (annata anno, testata id
     * and titolo), or null when unknown.
     *
     * @return array<string, mixed>|null
     */
    private function fetchFascicolo(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT f.*, a.anno, a.volume, a.testata_id, t.titolo AS testata_titolo
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
        if (is_array($row)) {
            return (int) $row['id'];
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
            SecureLogger::error('[Emeroteca] getOrCreateAnnata insert failed: ' . $ins->error);
            $ins->close();
            return null;
        }
        $newId = (int) $this->db->insert_id;
        $ins->close();
        return $newId;
    }

    /**
     * Insert issues numbered $from..$to into an annata with the given
     * stato, skipping numbers that already exist there.
     *
     * @return array{0:int, 1:int} [created, skipped]
     */
    private function insertNumberedIssues(int $annataId, int $from, int $to, string $stato): array
    {
        /** @var list<string> $existing */
        $existing = [];
        $stmt = $this->db->prepare('SELECT numero FROM emeroteca_fascicoli WHERE annata_id = ?');
        if ($stmt !== false) {
            $stmt->bind_param('i', $annataId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($row = $res->fetch_assoc()) {
                        $existing[] = (string) $row['numero'];
                    }
                }
            }
            $stmt->close();
        }

        $created = 0;
        $skipped = 0;
        $ins = $this->db->prepare(
            'INSERT INTO emeroteca_fascicoli (annata_id, numero, stato) VALUES (?, ?, ?)'
        );
        if ($ins === false) {
            SecureLogger::error('[Emeroteca] insertNumberedIssues prepare failed: ' . $this->db->error);
            return [0, 0];
        }
        for ($n = $from; $n <= $to; $n++) {
            $numero = (string) $n;
            if (in_array($numero, $existing, true)) {
                $skipped++;
                continue;
            }
            $ins->bind_param('iss', $annataId, $numero, $stato);
            if ($ins->execute()) {
                $created++;
            } else {
                SecureLogger::error('[Emeroteca] issue insert failed for n. ' . $numero . ': ' . $ins->error);
            }
        }
        $ins->close();
        return [$created, $skipped];
    }

    /**
     * Replace the spoglio of a fascicolo with the posted rows (parallel
     * arrays art_titolo[], art_autori[], art_pag_da[], art_pag_a[],
     * art_tipo[], art_keywords[] — one entry per row, aligned by index).
     * Delete + reinsert in a transaction: simple and idempotent; row
     * ids churn but nothing references emeroteca_articoli.id.
     *
     * @param array<string, mixed> $body
     */
    private function saveArticles(int $fascicoloId, array $body): bool
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

        $this->db->begin_transaction();
        try {
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

            if ($rows !== []) {
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
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            SecureLogger::error('[Emeroteca] saveArticles: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cover upload for a fascicolo — same hardening as the core
     * EventsController::handleImageUpload (extension + size + finfo
     * MIME + random name + path-traversal check), saved under
     * public/uploads/emeroteca/ (created on first use).
     *
     * @return array{success: bool, message?: string, path?: string}
     */
    private function handleCoverUpload(\Psr\Http\Message\UploadedFileInterface $uploadedFile): array
    {
        $filename = (string) $uploadedFile->getClientFilename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($extension, $allowedExtensions, true)) {
            return ['success' => false, 'message' => __('Formato immagine non supportato. Usa JPG, PNG o WebP.')];
        }

        if ((int) $uploadedFile->getSize() > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => __('L\'immagine è troppo grande. Max 5MB.')];
        }

        $tmpPath = $uploadedFile->getStream()->getMetadata('uri');
        if (!is_string($tmpPath) || !is_file($tmpPath)) {
            return ['success' => false, 'message' => __('Errore durante l\'upload.')];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mimeType, $allowedMimes, true)) {
            return ['success' => false, 'message' => __('Tipo di file non valido.')];
        }

        $baseDir = realpath(__DIR__ . '/../../../../../public/uploads');
        if ($baseDir === false) {
            SecureLogger::error('[Emeroteca] uploads base directory not found');
            return ['success' => false, 'message' => __('Errore di configurazione.')];
        }
        $targetDir = $baseDir . '/emeroteca';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        try {
            $randomSuffix = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] random_bytes() failed');
            return ['success' => false, 'message' => __('Errore di sistema.')];
        }

        $newFilename = 'fascicolo_' . date('Ymd_His') . '_' . $randomSuffix . '.' . $extension;
        $newFilename = str_replace("\0", '', $newFilename);
        $uploadPath = $targetDir . '/' . basename($newFilename);

        $realUploadPath = realpath(dirname($uploadPath));
        if ($realUploadPath === false || strpos($realUploadPath, $baseDir) !== 0) {
            SecureLogger::error('[Emeroteca] path traversal attempt detected in cover upload');
            return ['success' => false, 'message' => __('Percorso non valido.')];
        }

        try {
            $uploadedFile->moveTo($uploadPath);
            @chmod($uploadPath, 0644);
            return ['success' => true, 'path' => '/uploads/emeroteca/' . $newFilename];
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] cover upload error: ' . $e->getMessage());
            return ['success' => false, 'message' => __('Errore durante l\'upload.')];
        }
    }
}
