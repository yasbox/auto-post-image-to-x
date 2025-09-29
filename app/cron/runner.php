<?php
declare(strict_types=1);

use App\Lib\ImageProc;
use App\Lib\Lock;
use App\Lib\Logger;
use App\Lib\Queue;
use App\Lib\Failed;
use App\Lib\Settings;
use App\Lib\TitleLLM;
use App\Lib\Util;
use App\Lib\XClient;

require_once __DIR__ . '/../lib/bootstrap.php';

// Simple state file to track last post and fixed-time scheduling state
$stateFile = __DIR__ . '/../data/meta/state.json';
$state = Util::readJson($stateFile, [
    'lastPostAt' => 0,
    // Timestamp (epoch seconds) of the last executed fixed-time slot
    'lastFixedSlotTs' => 0,
    // Hash of schedule relevant fields to detect changes and avoid catch-up
    'scheduleHash' => '',
    // Per-day mode plan
    'dailyPlanDate' => '',
    'dailyPlanSlots' => [],
    'lastDailySlotTs' => 0,
]);
$cfg = Settings::get();
$now = Util::now($cfg['timezone']);
$nowTs = $now->getTimestamp();

if (!Lock::acquire('post.lock')) { Logger::op(['event' => 'runner.skip.lock', 'nowTs' => $nowTs]); exit(0); }

try {
    if (isset($cfg['schedule']['enabled']) && !$cfg['schedule']['enabled']) { Logger::op(['event' => 'runner.skip.disabled']); exit(0); }
    $due = false;
    $mode = $cfg['schedule']['mode'] ?? 'fixed';
    if ($mode === 'both') { $mode = 'fixed'; }

    // Fixed times (timestamp-based, with schedule change detection)
    $date = $now->format('Y-m-d');
    if ($mode === 'fixed') {
        $fixedTimes = $cfg['schedule']['fixedTimes'] ?? [];
        $scheduleHashNow = hash('sha256', json_encode([
            'tz' => $cfg['timezone'] ?? 'UTC',
            'times' => $fixedTimes,
            // include mode in hash so switching from interval<->fixed advances baseline to now
            'mode' => $mode,
        ]));

        // If schedule changed (times or timezone), advance baseline to now to avoid catch-up posts
        if (($state['scheduleHash'] ?? '') !== $scheduleHashNow) {
            $state['scheduleHash'] = $scheduleHashNow;
            $state['lastFixedSlotTs'] = $nowTs;
            // Persist immediately so the new baseline sticks even if no post occurs in this run
            Util::writeJson($stateFile, $state);
        }

        // Build today's slot timestamps and pick the earliest slot that is > lastFixedSlotTs and <= now
        $slots = [];
        foreach ($fixedTimes as $t) {
            $dt = new DateTimeImmutable($date . ' ' . $t, new DateTimeZone($cfg['timezone']));
            $slots[] = $dt->getTimestamp();
        }
        sort($slots);
        $lastFixedSlotTs = (int)($state['lastFixedSlotTs'] ?? 0);
        foreach ($slots as $slotTs) {
            if ($slotTs > $lastFixedSlotTs && $slotTs <= $nowTs) {
                $due = true;
                $state['lastFixedSlotTs'] = $slotTs; // mark the consumed slot for today
                break;
            }
        }
    }

    // Per-day count (random phase + min spacing, daily plan)
    if (!$due && $mode === 'per_day') {
        $count = (int)($cfg['schedule']['perDayCount'] ?? 0);
        $count = max(1, min(24, $count));
        $minSpacingSec = max(0, (int)($cfg['schedule']['minSpacingMinutes'] ?? 0) * 60);

        // Detect changes (tz/mode/count/minSpacing)
        $scheduleHashNow = hash('sha256', json_encode([
            'tz' => $cfg['timezone'] ?? 'UTC',
            'mode' => $mode,
            'count' => $count,
            'minSpacingSec' => $minSpacingSec,
        ]));

        // Day boundaries in TZ (DST-aware)
        $tz = new DateTimeZone($cfg['timezone'] ?? 'UTC');
        $todayStart = (new DateTimeImmutable($date . ' 00:00:00', $tz))->getTimestamp();
        $tomorrowStart = (new DateTimeImmutable($date . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp();
        $dayLen = max(1, $tomorrowStart - $todayStart); // 23/24/25h day

        $needRegen = false;
        if (($state['scheduleHash'] ?? '') !== $scheduleHashNow) { $needRegen = true; }
        if (($state['dailyPlanDate'] ?? '') !== $date) { $needRegen = true; }
        if (!is_array($state['dailyPlanSlots'] ?? null)) { $needRegen = true; }

        if ($needRegen) {
            // Generate plan with random phase and spacing
            $step = intdiv($dayLen, $count);
            $margin = max(0, intdiv($step, 10)); // 10% margin to avoid edges
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
            // Greedy adjust to enforce effectiveMin
            for ($i = 1; $i < count($slots); $i++) {
                if ($slots[$i] - $slots[$i-1] < $effectiveMin) {
                    $slots[$i] = $slots[$i-1] + $effectiveMin;
                }
            }
            // Clamp within the day
            for ($i = 0; $i < count($slots); $i++) {
                if ($slots[$i] >= $tomorrowStart) {
                    $slots[$i] = $tomorrowStart - 1;
                }
            }

            $state['dailyPlanDate'] = $date;
            $state['dailyPlanSlots'] = $slots;
            // Mark past slots as consumed to avoid catch-up bursts
            $baselineTs = 0;
            foreach ($slots as $s) { if ($s <= $nowTs && $s > $baselineTs) { $baselineTs = $s; } }
            $state['lastDailySlotTs'] = $baselineTs;
            $state['scheduleHash'] = $scheduleHashNow;
            Util::writeJson($stateFile, $state);
            Logger::op(['event' => 'daily_plan_regen', 'count' => $count, 'minSpacingSec' => $minSpacingSec, 'effectiveMinSec' => $effectiveMin, 'slots' => $slots]);
        }

        $slots = is_array($state['dailyPlanSlots'] ?? null) ? $state['dailyPlanSlots'] : [];
        sort($slots);
        $lastDaily = (int)($state['lastDailySlotTs'] ?? 0);
        foreach ($slots as $slotTs) {
            if ($slotTs > $lastDaily && $slotTs <= $nowTs) {
                // Enforce min spacing vs lastPostAt; if too soon, skip (do not consume)
                if ($minSpacingSec > 0 && ($nowTs - (int)$state['lastPostAt']) < $minSpacingSec) {
                    break; // not due yet due to spacing
                }
                $due = true;
                $state['lastDailySlotTs'] = $slotTs;
                break;
            }
        }
    }

    // Interval
    if (!$due && $mode === 'interval') {
        $interval = (int)($cfg['schedule']['intervalMinutes'] ?? 0) * 60;
        if ($interval > 0 && ($nowTs - (int)$state['lastPostAt']) >= $interval) {
            $due = true;
        }
    }

    if (!$due) {
        Logger::op([
            'event' => 'runner.skip.due',
            'mode' => $mode,
            'nowTs' => $nowTs,
            'lastPostAt' => (int)($state['lastPostAt'] ?? 0),
            'lastFixedSlotTs' => (int)($state['lastFixedSlotTs'] ?? 0),
            'intervalMinutes' => (int)($cfg['schedule']['intervalMinutes'] ?? 0),
            'perDayCount' => (int)($cfg['schedule']['perDayCount'] ?? 0),
            'minSpacingMinutes' => (int)($cfg['schedule']['minSpacingMinutes'] ?? 0),
            'fixedTimesCount' => is_array($cfg['schedule']['fixedTimes'] ?? null) ? count($cfg['schedule']['fixedTimes']) : 0,
            'timezone' => (string)($cfg['timezone'] ?? ''),
        ]);
        exit(0);
    }

    // Load queue head
    $q = Queue::get();
    $item = $q['items'][0] ?? null;
    if (!$item) { Logger::op(['event' => 'runner.skip.empty', 'queueCount' => count($q['items'] ?? [])]); exit(0); }
    $id = $item['id'];
    $inboxPath = __DIR__ . '/../data/inbox/' . $item['file'];

    // Title (LLM) & Hashtags (random only)
    $preview = null;
    $title = '';
    $hashtags = [];
    $tagsFile = __DIR__ . '/../config/' . ($cfg['post']['hashtags']['source'] ?? 'tags.txt');
    $tags = array_values(array_filter(array_map(fn($t) => preg_replace('/\s+/', ' ', ltrim(trim((string)$t), '#')), file_exists($tagsFile) ? file($tagsFile) : [])));
    $min = (int)($cfg['post']['hashtags']['min'] ?? 0);
    $max = (int)($cfg['post']['hashtags']['max'] ?? 0);
    $num = max($min, min($max, random_int($min, $max)));
    try {
        if (!isset($cfg['post']['title']['enabled']) || $cfg['post']['title']['enabled']) {
            $preview = ImageProc::makeLLMPreview($inboxPath, $cfg['imagePolicy'], $id);
            $title = trim((string) TitleLLM::generateTitle($preview, $cfg['post']['title'], $cfg['post']['title']['ngWords'] ?? []));
        }
        shuffle($tags);
        $picked = array_slice($tags, 0, $num);
        $hashtags = array_map(fn($t) => '#' . preg_replace('/\s+/', '', (string)$t), $picked);
    } catch (Throwable $e) {
        Logger::post(['level' => 'error', 'event' => 'title.fail', 'imageId' => $id, 'file' => $item['file'], 'error' => $e->getMessage()]);
        Failed::append($id, $item['file'], 'title', $e->getMessage());
        array_shift($q['items']);
        Queue::save($q);
        if (!empty($preview)) { @unlink($preview); }
        $state['lastPostAt'] = $nowTs;
        Util::writeJson($stateFile, $state);
        exit(0);
    }
    $hashtagsStr = trim(implode(' ', $hashtags));
    $titleStr = trim((string)($title ?? ''));
    if ($hashtagsStr !== '' && $titleStr !== '') {
        $text = $hashtagsStr . "\n" . $titleStr;
    } else {
        $text = $hashtagsStr . $titleStr;
    }
    if (mb_strlen($text) > (int)$cfg['post']['textMax']) {
        $text = mb_substr($text, 0, (int)$cfg['post']['textMax']);
    }

    // Image
    try { $tweetPath = ImageProc::makeTweetImage($inboxPath, $cfg['imagePolicy'], $id); }
    catch (Throwable $e) {
        Logger::post(['level' => 'error', 'event' => 'optimize.fail', 'imageId' => $id, 'file' => $item['file'], 'error' => $e->getMessage()]);
        // move to failed and remove from queue, keep original image
        Failed::append($id, $item['file'], 'optimize', $e->getMessage());
        Logger::op(['event' => 'failed.append', 'imageId' => $id, 'file' => $item['file'], 'stage' => 'optimize']);
        array_shift($q['items']);
        Queue::save($q);
        // advance schedule state
        $state['lastPostAt'] = $nowTs;
        Util::writeJson($stateFile, $state);
        exit(0);
    }

    // Post
    try {
        $client = new XClient();
        $mediaId = $client->uploadMedia($tweetPath);
        $res = $client->postTweet($text, $mediaId);
        if (!empty($cfg['post']['deleteOriginalOnSuccess'])) @unlink($inboxPath);
        array_shift($q['items']);
        Queue::save($q);
        @unlink($tweetPath);
        if (!empty($preview)) { @unlink($preview); }
        $state['lastPostAt'] = $nowTs;
        Logger::post(['level' => 'info', 'event' => 'posted', 'imageId' => $id, 'file' => $item['file'], 'tweet' => $res]);
    } catch (Throwable $e) {
        Logger::post(['level' => 'error', 'event' => 'post.fail', 'imageId' => $id, 'file' => $item['file'], 'error' => $e->getMessage()]);
        // failed: move to failed list, remove from queue, keep original
        Failed::append($id, $item['file'], 'post', $e->getMessage());
        Logger::op(['event' => 'failed.append', 'imageId' => $id, 'file' => $item['file'], 'stage' => 'post']);
        array_shift($q['items']);
        Queue::save($q);
        if (!empty($tweetPath)) { @unlink($tweetPath); }
        if (!empty($preview)) { @unlink($preview); }
        $state['lastPostAt'] = $nowTs;
    }

    Util::writeJson($stateFile, $state);
} finally {
    Lock::release('post.lock');
}


