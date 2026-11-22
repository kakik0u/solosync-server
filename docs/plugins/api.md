# プラグイン実行API

`plugin.php` はオブジェクトを返します。イベントのみの従来形式では callable を返せます。

```php
return new class {
    public function handleHttp(PluginContext $context, PluginHttpRequest $request): PluginHttpResponse {
        // $request->admin で管理画面からの呼び出しか判別する。
        return PluginHttpResponse::json(200, ['ok' => true]);
    }

    public function onEvent(PluginContext $context, array $event): void {
        // eventIdで重複配送を防ぐ。少なくとも一回配送される。
    }
};
```

管理ルートは `/v1/admin/plugins/<id>/routes/...` で、管理者cookieとCSRFをホストが確認します。公開ルートは `/v1/plugins/<id>/...` または `/p/<id>/...` です。公開ルートの認証はプラグイン自身で行います。OFFのプラグインはルートを受け付けません。受信本文は最大64KiBです。

`PluginContext` は以下を提供します。`readModel()` と `writer()` は対応するcapabilityをmanifestで宣言したプラグインだけが呼び出せます。

| API | 用途 |
| --- | --- |
| `storage()` | プラグイン所有テーブルへのパラメータ付き読み書き。`table('items')` は `plugin_<id>_items` を返す。`transaction()` も利用できる。 |
| `requestIsHttps()` | 直接HTTPSか、管理者が信頼設定したリバースプロキシのHTTPS転送かを判定する。秘密キーを受ける公開ルートでは必ず確認する。 |
| `readModel()` | `status()`、`step()`、`guilds()`、`categories($guildId)`、`channels()`、`channel($id)`、`messages($id,$limit,$before,$maxChars)`、`tags($limit)`、`messageTags($messageId)`。SyncPackから再構築する共通読み取りビュー。メモ本文は既定で16,384文字までで、`truncated` が付く。管理者用の `diagnostics()` は件数と親チャンネルのないメモ件数を返し、`rebuild()` は派生行だけを消す。 |
| `writer()` | `createMessage($storage,$channelId,$content,$before,$after,$embeds)`。通常のSyncPackを保存する。`$before` と `$after` はpackと同じDBトランザクション内で呼ばれ、冪等化レコードを扱える。末尾の `$embeds` は省略可能なSolocord RichEmbed v1配列（既定 `[]`、最大16件）で、Discord JSONではない。既存の呼び出しは変更不要。 |
| `settings` | 管理画面で保存した非秘密の設定値。 |

`messages()` は `blocks_json` と `blocks_truncated` も返します。旧メモのblockなしはnullまたは空のversion 1 envelopeです。1MiBを超えるJSONは返さず、nullと `blocks_truncated=true` にします。途中までのJSONとして扱わず未対応表示にしてください。未知・不正な形式も正本のSyncPackからは削除しません。

```php
$context->writer()->createMessage($context->storage(), $channelId, '', null, null, [
    ['title' => '完了', 'color' => '#5865f2', 'image' => ['url' => 'https://example.com/image.png']],
]);
```

writerはblockのUUIDと最大4KiBの要約を付け、version 1の `blocks_json` を通常のメモUPSERTに保存します。空本文はEmbedがある場合だけ使えます。入力はSolocordと同じRichEmbed制約で検証し、最大16 block・全体1MiBです。URL画像と `attachmentId` の両方をデータとして扱えますが、公開ページで管理添付を表示するAPIはありません。

Webhookの実装は [plugins/webhook/plugin.php](../../plugins/webhook/plugin.php) にあります。HTTPレスポンスは `PluginHttpResponse::json()`、または本文とContent-Typeを指定して返します。`ApiException` でHTTP statusと公開用エラーコードを返せます。

イベントは `eventId`、`type`、`key`、`payload` を持ちます。配送は少なくとも一回であり、失敗はプラグインごとに再試行されます。8回失敗すると管理画面で停止件数が表示され、管理者が再試行できます。外部送信や独自テーブルの更新には `eventId` を冪等キーとして使ってください。プラグインの処理は同一PHPプロセス上の信頼済みコードです。`PluginStorage` や宣言されたcapabilityは開発者向けの契約であり、OS/DBレベルの権限分離ではありません。

不正な `manifest.json` は他のプラグインの一覧や配送を止めず、そのプラグインをOFF扱いとして管理画面にエラーを表示します。`bin/maintenance.php` の定期実行はイベント配送と読み取りモデルの追いつき処理を少量ずつ行います。

読み取りモデルはSyncPackの正本ではありません。新しいプラグインは投影テーブルへ直接書き込まず、`writer()` に必要な操作を追加して通常のpackとして配信してください。添付ファイルの本体や端末固有設定は共通投影に複製しません。将来のMCP向け認証・権限と検索APIは、この共通モデルの上で別途設計します。
