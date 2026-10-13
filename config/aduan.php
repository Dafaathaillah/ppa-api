<?php

$sites = ['HO', 'BIB', 'ADW', 'VIB', 'BA', 'MIFA', 'MHU', 'AMI', 'PIK', 'BGE', 'IPT', 'MLP', 'MIP', 'SBS', 'SKS', 'MAC', 'DMP'];

return [
    'timezones' => [
        'default' => env('ADUAN_TIMEZONE', 'Asia/Makassar'),
        'sites' => [],
    ],
    'webhooks' => [
        'secret' => env('ADUAN_WEBHOOK_SECRET'),
        'sites' => collect($sites)->mapWithKeys(fn (string $site) => [$site => env("ADUAN_WEBHOOK_{$site}_URL")])->all(),
    ],
];
