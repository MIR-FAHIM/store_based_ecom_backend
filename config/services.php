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

    'muthobarta' => [
        'base_url' => env('MUTHOBARTA_BASE_URL', 'https://sysadmin.muthobarta.com/api/v1'),
        'api_key' => env('MUTHOBARTA_API_KEY','Token e76076c94b87ad21028fd2c13f931f727c6e5e24' ),
    ],

    'payment_gateway' => env('PAYMENT_GATEWAY', 'aamarpay'),

    'sslcommerz' => [
        'sandbox' => env('SSLCOMMERZ_SANDBOX', true),
        'store_id' => env('SSLCOMMERZ_STORE_ID'),
        'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
        'callback_base_url' => env('SSLCOMMERZ_CALLBACK_BASE_URL'),
    ],

    'aamarpay' => [

    'base_url' => env('AAMARPAY_BASE_URL'),

    'store_id' => env('AAMARPAY_STORE_ID'),

    'signature_key' => env('AAMARPAY_SIGNATURE_KEY'),

    'success_url' => env('AAMARPAY_SUCCESS_URL'),

    'fail_url' => env('AAMARPAY_FAIL_URL'),

    'cancel_url' => env('AAMARPAY_CANCEL_URL'),

    'validation_url' => env('AAMARPAY_VALIDATION_URL'),

],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'service_account_json' => env('FIREBASE_SERVICE_ACCOUNT_JSON'),
        'token_uri' => env('FIREBASE_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
    ],

    'frontend' => [
        'url' => env('FRONTEND_URL'),
        'product_path' => env('FRONTEND_PRODUCT_PATH', '/product'),
        'payment_success_path' => env('FRONTEND_PAYMENT_SUCCESS_PATH', '/payment-success'),
        'payment_failed_path' => env('FRONTEND_PAYMENT_FAILED_PATH', '/payment-failed'),
        'payment_cancelled_path' => env('FRONTEND_PAYMENT_CANCELLED_PATH', '/payment-cancelled'),
    ],

];
