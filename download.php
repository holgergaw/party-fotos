<?php
// ─── Download mit Wasserzeichen ──────────────────────────────────────────────
$uploadDir  = __DIR__ . '/uploads/';
$configFile = __DIR__ . '/data/config.json';

// Wasserzeichen-Text aus config.json
function getWatermarkText(): string {
    global $configFile;
    if (!file_exists($configFile)) return '';
    $cfg = json_decode(file_get_contents($configFile), true) ?? [];
    return $cfg['watermark_text'] ?? '';
}

// Dateinamen validieren
function validFilename(string $name): bool {
    return $name === basename($name)
        && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $name)
        && strlen($name) <= 255;
}

// Wasserzeichen auf GD-Image brennen (bottom center)
function burnWatermark(\GdImage $img, string $text): void {
    if ($text === '') return;
    $w = imagesx($img);
    $h = imagesy($img);

    // Schriftgröße dynamisch: ~2.5 % der Breite, min 14 px
    $fontSize = max(14, (int)round($w * 0.025));

    // Versuche TrueType, Fallback auf eingebaute Schrift
    $fontFile = __DIR__ . '/assets/DejaVuSans-Bold.ttf';
    if (function_exists('imagettftext') && file_exists($fontFile)) {
        $bbox = imagettfbbox($fontSize, 0, $fontFile, $text);
        $tw = abs($bbox[4] - $bbox[0]);
        $th = abs($bbox[5] - $bbox[1]);
        $x = (int)(($w - $tw) / 2);
        $y = $h - $th - (int)($h * 0.025);

        // Schatten
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 50);
        imagettftext($img, $fontSize, 0, $x + 2, $y + 2, $shadow, $fontFile, $text);
        // Text
        $white = imagecolorallocatealpha($img, 255, 255, 255, 15);
        imagettftext($img, $fontSize, 0, $x, $y, $white, $fontFile, $text);
    } else {
        // Fallback: eingebauter Font (Größe 5 = ca. 9px)
        $fw = imagefontwidth(5) * strlen($text);
        $fh = imagefontheight(5);
        $x = (int)(($w - $fw) / 2);
        $y = $h - $fh - (int)($h * 0.02);
        $shadow = imagecolorallocatealpha($img, 0,   0,   0,   50);
        $white  = imagecolorallocatealpha($img, 255, 255, 255, 15);
        imagestring($img, 5, $x + 1, $y + 1, $text, $shadow);
        imagestring($img, 5, $x,     $y,     $text, $white);
    }
}

// GD-Image aus Datei laden (JPEG / PNG / WebP / GIF)
function loadImage(string $path): ?\GdImage {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match($ext) {
        'jpg','jpeg','heic','heif' => @imagecreatefromjpeg($path),
        'png'                      => @imagecreatefrompng($path),
        'gif'                      => @imagecreatefromgif($path),
        'webp'                     => @imagecreatefromwebp($path),
        default                    => null,
    };
}

// Image als JPEG in Puffer ausgeben und Datei-Header setzen
function sendImageDownload(\GdImage $img, string $filename): void {
    ob_start();
    imagejpeg($img, null, 88);
    $data = ob_get_clean();
    imagedestroy($img);

    $dlName = preg_replace('/\.[^.]+$/', '', $filename) . '.jpg';
    header('Content-Type: image/jpeg');
    header('Content-Disposition: attachment; filename="' . $dlName . '"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-cache');
    echo $data;
    exit;
}

// ── GET-Modus: Einzeldatei mit Wasserzeichen ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['file'])) {
    $name = $_GET['file'];
    if (!validFilename($name)) {
        http_response_code(400);
        echo 'Ungültiger Dateiname';
        exit;
    }
    $path = $uploadDir . $name;
    if (!file_exists($path) || !is_file($path)) {
        http_response_code(404);
        echo 'Datei nicht gefunden';
        exit;
    }

    $img = loadImage($path);
    if (!$img) {
        // Fallback: Rohdatei ohne Wasserzeichen senden
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    burnWatermark($img, getWatermarkText());
    sendImageDownload($img, $name);
}

// ── POST-Modus: ZIP mehrerer Dateien mit Wasserzeichen ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw   = $_POST['files_json'] ?? '';
    $files = json_decode($raw, true);

    if (!is_array($files) || count($files) === 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Keine Dateien angegeben']);
        exit;
    }

    // Validieren
    $valid = [];
    foreach ($files as $name) {
        if (!is_string($name) || !validFilename($name)) continue;
        $path = $uploadDir . $name;
        if (!file_exists($path) || !is_file($path)) continue;
        $valid[] = $name;
    }

    if (count($valid) === 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Keine gültigen Dateien gefunden']);
        exit;
    }

    $wmText  = getWatermarkText();
    $tmpDir  = sys_get_temp_dir();
    $tmpZip  = tempnam($tmpDir, 'party_zip_');
    $tmpImgs = [];

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'ZIP konnte nicht erstellt werden']);
        exit;
    }

    foreach ($valid as $name) {
        $srcPath = $uploadDir . $name;
        $img = loadImage($srcPath);

        if ($img) {
            burnWatermark($img, $wmText);
            $tmpImg = tempnam($tmpDir, 'party_img_') . '.jpg';
            imagejpeg($img, $tmpImg, 88);
            imagedestroy($img);
            $dlName = preg_replace('/\.[^.]+$/', '', $name) . '.jpg';
            $zip->addFile($tmpImg, $dlName);
            $tmpImgs[] = $tmpImg;
        } else {
            // Fallback ohne Wasserzeichen
            $zip->addFile($srcPath, $name);
        }
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="party-fotos.zip"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Cache-Control: no-cache');
    header('Pragma: no-cache');

    readfile($tmpZip);
    unlink($tmpZip);
    foreach ($tmpImgs as $f) { if (file_exists($f)) unlink($f); }
    exit;
}

// Weder GET?file= noch POST
http_response_code(405);
header('Content-Type: application/json');
echo json_encode(['error' => 'Ungültige Anfrage']);
