<?php
declare(strict_types=1);

namespace App\Lib;

final class Settings
{
    private static ?array $cache = null;

    public static function get(): array
    {
        if (self::$cache !== null) return self::$cache;
        $path = __DIR__ . '/../config/config.json';
        $data = Util::readJson($path, self::default());
        self::$cache = $data;
        return $data;
    }

    public static function save(array $data): void
    {
        $path = __DIR__ . '/../config/config.json';
        Util::writeJson($path, $data);
        self::$cache = $data;
    }

    public static function security(string $key): string
    {
        $cfg = self::get();
        return (string)($cfg['security'][$key] ?? '');
    }

    private static function default(): array
    {
        return [
            'timezone' => 'Asia/Tokyo',
            'schedule' => [
                'enabled' => true,
                'mode' => 'both',
                'fixedTimes' => ['09:00', '13:00', '21:00'],
                'intervalMinutes' => 480,
                'jitterMinutes' => 0,
                'skipIfEmpty' => true,
            ],
            'upload' => [
                'chunkSize' => 2000000,
                'concurrency' => 3,
                'allowedMime' => ['image/jpeg', 'image/png', 'image/webp'],
                'maxClientFileSizeHintMB' => 8192,
            ],
            'post' => [
                'title' => [
                    'enabled' => true,
                    'language' => 'en',
                    'maxChars' => 80,
                    'tone' => 'neutral',
                    'ngWords' => ['example1','example2'],
                ],
                'hashtags' => [
                    'min' => 1,
                    'max' => 3,
                    'prepend' => true,
                    'source' => 'tags.txt',
                ],
                'textMax' => 280,
                'deleteOriginalOnSuccess' => true,
                'keepOnFailure' => true,
            ],
            'imagePolicy' => [
                'tweetMaxBytes' => 5000000,
                'tweetFormat' => 'jpeg',
                'tweetMaxLongEdge' => 4096,
                'tweetQualityMin' => 60,
                'tweetQualityMax' => 92,
                'stripMetadataOnTweet' => true,
                'llmPreviewLongEdge' => 500,
                'llmPreviewQuality' => 70,
                'stripMetadataOnLLM' => true,
            ],
            'thumb' => [
                'enabled' => true,
                'longEdge' => 512,
                'quality' => 70,
                'stripMetadata' => true,
                'memoryLimitMB' => 512,
            ],
            'xapi' => [
                'useAltText' => false,
                'duplicateCheck' => false,
            ],
            'security' => [
                'sessionName' => 'XPOSTSESS',
                'csrfCookieName' => 'XPOSTCSRF',
                'passwordHashAlgo' => 'PASSWORD_DEFAULT',
            ],
            'logs' => [
                'retentionDays' => 31,
            ],
        ];
    }
}


