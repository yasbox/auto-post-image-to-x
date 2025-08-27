<?php
declare(strict_types=1);

namespace App\Lib;

final class ImageProc
{
    public static function makeThumb(string $srcPath, array $p, string $outPath): string
    {
        $prevMem = null;
        try {
            $prevMem = self::bumpMemoryForLargeImage($srcPath, (int)($p['memoryLimitMB'] ?? 512));
        } catch (\Throwable $e) {
            // ignore inability to adjust memory
        }
        [$img, $w, $h] = self::loadBitmap($srcPath);
        $max = (int)($p['longEdge'] ?? 512);
        $quality = (int)($p['quality'] ?? 70);
        $strip = (bool)($p['stripMetadata'] ?? true);
        [$img, $w, $h] = self::resizeMaxLongEdge($img, $w, $h, $max);
        $jpeg = self::encodeJPEG($img, $quality, $strip);
        $dir = dirname($outPath);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        file_put_contents($outPath, $jpeg);
        imagedestroy($img);
        if ($prevMem !== null) { @ini_set('memory_limit', $prevMem); }
        return $outPath;
    }

    public static function makeLLMPreview(string $srcPath, array $p, string $id): string
    {
        [$img, $w, $h] = self::loadBitmap($srcPath);
        [$img, $w, $h] = self::resizeMaxLongEdge($img, $w, $h, (int)$p['llmPreviewLongEdge']);
        $jpeg = self::encodeJPEG($img, (int)$p['llmPreviewQuality'], (bool)$p['stripMetadataOnLLM']);
        $out = __DIR__ . '/../data/tmp/llm/' . $id . '.jpg';
        file_put_contents($out, $jpeg);
        imagedestroy($img);
        return $out;
    }

    public static function makeTweetImage(string $srcPath, array $p, string $id): string
    {
        [$img, $w, $h] = self::loadBitmap($srcPath);
        [$img, $w, $h] = self::resizeMaxLongEdge($img, $w, $h, (int)$p['tweetMaxLongEdge']);
        $qMin = (int)$p['tweetQualityMin'];
        $qMax = (int)$p['tweetQualityMax'];
        $best = null;

        // Binary search quality
        $lo = $qMin; $hi = $qMax;
        while ($hi - $lo > 2) {
            $q = intdiv($lo + $hi, 2);
            $buf = self::encodeJPEG($img, $q, (bool)$p['stripMetadataOnTweet']);
            if (strlen($buf) <= (int)$p['tweetMaxBytes']) { $best = $buf; $lo = $q; } else { $hi = $q; }
        }

        $out = __DIR__ . '/../data/tmp/tweet/' . $id . '.jpg';
        $attempt = 0;
        if ($best && strlen($best) <= (int)$p['tweetMaxBytes']) {
            file_put_contents($out, $best);
            imagedestroy($img);
            return $out;
        }

        // Fallback: scale down up to 5 times
        while ($attempt < 5) {
            $attempt++;
            [$img, $w, $h] = self::resizeScale($img, $w, $h, 0.9);
            $lo = $qMin; $hi = $qMax; $best = null;
            while ($hi - $lo > 2) {
                $q = intdiv($lo + $hi, 2);
                $buf = self::encodeJPEG($img, $q, (bool)$p['stripMetadataOnTweet']);
                if (strlen($buf) <= (int)$p['tweetMaxBytes']) { $best = $buf; $lo = $q; } else { $hi = $q; }
            }
            if ($best && strlen($best) <= (int)$p['tweetMaxBytes']) {
                file_put_contents($out, $best);
                imagedestroy($img);
                return $out;
            }
        }

        imagedestroy($img);
        throw new \RuntimeException('Cannot fit under size limit');
    }

    private static function loadBitmap(string $path): array
    {
        $info = getimagesize($path);
        if (!$info) throw new \InvalidArgumentException('Invalid image');
        $mime = $info['mime'];
        if ($mime === 'image/jpeg') { @ini_set('gd.jpeg_ignore_warning', '1'); $img = imagecreatefromjpeg($path); }
        elseif ($mime === 'image/png') { $img = imagecreatefrompng($path); imagepalettetotruecolor($img); imagesavealpha($img, false); }
        elseif ($mime === 'image/webp') { $img = imagecreatefromwebp($path); }
        else throw new \InvalidArgumentException('Unsupported mime');
        if (!$img) throw new \RuntimeException('Failed to load image');
        return [$img, imagesx($img), imagesy($img)];
    }

    private static function resizeMaxLongEdge($img, int $w, int $h, int $max): array
    {
        $long = max($w, $h);
        if ($long <= $max) return [$img, $w, $h];
        $scale = $max / $long;
        return self::resizeScale($img, $w, $h, $scale);
    }

    private static function resizeScale($img, int $w, int $h, float $scale): array
    {
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        return [$dst, $nw, $nh];
    }

    private static function encodeJPEG($img, int $quality, bool $stripMeta): string
    {
        ob_start();
        imageinterlace($img, false);
        imagejpeg($img, null, $quality);
        $buf = ob_get_clean();
        return $buf === false ? '' : $buf;
    }

    private static function bumpMemoryForLargeImage(string $path, int $limitMb): ?string
    {
        if ($limitMb <= 0) return null;
        $info = @getimagesize($path);
        if (!$info) return null;
        $w = (int)($info[0] ?? 0);
        $h = (int)($info[1] ?? 0);
        if ($w <= 0 || $h <= 0) return null;
        $estimate = (int)($w * $h * 5); // approx RGBA + overhead
        $current = self::toBytes(@ini_get('memory_limit'));
        // If current limit seems enough (with 64MB headroom), skip
        if ($current > 0 && $estimate + (64 * 1024 * 1024) < $current) return null;
        $prev = @ini_get('memory_limit');
        @ini_set('memory_limit', (string)$limitMb . 'M');
        return is_string($prev) ? $prev : null;
    }

    private static function toBytes($val): int
    {
        if ($val === false || $val === null || $val === '') return 0;
        if ($val === '-1') return 1 << 30; // treat unlimited as large
        $str = trim((string)$val);
        $unit = strtolower(substr($str, -1));
        $num = (float)$str;
        if ($unit === 'g') return (int)($num * 1024 * 1024 * 1024);
        if ($unit === 'm') return (int)($num * 1024 * 1024);
        if ($unit === 'k') return (int)($num * 1024);
        return (int)$num;
    }
}


