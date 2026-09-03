<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Controllers;

require_once __DIR__ . '/AbstractAdminController.php';

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
        $counts = ",
            (SELECT COUNT(*) FROM emeroteca_fascicoli f
              JOIN emeroteca_annate a ON f.annata_id = a.id
             WHERE a.testata_id = t.id AND f.stato = 'posseduto') AS n_posseduti,
            (SELECT COUNT(*) FROM emeroteca_fascicoli f
              JOIN emeroteca_annate a ON f.annata_id = a.id
             WHERE a.testata_id = t.id AND f.stato = 'mancante') AS n_mancanti";

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
        $sql = $select . $counts . $from
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY t.titolo';

        $rows = [];
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] index prepare failed: ' . $this->db->error);
        } else {
            if ($bindTypes !== '') {
                $stmt->bind_param($bindTypes, ...$bindVals);
            }
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($row = $res->fetch_assoc()) {
                        $row['consistenza'] = \EmerotecaPlugin::consistenzaTestata($this->db, (int) $row['id']);
                        $rows[] = $row;
                    }
                }
            } else {
                SecureLogger::error('[Emeroteca] index query failed: ' . $stmt->error);
            }
            $stmt->close();
        }

        return $this->renderView($response, 'index', [
            'rows'      => $rows,
            'editori'   => $this->fetchEditori(),
            'f_tipo'    => $fTipo,
            'f_editore' => $fEditore,
            'f_stato'   => $fStato,
        ]);
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
                (titolo, sottotitolo, issn, editore_id, luogo_pubblicazione, lingua,
                 periodicita, tipo, anno_inizio, anno_fine, testata_precedente_id,
                 genere_id, logo_url, descrizione, note, stato_raccolta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] create prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante il salvataggio della testata.'));
            return $this->redirect($response, '/admin/periodicals/create');
        }
        $stmt->bind_param(
            'sssissssiiiissss',
            $values['titolo'],
            $values['sottotitolo'],
            $values['issn'],
            $values['editore_id'],
            $values['luogo_pubblicazione'],
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
            $this->flashError(__('Errore durante il salvataggio della testata.'));
            return $this->redirect($response, '/admin/periodicals/create');
        }
        $newId = (int) $this->db->insert_id;
        $stmt->close();

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
        if ($this->fetchTestata($id) === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, '/admin/periodicals');
        }
        $body = (array) $request->getParsedBody();
        [$values, $errors] = $this->validate($body, $id);

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
                titolo = ?, sottotitolo = ?, issn = ?, editore_id = ?,
                luogo_pubblicazione = ?, lingua = ?, periodicita = ?, tipo = ?,
                anno_inizio = ?, anno_fine = ?, testata_precedente_id = ?,
                genere_id = ?, logo_url = ?, descrizione = ?, note = ?, stato_raccolta = ?
             WHERE id = ?'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] edit prepare failed: ' . $this->db->error);
            $this->flashError(__('Errore durante il salvataggio della testata.'));
            return $this->redirect($response, '/admin/periodicals/edit/' . $id);
        }
        $stmt->bind_param(
            'sssissssiiiissssi',
            $values['titolo'],
            $values['sottotitolo'],
            $values['issn'],
            $values['editore_id'],
            $values['luogo_pubblicazione'],
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
            $this->flashError(__('Errore durante il salvataggio della testata.'));
            return $this->redirect($response, '/admin/periodicals/edit/' . $id);
        }
        $stmt->close();

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
        // CSRF validated by CsrfMiddleware
        $id = (int) ($args['id'] ?? 0);
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
            $this->flashSuccess(__('Testata eliminata (con annate, fascicoli e spoglio).'));
        } else {
            $this->flashError(__('Testata non trovata.'));
        }
        return $this->redirect($response, '/admin/periodicals');
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
            'titolo'                => $str('titolo', 255) ?? '',
            'sottotitolo'           => $str('sottotitolo', 255),
            'issn'                  => $str('issn', 9),
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
        if ($values['issn'] !== null && !preg_match('/^\d{4}-\d{3}[\dXx]$/', $values['issn'])) {
            $errors['issn'] = __('ISSN non valido: formato atteso ####-#### (es. 0028-0836).');
        }
        if ($values['issn'] !== null) {
            $values['issn'] = strtoupper($values['issn']);
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
        if ($selfId !== null && $values['testata_precedente_id'] === $selfId) {
            $errors['testata_precedente_id'] = __('Una testata non può continuare sé stessa.');
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
}
