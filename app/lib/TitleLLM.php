<?php
declare(strict_types=1);

namespace App\Lib;

use App\Lib\Settings;

final class TitleLLM
{
    // Title generation utilities

    /**
     * Generate title only. Returns a trimmed title string or empty string on failure.
     * フォールバック機能付き：最初のプロバイダーでエラーが発生した場合、もう片方のプロバイダーで自動的に再試行
     */
    public static function generateTitle(string $previewPath, array $titleCfg, array $ngWords): string
    {
        $cfg = Settings::get();
        $cfgLlm = is_array($cfg['post']['llm'] ?? null) ? $cfg['post']['llm'] : [];
        $provider = (string)($cfgLlm['provider'] ?? '');
        if ($provider === '') {
            throw new \RuntimeException('LLM provider not configured');
        }
        
        $enableFallback = !empty($cfgLlm['fallbackProvider']);
        
        // 最初のプロバイダーで試行
        $result = self::generateTitleWithProvider($previewPath, $titleCfg, $ngWords, $provider);
        
        // 成功した場合はそのまま返す
        if ($result !== '') {
            return $result;
        }
        
        // フォールバックが無効な場合は空文字列を返す
        if (!$enableFallback) {
            return '';
        }
        
        // フォールバックプロバイダーを決定
        $fallbackProvider = ($provider === 'openai') ? 'gemini' : 'openai';
        
        // フォールバックプロバイダーで再試行
        Logger::post([
            'level' => 'info',
            'event' => 'llm.fallback.attempt',
            'primaryProvider' => $provider,
            'fallbackProvider' => $fallbackProvider,
        ]);
        
        $fallbackResult = self::generateTitleWithProvider($previewPath, $titleCfg, $ngWords, $fallbackProvider);
        
        // フォールバック成功
        if ($fallbackResult !== '') {
            Logger::post([
                'level' => 'info',
                'event' => 'llm.fallback.success',
                'primaryProvider' => $provider,
                'fallbackProvider' => $fallbackProvider,
            ]);
            return $fallbackResult;
        }
        
        // フォールバックも失敗
        Logger::post([
            'level' => 'warn',
            'event' => 'llm.fallback.fail',
            'primaryProvider' => $provider,
            'fallbackProvider' => $fallbackProvider,
        ]);
        
        return '';
    }
    
    /**
     * 指定されたプロバイダーでタイトルを生成
     * @param string $previewPath プレビュー画像のパス
     * @param array $titleCfg タイトル設定
     * @param array $ngWords NGワードリスト
     * @param string $provider 使用するプロバイダー ('openai' または 'gemini')
     * @return string 生成されたタイトル、または空文字列
     */
    private static function generateTitleWithProvider(string $previewPath, array $titleCfg, array $ngWords, string $provider): string
    {
        $cfg = Settings::get();
        
        // llmProvidersからモデルを取得
        $llmProviders = is_array($cfg['llmProviders'] ?? null) ? $cfg['llmProviders'] : [];
        $providerConfig = is_array($llmProviders[$provider] ?? null) ? $llmProviders[$provider] : [];
        $model = (string)($providerConfig['model'] ?? '');
        if ($model === '') {
            // デフォルト値を設定
            $model = ($provider === 'openai') ? 'gpt-4o-mini' : 'gemini-2.5-flash-lite';
        }
        
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        $language = $titleCfg['language'] ?? 'en';
        $maxTitle = (int)($titleCfg['maxChars'] ?? 80);
        $tone = (string)($titleCfg['tone'] ?? 'neutral');
        try {
            $imgData = base64_encode(file_get_contents($previewPath));
            if ($provider === 'openai' && $apiKey) {
                $prompt = 'Return only JSON {"title":"..."} for a short, tasteful, minimalist photo title.' . "\n"
                    . 'Language: ' . $language . '; tone ' . $tone . '; max ' . $maxTitle . ' chars.' . "\n"
                    . 'Avoid: ' . implode(', ', $ngWords) . '. No hashtags, emojis, or quotes.' . "\n"
                    . 'If unsafe/inappropriate, return {"title":"none"}. No extra text.';
                $payload = [
                    'model' => $model,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imgData]],
                        ],
                    ]],
                    'max_tokens' => 200,
                ];
                $endpoint = 'https://api.openai.com/v1/chat/completions';
                $t0 = microtime(true);
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $apiKey,
                    ],
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_TIMEOUT => 20,
                ]);
                $res = curl_exec($ch);
                $curlErr = ($res === false) ? curl_error($ch) : '';
                $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                \App\Lib\Logger::post([
                    'level' => ($st >= 200 && $st < 300) ? 'info' : 'warn',
                    'event' => 'llm.request',
                    'provider' => 'openai',
                    'model' => $model,
                    'mode' => 'title_only',
                    'status' => $st,
                    'elapsedMs' => (int)round((microtime(true) - $t0) * 1000),
                    'respSample' => is_string($res) ? mb_substr($res, 0, 200) : '',
                    'curlError' => $curlErr ?: null,
                ]);
                if ($st >= 200 && $st < 300 && is_string($res)) {
                    $json = json_decode($res, true);
                    $text = (string)($json['choices'][0]['message']['content'] ?? '');
                    $trimmed = trim($text);
                    $parsedFrom = 'none';
                    $parsed = json_decode($trimmed, true);
                    if (!is_array($parsed)) {
                        if (preg_match('/\{[\s\S]*\}/', $trimmed, $m)) {
                            $parsed = json_decode($m[0], true);
                        }
                    }
                    $titleOut = '';
                    if (is_array($parsed)) {
                        $t = (string)($parsed['title'] ?? '');
                        $lc = mb_strtolower(trim($t));
                        if ($lc !== 'none') {
                            $t = trim(preg_replace('/\s+/', ' ', $t));
                            $titleOut = mb_substr($t, 0, $maxTitle);
                            $parsedFrom = 'json';
                        }
                    }
                    if ($titleOut === '' && $trimmed !== '') {
                        $t = trim(preg_replace('/\s+/', ' ', $trimmed));
                        $titleOut = mb_substr($t, 0, $maxTitle);
                        $parsedFrom = 'text';
                    }
                    // keep minimal logging via llm.request already
                    if ($titleOut !== '') { return $titleOut; }
                }
            }
            
            if ($provider === 'gemini') {
                $geminiKey = $_ENV['GOOGLE_API_KEY'] ?? ($_ENV['GEMINI_API_KEY'] ?? '');
                if ($geminiKey) {
                    $prompt = 'Return only JSON {"title":"..."} for a short, tasteful, minimalist photo title.' . "\n"
                        . 'Language: ' . $language . '; tone ' . $tone . '; max ' . $maxTitle . ' chars.' . "\n"
                        . 'Avoid: ' . implode(', ', $ngWords) . '. No hashtags, emojis, or quotes.' . "\n"
                        . 'If unsafe/inappropriate, return {"title":"none"}. No extra text.';
                    $payload = [
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [
                                ['text' => $prompt],
                                ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $imgData]],
                            ],
                        ]],
                        'generationConfig' => [
                            'maxOutputTokens' => 512
                        ],
                        // 安全設定を緩和（高リスクコンテンツのみブロック）
                        'safetySettings' => [
                            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                            ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
                            ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                        ],
                    ];
                    $url = 'https://generativelanguage.googleapis.com/v1/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($geminiKey);
                    $t0 = microtime(true);
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                        ],
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode($payload),
                        CURLOPT_TIMEOUT => 20,
                    ]);
                    $res = curl_exec($ch);
                    $curlErr = ($res === false) ? curl_error($ch) : '';
                    $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    \App\Lib\Logger::post([
                        'level' => ($st >= 200 && $st < 300) ? 'info' : 'warn',
                        'event' => 'llm.request',
                        'provider' => 'gemini',
                        'model' => $model,
                        'mode' => 'title_only',
                        'status' => $st,
                        'elapsedMs' => (int)round((microtime(true) - $t0) * 1000),
                        'respSample' => is_string($res) ? mb_substr($res, 0, 200) : '',
                        'curlError' => $curlErr ?: null,
                    ]);
                    if ($st >= 200 && $st < 300 && is_string($res)) {
                        $json = json_decode($res, true);
                        
                        // Geminiの安全フィルターによるブロックをチェック
                        if (isset($json['promptFeedback']['blockReason'])) {
                            \App\Lib\Logger::post([
                                'level' => 'warn',
                                'event' => 'llm.blocked_by_gemini',
                                'provider' => 'gemini',
                                'blockReason' => $json['promptFeedback']['blockReason'],
                                'imageId' => $id ?? null,
                            ]);
                            // ブロックされた場合は空のタイトルを返す（投稿は続行）
                            return '';
                        }
                        
                        // candidatesがない場合もブロックの可能性
                        if (empty($json['candidates'])) {
                            \App\Lib\Logger::post([
                                'level' => 'warn',
                                'event' => 'llm.no_candidates',
                                'provider' => 'gemini',
                                'imageId' => $id ?? null,
                            ]);
                            return '';
                        }
                        
                        $finish = $json['candidates'][0]['finishReason'] ?? null;
                        $parts = $json['candidates'][0]['content']['parts'] ?? [];
                        $text = '';
                        if (is_array($parts)) {
                            foreach ($parts as $part) {
                                if (isset($part['text']) && is_string($part['text'])) { $text .= (string)$part['text']; }
                            }
                        }
                        // no retry: use the first response as-is
                        $trimmed = trim((string)$text);
                        $parsedFrom = 'none';
                        $parsed = json_decode($trimmed, true);
                        if (!is_array($parsed)) {
                            if (preg_match('/\{[\s\S]*\}/', $trimmed, $m)) {
                                $parsed = json_decode($m[0], true);
                            }
                        }
                        $titleOut = '';
                        if (is_array($parsed)) {
                            $t = (string)($parsed['title'] ?? '');
                            $lc = mb_strtolower(trim($t));
                            if ($lc !== 'none') {
                                $t = trim(preg_replace('/\s+/', ' ', $t));
                                $titleOut = mb_substr($t, 0, $maxTitle);
                                $parsedFrom = 'json';
                            }
                        }
                        if ($titleOut === '' && $trimmed !== '') {
                            $t = trim(preg_replace('/\s+/', ' ', $trimmed));
                            $titleOut = mb_substr($t, 0, $maxTitle);
                            $parsedFrom = 'text';
                        }
                        // keep minimal logging via llm.request already
                        if ($titleOut !== '') {
                            return $titleOut;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            \App\Lib\Logger::post([
                'level' => 'error',
                'event' => 'llm.exception',
                'provider' => $provider ?? 'unknown',
                'model' => $model ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
        return '';
    }

}


