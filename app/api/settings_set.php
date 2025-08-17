<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Csrf;
use App\Lib\Logger;
use App\Lib\Settings;
use App\Lib\Util;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) Util::jsonResponse(['error' => 'auth'], 401);
Csrf::validate();

$data = json_decode(file_get_contents('php://input') ?: 'null', true);
if (!is_array($data)) Util::jsonResponse(['error' => 'bad_request'], 400);

Settings::save($data);
Logger::op(['event' => 'settings.save']);
Util::jsonResponse(['status' => 'ok']);


