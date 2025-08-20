<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Csrf;
use App\Lib\Logger;
use App\Lib\Queue;
use App\Lib\Failed;
use App\Lib\Settings;
use App\Lib\Util;

require_once __DIR__ . '/../../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) Util::jsonResponse(['error' => 'auth'], 401);
Csrf::validate();

$data = json_decode(file_get_contents('php://input') ?: 'null', true);
$id = (string)($data['id'] ?? '');
$position = (string)($data['position'] ?? 'head');
if ($id === '') Util::jsonResponse(['error' => 'bad_request'], 400);

$f = Failed::get();
$found = null; $idx = -1;
foreach ($f['items'] as $i => $it) { if (($it['id'] ?? '') === $id) { $found = $it; $idx = $i; break; } }
if (!$found) Util::jsonResponse(['error' => 'not_found'], 404);

$inbox = __DIR__ . '/../../data/inbox/' . $found['file'];
if (!is_file($inbox)) {
    // prune stale failed entry
    array_splice($f['items'], $idx, 1);
    Failed::save($f);
    Logger::op(['event' => 'restore.missing', 'imageId' => $id, 'file' => $found['file']]);
    Util::jsonResponse(['error' => 'file_missing'], 404);
}

// Move to queue
$q = Queue::get();
$q['items'] = array_values(array_filter($q['items'], fn($i) => ($i['id'] ?? '') !== $id));
$newItem = [
    'id' => $id,
    'file' => $found['file'],
    'addedAt' => time(),
];
if ($position === 'head') { array_unshift($q['items'], $newItem); }
else { $q['items'][] = $newItem; }
Queue::save($q);

// Remove from failed
array_splice($f['items'], $idx, 1);
Failed::save($f);

Logger::op(['event' => 'restore', 'imageId' => $id, 'file' => $found['file'], 'position' => $position]);
Util::jsonResponse(['status' => 'ok']);


