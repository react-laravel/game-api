<?php

return [
    'defaults' => [
        'guard' => 'web',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'identity',
        ],
    ],

    // This provider deliberately has no SQL model or table. A verified SSO
    // identity snapshot is injected into the guard on every protected request.
    'providers' => [
        'identity' => [
            'driver' => 'identity',
        ],
    ],
];
