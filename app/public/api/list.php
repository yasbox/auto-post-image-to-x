<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Logger;
use App\Lib\Queue;
use App\Lib\Failed;
use App\Lib\Settings;
use App\Lib\Util;

require_once __DIR__ . '/../../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) Util::jsonResponse(['error' => 'auth'], 401);

$q = Queue::get();
$items = [];
$pruned = false;
foreach ($q['items'] as $it) {
    $path = __DIR__ . '/../../data/inbox/' . $it['file'];
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

// Failed list
$f = Failed::get();
$failedItems = [];
$failedPruned = false;
foreach ($f['items'] as $it) {
    $path = __DIR__ . '/../../data/inbox/' . $it['file'];
    if (is_file($path)) {
        $it['size'] = filesize($path);
        $dim = @getimagesize($path);
        if ($dim) { $it['width'] = $dim[0]; $it['height'] = $dim[1]; }
        $failedItems[] = $it;
    } else {
        $failedPruned = true;
    }
}
if ($failedPruned) {
    $f['items'] = $failedItems;
    Failed::save($f);
    Logger::op(['event' => 'failed.prune', 'removed' => true, 'remaining' => count($failedItems)]);
}

// Compute approximate remaining days based on schedule
$aboutDays = null;
try {
    $cfg = Settings::get();
    $enabled = !isset($cfg['schedule']['enabled']) || (bool)$cfg['schedule']['enabled'];
    if ($enabled) {
        $mode = (string)($cfg['schedule']['mode'] ?? 'both');
        $fixedTimes = is_array($cfg['schedule']['fixedTimes'] ?? null) ? $cfg['schedule']['fixedTimes'] : [];
        $intervalMinutes = (int)($cfg['schedule']['intervalMinutes'] ?? 0);
        $fixedCount = count(array_filter($fixedTimes, fn($t) => is_string($t) && $t !== ''));
        $intervalRate = $intervalMinutes > 0 ? (1440.0 / $intervalMinutes) : 0.0; // can be < 1
        $postsPerDay = 0.0;
        if ($mode === 'fixed') {
            $postsPerDay = $fixedCount;
        } elseif ($mode === 'interval') {
            $postsPerDay = $intervalRate;
        } else { // both
            // Use sum to reflect both schedules potentially triggering within a day
            $postsPerDay = $fixedCount + $intervalRate;
        }
        if ($postsPerDay > 0) {
            $aboutDays = (int)ceil(count($items) / $postsPerDay);
        }
    }
} catch (Throwable $e) {
    // ignore estimation errors
}

Util::jsonResponse([
    'items' => $items,
    'count' => count($items),
    'failed' => $failedItems,
    'failedCount' => count($failedItems),
    'aboutDays' => $aboutDays,
]);

