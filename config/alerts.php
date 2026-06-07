<?php

// Operational alerting. Kept in config (not read via env() at call time) so it
// keeps working once config is cached in production.
return [
    'webhook_url' => env('ALERT_WEBHOOK_URL'),
    'email' => env('ALERT_EMAIL'),
    'throttle_minutes' => (int) env('ALERT_THROTTLE_MINUTES', 10),
];
