<?php
declare(strict_types=1);
use Solosync\SyncServer\Core\{ApiException,PluginContext,PluginHttpRequest,PluginHttpResponse};
use Solosync\SyncServer\Plugins\Mcp\{Bridge,Notes,Protocol};
require_once __DIR__.'/Notes.php';require_once __DIR__.'/Protocol.php';require_once __DIR__.'/Bridge.php';
return new class {
    public function handleHttp(PluginContext $context,PluginHttpRequest $request): PluginHttpResponse
    {
        if($request->admin)return $this->admin($context,$request);
        if(!$context->requestIsHttps())throw new ApiException(400,'HTTPS_REQUIRED','HTTPS is required');
        if($request->header('origin')!=='' && (!str_starts_with($request->path,'/worker/') || !$context->allowsOrigin($request->header('origin'))))throw new ApiException(403,'ORIGIN_DENIED','Browser origins are not granted');
        $authorization=$request->header('authorization');$secret=str_starts_with($authorization,'Bearer ')?substr($authorization,7):'';
        $store=$context->storage();$token=$store->first('SELECT * FROM plugin_mcp_tokens WHERE secret_hash=? AND revoked_at IS NULL',[hash('sha256',$secret,true)]);
        if($secret===''||!$token)throw new ApiException(401,'INVALID_MCP_TOKEN','Invalid MCP token');
        if($request->method!=='POST')return new PluginHttpResponse(405,'',headers:['Allow'=>'POST']);
        if(!str_starts_with(strtolower($request->header('content-type')),'application/json'))throw new ApiException(415,'UNSUPPORTED_MEDIA_TYPE','Expected application/json');
        if(in_array($request->path,['/worker/claim','/worker/result','/worker/validate'],true)){
            if($token['kind']!=='worker')throw new ApiException(403,'WORKER_TOKEN_REQUIRED','Worker credential required');
            $context->readModel()->step(10);return PluginHttpResponse::json(200,(new Bridge($store,$context->readModel()))->worker($token,$request->path,$request->json()));
        }
        if($request->path!=='/mcp')throw new ApiException(404,'NOT_FOUND','Unknown MCP route');
        if($token['kind']!=='read')throw new ApiException(403,'READ_TOKEN_REQUIRED','Read credential required');
        $window=(int)floor(time()/60);
        $store->execute('UPDATE plugin_mcp_tokens SET rate_count=IF(rate_window=?,rate_count+1,1),rate_window=? WHERE token_id=?',[$window,$window,$token['token_id']]);
        $rate=$store->first('SELECT rate_count FROM plugin_mcp_tokens WHERE token_id=?',[$token['token_id']]);if((int)$rate['rate_count']>60)throw new ApiException(429,'RATE_LIMITED','MCP request limit exceeded');
        try{$body=$request->jsonObject();}catch(ApiException){return PluginHttpResponse::json(400,['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32700,'message'=>'Parse error']]);}
        return Protocol::handle($body,$request->headers,function($name,$args)use($context,$token,$store){
            $saved=json_decode($token['scope_json'],true,32,JSON_THROW_ON_ERROR);
            $scope=Notes::granted(Notes::scope($saved),$args['scope']??null);
            if(in_array($name,['list_note_targets','get_note','propose_changes','get_change_status'],true)){
                if(($saved['allowChanges']??false)!==true)throw new ApiException(403,'READ_ONLY','Enable change proposals for this credential');
                $context->readModel()->step(20);return (new Bridge($store,$context->readModel()))->request($token,$name,$args,$scope);
            }
            $keys=$name==='get_notes'?['scope','ids']:['scope','query','mode','limit'];if(array_diff(array_keys($args),$keys))throw new ApiException(400,'INVALID_ARGUMENTS','Unknown arguments');
            $context->readModel()->step(20);$notes=new Notes($store,$context->readModel());
            if($name==='get_notes'){if(!is_array($args['ids']??null))throw new ApiException(400,'INVALID_IDS','IDs required');return ['evidence'=>$notes->get($args['ids'],$scope),'readiness'=>'ready','projection'=>$context->readModel()->status()];}
            if(!is_string($args['query']??null)||trim($args['query'])===''||strlen($args['query'])>24000)throw new ApiException(400,'INVALID_QUERY','Query required');
            $mode=$args['mode']??'auto';$limit=$args['limit']??12;if(!in_array($mode,['auto','keyword','semantic','hybrid'],true)||!is_int($limit)||$limit<1||$limit>12)throw new ApiException(400,'INVALID_ARGUMENTS','Invalid mode or limit');
            if($mode==='semantic'||$mode==='hybrid')return (new Bridge($store,$context->readModel()))->request($token,$name,$args,$scope);
            if($mode==='auto'){
                $active=$store->first("SELECT token_id FROM plugin_mcp_tokens WHERE kind='worker' AND revoked_at IS NULL AND last_seen>UTC_TIMESTAMP(6)-INTERVAL 10 SECOND LIMIT 1");
                if($active){try{return (new Bridge($store,$context->readModel()))->request($token,$name,$args,$scope);}catch(ApiException $e){if(!in_array($e->apiCode,['SEMANTIC_UNAVAILABLE','SCOPE_DENIED'],true))throw $e;}}
            }
            return $notes->search($args['query'],$scope,$limit,$name==='retrieve_context');
        });
    }
    private function admin(PluginContext $context,PluginHttpRequest $request): PluginHttpResponse
    {
        $store=$context->storage();
        if($request->method==='GET'&&$request->path==='/tokens')return PluginHttpResponse::json(200,['tokens'=>$store->all('SELECT token_id AS id,label,kind,scope_json AS scopeJson,last_seen AS lastSeen,created_at AS createdAt FROM plugin_mcp_tokens WHERE revoked_at IS NULL ORDER BY created_at DESC')]);
        if($request->method==='POST'&&$request->path==='/tokens'){
            $body=$request->json();$label=$body['label']??'';$kind=$body['kind']??'';$scope=Notes::scope($body['scope']??null);
            if(!is_string($label)||trim($label)===''||strlen($label)>120||!in_array($kind,['read','worker'],true))throw new ApiException(400,'INVALID_TOKEN','Name, type and scope required');
            $model=$context->readModel();$model->step(20);$model->requireReady();
            $guilds=array_column($model->guilds(),'id');if(array_diff($scope['guildIds'],$guilds))throw new ApiException(400,'INVALID_SCOPE','Unknown server');
            foreach($scope['channelIds']??[] as $id){$channel=$model->channel($id);if(!$channel||!in_array($channel['guild_id'],$scope['guildIds'],true))throw new ApiException(400,'INVALID_SCOPE','Channel outside scope');}
            $scope['allowChanges']=($body['allowChanges']??false)===true;
            $id=bin2hex(random_bytes(16));$secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
            $store->transaction(function()use($store,$kind,$id,$secret,$label,$scope){if($kind==='worker')$store->execute("UPDATE plugin_mcp_tokens SET revoked_at=UTC_TIMESTAMP(6) WHERE kind='worker' AND revoked_at IS NULL");$store->execute('INSERT INTO plugin_mcp_tokens(token_id,label,kind,secret_hash,scope_json) VALUES (?,?,?,?,?)',[$id,$label,$kind,hash('sha256',$secret,true),json_encode($scope,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);});
            return PluginHttpResponse::json(201,['id'=>$id,'secret'=>$secret,'groupId'=>$model->status()['groupId'],'path'=>'/v1/plugins/mcp'.($kind==='read'?'/mcp':'')]);
        }
        if($request->method==='POST'&&preg_match('#^/tokens/([0-9a-f]{32})/revoke$#D',$request->path,$m)){$store->execute('UPDATE plugin_mcp_tokens SET revoked_at=UTC_TIMESTAMP(6) WHERE token_id=?',[$m[1]]);$store->execute('DELETE FROM plugin_mcp_requests WHERE token_id=? OR worker_id=?',[$m[1],$m[1]]);return PluginHttpResponse::json(200,['revoked'=>true]);}
        throw new ApiException(404,'NOT_FOUND','Unknown MCP admin route');
    }
};
