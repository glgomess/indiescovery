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

    'steam' => [
        'key' => env('STEAM_API_KEY'),
        'api_url' => env('STEAM_API_URL', 'https://api.steampowered.com'),
        'login_url' => env('STEAM_LOGIN_URL', 'https://steamcommunity.com/openid/login'),
        'media_url' => env('STEAM_MEDIA_URL', 'https://media.steampowered.com'),
        'capsule_url' => env('STEAM_CAPSULE_URL', 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps'),
    ],

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

];
