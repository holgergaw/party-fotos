<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$uploadDir = __DIR__ . '/uploads/';
$metaFile  = __DIR__ . '/data/metadata.json';
$extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'];

// Parameter
$cat     = $_GET['cat']     ?? 'all';   // 'all' | 'guest' | 'fotobox'
$shuffle = isset($_GET['shuffle']);     // ?shuffle → Zufallsreihenfolge

// Metadaten laden
$meta = [];
if (file_exists($metaFile)) {
    $raw = file_get_contents($metaFile);
    $meta = json_decode($raw, true) ?? [];
}

$files = [];
foreach (glob($uploadDir . '*') as $path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, $extensions)) continue;

    $name = basename($path);
    $m    = $meta[$name] ?? ['category' => 'guest', 'hidden' => false];

    // Ausgeblendete überspringen
    if (!empty($m['hidden'])) continue;

    // Kategorie-Filter
    if ($cat !== 'all' && ($m['category'] ?? 'guest') !== $cat) continue;

    $files[] = [
        'name' => $name,
        'time' => filemtime($path),
    ];
}

if ($shuffle) {
    shuffle($files);
} else {
    // Neueste zuerst
    usort($files, fn($a, $b) => $b['time'] - $a['time']);
}

echo json_encode(array_column($files, 'name'));
