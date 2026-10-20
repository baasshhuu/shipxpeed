<?php

/*
|--------------------------------------------------------------------------
| Courier tracking credentials
|--------------------------------------------------------------------------
| Used by App\Services\Tracking\ShipmentTracker (Track Order page and the
| track:sync scheduler). Secrets live only in .env, never in code.
*/

return [

    // How often (minutes) the scheduler re-checks open shipments
    'sync_every_minutes' => env('TRACKING_SYNC_MINUTES', 30),

    'delhivery' => [
        // Uses DELHIVERY_API_TOKEN / DELHIVERY_BASE_URL via config/services.php
    ],

    'xpressbees' => [
        // Uses XPB_USERNAME / XPB_PASSWORD via config/courier_services.php
        'track_url' => env('XPB_TRACK_URL', 'https://shipment.xpressbees.com/api/shipments2/track/'),
    ],

    'shadowfax' => [
        'token'     => env('SHADOWFAX_TRACK_TOKEN'),
        'track_url' => env('SHADOWFAX_TRACK_URL', 'https://dale.shadowfax.in/api/v4/clients/orders/'),
    ],

    'dtdc' => [
        'token'     => env('DTDC_TRACK_TOKEN'),
        'track_url' => env('DTDC_TRACK_URL', 'https://blktracksvc.dtdc.com/dtdc-api/rest/JSONCnTrk/getTrackDetails'),
    ],

    'parcelx' => [
        'token'     => env('PARCELX_ACCESS_TOKEN'),
        'track_url' => env('PARCELX_TRACK_URL', 'https://app.parcelx.in/api/v3/track_order'),
    ],

    'boxd' => [
        'secret_key'  => env('BOXD_SECRET_KEY'),
        'customer_id' => env('BOXD_CUSTOMER_ID'),
        'track_url'   => env('BOXD_TRACK_URL', 'https://backend.boxdlogistics.in/vendor/v1/shipment/shipment_tracking'),
    ],

    'tekipost' => [
        // Login handled by App\Services\TekipostService::getToken()
        'track_url' => env('TEKIPOST_TRACK_URL', 'https://app.tekipost.com/api-tracking-details/'),
    ],

    'shiprocket' => [
        // Login handled by App\Services\ShiprocketService::shiprocketLogin()
        'track_url' => env('SHIPROCKET_TRACK_URL', 'https://apiv2.shiprocket.in/v1/external/courier/track/awb/'),
    ],
];
