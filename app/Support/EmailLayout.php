<?php
declare(strict_types=1);

namespace App\Support;

/**
 * One email-safe visual system for core, plugin and operator-edited messages.
 *
 * Email markup deliberately uses tables and inline critical styles: unlike the
 * frontend it cannot depend on Tailwind, theme variables, web fonts or client
 * JavaScript. Template content and placeholders remain owned by the existing
 * settings system; this class only supplies presentation at send time.
 */
final class EmailLayout
{
    private const ACCENT = '#d70262';
    private const ACCENT_DARK = '#a8004b';
    private const INK = '#111827';
    private const TEXT = '#374151';
    private const MUTED = '#6b7280';
    private const CANVAS = '#f4f3f5';
    private const SURFACE = '#ffffff';
    private const SOFT = '#f7f6f7';
    private const DIVIDER = '#e5e7eb';

    public static function render(string $content, string $subject, ?string $locale = null): string
    {
        if (str_contains($content, 'data-pinakes-email="1"')) {
            return $content;
        }

        $locale ??= I18n::getLocale();
        $lang = htmlspecialchars(substr($locale, 0, 2), ENT_QUOTES, 'UTF-8');
        $appName = (string) ConfigStore::get('app.name', 'Biblioteca');
        $safeAppName = htmlspecialchars($appName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $preheader = htmlspecialchars(self::excerpt($subject), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = self::normalizeContent($content);
        $hasLeadingTitle = preg_match('/^\s*<h[1-2](?:\s[^>]*)?>/i', $content) === 1;
        $titleBlock = $hasLeadingTitle
            ? ''
            : '<div class="email-title" style="margin:0 0 26px;color:' . self::INK . ';font-size:28px;line-height:34px;font-weight:700;letter-spacing:-0.025em;">' . $safeSubject . '</div>';

        $previousLocale = I18n::getLocale();
        I18n::setLocale($locale);
        $automatic = __('Questa email è stata generata automaticamente da %s.', $appName);
        $support = __('Per assistenza, contatta l\'amministrazione della biblioteca.');
        I18n::setLocale($previousLocale);

        $logo = Branding::logo();
        if ($logo !== '') {
            $logoUrl = htmlspecialchars(absoluteUrl($logo), ENT_QUOTES, 'UTF-8');
            $brand = '<img src="' . $logoUrl . '" height="40" alt="' . $safeAppName . '" '
                . 'style="display:block;max-width:180px;max-height:40px;width:auto;height:auto;border:0;outline:none;text-decoration:none;">';
        } else {
            $brand = '<span style="font-size:18px;line-height:24px;font-weight:700;letter-spacing:-0.01em;color:' . self::INK . ';">'
                . $safeAppName . '</span>';
        }

        return '<!doctype html>'
            . '<html lang="' . $lang . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
            . '<title>' . $safeSubject . '</title>'
            . '<style>'
            . 'body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}'
            . 'table,td{mso-table-lspace:0;mso-table-rspace:0;}table{border-collapse:collapse!important;}'
            . 'img{-ms-interpolation-mode:bicubic;}'
            . '.email-content h1,.email-content h2,.email-content h3{color:' . self::INK . '!important;font-family:Arial,Helvetica,sans-serif;margin:0 0 16px;letter-spacing:-.02em;}'
            . '.email-content h1{font-size:26px;line-height:32px}.email-content h2{font-size:22px;line-height:28px}.email-content h3{font-size:18px;line-height:24px}'
            . '.email-content p{margin:0 0 16px}.email-content ul,.email-content ol{margin:0 0 20px;padding-left:24px}'
            . '.email-content li{margin:0 0 8px}.email-content a{color:' . self::ACCENT_DARK . ';}'
            . '.email-content hr{border:0;border-top:1px solid ' . self::DIVIDER . ';margin:24px 0}'
            . '.email-content img{max-width:100%;height:auto}.email-content code{word-break:break-word}'
            . '@media screen and (max-width:640px){.email-shell{width:100%!important}.email-gutter{padding-left:16px!important;padding-right:16px!important}.email-card{padding:28px 22px!important}.email-title{font-size:25px!important;line-height:31px!important}.email-content a[data-email-button="1"]{display:block!important;text-align:center!important;margin-left:0!important;margin-right:0!important}}'
            . '</style></head>'
            . '<body data-pinakes-email="1" style="margin:0!important;padding:0!important;background:' . self::CANVAS . ';color:' . self::TEXT . ';font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">' . $preheader . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background:' . self::CANVAS . ';">'
            . '<tr><td class="email-gutter" align="center" style="padding:32px 20px;">'
            . '<table role="presentation" class="email-shell" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">'
            . '<tr><td style="padding:0 4px 20px;">'
            . '<table role="presentation" width="100%"><tr><td align="left">' . $brand . '</td>'
            . '<td align="right" width="32"><span style="display:inline-block;width:28px;height:4px;border-radius:4px;background:' . self::ACCENT . ';font-size:0;line-height:0;">&nbsp;</span></td></tr></table>'
            . '</td></tr>'
            . '<tr><td class="email-card" style="background:' . self::SURFACE . ';border-radius:14px;padding:38px 40px;border:1px solid ' . self::DIVIDER . ';">'
            . $titleBlock
            . '<div class="email-content" style="color:' . self::TEXT . ';font-size:16px;line-height:1.65;">' . $content . '</div>'
            . '</td></tr>'
            . '<tr><td align="center" style="padding:22px 24px 4px;color:' . self::MUTED . ';font-size:12px;line-height:18px;">'
            . '<p style="margin:0 0 4px;">' . htmlspecialchars($automatic, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>'
            . '<p style="margin:0;">' . htmlspecialchars($support, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** Produce a readable multipart/alternative fallback without CSS noise. */
    public static function plainText(string $html): string
    {
        $html = preg_replace('/<(br|\/p|\/div|\/h[1-6]|\/li|\/tr)>/i', "$0\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * Re-style legacy/operator HTML at runtime. This is intentionally narrow:
     * it changes presentation declarations only and never text, hrefs or tokens.
     */
    public static function normalizeContent(string $content): string
    {
        // Templates are fragments. Remove accidental document wrappers saved by
        // rich-text editors so they cannot create nested HTML documents.
        $content = preg_replace('/<\/?(?:html|head|body)(?:\s[^>]*)?>/i', '', $content) ?? $content;
        $content = preg_replace('/<!doctype[^>]*>/i', '', $content) ?? $content;

        $content = preg_replace_callback('/<a\b([^>]*\bstyle=(?:"[^"]*"|\'[^\']*\')[^>]*)>/i', static function (array $match): string {
            $attrs = $match[1];
            if (!preg_match('/\bstyle=("|\')(.*?)\1/is', $attrs, $styleMatch)) {
                return $match[0];
            }
            $style = $styleMatch[2];
            if (!preg_match('/\bbackground(?:-color)?\s*:/i', $style)) {
                return $match[0];
            }
            $style = preg_replace('/\bbackground(?:-color)?\s*:[^;]+;?/i', '', $style) ?? $style;
            $style = preg_replace('/\bcolor\s*:[^;]+;?/i', '', $style) ?? $style;
            $style = preg_replace('/\bborder(?:-radius)?\s*:[^;]+;?/i', '', $style) ?? $style;
            $style = trim($style, " ;\t\n\r\0\x0B");
            if ($style !== '') {
                $style .= ';';
            }
            $style .= 'display:inline-block;background-color:' . self::ACCENT . ';color:#ffffff;border:1px solid ' . self::ACCENT . ';border-radius:8px;padding:12px 20px;text-decoration:none;font-weight:700;line-height:20px;margin:8px 8px 8px 0;';
            $attrs = str_replace($styleMatch[0], 'style=' . $styleMatch[1] . $style . $styleMatch[1], $attrs);
            if (!preg_match('/\bdata-email-button\s*=/i', $attrs)) {
                $attrs .= ' data-email-button="1"';
            }
            return '<a' . $attrs . '>';
        }, $content) ?? $content;

        $legacyBackgrounds = '#(?:fef3c7|ecfdf5|fef2f2|fff7ed|f0f9ff|dbeafe|eff6ff|f3f4f6|f8fafc)';
        $content = preg_replace('/background(?:-color)?\s*:\s*' . $legacyBackgrounds . '\s*;?/i', 'background-color:' . self::SOFT . ';', $content) ?? $content;
        $content = preg_replace('/border-left\s*:\s*\d+px\s+solid\s+#[0-9a-f]{3,6}\s*;?/i', 'border-left:3px solid ' . self::ACCENT . ';', $content) ?? $content;
        $content = preg_replace('/border\s*:\s*1px\s+solid\s+#[0-9a-f]{3,6}\s*;?/i', 'border:1px solid ' . self::DIVIDER . ';', $content) ?? $content;
        $content = preg_replace('/color\s*:\s*#(?:1e40af|1f2937|333333|333)\s*;?/i', 'color:' . self::INK . ';', $content) ?? $content;
        return $content;
    }

    private static function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? $text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 140);
        }
        return substr($text, 0, 140);
    }
}
