<?php

return [
    'gateway_url' => env('FOOTBALL_GATEWAY_URL'), 'gateway_token' => env('FOOTBALL_GATEWAY_TOKEN', ''),
    'data_token' => env('FOOTBALL_DATA_TOKEN', ''), 'competition' => env('FOOTBALL_COMPETITION', 'PL'), 'season' => env('FOOTBALL_SEASON'),
    'sync_enabled' => (bool) env('FOOTBALL_SYNC_ENABLED', false),
    'sportsdb_key' => env('SPORTSDB_API_KEY', '123'),
];
