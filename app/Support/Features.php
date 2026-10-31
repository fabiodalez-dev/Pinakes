<?php
declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Optional 0.9.0 modules, each with a `features.<key>` row in system_settings.
 *
 * DEFAULTS provides the static upgrade defaults. INHERITED controls instead
 * preserve their former parent when the row is absent. FRESH is what
 * a new installation starts with (installer/database/data_*.sql). Split controls
 * inherit their former parent until the migration or a settings save freezes
 * that choice; explicit OFF always wins. Both lists
 * are checked against the seeds, the migration and ConfigStore by
 * tests/feature-toggles-registry.unit.php.
 *
 * Circulation modules and their controls are also off in catalogue mode: that
 * switch stays the master switch of circulation.
 */
final class Features
{
    /** @var array<string, '0'|'1'> Static defaults; INHERITED controls preserve the former parent. */
    public const DEFAULTS = [
        'stocktake' => '0',
        'marc' => '1',
        'visitor_log' => '0',
        'catalogue_cards' => '1',
        'suppliers' => '0',
        'rda_types' => '0',
        'patron_cards' => '1',
        'user_import' => '1',
        'circulation_rules' => '1',
        'closures_calendar' => '1',
        'fines' => '0',
        'patron_photos' => '1',
        'consultation_only' => '1',
        'automatic_card_expiry' => '1',
        'automatic_fines' => '0',
        'debt_block' => '0',
        'loss_penalties' => '0',
        'attendance_retention' => '0',
    ];

    /** @var array<string, '0'|'1'> fresh-install default (data_*.sql seeds) */
    public const FRESH = [
        'stocktake' => '1',
        'marc' => '1',
        'visitor_log' => '0',
        'catalogue_cards' => '1',
        'suppliers' => '1',
        'rda_types' => '1',
        'patron_cards' => '1',
        'user_import' => '1',
        'circulation_rules' => '1',
        'closures_calendar' => '1',
        'fines' => '0',
        'patron_photos' => '1',
        'consultation_only' => '1',
        'automatic_card_expiry' => '1',
        'automatic_fines' => '0',
        'debt_block' => '0',
        'loss_penalties' => '0',
        'attendance_retention' => '0',
    ];

    /** Modules that belong to circulation: off whenever catalogue mode is on. */
    public const CIRCULATION = ['circulation_rules', 'closures_calendar', 'fines', 'visitor_log', 'consultation_only', 'automatic_card_expiry', 'automatic_fines', 'debt_block', 'loss_penalties'];

    /** Controls saved from Settings → Modules. */
    public const MODULES_TAB = ['stocktake', 'marc', 'catalogue_cards', 'suppliers', 'rda_types', 'patron_cards', 'user_import', 'visitor_log', 'patron_photos', 'attendance_retention'];

    /** Modules saved from Settings → Loans. */
    public const LOANS_TAB = ['circulation_rules', 'closures_calendar', 'fines', 'consultation_only', 'automatic_card_expiry', 'automatic_fines', 'debt_block', 'loss_penalties'];

    /** Missing granular choices reproduce the old parent setting until saved. */
    public const INHERITED = [
        'patron_photos' => 'patron_cards',
        'consultation_only' => 'circulation_rules',
        'automatic_card_expiry' => 'circulation_rules',
        'automatic_fines' => 'fines',
        'debt_block' => 'fines',
        'loss_penalties' => 'fines',
    ];

    /** Dependent automations require their parent module as well as their own switch. */
    private const PARENTS = ['automatic_fines' => 'fines', 'debt_block' => 'fines', 'loss_penalties' => 'fines', 'automatic_card_expiry' => 'circulation_rules'];

    /** @var array<string, bool> test seam: forced values, ahead of the stored setting */
    private static array $overrides = [];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * Whether $feature is on. An unknown key is a programming error, never a
     * module that is silently always on or always off.
     */
    public static function enabled(string $feature): bool
    {
        if (!array_key_exists($feature, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown feature: ' . $feature);
        }
        if (array_key_exists($feature, self::$overrides)) {
            return self::$overrides[$feature];
        }
        if (in_array($feature, self::CIRCULATION, true) && ConfigStore::isCatalogueMode()) {
            return false;
        }
        if (isset(self::PARENTS[$feature]) && !self::enabled(self::PARENTS[$feature])) {
            return false;
        }
        return self::stored($feature);
    }

    /** The saved switch, ignoring catalogue mode (what the settings form shows). */
    public static function stored(string $feature): bool
    {
        if (!array_key_exists($feature, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown feature: ' . $feature);
        }
        if (array_key_exists($feature, self::$overrides)) {
            return self::$overrides[$feature];
        }
        $value = ConfigStore::get('features.' . $feature, self::DEFAULTS[$feature] === '1');
        if ($value === null && isset(self::INHERITED[$feature])) {
            return self::stored(self::INHERITED[$feature]);
        }
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** Freeze inherited choices BEFORE changing their former parent (also for old forms). */
    public static function preserveInherited(\App\Models\SettingsRepository $repository): void
    {
        $values = self::snapshot();
        foreach (self::INHERITED as $key => $parent) {
            if ($repository->get('features', $key) === null) {
                $repository->set('features', $key, $values[$key] ? '1' : '0');
            }
        }
    }

    /**
     * The saved switches keyed by feature (Settings → Modules, Loans tab).
     *
     * @return array<string, bool>
     */
    public static function snapshot(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = self::stored($key);
        }
        return $out;
    }

    /**
     * The standard refusal of an admin page whose module is off: a 302 to the
     * dashboard with the message, or null when the module is on.
     */
    public static function requireOrRedirect(string $feature, Response $response): ?Response
    {
        if (self::enabled($feature)) {
            return null;
        }
        $_SESSION['error_message'] = __('Funzione disattivata nelle impostazioni.');
        return $response->withHeader('Location', url('/admin/dashboard'))->withStatus(302);
    }

    /** Label shown in the settings (Italian base string, translated by __()). */
    public static function label(string $feature): string
    {
        return match ($feature) {
            'stocktake' => __('Revisione inventariale'),
            'marc' => __('Import ed export MARC'),
            'catalogue_cards' => __('Schede catalografiche'),
            'suppliers' => __('Fornitori'),
            'rda_types' => __('Tipi RDA'),
            'patron_cards' => __('Stampa delle tessere'),
            'user_import' => __('Importazione utenti da CSV'),
            'visitor_log' => __('Registro presenze'),
            'circulation_rules' => __('Regole per tipologia di lettore'),
            'closures_calendar' => __('Calendario chiusure'),
            'fines' => __('Registro movimenti'),
            'patron_photos' => __('Foto dei lettori'),
            'consultation_only' => __('Copie in sola consultazione'),
            'automatic_card_expiry' => __('Validità automatica delle tessere'),
            'automatic_fines' => __('Multe automatiche per ritardo'),
            'debt_block' => __('Blocco dei prestiti per debito'),
            'loss_penalties' => __('Riporta penalità per perdita e danno nel registro'),
            'attendance_retention' => __('Conservazione a registro presenze sospeso'),
            default => throw new \InvalidArgumentException('Unknown feature: ' . $feature),
        };
    }

    /** Help text shown under the label. */
    public static function help(string $feature): string
    {
        return match ($feature) {
            'stocktake' => __('Sessioni di revisione con scansione delle copie, esiti e report. Spenta: voce di menu e pagine non disponibili, il ruolo "Revisore inventario" resta ma non apre nulla.'),
            'marc' => __('Importazione massiva di record MARC21/UNIMARC/danMARC2, esportazione del catalogo e catalogazione derivata via SRU.'),
            'catalogue_cards' => __('Stampa della scheda catalografica di un libro o di una selezione.'),
            'suppliers' => __('Anagrafica fornitori e campo "Fornitore" nella scheda libro e nell\'import CSV.'),
            'rda_types' => __('Campi tipo di contenuto, media e supporto RDA nella scheda libro. Spenti, i valori restano salvati e gli export li derivano dal tipo di materiale.'),
            'patron_cards' => __('Stampa delle tessere in PDF. Foto e validità automatica hanno controlli separati.'),
            'user_import' => __('Caricamento massivo dei lettori con invito via email.'),
            'visitor_log' => __('Registro degli ingressi in biblioteca con conservazione a tempo. Contiene dati personali: attivalo solo se la biblioteca lo usa davvero.'),
            'circulation_rules' => __('Tipologie di lettore, matrice delle regole per tipo di materiale, limiti alle prenotazioni e durata automatica della tessera. Spente, valgono solo le regole globali di questa scheda.'),
            'closures_calendar' => __('Giorni di chiusura, festività e import ICS: le scadenze saltano i giorni chiusi. Spento, il calendario non viene applicato.'),
            'fines' => __('Addebiti manuali, pagamenti, ricevute e condoni. Multe automatiche, penalità e blocco per debito hanno controlli separati. Spento, conserva i movimenti.'),
            'patron_photos' => __('Acquisizione e visualizzazione delle foto, indipendenti dalla stampa delle tessere. Spegnendo il controllo le foto restano conservate.'),
            'consultation_only' => __('Rispetta il vincolo di consultazione delle copie anche senza regole per tipologia, su banco, sito, app e integrazioni.'),
            'automatic_card_expiry' => __('Applica la durata della tipologia alle nuove iscrizioni e alle approvazioni. Non modifica le scadenze esistenti; il rinnovo manuale resta disponibile.'),
            'automatic_fines' => __('Richiede il registro attivo e una tariffa positiva. Spento, nessuna nuova multa per ritardo viene calcolata o addebitata; tariffe e movimenti restano salvati.'),
            'debt_block' => __('Richiede il registro attivo e una soglia positiva. Può bloccare i prestiti anche senza generare nuove multe automatiche.'),
            'loss_penalties' => __('Riporta gli importi già assegnati ai prestiti nel registro contabile, comprese le penalità precedenti. Non determina nuovi importi.'),
            'attendance_retention' => __('Continua la cancellazione secondo i giorni di conservazione anche quando il registro è spento o in modalità catalogo. Con 0 giorni non cancella nulla.'),
            default => throw new \InvalidArgumentException('Unknown feature: ' . $feature),
        };
    }

    /**
     * Force $feature on/off for the rest of the process (unit tests), or drop
     * the forced value with null.
     */
    public static function override(string $feature, ?bool $value): void
    {
        if (!array_key_exists($feature, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown feature: ' . $feature);
        }
        if ($value === null) {
            unset(self::$overrides[$feature]);
            return;
        }
        self::$overrides[$feature] = $value;
    }

    /** Force every module on (unit tests of the modules themselves). */
    public static function overrideAll(bool $value): void
    {
        foreach (self::keys() as $key) {
            self::$overrides[$key] = $value;
        }
    }

    public static function resetOverrides(): void
    {
        self::$overrides = [];
    }
}
