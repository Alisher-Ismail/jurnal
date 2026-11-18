<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Web plagiarism search (https://brave.com/search/api/): $5 free credit a month ≈ 1,000 searches.
    'brave' => [
        'key' => env('BRAVE_SEARCH_API_KEY'),
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'username' => env('TELEGRAM_BOT_USERNAME'),
        // Private channel or group (bot must be admin) where every file is kept.
        'storage_chat_id' => env('TELEGRAM_STORAGE_CHAT_ID'),
        // Comma-separated chat ids that are notified about submissions and may accept/reject them.
        'admin_chat_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('TELEGRAM_ADMIN_CHAT_IDS', ''))))),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

];
