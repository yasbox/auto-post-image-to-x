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
        
        // 後方互換性: llmProvidersが存在しない場合、既存の設定から構築
        if (!isset($data['llmProviders']) || !is_array($data['llmProviders'])) {
            $data['llmProviders'] = [];
            
            // post.llm.model から openai のモデルを取得
            if (isset($data['post']['llm']['model']) && is_string($data['post']['llm']['model'])) {
                $model = trim($data['post']['llm']['model']);
                if ($model !== '') {
                    // モデル名からプロバイダを推測
                    if (strpos(strtolower($model), 'gemini-') === 0) {
                        $data['llmProviders']['gemini'] = ['model' => $model];
                    } else {
                        $data['llmProviders']['openai'] = ['model' => $model];
                    }
                }
            }
            
            // sensitiveDetection.model から gemini のモデルを取得
            if (isset($data['sensitiveDetection']['model']) && is_string($data['sensitiveDetection']['model'])) {
                $model = trim($data['sensitiveDetection']['model']);
                if ($model !== '') {
                    // モデル名からプロバイダを推測
                    if (strpos(strtolower($model), 'gemini-') === 0) {
                        $data['llmProviders']['gemini'] = ['model' => $model];
                    } else {
                        $data['llmProviders']['openai'] = ['model' => $model];
                    }
                }
            }
            
            // デフォルト値を設定（まだ設定されていないプロバイダがある場合）
            if (!isset($data['llmProviders']['openai'])) {
                $data['llmProviders']['openai'] = ['model' => 'gpt-4o-mini'];
            }
            if (!isset($data['llmProviders']['gemini'])) {
                $data['llmProviders']['gemini'] = ['model' => 'gemini-2.5-flash-lite'];
            }
        }
        
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
                'mode' => 'fixed',
                'fixedTimes' => ['09:00', '13:00', '21:00'],
                'intervalMinutes' => 480,
                'perDayCount' => 3,
                'minSpacingMinutes' => 120,
                'jitterMinutes' => 0,
                'skipIfEmpty' => true,
            ],
            'upload' => [
                'chunkSize' => 2000000,
                'concurrency' => 3,
                'allowedMime' => ['image/jpeg', 'image/png', 'image/webp'],
                'maxClientFileSizeHintMB' => 8192,
            ],
            'llmProviders' => [
                'openai' => [
                    'model' => 'gpt-4o-mini',
                ],
                'gemini' => [
                    'model' => 'gemini-2.5-flash-lite',
                ],
            ],
            'post' => [
                'llm' => [
                    'provider' => 'openai',
                    'fallbackProvider' => false,
                ],
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
            'sensitiveDetection' => [
                'enabled' => false,
                'provider' => 'gemini',
                'threshold' => 61,
                'adultContentThreshold' => 71,
                'fallbackProvider' => false,
            ],
        ];
    }
}


