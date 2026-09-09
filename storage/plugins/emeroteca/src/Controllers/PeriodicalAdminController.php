<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Controllers;

require_once __DIR__ . '/AbstractAdminController.php';
require_once __DIR__ . '/../Support/IssnHelper.php';

use App\Plugins\Emeroteca\Support\IssnHelper;
use App\Support\ActivityLog;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin CRUD for the emeroteca_testate table (periodical titles).
 *
 * Routes (registered by EmerotecaPlugin::registerRoutes):
 *   GET  /admin/periodicals              → index
 *   GET  /admin/periodicals/create      → createForm
 *   POST /admin/periodicals/create      → createSubmit
 *   GET  /admin/periodicals/edit/{id}   → editForm
 *   POST /admin/periodicals/edit/{id}   → editSubmit
 *   POST /admin/periodicals/delete/{id} → delete
 */
class PeriodicalAdminController extends AbstractAdminController
{
    /** Hard bounds for publication years (sanity, not history pedantry). */
    private const ANNO_MIN = 1400;
    private const ANNO_MAX = 2100;

    /** Testate per page in the admin list (review #140). */
    private const PER_PAGE = 50;

    /**
     * A subscription is flagged as "in scadenza" this many days before
     * data_scadenza (1.4.0). Two months is the usual renewal lead time for
     * serials, long enough to renegotiate with the supplier.
     */
    public const SCADENZA_GIORNI = 60;

    /** A barcode_base is an EAN-13: exactly 13 digits. */
    private const BARCODE_LEN = 13;

    /**
     * GET /admin/periodicals — filterable list of testate with holdings
     * counters and the shared consistenza string.
     *
     * @param array<string,string> $args
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $params = (array) $request->getQueryParams();
        $fTipo    = isset($params['tipo']) ? trim((string) $params['tipo']) : '';
        $fEditore = isset($params['editore']) ? (int) $params['editore'] : 0;
        $fStato   = isset($params['stato_raccolta']) ? trim((string) $params['stato_raccolta']) : '';
        if (!array_key_exists($fTipo, \EmerotecaPlugin::TIPI_TESTATA)) {
            $fTipo = '';
        }
        if (!array_key_exists($fStato, \EmerotecaPlugin::STATI_RACCOLTA)) {
            $fStato = '';
        }

        $hasEditori = $this->tableExists('editori');
        $select = $hasEditori
            ? 'SELECT t.*, e.nome AS editore_nome'
            : 'SELECT t.*, NULL AS editore_nome';
        $from = $hasEditori
            ? ' FROM emeroteca_testate t LEFT JOIN editori e ON t.editore_id = e.id'
            : ' FROM emeroteca_testate t';

        $where = [];
        $bindTypes = '';
        $bindVals = [];
        if ($fTipo !== '') {
            $where[] = 't.tipo = ?';
            $bindTypes .= 's';
            $bindVals[] = $fTipo;
        }
        if ($fEditore > 0) {
            $where[] = 't.editore_id = ?';
            $bindTypes .= 'i';
            $bindVals[] = $fEditore;
        }
        if ($fStato !== '') {
            $where[] = 't.stato_raccolta = ?';
            $bindTypes .= 's';
            $bindVals[] = $fStato;
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        // Pagination: total first (same filters), then a LIMIT/OFFSET page.
        $total = 0;
        $countStmt = $this->db->prepare('SELECT COUNT(*) AS c FROM emeroteca_testate t' . $whereSql);
        if ($countStmt === false) {
            SecureLogger::error('[Emeroteca] index count prepare failed: ' . $this->db->error);
        } else {
            if ($bindTypes !== '') {
                $countStmt->bind_param($bindTypes, ...$bindVals);
            }
            if ($countStmt->execute()) {
                $res = $countStmt->get_result();
                if ($res instanceof \mysqli_result) {
                    $total = (int) ($res->fetch_assoc()['c'] ?? 0);
                }
            } else {
                SecureLogger::error('[Emeroteca] index count failed: ' . $countStmt->error);
            }
            $countStmt->close();
        }
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, (int) ($params['page'] ?? 1));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;

        $sql = $select . $from . $whereSql . ' ORDER BY t.titolo LIMIT ? OFFSET ?';
        $pageBindTypes = $bindTypes . 'ii';
        $pageBindVals = $bindVals;
        $pageBindVals[] = self::PER_PAGE;
        $pageBindVals[] = $offset;

        $rows = [];
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] index prepare failed: ' . $this->db->error);
        } else {
            $stmt->bind_param($pageBindTypes, ...$pageBindVals);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($row = $res->fetch_assoc()) {
                        $rows[] = $row;
                    }
                }
            } else {
                SecureLogger::error('[Emeroteca] index query failed: ' . $stmt->error);
            }
            $stmt->close();
        }

        // One aggregated holdings query for the whole page instead of a
        // consistenzaTestata() + two correlated subqueries per row (#140).
        $pageIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $summaries = $this->holdingsSummary($pageIds);
        // Second aggregated query (still one per page, not per row): active
        // subscriptions expiring within SCADENZA_GIORNI days, so the list can
        // warn before a renewal window closes (1.4.0).
        $expiring = $this->expiringSubscriptions($pageIds);
        foreach ($rows as &$row) {
            $summary = $summaries[(int) $row['id']]
                ?? ['n_posseduti' => 0, 'n_mancanti' => 0, 'consistenza' => '—'];
            $row['n_posseduti'] = $summary['n_posseduti'];
            $row['n_mancanti']  = $summary['n_mancanti'];
            $row['consistenza'] = $summary['consistenza'];
            $row['n_abbonamenti_scadenza'] = $expiring[(int) $row['id']] ?? 0;
        }
        unset($row);

        return $this->renderView($response, 'index', [
            'rows'        => $rows,
            'editori'     => $this->fetchEditori(),
            'f_tipo'      => $fTipo,
            'f_editore'   => $fEditore,
            'f_stato'     => $fStato,
            'page'        => $page,
            'total_pages' => $totalPages,
            'total'       => $total,
        ]);
    }

    /**
     * Aggregated holdings for a set of testate in TWO queries (review #140):
     * counts of posseduti/mancanti plus the year range of owned issues, and
     * the declared consistenza of their annate. From those the same
     * consistenza string as EmerotecaPlugin::consistenzaTestata() is derived
     * — same canonical rule everywhere: the DECLARED consistenza is APPENDED
     * to the computed one (never replaces it) after a ' · ', it stands alone
     * when nothing is computed, and '—' is the sentinel for "nothing at all".
     * Every requested id gets an entry (zero counts and '—' when the testata
     * has no annate).
     *
     * @param array<int, int> $testataIds
     * @return array<int, array{n_posseduti:int, n_mancanti:int, consistenza:string}>
     */
    public function holdingsSummary(array $testataIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $testataIds),
            static fn(int $id): bool => $id > 0
        )));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['n_posseduti' => 0, 'n_mancanti' => 0, 'consistenza' => '—'];
        }
        if ($ids === []) {
            return $out;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT a.testata_id,
                    COALESCE(SUM(f.stato = 'posseduto'), 0) AS n_posseduti,
                    COALESCE(SUM(f.stato = 'mancante'), 0)  AS n_mancanti,
                    MIN(CASE WHEN f.stato = 'posseduto' THEN a.anno END) AS anno_min,
                    MAX(CASE WHEN f.stato = 'posseduto' THEN a.anno END) AS anno_max
               FROM emeroteca_annate a
               LEFT JOIN emeroteca_fascicoli f ON f.annata_id = a.id
              WHERE a.testata_id IN ({$placeholders})
              GROUP BY a.testata_id"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] holdings summary prepare failed: ' . $this->db->error);
            return $out;
        }
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] holdings summary failed: ' . $stmt->error);
            $stmt->close();
            return $out;
        }
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $min    = $row['anno_min'] !== null ? (int) $row['anno_min'] : null;
                $max    = $row['anno_max'] !== null ? (int) $row['anno_max'] : null;
                $lacune = (int) $row['n_mancanti'];
                // Same rendering as EmerotecaPlugin::consistenzaTestata().
                if ($min === null) {
                    $consistenza = '—';
                } elseif ($max === null || $max === $min) {
                    $consistenza = (string) $min;
                } else {
                    $consistenza = $min . '–' . $max;
                }
                if ($lacune > 0) {
                    $label = function_exists('__') ? __('lacune') : 'lacune';
                    $consistenza .= ' · ' . $label . ': ' . $lacune;
                }
                $out[(int) $row['testata_id']] = [
                    'n_posseduti' => (int) $row['n_posseduti'],
                    'n_mancanti'  => $lacune,
                    'consistenza' => $consistenza,
                ];
            }
        }
        $stmt->close();

        // The declared consistenza is APPENDED, exactly like
        // consistenzaTestata() does: it documents holdings the issue records
        // do not carry yet, so dropping the computed part would hide what IS
        // recorded (and the admin list would disagree with the issues page).
        foreach ($this->declaredHoldings($ids) as $id => $dichiarata) {
            if (!isset($out[$id]) || $dichiarata === '') {
                continue;
            }
            $out[$id]['consistenza'] = $out[$id]['consistenza'] === '—'
                ? $dichiarata
                : $out[$id]['consistenza'] . ' · ' . $dichiarata;
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function declaredHoldings(array $ids): array
    {
        return \EmerotecaPlugin::declaredHoldings($this->db, $ids);
    }

    /**
     * Active subscriptions expiring within SCADENZA_GIORNI days, counted per
     * testata in ONE query (1.4.0). Already-expired active subscriptions
     * count too: an overdue renewal is more urgent than an imminent one, and
     * hiding it would make the warning disappear exactly when it matters.
     *
     * The count uses the DATABASE's current date on purpose: it is compared
     * against a DATE column by the same engine that stores it, so no PHP/DB
     * timezone skew can shift the window by a day.
     *
     * @param array<int, int> $testataIds
     * @return array<int, int> testata id => number of expiring subscriptions
     */
    public function expiringSubscriptions(array $testataIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $testataIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT testata_id, COUNT(*) AS c
               FROM emeroteca_abbonamenti
              WHERE testata_id IN ({$placeholders})
                AND attivo = 1
                AND data_scadenza IS NOT NULL
                AND data_scadenza <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
              GROUP BY testata_id"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] expiring subscriptions prepare failed: ' . $this->db->error);
            return [];
        }
        $days = self::SCADENZA_GIORNI;
        $params = $ids;
        $params[] = $days;
        $stmt->bind_param(str_repeat('i', count($ids)) . 'i', ...$params);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] expiring subscriptions query failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $out = [];
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[(int) $row['testata_id']] = (int) $row['c'];
            }
        }
        $stmt->close();
        return $out;
    }

    /**
     * GET /admin/periodicals/create — blank form.
     *
     * @param array<string,string> $args
     */
    public function createForm(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return $this->renderView($response, 'form', [
            'mode'    => 'create',
            'id'      => null,
            'values'  => [],
            'errors'  => [],
            'editori' => $this->fetchEditori(),
            'generi'  => $this->fetchGeneriTopLevel(),
            'testate' => $this->fetchTestateExcept(null),
        ]);
    }

    /**
     * POST /admin/periodicals/create — validate + INSERT + redirect.
     *
     * @param array<string,string> $args
     */
    public function createSubmit(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware
        $body = (array) $request->getParsedBody();
        [$values, $errors] = $this->validate($body, null);

        $newLogo = '';
        if ($errors === []) {
            [$newLogo, $uploadError] = $this->receiveLogoUpload($request, $values);
            if ($uploadError !== null) {
                $errors['logo_url'] = $uploadError;
            }
        }

        if ($errors !== []) {
            return $this->renderView($response, 'form', [
                'mode'    => 'create',
                'id'      => null,
                'values'  => $values,
                'errors'  => $errors,
                'editori' => $this->fetchEditori(),
                'generi'  => $this->fetchGeneriTopLevel(),
                'testate' => $this->fetchTestateExcept(null),
            ]);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO emeroteca_testate
                (titolo, sottotitolo, issn, e_issn, issn_l, barcode_base, editore_id,
                 luogo_pubblicazione, direttore_responsabile, registrazione_tribunale,
                 prezzo_copertina, acquisizione_default, prestabile, lingua,
                 periodicita, tipo, anno_inizio, anno_fine, testata_precedente_id,
                 genere_id, logo_url, descrizione, note, stato_raccolta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] create prepare failed: ' . $this->db->error);
            if ($newLogo !== '') {
                $this->deleteManagedImageIfUnreferenced($newLogo);
            }
            $this->flashError(__('Errore durante il salvataggio della testata.'));
            return $this->redirect($response, '/admin/periodicals/create');
        }
        $stmt->bind_param(
            'ssssssisssdsssssiiiissss',
            $values['titolo'],
            $values['sottotitolo'],
            $values['issn'],
            $values['e_issn'],
            $values['issn_l'],
            $values['barcode_base'],
            $values['editore_id'],
            $values['luogo_pubblicazione'],
            $values['direttore_responsabile'],
            $values['registrazione_tribunale'],
            $values['prezzo_copertina'],
            $values['acquisizione_default'],
            $values['prestabile'],
            $values['lingua'],
            $values['periodicita'],
            $values['tipo'],
            $values['anno_inizio'],
            $values['anno_fine'],
            $values['testata_precedente_id'],
            $values['genere_id'],
            $values['logo_url'],
            $values['descrizione'],
            $values['note'],
            $values['stato_raccolta']
        );
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] create insert failed: ' . $stmt->error);
            $stmt->close();
            if ($newLogo !== '') {
                $this->deleteManagedImageIfUnreferenced($newLogo);
            }
            $this->flashError(__('Errore durante il salvataggio della testata.'));
            return $this->redirect($response, '/admin/periodicals/create');
        }
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        ActivityLog::recordEntityEvent(
            $this->db,
            'emeroteca_testate',
            $newId,
            'periodical.created',
            [],
            self::auditSnapshot($values),
            'inserimento',
            'admin'
        );

        $this->flashSuccess(__('Testata creata con successo.'));
        return $this->redirect($response, '/admin/periodicals/' . $newId . '/issues');
    }

    /**
     * GET /admin/periodicals/edit/{id} — pre-populated form.
     *
     * @param array<string,string> $args
     */
    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $testata = $this->fetchTestata($id);
        if ($testata === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, '/admin/periodicals');
        }
        return $this->renderView($response, 'form', [
            'mode'    => 'edit',
            'id'      => $id,
            'values'  => $testata,
            'errors'  => [],
            'editori' => $this->fetchEditori(),
            'generi'  => $this->fetchGeneriTopLevel(),
            'testate' => $this->fetchTestateExcept($id),
        ]);
    }

    /**
     * POST /admin/periodicals/edit/{id} — validate + UPDATE.
     *
     * @param array<string,string> $args
     */
    public function editSubmit(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->fetchTestata($id);
        if ($existing === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, '/admin/periodicals');
        }
        $body = (array) $request->getParsedBody();
        [$values, $errors] = $this->validate($body, $id);

        $newLogo = '';
        if ($errors === []) {
            [$newLogo, $uploadError] = $this->receiveLogoUpload($request, $values);
            if ($uploadError !== null) {
                $errors['logo_url'] = $uploadError;
            }
        }

        if ($errors !== []) {
            return $this->renderView($response, 'form', [
                'mode'    => 'edit',
                'id'      => $id,
                'values'  => $values,
                'errors'  => $errors,
                'editori' => $this->fetchEditori(),
                'generi'  => $this->fetchGeneriTopLevel(),
                'testate' => $this->fetchTestateExcept($id),
            ]);
        }

        $stmt = $this->db->prepare(
            'UPDATE emeroteca_testate SET
                titolo = ?, sottotitolo = ?, issn = ?, e_issn = ?, issn_l = ?,
                barcode_base = ?, editore_id = ?, luogo_pubblicazione = ?,
                direttore_responsabile = ?, registrazione_tribunale = ?,
                prezzo_copertina = ?, acquisizione_default = ?, prestabile = ?,
                lingua = ?, periodicita = ?, tipo = ?,
                anno_inizio = ?, anno_fine = ?, testata_precedente_id = ?,
                genere_id = ?, logo_url = ?, descrizione = ?, note = ?, stato_raccolta = ?
             WHERE id = ?'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] edit prepare failed: ' . $this->db->error);
            if ($newLogo !== '') {
                $this->deleteManagedImageIfUnreferenced($newLogo);
            }
            $this->flashError(__('Errore durante il salvataggio della testata.'));
            return $this->redirect($response, '/admin/periodicals/edit/' . $id);
        }
        $stmt->bind_param(
            'ssssssisssdsssssiiiissssi',
            $values['titolo'],
            $values['sottotitolo'],
            $values['issn'],
            $values['e_issn'],
            $values['issn_l'],
            $values['barcode_base'],
            $values['editore_id'],
            $values['luogo_pubblicazione'],
            $values['direttore_responsabile'],
            $values['registrazione_tribunale'],
            $values['prezzo_copertina'],
            $values['acquisizione_default'],
            $values['prestabile'],
            $values['lingua'],
            $values['periodicita'],
            $values['tipo'],
            $values['anno_inizio'],
            $values['anno_fine'],
            $values['testata_precedente_id'],
            $values['genere_id'],
            $values['logo_url'],
            $values['descrizione'],
            $values['note'],
            $values['stato_raccolta'],
            $id
        );
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] edit update failed: ' . $stmt->error);
            $stmt->close();
            if ($newLogo !== '') {
                $this->deleteManagedImageIfUnreferenced($newLogo);
            }
            $this->flashError(__('Errore durante il salvataggio della testata.'));
            return $this->redirect($response, '/admin/periodicals/edit/' . $id);
        }
        $stmt->close();

        ActivityLog::recordEntityEvent(
            $this->db,
            'emeroteca_testate',
            $id,
            'periodical.updated',
            self::auditSnapshot($existing),
            self::auditSnapshot($values),
            'aggiornamento',
            'admin'
        );

        $previousLogo = (string) ($existing['logo_url'] ?? '');
        $currentLogo = (string) ($values['logo_url'] ?? '');
        if ($previousLogo !== '' && $previousLogo !== $currentLogo) {
            $this->deleteManagedImageIfUnreferenced($previousLogo);
        }

        $this->flashSuccess(__('Testata aggiornata con successo.'));
        return $this->redirect($response, '/admin/periodicals');
    }

    /**
     * POST /admin/periodicals/delete/{id} — deletes the testata; annate,
     * fascicoli and articoli follow via ON DELETE CASCADE.
     *
     * @param array<string,string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware.
        // Destructive action: AdminAuthMiddleware also admits staff, so
        // re-check the role inline (internal security scan 2026-07-25).
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            $this->flashError(__('Operazione riservata agli amministratori.'));
            return $this->redirect($response, '/admin/periodicals');
        }
        $id = (int) ($args['id'] ?? 0);
        // Audit snapshot taken BEFORE the cascading DELETE: afterwards the row
        // (and everything hanging off it) is gone and unreconstructable.
        $existing = $this->fetchTestata($id);

        // Capture locally managed images before the cascading DELETE removes
        // the rows that point to them. Cleanup happens only after DB success.
        $managedImages = [];
        $images = $this->db->prepare(
            'SELECT logo_url AS image_url FROM emeroteca_testate WHERE id = ?
             UNION SELECT a.copertina_url FROM emeroteca_annate a WHERE a.testata_id = ?
             UNION SELECT f.copertina_url FROM emeroteca_fascicoli f
                    JOIN emeroteca_annate a ON a.id = f.annata_id WHERE a.testata_id = ?'
        );
        if ($images !== false) {
            $images->bind_param('iii', $id, $id, $id);
            if ($images->execute()) {
                $res = $images->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($row = $res->fetch_assoc()) {
                        $path = trim((string) ($row['image_url'] ?? ''));
                        if ($path !== '') {
                            $managedImages[$path] = true;
                        }
                    }
                }
            }
            $images->close();
        }

        $managedPdfs = [];
        $pdfs = $this->db->prepare(
            'SELECT f.pdf_path FROM emeroteca_fascicoli f
             JOIN emeroteca_annate a ON a.id = f.annata_id
             WHERE a.testata_id = ? AND f.pdf_path IS NOT NULL'
        );
        if ($pdfs !== false) {
            $pdfs->bind_param('i', $id);
            if ($pdfs->execute()) {
                $res = $pdfs->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($row = $res->fetch_assoc()) {
                        $path = trim((string) ($row['pdf_path'] ?? ''));
                        if ($path !== '') {
                            $managedPdfs[$path] = true;
                        }
                    }
                }
            }
            $pdfs->close();
        }

        $stmt = $this->db->prepare('DELETE FROM emeroteca_testate WHERE id = ?');
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] delete prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante l\'eliminazione della testata.'));
            return $this->redirect($response, '/admin/periodicals');
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] delete failed: ' . $stmt->error);
            $stmt->close();
            $this->flashError(__('Errore durante l\'eliminazione della testata.'));
            return $this->redirect($response, '/admin/periodicals');
        }
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();

        if ($deleted) {
            ActivityLog::recordEntityEvent(
                $this->db,
                'emeroteca_testate',
                $id,
                'periodical.deleted',
                self::auditSnapshot($existing ?? []),
                [],
                'cancellazione',
                'admin'
            );
            foreach (array_keys($managedImages) as $imageUrl) {
                $this->deleteManagedImageIfUnreferenced($imageUrl);
            }
            foreach (array_keys($managedPdfs) as $pdfPath) {
                $this->deleteManagedPdfIfUnreferenced($pdfPath);
            }
            $this->flashSuccess(__('Testata eliminata (con annate, fascicoli e spoglio).'));
        } else {
            $this->flashError(__('Testata non trovata.'));
        }
        return $this->redirect($response, '/admin/periodicals');
    }

    // ── Merge of duplicate testate ────────────────────────────────────
    //
    // Union-catalogue imports and years of manual cataloguing leave the same
    // periodical registered twice. Merging follows the UX the core uses for
    // authors and publishers (App\Support\MergeHelper): tick the duplicates
    // in the list, review a preview, confirm — with one difference that the
    // core does not have to face. A testata owns a tree (annate → fascicoli
    // → articoli) protected by two unique keys,
    //   UNIQUE(testata_id, anno, volume) on emeroteca_annate and
    //   UNIQUE(annata_id, numero)        on emeroteca_fascicoli,
    // so moving the source's holdings can collide at both levels.
    //
    // COLLISION RULE (deterministic, and the only one applied):
    //
    //  • Same (anno, volume) on both sides → the two annate are FUSED: the
    //    destination annata survives and the source's issues move into it.
    //    The emptied source annata is deleted only after it is verified
    //    empty. Empty destination metadata inherits the source values;
    //    incompatible descriptions retain a separately numbered volume.
    //  • Same `numero` inside the fused annata → the issue that is
    //    `posseduto` KEEPS the plain number: the destination first, the
    //    source when the destination is not owned, the destination again
    //    when neither is (stable tie-break).
    //  • The loser is NEVER deleted and never silently dropped: it is
    //    renumbered to "<numero>-dup", "-dup2", … inside the same annata and
    //    listed by number in the on-screen summary, so a curator can decide
    //    afterwards which physical copy to discard.
    //
    // The whole merge runs in ONE transaction with the rows locked FOR
    // UPDATE, and asserts an invariant before committing: the number of
    // issues under the destination afterwards must equal the number under
    // both titles before. A merge that would lose an issue rolls back.

    /**
     * GET /admin/periodicals/merge?ids[]=A&ids[]=B — preview of a merge
     * between exactly two testate, with the holdings each side brings and
     * the choice of which one survives.
     *
     * @param array<string,string> $args
     */
    public function mergeForm(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $params = (array) $request->getQueryParams();
        $ids = $this->mergeIds($params['ids'] ?? []);
        if (count($ids) !== 2) {
            $this->flashError(__('Seleziona esattamente due testate da unire.'));
            return $this->redirect($response, '/admin/periodicals');
        }

        $testate = [];
        foreach ($ids as $id) {
            $row = $this->fetchTestata($id);
            if ($row === null) {
                $this->flashError(__('Testata non trovata.'));
                return $this->redirect($response, '/admin/periodicals');
            }
            $testate[] = $row;
        }

        $stats = $this->mergeStats($ids);
        // Suggested survivor: the richer holdings, ties broken by the lower
        // id so the same selection always proposes the same destination.
        $suggested = $ids[0];
        $bestA = $stats[$ids[0]]['n_fascicoli'] ?? 0;
        $bestB = $stats[$ids[1]]['n_fascicoli'] ?? 0;
        if ($bestB > $bestA) {
            $suggested = $ids[1];
        }

        return $this->renderView($response, 'merge', [
            'mode'             => 'confirm',
            'testate'          => $testate,
            'stats'            => $stats,
            'suggested_target' => $suggested,
            'summary'          => null,
        ]);
    }

    /**
     * POST /admin/periodicals/merge — perform the merge and render the
     * summary of everything that moved.
     *
     * @param array<string,string> $args
     */
    public function mergeSubmit(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware.
        // Destructive action (it fuses holdings and deletes a title):
        // AdminAuthMiddleware also admits staff, so re-check the role inline
        // (internal security scan 2026-07-25).
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            $this->flashError(__('Operazione riservata agli amministratori.'));
            return $this->redirect($response, '/admin/periodicals');
        }

        // The pair travels as ids[] and the survivor as the target_id radio;
        // the source is DERIVED, so the two fields can never disagree.
        $body = (array) $request->getParsedBody();
        $ids = $this->mergeIds($body['ids'] ?? []);
        $targetId = (int) ($body['target_id'] ?? 0);
        if (count($ids) !== 2 || !in_array($targetId, $ids, true)) {
            $this->flashError(__('Seleziona esattamente due testate da unire.'));
            return $this->redirect($response, '/admin/periodicals');
        }
        $sourceId = $ids[0] === $targetId ? $ids[1] : $ids[0];

        $target = $this->fetchTestata($targetId);
        $source = $this->fetchTestata($sourceId);
        if ($target === null || $source === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, '/admin/periodicals');
        }

        try {
            $summary = $this->performMerge($sourceId, $targetId);
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] merge failed: ' . $e->getMessage());
            $this->flashError(__('Errore durante l\'unione delle testate: nessuna modifica è stata applicata.'));
            return $this->redirect($response, '/admin/periodicals');
        }

        // Audit AFTER the commit, so the log never records a rolled-back
        // merge. Two events: the source disappears, the destination changed.
        ActivityLog::recordEntityEvent(
            $this->db,
            'emeroteca_testate',
            $sourceId,
            'periodical.deleted',
            self::auditSnapshot($source),
            [],
            'cancellazione',
            'admin'
        );
        ActivityLog::recordEntityEvent(
            $this->db,
            'emeroteca_testate',
            $targetId,
            'periodical.merged',
            self::auditSnapshot($target),
            [
                'merged_from_id'     => $sourceId,
                'merged_from_titolo' => (string) ($source['titolo'] ?? ''),
                'annate_spostate'    => $summary['annate_spostate'],
                'annate_fuse'        => $summary['annate_fuse'],
                'fascicoli_spostati' => $summary['fascicoli_spostati'],
                'fascicoli_rinumerati' => count($summary['rinumerati']),
                'abbonamenti_spostati' => $summary['abbonamenti_spostati'],
                'annate_conservate' => $summary['annate_conservate'],
                'link_azzerati'        => array_column($summary['link_azzerati'], 'id'),
                'precedente_ereditato' => $summary['precedente_ereditato']['id'] ?? null,
                'precedente_scartato'  => $summary['precedente_scartato']['id'] ?? null,
            ],
            'aggiornamento',
            'admin'
        );

        // The source logo is only removable once its row is gone and nothing
        // else points at it.
        $sourceLogo = (string) ($source['logo_url'] ?? '');
        if ($sourceLogo !== '') {
            $this->deleteManagedImageIfUnreferenced($sourceLogo);
        }

        // The title-history repairs belong on the summary SCREEN, not only in
        // the audit log: a link the merge had to drop is a relation the
        // operator has to re-enter by hand, so it is named here. The flash is
        // rendered (and consumed) by the very response built below.
        $notes = [];
        foreach ($summary['annate_conservate'] as $annata) {
            $notes[] = sprintf(
                __('Annata %1$d conservata separatamente come volume «%2$s» per mantenere i dati descrittivi.'),
                $annata['anno'], $annata['volume']
            );
        }
        if ($summary['link_azzerati'] !== []) {
            $notes[] = sprintf(
                __('Collegamento alla testata precedente azzerato per evitare un ciclo: %s'),
                implode(', ', array_map(
                    static fn(array $t): string => (string) $t['titolo'],
                    $summary['link_azzerati']
                ))
            );
        }
        if ($summary['precedente_ereditato'] !== null) {
            $notes[] = sprintf(
                __('Testata precedente ereditata dalla testata di origine: «%s».'),
                (string) $summary['precedente_ereditato']['titolo']
            );
        }
        if ($summary['precedente_scartato'] !== null) {
            $notes[] = sprintf(
                __('Relazione non conservata: la testata di origine era preceduta da «%s»; la testata di destinazione mantiene la propria.'),
                (string) $summary['precedente_scartato']['titolo']
            );
        }
        $this->flashSuccess(trim(__('Testate unite con successo.') . ' ' . implode(' ', $notes)));
        return $this->renderView($response, 'merge', [
            'mode'             => 'done',
            'testate'          => [$target],
            'stats'            => [],
            'suggested_target' => $targetId,
            'summary'          => $summary + [
                'target_id'     => $targetId,
                'target_titolo' => (string) ($target['titolo'] ?? ''),
                'source_titolo' => (string) ($source['titolo'] ?? ''),
            ],
        ]);
    }

    /**
     * The merge itself: one transaction, rows locked, invariant checked.
     *
     * @return array{annate_spostate:int, annate_fuse:int, fascicoli_spostati:int,
     *               abbonamenti_spostati:int, testate_ricollegate:int,
     *               annate_conservate:list<array{anno:int, volume:string}>,
     *               rinumerati:list<array{anno:int, numero:string, nuovo:string, lato:string}>,
     *               link_azzerati:list<array{id:int, titolo:string}>,
     *               precedente_ereditato:array{id:int, titolo:string}|null,
     *               precedente_scartato:array{id:int, titolo:string}|null}
     * @throws \RuntimeException on any inconsistency — the caller rolls back.
     */
    private function performMerge(int $sourceId, int $targetId): array
    {
        $summary = [
            'annate_spostate'      => 0,
            'annate_fuse'          => 0,
            'annate_conservate'    => [],
            'fascicoli_spostati'   => 0,
            'abbonamenti_spostati' => 0,
            'testate_ricollegate'  => 0,
            'rinumerati'           => [],
            'link_azzerati'        => [],
            'precedente_ereditato' => null,
            'precedente_scartato'  => null,
        ];

        $ownsTx = !$this->hasActiveTransaction();
        if ($ownsTx && !$this->db->begin_transaction()) {
            // Never assume the transaction opened: under MYSQLI_REPORT_OFF a
            // failure here is silent, every statement below would run in
            // autocommit and the rollback that protects the issue-count
            // invariant would be a no-op — with the source already deleted.
            throw new \RuntimeException('merge: begin_transaction failed: ' . $this->db->error);
        }
        try {
            // Lock both titles for the whole operation, lowest id first so two
            // concurrent merges of the same pair cannot deadlock each other.
            $locked = $this->lockTestate($sourceId, $targetId);
            if (count($locked) !== 2) {
                throw new \RuntimeException('merge: one of the titles disappeared before the lock');
            }

            $issuesBefore = $this->countIssues($sourceId) + $this->countIssues($targetId);

            foreach ($this->lockedAnnate($sourceId) as $annata) {
                $srcAnnataId = (int) $annata['id'];
                $anno = (int) $annata['anno'];
                $volume = (string) $annata['volume'];

                $destAnnataId = $this->lockedAnnataId($targetId, $anno, $volume);
                if ($destAnnataId === null) {
                    // No collision: the whole annata (and everything hanging
                    // off it) changes owner with a single UPDATE.
                    $this->exec(
                        'UPDATE emeroteca_annate SET testata_id = ? WHERE id = ?',
                        'ii',
                        [$targetId, $srcAnnataId]
                    );
                    $summary['annate_spostate']++;
                    $summary['fascicoli_spostati'] += $this->countIssuesInAnnata($srcAnnataId);
                    continue;
                }

                // A year can represent uncatalogued holdings solely through its
                // metadata. Never discard those fields when deleting the source.
                if (!$this->mergeAnnataMetadata($srcAnnataId, $destAnnataId)) {
                    $suffix = '-dup-' . $srcAnnataId;
                    $newVolume = mb_substr($volume, 0, 50 - strlen($suffix)) . $suffix;
                    for ($attempt = 2; $this->lockedAnnataId($targetId, $anno, $newVolume) !== null; $attempt++) {
                        if ($attempt > 1000) {
                            throw new \RuntimeException('merge: no free volume designation');
                        }
                        $suffix = '-dup-' . $srcAnnataId . '-' . $attempt;
                        $newVolume = mb_substr($volume, 0, 50 - strlen($suffix)) . $suffix;
                    }
                    $this->exec(
                        'UPDATE emeroteca_annate SET testata_id = ?, volume = ? WHERE id = ?',
                        'isi', [$targetId, $newVolume, $srcAnnataId]
                    );
                    $summary['annate_spostate']++;
                    $summary['fascicoli_spostati'] += $this->countIssuesInAnnata($srcAnnataId);
                    $summary['annate_conservate'][] = ['anno' => $anno, 'volume' => $newVolume];
                    continue;
                }

                // Collision on (anno, volume). First the cheap half in ONE
                // statement: every source issue whose number is still free in
                // the destination changes owner at once. The unique key
                // (annata_id, numero) guarantees at most one candidate per
                // number on either side, so no duplicate can be created and
                // the two row sets are disjoint (different annata_id).
                $summary['fascicoli_spostati'] += $this->exec(
                    'UPDATE emeroteca_fascicoli src
                       LEFT JOIN emeroteca_fascicoli dst
                              ON dst.annata_id = ? AND dst.numero = src.numero
                        SET src.annata_id = ?
                      WHERE src.annata_id = ? AND dst.id IS NULL',
                    'iii',
                    [$destAnnataId, $destAnnataId, $srcAnnataId]
                );

                // What is left genuinely collides: fuse those one by one.
                foreach ($this->lockedIssues($srcAnnataId) as $issue) {
                    $srcIssueId = (int) $issue['id'];
                    $numero = (string) $issue['numero'];
                    $destIssue = $this->lockedIssueByNumber($destAnnataId, $numero);

                    if ($destIssue === null) {
                        $this->exec(
                            'UPDATE emeroteca_fascicoli SET annata_id = ? WHERE id = ?',
                            'ii',
                            [$destAnnataId, $srcIssueId]
                        );
                        $summary['fascicoli_spostati']++;
                        continue;
                    }

                    $destOwned = (string) $destIssue['stato'] === 'posseduto';
                    $srcOwned = (string) $issue['stato'] === 'posseduto';
                    if ($destOwned || !$srcOwned) {
                        // Destination keeps the number; the incoming copy is
                        // renumbered rather than dropped.
                        $renamed = $this->freeIssueNumber($destAnnataId, $numero);
                        $this->exec(
                            'UPDATE emeroteca_fascicoli SET annata_id = ?, numero = ? WHERE id = ?',
                            'isi',
                            [$destAnnataId, $renamed, $srcIssueId]
                        );
                        $summary['rinumerati'][] = [
                            'anno'   => $anno,
                            'numero' => $numero,
                            'nuovo'  => $renamed,
                            'lato'   => 'sorgente',
                        ];
                    } else {
                        // Only the source copy is owned: it takes the number,
                        // the destination's non-owned record is renumbered.
                        $renamed = $this->freeIssueNumber($destAnnataId, $numero);
                        $this->exec(
                            'UPDATE emeroteca_fascicoli SET numero = ? WHERE id = ?',
                            'si',
                            [$renamed, (int) $destIssue['id']]
                        );
                        $this->exec(
                            'UPDATE emeroteca_fascicoli SET annata_id = ? WHERE id = ?',
                            'ii',
                            [$destAnnataId, $srcIssueId]
                        );
                        $summary['rinumerati'][] = [
                            'anno'   => $anno,
                            'numero' => $numero,
                            'nuovo'  => $renamed,
                            'lato'   => 'destinazione',
                        ];
                    }
                    $summary['fascicoli_spostati']++;
                }

                // The source annata must be empty now; deleting a non-empty
                // one would CASCADE its issues away.
                if ($this->countIssuesInAnnata($srcAnnataId) !== 0) {
                    throw new \RuntimeException('merge: source annata still holds issues after the fusion');
                }
                $this->exec('DELETE FROM emeroteca_annate WHERE id = ?', 'i', [$srcAnnataId]);
                $summary['annate_fuse']++;
            }

            $summary['abbonamenti_spostati'] = $this->exec(
                'UPDATE emeroteca_abbonamenti SET testata_id = ? WHERE testata_id = ?',
                'ii',
                [$targetId, $sourceId]
            );

            // Title history, in this order:
            //   1. the survivor cannot precede itself, so a link from the
            //      survivor to the source goes first — and going first also
            //      keeps the cycle walks below off the doomed row;
            //   2. the survivor INHERITS the source's own predecessor when it
            //      has none, otherwise that relation is reported as lost;
            //   3. the titles that continued FROM the source are repointed at
            //      the survivor, one by one, each checked against the whole
            //      predecessor chain — a link that would close a cycle is
            //      dropped instead, never committed.
            $this->exec(
                'UPDATE emeroteca_testate SET testata_precedente_id = NULL
                  WHERE id = ? AND testata_precedente_id = ?',
                'ii',
                [$targetId, $sourceId]
            );

            $inherited = $this->inheritPredecessor($sourceId, $targetId);
            $summary['precedente_ereditato'] = $inherited['ereditato'];
            $summary['precedente_scartato']  = $inherited['scartato'];

            $relinked = $this->relinkFollowers($sourceId, $targetId);
            $summary['testate_ricollegate'] = $relinked['ricollegate'];
            $summary['link_azzerati']       = $relinked['azzerati'];

            $leftover = $this->countAnnate($sourceId);
            if ($leftover !== 0) {
                throw new \RuntimeException('merge: source still owns ' . $leftover . ' annate');
            }
            $this->exec('DELETE FROM emeroteca_testate WHERE id = ?', 'i', [$sourceId]);

            $issuesAfter = $this->countIssues($targetId);
            if ($issuesAfter !== $issuesBefore) {
                throw new \RuntimeException(
                    'merge: issue count changed (' . $issuesBefore . ' → ' . $issuesAfter . ')'
                );
            }

            if ($ownsTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTx) {
                try {
                    $this->db->rollback();
                } catch (\Throwable $rollbackError) {
                    SecureLogger::error('[Emeroteca] merge rollback failed: ' . $rollbackError->getMessage());
                }
            }
            throw $e;
        }

        return $summary;
    }

    /**
     * Fill empty destination metadata; incompatible descriptions need two rows.
     * Both years are already locked by performMerge(). No data is truncated or
     * replaced merely because the year and volume designations happen to match.
     */
    private function mergeAnnataMetadata(int $sourceId, int $targetId): bool
    {
        // Descriptive fields only: a mismatch between two of these means the
        // years describe different things and must not be fused. `rilegata` is
        // deliberately NOT among them — it is a NOT NULL boolean where 0 is a
        // legitimate value ('not bound'), so comparing it would read 1 vs 0 as a
        // descriptive conflict and split an otherwise identical year into a
        // duplicate volume. It is merged below with a logical OR instead.
        $fields = ['serie', 'collocazione_id', 'consistenza_dichiarata', 'copertina_url', 'note'];
        $stmt = $this->db->prepare('SELECT * FROM emeroteca_annate WHERE id IN (?, ?) FOR UPDATE');
        if ($stmt === false) {
            throw new \RuntimeException('merge: year metadata prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $sourceId, $targetId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('merge: year metadata read failed: ' . $error);
        }
        $res = $stmt->get_result();
        $rows = [];
        while ($res instanceof \mysqli_result && ($row = $res->fetch_assoc())) {
            $rows[(int) $row['id']] = $row;
        }
        $stmt->close();
        if (!isset($rows[$sourceId], $rows[$targetId])) {
            throw new \RuntimeException('merge: year disappeared');
        }
        $values = [];
        foreach ($fields as $field) {
            $source = $rows[$sourceId][$field];
            $target = $rows[$targetId][$field];
            if ($source !== null && $source !== '' && $target !== null && $target !== ''
                && (string) $source !== (string) $target) {
                return false;
            }
            $values[] = $target === null || $target === '' ? $source : $target;
        }
        // Bound wins: if either year is bound the surviving one is bound, since
        // the physical volume exists once the issues are together.
        $values[] = ((int) $rows[$sourceId]['rilegata'] === 1 || (int) $rows[$targetId]['rilegata'] === 1) ? 1 : 0;
        $values[] = $targetId;
        $this->exec(
            'UPDATE emeroteca_annate SET serie = ?, collocazione_id = ?, consistenza_dichiarata = ?,
             copertina_url = ?, note = ?, rilegata = ? WHERE id = ?',
            'sisssii', $values
        );
        return true;
    }

    /**
     * The survivor inherits the source's predecessor when it has none of its
     * own: the source row is about to disappear and with it the only record
     * of what THAT title continued from.
     *
     * Refused (and reported as a lost relation) when the survivor already
     * declares a different predecessor — the operator chose that title as the
     * survivor, so its own history wins — or when inheriting would close a
     * predecessor cycle.
     *
     * @return array{ereditato:array{id:int, titolo:string}|null,
     *               scartato:array{id:int, titolo:string}|null}
     */
    private function inheritPredecessor(int $sourceId, int $targetId): array
    {
        $out = ['ereditato' => null, 'scartato' => null];

        $source = $this->titleRow($sourceId);
        $sourcePrev = $source['precedente'] ?? null;
        if ($sourcePrev === null || $sourcePrev === $targetId || $sourcePrev === $sourceId) {
            return $out;
        }
        $prev = $this->titleRow($sourcePrev);
        if ($prev === null) {
            return $out;
        }
        $label = ['id' => $prev['id'], 'titolo' => $prev['titolo']];

        $target = $this->titleRow($targetId);
        $targetPrev = $target['precedente'] ?? null;
        if ($targetPrev !== null) {
            // Same predecessor on both sides: nothing gained, nothing lost.
            if ($targetPrev !== $sourcePrev) {
                $out['scartato'] = $label;
            }
            return $out;
        }
        if ($this->wouldCreateTitleCycle($targetId, $sourcePrev)) {
            $out['scartato'] = $label;
            return $out;
        }
        $this->exec(
            'UPDATE emeroteca_testate SET testata_precedente_id = ? WHERE id = ?',
            'ii',
            [$sourcePrev, $targetId]
        );
        $out['ereditato'] = $label;
        return $out;
    }

    /**
     * Repoint at the survivor every OTHER title that continued from the
     * source (ON DELETE SET NULL would erase the link when the source row
     * goes), row by row instead of in one blind UPDATE.
     *
     * The blind version only ruled out the one-hop cycle "the survivor
     * precedes itself" and happily committed longer ones: with S → X → T
     * (X continues from the source, the survivor continues from X) it left
     * X ⇄ T, which no form can undo afterwards because validate() rejects
     * every save that touches either title. So each candidate is walked
     * through the same wouldCreateTitleCycle() the form uses, and a link that
     * would close a cycle is set to NULL and reported instead.
     *
     * @return array{ricollegate:int, azzerati:list<array{id:int, titolo:string}>}
     */
    private function relinkFollowers(int $sourceId, int $targetId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, titolo FROM emeroteca_testate
              WHERE testata_precedente_id = ? AND id <> ? ORDER BY id FOR UPDATE'
        );
        if ($stmt === false) {
            throw new \RuntimeException('merge: followers lock prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $sourceId, $targetId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('merge: followers lock failed: ' . $error);
        }
        $followers = [];
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $followers[] = $row;
            }
        }
        $stmt->close();

        $out = ['ricollegate' => 0, 'azzerati' => []];
        foreach ($followers as $follower) {
            $id = (int) $follower['id'];
            // Walks the survivor's predecessor chain: true when the follower
            // is already an ancestor of the survivor, i.e. exactly when the
            // new link would close the loop.
            if ($this->wouldCreateTitleCycle($id, $targetId)) {
                $this->exec(
                    'UPDATE emeroteca_testate SET testata_precedente_id = NULL WHERE id = ?',
                    'i',
                    [$id]
                );
                $out['azzerati'][] = ['id' => $id, 'titolo' => (string) $follower['titolo']];
                continue;
            }
            $out['ricollegate'] += $this->exec(
                'UPDATE emeroteca_testate SET testata_precedente_id = ? WHERE id = ?',
                'ii',
                [$targetId, $id]
            );
        }
        return $out;
    }

    /**
     * Identity + predecessor of one title, read inside the merge transaction.
     * No FOR UPDATE: the two merged rows are already locked by lockTestate()
     * and locking a third, arbitrary row (the predecessor) would only add a
     * deadlock surface for no gain.
     *
     * @return array{id:int, titolo:string, precedente:int|null}|null
     */
    private function titleRow(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, titolo, testata_precedente_id FROM emeroteca_testate WHERE id = ? LIMIT 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException('merge: title probe prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('merge: title probe failed: ' . $error);
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        return [
            'id'         => (int) $row['id'],
            'titolo'     => (string) $row['titolo'],
            'precedente' => $row['testata_precedente_id'] !== null
                ? (int) $row['testata_precedente_id']
                : null,
        ];
    }

    /**
     * A free issue number inside one annata, derived from the taken one:
     * "12" → "12-dup" → "12-dup2" → … The base is trimmed so the result
     * always fits emeroteca_fascicoli.numero (VARCHAR(50)).
     */
    private function freeIssueNumber(int $annataId, string $numero): string
    {
        for ($k = 1; $k <= 99; $k++) {
            $suffix = '-dup' . ($k > 1 ? (string) $k : '');
            $candidate = mb_substr($numero, 0, 50 - mb_strlen($suffix)) . $suffix;
            if (!$this->issueNumberTaken($annataId, $candidate)) {
                return $candidate;
            }
        }
        // 99 duplicates of the same number is pathological; fall back to a
        // random suffix rather than giving up on the issue.
        $suffix = '-dup' . bin2hex(random_bytes(3));
        $candidate = mb_substr($numero, 0, 50 - mb_strlen($suffix)) . $suffix;
        if ($this->issueNumberTaken($annataId, $candidate)) {
            throw new \RuntimeException('merge: cannot find a free issue number for ' . $numero);
        }
        return $candidate;
    }

    private function issueNumberTaken(int $annataId, string $numero): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM emeroteca_fascicoli WHERE annata_id = ? AND numero = ? LIMIT 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException('merge: issue number probe prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('is', $annataId, $numero);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('merge: issue number probe failed: ' . $error);
        }
        $res = $stmt->get_result();
        $taken = $res instanceof \mysqli_result && $res->fetch_row() !== null;
        $stmt->close();
        return $taken;
    }

    /**
     * Run a write statement inside the merge transaction. Any failure is an
     * exception, never a logged-and-ignored error: half a merge is worse
     * than no merge.
     *
     * @param  list<int|string> $params
     * @return int affected rows
     */
    private function exec(string $sql, string $types, array $params): int
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('merge: prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('merge: statement failed: ' . $error);
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        return (int) $affected;
    }

    /**
     * Lock both title rows, lowest id first.
     *
     * @return list<int>
     */
    private function lockTestate(int $a, int $b): array
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM emeroteca_testate WHERE id IN (?, ?) ORDER BY id FOR UPDATE'
        );
        if ($stmt === false) {
            throw new \RuntimeException('merge: lock prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $a, $b);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('merge: lock failed: ' . $error);
        }
        $out = [];
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[] = (int) $row['id'];
            }
        }
        $stmt->close();
        return $out;
    }

    /**
     * Every annata of a title, locked for the merge.
     *
     * @return list<array<string, mixed>>
     */
    private function lockedAnnate(int $testataId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, anno, volume FROM emeroteca_annate
              WHERE testata_id = ? ORDER BY anno, volume FOR UPDATE'
        );
        if ($stmt === false) {
            throw new \RuntimeException('merge: annate lock prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $testataId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('merge: annate lock failed: ' . $error);
        }
        $out = [];
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
        }
        $stmt->close();
        return $out;
    }

    /** The destination annata with this (anno, volume), locked, or null. */
    private function lockedAnnataId(int $testataId, int $anno, string $volume): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM emeroteca_annate
              WHERE testata_id = ? AND anno = ? AND volume = ? LIMIT 1 FOR UPDATE'
        );
        if ($stmt === false) {
            throw new \RuntimeException('merge: annata probe prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('iis', $testataId, $anno, $volume);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('merge: annata probe failed: ' . $error);
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? (int) $row['id'] : null;
    }

    /**
     * Every issue of one annata, locked.
     *
     * @return list<array<string, mixed>>
     */
    private function lockedIssues(int $annataId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, numero, stato FROM emeroteca_fascicoli
              WHERE annata_id = ? ORDER BY id FOR UPDATE'
        );
        if ($stmt === false) {
            throw new \RuntimeException('merge: issues lock prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $annataId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('merge: issues lock failed: ' . $error);
        }
        $out = [];
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
        }
        $stmt->close();
        return $out;
    }

    /**
     * The destination issue carrying this number, locked, or null.
     *
     * @return array<string, mixed>|null
     */
    private function lockedIssueByNumber(int $annataId, string $numero): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, stato FROM emeroteca_fascicoli
              WHERE annata_id = ? AND numero = ? LIMIT 1 FOR UPDATE'
        );
        if ($stmt === false) {
            throw new \RuntimeException('merge: issue probe prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('is', $annataId, $numero);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('merge: issue probe failed: ' . $error);
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** Issues owned by a whole title. */
    private function countIssues(int $testataId): int
    {
        return $this->countScalar(
            'SELECT COUNT(*) AS c FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON a.id = f.annata_id
              WHERE a.testata_id = ?',
            $testataId
        );
    }

    /** Issues inside one annata. */
    private function countIssuesInAnnata(int $annataId): int
    {
        return $this->countScalar(
            'SELECT COUNT(*) AS c FROM emeroteca_fascicoli WHERE annata_id = ?',
            $annataId
        );
    }

    /** Annate of a title. */
    private function countAnnate(int $testataId): int
    {
        return $this->countScalar(
            'SELECT COUNT(*) AS c FROM emeroteca_annate WHERE testata_id = ?',
            $testataId
        );
    }

    /** Single-int-parameter COUNT(*) helper used by the merge invariants. */
    private function countScalar(string $sql, int $param): int
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('merge: count prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $param);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('merge: count failed: ' . $error);
        }
        $res = $stmt->get_result();
        $count = $res instanceof \mysqli_result ? (int) ($res->fetch_assoc()['c'] ?? 0) : 0;
        $stmt->close();
        return $count;
    }

    /**
     * Detect both autocommit(false) and an explicit begin_transaction() (the
     * latter leaves @@autocommit enabled), using the same disposable
     * savepoint probe as App\Models\GenereRepository. The merge must not
     * nest a transaction inside a caller's one: begin_transaction() would
     * implicitly commit it.
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
     * Normalize the ids[] selection of the merge form.
     *
     * @param  mixed $raw
     * @return list<int>
     */
    private function mergeIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            $raw = [$raw];
        }
        return array_values(array_unique(array_filter(
            array_map(static fn(mixed $v): int => is_scalar($v) ? (int) $v : 0, $raw),
            static fn(int $id): bool => $id > 0
        )));
    }

    /**
     * Holdings counters shown in the merge preview, in two aggregated
     * queries instead of one per title.
     *
     * @param  list<int> $ids
     * @return array<int, array{n_annate:int, n_fascicoli:int, n_abbonamenti:int}>
     */
    private function mergeStats(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['n_annate' => 0, 'n_fascicoli' => 0, 'n_abbonamenti' => 0];
        }
        if ($ids === []) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $stmt = $this->db->prepare(
            "SELECT a.testata_id,
                    COUNT(DISTINCT a.id) AS n_annate,
                    COUNT(f.id)          AS n_fascicoli
               FROM emeroteca_annate a
               LEFT JOIN emeroteca_fascicoli f ON f.annata_id = a.id
              WHERE a.testata_id IN ({$placeholders})
              GROUP BY a.testata_id"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] merge stats prepare failed: ' . $this->db->error);
            return $out;
        }
        $stmt->bind_param($types, ...$ids);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res instanceof \mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    $tid = (int) $row['testata_id'];
                    $out[$tid]['n_annate'] = (int) $row['n_annate'];
                    $out[$tid]['n_fascicoli'] = (int) $row['n_fascicoli'];
                }
            }
        } else {
            SecureLogger::error('[Emeroteca] merge stats failed: ' . $stmt->error);
        }
        $stmt->close();

        $stmt = $this->db->prepare(
            "SELECT testata_id, COUNT(*) AS c FROM emeroteca_abbonamenti
              WHERE testata_id IN ({$placeholders}) GROUP BY testata_id"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] merge subscription stats prepare failed: ' . $this->db->error);
            return $out;
        }
        $stmt->bind_param($types, ...$ids);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res instanceof \mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    $out[(int) $row['testata_id']]['n_abbonamenti'] = (int) $row['c'];
                }
            }
        } else {
            SecureLogger::error('[Emeroteca] merge subscription stats failed: ' . $stmt->error);
        }
        $stmt->close();

        return $out;
    }

    /**
     * Receive the hidden multipart input populated by the standard Uppy image
     * widget. A selected file takes precedence over a manually entered URL.
     *
     * @param array<string,mixed> $values
     * @return array{0:string,1:?string} new managed path, validation error
     */
    private function receiveLogoUpload(ServerRequestInterface $request, array &$values): array
    {
        $files = $request->getUploadedFiles();
        $file = $files['logo_file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface
            || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return ['', null];
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return ['', __('Errore durante l\'upload.')];
        }
        $result = $this->storeManagedImage($file, 'testata');
        if (!$result['success']) {
            return ['', (string) ($result['message'] ?? __('Errore durante l\'upload.'))];
        }
        $path = (string) $result['path'];
        $values['logo_url'] = $path;
        return [$path, null];
    }

    // ── Validation ────────────────────────────────────────────────────

    /**
     * Server-side validation + normalization of the testata form.
     * Returns [values, errors]: values are ready for bind_param (nulls
     * for empty optionals), errors is field => message.
     *
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validate(array $body, ?int $selfId): array
    {
        $errors = [];
        $str = static function (string $key, int $max) use ($body): ?string {
            $v = trim(strip_tags((string) ($body[$key] ?? '')));
            if ($v === '') {
                return null;
            }
            return mb_substr($v, 0, $max);
        };
        $intOrNull = static function (string $key) use ($body): ?int {
            $v = trim((string) ($body[$key] ?? ''));
            return ($v === '' || !preg_match('/^\d+$/', $v)) ? null : (int) $v;
        };

        $values = [
            'titolo'                  => $str('titolo', 255) ?? '',
            'sottotitolo'             => $str('sottotitolo', 255),
            'issn'                    => $str('issn', 9),
            'e_issn'                  => $str('e_issn', 9),
            'issn_l'                  => $str('issn_l', 9),
            'barcode_base'            => $str('barcode_base', 13),
            'direttore_responsabile'  => $str('direttore_responsabile', 255),
            'registrazione_tribunale' => $str('registrazione_tribunale', 255),
            'prezzo_copertina'        => null,
            'acquisizione_default'    => $str('acquisizione_default', 20),
            'prestabile'              => $str('prestabile', 20) ?? 'consultazione',
            'editore_id'            => $intOrNull('editore_id'),
            'luogo_pubblicazione'   => $str('luogo_pubblicazione', 255),
            'lingua'                => $str('lingua', 10),
            'periodicita'           => $str('periodicita', 20),
            'tipo'                  => $str('tipo', 20) ?? 'rivista',
            'anno_inizio'           => $intOrNull('anno_inizio'),
            'anno_fine'             => $intOrNull('anno_fine'),
            'testata_precedente_id' => $intOrNull('testata_precedente_id'),
            'genere_id'             => $intOrNull('genere_id'),
            'logo_url'              => $str('logo_url', 500),
            'descrizione'           => $str('descrizione', 65535),
            'note'                  => $str('note', 65535),
            'stato_raccolta'        => $str('stato_raccolta', 20) ?? 'attiva',
        ];

        if ($values['titolo'] === '') {
            $errors['titolo'] = __('Il titolo è obbligatorio.');
        }
        if ($values['issn'] !== null) {
            if (!IssnHelper::isValidFormat($values['issn'])) {
                $errors['issn'] = __('ISSN non valido: formato atteso ####-#### (es. 0028-0836).');
            } elseif (!IssnHelper::isValidChecksum($values['issn'])) {
                $errors['issn'] = __('ISSN non valido: la cifra di controllo non corrisponde.');
            } else {
                $values['issn'] = IssnHelper::normalize($values['issn']);
            }
        }
        // e-ISSN (electronic edition) and ISSN-L (linking ISSN) share the
        // ISSN grammar and checksum: same rules, same messages.
        foreach (['e_issn', 'issn_l'] as $extraIssnField) {
            $extraIssn = $values[$extraIssnField];
            if ($extraIssn === null) {
                continue;
            }
            if (!IssnHelper::isValidFormat($extraIssn)) {
                $errors[$extraIssnField] = __('ISSN non valido: formato atteso ####-#### (es. 0028-0836).');
            } elseif (!IssnHelper::isValidChecksum($extraIssn)) {
                $errors[$extraIssnField] = __('ISSN non valido: la cifra di controllo non corrisponde.');
            } else {
                $values[$extraIssnField] = IssnHelper::normalize($extraIssn);
            }
        }
        // barcode_base: an EAN-13 with the 977 serials prefix. Left empty it
        // is DERIVED from a valid ISSN — the librarian never has to compute a
        // check digit by hand; typed in by hand it is validated as an EAN-13
        // (13 digits), because a wrong barcode is worse than no barcode: the
        // scanner would silently match the wrong title.
        if ($values['barcode_base'] === null) {
            if ($values['issn'] !== null && !isset($errors['issn'])) {
                $values['barcode_base'] = IssnHelper::barcodeBase($values['issn']);
            }
        } elseif (preg_match('/^\d{' . self::BARCODE_LEN . '}$/', $values['barcode_base']) !== 1) {
            $errors['barcode_base'] = sprintf(
                __('Barcode non valido: attese %d cifre (EAN-13, prefisso 977).'),
                self::BARCODE_LEN
            );
        }
        // prezzo_copertina: DECIMAL(8,2). Accepts both decimal separators —
        // an Italian keyboard types "3,50", the browser number input "3.50".
        $prezzoRaw = trim((string) ($body['prezzo_copertina'] ?? ''));
        if ($prezzoRaw !== '') {
            $prezzoNorm = str_replace(',', '.', $prezzoRaw);
            if (preg_match('/^\d{1,6}(\.\d{1,2})?$/', $prezzoNorm) !== 1) {
                $errors['prezzo_copertina'] = __('Prezzo non valido (usa un numero con al massimo due decimali).');
                // Keep what was typed so the re-rendered form shows it back;
                // it never reaches bind_param, which only runs on $errors === [].
                $values['prezzo_copertina'] = mb_substr($prezzoRaw, 0, 20);
            } else {
                $values['prezzo_copertina'] = (float) $prezzoNorm;
            }
        }
        if ($values['acquisizione_default'] !== null
            && !array_key_exists($values['acquisizione_default'], \EmerotecaPlugin::TIPI_ACQUISIZIONE)) {
            $errors['acquisizione_default'] = __('Modalità di acquisizione non valida.');
        }
        if (!array_key_exists($values['prestabile'], \EmerotecaPlugin::OPZIONI_PRESTABILE)) {
            $errors['prestabile'] = __('Politica di prestito non valida.');
        }
        if ($values['periodicita'] !== null
            && !array_key_exists($values['periodicita'], \EmerotecaPlugin::PERIODICITA)) {
            $errors['periodicita'] = __('Periodicità non valida.');
        }
        if (!array_key_exists($values['tipo'], \EmerotecaPlugin::TIPI_TESTATA)) {
            $errors['tipo'] = __('Tipo di testata non valido.');
        }
        if (!array_key_exists($values['stato_raccolta'], \EmerotecaPlugin::STATI_RACCOLTA)) {
            $errors['stato_raccolta'] = __('Stato della raccolta non valido.');
        }
        foreach (['anno_inizio', 'anno_fine'] as $k) {
            if ($values[$k] !== null && ($values[$k] < self::ANNO_MIN || $values[$k] > self::ANNO_MAX)) {
                $errors[$k] = sprintf(__('Anno non plausibile (atteso tra %d e %d).'), self::ANNO_MIN, self::ANNO_MAX);
            }
        }
        if ($values['anno_inizio'] !== null && $values['anno_fine'] !== null
            && $values['anno_fine'] < $values['anno_inizio']) {
            $errors['anno_fine'] = __('L\'anno finale non può precedere l\'anno iniziale.');
        }
        if ($values['logo_url'] !== null && !str_starts_with($values['logo_url'], '/')) {
            $scheme = parse_url($values['logo_url'], PHP_URL_SCHEME);
            if (filter_var($values['logo_url'], FILTER_VALIDATE_URL) === false
                || !is_string($scheme)
                || !in_array(strtolower($scheme), ['http', 'https'], true)) {
                $errors['logo_url'] = __('URL del logo non valido (usa un URL assoluto o un percorso che inizia con /).');
            }
        }
        if ($selfId !== null
            && $values['testata_precedente_id'] !== null
            && $this->wouldCreateTitleCycle($selfId, $values['testata_precedente_id'])) {
            $errors['testata_precedente_id'] = __('La relazione tra testate creerebbe un ciclo.');
        }

        // Referential sanity: unknown ids become validation errors, not
        // FK explosions at INSERT time. Pointed lookups instead of
        // loading whole tables; degraded installs (missing core tables)
        // skip the check, like the fetch* helpers degrade to empty.
        if ($values['editore_id'] !== null
            && $this->refRowExists(
                'SELECT 1 FROM editori WHERE id = ? LIMIT 1',
                'i',
                [$values['editore_id']]
            ) === false) {
            $errors['editore_id'] = __('Editore non trovato.');
        }
        if ($values['genere_id'] !== null
            && $this->refRowExists(
                'SELECT 1 FROM generi WHERE id = ? AND parent_id IS NULL LIMIT 1',
                'i',
                [$values['genere_id']]
            ) === false) {
            $errors['genere_id'] = __('Genere non trovato.');
        }
        if ($values['testata_precedente_id'] !== null
            && $this->refRowExists(
                'SELECT 1 FROM emeroteca_testate WHERE id = ? AND id <> ? LIMIT 1',
                'ii',
                [$values['testata_precedente_id'], (int) ($selfId ?? 0)]
            ) === false) {
            $errors['testata_precedente_id'] = __('Testata precedente non trovata.');
        }

        return [$values, $errors];
    }

    /**
     * Reduce a testata row (DB row or validated form values) to the fields
     * worth keeping in the audit log. Volatile/derived columns (created_at,
     * updated_at, the joined editore_nome) are dropped: they add noise to
     * every diff without saying anything about what the operator changed.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function auditSnapshot(array $row): array
    {
        $fields = [
            'titolo', 'sottotitolo', 'issn', 'e_issn', 'issn_l', 'barcode_base',
            'editore_id', 'luogo_pubblicazione', 'direttore_responsabile',
            'registrazione_tribunale', 'prezzo_copertina', 'acquisizione_default',
            'prestabile', 'lingua', 'periodicita', 'tipo', 'anno_inizio',
            'anno_fine', 'testata_precedente_id', 'genere_id', 'logo_url',
            'stato_raccolta',
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
     * Pointed referential probe: true when the row exists, false when it
     * does not, null when the check itself failed (e.g. missing core
     * table on a degraded install) — callers treat null as "skip".
     *
     * @param list<int> $params
     */
    private function refRowExists(string $sql, string $types, array $params): ?bool
    {
        try {
            $stmt = $this->db->prepare($sql);
            if ($stmt === false) {
                SecureLogger::error('[Emeroteca] ref check prepare failed (check skipped): ' . $this->db->error);
                return null;
            }
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                SecureLogger::error('[Emeroteca] ref check failed (check skipped): ' . $stmt->error);
                $stmt->close();
                return null;
            }
            $res = $stmt->get_result();
            $exists = $res instanceof \mysqli_result && $res->fetch_row() !== null;
            $stmt->close();
            return $exists;
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] ref check error (check skipped): ' . $e->getMessage());
            return null;
        }
    }

    /** Reject self-links and longer A → B → … → A predecessor cycles. */
    private function wouldCreateTitleCycle(int $selfId, int $candidateId): bool
    {
        $current = $candidateId;
        $visited = [];
        for ($hop = 0; $hop < 100 && $current > 0; $hop++) {
            if ($current === $selfId || isset($visited[$current])) {
                return true;
            }
            $visited[$current] = true;
            $stmt = $this->db->prepare(
                'SELECT testata_precedente_id FROM emeroteca_testate WHERE id = ? LIMIT 1'
            );
            if ($stmt === false) {
                return true;
            }
            $stmt->bind_param('i', $current);
            if (!$stmt->execute()) {
                $stmt->close();
                return true;
            }
            $res = $stmt->get_result();
            $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!is_array($row) || $row['testata_precedente_id'] === null) {
                return false;
            }
            $current = (int) $row['testata_precedente_id'];
        }
        return $current > 0;
    }
}
