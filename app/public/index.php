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

$isLoggedIn = Auth::isLoggedIn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
  $password = (string)($_POST['password'] ?? '');
  if (Auth::login($password)) {
    header('Location: ./');
    exit;
  }
  $error = 'Login failed';
}

if (isset($_GET['logout'])) {
  Auth::logout();
  header('Location: ./');
  exit;
}

?>
<!doctype html>
<html lang="ja">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>X Auto Poster Admin</title>
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  <script src="/assets/vendor/Sortable.min.js"></script>
  <script src="/assets/vendor/dropzone-min.js"></script>
  <link rel="stylesheet" href="/assets/vendor/photoswipe.css" />
  <script type="module">
    import PhotoSwipeLightbox from '/assets/vendor/photoswipe-lightbox.esm.min.js';
    import PhotoSwipe from '/assets/vendor/photoswipe.esm.min.js';
    window.PhotoSwipe = PhotoSwipe;
    window.PhotoSwipeLightbox = PhotoSwipeLightbox;
  </script>
  <script src="/assets/vendor/tailwindcdn.js"></script>
  <link rel="stylesheet" href="./assets/app.css" />
</head>

<body class="bg-gray-100">
  <div class="container mx-auto max-w-[3840px] p-6">
    <div class="flex items-center gap-3 mb-6 flex-wrap">
      <h1 class="text-2xl font-bold tracking-tight">X Auto Poster</h1>
      <span class="text-sm text-gray-500">自動投稿 管理コンソール</span>
      <?php if ($isLoggedIn): ?>
        <div class="ml-auto flex gap-2 flex-wrap">
          <button id="btn-post-now" class="btn-base btn-primary px-4 py-2">今すぐ1件投稿</button>
          <a href="/settings.php" class="btn-base btn-ghost px-4 py-2">設定</a>
          <a href="?logout=1" class="btn-base btn-ghost px-4 py-2">ログアウト</a>
        </div>
      <?php endif; ?>
    </div>
    <div id="op-msg" class="mb-4 hidden"></div>
    <div id="upload-modal" class="fixed inset-0 hidden flex items-center justify-center bg-black/50 z-50">
      <div class="bg-white rounded shadow p-6 w-full max-w-md">
        <div class="font-semibold mb-3">アップロード中…</div>
        <div class="w-full bg-gray-200 rounded h-2 overflow-hidden">
          <div id="upload-progress-bar" class="bg-blue-500 h-2" style="width:0%"></div>
        </div>
        <div class="text-xs text-gray-600 mt-2"><span id="upload-progress-text">0%</span></div>
      </div>
    </div>

    <?php if (!$isLoggedIn): ?>
      <div class="bg-white p-6 rounded shadow max-w-sm mx-auto mt-16">
        <h2 class="text-lg font-semibold mb-4 text-center">ログイン</h2>
        <?php if (!empty($error)): ?><p class="text-red-600"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="login" />
          <label class="block mb-2">Password
            <input class="border rounded p-2 w-full" type="password" name="password" required />
          </label>
          <button class="btn-base btn-primary w-full px-4 py-2">Login</button>
        </form>
      </div>
    <?php else: ?>


      <?php
        $cfg = Settings::get();
        $tz = (string)($cfg['timezone'] ?? 'UTC');
        $now = Util::now($tz);
        $nowTs = $now->getTimestamp();
        $stateFile = __DIR__ . '/../data/meta/state.json';
        $state = Util::readJson($stateFile, ['lastPostAt' => 0, 'lastFixedSlotTs' => 0, 'scheduleHash' => '', 'dailyPlanDate' => '', 'dailyPlanSlots' => []]);
        $mode = (string)($cfg['schedule']['mode'] ?? 'fixed');
        if ($mode === 'both') { $mode = 'fixed'; }
        $enabled = !isset($cfg['schedule']['enabled']) || (bool)$cfg['schedule']['enabled'];
        $candidates = [];
        if ($enabled) {
          // Fixed-time schedule: pick the nearest upcoming slot (today or tomorrow)
          if ($mode === 'fixed') {
            $fixedTimes = $cfg['schedule']['fixedTimes'] ?? [];
            $date = $now->format('Y-m-d');
            $tzObj = new \DateTimeZone($tz);
            $slots = [];
            foreach ($fixedTimes as $t) {
              $dt = new \DateTimeImmutable($date . ' ' . $t, $tzObj);
              if ($dt->getTimestamp() >= $nowTs) { $slots[] = $dt->getTimestamp(); }
            }
            sort($slots);
            if (empty($slots) && !empty($fixedTimes)) {
              sort($fixedTimes);
              $tomorrow = $now->modify('+1 day')->format('Y-m-d');
              $dt = new \DateTimeImmutable($tomorrow . ' ' . $fixedTimes[0], $tzObj);
              $slots[] = $dt->getTimestamp();
            }
            if (!empty($slots)) { $candidates[] = $slots[0]; }
          }
          // Per-day schedule: show next unconsumed slot today; if none in state, make ephemeral plan
          if ($mode === 'per_day') {
            $date = $now->format('Y-m-d');
            $tzObj = new \DateTimeZone($tz);
            $todayStart = (new \DateTimeImmutable($date . ' 00:00:00', $tzObj))->getTimestamp();
            $tomorrowStart = (new \DateTimeImmutable($date . ' 00:00:00', $tzObj))->modify('+1 day')->getTimestamp();
            $slots = [];
            if (($state['dailyPlanDate'] ?? '') === $date && is_array($state['dailyPlanSlots'] ?? null)) {
              $slots = $state['dailyPlanSlots'];
            } else {
              $count = max(1, min(24, (int)($cfg['schedule']['perDayCount'] ?? 3)));
              $step = intdiv(max(1, $tomorrowStart - $todayStart), $count);
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
            foreach ($slots as $ts) { if ($ts >= $nowTs) { $candidates[] = $ts; break; } }
          }
          // Interval schedule: next time is lastPostAt + interval (or now if already due)
          if ($mode === 'interval') {
            $interval = (int)($cfg['schedule']['intervalMinutes'] ?? 0) * 60;
            if ($interval > 0) {
              $lastPostAt = (int)($state['lastPostAt'] ?? 0);
              $nextIntervalTs = $lastPostAt + $interval;
              if ($nextIntervalTs < $nowTs) { $nextIntervalTs = $nowTs; }
              $candidates[] = $nextIntervalTs;
            }
          }
          if (!empty($candidates)) {
            $nextTs = min($candidates);
            $nextDt = (new \DateTimeImmutable('@' . $nextTs))->setTimezone(new \DateTimeZone($tz));
            $fmt = $nextDt->format('Y-m-d') === $now->format('Y-m-d') ? 'H:i' : 'Y-m-d H:i';
            $nextPostText = '次回投稿時間は' . $nextDt->format($fmt) . 'です。';
          } else {
            $nextPostText = '次回投稿時間は未定です。';
          }
        } else {
          $nextPostText = '自動投稿は無効です。';
        }
      ?>

      <section id="failed-section" class="card p-5 mb-5 hidden">
        <div class="flex items-center mb-3">
          <h2 class="font-semibold tracking-tight">投稿失敗一覧（<span id="failed-count">0</span>枚）</h2>
        </div>
        <div id="grid-failed" class="grid grid-cols-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-2"></div>
      </section>

      <section class="card p-5">
        <div class="flex items-center mb-3">
          <h2 class="font-semibold tracking-tight">投稿予約一覧（<span id="total-count">0</span>枚<span id="about-days" class="text-gray-500 text-sm ml-2 hidden">/ あと0日分</span>）</h2>
          <button id="btn-upload" class="ml-auto btn-base btn-primary px-3 py-2 text-sm">アップロード</button>
        </div>
        <p class="text-sm text-gray-500 my-3"><?php echo htmlspecialchars($nextPostText, ENT_QUOTES, 'UTF-8'); ?></p>
        <div id="grid" class="grid grid-cols-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-2"></div>
      </section>

      <template id="tpl-card">
        <div class="relative group img-card">
          <div class="thumb">
            <img />
          </div>
          <button class="absolute top-2 right-2 bg-white/90 px-2 py-1 rounded text-xs btn-delete opacity-0 group-hover:opacity-100 shadow">削除</button>
        </div>
      </template>

      <template id="tpl-card-failed">
        <div class="relative group img-card">
          <div class="thumb">
            <img />
          </div>
          <div class="absolute top-2 right-2 flex gap-2 opacity-0 group-hover:opacity-100">
            <button class="bg-white/90 px-2 py-1 rounded text-xs btn-restore shadow">予約に戻す</button>
            <button class="bg-white/90 px-2 py-1 rounded text-xs btn-delete shadow">削除</button>
          </div>
        </div>
      </template>

      <script>
        const csrfCookie = '<?php echo addslashes(Settings::security('csrfCookieName')); ?>';
        const csrfToken = '<?php echo addslashes(Csrf::issue()); ?>';

        async function apiGet(url) {
          const res = await fetch(url, {
            headers: {
              'X-CSRF-Token': csrfToken
            }
          });
          const txt = await res.text();
          try {
            return JSON.parse(txt);
          } catch (e) {
            console.error('Non-JSON response from', url, { status: res.status, body: txt });
            throw new Error('Request failed');
          }
        }
        async function apiPost(url, body) {
          const res = await fetch(url, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(body)
          });
          const txt = await res.text();
          try {
            return JSON.parse(txt);
          } catch (e) {
            console.error('Non-JSON response from', url, {
              status: res.status,
              body: txt
            });
            throw new Error('Request failed');
          }
        }

        async function refreshList() {
          const data = await apiGet('/api/list.php');

          // Failed section
          const failedSection = document.getElementById('failed-section');
          const failedCountEl = document.getElementById('failed-count');
          const failedGrid = document.getElementById('grid-failed');
          failedGrid.innerHTML = '';
          if (Array.isArray(data.failed) && data.failed.length > 0) {
            failedSection.classList.remove('hidden');
            failedCountEl.textContent = String(data.failed.length);
            data.failed.forEach(item => {
              const tpl = document.getElementById('tpl-card-failed').content.cloneNode(true);
              const img = tpl.querySelector('img');
              const full = `/api/file.php?id=${encodeURIComponent(item.id)}`;
              const thumbUrl = `${full}&thumb=1`;
              img.src = thumbUrl;
              img.alt = item.file;
              img.onerror = () => { img.src = full; };
              const btnDel = tpl.querySelector('.btn-delete');
              btnDel.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                await apiPost('/api/delete.php', { id: item.id, scope: 'failed' });
                refreshList();
              });
              const btnRestore = tpl.querySelector('.btn-restore');
              btnRestore.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                await apiPost('/api/restore.php', { id: item.id, position: 'head' });
                showMsg('先頭に戻しました', 'success');
                refreshList();
              });
              const a = document.createElement('a');
              a.href = full;
              if (item.width && item.height) {
                a.setAttribute('data-pswp-width', item.width);
                a.setAttribute('data-pswp-height', item.height);
              }
              a.appendChild(tpl);
              const cell = document.createElement('div');
              cell.dataset.id = item.id;
              cell.appendChild(a);
              failedGrid.appendChild(cell);
            });
          } else {
            failedSection.classList.add('hidden');
          }

          // Queue section
          const countEl = document.getElementById('total-count');
          const aboutDaysEl = document.getElementById('about-days');
          if (countEl) {
            countEl.textContent = String(data.items.length);
          }
          if (aboutDaysEl) {
            const days = data.aboutDays;
            if (typeof days === 'number' && days > 0) {
              aboutDaysEl.textContent = `/ あと${days}日分`;
              aboutDaysEl.classList.remove('hidden');
            } else {
              aboutDaysEl.classList.add('hidden');
            }
          }
          const grid = document.getElementById('grid');
          const isMobile = window.matchMedia('(max-width: 640px)').matches;
          grid.innerHTML = '';
          if (data.items.length === 0) {
            if (!isMobile) {
              grid.style.minHeight = '500px';
              grid.classList.add('empty-state');
              grid.classList.add('place-items-center');
              grid.style.gridTemplateColumns = '1fr';
              const placeholder = document.createElement('div');
              placeholder.className = 'text-gray-500 pointer-events-none text-center';
              placeholder.textContent = 'ここにドラッグ＆ドロップ（または「アップロード」をクリック）';
              grid.appendChild(placeholder);
            } else {
              grid.style.minHeight = '';
              grid.classList.remove('empty-state');
              grid.classList.remove('place-items-center');
              grid.style.gridTemplateColumns = '';
            }
          } else {
            grid.style.minHeight = '';
            grid.classList.remove('empty-state');
            grid.classList.remove('place-items-center');
            grid.style.gridTemplateColumns = '';
          }
          data.items.forEach(item => {
            const tpl = document.getElementById('tpl-card').content.cloneNode(true);
            const img = tpl.querySelector('img');
            const full = `/api/file.php?id=${encodeURIComponent(item.id)}`;
            const thumbUrl = `${full}&thumb=1`;
            img.src = thumbUrl;
            img.onerror = () => { img.src = full; };
            img.alt = item.file;
            const btn = tpl.querySelector('.btn-delete');
            btn.addEventListener('click', async (e) => {
              e.preventDefault();
              e.stopPropagation();
              if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
              await apiPost('/api/delete.php', { id: item.id });
              refreshList();
            });
            const a = document.createElement('a');
            a.href = full;
            if (item.width && item.height) {
              a.setAttribute('data-pswp-width', item.width);
              a.setAttribute('data-pswp-height', item.height);
            }
            a.appendChild(tpl);
            const cell = document.createElement('div');
            cell.dataset.id = item.id;
            cell.appendChild(a);
            grid.appendChild(cell);
          });

          if (data.items.length > 0) {
            new Sortable(grid, {
              animation: 150,
              onEnd: async () => {
                const order = Array.from(grid.children).map(el => el.dataset.id);
                await apiPost('/api/reorder.php', { order });
              }
            });
          }

          // Initialize PhotoSwipe lightbox for both sections
          if (window._pswp) {
            window._pswp.destroy();
          }
          const lb1 = new window.PhotoSwipeLightbox({
            gallery: '#grid',
            children: 'a',
            pswpModule: () => Promise.resolve(window.PhotoSwipe),
          });
          lb1.init();
          const lb2 = new window.PhotoSwipeLightbox({
            gallery: '#grid-failed',
            children: 'a',
            pswpModule: () => Promise.resolve(window.PhotoSwipe),
          });
          lb2.init();
          window._pswp = { destroy() { lb1.destroy(); lb2.destroy(); } };
        }


        function showMsg(text, type = 'error', onHide) {
          const el = document.getElementById('op-msg');
          el.textContent = text;
          el.className = 'mb-4 text-sm rounded border px-3 py-2 ' + (type === 'error' ? 'text-red-700 bg-red-50 border-red-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200');
          el.classList.remove('hidden');
          setTimeout(() => {
            el.classList.add('hidden');
            if (typeof onHide === 'function') onHide();
          }, 3000);
        }

        const postBtn = document.getElementById('btn-post-now');
        postBtn.addEventListener('click', async () => {
          if (postBtn.disabled) return;
          const originalHTML = postBtn.innerHTML;
          postBtn.disabled = true;
          postBtn.classList.add('opacity-60', 'cursor-not-allowed');
          postBtn.innerHTML = '<span class="inline-flex items-center gap-2"><svg class="animate-spin h-4 w-4" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>投稿中…</span>';
          try {
            const res = await apiPost('/api/post_now.php', {});
            if (res && res.error) throw new Error(res.message || '投稿に失敗しました');
            showMsg('投稿しました', 'success');
            refreshList();
          } catch (e) {
            showMsg(e.message || '投稿に失敗しました', 'error', () => {
              location.reload();
            });
          } finally {
            postBtn.disabled = false;
            postBtn.classList.remove('opacity-60', 'cursor-not-allowed');
            postBtn.innerHTML = originalHTML;
          }
        });

        Dropzone.autoDiscover = false;
        // Hidden Dropzone root (no previews)
        const dzRoot = document.createElement('form');
        dzRoot.action = '/api/upload.php';
        dzRoot.className = 'hidden';
        dzRoot.id = 'dz-hidden';
        document.body.appendChild(dzRoot);

        const dz = new Dropzone('#dz-hidden', {
          url: '/api/upload.php',
          chunking: true,
          forceChunking: true,
          chunkSize: <?php echo (int)Settings::get()['upload']['chunkSize']; ?>,
          parallelUploads: <?php echo (int)Settings::get()['upload']['concurrency']; ?>,
          headers: {
            'X-CSRF-Token': csrfToken
          },
          clickable: ['#btn-upload'],
          previewsContainer: '#dz-hidden'
        });
        // Batch-level progress (monotonic): track added files in this batch
        let batchTotalBytes = 0;
        let batchUploadedBytes = 0;
        let batchActive = false;

        function showUploadModal() {
          const modal = document.getElementById('upload-modal');
          if (modal) modal.classList.remove('hidden');
        }
        function hideUploadModal() {
          const modal = document.getElementById('upload-modal');
          if (modal) modal.classList.add('hidden');
        }
        function setProgress(pct) {
          const bar = document.getElementById('upload-progress-bar');
          const txt = document.getElementById('upload-progress-text');
          if (bar && txt) {
            const v = Math.max(0, Math.min(100, pct));
            bar.style.width = v + '%';
            txt.textContent = Math.round(v) + '%';
          }
        }

        function resetBatch() {
          batchTotalBytes = 0;
          batchUploadedBytes = 0;
          batchActive = false;
          setProgress(0);
        }

        // Start a new batch on first file added after idle
        dz.on('addedfile', (file) => {
          if (!batchActive) {
            resetBatch();
            batchActive = true;
            showUploadModal();
          }
          // If size is unknown, Dropzone sets 0; still okay for monotonic display
          batchTotalBytes += (typeof file.size === 'number' ? file.size : 0);
        });

        // Update uploaded bytes based on per-file progress
        dz.on('uploadprogress', (file, progress, bytesSent) => {
          // Recompute sum of uploaded bytes across all files in current batch to keep monotonic
          const files = dz.getAcceptedFiles().concat(dz.getUploadingFiles());
          let uploaded = 0;
          let total = 0;
          files.forEach(f => {
            const t = (typeof f.size === 'number' ? f.size : 0);
            total += t;
            const p = (typeof f.upload?.progress === 'number' ? f.upload.progress : (f.status === Dropzone.SUCCESS ? 100 : 0));
            uploaded += t * (p / 100);
          });
          // Fallback to tracked totals if recompute yields 0/NaN
          if (total <= 0 && batchTotalBytes > 0) {
            total = batchTotalBytes;
            uploaded = Math.max(batchUploadedBytes, uploaded);
          }
          batchTotalBytes = Math.max(batchTotalBytes, total);
          batchUploadedBytes = Math.max(batchUploadedBytes, uploaded);
          const pct = total > 0 ? (uploaded / total) * 100 : 0;
          setProgress(pct);
        });

        dz.on('queuecomplete', () => {
          setProgress(100);
          setTimeout(() => {
            hideUploadModal();
            resetBatch();
          }, 500);
          refreshList();
        });

        // Allow dropping files on the grid area and show highlight during drag
        const gridEl = document.getElementById('grid');
        ['dragenter', 'dragover'].forEach(type => {
          gridEl.addEventListener(type, (e) => {
            e.preventDefault();
            gridEl.classList.add('is-dragover');
          });
        });
        ['dragleave', 'drop'].forEach(type => {
          gridEl.addEventListener(type, (e) => {
            e.preventDefault();
            gridEl.classList.remove('is-dragover');
          });
        });
        gridEl.addEventListener('drop', (e) => {
          if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            Array.from(e.dataTransfer.files).forEach(f => dz.addFile(f));
          }
        });

        refreshList();
      </script>

    <?php endif; ?>
  </div>
</body>

</html>