<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'zamar_bible' => [
        'base_url' => env('ZAMAR_BIBLE_API_URL', ''),
        'api_key' => env('ZAMAR_BIBLE_API_KEY', ''),
        'default_bible' => env('ZAMAR_BIBLE_DEFAULT', 'kjv'),
    ],

    /*
     * Addresses that church admins type in (Bible API, ntfy, SMS, SMTP) must point at the public
     * internet, so nobody can make this server call its own private network. Turn on only for local
     * development against a service on 127.0.0.1 or the LAN.
     */
    'outbound' => [
        'allow_private_hosts' => (bool) env('OUTBOUND_ALLOW_PRIVATE_HOSTS', false),
    ],

    /*
     * Cloudflare Turnstile on the login page. Off until both keys are set. Pick the
     * "Non-interactive" widget mode for the site key in the Cloudflare dashboard.
     */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY', ''),
        'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

];
