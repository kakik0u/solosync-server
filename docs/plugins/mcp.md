# メモ検索MCP

管理画面のプラグインから「メモ検索MCP」を有効にする。メモは既存SyncPackの読み取りモデルから取得する。公開チャンネルとは別の、認証付きAPI。既定は読み取りで、明示的に許可した接続では、起動中アプリへ承認付きの変更案も中継する。PHPサーバー自身による直接書き込み、AI回答の生成、Embedding生成は行わない。

## 接続

管理画面で接続名と許可範囲（発行時点の全サーバー・全チャンネル、または1チャンネル）を選び、用途別にトークンを発行する。発行時だけ表示される接続情報を保管する。失効すると以降の要求を拒否する。外部クライアント用は1トークン毎分60要求、最大12メモ。管理画面APIでは複数server/channelのscopeも指定できる。

- 読み取り用: `POST /v1/plugins/mcp/mcp`。各要求に `Authorization: Bearer <read token>`。
- 中継用: Solocordの「設定 → AI → MCP」に、ベースURL `/v1/plugins/mcp`、Group ID、中継用トークン、許可範囲を入力する。アプリ自身の同期Groupが一致する必要がある。同期・管理用の秘密情報を流用しない。
- 新しい中継用トークンの発行は古い中継端末を失効させる。1インスタンス1Group・中継端末1台。

HTTPSが必要。読み取りMCPではブラウザーOriginを受け付けず、トークンをクエリやURLへ入れない。固定Bearer方式であり、ChatGPT画面向けOAuthではない。

## Tools

`search_notes`、`get_notes`、`retrieve_context`。任意のscopeは発行時の範囲を狭める場合のみ指定できる。

```json
{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"search_notes","arguments":{"query":"旅行","mode":"keyword","limit":6}}}
```

`get_notes`は`ids`配列を受け取り、他は`query`、`mode`（auto/keyword/semantic/hybrid）、`limit`を受け取る。`retrieve_context`は同一チャンネル内の返信・隣接メモも候補にする。本文と構造化blockの検索用テキストを返す。画像・添付の取得やURLアクセスはしない。表・グラフの集計計算は行わない。

結果にはevidence、メモID、revision/contentRevision、サーバー・チャンネル・日時・作者、実際の検索方式、projectionの反映状態を含む。参照本文は合計6,000 UTF-8 bytes以内を基本とする保守的予算。未同期・削除済み・許可外のメモは返さない。

PHP単独ではキーワード検索が使える。意味検索を指定する場合は、同じGroupの起動中アプリ、意味検索ON、準備済み索引が必要。中継はアプリが2秒間隔で要求を取り、要求は15秒で期限切れになる。サーバーは返却候補を同期済みの本文・block revisionと照合し、アプリの任意テキストは返さない。`auto`は利用可能な中継を試し、利用できなければキーワード検索と明示する。明示的なsemantic/hybridは利用不能時にエラー。

PHP-FPM等が2要求以上を同時処理できる必要がある。アプリ中継にはNative/Webは前面、Tauriはバックグラウンド実行を許可する。アプリ側のAIチャットOFFでも読み取り中継は継続するが、変更案は受け付けず、承認もできない。PHPの読み取り公開はサーバー側プラグインの停止・トークン失効で管理する。

## Protocol

2025-11-25はinitialize → notifications/initialized → tools/list/call。セッションIDは発行しない。2026-07-28は初期化なしでserver/discoverと通常要求を受け付ける。新版はMCP-Protocol-Version、Mcp-Method、tools/callのMcp-Name、params._metaのprotocolVersion/clientCapabilitiesが必要。新版結果にはresultType、発見・一覧にはttlMs/cacheScopeを返す。単一JSON応答で、GET/DELETEのSSE・セッション終了は提供しない。

[公式HTTP仕様](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http)

ブラウザー版でアプリ中継する場合だけ、既存サーバー設定 `allowed_origins` にアプリの正確なoriginを登録する。workerルートはそのallowlistを検証する。Native/TauriはOrigin登録不要。

## 承認付きの変更案

外部クライアント用と中継用のトークンを発行するときに`allowChanges:true`を指定する（管理画面の「変更案を許可」）。既存のトークンは読み取り専用のまま。アプリの「変更案を受け付ける」とAIチャットもONにする。

追加ツールは`list_note_targets(query)`、`get_note(message_id)`、`propose_changes(changes)`、`get_change_status(request_id)`。メモ・チャンネル・サーバー・ユーザー・タグ・カテゴリーを扱い、全ての変更はアプリの一括承認を通す。各変更はtarget/action/id/parent_id/name/content/colorの7文字列。作成idは一意な仮名、parent_idはメモならチャンネル、チャンネル・カテゴリーならサーバー。編集・削除は既存IDを指定する。未使用の値は空文字列。メモ編集前はget_noteで全文取得する。

提案の結果は`pending`と会話リンク。サーバー内に適用待ちの汎用ジョブを残さず、承認待ちの間は`get_change_status`で確認する。1回の中継の期限は従来どおり15秒。アプリのハンドルは10分・実行中の端末のみ有効。状態確認には同じ外部クライアントのトークン・範囲を使う。

承認前に`/worker/validate`で外部クライアント・中継用のトークンと範囲を再検査する。Groupはアプリの同期設定とも照合する。全文読み取りは同期済みprojectionと本文・contentRevisionを比較し、一覧は同期済みの名前と許可範囲で再構成する。提案・状態確認の結果はID・状態・会話リンクだけを返し、workerから渡された任意の本文・コメント・プレビューは公開しない。変更後の同期は通常のSolosyncを使用する。

共有ユーザー・タグの変更とサーバー新規作成には全範囲の権限が必要。トークンの範囲は発行時点の固定範囲なので、後からサーバーを追加した場合は必要に応じてトークンを発行し直す。アプリ不在の場合、変更操作は利用できず、PHPのキーワード検索は継続する。
