#!/usr/bin/env python3
"""Start with isolated CLOUD_DESK_DATA; no requests to real cloud resources."""
import json,urllib.request,urllib.error,http.cookiejar,subprocess,tempfile,socket,time,os,hashlib,shutil
from pathlib import Path
root=Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BINARY',shutil.which('php') or 'php')
with tempfile.TemporaryDirectory(prefix='cloud-native-http-') as directory:
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 process=subprocess.Popen([php,'-S',f'127.0.0.1:{port}','-t',str(root),str(root/'tests/router.php')],env={**os.environ,'CLOUD_DESK_DATA':directory,'CLOUD_DESK_TEST':'1'},stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base=f'http://127.0.0.1:{port}/index.php?api='
 def call(action,data=None,token='',expected=200,binary=False):
  request=urllib.request.Request(base+action,data=json.dumps(data).encode() if data is not None else None,headers={'Content-Type':'application/json','X-CSRF-Token':token})
  try:r=client.open(request)
  except urllib.error.HTTPError as error:r=error
  payload=r.read();assert r.code==expected,(action,r.code,payload)
  
  try:return payload if binary else json.loads(payload)
  except Exception:raise AssertionError((action,payload))
 try:
  for _ in range(60):
   try:boot=call('boot')['data'];break
   except OSError:time.sleep(.1)
  assert not boot['installed'];csrf=boot['csrf']
  call('setup',{'email':'owner@example.com','password':'native-password-123'},expected=419)
  call('setup',{'email':'owner@example.com','password':'native-password-123'},csrf)
  call('setup',{'email':'second@example.com','password':'native-password-123'},csrf,409)
  call('overview',expected=401)
  call('login',{'email':'owner@example.com','password':'wrong'},csrf,401)
  call('login',{'email':'owner@example.com','password':'native-password-123'},csrf)
  csrf=call('boot')['data']['csrf'];overview=call('overview')['data'];assert len(overview['versions'])==1
  account_id=call('account.save',{'name':'Testing <script>','provider':'aliyun','enabled':True,'key':'fake-key','secret':'fake-secret'},csrf)['data']
  overview=call('overview')['data'];assert 'credentials' not in overview['accounts'][0]
  assert 'fake-secret' not in Path(directory,'state.php').read_text()
  target_id=call('target.save',{'account_id':account_id,'name':'Test target','region':'cn-hangzhou','bucket':'test-bucket','key':'config.json','enabled':True},csrf)['data']
  call('account.delete',{'id':account_id,'confirmation':'Testing <script>'},csrf,422)
  call('config.save',{'id':1,'content':{'domains':['not a url']},'mode':'plain'},csrf,422)
  version=call('config.save',{'id':1,'content':{'domains':['https://example.com'],'config_version':'1'},'mode':'plain','summary':'native'},csrf)['data']
  preview=call('config.preview',{'id':version},csrf)['data'];assert preview['checksum']==hashlib.sha256(preview['payload'].encode()).hexdigest()
  clone=call('config.clone',{'id':1},csrf)['data'];assert clone==2
  call('config.settings',{'xor_key':'test-xor'},csrf)
  call('config.save',{'id':2,'content':{'domains':[]},'mode':'xor_base64'},csrf)
  assert call('config.preview',{'id':2},csrf)['data']['payload']
  backup=json.loads(call('backup',{},csrf,binary=True));assert backup['format']=='cloud-desk-v2'
  servers=call('instances&account_id='+str(account_id)+'&region=cn-hangzhou')['data'];assert servers['total']==1
  call('instance.action',{'account_id':account_id,'region':'cn-hangzhou','instance':'i-native-test','operation':'stop','confirmation':'wrong'},csrf,422)
  call('instance.action',{'account_id':account_id,'region':'cn-hangzhou','instance':'i-native-test','operation':'start','confirmation':'i-native-test'},csrf)
  call('plan.save',{'id':1,'name':'Legacy clients','clients':'1.x','template_id':1,'targets':[target_id]},csrf)
  result=call('config.publish',{'id':1,'targets':[target_id]},csrf)['data'];assert result[0]['status']=='success',result
  replacement=call('config.save',{'id':1,'content':{'domains':[],'unknown_legacy':True},'mode':'plain'},csrf)['data'];assert replacement!=1
  assert next(v for v in call('overview')['data']['versions'] if v['id']==1)['status']=='published'
  call('target.test',{'id':target_id},csrf)
  cf_id=call('account.save',{'name':'CF','provider':'cloudflare','enabled':True,'token':'test-token'},csrf)['data']
  assert call('cf.zones&account_id='+str(cf_id))['data']['result'][0]['id']=='test-zone'
  assert call('dns.list&account_id='+str(account_id)+'&domain=example.com')['data']['TotalCount']==1
  # Multipart restore verifies encrypted credentials are rewrapped, not copied under a wrong key.
  def multipart(action,fields,content):
   boundary='native-test-boundary';body=b''
   for name,value in fields.items():body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="'+name+'"\r\n\r\n'+str(value)+'\r\n').encode()
   body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="file"; filename="backup.json"\r\nContent-Type: application/json\r\n\r\n').encode()+content+('\r\n--'+boundary+'--\r\n').encode()
   request=urllib.request.Request(base+action,data=body,headers={'Content-Type':'multipart/form-data; boundary='+boundary,'X-CSRF-Token':csrf})
   response=client.open(request);assert response.code==200;return json.loads(response.read())
  multipart('restore',{'confirmation':'恢复备份'},json.dumps(backup).encode())
  assert len(call('overview')['data']['accounts'])==1
  call('target.test',{'id':target_id},csrf)
  call('plan.save',{'id':1,'name':'Legacy clients','clients':'1.x','template_id':1,'targets':[target_id]},csrf)
  call('config.publish',{'id':1,'targets':[target_id]},csrf)
  schema={'type':'object','properties':{'api_url':{'type':'string'},'new_flag':{'type':'boolean','default':True}},'required':['api_url'],'additionalProperties':True}
  template=call('template.save',{'name':'Client 2 structure','schema':schema},csrf)['data']
  duplicate=call('target.save',{'account_id':account_id,'name':'Alias path','region':'cn-hangzhou','bucket':'test-bucket','key':'config.json','enabled':True},csrf)['data']
  call('plan.save',{'name':'Conflict','template_id':template,'targets':[duplicate]},csrf,422)
  target2=call('target.save',{'account_id':account_id,'name':'Client 2 file','region':'cn-hangzhou','bucket':'test-bucket','key':'v2/config.json','enabled':True},csrf)['data']
  plan2=call('plan.save',{'name':'Client 2','clients':'>=2.0','template_id':template,'targets':[target2]},csrf)['data']
  call('config.save',{'plan_id':plan2,'template_id':template,'content':{'new_flag':True},'mode':'plain'},csrf,422)
  derived=call('config.clone',{'id':1,'plan_id':plan2},csrf)['data'];derived_row=next(v for v in call('overview')['data']['versions'] if v['id']==derived);assert derived_row['plan_id']==plan2 and derived_row['schema']==next(v for v in call('overview')['data']['versions'] if v['id']==1)['schema']
  v2=call('config.save',{'plan_id':plan2,'template_id':template,'content':{'api_url':'https://v2.example.com','future_unknown':{'x':1},'config_version':'client-two'},'mode':'plain'},csrf)['data']
  row=next(v for v in call('overview')['data']['versions'] if v['id']==v2)
  assert row['content']['config_version']=='client-two'
  assert 'new_flag' not in row['content'] and 'domains' not in row['content'] and row['content']['future_unknown']=={'x':1}
  call('config.publish',{'id':v2,'targets':[target_id]},csrf,422)
  assert call('config.publish',{'id':v2,'targets':[target2]},csrf)['data'][0]['status']=='success'
  revised=call('config.save',{'id':v2,'content':{'api_url':'https://v2.example.com','future_unknown':{'x':2},'config_version':'client-two'},'mode':'plain'},csrf)['data'];assert revised!=v2
  old=next(v for v in call('overview')['data']['versions'] if v['id']==v2);assert old['content']['future_unknown']['x']==1 and old['schema']==schema
  assert call('config.diff',{'id':revised,'from':v2},csrf)['data'][0]['path']=='$.future_unknown.x'
  rollback=call('config.clone',{'id':v2},csrf)['data'];assert call('config.publish',{'id':rollback,'targets':[target2]},csrf)['data'][0]['status']=='success'
  all_versions=call('overview')['data']['versions'];assert next(v for v in all_versions if v['id']==1)['status']=='published'
  new_backup=call('backup',{},csrf,binary=True);multipart('restore',{'confirmation':'恢复备份'},new_backup)
  restored=call('overview')['data'];assert len(restored['plans'])==2 and len(restored['templates'])==2
  print('PASS: independent client plans, immutable schemas and published revisions, missing/new/unknown fields, address isolation, diff and rollback publication')
  call('password',{'old':'native-password-123','password':'new-native-password-456'},csrf)
  call('overview',expected=401)
  call('login',{'email':'owner@example.com','password':'new-native-password-456'},csrf)
  call('logout',{},csrf);call('overview',expected=401)
  # All persisted sensitive files execute as a 404 guard, rather than raw JSON.
  assert Path(directory,'state.php').read_text().startswith('<?php http_response_code(404); exit; ?>')
  with socket.socket() as sock:sock.bind(('127.0.0.1',0));guard_port=sock.getsockname()[1]
  guard_server=subprocess.Popen([php,'-S',f'127.0.0.1:{guard_port}','-t',directory],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  try:
   for _ in range(40):
    try:
     response=urllib.request.urlopen(f'http://127.0.0.1:{guard_port}/key.php');assert response.read()==b'';break
    except OSError:time.sleep(.05)
   for sensitive in ['state.php','lock.php']:
    try:response=urllib.request.urlopen(f'http://127.0.0.1:{guard_port}/'+sensitive)
    except urllib.error.HTTPError as error:response=error
    assert response.code==404 and response.read()==b'',sensitive
  finally:guard_server.terminate();guard_server.wait()
  print('PASS: first setup, CSRF, session login/rotation/logout, authorization, encrypted credentials, reference protection, versioning, preview, backup, password revocation')
 finally:process.terminate();process.wait()
