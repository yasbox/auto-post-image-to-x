<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Csrf;
use App\Lib\ImageProc;
use App\Lib\Lock;
use App\Lib\Logger;
use App\Lib\Queue;
use App\Lib\Failed;
use App\Lib\SensitiveDetector;
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

    // 1) Log post start (for clear log separation)
    Logger::post(['level' => 'info', 'event' => 'post.start', 'imageId' => $id, 'file' => $item['file']]);

    // 2) Title (LLM) & hashtags (random only)
    $preview = null;
    $title = '';
    $hashtags = [];
    $tagsFile = __DIR__ . '/../config/' . ($cfg['post']['hashtags']['source'] ?? 'tags.txt');
    $tags = array_values(array_filter(array_map(fn($t) => preg_replace('/\s+/', ' ', ltrim(trim((string)$t), '#')), file_exists($tagsFile) ? file($tagsFile) : [])));
    $min = (int)$cfg['post']['hashtags']['min'];
    $max = (int)$cfg['post']['hashtags']['max'];
    $num = max($min, min($max, random_int($min, $max)));
    if (!isset($cfg['post']['title']['enabled']) || $cfg['post']['title']['enabled']) {
        $preview = ImageProc::makeLLMPreview($inboxPath, $cfg['imagePolicy'], $id);
        $title = trim((string) TitleLLM::generateTitle($preview, $cfg['post']['title'], $cfg['post']['title']['ngWords'] ?? []));
    }
    // hashtags: always random selection from tags list
    shuffle($tags);
    $picked = array_slice($tags, 0, $num);
    $hashtags = array_map(fn($t) => '#' . preg_replace('/\s+/', '', (string)$t), $picked);

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
        // move to failed, remove from queue, keep original
        Failed::append($id, $item['file'], 'optimize', $e->getMessage());
        \App\Lib\Logger::op(['event' => 'failed.append', 'imageId' => $id, 'file' => $item['file'], 'stage' => 'optimize']);
        array_shift($q['items']);
        Queue::save($q);
        // also advance scheduling state to avoid double attempts
        $stateFile = __DIR__ . '/../data/meta/state.json';
        $state = Util::readJson($stateFile, ['lastPostAt' => 0, 'lastFixedSlotTs' => 0, 'scheduleHash' => '']);
        $state['lastPostAt'] = time();
        Util::writeJson($stateFile, $state);
        Util::jsonResponse(['error' => 'optimize_fail'], 500);
    }

    // 5.5) センシティブ判定
    $isSensitive = false;
    $sensitiveScore = 0;
    if (!empty($cfg['sensitiveDetection']['enabled'])) {
        $provider = (string)($cfg['sensitiveDetection']['provider'] ?? 'gemini');
        $threshold = (int)($cfg['sensitiveDetection']['threshold'] ?? 61);
        $adultContentThreshold = (int)($cfg['sensitiveDetection']['adultContentThreshold'] ?? 71);
        $enableFallback = !empty($cfg['sensitiveDetection']['fallbackProvider']);
        
        try {
            $result = SensitiveDetector::analyzeWithFallback($tweetPath, $provider, $enableFallback);
            
            if (isset($result['error']) && $result['error']) {
                // API失敗 → アウト判定として失敗一覧に追加
                Logger::post(['level' => 'error', 'event' => 'sensitive_check.fail', 
                             'imageId' => $id, 'error' => $result['message']]);
                Failed::append($id, $item['file'], 'sensitive_check', 
                              'センシティブ判定APIエラー: ' . $result['message']);
                \App\Lib\Logger::op(['event' => 'failed.append', 'imageId' => $id, 'file' => $item['file'], 'stage' => 'sensitive_check']);
                array_shift($q['items']);
                Queue::save($q);
                @unlink($tweetPath);
                if (!empty($preview)) { @unlink($preview); }
                // also advance scheduling state
                $stateFile = __DIR__ . '/../data/meta/state.json';
                $state = Util::readJson($stateFile, ['lastPostAt' => 0, 'lastFixedSlotTs' => 0, 'scheduleHash' => '']);
                $state['lastPostAt'] = time();
                Util::writeJson($stateFile, $state);
                Util::jsonResponse(['error' => 'sensitive_check_fail', 'message' => $result['message']], 500);
            }
            
            $sensitiveScore = (int)$result['score'];
            $isSensitive = $sensitiveScore >= $threshold;
            $category = $isSensitive ? (($sensitiveScore >= $adultContentThreshold) ? 'adult_content' : 'other') : 'none';
            Logger::post([
                'level' => 'info', 
                'event' => 'sensitive_check.done',
                'imageId' => $id,
                'provider' => $result['provider'] ?? $provider,
                'score' => $sensitiveScore,
                'judgment' => $result['judgment'],
                'reason' => $result['reason'],
                'flagged' => $isSensitive,
                'category' => $category,
                'threshold' => $threshold,
                'adultThreshold' => $adultContentThreshold,
                'elapsedMs' => $result['elapsedMs'] ?? 0
            ]);
            
        } catch (\Throwable $e) {
            // 予期せぬエラー → アウト判定
            Logger::post(['level' => 'error', 'event' => 'sensitive_check.exception', 
                         'imageId' => $id, 'error' => $e->getMessage()]);
            Failed::append($id, $item['file'], 'sensitive_check', 
                          'センシティブ判定エラー: ' . $e->getMessage());
            \App\Lib\Logger::op(['event' => 'failed.append', 'imageId' => $id, 'file' => $item['file'], 'stage' => 'sensitive_check']);
            array_shift($q['items']);
            Queue::save($q);
            @unlink($tweetPath);
            if (!empty($preview)) { @unlink($preview); }
            // also advance scheduling state
            $stateFile = __DIR__ . '/../data/meta/state.json';
            $state = Util::readJson($stateFile, ['lastPostAt' => 0, 'lastFixedSlotTs' => 0, 'scheduleHash' => '']);
            $state['lastPostAt'] = time();
            Util::writeJson($stateFile, $state);
            Util::jsonResponse(['error' => 'sensitive_check_exception', 'message' => $e->getMessage()], 500);
        }
    }

    // 6-7) X upload + post
    $client = new XClient();
    $mediaId = $client->uploadMedia($tweetPath);
    // センシティブメタデータを設定（スコアと成人向け閾値も渡す）
    $adultThreshold = isset($adultContentThreshold) ? $adultContentThreshold : 71;
    $client->setMediaMetadata($mediaId, $isSensitive, $sensitiveScore, $adultThreshold);
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
    // enrich log with image info when available
    $log = ['level' => 'error', 'event' => 'post.fail', 'error' => $e->getMessage()];
    if (isset($item) && isset($item['id'], $item['file'])) { $log['imageId'] = $item['id']; $log['file'] = $item['file']; }
    Logger::post($log);
    // move to failed list and remove from queue
    if (isset($item) && isset($item['id'], $item['file'])) {
        Failed::append($item['id'], $item['file'], 'post', $e->getMessage());
        \App\Lib\Logger::op(['event' => 'failed.append', 'imageId' => $item['id'], 'file' => $item['file'], 'stage' => 'post']);
        array_shift($q['items']);
        Queue::save($q);
    }
    // tmp cleanup if created
    if (isset($tweetPath)) { @unlink($tweetPath); }
    if (isset($preview) && !empty($preview)) { @unlink($preview); }
    // advance state
    $stateFile = __DIR__ . '/../data/meta/state.json';
    $state = Util::readJson($stateFile, ['lastPostAt' => 0, 'lastFixedSlotTs' => 0, 'scheduleHash' => '']);
    $state['lastPostAt'] = time();
    Util::writeJson($stateFile, $state);
    Util::jsonResponse(['error' => 'post_fail', 'message' => $e->getMessage()], 500);
} finally {
    Lock::release('post.lock');
}


