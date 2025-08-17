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
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/dropzone@6.0.0-beta.2/dist/dropzone-min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/photoswipe@5.3.8/dist/photoswipe.css" />
  <script src="https://cdn.jsdelivr.net/npm/photoswipe@5.3.8/dist/photoswipe.umd.min.js"></script>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="./assets/app.css" />
</head>
<body class="bg-gray-100">
<div class="container mx-auto max-w-6xl p-4">
  <h1 class="text-2xl font-bold mb-4">X Auto Poster</h1>

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

  <div class="flex gap-4 mb-4">
    <button id="btn-post-now" class="bg-emerald-600 text-white px-4 py-2 rounded">今すぐ1件投稿</button>
    <a href="?logout=1" class="ml-auto text-blue-700 underline">ログアウト</a>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <section class="md:col-span-2 bg-white p-4 rounded shadow">
      <h2 class="font-semibold mb-2">キュー一覧（ドラッグで並べ替え）</h2>
      <div id="grid" class="grid grid-cols-2 md:grid-cols-3 gap-2"></div>
      <p class="text-sm text-gray-600 mt-2">先頭が次回投稿対象です。</p>
    </section>

    <section class="bg-white p-4 rounded shadow">
      <h2 class="font-semibold mb-2">アップロード</h2>
      <form action="/api/upload.php" class="dropzone" id="uploader"></form>
    </section>

    <section class="md:col-span-2 bg-white p-4 rounded shadow">
      <h2 class="font-semibold mb-2">設定</h2>
      <form id="form-settings"></form>
      <div class="mt-2"><button id="btn-save" class="bg-blue-600 text-white px-3 py-2 rounded">保存</button></div>
    </section>
  </div>

  <template id="tpl-card">
    <div class="relative group border rounded overflow-hidden">
      <img class="w-full h-40 object-cover" />
      <button class="absolute top-1 right-1 bg-white/80 px-2 py-1 rounded text-sm btn-delete opacity-0 group-hover:opacity-100">削除</button>
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
      return await res.json();
    }

    async function refreshList(){
      const data = await apiGet('/api/list.php');
      const grid = document.getElementById('grid');
      grid.innerHTML = '';
      data.items.forEach(item => {
        const tpl = document.getElementById('tpl-card').content.cloneNode(true);
        const img = tpl.querySelector('img');
        img.src = `/api/file.php?id=${encodeURIComponent(item.id)}`;
        img.alt = item.file;
        const btn = tpl.querySelector('.btn-delete');
        btn.addEventListener('click', async (e) => {
          e.preventDefault();
          await apiPost('/api/delete.php', {id: item.id});
          refreshList();
        });
        const cell = document.createElement('div');
        cell.dataset.id = item.id;
        cell.appendChild(tpl);
        grid.appendChild(cell);
      });

      new Sortable(grid, {
        animation: 150,
        onEnd: async () => {
          const order = Array.from(grid.children).map(el => el.dataset.id);
          await apiPost('/api/reorder.php', {order});
        }
      });
    }

    async function loadSettings(){
      const s = await apiGet('/api/settings_get.php');
      const f = document.getElementById('form-settings');
      f.innerHTML = '';
      const pre = document.createElement('pre');
      pre.className = 'border rounded p-2 text-xs bg-gray-50 h-64 overflow-auto';
      pre.contentEditable = true;
      pre.textContent = JSON.stringify(s, null, 2);
      f.appendChild(pre);
      document.getElementById('btn-save').onclick = async () => {
        try {
          const body = JSON.parse(pre.textContent);
          await apiPost('/api/settings_set.php', body);
          alert('保存しました');
        } catch (e) { alert('JSON形式が不正です'); }
      }
    }

    document.getElementById('btn-post-now').addEventListener('click', async () => {
      await apiPost('/api/post_now.php', {});
      refreshList();
    });

    Dropzone.autoDiscover = false;
    const dz = new Dropzone('#uploader', {
      url: '/api/upload.php',
      chunking: true,
      forceChunking: true,
      chunkSize: <?php echo (int)Settings::get()['upload']['chunkSize']; ?>,
      parallelUploads: <?php echo (int)Settings::get()['upload']['concurrency']; ?>,
      headers: {'X-CSRF-Token': csrfToken}
    });
    dz.on('queuecomplete', refreshList);

    refreshList();
    loadSettings();
  </script>

  <?php endif; ?>
</div>
</body>
</html>


