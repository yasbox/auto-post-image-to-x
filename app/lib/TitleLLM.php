<?php
declare(strict_types=1);

namespace App\Lib;

final class TitleLLM
{
    public static function generate(string $previewPath, array $titleCfg, int $textMax, array $ngWords): string
    {
        $provider = $_ENV['LLM_PROVIDER'] ?? 'openai';
        $model = $_ENV['LLM_MODEL'] ?? 'gpt-4o-mini';
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        $prompt = 'Generate a concise English photo title. Max ' . (int)$titleCfg['maxChars'] . ' chars. Tone: ' . ($titleCfg['tone'] ?? 'neutral') . '. Avoid: ' . implode(', ', $ngWords);
        try {
            $imgData = base64_encode(file_get_contents($previewPath));
            if ($provider === 'openai' && $apiKey) {
                $payload = [
                    'model' => $model,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imgData]],
                        ],
                    ]],
                    'max_tokens' => 50,
                ];
                $ch = curl_init('https://api.openai.com/v1/chat/completions');
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
                if ($res === false) throw new \RuntimeException('curl error');
                $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($st >= 200 && $st < 300) {
                    $json = json_decode($res, true);
                    $text = $json['choices'][0]['message']['content'] ?? '';
                    $title = trim(preg_replace('/\s+/', ' ', $text));
                    $title = mb_substr($title, 0, (int)$titleCfg['maxChars']);
                    if ($title !== '') return $title;
                }
            }
        } catch (\Throwable $e) {
            // fallthrough
        }
        return 'Untitled';
    }
}


