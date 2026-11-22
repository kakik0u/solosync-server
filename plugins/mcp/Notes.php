<?php
declare(strict_types=1);
namespace Solosync\SyncServer\Plugins\Mcp;
use Solosync\SyncServer\Core\{ApiException,PluginStorage,ReadModel,RichEmbed};
/** Bounded, read-only access to the existing rebuildable projection. */
final class Notes
{
    private const SELECT = "SELECT m.*,c.guild_id,c.name AS channel_name,COALESCE(g.name,'') AS guild_name,COALESCE(p.name,'Memo') AS author_name FROM projection_messages m JOIN projection_channels c ON c.id=m.channel_id LEFT JOIN projection_guilds g ON g.id=c.guild_id LEFT JOIN projection_profiles p ON p.id=m.user_id";
    public function __construct(private PluginStorage $store, private ReadModel $model) {}
    public static function scope(mixed $scope): array
    {
        if (!is_array($scope) || !isset($scope['guildIds']) || !is_array($scope['guildIds']) || !array_is_list($scope['guildIds']) || count($scope['guildIds']) < 1 || count($scope['guildIds']) > 100) throw new ApiException(400,'INVALID_SCOPE','At least one server is required');
        foreach (['guildIds','channelIds'] as $field) if (isset($scope[$field])) {
            if (!is_array($scope[$field]) || !array_is_list($scope[$field]) || count($scope[$field]) > 100) throw new ApiException(400,'INVALID_SCOPE','Invalid scope');
            foreach ($scope[$field] as $id) if (!is_string($id) || $id === '' || strlen($id) > 1024) throw new ApiException(400,'INVALID_SCOPE','Invalid scope ID');
        }
        return array_intersect_key($scope, array_flip(['guildIds','channelIds']));
    }
    public static function granted(array $allowed, mixed $requested): array
    {
        self::scope($allowed); if ($requested === null) return $allowed;
        $scope = self::scope($requested);
        if (array_diff($scope['guildIds'],$allowed['guildIds']) || isset($allowed['channelIds']) && (!isset($scope['channelIds']) || array_diff($scope['channelIds'],$allowed['channelIds']))) throw new ApiException(403,'SCOPE_DENIED','Requested scope is not permitted');
        return $scope;
    }
    private function conditions(array $scope): array
    {
        self::scope($scope); $sql = 'c.guild_id IN (' . implode(',',array_fill(0,count($scope['guildIds']),'?')) . ')'; $params = $scope['guildIds'];
        if (isset($scope['channelIds'])) { $sql .= ' AND m.channel_id IN (' . (count($scope['channelIds']) ? implode(',',array_fill(0,count($scope['channelIds']),'?')) : 'NULL') . ')'; array_push($params,...$scope['channelIds']); }
        return [$sql . ' AND m.archived_at IS NULL AND c.archived_at IS NULL AND OCTET_LENGTH(m.content)+COALESCE(OCTET_LENGTH(m.blocks_json),0)<=1048576',$params];
    }
    public function row(string $id, array $scope): ?array
    {
        $this->model->requireReady(); [$sql,$params] = $this->conditions($scope);
        return $this->store->first(self::SELECT . ' WHERE m.id=? AND ' . $sql,[$id,...$params]);
    }
    public static function revision(array $row): string
    {
        return hash('sha256',json_encode([$row['content'],$row['blocks_json'] ?? '',$row['edited_timestamp'],$row['archived_at'] ?? null,$row['timestamp'],$row['channel_id'],$row['user_id'],$row['reference_message_id'] ?? null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
    }
    public static function contentRevision(array $row): string
    {
        return hash('sha256',json_encode([$row['content'],$row['blocks_json'] ?? ''],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
    }
    public static function text(array $row): string
    {
        $parts = [explode('[attachment:',(string)$row['content'],2)[0]];
        $json = $row['blocks_json'] ?? ''; if (strlen($json)>RichEmbed::MAX_BLOCK_BYTES) return $parts[0];
        try { $blocks = json_decode($json,true,32,JSON_THROW_ON_ERROR); } catch (\JsonException) { return $parts[0]; }
        foreach (array_slice(is_array($blocks['blocks'] ?? null) ? $blocks['blocks'] : [],0,16) as $block) {
            $text = is_string($block['fallbackText'] ?? null) ? $block['fallbackText'] : '';
            if (($blocks['version'] ?? null)===1 && ($block['type'] ?? null)==='rich-embed' && ($block['schemaVersion'] ?? null)===1) {
                try { $p = RichEmbed::normalize(json_decode(json_encode($block['payload'],JSON_THROW_ON_ERROR),false,32,JSON_THROW_ON_ERROR)); $fields = [$p['title'] ?? '',$p['description'] ?? '',$p['author']['name'] ?? '',$p['footer']['text'] ?? '']; foreach ($p['fields'] ?? [] as $field) array_push($fields,$field['name'],$field['value']); $text=implode("\n",array_filter($fields,static fn($v)=>is_string($v)&&$v!=='')); } catch (\Throwable) {}
            }
            if ($text!=='') $parts[]=$text;
        }
        return implode("\n",$parts);
    }
    public static function evidence(array $row, int $index, string $relation='match'): array
    {
        return ['sourceId'=>'S'.($index+1),'messageId'=>$row['id'],'revision'=>self::revision($row),'contentRevision'=>self::contentRevision($row),'guildId'=>$row['guild_id'],'channelId'=>$row['channel_id'],'guildName'=>$row['guild_name'],'channelName'=>$row['channel_name'],'timestamp'=>$row['timestamp'],'author'=>$row['author_name'],'quote'=>self::text($row),'relation'=>$relation];
    }
    public function get(array $ids,array $scope): array
    {
        if (count($ids)>12 || !array_is_list($ids)) throw new ApiException(400,'INVALID_IDS','At most 12 IDs');
        $result=[]; foreach(array_unique($ids) as $id) { if(!is_string($id)) throw new ApiException(400,'INVALID_IDS','Expected string IDs'); $row=$this->row($id,$scope); if($row) $result[]=self::evidence($row,count($result)); }
        return $result;
    }
    public function search(string $query,array $scope,int $limit,bool $context): array
    {
        $this->model->requireReady(); [$where,$params]=$this->conditions($scope);
        $tokens=preg_split('/\s+/u',trim($query),-1,PREG_SPLIT_NO_EMPTY); if(!$tokens || count($tokens)>32 || strlen($query)>24000) throw new ApiException(400,'INVALID_QUERY','Invalid query');
        foreach($tokens as $token) { $where.=" AND CONCAT(m.content,' ',COALESCE(m.blocks_json,'')) LIKE ? ESCAPE '!'"; $params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$token).'%'; }
        $rows=$this->store->all(self::SELECT.' WHERE '.$where.' ORDER BY m.timestamp_ms DESC,m.id DESC LIMIT '.max(1,min(12,$limit)),$params);
        $seen=array_fill_keys(array_column($rows,'id'),true); $candidates=array_map(static fn($row)=>[$row,'match'],$rows);
        if($context) foreach($rows as $row) {
            if(is_string($row['reference_message_id'] ?? null)) { $reply=$this->row($row['reference_message_id'],$scope); if($reply && $reply['channel_id']===$row['channel_id'] && !isset($seen[$reply['id']])) { $seen[$reply['id']]=true;$candidates[]=[$reply,'reply']; } }
            [$condition,$scopeParams]=$this->conditions($scope);
            foreach(['<','>'] as $operator) { $order=$operator==='<'?'DESC':'ASC'; $adj=$this->store->first(self::SELECT.' WHERE m.channel_id=? AND (m.timestamp_ms'.$operator.'? OR (m.timestamp_ms=? AND m.id'.$operator.'?)) AND '.$condition.' ORDER BY m.timestamp_ms '.$order.',m.id '.$order.' LIMIT 1',[$row['channel_id'],$row['timestamp_ms'],$row['timestamp_ms'],$row['id'],...$scopeParams]); if($adj&&!isset($seen[$adj['id']])) {$seen[$adj['id']]=true;$candidates[]=[$adj,'adjacent'];} }
        }
        $items=[];$remaining=6000;
        foreach(array_slice($candidates,0,$limit) as [$row,$relation]) { $item=self::evidence($row,count($items),$relation); $item['quote']=self::bounded($item['quote'],$remaining); if($item['quote']==='') break; $remaining-=strlen($item['quote']); $items[]=$item; }
        return ['evidence'=>$items,'effectiveMode'=>'keyword','readiness'=>'ready','partial'=>false,'projection'=>$this->model->status()];
    }
    public static function bounded(string $value,int $bytes): string { $part=substr($value,0,max(0,$bytes)); while($part!==''&&!preg_match('//u',$part)) $part=substr($part,0,-1); return $part; }
    public function verify(array $result,array $scope): array
    {
        if(!is_array($result['evidence'] ?? null)||count($result['evidence'])>12||!in_array($result['effectiveMode'] ?? null,['keyword','semantic','hybrid'],true)) throw new ApiException(503,'INVALID_WORKER_RESULT','Invalid worker result');
        $evidence=[];$remaining=6000;
        foreach($result['evidence'] as $candidate) {
            if(!is_string($candidate['messageId'] ?? null)||!is_string($candidate['contentRevision'] ?? null)||!is_string($candidate['revision'] ?? null)) continue;
            $row=$this->row($candidate['messageId'],$scope);
            if(!$row||$row['channel_id']!==($candidate['channelId'] ?? null)||$row['guild_id']!==($candidate['guildId'] ?? null)||!hash_equals(self::contentRevision($row),$candidate['contentRevision'])||!hash_equals(self::revision($row),$candidate['revision'])) continue;
            // Return only synchronized text; never trust a worker-provided quote or metadata.
            $item=self::evidence($row,count($evidence),in_array($candidate['relation'] ?? null,['match','reply','adjacent'],true)?$candidate['relation']:'match');
            $item['quote']=self::bounded($item['quote'],$remaining);$remaining-=strlen($item['quote']);if($item['quote']!=='')$evidence[]=$item;
        }
        return ['evidence'=>$evidence,'effectiveMode'=>$result['effectiveMode'],'readiness'=>'ready','partial'=>count($evidence)!==count($result['evidence']),'projection'=>$this->model->status()];
    }
}
