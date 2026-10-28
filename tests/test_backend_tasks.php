<?php
// Isolated ActiveSync task regressions using real Z-Push objects and WBXML.
chdir('/var/www/zpush');
ob_start();
class ZLog {
    public static function Write($level, $message, $truncate = true) {}
    public static function IsWbxmlDebugEnabled() { return false; }
}
require 'vendor/autoload.php';
require '/workspace/backend/stalwart/stalwart.php';
(new ReflectionProperty(Request::class, 'asProtocolVersion'))->setValue(null, '14.1');
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
});
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
class TaskJmapFixture {
    public $objects;
    public $state = '1';
    public $fail = false;
    public $writes = 0;
    public function __construct() {
        $this->objects = array(
            'task-1' => array('id' => 'task-1', '@type' => 'Task', 'calendarIds' => array('a' => true),
                'title' => 'Check delivery', 'description' => 'Keep all of these notes', 'progress' => 'in-process',
                'percentComplete' => 40, 'priority' => 2, 'privacy' => 'private', 'timeZone' => 'America/Vancouver',
                'start' => '2026-10-28T08:00:00', 'due' => '2026-10-29T09:00:00', 'keywords' => array('Work' => true),
                'alerts' => array('a' => array('trigger' => array('@type' => 'OffsetTrigger', 'offset' => '-PT15M', 'relativeTo' => 'end'))),
                'vendor:extra' => 'preserve'),
            'undated' => array('id' => 'undated', '@type' => 'Task', 'calendarIds' => array('a' => true), 'title' => 'No date', 'progress' => 'needs-action'),
            'event' => array('id' => 'event', '@type' => 'Event', 'calendarIds' => array('a' => true), 'title' => 'An appointment'),
        );
    }
    public function getMailboxes() { return array(); }
    public function getAddressBooks() { return array(); }
    public function getCalendars() { return array((object) array('id'=>'a','name'=>'Calendar','is_default'=>true), (object) array('id'=>'b','name'=>'Other','is_default'=>false)); }
    public function queryCalendarEvents($calendar, $after, $before, $limit, $position) {
        if ($this->fail) throw new RuntimeException('Upstream unavailable');
        check($after === null || $after !== '', 'Invalid date filter');
        $ids = array_keys(array_filter($this->objects, fn($o) => !empty($o['calendarIds'][$calendar])));
        return (object) array('ids'=>array_slice($ids,$position,$limit),'total'=>count($ids));
    }
    public function getTasks($ids) {
        return array_values(array_map(fn($o) => (object) array('id'=>$o['id'],'data_json'=>json_encode($o)),
            array_filter($this->objects, fn($o) => in_array($o['id'],$ids,true) && $o['@type']==='Task')));
    }
    public function getCalendarEventMetadata($ids) {
        return array_map(fn($id) => (object) array('id'=>$id,'object_type'=>$this->objects[$id]['@type'],'utc_start'=>'2026-10-28T12:00:00Z'),$ids);
    }
    public function createCalendarEvent($calendar,$json) {
        ++$this->writes;
        $id='new-'.$this->writes;
        $this->objects[$id]=json_decode($json,true)+array('id'=>$id,'calendarIds'=>array($calendar=>true));
        return $id;
    }
    public function updateCalendarEvent($id,$json) {
        ++$this->writes;
        foreach (json_decode($json,true) as $path=>$value) {
            $keys=array_map(fn($s)=>str_replace(array('~1','~0'),array('/','~'),$s),explode('/',$path));
            $obj=&$this->objects[$id];
            while(count($keys)>1) { $key=array_shift($keys); if (!isset($obj[$key]) || !is_array($obj[$key])) throw new RuntimeException('Missing patch parent'); $obj=&$obj[$key]; }
            if ($value===null) unset($obj[$keys[0]]); else $obj[$keys[0]]=$value;
            unset($obj);
        }
        return true;
    }
    public function destroyCalendarEvent($id) { ++$this->writes; unset($this->objects[$id]); return true; }
    public function getCalendarEventState() { return $this->state; }
    public function getCalendarEventChanges($since) { return (object) array('has_changes'=>$since!==$this->state,'new_state'=>$this->state); }
}
class TaskBackendFixture extends BackendStalwart {
    public function __construct($client) { $this->_jmapClient=$client; $this->_connected=true; $this->_user='dummy@example.test'; }
}
function fixture() { $c=new TaskJmapFixture(); $b=new TaskBackendFixture($c); check($b->Setup('dummy'),'Setup failed'); return array($b,$c); }
$tests=array();
$tests['Task folders have stable IDs and separate ActiveSync types']=function() {
    list($b)=fixture();
    check($b->GetFolder('task-a')->type===SYNC_FOLDER_TYPE_TASK,'Missing default task folder');
    check($b->GetFolder('task-b')->type===SYNC_FOLDER_TYPE_USER_TASK,'Missing additional task folder');
    check($b->GetFolder('cal-a')->type===SYNC_FOLDER_TYPE_APPOINTMENT,'Existing calendar changed');
    check(count($b->GetFolderList())===4,'Unexpected hierarchy');
};
$tests['Tasks and appointments cannot leak into each other']=function() {
    list($b)=fixture();
    check(array_column($b->GetMessageList('task-a',time()),'id')===array('task-1','undated'),'Tasks were filtered by time or mixed with events');
    check(array_column($b->GetMessageList('cal-a',0),'id')===array('event'),'Task treated as appointment');
    check($b->StatMessage('cal-a','task-1')===false,'Task accepted as an appointment');
    check($b->GetMessage('cal-a','task-1',new ContentParameters())===false,'Task fetched as an appointment');
    check($b->DeleteMessage('cal-a','task-1',new ContentParameters())===false,'Task deleted through appointment folder');
};
$tests['Dates retain local and UTC values, including timezone offsets']=function() {
    list($b)=fixture(); $t=$b->GetMessage('task-a','task-1',new ContentParameters());
    check($t instanceof SyncTask,'Wrong ActiveSync type');
    check($t->startdate===strtotime('2026-10-28T08:00:00Z') && $t->utcstartdate===strtotime('2026-10-28T15:00:00Z'),'Start offset lost');
    check($t->utcduedate===strtotime('2026-10-29T16:00:00Z'),'Due offset lost');
    check($t->remindertime===strtotime('2026-10-29T15:45:00Z'),'Relative reminder incorrect');
    check($t->importance===2 && $t->sensitivity===2 && $t->categories===array('Work'),'Task fields lost');
};
$tests['Undated tasks stay undated']=function() {
    list($b)=fixture(); $t=$b->GetMessage('task-a','undated',new ContentParameters());
    check(!isset($t->startdate) && !isset($t->duedate),'Invented task dates');
};
$tests['A default Tasks list is provided when Stalwart omits isDefault']=function() {
    $c=new class extends TaskJmapFixture { public function getCalendars() {
        return array((object) array('id'=>'b','name'=>'Other','is_default'=>false),(object) array('id'=>'a','name'=>'Calendar','is_default'=>false));
    }};
    $b=new TaskBackendFixture($c); $b->Setup('dummy');
    check($b->GetFolder('task-a')->type===SYNC_FOLDER_TYPE_TASK && $b->GetFolder('task-b')->type===SYNC_FOLDER_TYPE_USER_TASK,'Default Tasks list was missing');
};
$tests['Completed tasks include the required completion date']=function() {
    list($b,$c)=fixture(); $c->objects['undated']['progress']='completed'; $c->objects['undated']['updated']='2026-10-28T13:00:00Z';
    $t=$b->GetMessage('task-a','undated',new ContentParameters());
    check($t->complete===1 && $t->datecompleted===strtotime('2026-10-28T13:00:00Z'),'Missing required completion date');
};
$tests['HTML task notes are readable and previews retain size metadata']=function() {
    list($b,$c)=fixture(); $c->objects['task-1']['description']='<p>Hello world</p>'; $c->objects['task-1']['descriptionContentType']='text/html';
    $cp=new ContentParameters(); $cp->BodyPreference(1)->SetTruncationSize(3);
    $t=$b->GetMessage('task-a','task-1',$cp);
    check($t instanceof SyncTask,'HTML task could not be read');
    check(strlen(stream_get_contents($t->asbody->data))===3 && $t->asbody->truncated===1 && $t->asbody->estimatedDataSize>3,'Task preview lost truncation metadata');
};
$tests['Task notes and completion survive a real WBXML round trip']=function() {
    list($b)=fixture(); $t=$b->GetMessage('task-a','task-1',new ContentParameters());
    $out=fopen('php://temp','w+'); $enc=new WBXMLEncoder($out); $enc->startWBXML(); $enc->startTag(SYNC_DATA); $t->Encode($enc); $enc->endTag(); rewind($out);
    $dec=new WBXMLDecoder($out); check($dec->getElementStartTag(SYNC_DATA),'Missing root'); $decoded=new SyncTask(); check($decoded->Decode($dec)!==false,'Task decode failed');
    check($decoded->subject==='Check delivery' && $decoded->complete==='0','Task fields missing on wire');
    check(stream_get_contents($decoded->asbody->data)==='Keep all of these notes','Notes missing on wire');
};
$tests['Note-only changes update the sync fingerprint']=function() {
    list($b,$c)=fixture(); $before=$b->StatMessage('task-a','task-1'); $c->objects['task-1']['description']='Changed';
    check($before['mod']!==$b->StatMessage('task-a','task-1')['mod'],'Notes edit was invisible to diff engine');
};
$tests['Create, complete, reopen and delete a native task']=function() {
    list($b,$c)=fixture(); $t=new SyncTask(); $t->subject='New task'; $t->complete=0; $t->body='Notes';
    $stat=$b->ChangeMessage('task-a',false,$t,new ContentParameters()); check(is_array($stat),'Create failed'); $id=$stat['id'];
    check($c->objects[$id]['@type']==='Task' && !empty($c->objects[$id]['uid']),'Missing native identity');
    $change=new SyncTask(); $change->complete=1; $change->datecompleted=strtotime('2026-10-28T16:00:00Z');
    check($b->ChangeMessage('task-a',$id,$change,new ContentParameters())!==false,'Complete failed');
    check($b->GetMessage('task-a',$id,new ContentParameters())->datecompleted===$change->datecompleted,'Completion time lost');
    $change->complete=0; check($b->ChangeMessage('task-a',$id,$change,new ContentParameters())!==false,'Reopen failed');
    check(!isset($c->objects[$id]['progressUpdated']),'Reopen retained completion date');
    check($b->DeleteMessage('task-a',$id,new ContentParameters()) && !isset($c->objects[$id]),'Delete failed');
};
$tests['Partial updates preserve notes, progress and native-only fields']=function() {
    list($b,$c)=fixture(); $t=new SyncTask(); $t->subject='Renamed'; $t->complete=0;
    check($b->ChangeMessage('task-a','task-1',$t,new ContentParameters())!==false,'Edit failed');
    check($c->objects['task-1']['description']==='Keep all of these notes' && $c->objects['task-1']['percentComplete']===40 && $c->objects['task-1']['vendor:extra']==='preserve','Partial edit destroyed data');
};
$tests['Explicit empty notes and categories clear those properties']=function() {
    list($b,$c)=fixture(); $t=new SyncTask(); $t->body=''; $t->categories=array();
    check($b->ChangeMessage('task-a','task-1',$t,new ContentParameters())!==false,'Clear failed');
    check($c->objects['task-1']['description']==='' && !isset($c->objects['task-1']['keywords']),'Clear ignored');
};
$tests['Reminder can be added when native alerts are absent']=function() {
    list($b)=fixture(); $t=new SyncTask(); $t->reminderset=1; $t->remindertime=strtotime('2026-10-30T12:00:00Z');
    check($b->ChangeMessage('task-a','undated',$t,new ContentParameters())!==false,'Reminder update failed');
    check($b->GetMessage('task-a','undated',new ContentParameters())->remindertime===$t->remindertime,'Reminder did not round trip');
};
$tests['Moves retain task identity and use calendar membership patches']=function() {
    list($b)=fixture(); check($b->MoveMessage('task-a','task-1','task-b',new ContentParameters())==='task-1','Move failed');
    check($b->StatMessage('task-a','task-1')===false && is_array($b->StatMessage('task-b','task-1')),'Move did not update membership');
};
$tests['Deleting one membership preserves a task in another calendar']=function() {
    list($b,$c)=fixture(); $c->objects['task-1']['calendarIds']['b']=true;
    check($b->DeleteMessage('task-a','task-1',new ContentParameters()),'Delete membership failed');
    check($b->StatMessage('task-a','task-1')===false && is_array($b->StatMessage('task-b','task-1')),'Deleted another list membership');
};
$tests['A task ID cannot modify or delete an appointment or another list']=function() {
    list($b,$c)=fixture(); $t=new SyncTask(); $t->subject='Wrong';
    check($b->ChangeMessage('task-a','event',$t,new ContentParameters())===false,'Modified appointment');
    check($b->DeleteMessage('task-b','task-1',new ContentParameters())===false,'Deleted from wrong list');
    check($c->writes===0,'Invalid request reached upstream');
};
$tests['Recurring and assigned task edits fail explicitly']=function() {
    list($b,$c)=fixture(); $c->objects['task-1']['recurrenceRules']=array(array('frequency'=>'daily'));
    $t=new SyncTask(); $t->subject='Unsupported';
    check($b->ChangeMessage('task-a','task-1',$t,new ContentParameters())===false && $c->writes===0,'Recurring task was flattened');
    unset($c->objects['task-1']['recurrenceRules']); $c->objects['task-1']['participants']=array('p'=>array('name'=>'Other'));
    check($b->ChangeMessage('task-a','task-1',$t,new ContentParameters())===false && $c->writes===0,'Assigned task was flattened');
};
$tests['Outages cannot become mass task deletions']=function() {
    list($b,$c)=fixture(); $c->fail=true;
    try { $b->GetMessageList('task-a',0); throw new RuntimeException('Outage accepted'); }
    catch (StatusException $e) { check($e->getCode()===SYNC_STATUS_SERVERERROR,'Wrong failure status'); }
};
$tests['Calendar push wakes appointments and tasks independently']=function() {
    list($b,$c)=fixture(); $b->ChangesSinkInitialize('cal-a'); $b->ChangesSinkInitialize('task-a'); $c->state='2';
    check($b->ChangesSink(0)===array('cal-a','task-a'),'One view consumed the other notification');
    check($b->ChangesSink(0)===array(),'Consumed change repeated');
};
$failures=0;
foreach($tests as $name=>$test) { try { $test(); echo "PASS: $name\n"; } catch(Throwable $e) { ++$failures; echo "FAIL: $name: {$e->getMessage()}\n"; } }
exit($failures ? 1 : 0);
