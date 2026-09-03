<?php

return [
    'public_url' => rtrim(env('DASHBOARD_PUBLIC_URL', env('APP_URL', 'http://localhost')), '/'),
    'force_https' => filter_var(env('DASHBOARD_FORCE_HTTPS', false), FILTER_VALIDATE_BOOL),
];