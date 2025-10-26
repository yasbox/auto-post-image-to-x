<?php
declare(strict_types=1);

namespace App\Lib;

final class XClient
{
    private string $oauth2Token;
    private string $apiKey;
    private string $apiSecret;
    private string $accessToken;
    private string $accessTokenSecret;

    public function __construct()
    {
        $this->oauth2Token = (string)($_ENV['X_OAUTH2_ACCESS_TOKEN'] ?? '');
        $this->apiKey = (string)($_ENV['X_API_KEY'] ?? '');
        $this->apiSecret = (string)($_ENV['X_API_SECRET'] ?? '');
        $this->accessToken = (string)($_ENV['X_ACCESS_TOKEN'] ?? '');
        $this->accessTokenSecret = (string)($_ENV['X_ACCESS_TOKEN_SECRET'] ?? '');
    }

    public function uploadMedia(string $path): string
    {
        // media/upload v1.1 は OAuth 1.0a が安定
        if (!$this->hasOAuth1()) {
            throw new \RuntimeException('OAuth1 tokens missing for media upload');
        }
        $size = filesize($path);
        $init = $this->oauth1Request('POST', 'https://upload.twitter.com/1.1/media/upload.json', [
            'command' => 'INIT',
            'total_bytes' => $size,
            'media_type' => 'image/jpeg',
        ]);
        $mediaId = (string)($init['media_id_string'] ?? '');
        if (!$mediaId) throw new \RuntimeException('INIT failed');

        $fh = fopen($path, 'rb');
        $segment = 0;
        while (!feof($fh)) {
            $chunk = fread($fh, 1024 * 1024);
            if ($chunk === '' || $chunk === false) break;
            $this->oauth1Request('POST', 'https://upload.twitter.com/1.1/media/upload.json', [
                'command' => 'APPEND',
                'media_id' => $mediaId,
                'segment_index' => $segment,
                // base64 で送る（非マルチパート）
                'media_data' => base64_encode($chunk),
            ]);
            $segment++;
        }
        fclose($fh);

        $this->oauth1Request('POST', 'https://upload.twitter.com/1.1/media/upload.json', [
            'command' => 'FINALIZE',
            'media_id' => $mediaId,
        ]);

        return $mediaId;
    }

    /**
     * メディアにセンシティブメタデータを設定
     * 
     * @param string $mediaId メディアID
     * @param bool $isSensitive センシティブフラグ
     * @param int $score センシティブ判定スコア（0-100）
     * @param int $adultContentThreshold 成人向けコンテンツ判定の閾値（この値以上でadult_content）
     * @return void
     */
    public function setMediaMetadata(string $mediaId, bool $isSensitive, int $score = 0, int $adultContentThreshold = 71): void
    {
        if (!$isSensitive) {
            return; // センシティブでない場合は何もしない
        }

        if (!$this->hasOAuth1()) {
            throw new \RuntimeException('OAuth1 tokens missing for media metadata');
        }

        // スコアに基づいてカテゴリを選択
        // adultContentThreshold以上: adult_content（ヌード・成人向け確定）
        // threshold以上でadultContentThreshold未満: other（グラビア・露出多め）
        $category = ($score >= $adultContentThreshold) ? ['adult_content'] : ['other'];
        
        $payload = [
            'media_id' => $mediaId,
            'sensitive_media_warning' => $category,
        ];

        $url = 'https://upload.twitter.com/1.1/media/metadata/create.json';
        $headers = $this->buildOAuth1Header('POST', $url, []);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge($headers, ['Content-Type: application/json']),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);

        $res = curl_exec($ch);
        $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            throw new \RuntimeException('metadata request failed');
        }

        // 204 No Content が成功レスポンス
        if ($st !== 204 && ($st < 200 || $st >= 300)) {
            throw new \RuntimeException('HTTP ' . $st . ': ' . $res);
        }
    }

    public function postTweet(string $text, string $mediaId): array
    {
        $payload = [
            'text' => $text,
            'media' => ['media_ids' => [$mediaId]],
        ];
        
        // OAuth2 ユーザーコンテキストがあれば優先、無ければ OAuth1 で署名
        if ($this->oauth2Token !== '') {
            $ch = curl_init('https://api.twitter.com/2/tweets');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->oauth2Token,
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 30,
            ]);
            $res = curl_exec($ch);
            if ($res === false) throw new \RuntimeException('post error');
            $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($st < 200 || $st >= 300) {
                throw new \RuntimeException('HTTP ' . $st . ': ' . $res);
            }
            return json_decode($res, true) ?: [];
        }

        // OAuth1 fallback for v2 endpoint
        $json = json_encode($payload);
        $headers = $this->buildOAuth1Header('POST', 'https://api.twitter.com/2/tweets', []);
        $ch = curl_init('https://api.twitter.com/2/tweets');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge($headers, ['Content-Type: application/json']),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => 30,
        ]);
        $res = curl_exec($ch);
        if ($res === false) throw new \RuntimeException('post error');
        $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($st < 200 || $st >= 300) {
            throw new \RuntimeException('HTTP ' . $st . ': ' . $res);
        }
        return json_decode($res, true) ?: [];
    }

    private function hasOAuth1(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '' && $this->accessToken !== '' && $this->accessTokenSecret !== '';
    }

    private function oauth1Request(string $method, string $url, array $params): array
    {
        $headers = $this->buildOAuth1Header($method, $url, $params);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_TIMEOUT => 30,
        ]);
        $res = curl_exec($ch);
        if ($res === false) throw new \RuntimeException('curl error');
        $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($st < 200 || $st >= 300) {
            throw new \RuntimeException('HTTP ' . $st . ': ' . $res);
        }
        return json_decode($res, true) ?: [];
    }

    private function buildOAuth1Header(string $method, string $url, array $params): array
    {
        $oauth = [
            'oauth_consumer_key' => $this->apiKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $this->accessToken,
            'oauth_version' => '1.0',
        ];
        // Collect parameters for signature (query + body + oauth)
        $query = [];
        $u = parse_url($url);
        if (!empty($u['query'])) {
            parse_str($u['query'], $query);
        }
        $sigParams = array_merge($query, $params, $oauth);
        ksort($sigParams);
        $base = strtoupper($method) . '&' . rawurlencode(($u['scheme'] ?? 'https') . '://' . $u['host'] . ($u['path'] ?? '')) . '&' . rawurlencode(http_build_query($sigParams, '', '&', PHP_QUERY_RFC3986));
        $key = rawurlencode($this->apiSecret) . '&' . rawurlencode($this->accessTokenSecret);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));
        $header = 'OAuth ' . implode(', ', array_map(function($k, $v) {
            return $k . '="' . rawurlencode((string)$v) . '"';
        }, array_keys($oauth), $oauth));
        return ['Authorization: ' . $header];
    }
}


