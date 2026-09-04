<?php

return [
    'default' => [
        'site_id' => 'YOUR_SITE_ID',
        'key' => env('SYSTEMPAY_SITE_KEY', 'YOUR_KEY'),
        'env' => env('SYSTEMPAY_ENV', 'PRODUCTION'),

        // Only required to use cancel()/refund()/cancelOrRefund()/getTransaction(). This is the
        // REST API password found in the Back Office, under Paramétrage > Boutique > Clés d'API
        // REST (use the test or production password depending on "env" above). It is not the
        // same as "key" above, which only signs the payment form and IPN callbacks.
        'password' => env('SYSTEMPAY_REST_API_PASSWORD'),
    ],
];
