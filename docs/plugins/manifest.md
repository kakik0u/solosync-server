# Manifest

`plugins/<id>/manifest.json` はPHPを実行せずに管理画面へメタ情報を表示するためのJSONです。IDは `[a-z][a-z0-9_]*` の最大31文字、versionは `X.Y.Z` 形式にします。`plugin.php` も必須です。固有テーブル名はMySQL/MariaDBの識別子長を超えない64文字以内にしてください。

```json
{
  "id": "sample",
  "version": "1.0.0",
  "minServerVersion": "1.0.0",
  "name": { "ja": "サンプル", "en": "Sample" },
  "description": { "ja": "説明", "en": "Description" },
  "capabilities": ["read-model", "http", "events"],
  "eventTypes": ["operations.stored"],
  "settingsSchemaVersion": 1,
  "settings": [
    { "key": "limit", "type": "integer", "label": { "ja": "上限", "en": "Limit" }, "min": 1, "max": 100, "default": 10 }
  ]
}
```

`minServerVersion` は任意です。設定した場合、古いサーバーではONにできません。`author` と `website` も任意で表示用に指定できます。利用可能なcapabilityは `read-model`、`sync-writer`、`http`、`events` です。`eventTypes` を省略すると `events` 宣言のプラグインはすべてのイベントを受け取ります。設定欄は `boolean`、`integer`、`text`、`textarea`、`channel` に対応します。`channel` は現在の読み取りモデルに存在するチャンネルのみ保存できます。秘密値の発行は汎用設定欄に置かず、プラグインの管理ルートで一度だけ表示し、DBにはハッシュを保存してください。

管理画面は設定欄を共通UIとして描画します。プラグイン独自のHTML/JSを管理画面へ挿入するAPIはありません。HTTPルートを使う場合、モジュールは `handleHttp` を実装してください。イベントを使う場合は `onEvent` を実装してください。
