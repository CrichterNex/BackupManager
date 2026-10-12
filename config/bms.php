<?php

return [
    // Sessions last 8 hours, matching the API spec's auth_token cookie.
    'session_hours' => 8,
    'rate_limit_per_hour' => (int) env('BMS_RATE_LIMIT_PER_HOUR', 1000),
    'login_attempts_per_15_min' => (int) env('BMS_LOGIN_ATTEMPTS_PER_15_MIN', 10),

    // Test engine: polled every minute by the scheduler. Retry and escalation timings follow the blueprint.
    'engine_enabled' => (bool) env('BMS_ENGINE_ENABLED', true),
    'test_retry_delay_minutes' => (int) env('BMS_TEST_RETRY_DELAY_MINUTES', 60),
    'test_max_attempts' => (int) env('BMS_TEST_MAX_ATTEMPTS', 3),
    'random_restore_every_days' => (int) env('BMS_RANDOM_RESTORE_EVERY_DAYS', 7),
    'restore_staging_dir' => env('BMS_RESTORE_STAGING_DIR') ?: '',

    // External backup platforms. BMS only ever calls hosts on these domains (guards against pointing it at internal addresses).
    'integration_allowed_host_suffixes' => array_filter(array_map('trim', explode(',', (string) env('BMS_INTEGRATION_HOST_SUFFIXES', 'acronis.com')))),
    'integration_sync_minutes' => (int) env('BMS_INTEGRATION_SYNC_MINUTES', 15),

    'agent_rate_limit_per_hour' => (int) env('BMS_AGENT_RATE_LIMIT_PER_HOUR', 1200),

    'min_password_length' => 12,
];
