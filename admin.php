<?php
// ─── Admin-Backend ────────────────────────────────────────────────────────────
session_start();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$configFile   = __DIR__ . '/data/config.json';
$metaFile     = __DIR__ . '/data/metadata.json';
$uploadDir    = __DIR__ . '/uploads/';
$action       = $_GET['action'] ?? '';

// ─── Hilfsfunktionen ─────────────────────────────────────────────────────────

function readJson(string $path): array {
    if (!file_exists($path)) return [];
    $raw = file_get_contents($path);
    return json_decode($raw, true) ?? [];
}

function writeJson(string $path, array $data): bool {
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function validFilename(string $name): bool {
    return $name === basename($name)
        && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $name)
        && strlen($name) <= 255;
}

function requireAuth(): void {
    if (empty($_SESSION['admin'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Nicht eingeloggt']);
        exit;
    }
}

function ok(array $payload = []): void {
    echo json_encode(array_merge(['ok' => true], $payload));
    exit;
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ─── Routen ──────────────────────────────────────────────────────────────────

switch ($action) {

    // ── Login ────────────────────────────────────────────────────────────────
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Nur POST erlaubt', 405);
        $pw = $_POST['password'] ?? '';
        $cfg = readJson($configFile);
        if (!password_verify($pw, $cfg['pw_hash'] ?? '')) {
            fail('Falsches Passwort', 401);
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        ok();

    // ── Logout ───────────────────────────────────────────────────────────────
    case 'logout':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Nur POST erlaubt', 405);
        session_destroy();
        ok();

    // ── Config lesen (title öffentlich, rest nur auth) ───────────────────────
    case 'get_config':
        $cfg = readJson($configFile);
        if (!empty($_SESSION['admin'])) {
            // Admin sieht alles außer pw_hash
            ok(['title' => $cfg['title'] ?? '', 'tagline' => $cfg['tagline'] ?? '', 'watermark_text' => $cfg['watermark_text'] ?? '']);
        } else {
            // Gäste sehen Titel + Tagline (für index.html)
            ok(['title' => $cfg['title'] ?? 'Party Fotos', 'tagline' => $cfg['tagline'] ?? '']);
        }

    // ── Config speichern ─────────────────────────────────────────────────────
    case 'save_config':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Nur POST erlaubt', 405);
        $cfg = readJson($configFile);
        if (isset($_POST['title']))          $cfg['title']          = mb_substr(trim($_POST['title']), 0, 80);
        if (isset($_POST['tagline']))         $cfg['tagline']        = mb_substr(trim($_POST['tagline']), 0, 120);
        if (isset($_POST['watermark_text'])) $cfg['watermark_text'] = mb_substr(trim($_POST['watermark_text']), 0, 100);
        if (!writeJson($configFile, $cfg)) fail('Konfiguration konnte nicht gespeichert werden', 500);
        ok();

    // ── Fotos mit Metadaten (Admin-Ansicht, alle inkl. hidden) ───────────────
    case 'get_photos':
        requireAuth();
        $meta = readJson($metaFile);
        $extensions = ['jpg','jpeg','png','gif','webp','heic','heif'];
        $files = [];
        foreach (glob($uploadDir . '*') as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, $extensions)) continue;
            $name = basename($path);
            $m = $meta[$name] ?? ['category' => 'guest', 'hidden' => false];
            $files[] = [
                'name'     => $name,
                'time'     => filemtime($path),
                'category' => $m['category'] ?? 'guest',
                'hidden'   => (bool)($m['hidden'] ?? false),
            ];
        }
        usort($files, fn($a, $b) => $b['time'] - $a['time']);
        ok(['photos' => $files]);

    // ── Foto ausblenden / einblenden ─────────────────────────────────────────
    case 'set_hidden':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Nur POST erlaubt', 405);
        $name   = $_POST['name'] ?? '';
        $hidden = filter_var($_POST['hidden'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!validFilename($name)) fail('Ungültiger Dateiname');
        if (!file_exists($uploadDir . $name)) fail('Datei nicht gefunden', 404);
        $meta = readJson($metaFile);
        if (!isset($meta[$name])) $meta[$name] = ['category' => 'guest', 'hidden' => false];
        $meta[$name]['hidden'] = $hidden;
        if (!writeJson($metaFile, $meta)) fail('Metadaten konnten nicht gespeichert werden', 500);
        ok();

    // ── Foto löschen ─────────────────────────────────────────────────────────
    case 'delete_photo':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Nur POST erlaubt', 405);
        $name = $_POST['name'] ?? '';
        if (!validFilename($name)) fail('Ungültiger Dateiname');
        $path = $uploadDir . $name;
        if (!file_exists($path) || !is_file($path)) fail('Datei nicht gefunden', 404);
        if (!unlink($path)) fail('Datei konnte nicht gelöscht werden', 500);
        $meta = readJson($metaFile);
        unset($meta[$name]);
        writeJson($metaFile, $meta);
        ok();

    // ── Diashow starten (Raspberry Pi) ───────────────────────────────────────
    case 'start_slideshow':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Nur POST erlaubt', 405);
        // Chromium im Kiosk-Modus starten (läuft auf dem Pi unter DISPLAY :0)
        $cmd = 'DISPLAY=:0 chromium-browser --kiosk --noerrdialogs --disable-infobars '
             . '--no-first-run http://localhost/slideshow.html > /dev/null 2>&1 &';
        exec($cmd, $out, $ret);
        ok(['cmd' => $cmd]);

    // ── Session-Status prüfen ────────────────────────────────────────────────
    case 'check':
        echo json_encode(['logged_in' => !empty($_SESSION['admin'])]);
        exit;

    default:
        fail('Unbekannte Aktion', 404);
}
