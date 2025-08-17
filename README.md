## X 自動投稿システム（Docker 開発環境）

このリポジトリは、`SPECS.md` の要件に基づき、画像を一定スケジュールで X（旧Twitter）に自動投稿する PHP アプリの開発環境（Docker）と実装一式です。

### 開発に必要なもの
- Docker / Docker Compose
- Git

### 主要ディレクトリ
- `app/public` Web 公開ディレクトリ（管理 UI）
- `app/api` API エンドポイント群
- `app/cron` スケジューラ実行
- `app/lib` ライブラリ
- `app/data` 画像やメタデータ保存（Web 非公開）
- `app/config` 設定（Web 非公開）
- `app/logs` ログ出力（Web 非公開）

### クイックスタート（開発）
```bash
docker compose up -d --build
# 初回のみ: パーミッション調整（Windows では不要な場合あり）
# docker compose exec php chown -R www-data:www-data /var/www/app/data /var/www/app/logs

# ブラウザで http://localhost:8080/ にアクセス
# 既定ログイン: パスワードは app/config/password.json のハッシュを生成して差し替えてください
```

### スケジューラ（開発）
- `docker-compose.yml` の `scheduler` サービスが 60 秒ごとに `app/cron/runner.php` を実行します。

### 本番の想定
- `SPECS.md` に従い、`/app/public` をドキュメントルートとして公開、`/app/data`/`/app/config`/`/app/logs` は非公開領域に配置。

### 免責
- X API の仕様変更等により動作しない可能性があります。実運用前に十分な検証を行ってください。


