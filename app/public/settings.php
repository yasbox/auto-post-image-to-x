<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Csrf;
use App\Lib\Settings;
use App\Lib\Util;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
]);

if (!Auth::isLoggedIn()) { header('Location: /'); exit; }

$cfg = Settings::get();

?><!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>設定 - X Auto Poster</title>
  <script src="/assets/vendor/tailwindcdn.js"></script>
  <link rel="stylesheet" href="/assets/app.css" />
</head>
<body class="bg-gray-100">
<div class="container mx-auto max-w-4xl p-6">
  <div class="flex items-center mb-6">
    <h1 class="text-2xl font-bold tracking-tight">設定</h1>
    <a href="/" class="ml-auto btn-base btn-ghost px-4 py-2">← 戻る</a>
  </div>

  <form id="settings-form" class="space-y-6 max-w-3xl">
    <input type="hidden" id="csrf" value="<?php echo htmlspecialchars(Csrf::issue(), ENT_QUOTES, 'UTF-8'); ?>" />

    <section class="card p-5">
      <h2 class="font-semibold mb-3 tracking-tight">スケジュール</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <label class="block md:col-span-2">モード
          <select id="schedule_mode" class="border rounded p-2 w-full">
            <option value="fixed">固定時刻</option>
            <option value="interval">一定間隔</option>
            <option value="both">両方</option>
          </select>
        </label>
        <label class="block">固定時刻（カンマ区切り HH:MM）
          <input type="text" id="fixed" class="border rounded p-2 w-full" placeholder="09:00, 13:00, 21:00" />
        </label>
        <label class="block">間隔（分）
          <input type="number" id="interval" class="border rounded p-2 w-full" min="0" />
        </label>
      </div>
    </section>

    <section class="card p-5">
      <h2 class="font-semibold mb-3 tracking-tight">アップロード</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <label class="block">チャンクサイズ（bytes）
          <input type="number" id="chunkSize" class="border rounded p-2 w-full" />
        </label>
        <label class="block">同時アップロード数
          <input type="number" id="concurrency" class="border rounded p-2 w-full" />
        </label>
        <label class="block md:col-span-2">許可MIME（カンマ区切り）
          <input type="text" id="allowedMime" class="border rounded p-2 w-full" />
        </label>
      </div>
    </section>

    <section class="card p-5">
      <h2 class="font-semibold mb-3 tracking-tight">投稿テキスト</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <label class="block">タイトル最大文字数
          <input type="number" id="title_max" class="border rounded p-2 w-full" />
        </label>
        <label class="block">トーン
          <input type="text" id="title_tone" class="border rounded p-2 w-full" />
        </label>
        <label class="block md:col-span-2">NGワード（カンマ区切り）
          <input type="text" id="title_ng" class="border rounded p-2 w-full" />
        </label>
        <label class="block">本文最大文字数
          <input type="number" id="textMax" class="border rounded p-2 w-full" />
        </label>
      </div>
    </section>

    <section class="card p-5">
      <h2 class="font-semibold mb-3 tracking-tight">ハッシュタグ</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <label class="block">最小
          <input type="number" id="tag_min" class="border rounded p-2 w-full" />
        </label>
        <label class="block">最大
          <input type="number" id="tag_max" class="border rounded p-2 w-full" />
        </label>
        <label class="block">先頭に付与
          <select id="tag_prepend" class="border rounded p-2 w-full"><option value="true">はい</option><option value="false">いいえ</option></select>
        </label>
        <label class="block md:col-span-3">タグリスト（カンマ区切り）
          <textarea id="tagsText" class="border rounded p-2 w-full h-32" placeholder="# は不要。例\nAIart, AIgirl, AIphoto"></textarea>
        </label>
      </div>
      <p class="text-sm text-gray-600 mt-1">保存すると `app/config/tags.txt` に1行ずつの形式で保存されます。</p>
    </section>

    <section class="card p-5">
      <h2 class="font-semibold mb-3 tracking-tight">画像最適化（5MB対応）</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <label class="block">最大バイト
          <input type="number" id="tweetMaxBytes" class="border rounded p-2 w-full" />
        </label>
        <label class="block">最大辺
          <input type="number" id="tweetMaxLongEdge" class="border rounded p-2 w-full" />
        </label>
        <label class="block">品質（最小〜最大）
          <div class="flex gap-2"><input type="number" id="tweetQualityMin" class="border rounded p-2 w-full" /><input type="number" id="tweetQualityMax" class="border rounded p-2 w-full" /></div>
        </label>
      </div>
    </section>

    <div class="flex gap-3">
      <button type="button" id="btn-save" class="btn-base btn-primary px-5 py-2">保存</button>
      <span id="msg" class="text-sm text-gray-600 self-center"></span>
    </div>
  </form>

  <script>
    const csrfToken = document.getElementById('csrf').value;

    async function apiGet(url){
      const res = await fetch(url, {headers: {'X-CSRF-Token': csrfToken}});
      return await res.json();
    }
    async function apiPost(url, body){
      const res = await fetch(url, {
        method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify(body)
      });
      return await res.json();
    }

    function loadIntoForm(cfg){
      document.getElementById('schedule_mode').value = cfg.schedule.mode;
      document.getElementById('interval').value = cfg.schedule.intervalMinutes;
      document.getElementById('fixed').value = (cfg.schedule.fixedTimes || []).join(', ');
      document.getElementById('chunkSize').value = cfg.upload.chunkSize;
      document.getElementById('concurrency').value = cfg.upload.concurrency;
      document.getElementById('allowedMime').value = (cfg.upload.allowedMime || []).join(', ');
      document.getElementById('title_max').value = cfg.post.title.maxChars;
      document.getElementById('title_tone').value = cfg.post.title.tone;
      document.getElementById('title_ng').value = (cfg.post.title.ngWords || []).join(', ');
      document.getElementById('textMax').value = cfg.post.textMax;
      document.getElementById('tag_min').value = cfg.post.hashtags.min;
      document.getElementById('tag_max').value = cfg.post.hashtags.max;
      document.getElementById('tag_prepend').value = cfg.post.hashtags.prepend ? 'true' : 'false';
      document.getElementById('tweetMaxBytes').value = cfg.imagePolicy.tweetMaxBytes;
      document.getElementById('tweetMaxLongEdge').value = cfg.imagePolicy.tweetMaxLongEdge;
      document.getElementById('tweetQualityMin').value = cfg.imagePolicy.tweetQualityMin;
      document.getElementById('tweetQualityMax').value = cfg.imagePolicy.tweetQualityMax;
      if (cfg.tagsText !== undefined) {
        document.getElementById('tagsText').value = cfg.tagsText;
      }
    }

    function collectFromForm(cur){
      return {
        ...cur,
        schedule: {
          ...cur.schedule,
          mode: document.getElementById('schedule_mode').value,
          fixedTimes: document.getElementById('fixed').value.split(',').map(s=>s.trim()).filter(Boolean),
          intervalMinutes: parseInt(document.getElementById('interval').value || '0', 10)
        },
        upload: {
          ...cur.upload,
          chunkSize: parseInt(document.getElementById('chunkSize').value || '0', 10),
          concurrency: parseInt(document.getElementById('concurrency').value || '0', 10),
          allowedMime: document.getElementById('allowedMime').value.split(',').map(s=>s.trim()).filter(Boolean)
        },
        post: {
          ...cur.post,
          title: {
            ...cur.post.title,
            maxChars: parseInt(document.getElementById('title_max').value || '0', 10),
            tone: document.getElementById('title_tone').value,
            ngWords: document.getElementById('title_ng').value.split(',').map(s=>s.trim()).filter(Boolean)
          },
          textMax: parseInt(document.getElementById('textMax').value || '0', 10),
          hashtags: {
            ...cur.post.hashtags,
            min: parseInt(document.getElementById('tag_min').value || '0', 10),
            max: parseInt(document.getElementById('tag_max').value || '0', 10),
            prepend: document.getElementById('tag_prepend').value === 'true'
          }
        },
        imagePolicy: {
          ...cur.imagePolicy,
          tweetMaxBytes: parseInt(document.getElementById('tweetMaxBytes').value || '0', 10),
          tweetMaxLongEdge: parseInt(document.getElementById('tweetMaxLongEdge').value || '0', 10),
          tweetQualityMin: parseInt(document.getElementById('tweetQualityMin').value || '0', 10),
          tweetQualityMax: parseInt(document.getElementById('tweetQualityMax').value || '0', 10)
        },
        tagsText: (document.getElementById('tagsText').value || '').replace(/\r\n/g, '\n')
      };
    }

    (async () => {
      const cur = await apiGet('/api/settings_get.php');
      loadIntoForm(cur);
      document.getElementById('btn-save').onclick = async () => {
        const updated = collectFromForm(cur);
        await apiPost('/api/settings_set.php', updated);
        const msg = document.getElementById('msg'); msg.textContent = '保存しました'; setTimeout(()=>msg.textContent='', 1500);
      };
    })();
  </script>
</div>
</body>
</html>


