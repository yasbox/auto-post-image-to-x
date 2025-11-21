<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * センシティブコンテンツ判定クラス
 * OpenAI GPT-4o-mini または Google Gemini を使用して画像を分析
 */
final class SensitiveDetector
{
    /**
     * 画像のセンシティブ判定を実行
     * 
     * @param string $imagePath 判定する画像のパス
     * @param string $provider 使用するAPI ('openai' または 'gemini')
     * @return array ['score' => int, 'judgment' => string, 'reason' => string] または ['error' => true, 'message' => string]
     */
    public static function analyze(string $imagePath, string $provider): array
    {
        if (!file_exists($imagePath)) {
            return ['error' => true, 'message' => '画像ファイルが見つかりません'];
        }

        try {
            if ($provider === 'openai') {
                return self::analyzeWithOpenAI($imagePath);
            } elseif ($provider === 'gemini') {
                return self::analyzeWithGemini($imagePath);
            } else {
                return ['error' => true, 'message' => '不明なプロバイダー: ' . $provider];
            }
        } catch (\Throwable $e) {
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * 画像のセンシティブ判定を実行（フォールバック機能付き）
     * 最初のプロバイダーでエラーが発生した場合、もう片方のプロバイダーで自動的に再試行
     * 
     * @param string $imagePath 判定する画像のパス
     * @param string $primaryProvider 最初に使用するAPI ('openai' または 'gemini')
     * @param bool $enableFallback フォールバックを有効にするかどうか
     * @return array ['score' => int, 'judgment' => string, 'reason' => string] または ['error' => true, 'message' => string]
     */
    public static function analyzeWithFallback(string $imagePath, string $primaryProvider, bool $enableFallback = false): array
    {
        if (!file_exists($imagePath)) {
            return ['error' => true, 'message' => '画像ファイルが見つかりません'];
        }

        // 最初のプロバイダーで試行
        $result = self::analyze($imagePath, $primaryProvider);
        
        // エラーがなく、フォールバックが無効な場合はそのまま返す
        if (!isset($result['error']) || !$result['error']) {
            return $result;
        }

        // フォールバックが無効な場合はエラーをそのまま返す
        if (!$enableFallback) {
            return $result;
        }

        // フォールバックプロバイダーを決定
        $fallbackProvider = ($primaryProvider === 'openai') ? 'gemini' : 'openai';
        
        // フォールバックプロバイダーで再試行
        Logger::post([
            'level' => 'info',
            'event' => 'sensitive.fallback.attempt',
            'primaryProvider' => $primaryProvider,
            'fallbackProvider' => $fallbackProvider,
            'primaryError' => $result['message'] ?? 'Unknown error',
        ]);

        $fallbackResult = self::analyze($imagePath, $fallbackProvider);
        
        // フォールバックも失敗した場合
        if (isset($fallbackResult['error']) && $fallbackResult['error']) {
            Logger::post([
                'level' => 'error',
                'event' => 'sensitive.fallback.fail',
                'primaryProvider' => $primaryProvider,
                'fallbackProvider' => $fallbackProvider,
                'primaryError' => $result['message'] ?? 'Unknown error',
                'fallbackError' => $fallbackResult['message'] ?? 'Unknown error',
            ]);
            return [
                'error' => true,
                'message' => '両方のAPIでエラーが発生しました。プライマリ(' . $primaryProvider . '): ' . $result['message'] . ' / フォールバック(' . $fallbackProvider . '): ' . $fallbackResult['message']
            ];
        }

        // フォールバック成功
        Logger::post([
            'level' => 'info',
            'event' => 'sensitive.fallback.success',
            'primaryProvider' => $primaryProvider,
            'fallbackProvider' => $fallbackProvider,
        ]);

        return $fallbackResult;
    }

    /**
     * OpenAI GPT-4o-mini で判定
     */
    private static function analyzeWithOpenAI(string $imagePath): array
    {
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        if ($apiKey === '') {
            return ['error' => true, 'message' => 'OPENAI_API_KEY が設定されていません'];
        }

        $cfg = Settings::get();
        // llmProvidersからモデルを取得
        $llmProviders = is_array($cfg['llmProviders'] ?? null) ? $cfg['llmProviders'] : [];
        $providerConfig = is_array($llmProviders['openai'] ?? null) ? $llmProviders['openai'] : [];
        $model = (string)($providerConfig['model'] ?? 'gpt-4o-mini');
        if ($model === '') {
            $model = 'gpt-4o-mini'; // フォールバック
        }

        $imgData = base64_encode(file_get_contents($imagePath));
        
        $prompt = self::getPrompt();

        $payload = [
            'model' => $model,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imgData]],
                ],
            ]],
            'max_tokens' => 300,
            'temperature' => 0.3,
        ];

        $t0 = microtime(true);
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15,
        ]);

        $res = curl_exec($ch);
        $curlErr = ($res === false) ? curl_error($ch) : '';
        $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $elapsed = (int)round((microtime(true) - $t0) * 1000);

        if ($curlErr !== '') {
            Logger::post([
                'level' => 'error',
                'event' => 'sensitive.api_error',
                'provider' => 'openai',
                'error' => $curlErr,
                'elapsedMs' => $elapsed,
            ]);
            return ['error' => true, 'message' => 'OpenAI API エラー: ' . $curlErr];
        }

        if ($st < 200 || $st >= 300) {
            Logger::post([
                'level' => 'error',
                'event' => 'sensitive.http_error',
                'provider' => 'openai',
                'status' => $st,
                'response' => is_string($res) ? mb_substr($res, 0, 200) : '',
                'elapsedMs' => $elapsed,
            ]);
            return ['error' => true, 'message' => 'OpenAI API エラー (HTTP ' . $st . ')'];
        }

        return self::parseResponse($res, 'openai', $elapsed);
    }

    /**
     * Google Gemini で判定
     */
    private static function analyzeWithGemini(string $imagePath): array
    {
        $apiKey = $_ENV['GOOGLE_API_KEY'] ?? ($_ENV['GEMINI_API_KEY'] ?? '');
        if ($apiKey === '') {
            return ['error' => true, 'message' => 'GOOGLE_API_KEY または GEMINI_API_KEY が設定されていません'];
        }

        $cfg = Settings::get();
        // llmProvidersからモデルを取得
        $llmProviders = is_array($cfg['llmProviders'] ?? null) ? $cfg['llmProviders'] : [];
        $providerConfig = is_array($llmProviders['gemini'] ?? null) ? $llmProviders['gemini'] : [];
        $model = (string)($providerConfig['model'] ?? 'gemini-2.5-flash-lite');
        if ($model === '') {
            $model = 'gemini-2.5-flash-lite'; // フォールバック
        }

        $imgData = base64_encode(file_get_contents($imagePath));
        
        $prompt = self::getPrompt();

                    $payload = [
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [
                                ['text' => $prompt],
                                ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $imgData]],
                            ],
                        ]],
                        'generationConfig' => [
                            'maxOutputTokens' => 512,
                            'temperature' => 0.3,
                        ],
                        // 安全設定を緩和（高リスクコンテンツのみブロック）
                        'safetySettings' => [
                            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                            ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
                            ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                        ],
                    ];

        $url = 'https://generativelanguage.googleapis.com/v1/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($apiKey);

        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15,
        ]);

        $res = curl_exec($ch);
        $curlErr = ($res === false) ? curl_error($ch) : '';
        $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $elapsed = (int)round((microtime(true) - $t0) * 1000);

        if ($curlErr !== '') {
            Logger::post([
                'level' => 'error',
                'event' => 'sensitive.api_error',
                'provider' => 'gemini',
                'error' => $curlErr,
                'elapsedMs' => $elapsed,
            ]);
            return ['error' => true, 'message' => 'Gemini API エラー: ' . $curlErr];
        }

        if ($st < 200 || $st >= 300) {
            Logger::post([
                'level' => 'error',
                'event' => 'sensitive.http_error',
                'provider' => 'gemini',
                'status' => $st,
                'response' => is_string($res) ? mb_substr($res, 0, 200) : '',
                'elapsedMs' => $elapsed,
            ]);
            return ['error' => true, 'message' => 'Gemini API エラー (HTTP ' . $st . ')'];
        }

        return self::parseResponse($res, 'gemini', $elapsed);
    }

    /**
     * プロンプト文字列を取得
     */
    private static function getPrompt(): string
    {
        return <<<'PROMPT'
あなたはSNS投稿画像のセンシティブ判定AIです。
X（旧Twitter）の自動判定基準に準拠して、適切に判定してください。

判定基準：
- 水着・グラビア・コスプレは露出度と構図を慎重に評価する
- 露出が多い場合や挑発的な構図はセンシティブ寄りに判定
- 意図的に性的魅力を強調している場合は厳しく判定
- 芸術的・ファッション的でも露出が多ければ注意が必要
- 明確に性的・暴力的・搾取的な表現は確実にアウト

スコア目安：
0-30: 完全にセーフ（一般的なファッション、ポートレート）
31-50: 軽度注意（水着だが露出控えめ、構図も健全）
51-70: センシティブ判定推奨（グラビア、露出多め、構図が挑発的）
71-90: SNS上でぼかし確定（露出強調、性的要素あり）
91-100: 成人向け確定（明確に性的・暴力的）

重要：
- 水着姿でも露出が多い、またはポーズ・アングルが性的魅力を強調している場合は51以上
- バストやヒップを強調する構図・ポーズは厳しく判定
- 「芸術的」「ファッション的」でも露出度が高ければセンシティブ判定

次の形式のJSONのみを返してください（他の説明文は一切不要）：
{
  "score": 数値(0-100),
  "judgment": "safe" または "sensitive",
  "reason": "判定理由（簡潔に1-2行）"
}
PROMPT;
    }

    /**
     * APIレスポンスをパースして統一形式に変換
     */
    private static function parseResponse(string $response, string $provider, int $elapsed): array
    {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['error' => true, 'message' => 'JSON パースエラー'];
        }

        $text = '';
        
        if ($provider === 'openai') {
            $text = $data['choices'][0]['message']['content'] ?? '';
        } elseif ($provider === 'gemini') {
            // Geminiの安全フィルターによるブロックをチェック
            if (isset($data['promptFeedback']['blockReason'])) {
                $blockReason = $data['promptFeedback']['blockReason'];
                $safetyRatings = $data['promptFeedback']['safetyRatings'] ?? [];
                
                // ブロックされたカテゴリーを特定
                $blockedCategories = [];
                foreach ($safetyRatings as $rating) {
                    if (isset($rating['blocked']) && $rating['blocked']) {
                        $blockedCategories[] = [
                            'category' => $rating['category'] ?? 'UNKNOWN',
                            'probability' => $rating['probability'] ?? 'UNKNOWN',
                        ];
                    }
                }
                
                Logger::post([
                    'level' => 'error',
                    'event' => 'sensitive.blocked_by_gemini',
                    'provider' => $provider,
                    'blockReason' => $blockReason,
                    'safetyRatings' => $safetyRatings,
                    'blockedCategories' => $blockedCategories,
                    'elapsedMs' => $elapsed,
                ]);
                
                $detailMsg = 'Gemini APIが画像をブロックしました（' . $blockReason . '）';
                if (!empty($blockedCategories)) {
                    $categoryNames = array_map(fn($c) => $c['category'] . ':' . $c['probability'], $blockedCategories);
                    $detailMsg .= ' - ' . implode(', ', $categoryNames);
                }
                
                return ['error' => true, 'message' => $detailMsg . '。OpenAI APIの使用を検討してください。'];
            }
            
            // candidatesがない、または空の場合もブロックと判断
            if (empty($data['candidates'])) {
                // candidatesが空でもsafetyRatingsがあるかチェック
                $safetyRatings = $data['promptFeedback']['safetyRatings'] ?? [];
                
                Logger::post([
                    'level' => 'error',
                    'event' => 'sensitive.no_candidates',
                    'provider' => $provider,
                    'safetyRatings' => $safetyRatings,
                    'elapsedMs' => $elapsed,
                ]);
                return ['error' => true, 'message' => 'Gemini APIから応答がありませんでした（安全フィルターによりブロックされた可能性があります）'];
            }
            
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        // JSON部分を抽出（マークダウンコードブロックを除去）
        $text = trim($text);
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i', '', $text);
        $text = trim($text);

        $result = json_decode($text, true);
        
        if (!is_array($result) || !isset($result['score']) || !isset($result['judgment'])) {
            Logger::post([
                'level' => 'error',
                'event' => 'sensitive.parse_error',
                'provider' => $provider,
                'response' => mb_substr($text, 0, 500),
                'elapsedMs' => $elapsed,
            ]);
            return ['error' => true, 'message' => 'レスポンス解析エラー。AI APIの応答が期待された形式ではありませんでした。'];
        }

        // スコアを整数に変換して範囲チェック
        $score = (int)$result['score'];
        $score = max(0, min(100, $score));

        $judgment = (string)($result['judgment'] ?? 'safe');
        $reason = (string)($result['reason'] ?? '');

        // ログは呼び出し側で出力（imageIdやcategoryを含めるため）
        return [
            'score' => $score,
            'judgment' => $judgment,
            'reason' => $reason,
            'provider' => $provider,
            'elapsedMs' => $elapsed,
        ];
    }
}

