<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Csrf;
use App\Lib\Logger;
use App\Lib\Queue;
use App\Lib\Failed;
use App\Lib\Settings;
use App\Lib\Util;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) Util::jsonResponse(['error' => 'auth'], 401);
Csrf::validate();

$data = json_decode(file_get_contents('php://input') ?: 'null', true);
$id = $data['id'] ?? '';
$scope = $data['scope'] ?? 'queue';

if ($scope === 'failed') {
    $f = Failed::get();
    $found = null; $idx = -1;
    foreach ($f['items'] as $i => $it) { if (($it['id'] ?? '') === $id) { $found = $it; $idx = $i; break; } }
    if ($found) {
        $inbox = __DIR__ . '/../data/inbox/' . $found['file'];
        if (is_file($inbox)) @unlink($inbox);
        $thumb = __DIR__ . '/../data/thumbs/' . $id . '.jpg';
        if (is_file($thumb)) @unlink($thumb);
        array_splice($f['items'], $idx, 1);
        Failed::save($f);
        Logger::op(['event' => 'delete', 'scope' => 'failed', 'imageId' => $id, 'file' => $found['file']]);
    }
} else {
    $q = Queue::get();
    $found = null; $idx = -1;
    foreach ($q['items'] as $i => $it) { if ($it['id'] === $id) { $found = $it; $idx = $i; break; } }
    if ($found) {
        $inbox = __DIR__ . '/../data/inbox/' . $found['file'];
        if (is_file($inbox)) @unlink($inbox);
        $thumb = __DIR__ . '/../data/thumbs/' . $id . '.jpg';
        if (is_file($thumb)) @unlink($thumb);
        array_splice($q['items'], $idx, 1);
        Queue::save($q);
        Logger::op(['event' => 'delete', 'scope' => 'queue', 'imageId' => $id, 'file' => $found['file']]);
    }
}

Util::jsonResponse(['status' => 'ok']);


