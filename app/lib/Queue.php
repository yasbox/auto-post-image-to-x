<?php
declare(strict_types=1);

namespace App\Lib;

final class Queue
{
    private static function path(): string { return __DIR__ . '/../data/meta/queue.json'; }

    public static function get(): array
    {
        $q = Util::readJson(self::path(), ['version' => 1, 'items' => []]);
        $q['items'] = array_values(array_filter($q['items'] ?? [], fn($i) => isset($i['id'], $i['file'])));
        return $q;
    }

    public static function save(array $q): void
    {
        Util::writeJson(self::path(), $q);
    }

    public static function append(string $id, string $file): void
    {
        $q = self::get();
        $q['items'][] = [
            'id' => $id,
            'file' => $file,
            'addedAt' => time(),
        ];
        self::save($q);
    }

    public static function shift(): ?array
    {
        $q = self::get();
        $item = array_shift($q['items']);
        self::save($q);
        return $item;
    }

    public static function reorder(array $order): void
    {
        $q = self::get();
        $map = [];
        foreach ($q['items'] as $i) $map[$i['id']] = $i;
        $new = [];
        foreach ($order as $id) if (isset($map[$id])) $new[] = $map[$id];
        // append remaining (if any)
        foreach ($q['items'] as $i) if (!in_array($i['id'], $order, true)) $new[] = $i;
        $q['items'] = $new;
        self::save($q);
    }
}


