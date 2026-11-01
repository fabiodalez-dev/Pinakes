<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Controllers\SettingsController;
use App\Models\AttendanceRepository;
use App\Models\SettingsRepository;
use App\Plugins\MobileApi\Staff090\Support\SettingsKit;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Support\Features;
use App\Support\PatronCardSettings;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Settings → Modules, → Loans and → Cards for the staff app (Mobile API
 * 1.10.0). Every write goes through the web action (SettingsController) with
 * the complete form the web page would post: the stored values, overridden by
 * the fields the app sent, so a partial save never resets what it did not name.
 */
final class LibrarySettingsController
{
    /** Loans tab fields Staff may save: key => [min, max] for integers, null for booleans / strings. */
    public const LOAN_FIELDS = [
        'loan_duration_days' => [1, 3650],
        'pickup_expiry_days' => [1, 30],
        'pickup_reminder_days' => [0, 30],
        'card_expiry_warning_days' => [0, 365],
        'max_renewals' => [0, 100],
        'max_active_loans_per_user' => [0, 1000],
        'max_loan_duration_days' => [1, 3650],
        'recall_interval_days' => [1, 365],
        'recall_max_count' => [1, 50],
        'auto_approve_requests' => null,
        'allow_multiple_loans_same_book' => null,
        'recall_auto_enabled' => null,
        'app_timezone' => null,
    ];

    /** Loans tab fields only an administrator saves (money policy, card renewal base). */
    public const LOAN_ADMIN_FIELDS = [
        'daily_fine_cents', 'fine_max_per_loan_cents', 'debt_block_threshold_cents',
        'fine_grace_days', 'fine_grace_mode', 'card_renewal_base', 'fine_charged_email',
    ];

    /** API money field => stored loans setting (= SettingsController::FINE_AMOUNT_FIELDS keys). */
    private const LOAN_MONEY = [
        'daily_fine_cents' => 'daily_fine',
        'fine_max_per_loan_cents' => 'fine_max_per_loan',
        'debt_block_threshold_cents' => 'debt_block_threshold',
    ];

    /** Money setting => patron type column whose label the web's error names. */
    private const LOAN_MONEY_LABEL = [
        'daily_fine' => 'multa_giornaliera',
        'fine_max_per_loan' => 'multa_massima_prestito',
        'debt_block_threshold' => 'soglia_blocco_debito',
    ];

    private const GRACE_MODES = ['threshold', 'deduct'];
    private const CARD_RENEWAL_BASES = ['today', 'expiry'];

    public function __construct(private mysqli $db)
    {
    }

    // ─── Modules ───────────────────────────────────────────────────────────

    public function modules(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            return ResponseEnvelope::success($response, $this->modulesPayload());
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'modules settings', $e);
        }
    }

    public function updateModules(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!SettingsKit::mayConfigure()) {
            return SettingsKit::forbidden($response);
        }
        $json = JsonBody::parse($request);
        $errors = [];
        $features = $json['features'] ?? [];
        if (!is_array($features) || array_is_list($features) && $features !== []) {
            $errors['features'] = __('Valore non valido.');
            $features = [];
        }
        foreach ($features as $key => $value) {
            if (!in_array((string) $key, Features::keys(), true) || !is_bool($value)) {
                $errors['features.' . $key] = __('Valore non valido.');
            }
        }
        if ($e = SettingsKit::checkInt($json, 'attendance_retention_days', 0, AttendanceRepository::MAX_RETENTION_DAYS, false)) {
            $errors['attendance_retention_days'] = $e;
        }
        if ($errors !== []) {
            return SettingsKit::invalid($response, $errors);
        }
        try {
            $repository = new SettingsRepository($this->db);
            $stored = Features::snapshot();
            $wanted = array_merge($stored, array_map(static fn ($v): bool => (bool) $v, $features));
            $db = $this->db;

            $modulesTouched = array_key_exists('attendance_retention_days', $json)
                || array_intersect(array_keys($features), Features::MODULES_TAB) !== [];
            if ($modulesTouched) {
                $form = [
                    'attendance_retention_days' => (string) ($json['attendance_retention_days'] ?? AttendanceRepository::retentionDays($repository)),
                ];
                // Explicit '0' for an off switch: the web keeps an absent
                // inherited control as it is instead of switching it off.
                foreach (Features::MODULES_TAB as $key) {
                    $form['feature_' . $key] = $wanted[$key] ? '1' : '0';
                }
                $outcome = Delegation::run(static fn (): ResponseInterface => (new SettingsController())->updateModules(
                    Delegation::request('POST', '/admin/settings/modules', $form),
                    new SlimResponse(),
                    $db
                ));
                if (SettingsKit::errorOf($outcome) !== null) {
                    return SettingsKit::forbidden($response);
                }
            }

            if (array_intersect(array_keys($features), Features::LOANS_TAB) !== []) {
                $form = $this->loansForm($repository, []);
                foreach (Features::LOANS_TAB as $key) {
                    $form['feature_' . $key] = $wanted[$key] ? '1' : '0';
                }
                $outcome = Delegation::run(static fn (): ResponseInterface => (new SettingsController())->updateLoansSettings(
                    Delegation::request('POST', '/admin/settings/loans', $form),
                    new SlimResponse(),
                    $db
                ));
                if (($error = SettingsKit::errorOf($outcome)) !== null) {
                    return SettingsKit::invalid($response, ['features' => $error]);
                }
            }

            return ResponseEnvelope::success($response, $this->modulesPayload());
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'modules settings update', $e);
        }
    }

    /** @return array<string, mixed> */
    private function modulesPayload(): array
    {
        $modules = [];
        foreach (Features::keys() as $key) {
            $modules[] = [
                'key' => $key,
                'label' => Features::label($key),
                'help' => Features::help($key),
                'group' => in_array($key, Features::LOANS_TAB, true) ? 'loans' : 'modules',
                'stored' => Features::stored($key),
                'effective' => Features::enabled($key),
                'parent' => Features::PARENTS[$key] ?? null,
                'inherits_from' => Features::INHERITED[$key] ?? null,
                'circulation' => in_array($key, Features::CIRCULATION, true),
            ];
        }

        return [
            'can_configure' => SettingsKit::mayConfigure(),
            'catalogue_mode' => \App\Support\ConfigStore::isCatalogueMode(),
            'attendance_retention_days' => AttendanceRepository::retentionDays(new SettingsRepository($this->db)),
            'attendance_retention_max_days' => AttendanceRepository::MAX_RETENTION_DAYS,
            'modules' => $modules,
        ];
    }

    // ─── Loans ─────────────────────────────────────────────────────────────

    public function loans(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            return ResponseEnvelope::success($response, $this->loansPayload());
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'loan settings', $e);
        }
    }

    public function updateLoans(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $errors = [];
        foreach (self::LOAN_FIELDS as $key => $range) {
            if ($range !== null) {
                $error = SettingsKit::checkInt($json, $key, $range[0], $range[1], false);
            } elseif ($key === 'app_timezone') {
                $error = array_key_exists($key, $json)
                    && (!is_string($json[$key]) || !in_array($json[$key], \DateTimeZone::listIdentifiers(), true))
                    ? __('Valore non valido.') : null;
            } else {
                $error = SettingsKit::checkBool($json, $key);
            }
            if ($error !== null) {
                $errors[$key] = $error;
            }
        }
        foreach (self::LOAN_MONEY as $apiKey => $setting) {
            if ($e = SettingsKit::checkCents($json, $apiKey, \App\Controllers\CirculationSettingsController::fieldLabel(self::LOAN_MONEY_LABEL[$setting]))) {
                $errors[$apiKey] = $e;
            }
        }
        if ($e = SettingsKit::checkInt($json, 'fine_grace_days', 0, 365, false)) {
            $errors['fine_grace_days'] = $e;
        }
        if ($e = SettingsKit::checkBool($json, 'fine_charged_email')) {
            $errors['fine_charged_email'] = $e;
        }
        foreach (['fine_grace_mode' => self::GRACE_MODES, 'card_renewal_base' => self::CARD_RENEWAL_BASES] as $key => $allowed) {
            if (array_key_exists($key, $json) && !in_array($json[$key], $allowed, true)) {
                $errors[$key] = __('Valore non valido.');
            }
        }
        $unknown = array_diff(array_keys($json), array_keys(self::LOAN_FIELDS), self::LOAN_ADMIN_FIELDS);
        foreach ($unknown as $key) {
            $errors[(string) $key] = __('Valore non valido.');
        }
        if ($errors !== []) {
            return SettingsKit::invalid($response, $errors);
        }
        // Money policy and card renewal base: the web keeps them as they are
        // when Staff saves the tab; the app is told instead of being ignored.
        $adminSent = array_values(array_intersect(self::LOAN_ADMIN_FIELDS, array_keys($json)));
        if ($adminSent !== [] && !SettingsKit::mayConfigure()) {
            return SettingsKit::forbidden($response, array_fill_keys($adminSent, __('Operazione riservata agli amministratori')));
        }
        try {
            $repository = new SettingsRepository($this->db);
            $form = $this->loansForm($repository, $json);
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new SettingsController())->updateLoansSettings(
                Delegation::request('POST', '/admin/settings/loans', $form),
                new SlimResponse(),
                $db
            ));
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return SettingsKit::invalid($response, ['daily_fine_cents' => $error]);
            }

            return ResponseEnvelope::success($response, $this->loansPayload());
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'loan settings update', $e);
        }
    }

    /**
     * The loans form exactly as the web page posts it: every stored value and
     * every saved Loans-tab switch, with $json (API keys) on top.
     *
     * @param array<string, mixed> $json
     * @return array<string, string>
     */
    private function loansForm(SettingsRepository $repository, array $json): array
    {
        $current = $this->currentLoans($repository);
        $values = array_merge($current, array_intersect_key($json, $current));
        $form = [];
        foreach (self::LOAN_FIELDS as $key => $range) {
            $value = $values[$key];
            $form[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
        foreach (self::LOAN_MONEY as $apiKey => $setting) {
            $cents = $values[$apiKey];
            $form[$setting] = is_int($cents) && $cents > 0 ? SettingsKit::decimal($cents) : '';
        }
        $form['fine_grace_days'] = (string) $values['fine_grace_days'];
        $form['fine_grace_mode'] = (string) $values['fine_grace_mode'];
        $form['card_renewal_base'] = (string) $values['card_renewal_base'];
        $form['fine_charged_email'] = $values['fine_charged_email'] ? '1' : '0';
        foreach (Features::LOANS_TAB as $key) {
            $form['feature_' . $key] = Features::stored($key) ? '1' : '0';
        }

        return $form;
    }

    /**
     * The stored loans tab in API keys (SettingsController::resolveLoansSettings()).
     *
     * @return array<string, mixed>
     */
    private function currentLoans(SettingsRepository $repository): array
    {
        $raw = (new SettingsController())->resolveLoansSettings($repository);
        $out = [];
        foreach (self::LOAN_FIELDS as $key => $range) {
            $out[$key] = $range !== null ? (int) $raw[$key] : ($key === 'app_timezone' ? (string) $raw[$key] : (bool) $raw[$key]);
        }
        foreach (self::LOAN_MONEY as $apiKey => $setting) {
            $out[$apiKey] = SettingsKit::cents($raw[$setting]);
        }
        $out['fine_grace_days'] = (int) $raw['fine_grace_days'];
        $out['fine_grace_mode'] = in_array($raw['fine_grace_mode'], self::GRACE_MODES, true) ? (string) $raw['fine_grace_mode'] : 'threshold';
        $out['card_renewal_base'] = in_array($raw['card_renewal_base'], self::CARD_RENEWAL_BASES, true) ? (string) $raw['card_renewal_base'] : 'today';
        $out['fine_charged_email'] = (bool) $raw['fine_charged_email'];

        return $out;
    }

    /** @return array<string, mixed> */
    private function loansPayload(): array
    {
        $canConfigure = SettingsKit::mayConfigure();
        $limits = [];
        foreach (self::LOAN_FIELDS as $key => $range) {
            if ($range !== null) {
                $limits[$key] = ['min' => $range[0], 'max' => $range[1]];
            }
        }
        $limits['fine_grace_days'] = ['min' => 0, 'max' => 365];
        $limits['money_max_cents'] = ['min' => 0, 'max' => \App\Services\FineParameters::MAX_SETTING_CENTS];

        return [
            'can_configure' => $canConfigure,
            'currency' => \App\Services\FineParameters::currency(),
            'settings' => $this->currentLoans(new SettingsRepository($this->db)),
            'editable_fields' => array_merge(array_keys(self::LOAN_FIELDS), $canConfigure ? self::LOAN_ADMIN_FIELDS : []),
            'admin_only_fields' => self::LOAN_ADMIN_FIELDS,
            'limits' => $limits,
            'options' => [
                'fine_grace_mode' => self::GRACE_MODES,
                'card_renewal_base' => self::CARD_RENEWAL_BASES,
            ],
        ];
    }

    // ─── Cards ─────────────────────────────────────────────────────────────

    public function cards(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = SettingsKit::configurationGate('patron_cards', $response)) {
            return $gate;
        }
        try {
            return ResponseEnvelope::success($response, $this->cardsPayload());
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'card settings', $e);
        }
    }

    public function updateCards(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = SettingsKit::configurationGate('patron_cards', $response)) {
            return $gate;
        }
        $json = JsonBody::parse($request);
        $errors = [];
        foreach (['layout' => PatronCardSettings::LAYOUTS, 'code_type' => PatronCardSettings::CODE_TYPES, 'format' => PatronCardSettings::FORMATS] as $key => $allowed) {
            if (array_key_exists($key, $json) && !in_array($json[$key], $allowed, true)) {
                $errors[$key] = __('Valore non valido.');
            }
        }
        foreach (['title' => PatronCardSettings::TITLE_MAX, 'back_text' => PatronCardSettings::BACK_TEXT_MAX] as $key => $max) {
            if ($e = SettingsKit::checkString($json, $key, $max, false)) {
                $errors[$key] = $e;
            }
        }
        foreach (['show_photo', 'show_logo', 'show_expiry'] as $key) {
            if ($e = SettingsKit::checkBool($json, $key)) {
                $errors[$key] = $e;
            }
        }
        foreach (array_diff(array_keys($json), array_keys(PatronCardSettings::defaults())) as $key) {
            $errors[(string) $key] = __('Valore non valido.');
        }
        if ($errors !== []) {
            return SettingsKit::invalid($response, $errors);
        }
        try {
            $settings = array_merge(PatronCardSettings::resolve(new SettingsRepository($this->db)), $json);
            $form = [];
            foreach (['layout', 'code_type', 'format', 'title', 'back_text'] as $key) {
                $form['card_' . $key] = (string) $settings[$key];
            }
            foreach (['show_photo', 'show_logo', 'show_expiry'] as $key) {
                if ($settings[$key] === true) {
                    $form['card_' . $key] = '1';
                }
            }
            $db = $this->db;
            Delegation::run(static fn (): ResponseInterface => (new SettingsController())->updateCards(
                Delegation::request('POST', '/admin/settings/cards', $form),
                new SlimResponse(),
                $db
            ));

            return ResponseEnvelope::success($response, $this->cardsPayload());
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'card settings update', $e);
        }
    }

    /** @return array<string, mixed> */
    private function cardsPayload(): array
    {
        return [
            'can_configure' => true,
            'module_enabled' => Features::enabled('patron_cards'),
            'settings' => PatronCardSettings::resolve(new SettingsRepository($this->db)),
            'options' => [
                'layout' => PatronCardSettings::LAYOUTS,
                'code_type' => PatronCardSettings::CODE_TYPES,
                'format' => PatronCardSettings::FORMATS,
            ],
            'limits' => [
                'title_max' => PatronCardSettings::TITLE_MAX,
                'back_text_max' => PatronCardSettings::BACK_TEXT_MAX,
            ],
        ];
    }
}
