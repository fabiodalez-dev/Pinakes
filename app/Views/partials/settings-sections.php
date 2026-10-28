<?php
/** One catalogue for settings navigation, page identity and direct links. */
return [
    'general' => ['group' => __('Biblioteca'), 'label' => __('Identità'), 'description' => __('Nome, logo e informazioni della biblioteca mostrate nel sito.'), 'icon' => 'fa-building'],
    'email' => ['group' => __('Comunicazione'), 'label' => __('Email'), 'description' => __('Configura il servizio di invio email e verifica la consegna.'), 'icon' => 'fa-envelope'],
    'registration' => ['group' => __('Biblioteca'), 'label' => __('Registrazione'), 'description' => __('Gestisci iscrizioni, approvazioni e campi degli utenti.'), 'icon' => 'fa-user-plus'],
    'templates' => ['group' => __('Comunicazione'), 'label' => __('Template'), 'description' => __('Personalizza i modelli delle notifiche email.'), 'icon' => 'fa-file-alt'],
    'cms' => ['group' => __('Sito e sistema'), 'label' => __('CMS'), 'description' => __('Gestisci le pagine e i contenuti del sito pubblico.'), 'icon' => 'fa-edit'],
    'contacts' => ['group' => __('Sito e sistema'), 'label' => __('Contatti'), 'description' => __('Configura i contatti della biblioteca e il modulo di richiesta.'), 'icon' => 'fa-address-book'],
    'privacy' => ['group' => __('Sito e sistema'), 'label' => __('Privacy'), 'description' => __('Gestisci informativa, cookie e preferenze di consenso.'), 'icon' => 'fa-shield-alt'],
    'messages' => ['group' => __('Comunicazione'), 'label' => __('Messaggi'), 'description' => __('Consulta le richieste ricevute dal modulo di contatto.'), 'icon' => 'fa-inbox'],
    'labels' => ['group' => __('Biblioteca'), 'label' => __('Etichette'), 'description' => __('Imposta formato e contenuto delle etichette da stampare.'), 'icon' => 'fa-barcode'],
    'cards' => ['group' => __('Biblioteca'), 'label' => __('Tessere'), 'description' => __('Imposta layout, codice e retro delle tessere della biblioteca da stampare.'), 'icon' => 'fa-id-card'],
    'sharing' => ['group' => __('Sito e sistema'), 'label' => __('Condivisione'), 'description' => __('Personalizza le anteprime e i contenuti condivisi dal sito.'), 'icon' => 'fa-share-alt'],
    'advanced' => ['group' => __('Sito e sistema'), 'label' => __('Avanzate'), 'description' => __('Gestisci integrazioni e configurazioni avanzate del sistema.'), 'icon' => 'fa-cogs'],
    'loans' => ['group' => __('Biblioteca'), 'label' => __('Prestiti'), 'description' => __('Configura le regole e le automazioni della circolazione.'), 'icon' => 'fa-book-open'],
    'circulation' => ['group' => __('Biblioteca'), 'label' => __('Circolazione'), 'description' => __('Tipologie di lettore e regole di prestito per tipo di materiale.'), 'icon' => 'fa-id-badge'],
];
