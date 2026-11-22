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

- `GET /v1/capabilities` — 認証不要のprobe。bind状態、server ID、保存履歴 ID、上限を返します。
- `POST /v1/session/exchange` — connection keyを端末Bearerへ交換します。未bind時だけ `group` metadataが必須です。
- `GET /v1/group` — Bearer認証後にGroup metadataを返します。新端末はここでgroupKeyを復元します。
- `POST /v1/group/reset` — Bearer認証後、`expectedGroupId` と新しい `group` を受け取り、保存先を初期化します。対応サーバーはcapabilitiesで `supportsReset:true` を返します。
- `GET /v1/packs?after=...&limit=...` — commit sequence順にpack identityを列挙します。
- `GET /v1/packs/{packId}` — pack bytesを取得します。
- `PUT /v1/packs/{packId}` — `X-Solosync-Digest`付きでimmutable packを保存します。同一packの再送は冪等です。
- `POST /v1/devices/{deviceId}/revoke` — 対象端末のBearerを失効します。