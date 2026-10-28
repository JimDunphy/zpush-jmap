#!/usr/bin/env python3
"""Exercise native task CRUD through compiled Rust, PHP and ActiveSync objects."""
import argparse
import copy
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import subprocess
import threading


class Fixture(BaseHTTPRequestHandler):
    objects = {
        'task-1': {'id':'task-1','@type':'Task','calendarIds':{'a':True},'title':'Task from another client','description':'Notes','progress':'needs-action'},
        'event-1': {'id':'event-1','@type':'Event','calendarIds':{'a':True},'title':'Appointment','utcStart':'2026-10-28T12:00:00Z'},
        'denied': {'id':'denied','@type':'Task','calendarIds':{'a':True},'title':'Protected'},
    }
    errors=[]
    state=1
    legacy=False
    null_only=False
    writes=[]
    server_time='2026-10-28T17:00:00Z'

    def log_message(self,*args): pass

    def respond(self,payload,status=200):
        data=json.dumps(payload).encode()
        self.send_response(status); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(data))); self.end_headers(); self.wfile.write(data)

    def do_GET(self):
        base=f'http://127.0.0.1:{self.server.server_port}'
        self.respond({'apiUrl':base+'/jmap','downloadUrl':base+'/download/{accountId}/{blobId}/{name}',
                      'uploadUrl':base+'/upload/{accountId}','primaryAccounts':{'urn:ietf:params:jmap:mail':'a'}})

    def do_POST(self):
        request=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        responses=[]
        for method,args,call in request['methodCalls']:
            result={'accountId':'a','state':str(self.state),'list':[],'notFound':[]}
            if method=='Mailbox/get' or method=='AddressBook/get': pass
            elif method=='Calendar/get': result['list']=[{'id':'a','name':'Calendar','isDefault':True}]
            elif method=='CalendarEvent/query':
                ids=[k for k,v in self.objects.items() if v['calendarIds'].get(args['filter']['inCalendar'])]
                result={'ids':ids[args.get('position',0):args.get('position',0)+args.get('limit',100)],'total':len(ids),'queryState':str(self.state)}
            elif method=='CalendarEvent/get':
                for ident in args['ids']:
                    if ident not in self.objects: result['notFound'].append(ident); continue
                    result['list'].append({k:v for k,v in self.objects[ident].items() if k in args.get('properties',self.objects[ident])})
            elif method=='CalendarEvent/set':
                result={'accountId':'a','oldState':str(self.state),'newState':str(self.state+1)}
                for ident,obj in args.get('create',{}).items():
                    self.writes.append(('create',copy.deepcopy(obj)))
                    if 'progressUpdated' in obj and (self.legacy or self.null_only and obj['progressUpdated'] is None):
                        result.setdefault('notCreated',{})[ident]={'type':'invalidProperties','properties':['progressUpdated']}; continue
                    if obj.get('title')=='Reject another property':
                        result.setdefault('notCreated',{})[ident]={'type':'invalidProperties','properties':['due']}; continue
                    if obj.get('@type')!='Task': self.errors.append('Created an Event instead of a Task')
                    new='created-'+str(self.state); self.objects[new]=dict(obj,id=new,updated=self.server_time); result.setdefault('created',{})[ident]={'id':new}
                    if obj.get('title')=='Ambiguous transport failure':
                        self.respond({'error':'response lost after write'},status=503); return
                for ident,patch in args.get('update',{}).items():
                    self.writes.append(('update',{'id':ident,**copy.deepcopy(patch)}))
                    if ident=='denied':
                        result.setdefault('notUpdated',{})[ident]={'type':'forbidden'}; continue
                    if 'progressUpdated' in patch and (self.legacy or self.null_only and patch['progressUpdated'] is None or self.objects[ident]['@type']!='Task'):
                        result.setdefault('notUpdated',{})[ident]={'type':'invalidProperties','properties':['progressUpdated']}; continue
                    for path,value in patch.items():
                        keys=[k.replace('~1','/').replace('~0','~') for k in path.split('/')]
                        node=self.objects[ident]
                        for key in keys[:-1]: node=node[key]
                        if value is None: node.pop(keys[-1],None)
                        else: node[keys[-1]]=value
                    self.objects[ident]['updated']=self.server_time
                    result.setdefault('updated',{})[ident]=None
                for ident in args.get('destroy',[]):
                    if ident=='denied': result.setdefault('notDestroyed',{})[ident]={'type':'forbidden'}
                    else: self.objects.pop(ident); result.setdefault('destroyed',[]).append(ident)
                type(self).state+=1
            else:
                self.errors.append('Unexpected method '+method)
                responses.append(['error',{'type':'unknownMethod'},call]); continue
            responses.append([method,result,call])
        self.respond({'methodResponses':responses,'sessionState':'1'})


PHP=r'''<?php
chdir('/var/www/zpush');
class ZLog { public static function Write($level,$message,$truncate=true) {} }
require 'vendor/autoload.php';
require '/workspace/backend/stalwart/stalwart.php';
(new ReflectionProperty(Request::class,'asProtocolVersion'))->setValue(null,'14.1');
function check($c,$m) { if (!$c) throw new RuntimeException($m); echo "PASS: $m\n"; }
class TransportTasks extends BackendStalwart {
    public function __construct($c) { $this->_jmapClient=$c; $this->_connected=true; }
}
$c=jmap_connect(getenv('TASK_TEST_URL'),'dummy@example.test','test-only');
$b=new TransportTasks($c); check($b->Setup('dummy'),'Rust calendar discovery and task folders');
check(array_column($b->GetMessageList('task-a',0),'id')===array('task-1','denied'),'Rust Task/Event discrimination');
check(array_column($b->GetMessageList('cal-a',0),'id')===array('event-1'),'Rust appointment isolation');
check($b->GetMessage('task-a','task-1',new ContentParameters())->subject==='Task from another client','Rust task read');
$t=new SyncTask(); $t->subject='Created via ActiveSync'; $t->body='Preserved notes'; $t->complete=0;
$t->utcduedate=strtotime('2026-10-29T12:00:00Z');
$stat=$b->ChangeMessage('task-a',false,$t,new ContentParameters()); check(is_array($stat),'Rust native task create'); $id=$stat['id'];
check($b->GetMessage('task-a',$id,new ContentParameters())->utcduedate===$t->utcduedate,'New incomplete task due tomorrow is stored');
$old=$stat['mod']; $edit=new SyncTask(); $edit->complete=1; $edit->datecompleted=strtotime('2026-10-28T16:00:00Z');
$edit->utcduedate=strtotime('2026-10-30T12:00:00Z'); $edit->reminderset=1; $edit->remindertime=strtotime('2026-10-30T11:00:00Z');
$stat=$b->ChangeMessage('task-a',$id,$edit,new ContentParameters()); check(is_array($stat) && $stat['mod']!==$old,'Rust native task completion and date/reminder edit');
$got=$b->GetMessage('task-a',$id,new ContentParameters()); check($got->complete===1 && $got->utcduedate===$edit->utcduedate && $got->remindertime===$edit->remindertime,'Rust task fields round trip');
$expected=getenv('TASK_TEST_LEGACY')==='1' ? strtotime('2026-10-28T17:00:00Z') : $edit->datecompleted;
check($got->datecompleted===$expected,'Completion timestamp uses native field or server-modification fallback');
check(stream_get_contents($got->asbody->data)==='Preserved notes','Rust partial update preserves notes');
$reopen=new SyncTask(); $reopen->complete=0;
check(is_array($b->ChangeMessage('task-a',$id,$reopen,new ContentParameters())) && $b->GetMessage('task-a',$id,new ContentParameters())->complete===0,'Task can be reopened');
$edit->datecompleted+=7200;
check(is_array($b->ChangeMessage('task-a',$id,$edit,new ContentParameters())),'Reopened task can be completed again');
$expected=getenv('TASK_TEST_LEGACY')==='1' ? strtotime('2026-10-28T17:00:00Z') : $edit->datecompleted;
check($b->GetMessage('task-a',$id,new ContentParameters())->datecompleted===$expected,'Recompletion never reuses an old completion time');
$done=new SyncTask(); $done->subject='Created completed'; $done->complete=1; $done->datecompleted=$edit->datecompleted;
$created=$b->ChangeMessage('task-a',false,$done,new ContentParameters());
check(is_array($created) && $b->GetMessage('task-a',$created['id'],new ContentParameters())->complete===1,'Already-completed task create survives timestamp compatibility retry');
check($b->DeleteMessage('task-a',$created['id'],new ContentParameters()),'Completed fixture task cleanup');
$invalid=new SyncTask(); $invalid->subject='Reject another property'; $invalid->complete=0;
check($b->ChangeMessage('task-a',false,$invalid,new ContentParameters())===false,'Unrelated invalid properties remain failures');
$denied=new SyncTask(); $denied->complete=1;
check($b->ChangeMessage('task-a','denied',$denied,new ContentParameters())===false,'Permission errors remain failures');
try { $c->updateCalendarEvent('event-1',json_encode(array('progressUpdated'=>'2026-10-28T16:00:00Z'))); throw new RuntimeException('Unexpected Event update success'); }
catch (Throwable $e) { check(strpos($e->getMessage(),'invalidProperties')!==false,'Non-Task timestamp rejection is not bypassed'); }
$ambiguous=new SyncTask(); $ambiguous->subject='Ambiguous transport failure'; $ambiguous->complete=0;
check($b->ChangeMessage('task-a',false,$ambiguous,new ContentParameters())===false,'Ambiguous transport failure is reported without replay');
check(!$b->DeleteMessage('task-a','denied',new ContentParameters()),'Rust per-object deletion failure is not reported as success');
check($b->DeleteMessage('task-a',$id,new ContentParameters()),'Rust task delete');
check($c->getTasks(array($id))===array(),'Rust deleted task is absent');
'''


def main():
    parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('--image',required=True); parser.add_argument('--legacy-only',action='store_true'); args=parser.parse_args()
    root=Path(__file__).resolve().parents[1]
    modes=[(True,False)] if args.legacy_only else [(False,False),(False,True),(True,False)]
    for legacy,null_only in modes:
        cls=type('TaskFixture',(Fixture,),dict(objects=copy.deepcopy(Fixture.objects),errors=[],state=1,writes=[],legacy=legacy,null_only=null_only))
        server=ThreadingHTTPServer(('127.0.0.1',0),cls)
        thread=threading.Thread(target=server.serve_forever,daemon=True); thread.start()
        print('Testing task transport:', 'timestamp field rejected' if legacy else 'null timestamp rejected (observed incomplete-task failure)' if null_only else 'timestamp-capable server',flush=True)
        try:
            subprocess.run(['docker','run','--rm','-i','--network','host','--entrypoint','php','-v',str(root)+':/workspace:ro',
                            '-e',f'TASK_TEST_URL=http://127.0.0.1:{server.server_port}',
                            '-e',f'TASK_TEST_LEGACY={int(legacy)}',args.image],input=PHP,text=True,check=True,timeout=40)
            if cls.errors: raise RuntimeError(str(cls.errors))
            creates=[v for op,v in cls.writes if op=='create']
            assert all('progressUpdated' not in v for v in creates if v.get('title')=='Created via ActiveSync')
            completed=[v for v in creates if v.get('title')=='Created completed']
            assert len(completed)==(2 if legacy else 1) and len({v['uid'] for v in completed})==1
            assert sum(v.get('title')=='Reject another property' for v in creates)==1
            assert sum(v.get('title')=='Ambiguous transport failure' for v in creates)==1
            assert sum(op=='update' and v['id']=='denied' for op,v in cls.writes)==1
            assert sum(op=='update' and v['id']=='event-1' for op,v in cls.writes)==1
            assert sum(v.get('title')=='Ambiguous transport failure' for v in cls.objects.values())==1
        finally:
            server.shutdown(); server.server_close(); thread.join()


if __name__=='__main__': main()
