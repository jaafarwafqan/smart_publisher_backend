<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Separate queue-worker topology
    |--------------------------------------------------------------------------
    |
    | Render Web and Worker services have separate filesystems. When enabled,
    | this flag therefore requires uploads to use an S3-compatible disk.
    | Keep it false for single-container local development.
    |
    */
    'separate_queue_worker' => filter_var(
        env('SP_SEPARATE_QUEUE_WORKER', false),
        FILTER_VALIDATE_BOOL,
    ),
];
