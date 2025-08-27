<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Csrf;
use App\Lib\Logger;
use App\Lib\Queue;
use App\Lib\ImageProc;
use App\Lib\Settings;
use App\Lib\Util;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) Util::jsonResponse(['error' => 'auth'], 401);
Csrf::validate();

$inboxDir = __DIR__ . '/../data/inbox';

$dzuuid = $_POST['dzuuid'] ?? '';
$dzchunkindex = (int)($_POST['dzchunkindex'] ?? 0);
$dztotalchunkcount = (int)($_POST['dztotalchunkcount'] ?? 1);
$filename = $_POST['name'] ?? ($_FILES['file']['name'] ?? '');

if (!isset($_FILES['file'])) Util::jsonResponse(['error' => 'bad_request'], 400);

// 非チャンク（互換）: dzuuid が無い場合は単発アップロードとして保存
if ($dzuuid === '') {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
    finfo_close($finfo);
    $allowed = Settings::get()['upload']['allowedMime'];
    if (!in_array($mime, $allowed, true)) Util::jsonResponse(['error' => 'mime'], 400);
    // Avoid match() for broader compatibility
    $ext = 'bin';
    if ($mime === 'image/jpeg') { $ext = 'jpg'; }
    elseif ($mime === 'image/png') { $ext = 'png'; }
    elseif ($mime === 'image/webp') { $ext = 'webp'; }
    $id = date('YmdHis') . '-' . substr(Util::uuid(), 0, 8);
    $targetName = Util::uuid() . '.' . $ext;
    $target = $inboxDir . '/' . $targetName;
    if (!@rename($_FILES['file']['tmp_name'], $target)) move_uploaded_file($_FILES['file']['tmp_name'], $target);
    Queue::append($id, $targetName);
    // Create thumbnail (best-effort)
    try {
        $thumbCfg = Settings::get()['thumb'] ?? ['enabled' => true, 'longEdge' => 512, 'quality' => 70, 'stripMetadata' => true];
        $gdAvailable = function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists('imagecopyresampled');
        if ((!isset($thumbCfg['enabled']) || (bool)$thumbCfg['enabled']) && $gdAvailable) {
            $thumbPath = __DIR__ . '/../data/thumbs/' . $id . '.jpg';
            ImageProc::makeThumb($target, $thumbCfg, $thumbPath);
        }
    } catch (Throwable $e) {
        Logger::op(['event' => 'thumb.error', 'imageId' => $id, 'message' => $e->getMessage()]);
    }
    Logger::op(['event' => 'upload.single', 'imageId' => $id, 'file' => $targetName, 'bytes' => filesize($target)]);
    Util::jsonResponse(['status' => 'ok', 'id' => $id]);
}

$tmpdir = sys_get_temp_dir() . '/up-' . preg_replace('/[^a-z0-9-]/i', '', $dzuuid);
@mkdir($tmpdir, 0775, true);
$chunkPath = $tmpdir . '/' . $dzchunkindex . '.part';
move_uploaded_file($_FILES['file']['tmp_name'], $chunkPath);

if ($dzchunkindex + 1 < $dztotalchunkcount) {
    Logger::op(['event' => 'upload.partial', 'uuid' => $dzuuid, 'index' => $dzchunkindex, 'total' => $dztotalchunkcount]);
    Util::jsonResponse(['status' => 'partial']);
}

// combine
$finalTmp = $tmpdir . '/combined.bin';
$fh = fopen($finalTmp, 'wb');
for ($i = 0; $i < $dztotalchunkcount; $i++) {
    $p = $tmpdir . '/' . $i . '.part';
    $fh2 = fopen($p, 'rb');
    stream_copy_to_stream($fh2, $fh);
    fclose($fh2);
}
fclose($fh);

// validate mime by finfo
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $finalTmp);
finfo_close($finfo);
$allowed = Settings::get()['upload']['allowedMime'];
if (!in_array($mime, $allowed, true)) {
    @unlink($finalTmp);
    Util::jsonResponse(['error' => 'mime'], 400);
}

// extension (avoid match for compatibility)
$ext = 'bin';
if ($mime === 'image/jpeg') { $ext = 'jpg'; }
elseif ($mime === 'image/png') { $ext = 'png'; }
elseif ($mime === 'image/webp') { $ext = 'webp'; }
$id = date('YmdHis') . '-' . substr(Util::uuid(), 0, 8);
$targetName = Util::uuid() . '.' . $ext;
$target = $inboxDir . '/' . $targetName;

// Move combined file into inbox. rename() may fail across mounts; fallback to copy+unlink
if (!@rename($finalTmp, $target)) {
    $ok = false;
    $in = fopen($finalTmp, 'rb');
    if ($in) {
        $out = fopen($target, 'wb');
        if ($out) {
            stream_copy_to_stream($in, $out);
            fclose($out);
            $ok = true;
        }
        fclose($in);
    }
    @unlink($finalTmp);
    if (!$ok) {
        Logger::op(['event' => 'upload.move_fail', 'error' => 'cannot move combined to inbox']);
        Util::jsonResponse(['error' => 'store_fail'], 500);
    }
}

Queue::append($id, $targetName);
// Generate thumbnail (best-effort)
try {
    $thumbCfg = Settings::get()['thumb'] ?? ['enabled' => true, 'longEdge' => 512, 'quality' => 70, 'stripMetadata' => true];
    $gdAvailable = function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists('imagecopyresampled');
    if ((!isset($thumbCfg['enabled']) || (bool)$thumbCfg['enabled']) && $gdAvailable) {
        $thumbPath = __DIR__ . '/../data/thumbs/' . $id . '.jpg';
        ImageProc::makeThumb($target, $thumbCfg, $thumbPath);
    }
} catch (Throwable $e) {
    Logger::op(['event' => 'thumb.error', 'imageId' => $id, 'message' => $e->getMessage()]);
}

Logger::op(['event' => 'upload.combined', 'imageId' => $id, 'file' => $targetName, 'bytes' => filesize($target), 'mime' => $mime]);

array_map('unlink', glob($tmpdir.'/*.part') ?: []);
@rmdir($tmpdir);

Util::jsonResponse(['status' => 'ok', 'id' => $id]);


