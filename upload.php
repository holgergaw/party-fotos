<?php
header('Content-Type: application/json');

$uploadDir = __DIR__ . '/uploads/';
$metaFile  = __DIR__ . '/data/metadata.json';
$maxSize = 20 * 1024 * 1024; // 20 MB
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Nur POST erlaubt']);
    exit;
}

if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['foto']['error'] ?? 'kein Dateifeld';
    echo json_encode(['ok' => false, 'error' => "Upload-Fehler: $err"]);
    exit;
}

$file = $_FILES['foto'];

if ($file['size'] > $maxSize) {
    echo json_encode(['ok' => false, 'error' => 'Datei zu groß (max 20 MB)']);
    exit;
}

// MIME via fileinfo prüfen (sicherer als $_FILES['type'])
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowedTypes)) {
    echo json_encode(['ok' => false, 'error' => "Dateityp nicht erlaubt: $mime"]);
    exit;
}

// Eindeutiger Dateiname: Timestamp + zufällige ID + originale Endung
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
$filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['ok' => false, 'error' => 'Konnte Datei nicht speichern']);
    exit;
}

// Kategorie aus Query-Parameter ermitteln
$source = (($_GET['source'] ?? '') === 'fotobox') ? 'fotobox' : 'guest';

// Metadaten schreiben
$meta = [];
if (file_exists($metaFile)) {
    $raw = file_get_contents($metaFile);
    $meta = json_decode($raw, true) ?? [];
}
$meta[$filename] = ['category' => $source, 'hidden' => false];
file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode(['ok' => true, 'file' => $filename]);
