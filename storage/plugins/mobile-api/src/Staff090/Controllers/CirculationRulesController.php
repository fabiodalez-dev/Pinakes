<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Controllers\CirculationSettingsController;
use App\Plugins\MobileApi\Staff090\Support\SettingsKit;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\DelegationOutcome;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Services\Circulation\LoanPolicy;
use App\Services\CirculationPolicy;
use App\Support\Features;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Settings → Circulation for the staff app (Mobile API 1.10.0): patron types
 * and the rules matrix patron type × material. Reads come from
 * CirculationSettingsController::viewData() (what the settings tab renders);
 * writes are the web actions, posted with the complete form so a partial
 * update keeps every value it does not name.
 *
 * Access mirrors the web routes: FeatureGateMiddleware('circulation_rules',
 * configuration: true), then the controller's mayConfigure() guard.
 */
final class CirculationRulesController
{
    private const FEATURE = 'circulation_rules';

    /** API field => tipologie_lettore integer column. */
    public const TYPE_INTS = [
        'max_loans' => 'max_prestiti',
        'loan_duration_days' => 'durata_prestito_giorni',
        'max_request_duration_days' => 'max_durata_richiesta_giorni',
        'max_renewals' => 'max_rinnovi',
        'renewal_days' => 'giorni_rinnovo',
        'max_reservations' => 'max_prenotazioni',
        'card_duration_days' => 'durata_tessera_giorni',
        'grace_days' => 'giorni_tolleranza',
    ];

    /** API field => tipologie_lettore money column. */
    public const TYPE_MONEY = [
        'daily_fine_cents' => 'multa_giornaliera',
        'max_fine_per_loan_cents' => 'multa_massima_prestito',
        'debt_block_threshold_cents' => 'soglia_blocco_debito',
    ];

    /** API cell field => web matrix field (CirculationSettingsController::RULE_FIELDS keys). */
    public const RULE_INTS = [
        'max_loans' => 'max',
        'loan_duration_days' => 'duration',
        'max_renewals' => 'renewals',
        'renewal_days' => 'renewal_days',
        'grace_days' => 'grace',
    ];

    /** API cell field => web matrix money field (RULE_MONEY_FIELDS keys). */
    public const RULE_MONEY = [
        'daily_fine_cents' => 'fine',
        'max_fine_per_loan_cents' => 'fine_max',
    ];

    public function __construct(private mysqli $db)
    {
    }

    // ─── Patron types ──────────────────────────────────────────────────────

    public function types(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = SettingsKit::adminArea(self::FEATURE, $response)) {
            return $gate;
        }
        try {
            return ResponseEnvelope::success($response, $this->typesPayload());
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'patron types', $e);
        }
    }

    public function type(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if ($gate = SettingsKit::adminArea(self::FEATURE, $response)) {
            return $gate;
        }
        try {
            $type = $this->loadType($id);

            return $type === null
                ? SettingsKit::notFound($response, __('Tipologia lettore non trovata.'))
                : ResponseEnvelope::success($response, $type);
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'patron type', $e);
        }
    }

    public function createType(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = SettingsKit::adminArea(self::FEATURE, $response)) {
            return $gate;
        }
        $json = JsonBody::parse($request);
        unset($json['idempotency_key']);
        if (!array_key_exists('name', $json)) {
            return SettingsKit::invalid($response, ['name' => __('Il nome della tipologia è obbligatorio (massimo 100 caratteri).')]);
        }
        if (($errors = $this->typeErrors($json)) !== []) {
            return SettingsKit::invalid($response, $errors);
        }
        try {
            $db = $this->db;
            $form = $this->typeForm($json, null);

            return (new StaffIdempotency($db))->run(
                $request,
                $response,
                JsonBody::parse($request),
                'patron_type_create',
                SettingsKit::userId($request),
                0,
                $json,
                function () use ($db, $form, $response): ResponseInterface|int {
                    $before = array_keys((new CirculationPolicy($db, configuration: true))->types());
                    $outcome = Delegation::run(static fn (): ResponseInterface => (new CirculationSettingsController())->createType(
                        Delegation::request('POST', '/admin/settings/circulation/types', $form),
                        new SlimResponse(),
                        $db
                    ));
                    if (($error = SettingsKit::errorOf($outcome)) !== null) {
                        return $this->typeError($response, $error);
                    }
                    $new = array_values(array_diff(array_keys((new CirculationPolicy($db, configuration: true))->types()), $before));
                    if ($new === []) {
                        throw new \RuntimeException('patron type not created');
                    }

                    return (int) max($new);
                },
                fn (int $id): ?array => $this->loadType($id)
            );
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'patron type create', $e);
        }
    }

    public function updateType(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if ($gate = SettingsKit::adminArea(self::FEATURE, $response)) {
            return $gate;
        }
        $json = JsonBody::parse($request);
        if (($errors = $this->typeErrors($json)) !== []) {
            return SettingsKit::invalid($response, $errors);
        }
        try {
            $current = (new CirculationPolicy($this->db, configuration: true))->types()[$id] ?? null;
            if ($current === null) {
                return SettingsKit::notFound($response, __('Tipologia lettore non trovata.'));
            }
            $form = $this->typeForm($json, $current);
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new CirculationSettingsController())->updateType(
                Delegation::request('POST', '/admin/settings/circulation/types/' . $id, $form),
                new SlimResponse(),
                $db,
                $id
            ));
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return $this->typeError($response, $error);
            }

            return ResponseEnvelope::success($response, $this->loadType($id));
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'patron type update', $e);
        }
    }

    public function deleteType(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if ($gate = SettingsKit::adminArea(self::FEATURE, $response)) {
            return $gate;
        }
        try {
            $type = $this->loadType($id);
            if ($type === null) {
                return SettingsKit::notFound($response, __('Tipologia lettore non trovata.'));
            }
            $outcome = $this->delegate('deleteType', '/admin/settings/circulation/types/' . $id . '/delete', [], $id);
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return (bool) $type['is_default']
                    ? ResponseEnvelope::error($response, 'default_type', $error, 409)
                    : ResponseEnvelope::error($response, 'internal_error', $error, 500);
            }

            return ResponseEnvelope::success($response, ['id' => $id, 'deleted' => true, 'users_reassigned' => (int) $type['user_count']]);
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'patron type delete', $e);
        }
    }

    public function setDefault(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if ($gate = SettingsKit::adminArea(self::FEATURE, $response)) {
            return $gate;
        }
        try {
            if ($this->loadType($id) === null) {
                return SettingsKit::notFound($response, __('Tipologia lettore non trovata.'));
            }
            $outcome = $this->delegate('setDefault', '/admin/settings/circulation/types/' . $id . '/default', [], $id);
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return ResponseEnvelope::error($response, 'internal_error', $error, 500);
            }

            return ResponseEnvelope::success($response, $this->loadType($id));
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'patron type default', $e);
        }
    }

    public function moveType(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if ($gate = SettingsKit::adminArea(self::FEATURE, $response)) {
            return $gate;
        }
        $json = JsonBody::parse($request);
        $direction = $json['direction'] ?? null;
        if (!in_array($direction, ['up', 'down'], true)) {
            return SettingsKit::invalid($response, ['direction' => __('Valore non valido.')]);
        }
        try {
            if ($this->loadType($id) === null) {
                return SettingsKit::notFound($response, __('Tipologia lettore non trovata.'));
            }
            $outcome = $this->delegate('moveType', '/admin/settings/circulation/types/' . $id . '/move', ['direction' => $direction], $id);
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return ResponseEnvelope::error($response, 'internal_error', $error, 500);
            }

            return ResponseEnvelope::success($response, $this->typesPayload());
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'patron type move', $e);
        }
    }

    // ─── Rules matrix ──────────────────────────────────────────────────────

    public function rules(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = SettingsKit::adminArea(self::FEATURE, $response)) {
            return $gate;
        }
        try {
            return ResponseEnvelope::success($response, $this->rulesPayload());
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'circulation rules', $e);
        }
    }

    public function updateRules(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = SettingsKit::adminArea(self::FEATURE, $response)) {
            return $gate;
        }
        $json = JsonBody::parse($request);
        $cells = $json['cells'] ?? null;
        if (!is_array($cells) || !array_is_list($cells)) {
            return SettingsKit::invalid($response, ['cells' => __('Valore non valido.')]);
        }
        try {
            $policy = new CirculationPolicy($this->db, configuration: true);
            $validTypes = array_merge([CirculationPolicy::ALL_TYPES], array_keys($policy->types()));
            $media = array_keys(CirculationSettingsController::mediaTypes());
            $errors = [];
            $overrides = [];
            foreach ($cells as $i => $cell) {
                $prefix = 'cells.' . $i;
                if (!is_array($cell) || !is_int($cell['type_id'] ?? null) || !in_array($cell['type_id'], $validTypes, true)) {
                    $errors[$prefix . '.type_id'] = __('Valore non valido.');
                    continue;
                }
                if (!is_string($cell['media'] ?? null) || !in_array($cell['media'], $media, true)) {
                    $errors[$prefix . '.media'] = __('Valore non valido.');
                    continue;
                }
                if (array_key_exists('loanable', $cell) && $cell['loanable'] !== null && !is_bool($cell['loanable'])) {
                    $errors[$prefix . '.loanable'] = __('Valore non valido.');
                }
                foreach (self::RULE_INTS as $apiKey => $webKey) {
                    [, $min, $max] = CirculationSettingsController::RULE_FIELDS[$webKey];
                    if ($e = SettingsKit::checkInt($cell, $apiKey, $min, $max)) {
                        $errors[$prefix . '.' . $apiKey] = $e;
                    }
                }
                foreach (self::RULE_MONEY as $apiKey => $webKey) {
                    if ($e = SettingsKit::checkCents($cell, $apiKey, CirculationSettingsController::fieldLabel(CirculationSettingsController::RULE_MONEY_FIELDS[$webKey]))) {
                        $errors[$prefix . '.' . $apiKey] = $e;
                    }
                }
                foreach (array_diff(array_keys($cell), array_merge(['type_id', 'media', 'loanable'], array_keys(self::RULE_INTS), array_keys(self::RULE_MONEY))) as $key) {
                    $errors[$prefix . '.' . $key] = __('Valore non valido.');
                }
                $key = $cell['type_id'] . '|' . $cell['media'];
                $overrides[$key] = array_merge($overrides[$key] ?? [], $cell);
            }
            if ($errors !== []) {
                return SettingsKit::invalid($response, $errors);
            }

            // The whole matrix, as the settings tab posts it: every stored
            // cell, with the cells the app sent on top.
            $stored = $policy->rules();
            $form = [];
            foreach ($validTypes as $typeId) {
                foreach ($media as $m) {
                    $cell = $this->cellValues($stored[$typeId . '|' . $m] ?? null);
                    $cell = array_merge($cell, array_intersect_key($overrides[$typeId . '|' . $m] ?? [], $cell));
                    $row = ['loanable' => $cell['loanable'] === null ? '' : ($cell['loanable'] ? '1' : '0')];
                    foreach (self::RULE_INTS as $apiKey => $webKey) {
                        $row[$webKey] = SettingsKit::formInt($cell[$apiKey]);
                    }
                    foreach (self::RULE_MONEY as $apiKey => $webKey) {
                        $row[$webKey] = SettingsKit::decimal($cell[$apiKey]);
                    }
                    $form['rules'][(string) $typeId][$m] = $row;
                }
            }
            $outcome = $this->delegate('saveRules', '/admin/settings/circulation/rules', $form);
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return str_starts_with($error, __('Impossibile salvare le regole di circolazione.'))
                    ? ResponseEnvelope::error($response, 'internal_error', $error, 500)
                    : SettingsKit::invalid($response, ['cells' => $error]);
            }

            return ResponseEnvelope::success($response, $this->rulesPayload());
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'circulation rules update', $e);
        }
    }

    // ─── Payloads ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function typesPayload(): array
    {
        $data = CirculationSettingsController::viewData($this->db);
        $types = [];
        foreach ($data['types'] as $id => $row) {
            $types[] = $this->typeResource($row, $data['userCounts'][$id] ?? 0, $data['effective'][$id] ?? null);
        }
        $limits = [];
        foreach (self::TYPE_INTS as $apiKey => $column) {
            [$min, $max] = CirculationSettingsController::TYPE_INT_FIELDS[$column];
            $limits[$apiKey] = ['min' => $min, 'max' => $max];
        }
        $limits['money_max_cents'] = ['min' => 0, 'max' => \App\Services\FineParameters::MAX_SETTING_CENTS];

        return [
            'can_configure' => SettingsKit::mayConfigure(),
            'module_enabled' => Features::enabled(self::FEATURE),
            'currency' => \App\Services\FineParameters::currency(),
            'default_type_id' => $data['defaultTypeId'],
            'globals' => $this->globals($data['globals']),
            'limits' => $limits,
            'types' => $types,
        ];
    }

    /** @return array<string, mixed>|null */
    private function loadType(int $id): ?array
    {
        $data = CirculationSettingsController::viewData($this->db);
        if (!isset($data['types'][$id])) {
            return null;
        }

        return $this->typeResource($data['types'][$id], $data['userCounts'][$id] ?? 0, $data['effective'][$id] ?? null);
    }

    /**
     * @param array<string, mixed> $row tipologie_lettore row
     * @return array<string, mixed>
     */
    private function typeResource(array $row, int $userCount, ?LoanPolicy $effective): array
    {
        $out = [
            'id' => (int) $row['id'],
            'code' => (string) $row['codice'],
            'name' => (string) $row['nome'],
            'description' => $row['descrizione'] !== null ? (string) $row['descrizione'] : null,
            'reservations_enabled' => (int) $row['prenotazioni_abilitate'] === 1,
            'active' => (int) $row['attiva'] === 1,
            'is_default' => (int) $row['predefinita'] === 1,
            'order' => (int) ($row['ordinamento'] ?? 0),
            'user_count' => $userCount,
        ];
        foreach (self::TYPE_INTS as $apiKey => $column) {
            $out[$apiKey] = SettingsKit::intOrNull($row[$column] ?? null);
        }
        foreach (self::TYPE_MONEY as $apiKey => $column) {
            $out[$apiKey] = SettingsKit::cents($row[$column] ?? null);
        }
        $out['effective'] = $effective === null ? null : [
            'max_loans' => $effective->user->maxActiveLoans,
            'loan_duration_days' => $effective->durationDays,
            'max_request_duration_days' => $effective->maxRequestDays,
            'max_renewals' => $effective->maxRenewals,
            'renewal_days' => $effective->renewalDays,
            'reservations_enabled' => $effective->user->reservationsEnabled,
            'max_reservations' => $effective->user->maxReservations,
            'card_duration_days' => $effective->user->cardDurationDays,
            'grace_days' => $effective->graceDays,
            'daily_fine_cents' => SettingsKit::floatCents($effective->dailyFine),
            'max_fine_per_loan_cents' => SettingsKit::floatCents($effective->maxFinePerLoan),
            'debt_block_threshold_cents' => SettingsKit::floatCents($effective->user->debtBlockThreshold),
        ];

        return $out;
    }

    /**
     * The global layer (Settings → Loans) every empty value inherits.
     *
     * @param array<string, mixed> $globals
     * @return array<string, mixed>
     */
    private function globals(array $globals): array
    {
        return [
            'loan_duration_days' => (int) $globals['duration_days'],
            'max_request_duration_days' => (int) $globals['max_request_days'],
            'max_renewals' => (int) $globals['max_renewals'],
            'renewal_days' => SettingsKit::intOrNull($globals['renewal_days']),
            'max_loans' => (int) $globals['max_active_loans'],
            'reservations_enabled' => (bool) $globals['reservations_enabled'],
            'max_reservations' => (int) $globals['max_reservations'],
            'card_duration_days' => SettingsKit::intOrNull($globals['card_duration_days']),
            'grace_days' => SettingsKit::intOrNull($globals['grace_days']),
            'daily_fine_cents' => SettingsKit::cents($globals['daily_fine']),
            'max_fine_per_loan_cents' => SettingsKit::cents($globals['max_fine_per_loan']),
            'debt_block_threshold_cents' => SettingsKit::cents($globals['debt_block_threshold']),
        ];
    }

    /** @return array<string, mixed> */
    private function rulesPayload(): array
    {
        $data = CirculationSettingsController::viewData($this->db);
        $rows = [['type_id' => CirculationPolicy::ALL_TYPES, 'name' => __('Tutte le tipologie')]];
        foreach ($data['types'] as $id => $row) {
            $rows[] = ['type_id' => (int) $id, 'name' => (string) $row['nome']];
        }
        $media = [];
        foreach ($data['media'] as $key => $label) {
            $media[] = ['key' => (string) $key, 'label' => $label];
        }
        $cells = [];
        foreach ($rows as $r) {
            foreach (array_keys($data['media']) as $m) {
                $key = $r['type_id'] . '|' . $m;
                $stored = $data['rules'][$key] ?? null;
                $inherited = $data['inherited'][$key] ?? [];
                $cells[] = ['type_id' => $r['type_id'], 'media' => (string) $m, 'has_rule' => $stored !== null]
                    + $this->cellValues($stored)
                    + ['inherited' => [
                        'loanable' => (bool) ($inherited['loanable'] ?? true),
                        'max_loans' => (int) ($inherited['max'] ?? 0),
                        'loan_duration_days' => (int) ($inherited['duration'] ?? 0),
                        'max_renewals' => (int) ($inherited['renewals'] ?? 0),
                        'renewal_days' => (int) ($inherited['renewal_days'] ?? 0),
                        'grace_days' => (int) ($inherited['grace'] ?? 0),
                        'daily_fine_cents' => SettingsKit::floatCents(isset($inherited['fine']) ? (float) $inherited['fine'] : null),
                        'max_fine_per_loan_cents' => SettingsKit::floatCents(isset($inherited['fine_max']) ? (float) $inherited['fine_max'] : null),
                    ]];
            }
        }
        $limits = [];
        foreach (self::RULE_INTS as $apiKey => $webKey) {
            [, $min, $max] = CirculationSettingsController::RULE_FIELDS[$webKey];
            $limits[$apiKey] = ['min' => $min, 'max' => $max];
        }
        $limits['money_max_cents'] = ['min' => 0, 'max' => \App\Services\FineParameters::MAX_SETTING_CENTS];

        return [
            'can_configure' => SettingsKit::mayConfigure(),
            'module_enabled' => Features::enabled(self::FEATURE),
            'currency' => \App\Services\FineParameters::currency(),
            'media' => $media,
            'rows' => $rows,
            'limits' => $limits,
            'cells' => $cells,
        ];
    }

    /**
     * A stored regole_prestito row (or none) in API cell keys; null = inherit.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function cellValues(?array $row): array
    {
        $loanable = $row['prestabile'] ?? null;
        $out = ['loanable' => $loanable === null ? null : (int) $loanable === 1];
        foreach (self::RULE_INTS as $apiKey => $webKey) {
            $out[$apiKey] = SettingsKit::intOrNull($row[CirculationSettingsController::RULE_FIELDS[$webKey][0]] ?? null);
        }
        foreach (self::RULE_MONEY as $apiKey => $webKey) {
            $out[$apiKey] = SettingsKit::cents($row[CirculationSettingsController::RULE_MONEY_FIELDS[$webKey]] ?? null);
        }

        return $out;
    }

    // ─── Forms and errors ──────────────────────────────────────────────────

    /**
     * Type checks of a patron type body; the web action validates the rest
     * (name, code pattern, uniqueness) and its message is mapped back.
     *
     * @param array<string, mixed> $json
     * @return array<string, string>
     */
    private function typeErrors(array $json): array
    {
        $errors = [];
        if ($e = SettingsKit::checkString($json, 'name', 100, false)) {
            $errors['name'] = $e;
        }
        if ($e = SettingsKit::checkString($json, 'code', 40)) {
            $errors['code'] = $e;
        }
        if ($e = SettingsKit::checkString($json, 'description', 2000)) {
            $errors['description'] = $e;
        }
        foreach (['reservations_enabled', 'active', 'is_default'] as $key) {
            if ($e = SettingsKit::checkBool($json, $key)) {
                $errors[$key] = $e;
            }
        }
        foreach (self::TYPE_INTS as $apiKey => $column) {
            [$min, $max] = CirculationSettingsController::TYPE_INT_FIELDS[$column];
            if ($e = SettingsKit::checkInt($json, $apiKey, $min, $max)) {
                $errors[$apiKey] = $e;
            }
        }
        foreach (self::TYPE_MONEY as $apiKey => $column) {
            if ($e = SettingsKit::checkCents($json, $apiKey, CirculationSettingsController::fieldLabel($column))) {
                $errors[$apiKey] = $e;
            }
        }
        $known = array_merge(['name', 'code', 'description', 'reservations_enabled', 'active', 'is_default', 'idempotency_key'], array_keys(self::TYPE_INTS), array_keys(self::TYPE_MONEY));
        foreach (array_diff(array_keys($json), $known) as $key) {
            $errors[(string) $key] = __('Valore non valido.');
        }

        return $errors;
    }

    /**
     * The patron type form: the stored type (on update) with the body on top.
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed>|null $current
     * @return array<string, string>
     */
    private function typeForm(array $json, ?array $current): array
    {
        $base = $current === null ? [
            'name' => '', 'code' => '', 'description' => null, 'reservations_enabled' => true, 'active' => true, 'is_default' => false,
        ] : [
            'name' => (string) $current['nome'],
            'code' => (string) $current['codice'],
            'description' => $current['descrizione'],
            'reservations_enabled' => (int) $current['prenotazioni_abilitate'] === 1,
            'active' => (int) $current['attiva'] === 1,
            'is_default' => false,
        ];
        foreach (self::TYPE_INTS as $apiKey => $column) {
            $base[$apiKey] = $current === null ? null : SettingsKit::intOrNull($current[$column] ?? null);
        }
        foreach (self::TYPE_MONEY as $apiKey => $column) {
            $base[$apiKey] = $current === null ? null : SettingsKit::cents($current[$column] ?? null);
        }
        $values = array_merge($base, array_intersect_key($json, $base));
        $form = [
            'nome' => (string) $values['name'],
            'codice' => (string) ($values['code'] ?? ''),
            'descrizione' => (string) ($values['description'] ?? ''),
            'attiva' => $values['active'] ? '1' : '0',
        ];
        if ($values['reservations_enabled']) {
            $form['prenotazioni_abilitate'] = '1';
        }
        if ($values['is_default'] === true) {
            $form['predefinita'] = '1';
        }
        foreach (self::TYPE_INTS as $apiKey => $column) {
            $form[$column] = SettingsKit::formInt($values[$apiKey]);
        }
        foreach (self::TYPE_MONEY as $apiKey => $column) {
            $form[$column] = SettingsKit::decimal($values[$apiKey]);
        }

        return $form;
    }

    /** The web's refusal of a patron type form, with the field it names. */
    private function typeError(ResponseInterface $response, string $error): ResponseInterface
    {
        if ($error === __('Il nome della tipologia è obbligatorio (massimo 100 caratteri).')) {
            return SettingsKit::invalid($response, ['name' => $error]);
        }
        if ($error === __('Il codice può contenere solo lettere minuscole, numeri, trattini e trattini bassi (massimo 40 caratteri).')
            || $error === __('Esiste già una tipologia con questo codice.')) {
            return SettingsKit::invalid($response, ['code' => $error]);
        }
        foreach (array_merge(self::TYPE_INTS, self::TYPE_MONEY) as $apiKey => $column) {
            if (str_contains($error, '"' . CirculationSettingsController::fieldLabel($column) . '"')) {
                return SettingsKit::invalid($response, [$apiKey => $error]);
            }
        }
        if (str_starts_with($error, __('Impossibile salvare la tipologia lettore.'))) {
            return ResponseEnvelope::error($response, 'internal_error', $error, 500);
        }

        return ResponseEnvelope::error($response, 'schema_not_ready', $error, 409);
    }

    /** @param array<string, mixed> $form */
    private function delegate(string $action, string $path, array $form, ?int $id = null): DelegationOutcome
    {
        $db = $this->db;

        return Delegation::run(static function () use ($action, $path, $form, $id, $db): ResponseInterface {
            $controller = new CirculationSettingsController();
            $request = Delegation::request('POST', $path, $form);

            return $id === null
                ? $controller->{$action}($request, new SlimResponse(), $db)
                : $controller->{$action}($request, new SlimResponse(), $db, $id);
        });
    }
}
