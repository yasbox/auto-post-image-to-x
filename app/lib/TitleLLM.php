<?php
declare(strict_types=1);

namespace App\Lib;

use App\Lib\Settings;

final class TitleLLM
{
    // Removed generate() and pickTags() as code now uses generateAndPickTags() in a single call

    /**
     * Generate title and pick suitable tags in a single LLM call.
     * Returns ['title' => string, 'tags' => string[]]. On failure, returns empty title and [] tags.
     */
    public static function generateAndPickTags(string $previewPath, array $titleCfg, int $textMax, array $ngWords, array $candidates, int $num): array
    {
        $cfg = Settings::get();
        $cfgLlm = is_array($cfg['post']['llm'] ?? null) ? $cfg['post']['llm'] : [];
        $provider = $_ENV['LLM_PROVIDER'] ?? (string)($cfgLlm['provider'] ?? 'openai');
        $model = $_ENV['LLM_MODEL'] ?? (string)($cfgLlm['model'] ?? ($provider === 'gemini' ? 'gemini-1.5-flash' : 'gpt-4o-mini'));
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        $language = $titleCfg['language'] ?? 'en';
        $maxTitle = (int)($titleCfg['maxChars'] ?? 80);
        $tone = (string)($titleCfg['tone'] ?? 'neutral');
        $num = max(0, (int)$num);
        try {
            $imgData = base64_encode(file_get_contents($previewPath));
            if ($provider === 'openai' && $apiKey) {
                // normalize candidate tags (strip #, collapse spaces)
                $candidates = array_values(array_filter(array_map(static function ($t) {
                    $t = trim((string)$t);
                    if ($t === '') return null;
                    $t = ltrim($t, '#');
                    return preg_replace('/\s+/', ' ', $t);
                }, $candidates)));
                $maxList = 500;
                if (count($candidates) > $maxList) {
                    $candidates = array_slice($candidates, 0, $maxList);
                }
                $listText = json_encode($candidates, JSON_UNESCAPED_UNICODE);
                $prompt = 'Task: Create a short, tasteful, minimalist photo title and select relevant tags, using the given candidate tag list.' . "\n"
                    . 'Language: ' . $language . ".\n"
                    . 'Title rules: subtle/evocative, not a literal description, tone ' . $tone . ', max ' . $maxTitle . ' chars, avoid: ' . implode(', ', $ngWords) . '. No hashtags, emojis, or quotes.' . "\n"
                    . 'If a safe/appropriate title cannot be created (e.g., sensitive content), set the title to the string "none" but still return relevant tags.' . "\n"
                    . 'Tags rules: choose at most ' . $num . ' tags ONLY from the candidate list. Do not invent tags. Exclude the # symbol. If none are appropriate, use an empty array.' . "\n"
                    . 'Output format: JSON object with exactly these keys: {"title": string, "tags": string[]}. No extra text.' . "\n"
                    . 'Candidates (JSON array): ' . $listText;
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
                    $text = (string)($json['choices'][0]['message']['content'] ?? '');
                    $trimmed = trim($text);
                    // Try parse JSON object
                    $parsed = json_decode($trimmed, true);
                    if (!is_array($parsed)) {
                        if (preg_match('/\{[\s\S]*\}/', $trimmed, $m)) {
                            $parsed = json_decode($m[0], true);
                        }
                    }
                    $outTitle = '';
                    $outTags = [];
                    if (is_array($parsed)) {
                        $t = (string)($parsed['title'] ?? '');
                        $lc = mb_strtolower(trim($t));
                        if ($lc !== 'none') {
                            $t = trim(preg_replace('/\s+/', ' ', $t));
                            $t = mb_substr($t, 0, $maxTitle);
                            $outTitle = $t;
                        }
                        $tagsArr = is_array($parsed['tags'] ?? null) ? $parsed['tags'] : [];
                        foreach ($tagsArr as $tg) {
                            if (!is_string($tg)) continue;
                            $tg = trim(ltrim($tg, '#'));
                            if ($tg === '') continue;
                            $outTags[] = $tg;
                            if (count($outTags) >= $num) break;
                        }
                        return ['title' => $outTitle, 'tags' => $outTags];
                    }
                }
                // Fallback: tags-only request if no structured result was obtained
                // or if tags array ended up empty
                try {
                    $prompt = 'Task: Select relevant tags for a social media photo using ONLY the given candidate tag list, based on the attached image.' . "\n"
                        . 'Language: ' . $language . ".\n"
                        . 'Rules: choose at most ' . $num . ' tags ONLY from the candidate list. Do not invent tags. Exclude the # symbol. If none are appropriate, output an empty array.' . "\n"
                        . 'Output format: JSON object with exactly this key: {"tags": string[]}. No extra text.' . "\n"
                        . 'Candidates (JSON array): ' . $listText;
                    $payload = [
                        'model' => $model,
                        'messages' => [[
                            'role' => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => $prompt],
                                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imgData]],
                            ],
                        ]],
                        'max_tokens' => 150,
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
                        $text = (string)($json['choices'][0]['message']['content'] ?? '');
                        $trimmed = trim($text);
                        $parsed = json_decode($trimmed, true);
                        if (!is_array($parsed)) {
                            if (preg_match('/\{[\s\S]*\}/', $trimmed, $m)) {
                                $parsed = json_decode($m[0], true);
                            }
                        }
                        if (is_array($parsed)) {
                            $outTags = [];
                            $tagsArr = is_array($parsed['tags'] ?? null) ? $parsed['tags'] : [];
                            foreach ($tagsArr as $tg) {
                                if (!is_string($tg)) continue;
                                $tg = trim(ltrim($tg, '#'));
                                if ($tg === '') continue;
                                $outTags[] = $tg;
                                if (count($outTags) >= $num) break;
                            }
                            return ['title' => '', 'tags' => $outTags];
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore and fallthrough to return empty
                }
            } elseif ($provider === 'gemini') {
                $geminiKey = $_ENV['GOOGLE_API_KEY'] ?? ($_ENV['GEMINI_API_KEY'] ?? '');
                if ($geminiKey) {
                    // normalize candidate tags (strip #, collapse spaces)
                    $candidates = array_values(array_filter(array_map(static function ($t) {
                        $t = trim((string)$t);
                        if ($t === '') return null;
                        $t = ltrim($t, '#');
                        return preg_replace('/\s+/', ' ', $t);
                    }, $candidates)));
                    $maxList = 500;
                    if (count($candidates) > $maxList) {
                        $candidates = array_slice($candidates, 0, $maxList);
                    }
                    $listText = json_encode($candidates, JSON_UNESCAPED_UNICODE);
                    $prompt = 'Task: Create a short, tasteful, minimalist photo title and select relevant tags, using the given candidate tag list.' . "\n"
                        . 'Language: ' . $language . ".\n"
                        . 'Title rules: subtle/evocative, not a literal description, tone ' . $tone . ', max ' . $maxTitle . ' chars, avoid: ' . implode(', ', $ngWords) . '. No hashtags, emojis, or quotes.' . "\n"
                        . 'If a safe/appropriate title cannot be created (e.g., sensitive content), set the title to the string "none" but still return relevant tags.' . "\n"
                        . 'Tags rules: choose at most ' . $num . ' tags ONLY from the candidate list. Do not invent tags. Exclude the # symbol. If none are appropriate, use an empty array.' . "\n"
                        . 'Output format: JSON object with exactly these keys: {"title": string, "tags": string[]}. No extra text.' . "\n"
                        . 'Candidates (JSON array): ' . $listText;
                    $payload = [
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [
                                ['text' => $prompt],
                                ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $imgData]],
                            ],
                        ]],
                        'generationConfig' => [
                            'maxOutputTokens' => 200,
                        ],
                    ];
                    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($geminiKey);
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
                    if ($res === false) throw new \RuntimeException('curl error');
                    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($st >= 200 && $st < 300) {
                        $json = json_decode($res, true);
                        $text = '';
                        if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                            $text = (string)$json['candidates'][0]['content']['parts'][0]['text'];
                        }
                        $trimmed = trim($text);
                        $parsed = json_decode($trimmed, true);
                        if (!is_array($parsed)) {
                            if (preg_match('/\{[\s\S]*\}/', $trimmed, $m)) {
                                $parsed = json_decode($m[0], true);
                            }
                        }
                        $outTitle = '';
                        $outTags = [];
                        if (is_array($parsed)) {
                            $t = (string)($parsed['title'] ?? '');
                            $lc = mb_strtolower(trim($t));
                            if ($lc !== 'none') {
                                $t = trim(preg_replace('/\s+/', ' ', $t));
                                $t = mb_substr($t, 0, $maxTitle);
                                $outTitle = $t;
                            }
                            $tagsArr = is_array($parsed['tags'] ?? null) ? $parsed['tags'] : [];
                            foreach ($tagsArr as $tg) {
                                if (!is_string($tg)) continue;
                                $tg = trim(ltrim($tg, '#'));
                                if ($tg === '') continue;
                                $outTags[] = $tg;
                                if (count($outTags) >= $num) break;
                            }
                            return ['title' => $outTitle, 'tags' => $outTags];
                        }
                    }
                    // Fallback: tags-only request
                    try {
                        $prompt = 'Task: Select relevant tags for a social media photo using ONLY the given candidate tag list, based on the attached image.' . "\n"
                            . 'Language: ' . $language . ".\n"
                            . 'Rules: choose at most ' . $num . ' tags ONLY from the candidate list. Do not invent tags. Exclude the # symbol. If none are appropriate, output an empty array.' . "\n"
                            . 'Output format: JSON object with exactly this key: {"tags": string[]}. No extra text.' . "\n"
                            . 'Candidates (JSON array): ' . $listText;
                        $payload = [
                            'contents' => [[
                                'role' => 'user',
                                'parts' => [
                                    ['text' => $prompt],
                                    ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $imgData]],
                                ],
                            ]],
                            'generationConfig' => [
                                'maxOutputTokens' => 150,
                            ],
                        ];
                        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($geminiKey);
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
                        if ($res === false) throw new \RuntimeException('curl error');
                        $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        if ($st >= 200 && $st < 300) {
                            $json = json_decode($res, true);
                            $text = '';
                            if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                                $text = (string)$json['candidates'][0]['content']['parts'][0]['text'];
                            }
                            $trimmed = trim($text);
                            $parsed = json_decode($trimmed, true);
                            if (!is_array($parsed)) {
                                if (preg_match('/\{[\s\S]*\}/', $trimmed, $m)) {
                                    $parsed = json_decode($m[0], true);
                                }
                            }
                            if (is_array($parsed)) {
                                $outTags = [];
                                $tagsArr = is_array($parsed['tags'] ?? null) ? $parsed['tags'] : [];
                                foreach ($tagsArr as $tg) {
                                    if (!is_string($tg)) continue;
                                    $tg = trim(ltrim($tg, '#'));
                                    if ($tg === '') continue;
                                    $outTags[] = $tg;
                                    if (count($outTags) >= $num) break;
                                }
                                return ['title' => '', 'tags' => $outTags];
                            }
                        }
                    } catch (\Throwable $e) {
                        // ignore and fallthrough to return empty
                    }
                }
            }
        } catch (\Throwable $e) {
            // fallthrough
        }
        return ['title' => '', 'tags' => []];
    }
}


