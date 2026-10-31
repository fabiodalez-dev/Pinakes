<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CirculationPolicy;
use App\Support\MediaLabels;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Settings → Circulation: patron types (tipologie_lettore) and the rules
 * matrix patron type × material (regole_prestito). Every rule field is
 * optional: empty = inherit (see CirculationPolicy for the precedence).
 */
class CirculationSettingsController
{
    /** Patron type fields edited by the form: column => [min, max] (NULL when empty). */
    private const TYPE_INT_FIELDS = [
        'max_prestiti' => [0, 1000],
        'durata_prestito_giorni' => [1, 3650],
        'max_durata_richiesta_giorni' => [1, 3650],
        'max_rinnovi' => [0, 100],
        'giorni_rinnovo' => [1, 3650],
        'max_prenotazioni' => [0, 1000],
        'durata_tessera_giorni' => [1, 36500],
        'giorni_tolleranza' => [0, 365],
    ];

    /** Patron type money fields (DECIMAL(10,2), NULL when empty; 0 = disabled for this type). */
    public const TYPE_MONEY_FIELDS = ['multa_giornaliera', 'multa_massima_prestito', 'soglia_blocco_debito'];

    /** Matrix cell fields: form key => [column, min, max]. */
    private const RULE_FIELDS = [
        'max' => ['max_prestiti', 0, 1000],
        'duration' => ['durata_prestito_giorni', 1, 3650],
        'renewals' => ['max_rinnovi', 0, 100],
        'renewal_days' => ['giorni_rinnovo', 1, 3650],
        'grace' => ['giorni_tolleranza', 0, 365],
    ];

    /** Matrix cell fields added with the fines: absent from an older form, kept as stored. */
    private const RULE_FIELDS_KEPT_WHEN_ABSENT = ['grace'];

    /** Matrix cell money fields: form key => column. */
    private const RULE_MONEY_FIELDS = [
        'fine' => 'multa_giornaliera',
        'fine_max' => 'multa_massima_prestito',
    ];

    private const TAB_URL = '/admin/settings?tab=circulation';

    /**
     * Data for the circulation tab of the settings page.
     *
     * @return array{types: array<int, array<string, mixed>>, defaultTypeId: ?int, media: array<string, string>,
     *               rules: array<string, array<string, mixed>>, inherited: array<string, array<string, mixed>>,
     *               globals: array<string, mixed>, userCounts: array<int, int>, effective: array<int, \App\Services\Circulation\LoanPolicy>}
     */
    public static function viewData(mysqli $db): array
    {
        $policy = new CirculationPolicy($db);
        $types = $policy->types();
        $media = self::mediaTypes();
        $globals = $policy->globalLayer();
        $rules = $policy->rules();

        // What an empty cell resolves to: every layer below the cell itself.
        $inherited = [];
        $rows = array_merge([CirculationPolicy::ALL_TYPES], array_keys($types));
        foreach ($rows as $typeId) {
            foreach (array_keys($media) as $m) {
                if ($typeId === CirculationPolicy::ALL_TYPES) {
                    $resolved = \App\Services\Circulation\PolicyResolver::resolve([], $globals, \App\Services\Circulation\PolicyResolver::resolveUser(null, $globals), $m);
                } else {
                    $layers = $policy->ruleLayers($typeId, $m);
                    array_shift($layers); // drop the cell's own rule
                    $resolved = \App\Services\Circulation\PolicyResolver::resolve($layers, $globals, \App\Services\Circulation\PolicyResolver::resolveUser(null, $globals), $m);
                }
                $inherited[$typeId . '|' . $m] = [
                    'loanable' => $resolved->loanable,
                    'max' => $resolved->maxActiveForMedia,
                    'duration' => $resolved->durationDays,
                    'renewals' => $resolved->maxRenewals,
                    'renewal_days' => $resolved->renewalDays,
                    'grace' => (int) ($resolved->graceDays ?? 0),
                    'fine' => $resolved->dailyFine,
                    'fine_max' => $resolved->maxFinePerLoan,
                ];
            }
        }

        $effective = [];
        foreach (array_keys($types) as $typeId) {
            $effective[$typeId] = $policy->forType($typeId);
        }

        $userCounts = [];
        if ($policy->schemaReady()) {
            $result = $db->query('SELECT tipologia_id, COUNT(*) AS c FROM utenti WHERE tipologia_id IS NOT NULL GROUP BY tipologia_id');
            if ($result instanceof \mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    $userCounts[(int) $row['tipologia_id']] = (int) $row['c'];
                }
            }
        }

        return [
            'types' => $types,
            'defaultTypeId' => $policy->defaultTypeId(),
            'media' => $media,
            'rules' => $rules,
            'inherited' => $inherited,
            'globals' => $globals,
            'userCounts' => $userCounts,
            'effective' => $effective,
        ];
    }

    /** @return array<string, string> tipo_media => translated label */
    public static function mediaTypes(): array
    {
        $out = [];
        foreach (MediaLabels::allTypes() as $key => $meta) {
            $out[$key] = __($meta['label']);
        }
        return $out;
    }

    /**
     * Patron types and the rules matrix are library policy: administrators
     * only (or a custom role with settings.manage), like the closures
     * calendar. AdminAuthMiddleware admits staff too, so every action checks
     * it inline.
     */
    private function guard(Response $response): ?Response
    {
        if (\App\Support\Permissions::mayConfigure()) {
            return null;
        }
        $_SESSION['error_message'] = __('Operazione riservata agli amministratori');
        return $response->withHeader('Location', url('/admin/dashboard'))->withStatus(302);
    }

    public function index(Request $request, Response $response): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
    }

    public function editForm(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        $policy = new CirculationPolicy($db);
        $type = $policy->types()[$id] ?? null;
        if ($type === null) {
            $_SESSION['error_message'] = __('Tipologia lettore non trovata.');
            return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
        }
        $globals = $policy->globalLayer();
        $title = __('Modifica tipologia lettore');
        ob_start();
        require __DIR__ . '/../Views/settings/circulation-type-edit.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write((string) $html);
        return $response;
    }

    public function createType(Request $request, Response $response, mysqli $db): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        $data = (array) ($request->getParsedBody() ?? []);
        $fields = $this->typeFields($data, null);
        if (is_string($fields)) {
            $_SESSION['error_message'] = $fields;
            return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
        }
        if (!(new CirculationPolicy($db))->schemaReady()) {
            $_SESSION['error_message'] = __('Le tabelle della circolazione non sono ancora state create: completa l\'aggiornamento del database.');
            return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
        }
        if ($this->codeTaken($db, (string) $fields['codice'], null)) {
            $_SESSION['error_message'] = __('Esiste già una tipologia con questo codice.');
            return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
        }
        $orderResult = $db->query('SELECT COALESCE(MAX(ordinamento), 0) + 10 FROM tipologie_lettore');
        $fields['ordinamento'] = $orderResult instanceof \mysqli_result ? (int) ($orderResult->fetch_row()[0] ?? 10) : 10;
        $makeDefault = !empty($data['predefinita']);
        $fields['predefinita'] = 0;
        try {
            $db->begin_transaction();
            $id = $this->insert($db, 'tipologie_lettore', $fields);
            if ($makeDefault) {
                $this->setDefaultTx($db, $id);
            }
            $db->commit();
            $_SESSION['success_message'] = __('Tipologia lettore creata.');
        } catch (\Throwable $e) {
            $db->rollback();
            SecureLogger::error('[Circulation] patron type create failed', ['error' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Impossibile salvare la tipologia lettore.');
        }
        return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
    }

    public function updateType(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        $data = (array) ($request->getParsedBody() ?? []);
        $policy = new CirculationPolicy($db);
        $current = $policy->types()[$id] ?? null;
        if ($current === null) {
            $_SESSION['error_message'] = __('Tipologia lettore non trovata.');
            return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
        }
        $fields = $this->typeFields($data, $current);
        if (is_string($fields)) {
            $_SESSION['error_message'] = $fields;
            return $response->withHeader('Location', url('/admin/settings/circulation/types/' . $id))->withStatus(302);
        }
        if ($this->codeTaken($db, (string) $fields['codice'], $id)) {
            $_SESSION['error_message'] = __('Esiste già una tipologia con questo codice.');
            return $response->withHeader('Location', url('/admin/settings/circulation/types/' . $id))->withStatus(302);
        }
        // The default type stays active: new patrons are assigned to it.
        if ((int) $current['predefinita'] === 1) {
            $fields['attiva'] = 1;
        }
        try {
            $db->begin_transaction();
            $this->update($db, 'tipologie_lettore', $fields, $id);
            if (!empty($data['predefinita'])) {
                $this->setDefaultTx($db, $id);
            }
            $db->commit();
            $_SESSION['success_message'] = __('Tipologia lettore aggiornata.');
        } catch (\Throwable $e) {
            $db->rollback();
            SecureLogger::error('[Circulation] patron type update failed', ['id' => $id, 'error' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Impossibile salvare la tipologia lettore.');
        }
        return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
    }

    public function deleteType(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        $policy = new CirculationPolicy($db);
        $type = $policy->types()[$id] ?? null;
        if ($type === null) {
            $_SESSION['error_message'] = __('Tipologia lettore non trovata.');
        } elseif ((int) $type['predefinita'] === 1) {
            $_SESSION['error_message'] = __('La tipologia predefinita non può essere eliminata: scegli prima un\'altra predefinita.');
        } else {
            try {
                $db->begin_transaction();
                // Patrons of the removed type fall back to the default one
                // (the FK is ON DELETE SET NULL; done explicitly for clarity).
                $stmt = $db->prepare('UPDATE utenti SET tipologia_id = NULL WHERE tipologia_id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                $stmt = $db->prepare('DELETE FROM regole_prestito WHERE tipologia_id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                $stmt = $db->prepare('DELETE FROM tipologie_lettore WHERE id = ? AND predefinita = 0');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                $db->commit();
                $_SESSION['success_message'] = __('Tipologia lettore eliminata.');
            } catch (\Throwable $e) {
                $db->rollback();
                SecureLogger::error('[Circulation] patron type delete failed', ['id' => $id, 'error' => $e->getMessage()]);
                $_SESSION['error_message'] = __('Impossibile eliminare la tipologia lettore.');
            }
        }
        return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
    }

    public function setDefault(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        if (!isset((new CirculationPolicy($db))->types()[$id])) {
            $_SESSION['error_message'] = __('Tipologia lettore non trovata.');
            return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
        }
        try {
            $db->begin_transaction();
            $this->setDefaultTx($db, $id);
            $db->commit();
            $_SESSION['success_message'] = __('Tipologia predefinita aggiornata.');
        } catch (\Throwable $e) {
            $db->rollback();
            SecureLogger::error('[Circulation] default patron type failed', ['id' => $id, 'error' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Impossibile salvare la tipologia lettore.');
        }
        return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
    }

    /** Move a type one place up or down in the display order. */
    public function moveType(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        $data = (array) ($request->getParsedBody() ?? []);
        $direction = ($data['direction'] ?? '') === 'up' ? -1 : 1;
        $ids = array_keys((new CirculationPolicy($db))->types());
        $pos = array_search($id, $ids, true);
        if ($pos !== false && isset($ids[$pos + $direction])) {
            $swap = $ids[$pos + $direction];
            $ids[$pos + $direction] = $id;
            $ids[$pos] = $swap;
            try {
                $db->begin_transaction();
                $stmt = $db->prepare('UPDATE tipologie_lettore SET ordinamento = ? WHERE id = ?');
                foreach ($ids as $i => $typeId) {
                    $order = ($i + 1) * 10;
                    $stmt->bind_param('ii', $order, $typeId);
                    $stmt->execute();
                }
                $stmt->close();
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                SecureLogger::error('[Circulation] patron type reorder failed', ['id' => $id, 'error' => $e->getMessage()]);
                $_SESSION['error_message'] = __('Impossibile salvare la tipologia lettore.');
            }
        }
        return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
    }

    /** Save the whole matrix: an all-empty cell removes its rule. */
    public function saveRules(Request $request, Response $response, mysqli $db): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        $data = (array) ($request->getParsedBody() ?? []);
        $policy = new CirculationPolicy($db);
        if (!$policy->schemaReady()) {
            $_SESSION['error_message'] = __('Le tabelle della circolazione non sono ancora state create: completa l\'aggiornamento del database.');
            return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
        }
        $cells = is_array($data['rules'] ?? null) ? $data['rules'] : [];
        $validTypes = array_merge([CirculationPolicy::ALL_TYPES], array_keys($policy->types()));
        $media = array_keys(self::mediaTypes());
        // A form rendered before the fine columns were in the matrix (an open
        // tab during the upgrade) does not send them: an absent field keeps
        // the stored value, only a field sent empty clears it.
        $existing = $policy->rules();
        $rows = [];
        foreach ($validTypes as $typeId) {
            foreach ($media as $m) {
                $cell = $cells[$typeId][$m] ?? [];
                $cell = is_array($cell) ? $cell : [];
                $stored = $existing[$typeId . '|' . $m] ?? [];
                $row = ['prestabile' => null];
                $loanable = (string) ($cell['loanable'] ?? '');
                if ($loanable === '1' || $loanable === '0') {
                    $row['prestabile'] = (int) $loanable;
                }
                foreach (self::RULE_FIELDS as $key => [$column, $min, $max]) {
                    if (in_array($key, self::RULE_FIELDS_KEPT_WHEN_ABSENT, true) && !array_key_exists($key, $cell)) {
                        $row[$column] = isset($stored[$column]) ? (int) $stored[$column] : null;
                        continue;
                    }
                    $value = $this->intOrNull($cell[$key] ?? null, $min, $max);
                    if ($value === false) {
                        $_SESSION['error_message'] = sprintf(__('Valore non valido nella matrice (%s, %s).'), $typeId === 0 ? __('Tutte le tipologie') : (string) ($policy->types()[$typeId]['nome'] ?? $typeId), (string) (self::mediaTypes()[$m] ?? $m));
                        return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
                    }
                    $row[$column] = $value;
                }
                foreach (self::RULE_MONEY_FIELDS as $key => $column) {
                    if (!array_key_exists($key, $cell)) {
                        $row[$column] = isset($stored[$column]) ? (string) $stored[$column] : null;
                        continue;
                    }
                    $value = self::moneyOrNull($cell[$key] ?? null);
                    if ($value === false) {
                        $_SESSION['error_message'] = sprintf(__('Valore non valido nella matrice (%s, %s).'), $typeId === 0 ? __('Tutte le tipologie') : (string) ($policy->types()[$typeId]['nome'] ?? $typeId), (string) (self::mediaTypes()[$m] ?? $m));
                        return $response->withHeader('Location', url(self::TAB_URL))->withStatus(302);
                    }
                    $row[$column] = $value;
                }
                $rows[$typeId . '|' . $m] = ['tipologia_id' => $typeId, 'tipo_media' => $m] + $row;
            }
        }
        try {
            $db->begin_transaction();
            $delete = $db->prepare('DELETE FROM regole_prestito WHERE tipologia_id = ? AND tipo_media = ?');
            $upsert = $db->prepare(
                'INSERT INTO regole_prestito (tipologia_id, tipo_media, prestabile, max_prestiti, durata_prestito_giorni, max_rinnovi,
                                              giorni_rinnovo, giorni_tolleranza, multa_giornaliera, multa_massima_prestito)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE prestabile = VALUES(prestabile), max_prestiti = VALUES(max_prestiti),
                     durata_prestito_giorni = VALUES(durata_prestito_giorni), max_rinnovi = VALUES(max_rinnovi),
                     giorni_rinnovo = VALUES(giorni_rinnovo), giorni_tolleranza = VALUES(giorni_tolleranza),
                     multa_giornaliera = VALUES(multa_giornaliera), multa_massima_prestito = VALUES(multa_massima_prestito)'
            );
            foreach ($rows as $row) {
                $typeId = (int) $row['tipologia_id'];
                $m = (string) $row['tipo_media'];
                $empty = $row['prestabile'] === null && $row['max_prestiti'] === null
                    && $row['durata_prestito_giorni'] === null && $row['max_rinnovi'] === null
                    && $row['giorni_rinnovo'] === null && $row['giorni_tolleranza'] === null
                    && $row['multa_giornaliera'] === null && $row['multa_massima_prestito'] === null;
                // Every rule column is edited in the matrix: an all-empty cell removes its rule.
                if ($empty) {
                    $delete->bind_param('is', $typeId, $m);
                    $delete->execute();
                    continue;
                }
                $loanable = $row['prestabile'];
                $max = $row['max_prestiti'];
                $duration = $row['durata_prestito_giorni'];
                $renewals = $row['max_rinnovi'];
                $renewalDays = $row['giorni_rinnovo'];
                $grace = $row['giorni_tolleranza'];
                $fine = $row['multa_giornaliera'];
                $fineMax = $row['multa_massima_prestito'];
                $upsert->bind_param('isiiiiiiss', $typeId, $m, $loanable, $max, $duration, $renewals, $renewalDays, $grace, $fine, $fineMax);
                $upsert->execute();
            }
            $delete->close();
            $upsert->close();
            $db->commit();
            $_SESSION['success_message'] = __('Regole di circolazione salvate.');
        } catch (\Throwable $e) {
            $db->rollback();
            SecureLogger::error('[Circulation] rules save failed', ['error' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Impossibile salvare le regole di circolazione.');
        }
        return $response->withHeader('Location', url(self::TAB_URL . '#circulation-matrix'))->withStatus(302);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Validated column values of a patron type form, or the error message.
     *
     * @param array<mixed> $data
     * @param array<string, mixed>|null $current
     * @return array<string, int|string|null>|string
     */
    private function typeFields(array $data, ?array $current): array|string
    {
        $name = trim(strip_tags((string) ($data['nome'] ?? '')));
        if ($name === '' || mb_strlen($name) > 100) {
            return __('Il nome della tipologia è obbligatorio (massimo 100 caratteri).');
        }
        $code = strtolower(trim((string) ($data['codice'] ?? '')));
        if ($code === '') {
            $code = $current !== null ? (string) $current['codice'] : self::slug($name);
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $code) !== 1) {
            return __('Il codice può contenere solo lettere minuscole, numeri, trattini e trattini bassi (massimo 40 caratteri).');
        }
        $description = trim(strip_tags((string) ($data['descrizione'] ?? '')));
        $fields = [
            'codice' => $code,
            'nome' => $name,
            'descrizione' => $description !== '' ? mb_substr($description, 0, 2000) : null,
            'prenotazioni_abilitate' => !empty($data['prenotazioni_abilitate']) ? 1 : 0,
            'attiva' => !array_key_exists('attiva', $data) || !empty($data['attiva']) ? 1 : 0,
        ];
        foreach (self::TYPE_INT_FIELDS as $column => [$min, $max]) {
            $value = $this->intOrNull($data[$column] ?? null, $min, $max);
            if ($value === false) {
                return sprintf(__('Valore non valido per il campo "%s".'), self::fieldLabel($column));
            }
            $fields[$column] = $value;
        }
        foreach (self::TYPE_MONEY_FIELDS as $column) {
            $value = self::moneyOrNull($data[$column] ?? null);
            if ($value === false) {
                $raw = $data[$column] ?? null;
                return is_scalar($raw) && \App\Services\FineParameters::exceedsSettingMax((string) $raw)
                    ? sprintf(__('Importo non valido per "%1$s": il massimo consentito è %2$s.'), self::fieldLabel($column), \App\Services\FineParameters::format(\App\Services\FineParameters::MAX_SETTING_CENTS))
                    : sprintf(__('Valore non valido per il campo "%s".'), self::fieldLabel($column));
            }
            $fields[$column] = $value;
        }
        return $fields;
    }

    /**
     * An amount typed by the operator ("2", "2,50") as a DECIMAL string.
     *
     * @return string|null|false null for an empty value, false when malformed, negative or above FineParameters::MAX_SETTING_CENTS
     */
    private static function moneyOrNull(mixed $raw): string|null|false
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (!is_scalar($raw)) {
            return false;
        }
        $cents = \App\Services\FineParameters::toCents((string) $raw);
        return $cents === null || $cents > \App\Services\FineParameters::MAX_SETTING_CENTS
            ? false
            : \App\Services\FineParameters::centsToDecimal($cents);
    }

    public static function fieldLabel(string $column): string
    {
        return match ($column) {
            'max_prestiti' => __('Prestiti attivi massimi'),
            'durata_prestito_giorni' => __('Durata prestito (giorni)'),
            'max_durata_richiesta_giorni' => __('Durata massima richiedibile (giorni)'),
            'max_rinnovi' => __('Rinnovi massimi'),
            'giorni_rinnovo' => __('Giorni per rinnovo'),
            'max_prenotazioni' => __('Prenotazioni massime'),
            'durata_tessera_giorni' => __('Durata tessera (giorni)'),
            'multa_giornaliera' => __('Multa giornaliera'),
            'giorni_tolleranza' => __('Giorni di tolleranza'),
            'multa_massima_prestito' => __('Multa massima per prestito'),
            'soglia_blocco_debito' => __('Soglia di blocco per debito'),
            default => $column,
        };
    }

    /** @return int|null|false null for an empty value, false when out of range or not an integer */
    private function intOrNull(mixed $raw, int $min, int $max): int|null|false
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (!is_scalar($raw) || preg_match('/^-?\d+$/', trim((string) $raw)) !== 1) {
            return false;
        }
        $value = (int) trim((string) $raw);
        return $value < $min || $value > $max ? false : $value;
    }

    private static function slug(string $name): string
    {
        $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
        return $slug !== '' ? substr($slug, 0, 40) : 'tipologia-' . bin2hex(random_bytes(3));
    }

    private function codeTaken(mysqli $db, string $code, ?int $exceptId): bool
    {
        $stmt = $db->prepare('SELECT id FROM tipologie_lettore WHERE codice = ? AND id <> ?');
        $except = $exceptId ?? 0;
        $stmt->bind_param('si', $code, $except);
        $stmt->execute();
        $taken = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $taken;
    }

    /** Exactly one default type: clear the flag everywhere, then set it (inside the caller's transaction). */
    private function setDefaultTx(mysqli $db, int $id): void
    {
        $db->query('UPDATE tipologie_lettore SET predefinita = 0 WHERE predefinita = 1');
        $stmt = $db->prepare('UPDATE tipologie_lettore SET predefinita = 1, attiva = 1 WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    /** @param array<string, int|string|null> $fields */
    private function insert(mysqli $db, string $table, array $fields): int
    {
        $columns = array_keys($fields);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $stmt = $db->prepare($sql);
        $values = array_values($fields);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    }

    /** @param array<string, int|string|null> $fields */
    private function update(mysqli $db, string $table, array $fields, int $id): void
    {
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($fields)));
        $stmt = $db->prepare('UPDATE ' . $table . ' SET ' . $set . ' WHERE id = ?');
        $values = array_values($fields);
        $values[] = $id;
        $stmt->bind_param(str_repeat('s', count($values) - 1) . 'i', ...$values);
        $stmt->execute();
        $stmt->close();
    }
}
