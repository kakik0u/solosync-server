<?php
declare(strict_types=1);
namespace Solosync\SyncServer\Plugins\Mcp;
use Solosync\SyncServer\Core\{ApiException,PluginHttpResponse};
final class Protocol
{
    public const VERSIONS=['2026-07-28','2025-11-25'];
    public static function tools(): array
    {
        $scope=['type'=>'object','properties'=>['guildIds'=>['type'=>'array','items'=>['type'=>'string'],'minItems'=>1,'maxItems'=>100],'channelIds'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>100]],'required'=>['guildIds'],'additionalProperties'=>false];
        $tools=[];
        foreach(['search_notes','get_notes','retrieve_context'] as $name) $tools[]=['name'=>$name,'description'=>$name==='get_notes'?'Read up to 12 synchronized notes by ID.':($name==='search_notes'?'Search synchronized notes. Semantic modes require an active prepared app.':'Retrieve bounded synchronized reference notes and same-channel context. Does not generate an answer.'),'inputSchema'=>['type'=>'object','properties'=>['scope'=>$scope]+($name==='get_notes'?['ids'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>12]]:['query'=>['type'=>'string','minLength'=>1,'maxLength'=>8000],'mode'=>['enum'=>['auto','keyword','semantic','hybrid']],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>12]]),'required'=>[$name==='get_notes'?'ids':'query'],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false]];
        $fields=[];foreach(['id','parent_id','name','content','color'] as $key)$fields[$key]=['type'=>'string'];
        $action=['type'=>'object','properties'=>['target'=>['enum'=>['memo','channel','server','user','tag','category']],'action'=>['enum'=>['create','update','delete']]]+$fields,'required'=>['target','action','id','parent_id','name','content','color'],'additionalProperties'=>false];
        foreach(['list_note_targets','get_note','propose_changes','get_change_status'] as $name){
            $prop=match($name){'list_note_targets'=>['query'=>['type'=>'string']],'get_note'=>['message_id'=>['type'=>'string']],'propose_changes'=>['changes'=>['type'=>'array','minItems'=>1,'maxItems'=>20,'items'=>$action]],default=>['request_id'=>['type'=>'string']]};
            $tools[]=['name'=>$name,'description'=>match($name){'list_note_targets'=>'List permitted servers, channels, profiles, tags and categories. Requires an active app.','get_note'=>'Read the complete current memo before proposing an edit.','propose_changes'=>'Propose memo/channel/server/user/tag/category create, update or delete operations. Requires change-enabled credentials and human approval in Solocord. All seven string fields are required per change; irrelevant fields are empty. Create id is a temporary reference, parent_id can reference earlier created servers/channels. Category parent_id is a server; tag content is general/character/copyright/artist/meta. Nothing is applied yet.',default=>'Poll the status of a proposed batch. Cannot approve it.'},'inputSchema'=>['type'=>'object','properties'=>['scope'=>$scope]+$prop,'required'=>array_keys($prop),'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>$name!=='propose_changes','destructiveHint'=>false,'idempotentHint'=>$name!=='propose_changes','openWorldHint'=>false]];
        }
        return $tools;
    }
    public static function handle(\stdClass $body,array $headers,callable $call): PluginHttpResponse
    {
        $id=$body->id??null;
        $fail=static fn(int $code,string $message,int $status=400,?array $data=null)=>PluginHttpResponse::json($status,['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]+($data?['data'=>$data]:[])]);
        if(($body->jsonrpc??null)!=='2.0'||!is_string($body->method??null)||(property_exists($body,'id')&&!is_string($id)&&!is_int($id)&&!is_float($id))) return $fail(-32600,'Invalid Request');
        $params=$body->params??new \stdClass();if(!$params instanceof \stdClass)return $fail(-32602,'Invalid params');
        $version=$headers['mcp-protocol-version']??($body->method==='server/discover'?'2026-07-28':'2025-11-25');$modern=$version===self::VERSIONS[0];
        if(!in_array($version,self::VERSIONS,true))return $fail(-32022,'Unsupported protocol version',400,['supported'=>self::VERSIONS,'requested'=>$version]);
        if($modern){
            if(($headers['mcp-method']??null)!==$body->method||$body->method==='tools/call'&&($headers['mcp-name']??null)!==($params->name??null))return $fail(-32020,'MCP routing headers do not match');
            $meta=$params->_meta??null;if(!$meta instanceof \stdClass||($meta->{'io.modelcontextprotocol/protocolVersion'}??null)!==$version||!($meta->{'io.modelcontextprotocol/clientCapabilities'}??null) instanceof \stdClass)return $fail(-32602,'Missing protocol metadata');
        }
        if(!property_exists($body,'id'))return new PluginHttpResponse(202,'');
        $result=static fn(array $value)=>PluginHttpResponse::json(200,['jsonrpc'=>'2.0','id'=>$id,'result'=>($modern?['resultType'=>'complete']:[])+$value]);
        if($body->method==='initialize'&&!$modern)return $result(['protocolVersion'=>'2025-11-25','capabilities'=>['tools'=>new \stdClass()],'serverInfo'=>['name'=>'solosync','version'=>'1.0.0']]);
        if($body->method==='server/discover'&&$modern)return $result(['supportedVersions'=>self::VERSIONS,'capabilities'=>['tools'=>new \stdClass()],'_meta'=>['io.modelcontextprotocol/serverInfo'=>['name'=>'solosync','version'=>'1.0.0']],'ttlMs'=>0,'cacheScope'=>'private']);
        if($body->method==='ping')return $result([]);
        if($body->method==='tools/list')return $result(['tools'=>self::tools()]+($modern?['ttlMs'=>0,'cacheScope'=>'private']:[]));
        if($body->method!=='tools/call')return $fail(-32601,'Method not found',$modern?404:200);
        if(!in_array($params->name??null,array_column(self::tools(),'name'),true))return $fail(-32602,'Unknown tool',200);
        try{
            $args=$params->arguments??new \stdClass();if(!$args instanceof \stdClass)throw new ApiException(400,'INVALID_ARGUMENTS','Expected an object');
            $data=$call($params->name,json_decode(json_encode($args,JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR));
            return $result(['content'=>[['type'=>'text','text'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]],'structuredContent'=>$data,'isError'=>false]);
        }catch(ApiException $error){return $result(['content'=>[['type'=>'text','text'=>$error->apiCode]],'isError'=>true]);}
    }
}
