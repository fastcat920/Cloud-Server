#!/usr/bin/env python3
"""Isolated callback and restore checks; Telegram is mocked by tests/router.php."""
import json,urllib.request,urllib.error,http.cookiejar,subprocess,tempfile,socket,time,os,shutil,base64
from pathlib import Path
root=Path(__file__).resolve().parents[1];php=os.environ.get('PHP_BINARY',shutil.which('php') or 'php')
with tempfile.TemporaryDirectory(prefix='notification-http-') as directory:
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 proc=subprocess.Popen([php,'-S',f'127.0.0.1:{port}','-t',str(root),str(root/'tests/router.php')],env={**os.environ,'CLOUD_DESK_DATA':directory,'CLOUD_DESK_TEST':'1'},stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base=f'http://127.0.0.1:{port}/index.php?api='
 def call(action,data=None,csrf='',status=200,public=False,raw=False,content_type='application/json'):
  req=urllib.request.Request(base+action,data=(data if isinstance(data,bytes) else json.dumps(data).encode()) if data is not None else None,headers={'Content-Type':content_type,'X-CSRF-Token':csrf})
  try:r=(urllib.request.build_opener() if public else client).open(req)
  except urllib.error.HTTPError as e:r=e
  payload=r.read();assert r.code==status,(action,r.code,payload)
  return payload if raw else json.loads(payload)
 try:
  for _ in range(50):
   try:b=call('boot')['data'];break
   except OSError:time.sleep(.1)
  csrf=b['csrf'];call('setup',{'email':'notice@test.local','password':'notification-password'},csrf);call('login',{'email':'notice@test.local','password':'notification-password'},csrf);csrf=call('boot')['data']['csrf']
  call('notify.list',status=401,public=True)
  channel=call('notify.channel.save',{'name':'Bot','token':'123456:'+('a'*32),'chat_id':'-100123456','thread_id':'','enabled':True},csrf)['data']
  assert call('notify.verify',{'id':channel},csrf)['data']['username']=='notice_test_bot'
  source=call('notify.source.save',{'name':'Aliyun GTM','channel_id':channel,'enabled':True},csrf)['data'];config=call('notify.list')['data'];key=config['sources'][0]['webhook_key']
  assert 'token' not in config['channels'][0]
  overview=call('overview')['data'];assert not any(k.startswith('notification_') for k in overview)
  action=f'notify.webhook&source={source}&key={key}'
  call(action,public=True,status=405)
  call(f'notify.webhook&source={source}&key='+('0'*64),{'status':'OCCURRED'},public=True,status=401)
  event={'id':'event-1','status':'OCCURRED','alertMessage':'Address 192.0.2.1 unavailable'}
  assert not call(action,event,public=True)['data']['duplicate']
  assert call(action,event,public=True)['data']['duplicate']
  deliveries=call('notify.list')['data']['deliveries'];assert len(deliveries)==1 and deliveries[0]['status']=='sent' and deliveries[0]['attempts']==1
  assert deliveries[0]['message']==json.dumps(event)
  original='  original **body**\n'+('中😀'*2200)+'\n  '
  multipart={'id':'multipart-http','text':{'content':original}}
  part=call(action,multipart,public=True)['data'];jobs=call('notify.list')['data']['deliveries'];assert jobs[0]['message']==original and jobs[0]['status']=='pending' and jobs[0]['part_index']==1
  call('notify.process',{},csrf);assert call('notify.list')['data']['deliveries'][0]['status']=='sent'
  event['id']='event-2';event['status']='RESOLVED';call(action,event,public=True)
  call(action,b'{broken',public=True,status=422);call(action,b'x'*131073,public=True,status=413)
  call(action,b'alertName=GTM&alertState=ALARM&message=test',public=True,content_type='application/x-www-form-urlencoded')
  gtm={'alertType':'addr_alert','desc':'地址异常','domainAlertContent':{'addressIp':'192.0.2.37','addressName':'cece','domainName':'example.com','instanceName':'GTM','time':'2026-11-16 23:10:16','timestamp':1794841816581},'title':'公网全局流量管理 (GTM) 健康检查状态变更通知','userId':12345,'webhook':'https://example.invalid/?key=private-callback'}
  call(action,gtm,public=True);readable=call('notify.list')['data']['deliveries'][0];assert readable['content_mode']=='readable' and '域名：example.com' in readable['message'] and 'private-callback' not in readable['message'] and '地址池' not in readable['message']
  call('notify.source.save',{'id':source,'name':'Aliyun GTM','channel_id':channel,'enabled':True,'format':'original'},csrf)
  call(action,gtm,public=True);assert call('notify.list')['data']['deliveries'][0]['message']==json.dumps(gtm)
  exported=json.loads(call('backup',{},csrf,raw=True))
  # Rewrap the backup under another installation key, then import into the current installation.
  code="require '_core/Store.php';require '_core/Security.php';$b=json_decode(stream_get_contents(STDIN),true);$from=new CloudDesk\\Security($b['key']);$key=base64_encode(random_bytes(32));$to=new CloudDesk\\Security($key);foreach(['notification_channels'=>'token','notification_sources'=>'secret'] as $table=>$field){foreach($b['state'][$table] as &$r)$r[$field]=$to->encrypt($from->decrypt($r[$field]));unset($r);}$b['key']=$key;echo json_encode($b);"
  rewrapped=subprocess.run([php,'-r',code],cwd=root,input=json.dumps(exported).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True).stdout
  boundary='notice-backup';body=('--'+boundary+'\r\nContent-Disposition: form-data; name="confirmation"\r\n\r\n恢复备份\r\n--'+boundary+'\r\nContent-Disposition: form-data; name="file"; filename="backup.json"\r\nContent-Type: application/json\r\n\r\n').encode()+rewrapped+('\r\n--'+boundary+'--\r\n').encode()
  call('restore',body,csrf,content_type='multipart/form-data; boundary='+boundary)
  assert call('notify.list')['data']['sources'][0]['webhook_key']==key
  assert call('notify.test',{'id':channel},csrf)['data']['status']=='sent'
  call('notify.source.rotate',{'id':source,'confirmation':str(source)},csrf)
  call(action,event,public=True,status=401)
  call('notify.channel.delete',{'id':channel,'confirmation':str(channel)},csrf,status=422)
  call('notify.source.delete',{'id':source,'confirmation':str(source)},csrf);call('notify.channel.delete',{'id':channel,'confirmation':str(channel)},csrf)
  print('PASS: unauthenticated secret-protected callback, JSON/FORM input, deduplication, no secret overview, bounds, Telegram delivery and cross-key backup restore')
 finally:proc.terminate();proc.wait()
