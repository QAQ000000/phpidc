<?php

declare(strict_types=1);

return [
    // Progressive enhancement of the client console and storefront.
    'livewire' => [
        'class_namespace' => 'App\\Livewire',
        'view_path' => resource_path('views/livewire'),
        'layout' => 'layouts.app',
        'temporary_file_upload' => [
            'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
            'rules' => ['required', 'file', 'max:102400'],
            'directory' => null,
            'middleware' => 'throttle:60,1',
            'preview_mimes' => ['png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf'],
            'max_upload_time' => 5,
            'cleanup' => true,
        ],
        'render_on_redirect' => false,
        'legacy_model_binding' => false,
        'inject_assets' => true,
        'navigate' => ['show_progress_bar' => true],
        'inject_morph_markers' => true,
        'pagination_theme' => 'tailwind',
    ],

    // PVE API client timeouts. Detail lives in upstream_apis; these are
    // only transport-level floors.
    'proxmox' => [
        'request_timeout' => (int) env('PROXMOX_REQUEST_TIMEOUT', 30),
        'pool_timeout' => (int) env('PROXMOX_POOL_TIMEOUT', 60),
    ],
];
