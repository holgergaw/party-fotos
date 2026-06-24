<?php
// CLI-Skript: erzeugt Thumbnails für bereits vorhandene Fotos in uploads/ ohne Thumbnail.
// Aufruf auf dem Pi: php backfill-thumbnails.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur CLI erlaubt.');
}

$uploadDir = __DIR__ . '/uploads/';
$thumbDir  = __DIR__ . '/thumbs/';

if (!is_dir($thumbDir)) mkdir($thumbDir, 0775, true);

// Duplikat aus upload.php, bewusst (kein shared lib-Stil)
function createThumbnail(string $srcPath, string $destPath, string $ext, int $maxEdge = 1024): bool {
    $src = match($ext) {
        'jpg','jpeg' => @imagecreatefromjpeg($srcPath),
        'png'        => @imagecreatefrompng($srcPath),
        'gif'        => @imagecreatefromgif($srcPath),
        'webp'       => @imagecreatefromwebp($srcPath),
        default      => null,
    };
    if (!$src) return false;

    $w = imagesx($src); $h = imagesy($src);
    $longEdge = max($w, $h);

    if ($longEdge <= $maxEdge) {
        $dst = $src; $dw = $w; $dh = $h;
    } else {
        $scale = $maxEdge / $longEdge;
        $dw = (int)round($w * $scale);
        $dh = (int)round($h * $scale);
        $dst = imagecreatetruecolor($dw, $dh);
        if ($ext === 'png' || $ext === 'gif') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $w, $h);
    }

    $ok = match($ext) {
        'jpg','jpeg' => imagejpeg($dst, $destPath, 78),
        'png'        => imagepng($dst, $destPath, 6),
        'gif'        => imagegif($dst, $destPath),
        'webp'       => imagewebp($dst, $destPath, 78),
        default      => false,
    };
    imagedestroy($src);
    if ($dst !== $src) imagedestroy($dst);
    return $ok;
}

$files = glob($uploadDir . '*');
$created = 0; $skipped = 0; $failed = 0;

foreach ($files as $path) {
    if (!is_file($path)) continue;
    $filename = basename($path);
    $thumbPath = $thumbDir . $filename;

    if (file_exists($thumbPath)) { $skipped++; continue; }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (createThumbnail($path, $thumbPath, $ext)) {
        $created++;
        echo "OK:   $filename\n";
    } else {
        $failed++;
        echo "FAIL: $filename (Format evtl. nicht unterstuetzt, z.B. HEIC)\n";
    }
}

echo "\nFertig. Erstellt: $created, uebersprungen: $skipped, fehlgeschlagen: $failed\n";
