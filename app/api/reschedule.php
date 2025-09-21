<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Csrf;
use App\Lib\Settings;
use App\Lib\Util;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) Util::jsonResponse(['error' => 'auth'], 401);
Csrf::validate();

$cfg = Settings::get();
$mode = (string)($cfg['schedule']['mode'] ?? 'fixed');
if ($mode !== 'per_day') {
    Util::jsonResponse(['error' => 'bad_mode', 'message' => 'per_day mode only'], 400);
}

$stateFile = __DIR__ . '/../data/meta/state.json';
$state = Util::readJson($stateFile, [
    'lastPostAt' => 0,
    'lastFixedSlotTs' => 0,
    'scheduleHash' => '',
    'dailyPlanDate' => '',
    'dailyPlanSlots' => [],
    'lastDailySlotTs' => 0,
]);

$tz = new DateTimeZone($cfg['timezone'] ?? 'UTC');
$now = Util::now($cfg['timezone'] ?? 'UTC');
$date = $now->format('Y-m-d');
$todayStart = (new DateTimeImmutable($date . ' 00:00:00', $tz))->getTimestamp();
$tomorrowStart = (new DateTimeImmutable($date . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp();
$dayLen = max(1, $tomorrowStart - $todayStart);

$count = (int)($cfg['schedule']['perDayCount'] ?? 0);
$count = max(1, min(24, $count));
$minSpacingSec = max(0, (int)($cfg['schedule']['minSpacingMinutes'] ?? 0) * 60);

// random-phase bins
$step = intdiv($dayLen, $count);
$margin = max(0, intdiv($step, 10));
$effectiveMin = min($minSpacingSec, max(0, $step - $margin));
$slots = [];
for ($i = 0; $i < $count; $i++) {
    $binStart = $todayStart + $i * $step;
    $lo = $binStart + $margin;
    $hi = min($tomorrowStart - 1, $binStart + $step - $margin);
    if ($hi <= $lo) { $lo = $binStart; $hi = min($tomorrowStart - 1, $binStart + $step - 1); }
    $slots[] = random_int($lo, $hi);
}
sort($slots);
for ($i = 1; $i < count($slots); $i++) {
    if ($slots[$i] - $slots[$i-1] < $effectiveMin) {
        $slots[$i] = $slots[$i-1] + $effectiveMin;
    }
}
for ($i = 0; $i < count($slots); $i++) {
    if ($slots[$i] >= $tomorrowStart) $slots[$i] = $tomorrowStart - 1;
}

$state['dailyPlanDate'] = $date;
$state['dailyPlanSlots'] = $slots;
$state['lastDailySlotTs'] = 0;
Util::writeJson($stateFile, $state);

Util::jsonResponse(['status' => 'ok', 'date' => $date, 'slots' => $slots]);


