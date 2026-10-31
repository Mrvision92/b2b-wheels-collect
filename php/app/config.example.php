<?php
/**
 * Configuration secrète de B2B Wheels Collect.
 *
 * Copiez ce fichier en « config.php » (dans le même dossier app/) puis
 * remplissez les valeurs. config.php ne doit jamais être publié ni envoyé
 * sur GitHub : le dossier app/ reste en dehors de public_html.
 */

return [
    // Adresse publique du site, sans « / » final.
    'app_url' => 'https://www.votre-domaine.be',

    // Clé secrète d'au moins 32 caractères, à générer une fois :
    //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Ne plus la changer ensuite (elle sert à calculer les références).
    'app_secret' => '',

    // true : mode démonstration. Les emails sont simulés (consultables sur le
    // site) et Google Agenda n'est pas utilisé. Passez à false en production.
    'demo' => true,

    // Base MySQL créée dans hPanel → Bases de données MySQL.
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'u123456789_bwc',
        'user' => 'u123456789_bwc',
        'password' => '',
    ],

    'mail' => [
        // "smtp" (boîte email Hostinger) ou "resend" (https://resend.com).
        'transport' => 'smtp',
        // Expéditeur. Avec SMTP, utilisez l'adresse de la boîte ci-dessous.
        'from' => 'B2B Wheels Collect <reservations@votre-domaine.be>',
        // Adresse de réponse pour les garages (vide = expéditeur).
        'reply_to' => '',
        // Qui reçoit la notification de chaque nouvel enlèvement.
        'vbm_recipients' => ['planning@vbmwheels.com'],

        'smtp' => [
            'host' => 'smtp.hostinger.com',
            'port' => 465,
            'encryption' => 'ssl', // "ssl" pour le port 465, "tls" pour le port 587
            'username' => 'reservations@votre-domaine.be',
            'password' => '',
        ],

        'resend_api_key' => '',
    ],

    // Google Agenda (facultatif : sans ces valeurs, les réservations sont
    // enregistrées dans MySQL et envoyées par email, sans agenda).
    'google' => [
        // Identifiant de l'agenda, ex. « abc123@group.calendar.google.com ».
        'calendar_id' => '',
        // Compte de service : chemin du fichier JSON téléchargé dans Google
        // Cloud, placé hors de public_html (ex. dans app/storage/).
        'service_account_file' => '',
        // Ou bien OAuth (laisser vide si le compte de service est utilisé).
        'oauth_client_id' => '',
        'oauth_client_secret' => '',
        'oauth_refresh_token' => '',
    ],

    // Cloudflare Turnstile (anti-spam facultatif, widget en mode « Managed »).
    'turnstile' => [
        'site_key' => '',
        'secret_key' => '',
    ],
];
