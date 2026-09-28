from __future__ import annotations
import json, shutil, socket, sqlite3, subprocess, sys, tempfile, time, urllib.error, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SERVER=ROOT/'payload/runtime/bridge/server.py'; FX=ROOT/'tests/fixtures'; CAT=ROOT/'payload/runtime/bridge/currency_catalog.json'

def free_port():
    s=socket.socket();s.bind(('127.0.0.1',0));p=s.getsockname()[1];s.close();return p

def get(port,path):
    with urllib.request.urlopen(f'http://127.0.0.1:{port}{path}',timeout=3) as r:return r.status,json.load(r)

def post(port,path,payload):
    req=urllib.request.Request(f'http://127.0.0.1:{port}{path}',data=json.dumps(payload).encode(),headers={'Content-Type':'application/json'},method='POST')
    with urllib.request.urlopen(req,timeout=3) as r:return r.status,json.load(r)

def status(port,path):
    try:
        with urllib.request.urlopen(f'http://127.0.0.1:{port}{path}',timeout=3) as r:return r.status
    except urllib.error.HTTPError as e:return e.code

def run(snapshot, legacy=None):
    td=tempfile.TemporaryDirectory(); root=Path(td.name); port=free_port()
    req_path=root/'requirements.json'; goals_path=root/'goals.json'
    req_path.write_bytes((FX/'sample-requirements-v06.json').read_bytes())
    goals_path.write_bytes((FX/'sample-goals-v06.json').read_bytes())

    # Public seed is intentionally schema-only. Build a tiny synthetic local
    # knowledge cache so the API item-knowledge route is still covered.
    catalog_db=root/'catalog.sqlite'
    shutil.copy2(ROOT/'seed-data/gear-center.sqlite', catalog_db)
    con=sqlite3.connect(catalog_db)
    now='2099-01-01T00:00:00Z'
    con.execute("INSERT INTO bgwiki_pages(page_title,canonical_url,revision_id,revision_timestamp,fetched_at,exists_flag,sections_json,raw_excerpt,error_message) VALUES(?,?,?,?,?,1,'{}',?,NULL)", ('Heishi Shorinken','https://www.bg-wiki.com/ffxi/Heishi_Shorinken',1,now,now,'Obtained From Dealer Moogle'))
    con.execute("INSERT INTO bgwiki_item_map(item_name_norm,item_name,page_title,confidence,updated_at) VALUES(?,?,?,?,?)", ('heishi shorinken','Heishi Shorinken','Heishi Shorinken','exact_title',now))
    con.execute("INSERT INTO bgwiki_progression_records(page_title,section_name,record_index,source_type,fields_json,created_at) VALUES(?,?,?,?,?,?)", ('Heishi Shorinken','Obtained From',0,'table',json.dumps({'NPC Name':'Dealer Moogle','Zone':'Example Zone'}),now))
    con.commit(); con.close()

    args=[sys.executable,str(SERVER),'--port',str(port),'--snapshot',str(snapshot),'--legacy-snapshot',str(legacy or FX/'sample-snapshot-v05.json'),'--points',str(FX/'sample-points-v05.json'),'--augments',str(FX/'sample-augments.json'),'--currency-catalog',str(CAT),'--requirements',str(req_path),'--goals',str(goals_path),'--performance-cache',str(root/'perf.json'),'--gearlab-root',str(root/'missing-gearlab'),'--kastra-python',sys.executable,'--catalog-db',str(catalog_db),'--runtime-overlay',str(root/'runtime-overlay.json')]
    p=subprocess.Popen(args,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    for _ in range(80):
        try:get(port,'/health');return td,p,port,req_path,goals_path
        except Exception:time.sleep(.05)
    p.terminate();raise RuntimeError('server failed')

def main():
    td,p,port,req_path,goals_path=run(FX/'sample-snapshot-v06.json')
    try:
        _,h=get(port,'/health');assert h['release']=='1.0.2' and h['mode']=='live'
        _,d=get(port,'/api/v1/dashboard');assert d['schema']=='gearcenter.dashboard/1.0' and isinstance(d.get('revision'),str) and d['revision']
        assert len(d['state'].get('key_items') or [])==2
        _,kis=get(port,'/api/v1/key-items');assert kis['schema']=='gearplaner.key-items/1.0' and kis['count']==2 and kis['items'][1]['key_item_id']==314
        assert 'combat' not in d and 'live_test' not in d
        assert len(d['plan']['personal'])==1
        _,compat=get(port,'/api/v0.8/status');assert compat['release']=='1.0.2'
        _,farm=get(port,'/api/v1/farming');assert farm['personal_only'] is True
        _,know=get(port,'/api/v1/item-knowledge?name=Heishi%20Shorinken');assert know['item']['cached'] is True and 'Dealer Moogle' in know['item']['acquisition_hint']
        assert status(port,'/api/v1/combat')==404
        assert status(port,'/api/v1/live-test')==404

        requirements=json.loads(req_path.read_text())
        task_ids=[str(t['id']) for t in requirements.get('tasks') or []]
        assert task_ids
        existing=json.loads(goals_path.read_text())
        existing_ids=[str(g.get('task_id')) for g in existing.get('goals') or [] if g.get('enabled',True) is not False]
        # Remove an existing personal guide goal through the public API.
        assert existing_ids
        victim=existing_ids[0]
        _,removed=post(port,'/api/v1/goals',{'action':'remove','task_id':victim})
        remaining=[str(g.get('task_id')) for g in removed['goals'].get('goals') or [] if g.get('enabled',True) is not False]
        assert victim not in remaining
        # And set it again; recommendation -> goal remains explicit and reversible.
        _,restored=post(port,'/api/v1/goals',{'action':'set','task_id':victim})
        restored_ids=[str(g.get('task_id')) for g in restored['goals'].get('goals') or [] if g.get('enabled',True) is not False]
        assert victim in restored_ids
    finally:p.terminate();p.wait(timeout=3);td.cleanup()
    td,p,port,_,_=run(FX/'does-not-exist.json',FX/'also-does-not-exist.json')
    try:
        _,h=get(port,'/health');assert h['mode']=='pre_live' and h['release']=='1.0.2'
        _,d=get(port,'/api/v1/dashboard');assert d['status']['snapshot_available'] is False and d.get('revision')
    finally:p.terminate();p.wait(timeout=3);td.cleanup()
    print('PASS: API v1 1.0.2 live/pre-live/revision/goal set-remove/compatibility/retired-routes')
if __name__=='__main__':main()
