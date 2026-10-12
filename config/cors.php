<?php

// The web UI is served by this same app, so no cross-origin access is needed. An empty paths list turns CORS
// handling off, and browsers then refuse cross-origin reads of the API.
return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
