<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Csrf;
use App\Lib\Logger;
use App\Lib\Queue;
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

if (!$dzuuid || !isset($_FILES['file'])) Util::jsonResponse(['error' => 'bad_request'], 400);

$tmpdir = sys_get_temp_dir() . '/up-' . preg_replace('/[^a-z0-9-]/i', '', $dzuuid);
@mkdir($tmpdir, 0775, true);
$chunkPath = $tmpdir . '/' . $dzchunkindex . '.part';
move_uploaded_file($_FILES['file']['tmp_name'], $chunkPath);

if ($dzchunkindex + 1 < $dztotalchunkcount) {
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

// extension
$ext = match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => 'bin'
};
$id = date('YmdHis') . '-' . substr(Util::uuid(), 0, 8);
$targetName = Util::uuid() . '.' . $ext;
$target = $inboxDir . '/' . $targetName;
rename($finalTmp, $target);

Queue::append($id, $targetName);
Logger::op(['event' => 'upload', 'imageId' => $id, 'file' => $targetName, 'bytes' => filesize($target)]);

array_map('unlink', glob($tmpdir.'/*.part') ?: []);
@rmdir($tmpdir);

Util::jsonResponse(['status' => 'ok', 'id' => $id]);


