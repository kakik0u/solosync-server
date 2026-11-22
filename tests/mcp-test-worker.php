<?php
// Dedicated integration-test helper. Credentials arrive only over stdin.
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Solosync\SyncServer\Core\{Config,Database,PluginContext,PluginHttpRequest};
use Solosync\SyncServer\Plugins\Mcp\Notes;
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$config=Config::load($input['config']);$db=Database::connect($config);
$plugin=require dirname(__DIR__).'/plugins/mcp/plugin.php';
$context=new PluginContext($db,$config,'mcp',[],['read-model','http']);$_SERVER['HTTPS']='on';
$headers=['authorization'=>'Bearer '.$input['secret'],'content-type'=>'application/json'];
$start=microtime(true);
do{
    $claimed=json_decode($plugin->handleHttp($context,new PluginHttpRequest('POST','/worker/claim',[],$headers,json_encode(['groupId'=>$input['groupId']],JSON_THROW_ON_ERROR),false))->body,true,32,JSON_THROW_ON_ERROR);
    if($claimed['job']){
        $job=$claimed['job'];$scope=$job['arguments']['scope'];$notes=new Notes($context->storage(),$context->readModel());
        if(isset($input['proposal'])) { if(!isset($job['clientId']))throw new RuntimeException('Missing client identity'); $result=$input['proposal']; }
        else { $result=$notes->search($job['arguments']['query'],$scope,12,false);$result['effectiveMode']=$job['arguments']['mode']==='hybrid'?'hybrid':'semantic'; }
        $response=$plugin->handleHttp($context,new PluginHttpRequest('POST','/worker/result',[],$headers,json_encode(['groupId'=>$input['groupId'],'id'=>$job['id'],'result'=>$result],JSON_THROW_ON_ERROR),false));
        if($response->status!==200)exit(1);exit(0);
    }
    usleep(50000);
}while(microtime(true)-$start<10);
exit(2);
