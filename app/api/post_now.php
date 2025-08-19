<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Csrf;
use App\Lib\ImageProc;
use App\Lib\Lock;
use App\Lib\Logger;
use App\Lib\Queue;
use App\Lib\Settings;
use App\Lib\TitleLLM;
use App\Lib\Util;
use App\Lib\XClient;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) Util::jsonResponse(['error' => 'auth'], 401);
Csrf::validate();

if (!Lock::acquire('post.lock')) Util::jsonResponse(['error' => 'locked'], 423);

try {
    $cfg = Settings::get();
    $q = Queue::get();
    $item = $q['items'][0] ?? null;
    if (!$item) Util::jsonResponse(['status' => 'empty']);
    $id = $item['id'];
    $inboxPath = __DIR__ . '/../data/inbox/' . $item['file'];

    // 2) Title & hashtags
    $preview = null;
    $title = '';
    $hashtags = [];
    $tagsFile = __DIR__ . '/../config/' . ($cfg['post']['hashtags']['source'] ?? 'tags.txt');
    $tags = array_values(array_filter(array_map('trim', file_exists($tagsFile) ? file($tagsFile) : [])));
    $min = (int)$cfg['post']['hashtags']['min'];
    $max = (int)$cfg['post']['hashtags']['max'];
    $num = max($min, min($max, random_int($min, $max)));
    if (!isset($cfg['post']['title']['enabled']) || $cfg['post']['title']['enabled']) {
        $preview = ImageProc::makeLLMPreview($inboxPath, $cfg['imagePolicy'], $id);
        $both = TitleLLM::generateAndPickTags($preview, $cfg['post']['title'], (int)$cfg['post']['textMax'], $cfg['post']['title']['ngWords'] ?? [], $tags, $num);
        $title = trim((string)($both['title'] ?? ''));
        $picked = is_array($both['tags'] ?? null) ? $both['tags'] : [];
        $hashtags = array_map(fn($t) => '#' . preg_replace('/\s+/', '', (string)$t), $picked);
    } else {
        // LLM disabled: old behavior (random hashtags only)
        shuffle($tags);
        $picked = array_slice($tags, 0, $num);
        $hashtags = array_map(fn($t) => '#' . preg_replace('/\s+/', '', (string)$t), $picked);
    }

    // 4) text (hashtags + newline + title)
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

    // 5) image optimize
    try {
        $tweetPath = ImageProc::makeTweetImage($inboxPath, $cfg['imagePolicy'], $id);
    } catch (\Throwable $e) {
        Logger::post(['level' => 'error', 'event' => 'optimize.fail', 'imageId' => $id, 'file' => $item['file'], 'error' => $e->getMessage()]);
        Util::jsonResponse(['error' => 'optimize_fail'], 500);
    }

    // 6-7) X upload + post
    $client = new XClient();
    $mediaId = $client->uploadMedia($tweetPath);
    $res = $client->postTweet($text, $mediaId);

    // 8) cleanup
    if (!empty($cfg['post']['deleteOriginalOnSuccess'])) {
        @unlink($inboxPath);
    }
    array_shift($q['items']);
    Queue::save($q);

    // tmp cleanup
    @unlink($tweetPath);
    if (!empty($preview)) { @unlink($preview); }

    // update state to avoid immediate scheduler double-post
    $stateFile = __DIR__ . '/../data/meta/state.json';
    $state = Util::readJson($stateFile, ['lastPostAt' => 0, 'lastFixedSlotTs' => 0, 'scheduleHash' => '']);
    $nowTs = time();
    $state['lastPostAt'] = $nowTs;
    $tz = new \DateTimeZone($cfg['timezone'] ?? 'Asia/Tokyo');
    $date = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
    // Mark the latest fixed-time slot up to now as consumed, so scheduler won't double-post
    $slots = [];
    foreach (($cfg['schedule']['fixedTimes'] ?? []) as $t) {
        $dt = new \DateTimeImmutable($date . ' ' . $t, $tz);
        $slots[] = $dt->getTimestamp();
    }
    sort($slots);
    $lastFixed = (int)($state['lastFixedSlotTs'] ?? 0);
    foreach ($slots as $slotTs) {
        if ($slotTs <= $nowTs && $slotTs > $lastFixed) {
            $lastFixed = $slotTs;
        }
    }
    $state['lastFixedSlotTs'] = $lastFixed;
    Util::writeJson($stateFile, $state);

    Logger::post(['level' => 'info', 'event' => 'posted', 'imageId' => $id, 'file' => $item['file'], 'tweet' => $res]);
    Util::jsonResponse(['status' => 'ok', 'tweet' => $res]);
} catch (\Throwable $e) {
    Logger::post(['level' => 'error', 'event' => 'post.fail', 'error' => $e->getMessage()]);
    Util::jsonResponse(['error' => 'post_fail', 'message' => $e->getMessage()], 500);
} finally {
    Lock::release('post.lock');
}


