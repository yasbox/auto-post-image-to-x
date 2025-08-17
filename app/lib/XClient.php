<?php
declare(strict_types=1);

namespace App\Lib;

final class XClient
{
    private string $token;

    public function __construct()
    {
        $this->token = (string)($_ENV['X_OAUTH2_ACCESS_TOKEN'] ?? '');
        if (!$this->token) {
            throw new \RuntimeException('X OAuth2 token missing');
        }
    }

    public function uploadMedia(string $path): string
    {
        $size = filesize($path);
        $init = $this->request('https://upload.twitter.com/1.1/media/upload.json', [
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
            $this->request('https://upload.twitter.com/1.1/media/upload.json', [
                'command' => 'APPEND',
                'media_id' => $mediaId,
                'segment_index' => $segment,
                'media' => base64_encode($chunk),
            ]);
            $segment++;
        }
        fclose($fh);

        $this->request('https://upload.twitter.com/1.1/media/upload.json', [
            'command' => 'FINALIZE',
            'media_id' => $mediaId,
        ]);

        return $mediaId;
    }

    public function postTweet(string $text, string $mediaId): array
    {
        // v2 create Tweet
        $payload = [
            'text' => $text,
            'media' => ['media_ids' => [$mediaId]],
        ];
        $ch = curl_init('https://api.twitter.com/2/tweets');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->token,
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
            throw new \RuntimeException('Tweet failed: ' . $res);
        }
        return json_decode($res, true) ?: [];
    }

    private function request(string $url, array $fields): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->token,
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
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
}


