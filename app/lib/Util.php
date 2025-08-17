<?php
declare(strict_types=1);

namespace App\Lib;

final class Util
{
    public static function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function now(string $timezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone($timezone));
    }

    public static function readJson(string $path, array $default = []): array
    {
        if (!is_file($path)) return $default;
        $txt = file_get_contents($path);
        $data = json_decode($txt ?: 'null', true);
        return is_array($data) ? $data : $default;
    }

    public static function writeJson(string $path, array $data): void
    {
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, $path);
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}


