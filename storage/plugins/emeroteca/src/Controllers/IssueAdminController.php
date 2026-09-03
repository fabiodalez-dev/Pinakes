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
        if ($dataPub !== '' && !$this->isValidDate($dataPub)) {
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
            $duplicate = (int) $stmt->errno === 1062;
            SecureLogger::error('[Emeroteca] addFascicolo insert failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError($duplicate
                ? __('Un fascicolo con questo numero esiste già nell’annata.')
                : __('Errore durante la creazione del fascicolo.'));
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

        [$created, $skipped, $success] = $this->insertNumberedIssues($annataId, 1, $perYear[$periodicita], 'atteso');
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
        if ($dataPub !== '' && !$this->isValidDate($dataPub)) {
            $this->flashError(__('Data di pubblicazione non valida (formato AAAA-MM-GG).'));
            return $this->redirect($response, $back);
        }
        $dataPubOrNull = $dataPub === '' ? null : $dataPub;
        $stato = trim((string) ($body['stato'] ?? 'posseduto'));
        if (!array_key_exists($stato, \EmerotecaPlugin::STATI_FASCICOLO)) {
            $stato = 'posseduto';
        }

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
        $pagine            = $smallint('pagine');
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

        try {
            if (!$this->db->begin_transaction()) {
                throw new \RuntimeException('could not start issue-save transaction');
            }
            $stmt = $this->db->prepare(
                'UPDATE emeroteca_fascicoli SET
                    numero = ?, numero_progressivo = ?, titolo_fascicolo = ?,
                    data_copertina = ?, data_pubblicazione = ?, pagine = ?,
                    copertina_url = ?, numero_inventario = ?, collocazione_id = ?, stato = ?,
                    supplementi = ?, note = ?, pdf_path = ?, pdf_nome_originale = ?,
                    pdf_dimensione = ?, pdf_pubblico = ?
                 WHERE id = ?'
            );
            if ($stmt === false) {
                throw new \RuntimeException('issue update prepare failed: ' . $this->db->error);
            }
            $stmt->bind_param(
                'sssssississsssiii',
                $numero,
                $numeroProgressivo,
                $titoloFascicolo,
                $dataCopertina,
                $dataPubOrNull,
                $pagine,
                $copertinaOrNull,
                $numeroInventario,
                $collocazioneId,
                $stato,
                $supplementi,
                $note,
                $pdfPathOrNull,
                $pdfOriginalName,
                $pdfSize,
                $pdfPubblico,
                $id
            );
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new \RuntimeException('issue update failed: ' . $error);
            }
            $stmt->close();
            $this->replaceArticles($id, $body);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            SecureLogger::error('[Emeroteca] atomic issue save failed: ' . $e->getMessage());
            if ($newCoverUploaded) {
                $this->deleteUploadedCover($copertinaUrl);
            }
            if ($newPdfUploaded) {
                $this->deleteManagedPdfIfUnreferenced($pdfPath);
            }
            $this->flashError(__('Errore durante il salvataggio del fascicolo. Nessuna modifica è stata applicata.'));
            return $this->redirect($response, $back);
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

        $this->deleteManagedImageIfUnreferenced((string) ($fascicolo['copertina_url'] ?? ''));
        $this->deleteManagedPdfIfUnreferenced((string) ($fascicolo['pdf_path'] ?? ''));

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
        try {
            if (!$this->db->begin_transaction()) {
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
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
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
