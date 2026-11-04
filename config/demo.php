<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public demo
    |--------------------------------------------------------------------------
    |
    | DEMO_MODE turns this installation into the public test site. Never set it on
    | the real site: demo:reset wipes the whole database and file storage and loads
    | the anonymized snapshot instead. In demo mode the messaging modules that send
    | through real third-party accounts can't be turned on, and the system mailer
    | only writes to the log, so testers can't send email, SMS, WhatsApp or Telegram.
    |
    */

    'enabled' => (bool) env('DEMO_MODE', false),

    /** Modules a demo church can never turn on. */
    'locked_modules' => ['messaging', 'messaging_sms', 'messaging_whatsapp', 'messaging_telegram'],

    /** Accounts with this email domain are the shared demo logins: their email and password can't be changed. */
    'account_domain' => 'example.com',

    /** Password every demo account gets when the snapshot is loaded. */
    'password' => env('DEMO_PASSWORD', 'demo1234'),

    /** When the scheduler reloads the snapshot (server time). */
    'reset_at' => env('DEMO_RESET_AT', '03:30'),

    'snapshot_path' => database_path('demo/snapshot.json'),

    'translations_path' => database_path('demo/translations.json'),

    'hymns_path' => database_path('demo/hymns.json'),

    /** AI-generated profile pictures, one {user id}.jpg per demo person. */
    'avatars_path' => database_path('demo/avatars'),

];
