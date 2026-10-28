<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\SettingsRepository;

/**
 * Settings of the printable library card (Settings → Tessere).
 *
 * Stored in system_settings under the `cards` category and read straight from
 * the repository (no ConfigStore mapping), with defaults that reproduce a plain
 * card when nothing was ever saved: classic layout, Code128, photo, logo and
 * expiry on, no back side.
 */
final class PatronCardSettings
{
    public const CATEGORY = 'cards';
    public const LAYOUTS = ['classic', 'minimal', 'portrait'];
    public const CODE_TYPES = ['code128', 'qr'];
    public const FORMATS = ['single', 'sheet'];
    public const TITLE_MAX = 60;
    public const BACK_TEXT_MAX = 600;

    /**
     * @return array{layout:string, code_type:string, format:string, title:string, back_text:string, show_photo:bool, show_logo:bool, show_expiry:bool}
     */
    public static function defaults(): array
    {
        return [
            'layout' => 'classic',
            'code_type' => 'code128',
            'format' => 'single',
            'title' => '',
            'back_text' => '',
            'show_photo' => true,
            'show_logo' => true,
            'show_expiry' => true,
        ];
    }

    /**
     * @return array{layout:string, code_type:string, format:string, title:string, back_text:string, show_photo:bool, show_logo:bool, show_expiry:bool}
     */
    public static function resolve(SettingsRepository $repository): array
    {
        $d = self::defaults();
        $raw = [
            'card_layout' => (string) $repository->get(self::CATEGORY, 'layout', $d['layout']),
            'card_code_type' => (string) $repository->get(self::CATEGORY, 'code_type', $d['code_type']),
            'card_format' => (string) $repository->get(self::CATEGORY, 'format', $d['format']),
            'card_title' => (string) $repository->get(self::CATEGORY, 'title', $d['title']),
            'card_back_text' => (string) $repository->get(self::CATEGORY, 'back_text', $d['back_text']),
        ];
        foreach (['show_photo', 'show_logo', 'show_expiry'] as $flag) {
            if ($repository->get(self::CATEGORY, $flag, $d[$flag] ? '1' : '0') === '1') {
                $raw['card_' . $flag] = '1';
            }
        }
        return self::sanitize($raw);
    }

    /**
     * Normalise a submitted settings form (or stored values) into the settings
     * array. Unknown enum values fall back to the default; text is trimmed,
     * stripped of tags and length-capped; an absent checkbox means "off".
     *
     * @param array<string,mixed> $post
     * @return array{layout:string, code_type:string, format:string, title:string, back_text:string, show_photo:bool, show_logo:bool, show_expiry:bool}
     */
    public static function sanitize(array $post): array
    {
        $d = self::defaults();
        $pick = static function (mixed $value, array $allowed, string $fallback): string {
            $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';
            return in_array($value, $allowed, true) ? $value : $fallback;
        };
        $text = static function (mixed $value, int $max, bool $multiline): string {
            $value = is_scalar($value) ? (string) $value : '';
            $value = strip_tags(str_replace("\r\n", "\n", $value));
            $value = $multiline
                ? (string) preg_replace("/\n{3,}/", "\n\n", $value)
                : (string) preg_replace('/\s+/u', ' ', $value);
            $value = trim($value);
            return mb_substr($value, 0, $max, 'UTF-8');
        };
        return [
            'layout' => $pick($post['card_layout'] ?? null, self::LAYOUTS, $d['layout']),
            'code_type' => $pick($post['card_code_type'] ?? null, self::CODE_TYPES, $d['code_type']),
            'format' => $pick($post['card_format'] ?? null, self::FORMATS, $d['format']),
            'title' => $text($post['card_title'] ?? '', self::TITLE_MAX, false),
            'back_text' => $text($post['card_back_text'] ?? '', self::BACK_TEXT_MAX, true),
            'show_photo' => !empty($post['card_show_photo']),
            'show_logo' => !empty($post['card_show_logo']),
            'show_expiry' => !empty($post['card_show_expiry']),
        ];
    }

    /**
     * @param array{layout:string, code_type:string, format:string, title:string, back_text:string, show_photo:bool, show_logo:bool, show_expiry:bool} $settings
     */
    public static function save(SettingsRepository $repository, array $settings): void
    {
        foreach (['layout', 'code_type', 'format', 'title', 'back_text'] as $key) {
            $repository->set(self::CATEGORY, $key, $settings[$key]);
        }
        foreach (['show_photo', 'show_logo', 'show_expiry'] as $flag) {
            $repository->set(self::CATEGORY, $flag, $settings[$flag] ? '1' : '0');
        }
    }
}
