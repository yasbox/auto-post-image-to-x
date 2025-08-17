<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Queue;
use App\Lib\Settings;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) { http_response_code(401); exit; }

$id = $_GET['id'] ?? '';
$q = Queue::get();
$found = null;
foreach ($q['items'] as $it) if ($it['id'] === $id) { $found = $it; break; }
if (!$found) { http_response_code(404); exit; }

$path = __DIR__ . '/../data/inbox/' . $found['file'];
$info = getimagesize($path);
if (!$info) { http_response_code(404); exit; }
header('Content-Type: ' . $info['mime']);
header('Content-Length: ' . filesize($path));
readfile($path);


