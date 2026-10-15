<?php

return [
    // Sessions last 8 hours, matching the API spec's auth_token cookie.
    'session_hours' => 8,
    'rate_limit_per_hour' => (int) env('BMS_RATE_LIMIT_PER_HOUR', 1000), // changes (anything that is not a read)
    'read_rate_limit_per_hour' => (int) env('BMS_READ_RATE_LIMIT_PER_HOUR', 7200), // reads, which live pages make every 15 seconds
    'login_attempts_per_15_min' => (int) env('BMS_LOGIN_ATTEMPTS_PER_15_MIN', 10),

    // Test engine: polled every minute by the scheduler. Retry and escalation timings follow the blueprint.
    'engine_enabled' => (bool) env('BMS_ENGINE_ENABLED', true),
    'test_retry_delay_minutes' => (int) env('BMS_TEST_RETRY_DELAY_MINUTES', 60),
    'test_max_attempts' => (int) env('BMS_TEST_MAX_ATTEMPTS', 3),
    // How long a passing test counts as current. After this a server shows "test overdue" until it is tested again.
    'test_fresh_days' => (int) env('BMS_TEST_FRESH_DAYS', 14),
    'platform_validation_max_age_hours' => (int) env('BMS_PLATFORM_VALIDATION_MAX_AGE_HOURS', 72), // an Acronis device must have had a validation run start within this long
    'recovery_point_max_age_hours' => (int) env('BMS_RECOVERY_POINT_MAX_AGE_HOURS', 36), // a job with no schedule must have a successful backup newer than this
    'manual_verify_percent' => (int) env('BMS_MANUAL_VERIFY_PERCENT', 20),        // share of passed manual tests a second person is asked to confirm
    'manual_pick_window_days' => (int) env('BMS_MANUAL_PICK_WINDOW_DAYS', 14),     // how far back the item to open may be received
    'manual_pick_max_position' => (int) env('BMS_MANUAL_PICK_MAX_POSITION', 10),   // the highest "nth email that day" it may ask for
    'random_restore_every_days' => (int) env('BMS_RANDOM_RESTORE_EVERY_DAYS', 7),
    'restore_staging_dir' => env('BMS_RESTORE_STAGING_DIR') ?: '',

    // External backup platforms. BMS only ever calls hosts on these domains (guards against pointing it at internal addresses).
    'integration_allowed_host_suffixes' => array_filter(array_map('trim', explode(',', (string) env('BMS_INTEGRATION_HOST_SUFFIXES', 'acronis.com')))),
    // Optional: folders on THIS server that backup locations may point into (comma separated). Empty means any absolute path.
    'location_path_roots' => array_filter(array_map('trim', explode(',', (string) env('BMS_LOCATION_PATH_ROOTS', '')))),
    // Most servers one sync may auto-import, so a large or surprising device list cannot flood the company in one go.
    'integration_auto_import_max_per_sync' => (int) env('BMS_AUTO_IMPORT_MAX_PER_SYNC', 25),
    'workload_window_days' => (int) env('BMS_WORKLOAD_WINDOW_DAYS', 7),   // Microsoft 365 items count if their latest backup finished within this long
    'workload_failed_percent' => (float) env('BMS_WORKLOAD_FAILED_PERCENT', 5),   // a workload is failed when this share of its items failed in their latest backup
    'activity_lookback_days' => (int) env('BMS_ACTIVITY_LOOKBACK_DAYS', 7),   // the first read of an Acronis connection's activity starts this far back
    'agent_bundle_dir' => env('BMS_AGENT_BUNDLE_DIR'),   // where agent bundles (agent + its own Python) are kept; storage/app/agent-bundles when empty
    'agent_pickup_grace_minutes' => (int) env('BMS_AGENT_PICKUP_GRACE_MINUTES', 15),   // a queued backup the agent has not picked up, with the agent offline, is raised as a problem after this long
    'integration_sync_minutes' => (int) env('BMS_INTEGRATION_SYNC_MINUTES', 15),

    'agent_rate_limit_per_hour' => (int) env('BMS_AGENT_RATE_LIMIT_PER_HOUR', 1200),

    // Two-step sign-in with an authenticator app. When true every user must set it up, and can do nothing else until they do.
    'mfa_required' => (bool) env('BMS_MFA_REQUIRED', true),

    'min_password_length' => 12,
];
