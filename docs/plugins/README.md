# Solosync Server プラグイン

プラグインは `plugins/<id>/` に置く**信頼済みPHPコード**です。PHP/DBユーザーを共有するため、悪意あるコードを隔離する仕組みではありません。管理者が内容を確認して配置してください。管理画面へのZIPアップロード機能はありません。

標準プラグインは [Webhook受信](webhook.md) と [公開チャンネル](public-channel.md) です。どちらも初期状態はOFFです。初回ON時に専用テーブルを作ります。OFFではHTTPルート・イベント処理を停止し、テーブルと設定は保持します。

プラグインを作る場合は [manifest](manifest.md)、[実行API](api.md)、[migrationと更新](migration.md) を参照してください。既存の `plugins/<id>/plugin.php` が callable を返す形式は従来形式として引き続きイベントを受信しますが、設定やHTTPルートはありません。

プラグインが現在のチャンネル・メモを読むには `read-model` capability、同期されるメモを追加するには `sync-writer` capability が必要です。`sync_packs` は同期の正本であり、読み取りモデルへ直接INSERTしてもクライアントには届きません。

WebhookはDiscord形式のRich EmbedをSolocordのblockとして投稿でき、公開チャンネルはアプリやWebhookで作成したEmbedを表示します。表・グラフは要約表示です。対応形式・上限・URL画像の扱いは各プラグインの文書を参照してください。共通writerからも末尾の省略可能なEmbed引数を使えます。

- [メモ検索MCP](mcp.md): 外部AI用の読み取り・意味検索中継と、アプリで承認する変更案。
