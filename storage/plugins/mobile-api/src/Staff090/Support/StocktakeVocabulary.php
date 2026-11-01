<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

/**
 * The English codes the Mobile API uses for the stocktake vocabulary, mapped
 * to and from the values StocktakeService stores (Italian), plus the colour
 * and the tone the web scan page gives each outcome, so the app can give the
 * same feedback. Pure: no database, no session.
 */
final class StocktakeVocabulary
{
    /** Scan outcome / report category: service value => API code. */
    public const OUTCOMES = [
        'trovata'      => 'found',
        'fuori_posto'  => 'misplaced',
        'in_prestito'  => 'on_loan',
        'mancante'     => 'missing',
        'sconosciuta'  => 'unknown',
        'fuori_ambito' => 'out_of_scope',
        'duplicata'    => 'duplicate',
    ];

    /** Session state: service value => API code. */
    public const STATUSES = [
        'aperta'    => 'open',
        'chiusa'    => 'closed',
        'annullata' => 'cancelled',
    ];

    /** Close action for the missing copies: service value => API code. */
    public const MISSING_ACTIONS = [
        'nessuna' => 'none',
        'perso'   => 'lost',
    ];

    /**
     * Colour family of each outcome, the one of the web badges
     * (app/Views/inventario/show.php: green, amber, blue, red, purple, gray).
     */
    public const OUTCOME_COLORS = [
        'trovata'      => 'green',
        'fuori_posto'  => 'amber',
        'in_prestito'  => 'blue',
        'mancante'     => 'red',
        'sconosciuta'  => 'red',
        'fuori_ambito' => 'purple',
        'duplicata'    => 'gray',
    ];

    /** Badge colour (Tailwind 800 text on a 100 background), as hex for the app. */
    public const COLOR_HEX = [
        'green'  => ['foreground' => '#166534', 'background' => '#dcfce7'],
        'amber'  => ['foreground' => '#92400e', 'background' => '#fef3c7'],
        'blue'   => ['foreground' => '#1e40af', 'background' => '#dbeafe'],
        'red'    => ['foreground' => '#991b1b', 'background' => '#fee2e2'],
        'purple' => ['foreground' => '#6b21a8', 'background' => '#f3e8ff'],
        'gray'   => ['foreground' => '#1f2937', 'background' => '#f3f4f6'],
    ];

    public const STATUS_COLORS = [
        'aperta'    => 'green',
        'chiusa'    => 'gray',
        'annullata' => 'red',
    ];

    /**
     * The beep the web scan page plays for each outcome: [frequency Hz, duration ms].
     * A missing copy is never the answer to a scan, so it has no tone.
     */
    public const TONES = [
        'trovata'      => [880, 120],
        'fuori_posto'  => [520, 250],
        'in_prestito'  => [660, 250],
        'fuori_ambito' => [440, 300],
        'duplicata'    => [330, 200],
        'sconosciuta'  => [220, 450],
    ];

    /** Summary counter: service key => API key. */
    public const SUMMARY_KEYS = [
        'attese'       => 'expected',
        'trovate'      => 'found',
        'fuori_posto'  => 'misplaced',
        'in_prestito'  => 'on_loan',
        'mancanti'     => 'missing',
        'perse'        => 'lost',
        'sconosciute'  => 'unknown',
        'fuori_ambito' => 'out_of_scope',
        'duplicate'    => 'duplicates',
        'scansioni'    => 'scans',
        'mie'          => 'mine',
    ];

    /** Session filter: API key => service key. */
    public const FILTER_KEYS = [
        'shelf_id'         => 'scaffale_id',
        'level_id'         => 'mensola_id',
        'genre_id'         => 'genere_id',
        'media_type'       => 'tipo_media',
        'collocation_from' => 'collocazione_da',
        'collocation_to'   => 'collocazione_a',
    ];

    private function __construct()
    {
    }

    public static function outcome(string $value): string
    {
        return self::OUTCOMES[$value] ?? $value;
    }

    /** API outcome code => service value, null when unknown. */
    public static function outcomeValue(string $code): ?string
    {
        $flip = array_flip(self::OUTCOMES);

        return $flip[$code] ?? null;
    }

    public static function status(string $value): string
    {
        return self::STATUSES[$value] ?? $value;
    }

    /** API status code => service value, null when unknown. */
    public static function statusValue(string $code): ?string
    {
        $flip = array_flip(self::STATUSES);

        return $flip[$code] ?? null;
    }

    public static function missingAction(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::MISSING_ACTIONS[$value] ?? $value;
    }

    /** API missing action => service value, null when unknown. */
    public static function missingActionValue(string $code): ?string
    {
        $flip = array_flip(self::MISSING_ACTIONS);

        return $flip[$code] ?? null;
    }

    /**
     * Colour of an outcome: family name plus hex pair.
     *
     * @return array{name:string,foreground:string,background:string}
     */
    public static function outcomeColor(string $value): array
    {
        $name = self::OUTCOME_COLORS[$value] ?? 'gray';

        return ['name' => $name] + self::COLOR_HEX[$name];
    }

    /** @return array{name:string,foreground:string,background:string} */
    public static function statusColor(string $value): array
    {
        $name = self::STATUS_COLORS[$value] ?? 'gray';

        return ['name' => $name] + self::COLOR_HEX[$name];
    }

    /** @return array{frequency_hz:int,duration_ms:int}|null */
    public static function tone(string $value): ?array
    {
        $tone = self::TONES[$value] ?? null;

        return $tone === null ? null : ['frequency_hz' => $tone[0], 'duration_ms' => $tone[1]];
    }

    /**
     * StocktakeService::summary() with API keys (every counter, 0 when absent).
     *
     * @param array<string, int> $summary
     * @return array<string, int>
     */
    public static function summary(array $summary): array
    {
        $out = [];
        foreach (self::SUMMARY_KEYS as $from => $to) {
            $out[$to] = (int) ($summary[$from] ?? 0);
        }

        return $out;
    }

    /**
     * The stored filter (service keys) with API keys; absent keys are null.
     *
     * @param array<string, mixed> $filter
     * @return array<string, int|string|null>
     */
    public static function filter(array $filter): array
    {
        $out = [];
        foreach (self::FILTER_KEYS as $api => $service) {
            $value = $filter[$service] ?? null;
            if ($value === null || $value === '') {
                $out[$api] = null;
            } elseif (in_array($api, ['shelf_id', 'level_id', 'genre_id'], true)) {
                $out[$api] = (int) $value;
            } else {
                $out[$api] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * An API request body's filter keys translated to the service keys the
     * web form posts (only the keys present in $body).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function filterInput(array $body): array
    {
        $out = [];
        foreach (self::FILTER_KEYS as $api => $service) {
            if (array_key_exists($api, $body)) {
                $out[$service] = $body[$api];
            }
        }

        return $out;
    }

    /**
     * A database DATETIME (library time zone, as the connection is set) as
     * ISO 8601 with offset; null for an empty value.
     */
    public static function datetime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d\TH:i:sP');
        } catch (\Throwable) {
            return null;
        }
    }
}
