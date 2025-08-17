<?php
declare(strict_types=1);

namespace App\Lib;

final class Lock
{
    public static function acquire(string $name, int $timeoutMs = 0): bool
    {
        $path = __DIR__ . '/../data/meta/' . $name;
        $start = microtime(true);
        while (true) {
            $fh = @fopen($path, 'c');
            if ($fh && flock($fh, LOCK_EX | LOCK_NB)) {
                // store handle for process lifetime
                $GLOBALS['__lock_'.$name] = $fh;
                return true;
            }
            if ($timeoutMs <= 0) return false;
            if ((microtime(true) - $start) * 1000 > $timeoutMs) return false;
            usleep(50000);
        }
    }

    public static function release(string $name): void
    {
        $key = '__lock_'.$name;
        if (!empty($GLOBALS[$key]) && is_resource($GLOBALS[$key])) {
            flock($GLOBALS[$key], LOCK_UN);
            fclose($GLOBALS[$key]);
            unset($GLOBALS[$key]);
        }
    }
}


