<?php
declare(strict_types=1);

namespace App\Lib;

final class ImageProc
{
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
        if ($mime === 'image/jpeg') { $img = imagecreatefromjpeg($path); }
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
}


