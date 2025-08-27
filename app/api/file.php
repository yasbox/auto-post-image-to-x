<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Queue;
use App\Lib\Failed;
use App\Lib\ImageProc;
use App\Lib\Settings;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) { http_response_code(401); exit; }

$id = $_GET['id'] ?? '';
$thumb = isset($_GET['thumb']) && $_GET['thumb'] !== '0' && $_GET['thumb'] !== '';
$q = Queue::get();
$found = null;
foreach ($q['items'] as $it) if ($it['id'] === $id) { $found = $it; break; }
if (!$found) {
    $f = Failed::get();
    foreach ($f['items'] as $it) if (($it['id'] ?? '') === $id) { $found = $it; break; }
}
if (!$found) { http_response_code(404); exit; }

$path = __DIR__ . '/../data/inbox/' . $found['file'];
if ($thumb) {
    $thumbPath = __DIR__ . '/../data/thumbs/' . $id . '.jpg';
    if (!is_file($thumbPath)) {
        try {
            $thumbCfg = Settings::get()['thumb'] ?? ['enabled' => true, 'longEdge' => 512, 'quality' => 70, 'stripMetadata' => true];
            $gdAvailable = function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists('imagecopyresampled');
            if ((!isset($thumbCfg['enabled']) || (bool)$thumbCfg['enabled']) && $gdAvailable) {
                ImageProc::makeThumb($path, $thumbCfg, $thumbPath);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (is_file($thumbPath)) { $path = $thumbPath; }
}
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


