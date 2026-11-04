<?php
declare(strict_types=1);

namespace App\Support;

/**
 * RDA content / media / carrier types for a catalogue record.
 *
 * The three RDA categories are derived from `libri.tipo_media` (a book is
 * text / unmediated / volume, a DVD is two-dimensional moving image / video /
 * videodisc, …). A cataloguer can override any of them per record through the
 * nullable `libri.rda_content_type`, `rda_media_type` and `rda_carrier_type`
 * columns; NULL means "use the derived value".
 *
 * Codes are the Library of Congress RDA vocabulary codes, so they map 1:1 to
 * the id.loc.gov URIs the BIBFRAME plugin emits and to the MARC 21 fields
 * 336 (content), 337 (media) and 338 (carrier): codes() is the triple
 * BookMarcBuilder (MARC export, #505) writes in $b of those fields, with
 * "rdacontent" / "rdamedia" / "rdacarrier" in $2.
 */
final class RdaTypes
{
    public const KIND_CONTENT = 'content';
    public const KIND_MEDIA = 'media';
    public const KIND_CARRIER = 'carrier';

    /** Column holding the override for each kind. */
    public const COLUMNS = [
        self::KIND_CONTENT => 'rda_content_type',
        self::KIND_MEDIA => 'rda_media_type',
        self::KIND_CARRIER => 'rda_carrier_type',
    ];

    /** id.loc.gov vocabulary base for each kind. */
    private const URI_BASE = [
        self::KIND_CONTENT => 'http://id.loc.gov/vocabulary/contentTypes/',
        self::KIND_MEDIA => 'http://id.loc.gov/vocabulary/mediaTypes/',
        self::KIND_CARRIER => 'http://id.loc.gov/vocabulary/carriers/',
    ];

    /**
     * code => untranslated Italian label (pass through __() when rendering).
     *
     * @var array<string, array<string, string>>
     */
    private const VOCABULARY = [
        self::KIND_CONTENT => [
            'txt' => 'Testo',
            'spw' => 'Parola parlata',
            'prm' => 'Musica eseguita',
            'ntm' => 'Musica notata',
            'snd' => 'Suoni',
            'sti' => 'Immagine fissa',
            'tdi' => 'Immagine in movimento bidimensionale',
            'cri' => 'Immagine cartografica',
            'cop' => 'Programma per computer',
            'xxx' => 'Altro',
        ],
        self::KIND_MEDIA => [
            'n' => 'Senza mediazione',
            's' => 'Audio',
            'v' => 'Video',
            'c' => 'Computer',
            'h' => 'Microforma',
            'g' => 'Proiettato',
            'x' => 'Altro',
        ],
        self::KIND_CARRIER => [
            'nc' => 'Volume',
            'no' => 'Scheda',
            'nb' => 'Foglio',
            'sd' => 'Disco sonoro',
            'ss' => 'Audiocassetta',
            'vd' => 'Videodisco',
            'cd' => 'Disco per computer',
            'cr' => 'Risorsa online',
            'zu' => 'Non specificato',
        ],
    ];

    /**
     * Derived triple for each tipo_media.
     *
     * @var array<string, array{content: string, media: string, carrier: string}>
     */
    private const DERIVED = [
        'libro' => ['content' => 'txt', 'media' => 'n', 'carrier' => 'nc'],
        'disco' => ['content' => 'prm', 'media' => 's', 'carrier' => 'sd'],
        'audiolibro' => ['content' => 'spw', 'media' => 's', 'carrier' => 'sd'],
        'dvd' => ['content' => 'tdi', 'media' => 'v', 'carrier' => 'vd'],
        'altro' => ['content' => 'xxx', 'media' => 'x', 'carrier' => 'zu'],
    ];

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::KIND_CONTENT, self::KIND_MEDIA, self::KIND_CARRIER];
    }

    /**
     * Code => untranslated label for one kind.
     *
     * @return array<string, string>
     */
    public static function options(string $kind): array
    {
        return self::VOCABULARY[$kind] ?? [];
    }

    /** Untranslated label of a code, or the code itself when unknown. */
    public static function label(string $kind, string $code): string
    {
        return self::VOCABULARY[$kind][$code] ?? $code;
    }

    /**
     * The triple derived from tipo_media (unknown/empty values derive as a book,
     * the same default the catalogue applies everywhere else).
     *
     * @return array{content: string, media: string, carrier: string}
     */
    public static function derive(?string $tipoMedia): array
    {
        $key = MediaLabels::normalizeTipoMedia($tipoMedia) ?? 'libro';
        return self::DERIVED[$key] ?? self::DERIVED['libro'];
    }

    /**
     * A submitted override, validated against the vocabulary. Anything that
     * is not a known code (including '' from the "derived" option) is NULL.
     */
    public static function normalize(string $kind, mixed $code): ?string
    {
        if (!is_string($code)) {
            return null;
        }
        $code = strtolower(trim($code));
        return isset(self::VOCABULARY[$kind][$code]) ? $code : null;
    }

    /**
     * Overrides explicitly stored on the record (kind => code), only the ones set.
     *
     * @param array<string, mixed> $book
     * @return array<string, string>
     */
    public static function overrides(array $book): array
    {
        $out = [];
        // RDA types module off (Settings → Modules): the stored overrides are
        // kept but not applied, so every export derives from tipo_media as
        // before 0.9.0.
        if (!Features::enabled('rda_types')) {
            return $out;
        }
        foreach (self::COLUMNS as $kind => $column) {
            $code = self::normalize($kind, $book[$column] ?? null);
            if ($code !== null) {
                $out[$kind] = $code;
            }
        }
        return $out;
    }

    /**
     * Effective triple: override when set, derived value otherwise.
     *
     * @param array<string, mixed> $book
     * @return array{content: string, media: string, carrier: string}
     */
    public static function effective(array $book): array
    {
        $derived = self::derive(isset($book['tipo_media']) ? (string) $book['tipo_media'] : null);
        foreach (self::overrides($book) as $kind => $code) {
            $derived[$kind] = $code;
        }
        return $derived;
    }

    /**
     * The effective codes under the names the exporters use: the same triple
     * as effective(), kept as a stable entry point for BookMarcBuilder
     * (336/337/338) and the BIBFRAME plugin.
     *
     * @param array<string, mixed> $book
     * @return array{content: string, media: string, carrier: string}
     */
    public static function codes(array $book): array
    {
        return self::effective($book);
    }

    /** id.loc.gov URI of a code. */
    public static function uri(string $kind, string $code): string
    {
        return (self::URI_BASE[$kind] ?? '') . rawurlencode($code);
    }
}
