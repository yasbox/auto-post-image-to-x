# X自動投稿システム 設計仕様書（DBなし / PHP+JS / Xserver）
Version: v0.3  
Timezone: Asia/Tokyo  
License: Private

---

## 1. 目的・スコープ
サーバー上に蓄積した静止画（JPEG/PNG/WEBP）を、固定時刻または一定間隔で1枚ずつ自動でX（旧Twitter）に投稿する。

- 投稿成功後: 画像は完全削除
- 投稿失敗: 画像は保持（通知/自動リトライなし）
- キャプション: 英語タイトルをLLMで投稿直前に生成（画像は長辺500pxプレビューで渡す）。失敗時はタイトル無し（ハッシュタグのみ）
- ハッシュタグ: 設定済みリストからランダム1〜3個を先頭に付与
- DB不使用: JSON + ファイルシステムで実装
- 管理UI: アップロード（D&D/複数/チャンク）、一覧（PhotoSwipe v5）、D&D並べ替え（SortableJS）、削除、設定（保存ボタン式）
- セキュリティ: パスワード認証（セッション + CSRF）/ HTTPS前提 / 同一オリジン

対象外: 動画 / アニメGIF / 複数枚同時ポスト / 通知・重複防止・ALT自動生成

---

## 2. システム要件
- PHP: 8.1+（8.2推奨）  
  必須拡張: curl, json, mbstring, openssl, fileinfo, gd（画像処理。ImageMagickがあれば切替可）
- Web: HTTPS, 同一オリジン
- ブラウザ: 最新 Chrome / Edge / Firefox / Safari
- ライブラリ:
  - Tailwind CSS（CDN またはビルド済みCSS配置）
  - PhotoSwipe v5
  - SortableJS
  - Dropzone.js（チャンクアップロード）

---

## 3. ディレクトリ構成（例）
/app はドキュメントルート外配置を推奨。/public のみWeb公開。

    /app
      /public
        index.php            # 管理UI（ログイン必須）
        settings.php         # 設定UI（フォームで編集/保存）
        /assets              # JS/CSS/ライブラリ（配布物）
      /api
        upload.php           # チャンク受付/結合/検証/登録
        list.php             # 画像一覧/件数
        reorder.php          # 並び順保存（都度反映）
        delete.php           # 単一削除
        settings_get.php     # 設定取得
        settings_set.php     # 設定保存（CSRF必須）
        post_now.php         # 手動1回投稿（管理者のみ）
      /cron
        runner.php           # スケジュール判定 → 最大1枚投稿
      /lib
        Auth.php             # ログイン/セッション
        Csrf.php             # CSRFトークン発行/検証
        Lock.php             # 排他ロック（ファイル）
        Logger.php           # JSON Linesログ
        Queue.php            # queue.json操作
        Settings.php         # config.json操作
        TitleLLM.php         # タイトル生成（LLMアダプタ）
        XClient.php          # X API（media upload / post）
        ImageProc.php        # 画像最適化（投稿用5MB/LLMプレビュー500px）
        Util.php             # 共通関数
      /data
        /inbox               # 未投稿の原本
        /tmp                 # 投稿直前の生成物（投稿用/LLM用縮小）
        /meta
          queue.json         # 並び順（先頭＝次に投稿）
          state.json         # 最終投稿時刻/固定時刻の消化状況
      /config
        config.json          # 設定本体（UIの保存で更新）
        tags.txt             # ハッシュタグ候補（1行1タグ・#なし素片）
        password.json        # {"hash": "<password_hash>"}
        .env                 # APIキー等（Webから不可視）
      /logs
        post-YYYYMMDD.log    # 投稿ログ（JSONL）
        op-YYYYMMDD.log      # 操作ログ（JSONL）

---

## 4. 設定ファイル仕様

### 4.1 config.json（スキーマ例）
    {
      "timezone": "Asia/Tokyo",
      "schedule": {
        "mode": "fixed",                  // "fixed" | "interval"
        "fixedTimes": ["09:00", "13:00", "21:00"],
        "intervalMinutes": 480,
        "jitterMinutes": 0,
        "skipIfEmpty": true
      },
      "upload": {
        "chunkSize": 2000000,
        "concurrency": 3,
        "allowedMime": ["image/jpeg", "image/png", "image/webp"],
        "maxClientFileSizeHintMB": 8192
      },
      "post": {
        "title": {
          "language": "en",
          "maxChars": 80,
          "tone": "neutral",
          "ngWords": ["example1", "example2"]
        },
        "hashtags": {
          "min": 1,
          "max": 3,
          "prepend": true,
          "source": "tags.txt"
        },
        "textMax": 280,
        "deleteOriginalOnSuccess": true,
        "keepOnFailure": true
      },
      "imagePolicy": {
        "tweetMaxBytes": 5000000,         // 5MB上限
        "tweetFormat": "jpeg",            // 投稿用は常にJPEGに統一
        "tweetMaxLongEdge": 4096,         // 投稿用の初期最大辺（動的縮小あり）
        "tweetQualityMin": 60,            // バイナリサーチの下限品質
        "tweetQualityMax": 92,
        "stripMetadataOnTweet": true,     // EXIF等を除去し容量削減
        "llmPreviewLongEdge": 500,        // LLMに渡すプレビューの長辺
        "llmPreviewQuality": 70,
        "stripMetadataOnLLM": true
      },
      "xapi": {
        "useAltText": false,
        "duplicateCheck": false
      },
      "security": {
        "sessionName": "XPOSTSESS",
        "csrfCookieName": "XPOSTCSRF",
        "passwordHashAlgo": "PASSWORD_DEFAULT"
      },
      "logs": {
        "retentionDays": 31
      }
    }

### 4.2 .env（例）
    OPENAI_API_KEY=***
    # Gemini を使う場合は以下のいずれか
    GOOGLE_API_KEY=***
    # または
    GEMINI_API_KEY=***
    # LLM_PROVIDER / LLM_MODEL は使用しません（config.json で指定）
    # いずれか（または両方）
    # OAuth2 ユーザーコンテキスト（任意・あれば使用）
    X_OAUTH2_ACCESS_TOKEN=***
    # OAuth1.0a（メディアアップロードで必須）
    X_API_KEY=***
    X_API_SECRET=***
    X_ACCESS_TOKEN=***
    X_ACCESS_TOKEN_SECRET=***

### 4.3 tags.txt（例）
    photography
    daily
    street
    portrait
    ...

※ 1行1語（#は付けない）。投稿時に「#photography」のように整形。

---

## 5. データ仕様

### 5.1 queue.json
唯一の真実の順序（先頭＝次回投稿対象）。アップロードで末尾追加、UI並べ替えで都度保存。

    {
      "version": 1,
      "items": [
        {"id": "20250817-uuid1", "file": "2f9c2f2a-....jpg", "addedAt": 1723850000},
        {"id": "20250817-uuid2", "file": "a8b7c6d5-....png", "addedAt": 1723853600}
      ]
    }

### 5.2 state.json
固定時刻の当日消化状況と最後の投稿時刻を記録し、多重実行を防止。

    {
      "lastPostAt": 1723860000,
      "fixedTimesConsumed": { "2025-08-17 09:00": true, "2025-08-17 13:00": true }
    }

---

## 6. アップロード仕様（/api/upload）
- Dropzone.js のチャンクパラメータ（dzuuid, dzchunkindex, dztotalchunkcount, …）を受理。
- サーバ処理:
  1) チャンク受領 → 一時保管 → 最終チャンクで結合
  2) 拡張子 & MIME（finfo）検証（許可: JPEG/PNG/WEBP）
  3) 生成名は UUID.拡張子 で /data/inbox/ に保存
  4) queue.json.items に 末尾push（addedAt = time()）

取り込み時は EXIF削除/サムネイル生成なし（一覧はブラウザ縮小表示）。

---

## 7. 一覧/閲覧UI
- 初期順: 追加日で新しいものが下（= 次回投稿が一番上）
- ライトボックス: PhotoSwipe v5（width/height 属性を事前設定）
- 並べ替え: SortableJS（D&D）→ 都度 /api/reorder で保存
- アップロード: 右上のボタンから選択、または一覧領域へD&D（プレビューなし）
- 検索/一括操作: なし

---

## 8. スケジューラ（/cron/runner.php）
- 起動: XserverのCronで 毎分 or 5分毎 に php /path/to/app/cron/runner.php
- 排他: /data/meta/post.lock により多重起動を回避。
- ロジック（擬似）:

        if (!Lock::acquire('post.lock')) exit;
        $cfg = Settings::load();
        $state = State::load();
        $now = nowJST($cfg->timezone);

        if (isDueFixedTime($now, $cfg, $state) || isDueInterval($now, $cfg, $state)) {
          post_once($cfg, $state); // 最大1枚
        }
        Lock::release();

成功時: lastPostAt 更新 / 固定時刻は当日消化フラグを立てる。手動投稿後も同様に state を更新し直後の重複投稿を回避。

---

## 9. 投稿パイプライン（5MB対応 / LLM 500pxプレビュー）
関数: post_once(Settings $cfg, State $state)

1) 対象決定: queue.items[0]（なければ終了）  
2) LLMタイトル生成:
   - ImageProc::makeLLMPreview() で /data/tmp/llm/<id>.jpg を生成  
     ・長辺500px、JPEG quality=70、メタ削除  
   - TitleLLM::generate(previewPath, title設定, textMax, ngWords)  
   - 失敗時はタイトル無し（空文字）  
3) ハッシュタグ選定: tags.txt から 1〜3個（重複なし）をランダム抽出  
4) 本文生成: 「#tag1 #tag2 #tag3 英語タイトル」（先頭にタグ + 半角スペース1つ。タイトル無し時はタグのみ）  
5) 画像最適化（投稿用）: ImageProc::makeTweetImage(inboxPath, imagePolicy)
   - 入力: JPEG/PNG/WEBP
   - 出力: 常にJPEG（互換/容量重視）
   - 手順:
     1. 画像ロード → 最大辺 tweetMaxLongEdge（初期4096px）以内へ
     2. メタデータ除去（EXIF等）
     3. 品質バイナリサーチ（tweetQualityMax..tweetQualityMin）で <= 5,000,000 bytes を満たす品質を探索
     4. それでも超過: 長辺を10%刻みで縮小 → (2)〜(3) 再試行（最大5回）
     5. 生成先: /data/tmp/tweet/<id>.jpg
   - 生成失敗（容量未達）→ スキップ（ログのみ）
6) メディアアップロード: INIT → APPEND（1MBチャンク）→ FINALIZE  
7) 投稿作成: 本文 + media_id  
8) 後処理:
   - 成功: 原本削除（deleteOriginalOnSuccess=true）→ queue.items.shift()
   - 失敗: 原本保持（keepOnFailure=true）→ queue不変
   - /data/tmp の生成物は削除
9) ログ: post-YYYYMMDD.log に JSONLで追記

---

## 10. API設計（サマリ）

### 10.1 認証/CSRF
- ログイン: パスワードのみ（単一管理者）。password.json のハッシュと照合。
- セッション: HttpOnly; Secure; SameSite=Lax。
- CSRF: 変更系エンドポイントはCSRFトークン必須。

### 10.2 エンドポイント
- POST /api/upload … Dropzone互換（チャンク）。単発アップロードも受理
- GET  /api/list   … {"items":[{"id","file","size","addedAt","width","height"}], "count":N}
- POST /api/reorder … {"order":["id1","id2",...]}
- POST /api/delete  … {"id":"..."}
- GET  /api/settings / POST /api/settings
- POST /api/post-now … 手動1件投稿（排他ロック尊重）
- GET  /api/file    … 画像バイナリを同一オリジンで配信（例: ?id=...）

すべて同一オリジン / HTTPS必須 / JSONレスポンス。

---

## 11. セキュリティ
- /data /config は公開外。/data/inbox 直下に .htaccess（Apache）の例:

        RemoveHandler .php .phtml .php3 .php4 .php5 .phps .php7
        php_flag engine off
        Options -ExecCGI
        <FilesMatch "\.(php|phtml|phps?)$">
          Require all denied
        </FilesMatch>

- アップロード: 拡張子 & MIME検証、実画像読込で不正バイト拒否。
- CSP, XSS対策（HTMLエスケープ）。
- ログイン連続失敗で簡易スリープ（例: 1秒）。

---

## 12. ログ/保持
- 投稿ログ: post-YYYYMMDD.log（JSONL）  
  フィールド例: timestamp, level, event, imageId, file, tweetId（成功時）, bytes, durationMs, error
- 操作ログ: アップロード/削除/並べ替え/設定変更を op-YYYYMMDD.log に保存
- 保持: logs.retentionDays（既定31日）で古いログを削除

---

## 13. 管理UI（実装指針）
- Tailwind: CDN（Play）またはビルド済みCSSを /public/assets に配置
- 一覧: CSSグリッド（先頭が次回投稿）。巨大画像はCSSでmax-height/width制限
- PhotoSwipe v5: getimagesize で width/height を事前取得
- 並べ替え: SortableJS → 並べ替えイベント毎に POST /api/reorder
- アップロード: Dropzone.js（chunkSize=config.upload.chunkSize / parallelUploads=config.upload.concurrency）
- 設定: フォーム → POST /api/settings（CSRF）。保存ボタン押下時のみ反映

---

## 14. セットアップ手順（概要）
1) /app をサーバーに配置し、/public をドキュメントルートに設定（またはサブディレクトリ公開）
2) .env と password.json（password_hash()で生成）を作成
3) config.json と tags.txt を用意
4) パーミッション: /data /logs はPHPから書込み可
5) XserverのCronに登録（例: 毎分）

        php /home/USER/yourapp/app/cron/runner.php

6) ブラウザで /public にアクセス → ログイン → 設定保存 → 運用開始

---

## 15. 受け入れ基準（Acceptance Criteria）
- スケジューラ: 固定時刻/一定間隔/両対応で、1起動につき最大1枚のみ投稿
- 並び順: UIのD&Dが即 queue.json に反映、先頭が次回投稿
- 5MB制約: どの入力画像でも投稿用最終ファイルが 5,000,000 bytes 以下へ自動調整
- タイトル生成: LLMに渡す画像は長辺500pxのプレビュー。失敗時は "Untitled"
- 本文: 「#tag ... + 空白 + 英語タイトル」。タグは tags.txt から1〜3個（重複なし/ランダム/先頭）
- 成功後削除: 投稿成功で原本が完全削除され、queue先頭が除去
- 失敗時保持: 投稿失敗時は原本保持、queue不変
- ログ: 投稿/操作の主要イベントがJSONLに記録、31日で自動削除
- セキュリティ: パスワードログイン + CSRF必須、/data /config がWeb非公開
- UI: PhotoSwipe v5 で閲覧可、検索や一括操作はなし

---

## 16. 主要クラスと責務（概要）
- Auth: ログイン/ログアウト/セッション
- Csrf: CSRFトークン生成・検証
- Lock: ファイルロック（acquire/release, with timeout）
- Logger: JSONL書込み/日次ローテート/保持日数で削除
- Settings: config.jsonのロード/保存/検証
- Queue: queue.jsonのロード/保存/整合性チェック
- ImageProc: 画像最適化（LLMプレビュー, 投稿用5MB最適化）
- TitleLLM: 画像+制約を受け取り英語タイトル生成（プロバイダ抽象）
- XClient: media upload(1MBチャンク)/tweet post

ImageProc::makeTweetImage（擬似）:

        function makeTweetImage(string $src, array $p): string {
          $img = loadBitmap($src); // 読込
          $img = resizeMaxLongEdge($img, $p['tweetMaxLongEdge']); // リサイズ
          // メタ除去 + JPEG化 + 品質バイナリサーチ
          $qMin = $p['tweetQualityMin']; $qMax = $p['tweetQualityMax'];
          $best = null;
          while ($qMax - $qMin > 2) {
            $q = intdiv($qMin + $qMax, 2);
            $buf = encodeJPEG($img, $q, stripMeta: true);
            if (strlen($buf) <= $p['tweetMaxBytes']) { $best = $buf; $qMin = $q; } else { $qMax = $q; }
          }
          if (!$best || strlen($best) > $p['tweetMaxBytes']) {
            for ($i=0; $i<5; $i++) {
              $img = resizeScale($img, 0.9);
              // ...再サーチ（省略）
              // 成功で return tmpPath
            }
            throw new RuntimeException("Cannot fit under 5MB");
          }
          return writeTmp($best, "/data/tmp/tweet/{$id}.jpg");
        }

ImageProc::makeLLMPreview（擬似）:

        function makeLLMPreview(string $src, array $p): string {
          $img = loadBitmap($src);
          $img = resizeMaxLongEdge($img, $p['llmPreviewLongEdge']);  // 500px
          $buf = encodeJPEG($img, $p['llmPreviewQuality'], stripMeta: $p['stripMetadataOnLLM']);
          return writeTmp($buf, "/data/tmp/llm/{$id}.jpg");
        }

---

## 17. 既知の制約・注意
- 投稿用は常にJPEG化（互換性と容量の安定化を優先）。透過が必要なPNGは白などの背景でフラット化。
- 巨大原本は一覧表示が重い可能性あり（事前サムネイル生成は行わない運用のため）。
- XのAPI/ガイドラインは変更され得るため、運用時に定期確認が必要。

---

End of SPECS.md
