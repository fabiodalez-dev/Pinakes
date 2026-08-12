<?php
declare(strict_types=1);

/**
 * Badge canonico per prestiti.stato (#333) — UNICA fonte di verità per colore,
 * icona ed etichetta di ogni stato, condivisa da tutte le viste admin (sia il
 * rendering server-side sia le colonne DataTables via loan_status_badge_map()).
 * Un nuovo valore dell'enum si aggiunge SOLO qui.
 *
 * Vive in app/Views/partials (non in app/helpers.php) deliberatamente: i glob
 * `content` di Tailwind scansionano solo app/Views/** — spostare queste classi
 * fuori dalle viste le farebbe sparire dal CSS compilato.
 *
 * Le etichette vengono da translate_loan_status() (app/helpers.php), già usata
 * da PDF ed export CSV: testo e badge non possono divergere.
 */

if (!function_exists('loan_status_badge_map')) {
    /**
     * @return array<string, string> stato => badge HTML pronto da stampare
     */
    function loan_status_badge_map(): array
    {
        $base = 'inline-flex items-center px-3 py-1 rounded-full text-xs font-medium';
        // stato => [classi colore Tailwind, icona FontAwesome]
        $defs = [
            'pendente'    => ['bg-orange-100 text-orange-800', 'fa-hourglass-half'],
            'prenotato'   => ['bg-purple-100 text-purple-800', 'fa-calendar-check'],
            'da_ritirare' => ['bg-amber-100 text-amber-800', 'fa-box'],
            'in_corso'    => ['bg-blue-100 text-blue-800', 'fa-clock'],
            'in_ritardo'  => ['bg-yellow-100 text-yellow-800', 'fa-exclamation-triangle'],
            'restituito'  => ['bg-green-100 text-green-800', 'fa-check-circle'],
            'perso'       => ['bg-red-100 text-red-800', 'fa-times-circle'],
            'danneggiato' => ['bg-red-100 text-red-800', 'fa-times-circle'],
            'scaduto'     => ['bg-gray-200 text-gray-700', 'fa-calendar-times'],
            'annullato'   => ['bg-gray-200 text-gray-700', 'fa-ban'],
        ];
        $map = [];
        foreach ($defs as $stato => [$colors, $icon]) {
            $map[$stato] = '<span class="' . $base . ' ' . $colors . '"><i class="fas ' . $icon . ' mr-2"></i>'
                . htmlspecialchars(translate_loan_status($stato), ENT_QUOTES, 'UTF-8') . '</span>';
        }
        return $map;
    }
}

if (!function_exists('loan_status_badge')) {
    /**
     * Badge HTML per un singolo stato; fallback "Sconosciuto" per valori
     * fuori enum (non dovrebbe più accadere: la mappa copre tutto l'enum).
     */
    function loan_status_badge(?string $stato): string
    {
        if ($stato !== null && $stato !== '') {
            $map = loan_status_badge_map();
            if (isset($map[$stato])) {
                return $map[$stato];
            }
        }
        return '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800"><i class="fas fa-question-circle mr-2"></i>'
            . htmlspecialchars(__('Sconosciuto'), ENT_QUOTES, 'UTF-8') . '</span>';
    }
}
