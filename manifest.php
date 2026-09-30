<?php
// manifest.php - Dynamic Web App Manifest for Add to Homescreen
header('Content-Type: application/manifest+json; charset=utf-8');
require_once __DIR__ . '/includes/auth.php';

$baseUrl = getBaseUrl();
$startUrl = isset($_GET['url']) ? sanitize($_GET['url']) : './';
if (empty($startUrl) || strpos($startUrl, 'http') === 0 || strpos($startUrl, '//') === 0) {
    $startUrl = './';
}

$manifest = [
    'name' => 'PitchVault Private Video Platform',
    'short_name' => 'PitchVault',
    'icons' => [
        [
            'src' => $baseUrl . '/favicon.svg',
            'sizes' => 'any',
            'type' => 'image/svg+xml',
            'purpose' => 'any'
        ],
        [
            'src' => $baseUrl . '/apple-touch-icon.png',
            'sizes' => '180x180',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => $baseUrl . '/icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => $baseUrl . '/icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ]
    ],
    'theme_color' => '#1d4ed8',
    'background_color' => '#f8fafc',
    'display' => 'standalone',
    'start_url' => $startUrl
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
