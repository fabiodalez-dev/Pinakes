<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Localized human labels for libri_autori.ruolo values (issue #237). One place so
 * the book form, admin detail and public detail all name a role identically and
 * translate it via the active locale.
 */
final class ContributorRoles
{
    public static function label(string $ruolo): string
    {
        switch ($ruolo) {
            case 'principale':   return __('Autore');
            case 'co-autore':    return __('Co-autore');
            case 'traduttore':   return __('Traduttore');
            case 'illustratore': return __('Illustratore');
            case 'curatore':     return __('Curatore');
            case 'colorista':    return __('Colorista');
            default:             return ucfirst($ruolo);
        }
    }
}
