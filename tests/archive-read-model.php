<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\{PluginStorage,ReadModel};
use Solosync\SyncServer\Plugins\Mcp\Notes;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP: archive read tests require pdo_sqlite.\n";
    return;
}
// Exercise the actual public/MCP SELECTs; only MySQL scalar syntax is adapted.
$archiveDb = new class('sqlite::memory:') extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace('LEFT(', '"LEFT"(', $query), $options);
    }
};
$archiveDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$archiveDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$archiveDb->sqliteCreateFunction('LEFT', static fn($text,$length) => mb_substr($text,0,$length));
$archiveDb->sqliteCreateFunction('CHAR_LENGTH', static fn($text) => mb_strlen($text));
$archiveDb->sqliteCreateFunction('OCTET_LENGTH', static fn($text) => $text === null ? null : strlen($text));
$archiveDb->exec("CREATE TABLE projection_state(id INTEGER,group_id TEXT,generation INTEGER,sequence INTEGER,status TEXT,last_error TEXT);
CREATE TABLE sync_instance(id INTEGER,group_id TEXT,generation INTEGER,head_sequence INTEGER);
CREATE TABLE projection_revision(id INTEGER,revision INTEGER);
CREATE TABLE projection_channels(id TEXT,name TEXT,guild_id TEXT,is_hidden INTEGER,archived_at TEXT);
CREATE TABLE projection_channel_details(channel_id TEXT,category_id TEXT,topic TEXT);
CREATE TABLE projection_guilds(id TEXT,name TEXT);
CREATE TABLE projection_profiles(id TEXT,name TEXT);
CREATE TABLE projection_messages(id TEXT,channel_id TEXT,content TEXT,blocks_json TEXT,timestamp TEXT,timestamp_ms INTEGER,edited_timestamp TEXT,user_id TEXT,reference_message_id TEXT,archived_at TEXT);
INSERT INTO projection_state VALUES(1,'group',1,0,'ready',NULL);
INSERT INTO sync_instance VALUES(1,'group',1,0);
INSERT INTO projection_channels VALUES('normal','Notes','g',0,NULL),('session','AI','g',0,'2026-10-22');
INSERT INTO projection_guilds VALUES('g','Server');
INSERT INTO projection_messages VALUES('live','normal','visible','{\"version\":1,\"blocks\":[]}','2026-10-22',1,NULL,NULL,NULL,NULL),('archived','normal','private archive','{\"version\":1,\"blocks\":[]}','2026-10-22',2,NULL,NULL,NULL,'2026-10-22'),('history','session','private session','{\"version\":1,\"blocks\":[]}','2026-10-22',3,NULL,NULL,NULL,NULL);");
$archiveDb->prepare('INSERT INTO projection_revision VALUES(1,?)')->execute([ReadModel::REVISION]);
$archiveModel = new ReadModel($archiveDb);
check(array_column($archiveModel->messages('normal',10),'id') === ['live'],'Public messages exposed archive');
check($archiveModel->messages('session',10) === [],'Public messages exposed archived AI session');
check($archiveModel->channel('session') === null,'Public channel exposed archived session');
$archiveNotes = new Notes(new PluginStorage($archiveDb,'mcp'),$archiveModel);
$archiveScope=['guildIds'=>['g']];
check($archiveNotes->row('live',$archiveScope) !== null,'MCP lost ordinary message');
check($archiveNotes->row('archived',$archiveScope) === null,'MCP exposed archived message');
check($archiveNotes->row('history',$archiveScope) === null,'MCP exposed archived session');
$archiveDb->exec("UPDATE projection_messages SET archived_at=NULL WHERE id='archived'; UPDATE projection_channels SET archived_at=NULL WHERE id='session'");
check(count($archiveModel->messages('normal',10))===2 && $archiveNotes->row('history',$archiveScope)!==null,'Unarchive did not restore visibility');
unset($archiveDb,$archiveModel,$archiveNotes);
echo "Archive public/MCP read tests passed.\n";
