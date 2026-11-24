#!/usr/bin/env python3
# Optional isolated HTTP fixture checks: Debian Apache2, root, PHP CLI, Python 3.
import tempfile, pathlib, shutil, socket, subprocess, time, http.client, os
root=pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='brendy-http-') as t:
 t=pathlib.Path(t); t.chmod(0o755); doc=t/'www'; doc.mkdir(mode=0o755)
 for base in [doc,doc/'sub']:
  base.mkdir(exist_ok=True)
  shutil.copy(root/'.htaccess',base/'.htaccess')
  for file in ['sadrzaj/databases/private.txt','sadrzaj/private.log','sadrzaj/tmp/restore-last-result.json','sadrzaj/tmp/restore-transaction.json','sadrzaj/tmp/restore-ledger-lookup-bucket-ab.json','sadrzaj/tmp/restore-ledger-page-example.json','sadrzaj/.tinymce-cloud.json','brendy-content/.tinymce-cloud.json','brendy-content/log.txt','sadrzaj/uploads/a.php74.jpg','sadrzaj/uploads/a.jpg','jezgra/functions.php','dodaci/demo/plugin.php','teme/demo/index.php','tests/run.php','README.md','.env','index.php','install.php','unexpected.php','unexpected.php.jpg','extra/tool.phtml','DEPENDENCIES.json','SHA256SUMS.txt','php.ini','WEB.CONFIG','jezgra/php.ini']:
   p=base/file;p.parent.mkdir(parents=True,exist_ok=True);p.write_text('fixture')
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
 modules=['mpm_event','authz_core','authz_host','access_compat','dir','mime','rewrite','headers','autoindex','negotiation','expires','filter','deflate']
 conf=t/'httpd.conf';conf.write_text(f'ServerRoot "{t}"\nListen 127.0.0.1:{port}\nServerName localhost\nPidFile "{t}/pid"\nErrorLog "{t}/error.log"\nLogLevel warn\nUser www-data\nGroup www-data\nTypesConfig /etc/mime.types\n'+''.join(f'LoadModule {m}_module /usr/lib/apache2/modules/mod_{m}.so\n' for m in modules)+f'DocumentRoot "{doc}"\n<Directory "{doc}">\nAllowOverride All\nOptions FollowSymLinks\nRequire all granted\n</Directory>\n')
 proc=subprocess.Popen(['apache2','-f',str(conf),'-DFOREGROUND'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 try:
  for _ in range(30):
   if proc.poll() is not None: raise RuntimeError(proc.stderr.read().decode())
   try:
    with socket.create_connection(('127.0.0.1',port),.2):break
   except OSError:time.sleep(.1)
  for prefix in ['', '/sub']:
   for path,allowed in [('sadrzaj/databases/private.txt',False),('sadrzaj/private.log',False),('sadrzaj/tmp/restore-last-result.json',False),('sadrzaj/tmp/restore-transaction.json',False),('sadrzaj/tmp/restore-ledger-lookup-bucket-ab.json',False),('sadrzaj/tmp/restore-ledger-page-example.json',False),('sadrzaj/.tinymce-cloud.json',False),('brendy-content/.tinymce-cloud.json',False),('brendy-content/log.txt',False),('sadrzaj/uploads/a.php74.jpg',False),('sadrzaj/uploads/a.jpg',True),('jezgra/functions.php',False),('dodaci/demo/plugin.php',False),('teme/demo/index.php',False),('tests/run.php',False),('README.md',False),('.env',False),('index.php',True),('install.php',True),('unexpected.php',False),('unexpected.php.jpg',False),('extra/tool.phtml',False),('DEPENDENCIES.json',False),('SHA256SUMS.txt',False),('php.ini',False),('WEB.CONFIG',False),('jezgra/php.ini',False)]:
    c=http.client.HTTPConnection('127.0.0.1',port,timeout=3);c.request('GET',prefix+'/'+path);r=c.getresponse();r.read();c.close(); assert (r.status==200 if allowed else r.status in (403,404)), (prefix,path,r.status); print(f'[PASS] Apache GET {prefix}/{path}: {r.status}')
 finally:
  proc.terminate();proc.wait(timeout=5)
# PHP early router tests independently of installation.
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}',str(root/'index.php')],cwd=root,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
try:
 for _ in range(30):
  try:
   with socket.create_connection(('127.0.0.1',port),.2):break
  except OSError:time.sleep(.1)
 for path,expected in [('/broken%ZZ',400),('/bad%2fpath',400),('/%2e%2e/test',400),('/%ff',400),('/x%5cy',400),('/%C4%8Dlanak',302),('/search/?q=foo%2Fbar',302)]:
  c=http.client.HTTPConnection('127.0.0.1',port,timeout=3);c.request('GET',path);r=c.getresponse();r.read();c.close();assert r.status==expected,(path,r.status,expected);print(f'[PASS] PHP front controller GET {path}: {r.status}')
finally:
 proc.terminate();proc.wait(timeout=5)
