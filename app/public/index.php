<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Csrf;
use App\Lib\Settings;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
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

?><!doctype html>
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
<div class="container mx-auto max-w-screen-2xl p-6">
  <div class="flex items-center gap-3 mb-6 flex-wrap">
    <h1 class="text-2xl font-bold tracking-tight">X Auto Poster</h1>
    <span class="text-sm text-gray-500">自動投稿 管理コンソール</span>
    <div class="ml-auto flex gap-2 flex-wrap">
      <button id="btn-post-now" class="btn-base btn-primary px-4 py-2">今すぐ1件投稿</button>
      <a href="/settings.php" class="btn-base btn-ghost px-4 py-2">設定</a>
      <a href="?logout=1" class="btn-base btn-ghost px-4 py-2">ログアウト</a>
    </div>
  </div>
  <div id="op-msg" class="mb-4 hidden"></div>

  <?php if (!$isLoggedIn): ?>
  <div class="bg-white p-6 rounded shadow">
    <h2 class="text-lg font-semibold mb-2">ログイン</h2>
    <?php if (!empty($error)): ?><p class="text-red-600"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login" />
      <label class="block mb-2">Password
        <input class="border rounded p-2 w-full" type="password" name="password" required />
      </label>
      <button class="bg-blue-600 text-white px-4 py-2 rounded">Login</button>
    </form>
  </div>
  <?php else: ?>

  
  <section class="card p-5">
      <div class="flex items-center mb-3">
        <h2 class="font-semibold tracking-tight">キュー一覧（ドラッグで並べ替え）</h2>
        <button id="btn-upload" class="ml-auto btn-base btn-primary px-3 py-2 text-sm">ファイルを選択</button>
      </div>
      <div id="grid" class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-5"></div>
      <p class="text-sm text-gray-500 mt-3">先頭が次回投稿対象です。</p>
  </section>

  <template id="tpl-card">
    <div class="relative group img-card">
      <div class="thumb">
        <img />
      </div>
      <button class="absolute top-2 right-2 bg-white/90 px-2 py-1 rounded text-xs btn-delete opacity-0 group-hover:opacity-100 shadow">削除</button>
    </div>
  </template>

  <script>
    const csrfCookie = '<?php echo addslashes(Settings::security('csrfCookieName')); ?>';
    const csrfToken = '<?php echo addslashes(Csrf::issue()); ?>';

    async function apiGet(url){
      const res = await fetch(url, {headers: {'X-CSRF-Token': csrfToken}});
      return await res.json();
    }
    async function apiPost(url, body){
      const res = await fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify(body)
      });
      const txt = await res.text();
      try {
        return JSON.parse(txt);
      } catch (e) {
        console.error('Non-JSON response from', url, {status: res.status, body: txt});
        throw new Error('Request failed');
      }
    }

    async function refreshList(){
      const data = await apiGet('/api/list.php');
      const grid = document.getElementById('grid');
      grid.innerHTML = '';
      data.items.forEach(item => {
        const tpl = document.getElementById('tpl-card').content.cloneNode(true);
        const img = tpl.querySelector('img');
        const src = `/api/file.php?id=${encodeURIComponent(item.id)}`;
        img.src = src;
        img.alt = item.file;
        const btn = tpl.querySelector('.btn-delete');
        btn.addEventListener('click', async (e) => {
          e.preventDefault();
          e.stopPropagation();
          if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
          await apiPost('/api/delete.php', {id: item.id});
          refreshList();
        });
        const a = document.createElement('a');
        a.href = src;
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

      new Sortable(grid, {
        animation: 150,
        onEnd: async () => {
          const order = Array.from(grid.children).map(el => el.dataset.id);
          await apiPost('/api/reorder.php', {order});
        }
      });

      // Initialize PhotoSwipe lightbox
      if (window._pswp) { window._pswp.destroy(); }
      const lightbox = new window.PhotoSwipeLightbox({
        gallery: '#grid',
        children: 'a',
        pswpModule: () => Promise.resolve(window.PhotoSwipe),
      });
      lightbox.init();
      window._pswp = lightbox;
    }


    function showMsg(text, type = 'error'){
      const el = document.getElementById('op-msg');
      el.textContent = text;
      el.className = 'mb-4 text-sm rounded border px-3 py-2 ' + (type === 'error' ? 'text-red-700 bg-red-50 border-red-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200');
      el.classList.remove('hidden');
      setTimeout(() => { el.classList.add('hidden'); }, 3000);
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
        showMsg(e.message || '投稿に失敗しました', 'error');
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
      headers: {'X-CSRF-Token': csrfToken},
      clickable: ['#btn-upload'],
      previewsContainer: '#dz-hidden'
    });
    dz.on('queuecomplete', refreshList);

    // Allow dropping files on the grid area and show highlight during drag
    const gridEl = document.getElementById('grid');
    ['dragenter','dragover'].forEach(type => {
      gridEl.addEventListener(type, (e) => {
        e.preventDefault();
        gridEl.classList.add('is-dragover');
      });
    });
    ['dragleave','drop'].forEach(type => {
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


