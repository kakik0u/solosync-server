# Solosync PHP Sync Server

Solocord の Solosync v2 に対応する、共有レンタルサーバー向け PHP + MySQL/MariaDB サーバーです。

**インストールには、[GitHub Releases](https://github.com/kakik0u/solosync-server/releases/latest) から最新版の `solosync-server-v*.zip` をダウンロードしてください。**

## 必要環境

- PHP 8.2+ (`pdo_mysql`, `json`, `hash`, `openssl`)
- MySQL 8+ または対応する MariaDB
- HTTPS
- Apache互換の `.htaccess` が利用できる共有ホスト、または `public/` をDocumentRootに設定できる環境

## セットアップ

[Docs](https://solocord.kakikou.app/docs/sync-server-setup/)を参照してください。

手動セットアップを行う場合は、 `private/config.example.php` を `private/config.php` にコピーして設定できます。

## API

- `GET /v1/capabilities` — 認証不要のprobe。bind状態、server ID、上限を返します。
- `POST /v1/session/exchange` — connection keyを端末Bearerへ交換します。未bind時だけ `group` metadataが必須です。
- `GET /v1/group` — Bearer認証後にGroup metadataを返します。新端末はここでgroupKeyを復元します。
- `GET /v1/packs?after=...&limit=...` — commit sequence順にpack identityを列挙します。
- `GET /v1/packs/{packId}` — pack bytesを取得します。
- `PUT /v1/packs/{packId}` — `X-Solosync-Digest`付きでimmutable packを保存します。同一packの再送は冪等です。
- `POST /v1/devices/{deviceId}/revoke` — 対象端末のBearerを失効します。

## Plugin

`plugins/<id>/plugin.php` がcallableを返すと、`php bin/maintenance.php` または管理画面のメンテナンスからpost-commit eventを受け取れます。イベントは `pack.stored`, `operations.stored`, `device.revoked` です。`operations.stored` は平文operationを含みます。同期transaction中は `plugin_outbox` への追加だけを行うため、plugin例外で同期保存はrollbackされません。

Plugin配送はat-least-onceとして扱い、同じ `eventId` を再度受け取っても安全な処理にしてください。複数のメンテナンス実行を同時に走らせない運用を推奨します。