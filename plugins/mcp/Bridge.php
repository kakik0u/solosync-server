<?php
declare(strict_types=1);
namespace Solosync\SyncServer\Plugins\Mcp;
use Solosync\SyncServer\Core\{ApiException,PluginStorage,ReadModel};
final class Bridge
{
    public function __construct(private PluginStorage $store,private ReadModel $model){}
    public function request(array $client,string $name,array $args,array $scope): array
    {
        $status=$this->model->status();$this->model->requireReady();
        $unavailable=in_array($name,['list_note_targets','get_note','propose_changes','get_change_status'],true)?'APP_REQUIRED':'SEMANTIC_UNAVAILABLE';
        $worker=$this->store->first("SELECT * FROM plugin_mcp_tokens WHERE kind='worker' AND revoked_at IS NULL AND last_seen>UTC_TIMESTAMP(6)-INTERVAL 10 SECOND ORDER BY created_at DESC LIMIT 1");
        if(!$worker)throw new ApiException(503,$unavailable,'No active app worker');
        Notes::granted(json_decode($worker['scope_json'],true,32,JSON_THROW_ON_ERROR),$scope);
        if(in_array($name,['list_note_targets','get_note','propose_changes','get_change_status'],true) && (json_decode($worker['scope_json'],true)['allowChanges']??false)!==true)throw new ApiException(403,'READ_ONLY','Worker does not accept change proposals');
        $id=bin2hex(random_bytes(16));$args['scope']=$scope;
        $this->store->execute('DELETE FROM plugin_mcp_requests WHERE expires_at<UTC_TIMESTAMP(6)-INTERVAL 1 MINUTE LIMIT 100');
        $this->store->execute('INSERT INTO plugin_mcp_requests(request_id,token_id,worker_id,group_id,generation,name,arguments_json,expires_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6)+INTERVAL 15 SECOND)',[$id,$client['token_id'],$worker['token_id'],$status['groupId'],$status['generation'],$name,json_encode($args,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $start=microtime(true);
        try{
            do{
                $row=$this->store->first('SELECT state,result_json,(expires_at>UTC_TIMESTAMP(6)) AS live FROM plugin_mcp_requests WHERE request_id=?',[$id]);
                if(!$row||!(int)$row['live'])break;
                if($row['state']==='done'){
                    $active=$this->store->first('SELECT token_id FROM plugin_mcp_tokens WHERE token_id=? AND revoked_at IS NULL',[$client['token_id']]);
                    $activeWorker=$this->store->first('SELECT token_id FROM plugin_mcp_tokens WHERE token_id=? AND revoked_at IS NULL',[$worker['token_id']]);
                    $current=$this->model->status();if(!$active||!$activeWorker||$current['groupId']!==$status['groupId']||$current['generation']!==$status['generation'])throw new ApiException(403,'REQUEST_REVOKED','Request no longer permitted');
                    $result=json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
                    if(isset($result['error']))throw new ApiException(503,in_array($result['error'],['READ_ONLY','AI_DISABLED','NOTE_CHANGED','NOTE_SCOPE_DENIED','READ_NOTE_FIRST','APPROVAL_EXPIRED','SOURCE_CHANGED','INVALID_ARGUMENTS','TOO_MANY_PROPOSALS'],true)?$result['error']:'SEMANTIC_UNAVAILABLE','App unavailable');
                    if(in_array($name,['list_note_targets','get_note','propose_changes','get_change_status'],true))return $this->verifyAction($name,$result,$scope,$args);
                    if(in_array($args['mode']??null,['semantic','hybrid'],true)&&($result['effectiveMode']??null)!==$args['mode'])throw new ApiException(503,'SEMANTIC_UNAVAILABLE','Worker returned a different search mode');
                    return (new Notes($this->store,$this->model))->verify($result,$scope);
                }
                usleep(100000);
            }while(microtime(true)-$start<15);
            throw new ApiException(503,$unavailable,'App request expired');
        }finally{$this->store->execute('DELETE FROM plugin_mcp_requests WHERE request_id=?',[$id]);}
    }
    public function worker(array $worker,string $path,array $body): array
    {
        $status=$this->model->status();$this->model->requireReady();
        if(($body['groupId']??null)!==$status['groupId'])throw new ApiException(409,'GROUP_MISMATCH','Wrong Group');
        $this->store->execute('UPDATE plugin_mcp_tokens SET last_seen=UTC_TIMESTAMP(6) WHERE token_id=? AND revoked_at IS NULL',[$worker['token_id']]);
        if($path==='/worker/validate'){
            $client=$this->store->first("SELECT * FROM plugin_mcp_tokens WHERE token_id=? AND kind='read' AND revoked_at IS NULL",[is_string($body['clientId']??null)?$body['clientId']:'']);
            $grant=$client?json_decode($client['scope_json'],true):[];$workerGrant=json_decode($worker['scope_json'],true);
            if(!$client||($grant['allowChanges']??false)!==true||($workerGrant['allowChanges']??false)!==true)throw new ApiException(403,'READ_ONLY','Change grant revoked');
            Notes::granted(Notes::scope($grant),$body['scope']??null);Notes::granted(Notes::scope($workerGrant),$body['scope']??null);
            return ['authorized'=>true];
        }
        if($path==='/worker/claim')return $this->store->transaction(function()use($worker,$status){
            $row=$this->store->first("SELECT * FROM plugin_mcp_requests WHERE worker_id=? AND state='pending' AND expires_at>UTC_TIMESTAMP(6) AND group_id=? AND generation=? ORDER BY created_at LIMIT 1 FOR UPDATE",[$worker['token_id'],$status['groupId'],$status['generation']]);
            if(!$row)return ['job'=>null];
            $this->store->execute("UPDATE plugin_mcp_requests SET state='claimed' WHERE request_id=?",[$row['request_id']]);
            return ['job'=>['id'=>$row['request_id'],'groupId'=>$row['group_id'],'clientId'=>$row['token_id'],'name'=>$row['name'],'arguments'=>json_decode($row['arguments_json'],true,32,JSON_THROW_ON_ERROR),'expiresAt'=>str_replace(' ','T',$row['expires_at']).'Z']];
        });
        if($path==='/worker/result'){
            if(!is_string($body['id']??null))throw new ApiException(400,'INVALID_RESULT','Request ID required');
            $result=isset($body['error'])?['error'=>is_string($body['error'])?$body['error']:'SEARCH_UNAVAILABLE']:($body['result']??null);
            if(!is_array($result))throw new ApiException(400,'INVALID_RESULT','Invalid result');
            $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($json)>60000)throw new ApiException(413,'RESULT_TOO_LARGE','Result too large');
            $changed=$this->store->execute("UPDATE plugin_mcp_requests SET state='done',result_json=? WHERE request_id=? AND worker_id=? AND state='claimed' AND group_id=? AND generation=? AND expires_at>UTC_TIMESTAMP(6)",[$json,$body['id'],$worker['token_id'],$status['groupId'],$status['generation']]);
            if(!$changed)throw new ApiException(409,'REQUEST_EXPIRED','Request expired');return ['accepted'=>true];
        }
        throw new ApiException(404,'NOT_FOUND','Unknown worker route');
    }
    /** New proposal responses never return arbitrary app memo text. */
    public function verifyAction(string $name,array $result,array $scope,array $args=[]): array
    {
        if($name==='get_note'){
            $row=(new Notes($this->store,$this->model))->row(is_string($result['message_id']??null)?$result['message_id']:'',$scope);
            if(!$row||($result['body']??null)!==$row['content']||($result['revision']??null)!==Notes::contentRevision($row))throw new ApiException(409,'SOURCE_CHANGED','Wait for synchronization');
            return ['message_id'=>$row['id'],'channel_id'=>$row['channel_id'],'body'=>$row['content'],'revision'=>Notes::contentRevision($row),'rich_content_preserved'=>true];
        }
        if($name==='list_note_targets'){
            $out=['hasMore'=>($result['hasMore']??false)===true];
            $full=!isset($scope['channelIds'])&&!array_diff(array_column($this->model->guilds(),'id'),$scope['guildIds']);
            foreach(['servers'=>'projection_guilds','channels'=>'projection_channels','users'=>'projection_profiles','tags'=>'projection_tags','categories'=>'projection_categories'] as $key=>$table){
                $out[$key]=[];
                foreach(array_slice(is_array($result[$key]??null)?$result[$key]:[],0,50) as $item){
                    if(!is_array($item)||!is_string($item['id']??null))continue;
                    $row=$this->store->first("SELECT * FROM $table WHERE id=?",[$item['id']]);if(!$row)continue;
                    if($key==='channels'&&($row['archived_at']??null)!==null)continue;
                    if($key==='servers'&&!in_array($row['id'],$scope['guildIds'],true)||in_array($key,['channels','categories'],true)&&!in_array($row['guild_id'],$scope['guildIds'],true)||$key==='channels'&&isset($scope['channelIds'])&&!in_array($row['id'],$scope['channelIds'],true))continue;
                    if(!$full&&in_array($key,['users','tags'],true)){
                        $sql='c.guild_id IN ('.implode(',',array_fill(0,count($scope['guildIds']),'?')).')';$params=$scope['guildIds'];
                        if(isset($scope['channelIds'])){$sql.=' AND c.id IN ('.(count($scope['channelIds'])?implode(',',array_fill(0,count($scope['channelIds']),'?')):'NULL').')';array_push($params,...$scope['channelIds']);}
                        $join=$key==='tags'?' JOIN projection_message_tags mt ON mt.message_id=m.id ':'';$owner=$key==='tags'?'mt.tag_id':'m.user_id';
                        if(!$this->store->first('SELECT m.id FROM projection_messages m JOIN projection_channels c ON c.id=m.channel_id'.$join.' WHERE m.archived_at IS NULL AND c.archived_at IS NULL AND '.$owner.'=? AND '.$sql.' LIMIT 1',[$row['id'],...$params]))continue;
                    }
                    if($key==='categories'&&isset($scope['channelIds'])){ $allowed=false;foreach($scope['channelIds'] as $channelId)if(($this->model->channel($channelId)['category_id']??null)===$row['id']){$allowed=true;break;}if(!$allowed)continue; }
                    $view=array_intersect_key($row,array_flip(['id','name','guild_id','category']));$view['id']=(string)$view['id'];$out[$key][]=$view;
                }
            }return $out;
        }
        foreach(['request_id','conversation_id','message_id'] as $key)if(!is_string($result[$key]??null)||!preg_match('/^[a-zA-Z0-9-]{1,255}$/D',$result[$key]))throw new ApiException(400,'INVALID_RESULT','Invalid proposal handle');
        if(!in_array($result['status']??null,['pending','applied','rejected','expired'],true))throw new ApiException(400,'INVALID_RESULT','Invalid proposal status');
        if($name==='propose_changes'&&$result['status']!=='pending'||$name==='get_change_status'&&($args['request_id']??null)!==$result['request_id'])throw new ApiException(400,'INVALID_RESULT','Unexpected proposal status or handle');
        $out=array_intersect_key($result,array_flip(['request_id','conversation_id','message_id','status']));
        $out['url']='solocord://channel/'.$out['conversation_id'].'/message/'.$out['message_id'];
        $out['changes']=[];
        foreach(array_slice(is_array($result['changes']??null)?$result['changes']:[],0,20) as $change){
            if(!is_array($change)||!in_array($change['target']??null,['memo','channel','server','user','tag','category'],true)||!in_array($change['action']??null,['create','update','delete'],true)||!is_string($change['id']??null)||!preg_match('/^[@a-zA-Z0-9_-]{1,255}$/D',$change['id']))throw new ApiException(400,'INVALID_RESULT','Invalid change receipt');
            $out['changes'][]=['target'=>$change['target'],'action'=>$change['action'],'id'=>$change['id'],'applied'=>($change['applied']??false)===true];
        }
        $out['instruction']='Review and approve this batch in Solocord. Pending does not mean applied.';
        return $out;
    }

}
