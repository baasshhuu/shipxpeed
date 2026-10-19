<?php

/*
| Cashfree Secure ID (KYC / Verification Suite)
| Keys: merchant.cashfree.com > Secure ID > Developers > API Keys
| (Secure ID keys are separate from Payment Gateway keys.)
*/

return [
    // sandbox = Test Environment, production = live
    'env' => env('CASHFREE_KYC_ENV', env('CASHFREE_ENV', env('CASHFREE_PRODUCTION') ? 'production' : 'sandbox')),

    'base_urls' => [
        'sandbox'    => 'https://sandbox.cashfree.com/verification',
        'production' => 'https://api.cashfree.com/verification',
    ],

    'client_id'     => env('CASHFREE_KYC_CLIENT_ID', env('CASHFREE_CLIENT_ID')),
    'client_secret' => env('CASHFREE_KYC_CLIENT_SECRET', env('CASHFREE_CLIENT_SECRET')),

    // Optional 2FA "Public Key" mode (Developers > Two-Factor Authentication).
    // Leave empty when the server IP is whitelisted instead.
    'public_key_path' => env('CASHFREE_KYC_PUBLIC_KEY_PATH'),

    'timeout' => (int) env('CASHFREE_KYC_TIMEOUT', 30),

    // Optional x-api-version header (leave empty for Cashfree defaults)
    'api_version' => env('CASHFREE_KYC_API_VERSION'),

    // Seller must finish Aadhaar (DigiLocker) before KYC can be completed.
    'require_aadhaar' => (bool) env('CASHFREE_KYC_REQUIRE_AADHAAR', true),
];
