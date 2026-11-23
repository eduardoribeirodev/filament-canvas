<?php

return [
    'elements' => [],
    'fonts' => [
        'Arial, sans-serif' => 'Arial',
        'Verdana, sans-serif' => 'Verdana',
        'Georgia, serif' => 'Georgia',
        '"Times New Roman", serif' => 'Times New Roman',
        '"Courier New", monospace' => 'Courier New',
    ],
    'font_stylesheet' => null,
    'width' => 1920,
    'height' => 1080,
    'min_zoom' => 0.25,
    'max_zoom' => 3,
    'media_disk' => env('FILESYSTEM_DISK', 'public'),
    'library_collection' => 'library',
    'file_roots' => [],
];
