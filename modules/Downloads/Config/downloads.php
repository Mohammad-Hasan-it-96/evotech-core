<?php

return [
    /*
     * The filesystem disk artifacts are stored on and delivered from (ADR 0008).
     * Defaults to the private `downloads` local disk for local/CI; point this at
     * the `s3` disk in production (DOWNLOADS_DISK=s3). Never a public disk.
     */
    'disk' => env('DOWNLOADS_DISK', 'downloads'),

    /*
     * Maximum artifact upload size, in kilobytes. Default 2 GB.
     */
    'max_upload_kilobytes' => (int) env('DOWNLOADS_MAX_UPLOAD_KB', 2 * 1024 * 1024),

    /*
     * How long a minted signed download URL stays valid, in minutes (ADR 0008).
     */
    'link_ttl_minutes' => (int) env('DOWNLOADS_LINK_TTL_MINUTES', 15),

    /*
     * The release channel assumed when a product/staff request omits one.
     */
    'default_channel' => env('DOWNLOADS_DEFAULT_CHANNEL', 'stable'),
];
