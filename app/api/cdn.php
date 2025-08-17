<?php
declare(strict_types=1);

// Same-origin proxy for whitelisted CDN assets (CSS/JS) to avoid MIME/CSP issues

$url = $_GET['u'] ?? '';
if (!$url) { http_response_code(400); echo 'missing u'; exit; }

$parts = parse_url($url);
if (!$parts || ($parts['scheme'] ?? '') !== 'https') { http_response_code(400); echo 'bad url'; exit; }
$host = strtolower($parts['host'] ?? '');
$path = $parts['path'] ?? '';

$allowedHosts = ['cdn.jsdelivr.net','unpkg.com','cdn.tailwindcss.com'];
if (!in_array($host, $allowedHosts, true)) { http_response_code(403); echo 'host not allowed'; exit; }

$allowedExt = ['.js' => 'application/javascript; charset=utf-8', '.css' => 'text/css; charset=utf-8'];
$ctype = null;
foreach ($allowedExt as $ext => $mime) {
    if (str_ends_with($path, $ext)) { $ctype = $mime; break; }
}
// tailwind CDN is a JS script without extension; allow explicitly
if ($host === 'cdn.tailwindcss.com' && ($ctype === null)) { $ctype = 'application/javascript; charset=utf-8'; }
if ($ctype === null) { http_response_code(403); echo 'ext not allowed'; exit; }

$cacheDir = __DIR__ . '/../data/tmp/cdn';
@mkdir($cacheDir, 0775, true);
$hash = sha1($url);
$ext = str_ends_with($path, '.css') ? '.css' : '.js';
$cacheFile = $cacheDir . '/' . $hash . $ext;

// serve cache if exists (24h)
if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
    header('Content-Type: ' . $ctype);
    header('Cache-Control: public, max-age=3600');
    readfile($cacheFile); exit;
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
if ($body === false) { http_response_code(502); echo 'fetch error'; exit; }
$st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($st < 200 || $st >= 300) { http_response_code(502); echo 'bad status'; exit; }

file_put_contents($cacheFile, $body);
header('Content-Type: ' . $ctype);
header('Cache-Control: public, max-age=3600');
echo $body;
exit;


