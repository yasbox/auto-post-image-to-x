<?php
declare(strict_types=1);

// Same-origin proxy for whitelisted CDN assets (CSS/JS) to avoid MIME/CSP issues

function send_asset(string $body, string $ctype, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: ' . $ctype);
    header('Cache-Control: public, max-age=3600');
    echo $body;
    exit;
}

$url = $_GET['u'] ?? '';
$parts = $url ? parse_url($url) : null;
if (!$url || !$parts || ($parts['scheme'] ?? '') !== 'https') {
    send_asset('/* bad url */', 'application/javascript; charset=utf-8', 400);
}

$host = strtolower($parts['host'] ?? '');
$path = $parts['path'] ?? '';

$allowedHosts = ['cdn.jsdelivr.net','unpkg.com','cdn.tailwindcss.com'];
if (!in_array($host, $allowedHosts, true)) {
    send_asset('/* host not allowed */', 'application/javascript; charset=utf-8', 403);
}

$allowedExt = ['.js' => 'application/javascript; charset=utf-8', '.css' => 'text/css; charset=utf-8'];
$ctype = null;
foreach ($allowedExt as $ext => $mime) {
    if (str_ends_with($path, $ext)) { $ctype = $mime; break; }
}
// tailwind CDN is a JS script without extension; allow explicitly
if ($host === 'cdn.tailwindcss.com' && ($ctype === null)) { $ctype = 'application/javascript; charset=utf-8'; }
if ($ctype === null) {
    // default to JS to avoid HTML fallback triggering nosniff
    $ctype = 'application/javascript; charset=utf-8';
}

$cacheDir = __DIR__ . '/../data/tmp/cdn';
@mkdir($cacheDir, 0775, true);
$hash = sha1($url);
$ext = str_ends_with($path, '.css') ? '.css' : '.js';
$cacheFile = $cacheDir . '/' . $hash . $ext;

// serve cache if exists (24h)
if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
    send_asset(file_get_contents($cacheFile) ?: '', $ctype, 200);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'User-Agent: x-auto-poster/1.0',
        'Accept: text/css,application/javascript,application/x-javascript,*/*;q=0.1',
    ],
]);
$body = curl_exec($ch);
if ($body === false) {
    send_asset('/* fetch error */', $ctype, 502);
}
$st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($st < 200 || $st >= 300) {
    send_asset('/* bad status ' . $st . ' */', $ctype, 502);
}

file_put_contents($cacheFile, $body);
send_asset($body, $ctype, 200);


