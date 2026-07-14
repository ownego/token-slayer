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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'client_id' => env('SLACK_OAUTH_CLIENT_ID'),
        'client_secret' => env('SLACK_OAUTH_CLIENT_SECRET'),
        'redirect' => env('SLACK_OAUTH_REDIRECT_URI'),
        'bot_token' => env('SLACK_BOT_TOKEN'),
    ],

    'slack_notifier' => [
        'webhook_url' => env('SLACK_NOTIFIER_WEBHOOK_URL'),
    ],

    'slack_security' => [
        'webhook_url' => env('SLACK_SECURITY_WEBHOOK_URL'),
    ],

];
