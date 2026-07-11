<?php

return [
    /*
     * Scheme prefix stamped on every product API token, e.g. "evo" -> evo_XXXX_...
     * The prefix is public (shown for display); the secret that follows is not.
     */
    'key_prefix' => env('GATEWAY_API_KEY_PREFIX', 'evo'),
];
