<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Queue;
use App\Lib\Settings;
use App\Lib\Util;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) Util::jsonResponse(['error' => 'auth'], 401);

$q = Queue::get();
Util::jsonResponse(['items' => $q['items'], 'count' => count($q['items'])]);


