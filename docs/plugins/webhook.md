# Webhook受信

管理画面の「プラグイン」で「Webhook受信」をONにし、投稿先チャンネルを選んで接続先を作成します。発行後のモーダルに送信URLが表示され、「コピー」でURL全体をコピーできます。URLは一度だけ表示され、DBにはトークンのSHA-256ハッシュのみ保存します。

DiscordのWebhookと同じく、このURLだけで投稿できます。Authorizationヘッダーや管理画面へのログインは不要です。URLそのものが送信権限なので、公開せずに保管してください。紛失・漏洩時は接続先を失効し、新しく作成します。

```http
POST /v1/plugins/webhook/<endpoint-id>/<token>
Content-Type: application/json

{"content":"test","attachments":[]}
```

成功時は `204 No Content` です。`?wait=true` を付けると、保存完了後に `200` と `id`、`channel_id`、`webhook_id`、`content`、空の `attachments`、保存した `embeds` を返します。IDはSolocordのIDです。DiscordのMessageオブジェクト全体を再現するものではありません。

## 対応するJSON

- `content`: 最大32KiBの文字列。Embedがあれば省略・空文字にできます。本文とEmbedが両方空の投稿は拒否します。
- `embeds`: 最大10件のRich Embed。投稿全体のEmbed文字数は合計6,000文字まで。`title`（256文字）、`description`（4,096文字）、`url`、`timestamp`、整数の `color`（0〜16777215）、`author`、`footer`、`image`、`thumbnail`、`fields` に対応します。`type` は省略または `rich` のみです。
- `author`: `name`（256文字）、`url`、`icon_url`。`footer`: `text`（2,048文字）、`icon_url`。`image` / `thumbnail` は `url` のみ。画像URLとリンクは認証情報を含まないHTTP(S)に限ります。サーバーは画像を取得しません。
- `fields`: 最大25件。`name`（256文字）と `value`（1,024文字）が必須で、`inline` は真偽値、省略時はfalseです。改行はdescriptionとfieldのvalueで使用できます。文字数はUnicodeの文字単位で数えます。
- `timestamp`: 秒とUTC offsetを含むRFC 3339。小数秒は3桁まで、UTCで1970〜2099年。Solocord形式に正規化します。
- `attachments: []`、`components: []`: 空配列のみ受け付けます。
- `username`、`avatar_url`（文字列）、`tts`（真偽値）、`allowed_mentions`（オブジェクト）、`flags`（4 / 4096 / それらの組み合わせ）: Discord送信元との互換性のため受け付けますが、送信者・アバター・読み上げ・通知・Embed抑制の動作には反映しません。URLの取得も行いません。

実ファイル添付、multipart/form-data、動画・provider・画像のproxy情報、コンポーネント、poll、スレッド作成、Solocordの `blocks` / `blocks_json` 直接入力は未対応です。未対応項目や不正なEmbedは項目名を含む `400`、JSON以外は `415` を返します。投稿先は管理画面で固定され、本文の `channel_id` 等から変更できません。受信JSON全体は64KiBまでです。

```json
{
  "embeds": [{
    "title": "処理完了",
    "description": "バックアップが完了しました。",
    "color": 5793266,
    "fields": [{"name": "結果", "value": "成功", "inline": true}],
    "footer": {"text": "通知ボット"}
  }]
}
```

Embedは第1段階のSolocord RichEmbed v1 blockとして保存されます。利用前にGroup内の全端末を対応版に更新してください。表・グラフのWebhook投稿や添付画像のアップロードは追加していません。

## 再送と上限

`Idempotency-Key` は任意です。省略時は同じ内容でも送信ごとに新しいメモを作成します。再送時の二重投稿を避けるには、送信ごとに固有のキー（最大128byte）を付けてください。同じキー・同じ本文と正規化済みEmbedは同じIDを返し、本文またはEmbedが異なれば `409` です。JSONのキー順や省略したinline=falseには左右されません。本文だけの既存記録も引き続き使えます。記録は30日間保持します。

同期データの反映待ちは `503`、投稿上限は `429` です。受信メモはサーバー発の通常のSyncPackとなり、接続端末の同期で届きます。失効したURLは直ちに利用できなくなります。

既存送信元のため、従来の `/v1/plugins/webhook/<endpoint-id>` と `Authorization: Bearer <token>` も維持します。この経路の成功応答は従来どおり `201`（再送 `200`）と `messageId` / `packId` です。

互換性の対象は [Discord Execute Webhookの公式仕様](https://github.com/discord/discord-api-docs/blob/main/developers/resources/webhook.mdx) のテキスト・Rich Embed投稿部分です。本文の32KiB上限など既存のSolosync仕様も維持しており、完全なDiscord API互換ではありません。
