<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * A book's media type (`libri.tipo_media`: libro, disco, audiolibro, dvd,
 * altro) as MARC says it, and back:
 *
 *   leader/06 (MARC 21 and UNIMARC alike): a language material, i non-musical
 *     sound recording, j musical sound recording, g projected medium;
 *   007 physical description, category of material (MARC 21, where cheap);
 *   RDA content / media / carrier types (MARC 21 336 / 337 / 338 $b, with
 *     the English RDA term in $a and the vocabulary in $2).
 *
 * The RDA codes a media type implies are a fixed table; a row that names its
 * own (`rda_content_type`, `rda_media_type`, `rda_carrier_type`) overrides
 * them, and on import only a code that differs from what the media type
 * implies is kept as an override, so a plain book carries nothing extra.
 */
final class MarcMediaType
{
    public const DEFAULT = 'libro';

    /** tipo_media → [leader/06, 007 or null, RDA content, media, carrier]. */
    private const TYPES = [
        'libro'      => ['a', 'ta', 'txt', 'n', 'nc'],
        'disco'      => ['j', 'sd |||||||||||', 'prm', 's', 'sd'],
        'audiolibro' => ['i', 'sd |||||||||||', 'spw', 's', 'sd'],
        'dvd'        => ['g', 'vd |v||||', 'tdi', 'v', 'vd'],
        'altro'      => ['a', null, 'xxx', 'x', 'zu'],
    ];

    /** RDA content types (rdacontent): code → English term. */
    private const CONTENT_TERMS = [
        'txt' => 'text', 'prm' => 'performed music', 'spw' => 'spoken word', 'snd' => 'sounds',
        'tdi' => 'two-dimensional moving image', 'tdm' => 'three-dimensional moving image',
        'sti' => 'still image', 'tci' => 'tactile image', 'ntm' => 'notated music', 'tcm' => 'tactile notated music',
        'cri' => 'cartographic image', 'crm' => 'cartographic moving image', 'crd' => 'cartographic dataset',
        'crf' => 'cartographic three-dimensional form', 'crn' => 'cartographic tactile image',
        'cod' => 'computer dataset', 'cop' => 'computer program', 'tdf' => 'three-dimensional form',
        'tct' => 'tactile text', 'xxx' => 'other', 'zzz' => 'unspecified',
    ];

    /** RDA media types (rdamedia): code → English term. */
    private const MEDIA_TERMS = [
        'n' => 'unmediated', 's' => 'audio', 'v' => 'video', 'c' => 'computer', 'g' => 'projected',
        'h' => 'microform', 'e' => 'stereographic', 'p' => 'microscopic', 'x' => 'other', 'z' => 'unspecified',
    ];

    /** RDA carrier types (rdacarrier): code → English term. */
    private const CARRIER_TERMS = [
        'nc' => 'volume', 'nb' => 'sheet', 'na' => 'roll', 'no' => 'card', 'nn' => 'flipchart', 'nr' => 'object', 'nz' => 'other',
        'sd' => 'audio disc', 'ss' => 'audiocassette', 'st' => 'audiotape reel', 'sz' => 'other',
        'vd' => 'videodisc', 'vf' => 'videocassette', 'vr' => 'videotape reel', 'vz' => 'other',
        'cr' => 'online resource', 'cd' => 'computer disc', 'ck' => 'computer card', 'cz' => 'other',
        'zu' => 'unspecified',
    ];

    /** The media type as stored, defaulting to a book. */
    public static function normalise(?string $tipoMedia): string
    {
        $type = \App\Support\MediaLabels::normalizeTipoMedia($tipoMedia);
        return $type !== null && isset(self::TYPES[$type]) ? $type : self::DEFAULT;
    }

    /** Leader/06 (type of record) for a media type. */
    public static function leaderType(?string $tipoMedia): string
    {
        return self::TYPES[self::normalise($tipoMedia)][0];
    }

    /** MARC 21 007 for a media type, null when it has none worth claiming. */
    public static function field007(?string $tipoMedia): ?string
    {
        return self::TYPES[self::normalise($tipoMedia)][1];
    }

    /**
     * The RDA codes of a book: those its media type implies, each replaced
     * by the row's own (`rda_content_type`, `rda_media_type`,
     * `rda_carrier_type`) when present.
     *
     * @param array<string, mixed> $row a libri row
     * @return array{content: string, media: string, carrier: string}
     */
    public static function rdaCodes(array $row): array
    {
        [, , $content, $media, $carrier] = self::TYPES[self::normalise(isset($row['tipo_media']) ? (string) $row['tipo_media'] : null)];
        // RDA types module off: the stored overrides are not applied.
        $overridesOn = \App\Support\Features::enabled('rda_types');
        $own = static fn (string $key): string => $overridesOn ? strtolower(trim((string) ($row[$key] ?? ''))) : '';
        return [
            'content' => $own('rda_content_type') !== '' ? $own('rda_content_type') : $content,
            'media' => $own('rda_media_type') !== '' ? $own('rda_media_type') : $media,
            'carrier' => $own('rda_carrier_type') !== '' ? $own('rda_carrier_type') : $carrier,
        ];
    }

    /** The English RDA term of a code; null when the code is not known. */
    public static function term(string $kind, string $code): ?string
    {
        $code = strtolower(trim($code));
        return match ($kind) {
            'content' => self::CONTENT_TERMS[$code] ?? null,
            'media' => self::MEDIA_TERMS[$code] ?? null,
            'carrier' => self::CARRIER_TERMS[$code] ?? null,
            default => null,
        };
    }

    /**
     * The media type a record's leader/06 says, refined by its RDA content
     * type (a sound recording of performed music is a disco whatever the
     * leader says, of spoken word an audiolibro); '' when the leader names
     * a kind the catalogue has no media type for (a map, a computer file).
     */
    public static function tipoMediaOf(string $leaderType, ?string $contentCode = null): string
    {
        $content = strtolower(trim((string) $contentCode));
        return match (strtolower($leaderType)) {
            'a', 't' => self::DEFAULT,
            'g' => 'dvd',
            'i' => $content === 'prm' ? 'disco' : 'audiolibro',
            'j' => $content === 'spw' ? 'audiolibro' : 'disco',
            default => '',
        };
    }

    /**
     * The RDA codes a record carries that its media type does not imply,
     * keyed as the libri columns (`rda_content_type`, ...): what an import
     * stores as the book's own, so a plain book stores nothing.
     *
     * @return array<string, string>
     */
    public static function overrides(string $tipoMedia, ?string $contentCode, ?string $mediaCode, ?string $carrierCode): array
    {
        [, , $content, $media, $carrier] = self::TYPES[self::normalise($tipoMedia)];
        $out = [];
        foreach ([
            'rda_content_type' => [$contentCode, $content, 'content'],
            'rda_media_type' => [$mediaCode, $media, 'media'],
            'rda_carrier_type' => [$carrierCode, $carrier, 'carrier'],
        ] as $column => [$given, $implied, $kind]) {
            $code = strtolower(trim((string) $given));
            if ($code !== '' && $code !== $implied && self::term($kind, $code) !== null) {
                $out[$column] = $code;
            }
        }
        return $out;
    }
}
