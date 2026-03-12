<?php
declare(strict_types=1);

namespace App\Support;

class SharingProviders
{
    /**
     * Canonical registry of all supported sharing providers.
     *
     * @return array<string, array{name: string, icon: string, color: string, url: string, label: string}>
     */
    public static function all(): array
    {
        return [
            'facebook'  => ['name' => __('Facebook'),    'icon' => 'fab fa-facebook-f',   'color' => '#1877F2', 'url' => 'https://www.facebook.com/sharer/sharer.php?u={url}',                           'label' => __('Condividi su Facebook')],
            'x'         => ['name' => 'X',               'icon' => 'fab fa-x-twitter',    'color' => '#000000', 'url' => 'https://twitter.com/intent/tweet?text={title}&url={url}',                       'label' => __('Condividi su X')],
            'whatsapp'  => ['name' => 'WhatsApp',        'icon' => 'fab fa-whatsapp',     'color' => '#25D366', 'url' => 'https://wa.me/?text={title}%20{url}',                                           'label' => __('Condividi su WhatsApp')],
            'telegram'  => ['name' => 'Telegram',        'icon' => 'fab fa-telegram',     'color' => '#0088CC', 'url' => 'https://t.me/share/url?url={url}&text={title}',                                 'label' => __('Condividi su Telegram')],
            'linkedin'  => ['name' => 'LinkedIn',        'icon' => 'fab fa-linkedin-in',  'color' => '#0A66C2', 'url' => 'https://www.linkedin.com/sharing/share-offsite/?url={url}',                     'label' => __('Condividi su LinkedIn')],
            'reddit'    => ['name' => 'Reddit',          'icon' => 'fab fa-reddit-alien', 'color' => '#FF4500', 'url' => 'https://www.reddit.com/submit?url={url}&title={title}',                         'label' => __('Condividi su Reddit')],
            'pinterest' => ['name' => 'Pinterest',       'icon' => 'fab fa-pinterest-p',  'color' => '#E60023', 'url' => 'https://pinterest.com/pin/create/button/?url={url}&description={title}',        'label' => __('Condividi su Pinterest')],
            'threads'   => ['name' => 'Threads',         'icon' => 'fab fa-threads',      'color' => '#000000', 'url' => 'https://www.threads.com/intent/post?text={title}%20{url}',                      'label' => __('Condividi su Threads')],
            'bluesky'   => ['name' => 'Bluesky',         'icon' => 'fab fa-bluesky',      'color' => '#0085FF', 'url' => 'https://bsky.app/intent/compose?text={title}%20{url}',                          'label' => __('Condividi su Bluesky')],
            'tumblr'    => ['name' => 'Tumblr',          'icon' => 'fab fa-tumblr',       'color' => '#36465D', 'url' => 'https://www.tumblr.com/widgets/share/tool?canonicalUrl={url}&title={title}',     'label' => __('Condividi su Tumblr')],
            'pocket'    => ['name' => 'Pocket',          'icon' => 'fab fa-get-pocket',   'color' => '#EF4056', 'url' => 'https://getpocket.com/save?url={url}&title={title}',                            'label' => __('Salva su Pocket')],
            'vk'        => ['name' => 'VKontakte',       'icon' => 'fab fa-vk',           'color' => '#4680C2', 'url' => 'https://vk.com/share.php?url={url}&title={title}',                              'label' => __('Condividi su VK')],
            'line'      => ['name' => 'LINE',            'icon' => 'fab fa-line',         'color' => '#00C300', 'url' => 'https://social-plugins.line.me/lineit/share?url={url}',                         'label' => __('Condividi su LINE')],
            'sms'       => ['name' => 'SMS',             'icon' => 'fas fa-sms',          'color' => '#666666', 'url' => 'sms:?body={title}%20{url}',                                                     'label' => __('Invia via SMS')],
            'email'     => ['name' => 'Email',           'icon' => 'fas fa-envelope',     'color' => '#666666', 'url' => 'mailto:?subject={title}&body={url}',                                            'label' => __('Invia per email')],
            'copylink'  => ['name' => __('Copia link'),  'icon' => 'fas fa-link',         'color' => '#666666', 'url' => '',                                                                              'label' => __('Copia link')],
        ];
    }

    /**
     * @return string[]
     */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }
}
