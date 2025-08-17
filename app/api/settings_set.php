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

// Extract optional tagsText to write into tags file, but do not store in config.json
$tagsText = isset($data['tagsText']) && is_string($data['tagsText']) ? $data['tagsText'] : null;
unset($data['tagsText']);

// Persist config.json
Settings::save($data);

// Persist tags file if provided (UI sends comma-separated; store as newline-separated)
if ($tagsText !== null) {
    $cfg = Settings::get();
    $tagsFile = __DIR__ . '/../config/' . ($cfg['post']['hashtags']['source'] ?? 'tags.txt');
    $items = array_values(array_filter(array_map('trim', explode(',', $tagsText))));
    $normalized = implode("\n", $items);
    if ($normalized !== '') $normalized .= "\n";
    file_put_contents($tagsFile, $normalized);
}
Logger::op(['event' => 'settings.save']);
Util::jsonResponse(['status' => 'ok']);


