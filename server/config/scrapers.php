<?php
/**
 * Scrapers Configuration
 * Register and configure available book scrapers
 */

return [
    'scrapers' => [
        [
            'name' => 'libreria-universitaria',
            'class' => LibreriaUniversitariaScraper::class,
            'priority' => 10,
            'enabled' => true,
        ],
        [
            'name' => 'feltrinelli',
            'class' => FeltrinelliScraper::class,
            'priority' => 5,
            'enabled' => true,
        ],

        // Example: Add more scrapers here
        // [
        //     'name' => 'amazon-it',
        //     'class' => AmazonItScraper::class,
        //     'priority' => 8,
        //     'enabled' => false,
        // ],
    ],
];
