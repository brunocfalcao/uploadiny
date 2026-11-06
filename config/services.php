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
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'uploadiny' => [
        'ffmpeg_binary' => env('UPLOADINY_FFMPEG_BINARY', 'ffmpeg'),
        'ffmpeg_timeout' => (int) env('UPLOADINY_FFMPEG_TIMEOUT', 5),
        'device_token_expiration_days' => (int) env('UPLOADINY_DEVICE_TOKEN_EXPIRATION_DAYS', 90),
        'vision_binary' => env('UPLOADINY_VISION_BINARY', '/usr/local/libexec/uploadiny-vision.py'),
        'vision_model' => 'gpt-6.1-sol',
        'vision_timeout' => (int) env('UPLOADINY_VISION_TIMEOUT', 80),
    ],

];
