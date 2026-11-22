<?php
declare(strict_types=1);
use Solosync\SyncServer\Core\{ApiException,PluginContext,PluginHttpRequest};
use Solosync\SyncServer\Plugins\Mcp\Notes;
$manager->enable('mcp');
// Reply projection migrates/replays from ordinary packs, with omission preservation.
$replyPack=$snapshot;$replyPack['packId']='pack-mcp-reply';$replyPack['operations']=[[
 'operationId'=>'op-mcp-reply','originDeviceId'=>'device-a','table'=>'messages','rowId'=>'mcp-reply','operation'=>'UPSERT','executedAtMs'=>900000,'logicalClock'=>900,
 'data'=>['id'=>'mcp-reply','channel_id'=>'channel-1','content'=>'Synthetic reply From MCP','timestamp'=>'2026-10-15T00:00:00.000Z','reference_message_id'=>'message-1','blocks_json'=>null]
]];
$putReply=static function($pack)use($repo){$bytes=json_encode($pack,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$repo->put($pack['packId'],$bytes,hash('sha256',$bytes),'device-a');};
$putReply($replyPack);$model->step(100);
if($db->query("SELECT reference_message_id FROM projection_messages WHERE id='mcp-reply'")->fetchColumn()!=='message-1')throw new RuntimeException('Reply reference was not projected');
$replyPack['packId']='pack-mcp-reply-omit';$replyPack['operations'][0]['operationId']='op-mcp-reply-omit';$replyPack['operations'][0]['executedAtMs']++;$replyPack['operations'][0]['logicalClock']++;unset($replyPack['operations'][0]['data']['reference_message_id']);
$putReply($replyPack);$model->step(100);$model->rebuild();$model->step(100);
if($db->query("SELECT reference_message_id FROM projection_messages WHERE id='mcp-reply'")->fetchColumn()!=='message-1')throw new RuntimeException('Omitted reply field/rebuild erased the reference');

$mcp=$manager->module('mcp');$mcpContext=new PluginContext($db,$config,'mcp',[],['read-model','http']);
$channel=$model->channel('channel-1');$scope=['guildIds'=>[$channel['guild_id']],'channelIds'=>['channel-1']];
$adminRequest=static fn($method,$path,$body=[])=>new PluginHttpRequest($method,$path,[],[],json_encode($body,JSON_THROW_ON_ERROR),true);
$issue=static function($kind,$allow=false)use($mcp,$mcpContext,$adminRequest,$scope){return json_decode($mcp->handleHttp($mcpContext,$adminRequest('POST','/tokens',['kind'=>$kind,'label'=>'integration-'.$kind,'scope'=>$scope,'allowChanges'=>$allow]))->body,true,32,JSON_THROW_ON_ERROR);};
$read=$issue('read');$worker=$issue('worker');
$headers=['authorization'=>'Bearer '.$read['secret'],'content-type'=>'application/json'];
$rpc=static function($name,$args)use($mcp,$mcpContext,$headers){$response=$mcp->handleHttp($mcpContext,new PluginHttpRequest('POST','/mcp',[],$headers,json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],JSON_THROW_ON_ERROR),false));return json_decode($response->body,true,32,JSON_THROW_ON_ERROR)['result'];};
$readonly=$rpc('propose_changes',['changes'=>[]]);if(!$readonly['isError']||$readonly['content'][0]['text']!=='READ_ONLY')throw new RuntimeException('Existing read token gained write proposals');
$result=$rpc('search_notes',['query'=>'From','mode'=>'keyword']);
if($result['isError']||!count($result['structuredContent']['evidence'])||$result['structuredContent']['effectiveMode']!=='keyword')throw new RuntimeException('PHP MCP keyword search failed');
$note=$result['structuredContent']['evidence'][0];
$get=$rpc('get_notes',['ids'=>[$note['messageId']]]);if($get['isError']||$get['structuredContent']['evidence'][0]['contentRevision']!==$note['contentRevision'])throw new RuntimeException('MCP get/revision mismatch');
$wide=$rpc('search_notes',['query'=>'From','scope'=>['guildIds'=>[$channel['guild_id']]]]);if(!$wide['isError']||$wide['content'][0]['text']!=='SCOPE_DENIED')throw new RuntimeException('MCP widened channel scope');
$offline=$rpc('search_notes',['query'=>'From','mode'=>'semantic']);if(!$offline['isError']||$offline['content'][0]['text']!=='SEMANTIC_UNAVAILABLE')throw new RuntimeException('MCP lied about unavailable semantic search');
foreach([['authorization'=>'Bearer invalid','content-type'=>'application/json'], $headers+['origin'=>'https://evil.example']] as $bad){$rejected=false;try{$mcp->handleHttp($mcpContext,new PluginHttpRequest('POST','/mcp',[],$bad,'{}',false));}catch(ApiException $e){$rejected=in_array($e->status,[401,403],true);}if(!$rejected)throw new RuntimeException('MCP authentication/origin failed');}
$claimHeaders=['authorization'=>'Bearer '.$worker['secret'],'content-type'=>'application/json'];
$mcp->handleHttp($mcpContext,new PluginHttpRequest('POST','/worker/claim',[],$claimHeaders,json_encode(['groupId'=>$groupId],JSON_THROW_ON_ERROR),false));
$badGroup=false;try{$mcp->handleHttp($mcpContext,new PluginHttpRequest('POST','/worker/claim',[],$claimHeaders,'{"groupId":"wrong"}',false));}catch(ApiException $e){$badGroup=$e->apiCode==='GROUP_MISMATCH';}if(!$badGroup)throw new RuntimeException('Worker crossed Groups');
// A real second PHP process claims the queued request while this request waits.
if(!function_exists('proc_open'))throw new RuntimeException('proc_open required for MCP relay integration');
$process=proc_open([PHP_BINARY,__DIR__.'/mcp-test-worker.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
if(!is_resource($process))throw new RuntimeException('Cannot start test worker');
fwrite($pipes[0],json_encode(['config'=>$tmp,'secret'=>$worker['secret'],'groupId'=>$groupId],JSON_THROW_ON_ERROR));fclose($pipes[0]);
$semantic=$rpc('retrieve_context',['query'=>'From','mode'=>'semantic']);
$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
if($exit!==0||$semantic['isError']||$semantic['structuredContent']['effectiveMode']!=='semantic'||!count($semantic['structuredContent']['evidence']))throw new RuntimeException('MCP app relay failed: '.$errors.$output);
if($db->query("SELECT blocks_json IS NULL FROM projection_messages WHERE id='mcp-reply'")->fetchColumn()!=1)throw new RuntimeException('Null blocks changed during projection/rebuild');
// Never return a worker-supplied private quote or unsynchronized revision.
$notes=new Notes($mcpContext->storage(),$model);$fake=$note;$fake['quote']='UNTRUSTED PRIVATE QUOTE';
$verified=$notes->verify(['evidence'=>[$fake],'effectiveMode'=>'semantic'],$scope);
if(str_contains(json_encode($verified),'UNTRUSTED PRIVATE QUOTE'))throw new RuntimeException('Worker supplied arbitrary text');
$fake['contentRevision']=str_repeat('0',64);if(count($notes->verify(['evidence'=>[$fake],'effectiveMode'=>'semantic'],$scope)['evidence']))throw new RuntimeException('Worker stale revision accepted');
$stale=$note;$stale['revision']=str_repeat('f',64);if(count($notes->verify(['evidence'=>[$stale],'effectiveMode'=>'semantic'],$scope)['evidence']))throw new RuntimeException('Stale metadata/reply revision accepted');
$expired=bin2hex(random_bytes(16));
$mcpContext->storage()->execute("INSERT INTO plugin_mcp_requests(request_id,token_id,worker_id,group_id,generation,name,arguments_json,state,expires_at) VALUES (?,?,?,?,?,'search_notes','{}','claimed',UTC_TIMESTAMP(6)-INTERVAL 1 SECOND)",[$expired,$read['id'],$worker['id'],$groupId,$model->status()['generation']]);
$late=false;try{$mcp->handleHttp($mcpContext,new PluginHttpRequest('POST','/worker/result',[],$claimHeaders,json_encode(['id'=>$expired,'groupId'=>$groupId,'result'=>[]],JSON_THROW_ON_ERROR),false));}catch(ApiException $e){$late=$e->apiCode==='REQUEST_EXPIRED';}if(!$late)throw new RuntimeException('Expired job result accepted');
$mcp->handleHttp($mcpContext,$adminRequest('POST','/tokens/'.$read['id'].'/revoke'));
$revoked=false;try{$rpc('search_notes',['query'=>'From']);}catch(ApiException $e){$revoked=$e->apiCode==='INVALID_MCP_TOKEN';}if(!$revoked)throw new RuntimeException('Revoked MCP token accepted');
$changeRead=$issue('read',true);$changeWorker=$issue('worker',true);
$changeHeaders=['authorization'=>'Bearer '.$changeRead['secret'],'content-type'=>'application/json'];$changeWorkerHeaders=['authorization'=>'Bearer '.$changeWorker['secret'],'content-type'=>'application/json'];
$validate=static function($clientId)use($mcp,$mcpContext,$changeWorkerHeaders,$groupId,$scope){return $mcp->handleHttp($mcpContext,new PluginHttpRequest('POST','/worker/validate',[],$changeWorkerHeaders,json_encode(['groupId'=>$groupId,'clientId'=>$clientId,'scope'=>$scope],JSON_THROW_ON_ERROR),false));};
if($validate($changeRead['id'])->status!==200)throw new RuntimeException('Valid change grant rejected');
$rejectedRead=false;try{$validate($read['id']);}catch(ApiException $e){$rejectedRead=$e->apiCode==='READ_ONLY';}if(!$rejectedRead)throw new RuntimeException('Read/revoked credential gained proposal permission');
$mcp->handleHttp($mcpContext,new PluginHttpRequest('POST','/worker/claim',[],$changeWorkerHeaders,json_encode(['groupId'=>$groupId],JSON_THROW_ON_ERROR),false));
$proposal=['request_id'=>'proposal-1','conversation_id'=>'conversation-1','message_id'=>'response-1','status'=>'pending','body'=>'UNTRUSTED PRIVATE TEXT'];
$process=proc_open([PHP_BINARY,__DIR__.'/mcp-test-worker.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
fwrite($pipes[0],json_encode(['config'=>$tmp,'secret'=>$changeWorker['secret'],'groupId'=>$groupId,'proposal'=>$proposal],JSON_THROW_ON_ERROR));fclose($pipes[0]);
$before=$db->query('SELECT COUNT(*) FROM sync_packs')->fetchColumn();
$changeReply=$mcp->handleHttp($mcpContext,new PluginHttpRequest('POST','/mcp',[],$changeHeaders,json_encode(['jsonrpc'=>'2.0','id'=>5,'method'=>'tools/call','params'=>['name'=>'propose_changes','arguments'=>['changes'=>[['target'=>'memo','action'=>'create','id'=>'new','parent_id'=>'channel-1','name'=>'','content'=>'Synthetic draft','color'=>'']]]]],JSON_THROW_ON_ERROR),false));
$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$data=json_decode($changeReply->body,true)['result'];
if($exit!==0||$data['isError']||$data['structuredContent']['status']!=='pending'||str_contains($changeReply->body,'UNTRUSTED PRIVATE TEXT')||$before!==$db->query('SELECT COUNT(*) FROM sync_packs')->fetchColumn())throw new RuntimeException('Proposal relay wrote data or exposed arbitrary worker data '.$errors.$output);
$exact=$notes->row($note['messageId'],$scope);$verified=(new \Solosync\SyncServer\Plugins\Mcp\Bridge($mcpContext->storage(),$model))->verifyAction('get_note',['message_id'=>$exact['id'],'body'=>$exact['content'],'revision'=>Notes::contentRevision($exact)],$scope);if($verified['body']!==$exact['content'])throw new RuntimeException('Exact memo verification failed');
$changed=false;try{(new \Solosync\SyncServer\Plugins\Mcp\Bridge($mcpContext->storage(),$model))->verifyAction('get_note',['message_id'=>$exact['id'],'body'=>'private','revision'=>Notes::contentRevision($exact)],$scope);}catch(ApiException $e){$changed=$e->apiCode==='SOURCE_CHANGED';}if(!$changed)throw new RuntimeException('Unverified exact memo returned');
$mcp->handleHttp($mcpContext,$adminRequest('POST','/tokens/'.$changeRead['id'].'/revoke'));$revokedChange=false;try{$validate($changeRead['id']);}catch(ApiException $e){$revokedChange=$e->apiCode==='READ_ONLY';}if(!$revokedChange)throw new RuntimeException('Approval can use revoked client');
$again=$issue('worker');$workerRevoked=false;try{$mcp->handleHttp($mcpContext,new PluginHttpRequest('POST','/worker/claim',[],$claimHeaders,json_encode(['groupId'=>$groupId],JSON_THROW_ON_ERROR),false));}catch(ApiException $e){$workerRevoked=$e->apiCode==='INVALID_MCP_TOKEN';}if(!$workerRevoked)throw new RuntimeException('Replacing worker did not revoke old worker');
$manager->disable('mcp');$manager->enable('mcp');if(!$manager->isEnabled('mcp'))throw new RuntimeException('MCP plugin did not re-enable');
echo "MCP MariaDB auth/scope/search/relay/expiry tests passed.\n";
