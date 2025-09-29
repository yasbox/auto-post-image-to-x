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
        if (class_exists('Imagick') && is_a($img, 'Imagick')) {
            if (method_exists($img, 'destroy')) { $img->destroy(); }
        } else {
            imagedestroy($img);
        }
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
        if (class_exists('Imagick') && is_a($img, 'Imagick')) {
            if (method_exists($img, 'destroy')) { $img->destroy(); }
        } else {
            imagedestroy($img);
        }
        return $out;
    }

    public static function makeTweetImage(string $srcPath, array $p, string $id): string
    {
		$__t0 = microtime(true);
        [$img, $w, $h] = self::loadBitmap($srcPath);
        \App\Lib\Logger::post([
            'level' => 'info', 'event' => 'opt.trace.start', 'imageId' => $id,
            'srcW' => $w, 'srcH' => $h, 'maxLongEdge' => (int)$p['tweetMaxLongEdge'],
        ]);
        [$img, $w, $h] = self::resizeMaxLongEdge($img, $w, $h, (int)$p['tweetMaxLongEdge']);
		\App\Lib\Logger::post([
			'level' => 'info', 'event' => 'opt.trace.resized', 'imageId' => $id,
			'w' => $w, 'h' => $h,
		]);
        $qMin = (int)$p['tweetQualityMin'];
        $qMax = (int)$p['tweetQualityMax'];
        $best = null;

        // Binary search quality
        $lo = $qMin; $hi = $qMax;
        while ($hi - $lo > 2) {
            $q = intdiv($lo + $hi, 2);
            $buf = self::encodeJPEG($img, $q, (bool)$p['stripMetadataOnTweet']);
			$bytes = strlen($buf);
			$limit = (int)$p['tweetMaxBytes'];
			\App\Lib\Logger::post([
				'level' => 'info', 'event' => 'opt.trace.search', 'imageId' => $id,
				'q' => $q, 'bytes' => $bytes, 'within' => $bytes <= $limit, 'lo' => $lo, 'hi' => $hi,
			]);
			if ($bytes <= $limit) { $best = $buf; $lo = $q; } else { $hi = $q; }
        }

        $out = __DIR__ . '/../data/tmp/tweet/' . $id . '.jpg';
        $attempt = 0;
        if ($best && strlen($best) <= (int)$p['tweetMaxBytes']) {
            file_put_contents($out, $best);
            \App\Lib\Logger::post([
				'level' => 'info', 'event' => 'opt.trace.done', 'imageId' => $id,
				'q' => $lo, 'bytes' => strlen($best), 'elapsedMs' => (int)round((microtime(true)-$__t0)*1000),
			]);
            if (class_exists('Imagick') && is_a($img, 'Imagick')) {
                if (method_exists($img, 'destroy')) { $img->destroy(); }
            } else {
                imagedestroy($img);
            }
            return $out;
        }

        // Fallback: scale down up to 5 times
        while ($attempt < 5) {
            $attempt++;
            if (class_exists('Imagick') && is_a($img, 'Imagick')) {
                $w = max(1, (int)round($w * 0.9));
                $h = max(1, (int)round($h * 0.9));
                if (method_exists($img, 'resizeImage')) { $img->resizeImage($w, $h, 0 /* FILTER_UNDEFINED */, 1); }
            } else {
                [$img, $w, $h] = self::resizeScale($img, $w, $h, 0.9);
            }
			\App\Lib\Logger::post([
				'level' => 'info', 'event' => 'opt.trace.scale', 'imageId' => $id,
				'attempt' => $attempt, 'w' => $w, 'h' => $h,
			]);
            $lo = $qMin; $hi = $qMax; $best = null;
            while ($hi - $lo > 2) {
                $q = intdiv($lo + $hi, 2);
                $buf = self::encodeJPEG($img, $q, (bool)$p['stripMetadataOnTweet']);
				$bytes = strlen($buf);
				$limit = (int)$p['tweetMaxBytes'];
				\App\Lib\Logger::post([
					'level' => 'info', 'event' => 'opt.trace.search', 'imageId' => $id,
					'q' => $q, 'bytes' => $bytes, 'within' => $bytes <= $limit, 'lo' => $lo, 'hi' => $hi, 'attempt' => $attempt,
				]);
				if ($bytes <= $limit) { $best = $buf; $lo = $q; } else { $hi = $q; }
            }
            if ($best && strlen($best) <= (int)$p['tweetMaxBytes']) {
                file_put_contents($out, $best);
                \App\Lib\Logger::post([
					'level' => 'info', 'event' => 'opt.trace.done', 'imageId' => $id,
					'q' => $lo, 'bytes' => strlen($best), 'attempt' => $attempt, 'elapsedMs' => (int)round((microtime(true)-$__t0)*1000),
				]);
                if (class_exists('Imagick') && is_a($img, 'Imagick')) {
                    if (method_exists($img, 'destroy')) { $img->destroy(); }
                } else {
                    imagedestroy($img);
                }
                return $out;
            }
        }

        if (class_exists('Imagick') && is_a($img, 'Imagick')) {
            if (method_exists($img, 'destroy')) { $img->destroy(); }
        } else {
            imagedestroy($img);
        }
		\App\Lib\Logger::post([
			'level' => 'error', 'event' => 'opt.trace.fail', 'imageId' => $id,
			'elapsedMs' => (int)round((microtime(true)-$__t0)*1000),
		]);
        throw new \RuntimeException('Cannot fit under size limit');
    }

    private static function loadBitmap(string $path): array
    {
        // Prefer Imagick when available for speed/memory efficiency on large images
        if (class_exists('Imagick')) {
            try {
                /** @var object $im */
                $cls = 'Imagick';
                $im = new $cls();
                $im->readImage($path);
                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
                return [$im, $w, $h];
            } catch (\Throwable $e) {
                // fallback to GD below
            }
        }
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
        if (class_exists('Imagick') && is_a($img, 'Imagick')) {
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            if (method_exists($img, 'resizeImage')) { $img->resizeImage($nw, $nh, 12 /* LANCZOS */, 1); }
            return [$img, $nw, $nh];
        }
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
        if (class_exists('Imagick') && is_a($img, 'Imagick')) {
            try {
                if ($stripMeta && method_exists($img, 'stripImage')) { $img->stripImage(); }
                if (method_exists($img, 'setImageFormat')) { $img->setImageFormat('jpeg'); }
                if (method_exists($img, 'setImageCompressionQuality')) { $img->setImageCompressionQuality($quality); }
                return (string)(method_exists($img, 'getImageBlob') ? $img->getImageBlob() : '');
            } catch (\Throwable $e) {
                // fallthrough to GD fallback below
            }
        }
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


