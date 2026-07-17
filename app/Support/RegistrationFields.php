<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * Admin-configurable registration fields (issue #255).
 *
 * Two concerns live here:
 *
 *  1. Which of the BUILT-IN personal fields (cognome / telefono / indirizzo)
 *     are required at self-registration. Defaults preserve the historical
 *     behaviour (all required) so existing installs change nothing until the
 *     administrator opts out. Keys live in system_settings under the
 *     'registration' category next to require_admin_approval.
 *
 *  2. CUSTOM fields the administrator defines (e.g. a Telegram username for a
 *     community that notifies through it). Definitions live in
 *     `registrazione_campi`; per-user values in `utenti_campi_valori`
 *     (PK utente_id+campo_id, FK CASCADE both ways). Both tables ship in
 *     schema.sql for fresh installs and in migrate_0.7.37.sql for upgrades;
 *     every reader here degrades gracefully when the tables are missing
 *     (pre-migration window), mirroring the consent_log pattern.
 */
final class RegistrationFields
{
    /** Built-in optional-able fields => settings key. */
    public const BUILTIN_TOGGLES = [
        'cognome'   => 'require_cognome',
        'telefono'  => 'require_telefono',
        'indirizzo' => 'require_indirizzo',
    ];

    public const TYPES = ['text', 'textarea', 'email', 'url', 'number', 'checkbox'];

    private const MAX_VALUE_LENGTH = 1000;

    /** Whether a built-in field is required at registration (default: yes). */
    public static function isRequired(string $field): bool
    {
        $key = self::BUILTIN_TOGGLES[$field] ?? null;
        if ($key === null) {
            return true;
        }
        return (bool) ConfigStore::get('registration.' . $key, true);
    }

    /**
     * Custom field definitions, ordered. Empty when the table is absent.
     *
     * @return list<array{id:int, etichetta:string, tipo:string, obbligatorio:bool, attivo:bool, ordine:int}>
     */
    public static function definitions(mysqli $db, bool $onlyActive = true): array
    {
        if (!self::tableExists($db, 'registrazione_campi')) {
            return [];
        }
        $where = $onlyActive ? 'WHERE attivo = 1' : '';
        $res = $db->query(
            "SELECT id, etichetta, tipo, obbligatorio, attivo, ordine
               FROM registrazione_campi {$where}
              ORDER BY ordine, id"
        );
        $rows = [];
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = [
                    'id'           => (int) $row['id'],
                    'etichetta'    => (string) $row['etichetta'],
                    'tipo'         => (string) $row['tipo'],
                    'obbligatorio' => (bool) $row['obbligatorio'],
                    'attivo'       => (bool) $row['attivo'],
                    'ordine'       => (int) $row['ordine'],
                ];
            }
        }
        return $rows;
    }

    /**
     * Validate the submitted values for the given definitions.
     *
     * @param list<array{id:int, etichetta:string, tipo:string, obbligatorio:bool}> $definitions
     * @param array<string,mixed> $post  raw request body (custom_field[<id>] keys)
     * @param bool $enforceRequired  when false (profile self-edit), a blank
     *        required field is accepted rather than rejected — a custom field
     *        marked obbligatorio AFTER signup must not wall an existing user
     *        (who never saw it) out of unrelated profile edits. Format checks
     *        still apply to any value the user actually typed. Registration
     *        passes true so the signup contract stays enforced.
     * @return array{values: array<int,string>, error: ?string, error_reason: ?string}
     *         values maps field id => normalised value ('' allowed for optional
     *         — or, when $enforceRequired is false, any — fields; checkboxes
     *         normalise to '1'/''). error is the offending field's label (null
     *         when valid); error_reason is 'missing' or 'format' so callers can
     *         show a message that names the actual problem.
     */
    public static function validate(array $definitions, array $post, bool $enforceRequired = true): array
    {
        $submitted = $post['custom_field'] ?? [];
        if (!is_array($submitted)) {
            $submitted = [];
        }

        $fail = static fn (string $label, string $reason): array =>
            ['values' => [], 'error' => $label, 'error_reason' => $reason];

        $values = [];
        foreach ($definitions as $def) {
            $required = $def['obbligatorio'] && $enforceRequired;
            // Reject non-scalar payloads (custom_field[id][]=x) BEFORE casting:
            // (string) on an array would coerce to "Array", passing required
            // checks and persisting garbage / ticking checkboxes.
            $submittedValue = $submitted[$def['id']] ?? null;
            if ($submittedValue !== null && !is_scalar($submittedValue)) {
                return $fail($def['etichetta'], 'format');
            }
            $raw = trim((string) ($submittedValue ?? ''));

            if ($def['tipo'] === 'checkbox') {
                $values[$def['id']] = $raw !== '' ? '1' : '';
                if ($required && $values[$def['id']] === '') {
                    return $fail($def['etichetta'], 'missing');
                }
                continue;
            }

            if ($raw === '') {
                if ($required) {
                    return $fail($def['etichetta'], 'missing');
                }
                $values[$def['id']] = '';
                continue;
            }

            if (mb_strlen($raw) > self::MAX_VALUE_LENGTH) {
                return $fail($def['etichetta'], 'format');
            }

            $ok = match ($def['tipo']) {
                'email'  => filter_var($raw, FILTER_VALIDATE_EMAIL) !== false,
                'url'    => filter_var($raw, FILTER_VALIDATE_URL) !== false
                            && preg_match('#^https?://#i', $raw) === 1,
                'number' => is_numeric($raw),
                default  => true, // text / textarea: length-capped free text
            };
            if (!$ok) {
                return $fail($def['etichetta'], 'format');
            }
            $values[$def['id']] = $raw;
        }

        return ['values' => $values, 'error' => null, 'error_reason' => null];
    }

    /**
     * Persist the validated values for a user. Empty values delete the row so
     * clearing a field in the profile really clears it.
     *
     * @param array<int,string> $values field id => value
     */
    public static function saveValues(mysqli $db, int $userId, array $values): void
    {
        if ($userId <= 0 || $values === [] || !self::tableExists($db, 'utenti_campi_valori')) {
            return;
        }
        $upsert = $db->prepare(
            'INSERT INTO utenti_campi_valori (utente_id, campo_id, valore)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valore = VALUES(valore)'
        );
        $delete = $db->prepare(
            'DELETE FROM utenti_campi_valori WHERE utente_id = ? AND campo_id = ?'
        );
        if ($upsert === false || $delete === false) {
            throw new \RuntimeException('Unable to prepare custom field persistence');
        }
        foreach ($values as $fieldId => $value) {
            $fieldId = (int) $fieldId;
            if ($value === '') {
                $delete->bind_param('ii', $userId, $fieldId);
                if (!$delete->execute()) {
                    throw new \RuntimeException('Unable to clear custom field value: ' . $delete->error);
                }
                continue;
            }
            $upsert->bind_param('iis', $userId, $fieldId, $value);
            if (!$upsert->execute()) {
                throw new \RuntimeException('Unable to save custom field value: ' . $upsert->error);
            }
        }
        $upsert->close();
        $delete->close();
    }

    /**
     * Values for one user keyed by field id. Empty when tables are missing.
     *
     * @return array<int,string>
     */
    public static function valuesForUser(mysqli $db, int $userId): array
    {
        if ($userId <= 0 || !self::tableExists($db, 'utenti_campi_valori')) {
            return [];
        }
        $stmt = $db->prepare('SELECT campo_id, valore FROM utenti_campi_valori WHERE utente_id = ?');
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $values = [];
        while ($row = $res->fetch_assoc()) {
            $values[(int) $row['campo_id']] = (string) $row['valore'];
        }
        $stmt->close();
        return $values;
    }

    /**
     * Labelled values for one user (for display surfaces and API payloads).
     *
     * @return list<array{id:int, etichetta:string, tipo:string, valore:string}>
     */
    public static function labelledValuesForUser(mysqli $db, int $userId): array
    {
        $values = self::valuesForUser($db, $userId);
        if ($values === []) {
            return [];
        }
        $out = [];
        foreach (self::definitions($db, false) as $def) {
            if (isset($values[$def['id']]) && $values[$def['id']] !== '') {
                $out[] = [
                    'id'        => $def['id'],
                    'etichetta' => $def['etichetta'],
                    'tipo'      => $def['tipo'],
                    'valore'    => $values[$def['id']],
                ];
            }
        }
        return $out;
    }

    /**
     * Active definitions exposed to public clients (registration discovery).
     *
     * @return list<array{id:int,label:string,type:string,required:bool}>
     */
    public static function apiDefinitions(mysqli $db): array
    {
        $out = [];
        foreach (self::definitions($db) as $def) {
            $out[] = [
                'id'       => $def['id'],
                'label'    => $def['etichetta'],
                'type'     => $def['tipo'],
                'required' => $def['obbligatorio'],
            ];
        }
        return $out;
    }

    /**
     * Active editable fields plus this user's current values (mobile/profile API).
     *
     * @return list<array{id:int,label:string,type:string,required:bool,value:string}>
     */
    public static function editableFieldsForUser(mysqli $db, int $userId): array
    {
        $values = self::valuesForUser($db, $userId);
        $out = [];
        foreach (self::definitions($db) as $def) {
            $out[] = [
                'id'       => $def['id'],
                'label'    => $def['etichetta'],
                'type'     => $def['tipo'],
                'required' => $def['obbligatorio'],
                'value'    => $values[$def['id']] ?? '',
            ];
        }
        return $out;
    }

    /**
     * Whether changing/deleting a definition would affect stored user data.
     */
    public static function hasStoredValues(mysqli $db, int $fieldId): bool
    {
        if ($fieldId <= 0 || !self::tableExists($db, 'utenti_campi_valori')) {
            return false;
        }
        $stmt = $db->prepare(
            'SELECT 1 FROM utenti_campi_valori WHERE campo_id = ? LIMIT 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Unable to inspect stored custom field values');
        }
        $stmt->bind_param('i', $fieldId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $exists;
    }

    private static function tableExists(mysqli $db, string $table): bool
    {
        try {
            $stmt = $db->prepare(
                'SELECT 1 FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            return $exists;
        } catch (\Throwable) {
            return false;
        }
    }
}
