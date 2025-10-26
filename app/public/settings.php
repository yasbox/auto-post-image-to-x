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
    'cookie_lifetime' => 60 * 60 * 24 * 30,
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
  <div class="mb-6">
    <div class="mx-auto max-w-4xl flex items-center">
      <a href="/" class="btn-base btn-ghost px-4 py-2 mr-3">← 戻る</a>
      <h1 class="text-2xl font-bold tracking-tight">設定</h1>
      <div class="ml-auto flex items-center gap-2">
        <span id="msg" class="text-sm text-gray-600 hidden sm:inline"></span>
        <button type="button" id="btn-save-top" class="btn-base btn-primary px-4 py-2">保存</button>
      </div>
    </div>
  </div>

  <form id="settings-form" class="ml-auto space-y-6 max-w-3xl">
    <input type="hidden" id="csrf" value="<?php echo htmlspecialchars(Csrf::issue(), ENT_QUOTES, 'UTF-8'); ?>" />

    <section class="card p-5">
      <h2 class="font-semibold mb-3 tracking-tight">スケジュール</h2>
      <div class="flex items-center mb-4">
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" id="schedule_enabled" class="h-4 w-4">
          <span class="text-sm text-gray-700">自動投稿を有効化</span>
        </label>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div id="mode_card_fixed" class="p-4 border rounded bg-white cursor-pointer select-none hover:border-blue-300 focus:outline-none" role="button" tabindex="0">
          <label class="inline-flex items-center gap-2">
            <input type="radio" name="schedule_mode" value="fixed" id="schedule_mode_fixed" class="h-4 w-4" />
            <span class="font-medium">固定時刻</span>
          </label>
          <label class="block mt-3 text-sm text-gray-700">固定時刻（カンマ区切り HH:MM）
            <input type="text" id="fixed" class="border rounded p-2 w-full" placeholder="09:00, 13:00, 21:00" />
          </label>
        </div>
        <div id="mode_card_interval" class="p-4 border rounded bg-white cursor-pointer select-none hover:border-blue-300 focus:outline-none" role="button" tabindex="0">
          <label class="inline-flex items-center gap-2">
            <input type="radio" name="schedule_mode" value="interval" id="schedule_mode_interval" class="h-4 w-4" />
            <span class="font-medium">一定間隔</span>
          </label>
          <label class="block mt-3 text-sm text-gray-700">間隔（分）
            <input type="number" id="interval" class="border rounded p-2 w-full" min="0" />
          </label>
        </div>
        <div id="mode_card_per_day" class="p-4 border rounded bg-white cursor-pointer select-none hover:border-blue-300 focus:outline-none md:col-span-2" role="button" tabindex="0">
          <label class="inline-flex items-center gap-2">
            <input type="radio" name="schedule_mode" value="per_day" id="schedule_mode_per_day" class="h-4 w-4" />
            <span class="font-medium">1日あたりの投稿数</span>
          </label>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mt-3">
            <label class="block text-sm text-gray-700">投稿数（回/日）
              <input type="number" id="perDayCount" class="border rounded p-2 w-full" min="1" max="24" step="1" />
            </label>
            <label class="block text-sm text-gray-700">最小間隔（分）
              <input type="number" id="minSpacingMinutes" class="border rounded p-2 w-full" min="0" max="1440" step="1" />
            </label>
          </div>
          <p class="text-xs text-gray-500 mt-2">日を等分して各ブロック内でランダムに時刻を選びます。偏り抑制のため日ごとに位相をランダム化し、最小間隔を守るよう調整します。</p>
          <div class="mt-3">
            <button type="button" id="btn-reschedule" class="btn-base btn-primary px-3 py-1 text-sm">スケジュール再生成</button>
          </div>
          <div class="mt-3">
            <div id="perday-schedule" class="text-sm text-gray-700"></div>
          </div>
        </div>
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
        <label class="block md:col-span-2">LLMプロバイダ
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <select id="llm_provider" class="border rounded p-2 w-full">
              <option value="openai">OpenAI</option>
              <option value="gemini">Google Gemini</option>
            </select>
            <input type="text" id="llm_model" class="border rounded p-2 w-full md:col-span-2" placeholder="モデル（例: gpt-4o-mini / gemini-1.5-flash）" />
          </div>
          <p class="text-xs text-gray-500 mt-1">OpenAIは `OPENAI_API_KEY`、Geminiは `GOOGLE_API_KEY`（または `GEMINI_API_KEY`）を `app/config/.env` に設定してください。</p>
        </label>
        <label class="block md:col-span-2">
          <span class="inline-flex items-center gap-2">
            <input type="checkbox" id="title_enabled" class="h-4 w-4" />
            <span>タイトル生成を有効化</span>
          </span>
        </label>
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
      <h2 class="font-semibold mb-3 tracking-tight">センシティブ判定</h2>
      <div class="grid grid-cols-1 gap-5">
        <label class="block">
          <span class="inline-flex items-center gap-2">
            <input type="checkbox" id="sensitive_enabled" class="h-4 w-4" />
            <span>センシティブ判定を有効にする</span>
          </span>
          <p class="text-xs text-gray-500 mt-1">画像を投稿前にAIで分析し、センシティブと判定された場合は自動的にセンシティブフラグを付けて投稿します。</p>
        </label>
        
        <label class="block">判定API
          <select id="sensitive_provider" class="border rounded p-2 w-full">
            <option value="gemini">Google Gemini（推奨・月1,500枚まで無料）</option>
            <option value="openai">OpenAI GPT-4o-mini（有料・高精度）</option>
          </select>
          <p class="text-xs text-gray-500 mt-1">OpenAIは `OPENAI_API_KEY`、Geminiは `GOOGLE_API_KEY`（または `GEMINI_API_KEY`）を `app/config/.env` に設定してください。</p>
        </label>
        
        <label class="block">モデル名
          <input type="text" id="sensitive_model" class="border rounded p-2 w-full" placeholder="例: gemini-2.5-flash-lite / gpt-4o-mini" />
          <p class="text-xs text-gray-500 mt-1">デフォルト: Gemini は `gemini-2.5-flash-lite`、OpenAI は `gpt-4o-mini`</p>
        </label>
        
        <label class="block">センシティブ判定閾値（この値以上でセンシティブフラグを付与）
          <input type="number" id="sensitive_threshold" min="0" max="100" class="border rounded p-2 w-full" />
          <p class="text-xs text-gray-500 mt-1">
            0-50: セーフ（フラグなし） / 51以上: センシティブ扱い（フラグ付き投稿）<br>
            推奨値: 51-61
          </p>
        </label>
        
        <label class="block">成人向け判定閾値（この値以上で adult_content カテゴリ）
          <input type="number" id="sensitive_adult_threshold" min="0" max="100" class="border rounded p-2 w-full" />
          <p class="text-xs text-gray-500 mt-1">
            センシティブ閾値〜この値未満: "その他"警告 / この値以上: "成人向けコンテンツ"警告<br>
            推奨値: 71（センシティブ判定閾値以上の値を設定してください）
          </p>
        </label>
        
        <div class="bg-yellow-50 border border-yellow-200 rounded p-3 text-sm text-yellow-800">
          <strong>注意:</strong> API呼び出しが失敗した場合、その画像は投稿されず失敗一覧に追加されます。判定が不要な場合は、この機能を無効にしてください。
        </div>
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

    function setDisabled(container, disabled){
      if (!container) return;
      const inputs = container.querySelectorAll('input, select, textarea, button');
      inputs.forEach(el => {
        if (el.type === 'radio') return; // do not disable radios in the card header
        el.disabled = !!disabled;
      });
      if (disabled) {
        container.classList.remove('ring-2','ring-blue-400','border-blue-300','bg-blue-50');
        container.classList.add('opacity-60');
      } else {
        container.classList.remove('opacity-60');
        container.classList.add('ring-2','ring-blue-400','border-blue-300','bg-blue-50');
      }
    }

    function updateScheduleModeUI(){
      const sel = document.querySelector('input[name="schedule_mode"]:checked');
      const val = sel ? sel.value : 'fixed';
      const fixedCard = document.getElementById('mode_card_fixed');
      const intervalCard = document.getElementById('mode_card_interval');
      const perDayCard = document.getElementById('mode_card_per_day');
      setDisabled(fixedCard, val !== 'fixed');
      setDisabled(intervalCard, val !== 'interval');
      setDisabled(perDayCard, val !== 'per_day');
      const btnRes = document.getElementById('btn-reschedule');
      if (btnRes) btnRes.disabled = (val !== 'per_day');
      if (val === 'per_day') { refreshPerDaySchedule(); }
    }

    function getDefaultModelForProvider(provider){
      switch (provider) {
        case 'gemini':
          return 'gemini-2.5-flash-lite';
        case 'openai':
        default:
          return 'gpt-4o-mini';
      }
    }

    async function refreshPerDaySchedule(){
      const wrap = document.getElementById('perday-schedule');
      if (!wrap) return;
      wrap.textContent = '読み込み中…';
      try {
        const res = await apiGet('/api/schedule_get.php');
        if (!res || !Array.isArray(res.items)) { wrap.textContent = ''; return; }
        if (res.items.length === 0) { wrap.textContent = '今日のスケジュールはありません'; return; }
        const frag = document.createDocumentFragment();
        const list = document.createElement('div');
        list.className = 'flex flex-wrap gap-2';
        res.items.forEach(it => {
          const b = document.createElement('span');
          b.textContent = it.time;
          b.className = 'px-2 py-1 rounded border text-xs ' + (it.status === 'upcoming' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : (it.status === 'past' ? 'bg-gray-50 border-gray-200 text-gray-500' : 'bg-blue-50 border-blue-200 text-blue-700'));
          list.appendChild(b);
        });
        frag.appendChild(list);
        wrap.innerHTML = '';
        wrap.appendChild(frag);
      } catch (e) {
        wrap.textContent = '';
      }
    }

    function loadIntoForm(cfg){
      document.getElementById('schedule_enabled').checked = cfg.schedule.enabled !== false;
      {
        const m = (cfg.schedule.mode || 'fixed');
        const allowed = ['fixed', 'interval', 'per_day'];
        const val = allowed.includes(m) ? m : 'fixed';
        const el = document.querySelector(`input[name="schedule_mode"][value="${val}"]`);
        if (el) el.checked = true;
      }
      document.getElementById('interval').value = cfg.schedule.intervalMinutes;
      document.getElementById('fixed').value = (cfg.schedule.fixedTimes || []).join(', ');
      // per_day
      document.getElementById('perDayCount').value = (typeof cfg.schedule.perDayCount === 'number' ? cfg.schedule.perDayCount : 3);
      document.getElementById('minSpacingMinutes').value = (typeof cfg.schedule.minSpacingMinutes === 'number' ? cfg.schedule.minSpacingMinutes : 120);
      document.getElementById('chunkSize').value = cfg.upload.chunkSize;
      document.getElementById('concurrency').value = cfg.upload.concurrency;
      document.getElementById('allowedMime').value = (cfg.upload.allowedMime || []).join(', ');
      document.getElementById('llm_provider').value = (cfg.post.llm && cfg.post.llm.provider) ? cfg.post.llm.provider : 'openai';
      document.getElementById('llm_model').value = (cfg.post.llm && cfg.post.llm.model) ? cfg.post.llm.model : getDefaultModelForProvider(document.getElementById('llm_provider').value);
      document.getElementById('title_max').value = cfg.post.title.maxChars;
      document.getElementById('title_tone').value = cfg.post.title.tone;
      document.getElementById('title_ng').value = (cfg.post.title.ngWords || []).join(', ');
      document.getElementById('title_enabled').checked = (cfg.post.title.enabled !== false);
      document.getElementById('textMax').value = cfg.post.textMax;
      // センシティブ判定
      const sensEnabled = document.getElementById('sensitive_enabled');
      const sensProvider = document.getElementById('sensitive_provider');
      const sensModel = document.getElementById('sensitive_model');
      const sensThreshold = document.getElementById('sensitive_threshold');
      const sensAdultThreshold = document.getElementById('sensitive_adult_threshold');
      if (sensEnabled) sensEnabled.checked = (cfg.sensitiveDetection && cfg.sensitiveDetection.enabled) || false;
      if (sensProvider) sensProvider.value = (cfg.sensitiveDetection && cfg.sensitiveDetection.provider) || 'gemini';
      if (sensModel) sensModel.value = (cfg.sensitiveDetection && cfg.sensitiveDetection.model) || '';
      if (sensThreshold) sensThreshold.value = (cfg.sensitiveDetection && typeof cfg.sensitiveDetection.threshold === 'number') ? cfg.sensitiveDetection.threshold : 61;
      if (sensAdultThreshold) sensAdultThreshold.value = (cfg.sensitiveDetection && typeof cfg.sensitiveDetection.adultContentThreshold === 'number') ? cfg.sensitiveDetection.adultContentThreshold : 71;
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
      updateScheduleModeUI();
    }

    function collectFromForm(cur){
      return {
        ...cur,
        schedule: {
          ...cur.schedule,
          enabled: document.getElementById('schedule_enabled').checked,
          mode: (()=>{ const el = document.querySelector('input[name="schedule_mode"]:checked'); const v = el ? el.value : 'fixed'; return (v === 'fixed' || v === 'interval' || v === 'per_day') ? v : 'fixed'; })(),
          fixedTimes: document.getElementById('fixed').value.split(',').map(s=>s.trim()).filter(Boolean),
          intervalMinutes: parseInt(document.getElementById('interval').value || '0', 10),
          perDayCount: parseInt(document.getElementById('perDayCount').value || '0', 10),
          minSpacingMinutes: parseInt(document.getElementById('minSpacingMinutes').value || '0', 10)
        },
        upload: {
          ...cur.upload,
          chunkSize: parseInt(document.getElementById('chunkSize').value || '0', 10),
          concurrency: parseInt(document.getElementById('concurrency').value || '0', 10),
          allowedMime: document.getElementById('allowedMime').value.split(',').map(s=>s.trim()).filter(Boolean)
        },
        post: {
          ...cur.post,
          llm: {
            ...cur.post.llm,
            provider: document.getElementById('llm_provider').value,
            model: document.getElementById('llm_model').value
          },
          title: {
            ...cur.post.title,
            enabled: document.getElementById('title_enabled').checked,
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
        sensitiveDetection: {
          ...(cur.sensitiveDetection || {}),
          enabled: document.getElementById('sensitive_enabled').checked,
          provider: document.getElementById('sensitive_provider').value,
          model: document.getElementById('sensitive_model').value || (document.getElementById('sensitive_provider').value === 'gemini' ? 'gemini-2.5-flash-lite' : 'gpt-4o-mini'),
          threshold: parseInt(document.getElementById('sensitive_threshold').value || '61', 10),
          adultContentThreshold: parseInt(document.getElementById('sensitive_adult_threshold').value || '71', 10)
        },
        tagsText: (document.getElementById('tagsText').value || '').replace(/\r\n/g, '\n')
      };
    }

    (async () => {
      const cur = await apiGet('/api/settings_get.php');
      loadIntoForm(cur);
      document.querySelectorAll('input[name="schedule_mode"]').forEach(el => {
        el.addEventListener('change', updateScheduleModeUI);
      });
      const fixedCard = document.getElementById('mode_card_fixed');
      const intervalCard = document.getElementById('mode_card_interval');
      const perDayCard = document.getElementById('mode_card_per_day');
      function selectMode(val){
        const el = document.querySelector(`input[name="schedule_mode"][value="${val}"]`);
        if (el) { el.checked = true; updateScheduleModeUI(); }
      }
      [
        [fixedCard, 'fixed'],
        [intervalCard, 'interval'],
        [perDayCard, 'per_day']
      ].forEach(([card, val]) => {
        if (!card) return;
        card.addEventListener('click', (e) => {
          if (e.target && String(e.target.tagName).toLowerCase() === 'input') return;
          selectMode(val);
        });
        card.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectMode(val); }
        });
      });
      const provEl = document.getElementById('llm_provider');
      if (provEl) {
        provEl.addEventListener('change', () => {
          const modelEl = document.getElementById('llm_model');
          if (modelEl) modelEl.value = getDefaultModelForProvider(provEl.value);
        });
      }
      // センシティブ判定APIプロバイダ変更時にもデフォルトモデルを自動入力
      const sensProvEl = document.getElementById('sensitive_provider');
      if (sensProvEl) {
        sensProvEl.addEventListener('change', () => {
          const sensModelEl = document.getElementById('sensitive_model');
          if (sensModelEl) sensModelEl.value = getDefaultModelForProvider(sensProvEl.value);
        });
      }
      const onSave = async () => {
        const updated = collectFromForm(cur);
        // Detect changes relevant to per_day reschedule
        const prev = cur || {};
        const prevMode = (prev.schedule && prev.schedule.mode) ? prev.schedule.mode : 'fixed';
        const newMode = (updated.schedule && updated.schedule.mode) ? updated.schedule.mode : 'fixed';
        const prevCount = (prev.schedule && typeof prev.schedule.perDayCount === 'number') ? prev.schedule.perDayCount : 0;
        const newCount = (updated.schedule && typeof updated.schedule.perDayCount === 'number') ? updated.schedule.perDayCount : 0;
        const prevSpacing = (prev.schedule && typeof prev.schedule.minSpacingMinutes === 'number') ? prev.schedule.minSpacingMinutes : 0;
        const newSpacing = (updated.schedule && typeof updated.schedule.minSpacingMinutes === 'number') ? updated.schedule.minSpacingMinutes : 0;
        const switchedToPerDay = (prevMode !== 'per_day' && newMode === 'per_day');
        const changedCount = (newCount !== prevCount);
        const changedSpacing = (newSpacing !== prevSpacing);
        const prevEnabled = !(prev.schedule && prev.schedule.enabled === false);
        const enabled = !(updated.schedule && updated.schedule.enabled === false);
        const enabledJustTurnedOn = (!prevEnabled && enabled);
        const shouldAutoReschedule = enabled && newMode === 'per_day' && (switchedToPerDay || changedCount || changedSpacing || enabledJustTurnedOn);

        await apiPost('/api/settings_set.php', updated);
        // Auto-reschedule only when relevant fields changed
        try {
          if (shouldAutoReschedule) {
            await apiPost('/api/reschedule.php', {});
            await refreshPerDaySchedule();
          }
        } catch (e) { /* ignore auto-reschedule failure */ }
        // Update local baseline to avoid repeated triggers on next save
        Object.assign(cur, updated);
        const msg = document.getElementById('msg'); if (msg){ msg.textContent = '保存しました'; msg.classList.remove('hidden'); setTimeout(()=>{ msg.textContent=''; msg.classList.add('hidden'); }, 1500); }
      };
      const btnTop = document.getElementById('btn-save-top');
      if (btnTop) btnTop.onclick = onSave;
      const btnRes = document.getElementById('btn-reschedule');
      if (btnRes) btnRes.onclick = async () => {
        const original = btnRes.textContent;
        btnRes.disabled = true;
        btnRes.textContent = '再生成中…';
        try {
          await apiPost('/api/reschedule.php', {});
          const msg = document.getElementById('msg'); if (msg){ msg.textContent = '当日のスケジュールを再生成しました'; msg.classList.remove('hidden'); setTimeout(()=>{ msg.textContent=''; msg.classList.add('hidden'); }, 1800); }
          refreshPerDaySchedule();
        } catch (e) {
          const msg = document.getElementById('msg'); if (msg){ msg.textContent = '再生成に失敗しました'; msg.classList.remove('hidden'); setTimeout(()=>{ msg.textContent=''; msg.classList.add('hidden'); }, 2000); }
        } finally {
          btnRes.disabled = false;
          btnRes.textContent = original;
        }
      };
    })();
  </script>
</div>
</body>
</html>


