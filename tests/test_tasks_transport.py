#!/usr/bin/env python3
"""Exercise native task CRUD through compiled Rust, PHP and ActiveSync objects."""
import argparse
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

    def log_message(self,*args): pass

    def respond(self,payload):
        data=json.dumps(payload).encode()
        self.send_response(200); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(data))); self.end_headers(); self.wfile.write(data)

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
                    if obj.get('@type')!='Task': self.errors.append('Created an Event instead of a Task')
                    new='created-'+str(self.state); self.objects[new]=dict(obj,id=new); result.setdefault('created',{})[ident]={'id':new}
                for ident,patch in args.get('update',{}).items():
                    for path,value in patch.items():
                        keys=[k.replace('~1','/').replace('~0','~') for k in path.split('/')]
                        node=self.objects[ident]
                        for key in keys[:-1]: node=node[key]
                        if value is None: node.pop(keys[-1],None)
                        else: node[keys[-1]]=value
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
$stat=$b->ChangeMessage('task-a',false,$t,new ContentParameters()); check(is_array($stat),'Rust native task create'); $id=$stat['id'];
$old=$stat['mod']; $edit=new SyncTask(); $edit->complete=1; $edit->datecompleted=strtotime('2026-10-28T16:00:00Z');
$edit->utcduedate=strtotime('2026-10-30T12:00:00Z'); $edit->reminderset=1; $edit->remindertime=strtotime('2026-10-30T11:00:00Z');
$stat=$b->ChangeMessage('task-a',$id,$edit,new ContentParameters()); check(is_array($stat) && $stat['mod']!==$old,'Rust native task completion and date/reminder edit');
$got=$b->GetMessage('task-a',$id,new ContentParameters()); check($got->complete===1 && $got->utcduedate===$edit->utcduedate && $got->remindertime===$edit->remindertime,'Rust task fields round trip');
check(stream_get_contents($got->asbody->data)==='Preserved notes','Rust partial update preserves notes');
check(!$b->DeleteMessage('task-a','denied',new ContentParameters()),'Rust per-object deletion failure is not reported as success');
check($b->DeleteMessage('task-a',$id,new ContentParameters()),'Rust task delete');
check($c->getTasks(array($id))===array(),'Rust deleted task is absent');
'''


def main():
    parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('--image',required=True); args=parser.parse_args()
    root=Path(__file__).resolve().parents[1]
    server=ThreadingHTTPServer(('127.0.0.1',0),Fixture)
    thread=threading.Thread(target=server.serve_forever,daemon=True); thread.start()
    try:
        subprocess.run(['docker','run','--rm','-i','--network','host','--entrypoint','php','-v',str(root)+':/workspace:ro',
                        '-e',f'TASK_TEST_URL=http://127.0.0.1:{server.server_port}',args.image],input=PHP,text=True,check=True,timeout=40)
        if Fixture.errors: raise RuntimeError(str(Fixture.errors))
    finally:
        server.shutdown(); server.server_close(); thread.join()


if __name__=='__main__': main()
