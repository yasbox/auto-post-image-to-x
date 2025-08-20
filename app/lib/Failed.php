<?php
declare(strict_types=1);

namespace App\Lib;

final class Failed
{
    private static function path(): string { return __DIR__ . '/../data/meta/failed.json'; }

    public static function get(): array
    {
        $f = Util::readJson(self::path(), ['version' => 1, 'items' => []]);
        $f['items'] = array_values(array_filter($f['items'] ?? [], fn($i) => isset($i['id'], $i['file'])));
        return $f;
    }

    public static function save(array $f): void
    {
        Util::writeJson(self::path(), $f);
    }

    public static function append(string $id, string $file, string $stage, string $message): void
    {
        $f = self::get();
        foreach ($f['items'] as $it) {
            if (($it['id'] ?? '') === $id) {
                // already recorded
                return;
            }
        }
        $f['items'][] = [
            'id' => $id,
            'file' => $file,
            'failedAt' => time(),
            'stage' => $stage,
            'message' => $message,
        ];
        self::save($f);
    }

    public static function remove(string $id): void
    {
        $f = self::get();
        $f['items'] = array_values(array_filter($f['items'], fn($it) => ($it['id'] ?? '') !== $id));
        self::save($f);
    }
}


