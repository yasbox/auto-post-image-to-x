<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Queue;
use App\Lib\Settings;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) { http_response_code(401); exit; }

$id = $_GET['id'] ?? '';
$q = Queue::get();
$found = null;
foreach ($q['items'] as $it) if ($it['id'] === $id) { $found = $it; break; }
if (!$found) { http_response_code(404); exit; }

$path = __DIR__ . '/../data/inbox/' . $found['file'];
if (!is_file($path)) { http_response_code(404); exit; }
$mime = null;
$info = @getimagesize($path);
if ($info && !empty($info['mime'])) { $mime = $info['mime']; }
if ($mime === null) {
    $fi = @finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) { $mime = finfo_file($fi, $path) ?: null; finfo_close($fi); }
}
if ($mime === null) { $mime = 'application/octet-stream'; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);


