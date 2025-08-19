<?php
declare(strict_types=1);

namespace App\Lib;

final class Logger
{
    private static function logPath(string $prefix): string
    {
        $date = (new \DateTimeImmutable('now', new \DateTimeZone(Settings::get()['timezone'] ?? 'UTC')))->format('Ymd');
        return __DIR__ . '/../logs/' . $prefix . '-' . $date . '.log';
    }

    public static function post(array $row): void
    {
        self::append(self::logPath('post'), $row);
        self::gc();
    }

    public static function op(array $row): void
    {
        self::append(self::logPath('op'), $row);
        self::gc();
    }

    private static function append(string $file, array $row): void
    {
        $row['timestamp'] = time();
        // Ensure log directory exists and is writable, but never emit warnings to output
        $dir = dirname($file);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        if (!is_writable($dir)) { @chmod($dir, 0777); }

        // Suppress warnings so API responses remain clean JSON even if logging fails
        @file_put_contents(
            $file,
            json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND
        );
    }

    private static function gc(): void
    {
        $retention = (int) (Settings::get()['logs']['retentionDays'] ?? 31);
        $dir = __DIR__ . '/../logs';
        foreach (glob($dir . '/*.log') ?: [] as $file) {
            if (filemtime($file) < time() - $retention * 86400) @unlink($file);
        }
    }
}


