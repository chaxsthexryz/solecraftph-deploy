<?php
/**
 * Web App Manifest — served as PHP so icon/start URLs are correct whether
 * SoleCraftPH is installed at the domain root or in a subfolder on Hostinger.
 */
require_once __DIR__ . '/config/app.php';
header('Content-Type: application/manifest+json');
$manifest = [
    'name' => 'SoleCraftPH',
    'short_name' => 'SoleCraft',
    'description' => 'Authentic footwear for the way Filipinos move.',
    'start_url' => BASE_PATH . '/index.php',
    'scope' => BASE_PATH . '/',
    'display' => 'standalone',
    'background_color' => '#FAF9F6',
    'theme_color' => '#111111',
    'icons' => [
        [
            'src' => BASE_PATH . '/assets/icons/icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => BASE_PATH . '/assets/icons/icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => BASE_PATH . '/assets/icons/icon-512-maskable.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
    'screenshots' => [
        [
            'src' => BASE_PATH . '/assets/screenshots/desktop.png',
            'sizes' => '1600x793',
            'type' => 'image/png',
            'form_factor' => 'wide',
        ],
        [
            'src' => BASE_PATH . '/assets/screenshots/mobile.png',
            'sizes' => '651x947',
            'type' => 'image/png',
            'form_factor' => 'narrow',
        ],
    ],
];
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
