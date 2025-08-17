<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Logger;
use App\Lib\Queue;
use App\Lib\Settings;
use App\Lib\Util;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) Util::jsonResponse(['error' => 'auth'], 401);

$q = Queue::get();
$items = [];
$pruned = false;
foreach ($q['items'] as $it) {
    $path = __DIR__ . '/../data/inbox/' . $it['file'];
    if (is_file($path)) {
        $it['size'] = filesize($path);
        $dim = @getimagesize($path);
        if ($dim) { $it['width'] = $dim[0]; $it['height'] = $dim[1]; }
        $items[] = $it;
    } else {
        $pruned = true; // dangling entry (file missing)
    }
}
if ($pruned) {
    $q['items'] = $items;
    Queue::save($q);
    Logger::op(['event' => 'list.prune', 'removed' => true, 'remaining' => count($items)]);
}
Util::jsonResponse(['items' => $items, 'count' => count($items)]);


