<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/plugins/mcp/Protocol.php';require_once dirname(__DIR__).'/plugins/mcp/Notes.php';
use Solosync\SyncServer\Plugins\Mcp\{Protocol,Notes};
$call=static fn($name,$args)=>['evidence'=>[]];
$old=Protocol::handle(json_decode('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}'),[],$call);
check(json_decode($old->body,true)['result']['protocolVersion']==='2025-11-25','MCP legacy initialize failed');
$notification=Protocol::handle(json_decode('{"jsonrpc":"2.0","method":"notifications/initialized"}'),[],$call);check($notification->status===202&&$notification->body==='','MCP notification returned a response');
$body=json_decode('{"jsonrpc":"2.0","id":2,"method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}');
$headers=['mcp-protocol-version'=>'2026-07-28','mcp-method'=>'server/discover'];$modern=Protocol::handle($body,$headers,$call);$result=json_decode($modern->body,true)['result'];check($result['resultType']==='complete'&&$result['ttlMs']===0,'MCP discovery failed');
$bad=Protocol::handle($body,array_merge($headers,['mcp-method'=>'tools/list']),$call);check(json_decode($bad->body,true)['error']['code']===-32020,'MCP routing mismatch accepted');
$body->params=new stdClass();$bad=Protocol::handle($body,$headers,$call);check(json_decode($bad->body,true)['error']['code']===-32602,'MCP missing metadata accepted');
$scope=['guildIds'=>['g'],'channelIds'=>['c']];$denied=false;try{Notes::granted($scope,['guildIds'=>['g']]);}catch(\Solosync\SyncServer\Core\ApiException){$denied=true;}check($denied,'MCP grant widened');
check(strlen(Notes::bounded(str_repeat('漢',20),10))===9,'MCP bounded text cut UTF8');
echo "MCP protocol tests passed.\n";
