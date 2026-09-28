<?php

return [
    'public_url' => rtrim(env('DASHBOARD_PUBLIC_URL', env('APP_URL', 'http://localhost')), '/'),
    'force_https' => filter_var(env('DASHBOARD_FORCE_HTTPS', false), FILTER_VALIDATE_BOOL),

    // Private key (PEM, RSA) used to sign the WordPress reporter plugin's self-update
    // manifest. Matching public key is hardcoded in the plugin source. Keep this file
    // outside the web root; storage/app is gitignored by default.
    'reporter_update_signing_key_path' => env(
        'SERVERDASHBOARD_REPORTER_SIGNING_KEY_PATH',
        storage_path('app/serverdashboard-reporter-update-signing-key.pem')
    ),
];