<?php
declare(strict_types=1);

use App\Lib\Auth;
use App\Lib\Settings;
use App\Lib\Util;

require_once __DIR__ . '/../lib/bootstrap.php';

session_name(Settings::security('sessionName'));
session_start();
if (!Auth::isLoggedIn()) Util::jsonResponse(['error' => 'auth'], 401);

// Load base config and append tagsText from current source file
$cfg = Settings::get();
$tagsFile = __DIR__ . '/../config/' . ($cfg['post']['hashtags']['source'] ?? 'tags.txt');
// Convert newline-based file to comma-separated UI string
$tagsText = file_exists($tagsFile) ? file_get_contents($tagsFile) : '';
$lines = array_values(array_filter(array_map('trim', preg_split("/\r?\n/", (string)$tagsText))));
$cfg['tagsText'] = implode(', ', $lines);
Util::jsonResponse($cfg);


