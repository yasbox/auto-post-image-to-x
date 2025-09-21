<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Settings;
use App\Lib\Util;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) Util::jsonResponse(['error' => 'auth'], 401);

$cfg = Settings::get();
$mode = (string)($cfg['schedule']['mode'] ?? 'fixed');
$tz = (string)($cfg['timezone'] ?? 'UTC');
$now = Util::now($tz);
$nowTs = $now->getTimestamp();
$date = $now->format('Y-m-d');

$stateFile = __DIR__ . '/../data/meta/state.json';
$state = Util::readJson($stateFile, [
    'dailyPlanDate' => '',
    'dailyPlanSlots' => [],
    'lastDailySlotTs' => 0,
]);

$slots = [];
$ephemeral = false;

if ($mode === 'per_day') {
    if (($state['dailyPlanDate'] ?? '') === $date && is_array($state['dailyPlanSlots'] ?? null)) {
        $slots = $state['dailyPlanSlots'];
    } else {
        // Fallback: ephemeral preview plan (not saved)
        $ephemeral = true;
        $tzObj = new DateTimeZone($tz);
        $todayStart = (new DateTimeImmutable($date . ' 00:00:00', $tzObj))->getTimestamp();
        $tomorrowStart = (new DateTimeImmutable($date . ' 00:00:00', $tzObj))->modify('+1 day')->getTimestamp();
        $dayLen = max(1, $tomorrowStart - $todayStart);
        $count = max(1, min(24, (int)($cfg['schedule']['perDayCount'] ?? 3)));
        $step = intdiv($dayLen, $count);
        $margin = max(0, intdiv($step, 10));
        for ($i=0; $i<$count; $i++) {
            $binStart = $todayStart + $i*$step;
            $lo = $binStart + $margin;
            $hi = min($tomorrowStart - 1, $binStart + $step - $margin);
            if ($hi <= $lo) { $lo = $binStart; $hi = min($tomorrowStart - 1, $binStart + $step - 1); }
            $slots[] = random_int($lo, $hi);
        }
        sort($slots);
    }
}

$lastConsumed = (int)($state['lastDailySlotTs'] ?? 0);
$tzObj = new DateTimeZone($tz);
$items = [];
foreach ($slots as $ts) {
    $dt = (new DateTimeImmutable('@' . (int)$ts))->setTimezone($tzObj);
    $status = ($ts <= $lastConsumed) ? 'done' : (($ts <= $nowTs) ? 'past' : 'upcoming');
    $items[] = [
        'ts' => (int)$ts,
        'time' => $dt->format('H:i'),
        'status' => $status,
    ];
}

Util::jsonResponse([
    'mode' => $mode,
    'date' => $date,
    'tz' => $tz,
    'items' => $items,
    'ephemeral' => $ephemeral,
]);


