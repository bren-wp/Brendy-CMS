#!/usr/bin/env python3
"""Capture actual product screens on an isolated, newly installed Brendy site.

Only the generated PNG files and README gallery are published. No site data,
credentials, sessions, server logs or installation configuration are committed.
"""
import os
import pathlib
import re
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
from playwright.sync_api import sync_playwright

root = pathlib.Path(__file__).resolve().parent.parent
out = root / 'branding/screenshots'
names = ('installer', 'login', 'dashboard', 'content', 'editor', 'themes', 'extensions', 'website', 'website-mobile')
with tempfile.TemporaryDirectory(prefix='brendy-capture-') as tmp:
    temp = pathlib.Path(tmp)
    temp.chmod(0o755)
    site = temp / 'www'
    site.mkdir(mode=0o755)
    archive = temp / 'source.tar'
    subprocess.run(['git','archive','--format=tar','-o',str(archive),'HEAD'],cwd=root,check=True)
    subprocess.run(['tar','-xf',str(archive),'-C',str(site)],check=True)
    subprocess.run(['sudo','chown','-R','www-data:www-data',str(site)],check=True)
    sessions = temp / 'sessions'
    sessions.mkdir(mode=0o700)
    subprocess.run(['sudo','chown','www-data:www-data',str(sessions)],check=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    conf = temp / 'httpd.conf'
    modules = ['mpm_prefork','authz_core','authz_host','access_compat','dir','mime','rewrite','headers','autoindex','negotiation','expires','filter','deflate','env']
    conf.write_text(
        f'ServerRoot "{temp}"\nListen 127.0.0.1:{port}\nServerName 127.0.0.1:{port}\nUseCanonicalName On\nPidFile "{temp}/httpd.pid"\nErrorLog "{temp}/error.log"\nLogLevel warn\nUser www-data\nGroup www-data\nTypesConfig /etc/mime.types\n'
        + ''.join(f'LoadModule {m}_module /usr/lib/apache2/modules/mod_{m}.so\n' for m in modules)
        + 'LoadModule php_module /usr/lib/apache2/modules/libphp8.3.so\n'
        + 'PHPIniDir /etc/php/8.3/apache2\n'
        + f'php_value session.save_path "{sessions}"\n'
        + f'DocumentRoot "{site}"\nDirectoryIndex index.php\n<Directory "{site}">\nAllowOverride All\nOptions FollowSymLinks\nRequire local\n</Directory>\n'
        + '<FilesMatch "\\.php$">\nSetHandler application/x-httpd-php\n</FilesMatch>\n',
        encoding='utf-8')
    proc = subprocess.Popen(['sudo','apache2','-f',str(conf),'-DFOREGROUND'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
    try:
        for _ in range(100):
            if proc.poll() is not None:
                raise RuntimeError('Isolated Apache exited during startup')
            try:
                with socket.create_connection(('127.0.0.1',port),.2): break
            except OSError: time.sleep(.1)
        else: raise RuntimeError('Isolated Apache did not start')
        password = 'Aa9!' + secrets.token_urlsafe(24)
        captured = temp / 'captured'
        captured.mkdir()
        with sync_playwright() as pw:
            browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM',shutil.which('google-chrome') or ''),args=['--no-sandbox'])
            context = browser.new_context(viewport={'width':1440,'height':1000},device_scale_factor=1,color_scheme='light',locale='hr-HR',timezone_id='Europe/Zagreb')
            # Browser must never contact an external service or customer site.
            context.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base+'/') or route.request.url.startswith(('data:','blob:')) else route.abort())
            page = context.new_page()
            page.set_default_timeout(15000)
            def open_page(url, selector):
                response = page.goto(base+url,wait_until='load',timeout=45000)
                if response is None or not response.ok:
                    raise RuntimeError(f'Page unavailable: {url}, {response.status if response else "no response"}')
                page.locator(selector).first.wait_for(state='visible')
            def shot(name):
                page.evaluate('document.fonts.ready')
                page.wait_for_timeout(250)
                page.screenshot(path=str(captured/(name+'.png')),animations='disabled')
                print('[CAPTURE] '+name,flush=True)
            open_page('/install.php','#jsformInstaller')
            shot('installer')
            # Exercise real installer security with the browser's session cookie.
            # The invalid requests never follow redirects or send data off-host.
            csrf = page.locator('input[name=install_csrf]').input_value()
            if not re.fullmatch(r'[a-f0-9]{64}',csrf):
                raise RuntimeError('Installer did not provide a valid CSRF token')
            if not any(c['name']=='BRENDY_INSTALL' for c in context.cookies(base)):
                raise RuntimeError('Installer session cookie is missing')
            fields={'install_csrf':csrf,'email':'admin@example.com','password':password,'timezone':'Europe/Zagreb'}
            for label,origin,fetch_site,token in (
                ('cross-origin','https://example.invalid','cross-site',csrf),
                ('opaque-origin','null','same-origin',csrf),
                ('invalid-token',base,'same-origin','0'*64),
            ):
                response=context.request.post(base+'/install.php',
                    form={**fields,'install_csrf':token},
                    headers={'Origin':origin,'Sec-Fetch-Site':fetch_site},
                    max_redirects=0,timeout=15000)
                if response.status!=403 or (site/'sadrzaj/databases/site.php').exists():
                    raise RuntimeError('Installer incorrectly accepted '+label)
                response.dispose()
                print('[PASS] Installer rejects '+label,flush=True)
            open_page('/install.php','#jsformInstaller')
            page.locator('#jsemail').fill('admin@example.com')
            page.locator('#jspassword').fill(password)
            print('[INSTALL] Submitting the real installer form',flush=True)
            with page.expect_response(lambda r: r.request.method=='POST' and r.url.split('?')[0]==base+'/install.php',timeout=30000) as submission:
                page.locator('#jsformInstaller button[type=submit]').click(timeout=15000)
            result=submission.value
            print('[INSTALL] HTTP status '+str(result.status),flush=True)
            headers=result.request.all_headers()
            origin=headers.get('origin','(absent)')
            print('[INSTALL] Origin='+('same-origin' if origin==base else 'null' if origin=='null' else 'absent' if origin=='(absent)' else 'unexpected')+'; Fetch-Site='+headers.get('sec-fetch-site','(absent)')+'; session-cookie-present='+str('BRENDY_INSTALL=' in headers.get('cookie','')),flush=True)
            if result.status not in (301,302,303):
                # Only print the visible validation message, never form data.
                alert=page.locator('.alert-danger,.error-table').first
                reason=alert.inner_text(timeout=3000) if alert.count() and alert.is_visible() else 'no visible installer validation message'
                raise RuntimeError('Installation rejected: '+reason.replace(password,'[redacted]')[:500])
            if not (site/'sadrzaj/databases/site.php').is_file():
                raise RuntimeError('Installer did not publish site database')
            page.wait_for_url(base+'/',timeout=15000)
            print('[PASS] Fresh flat-file installation completed',flush=True)
            open_page('/','body')
            shot('website')
            page.set_viewport_size({'width':390,'height':844})
            open_page('/','body')
            shot('website-mobile')
            page.set_viewport_size({'width':1440,'height':1000})
            open_page('/admin/login','#jsusername')
            shot('login')
            page.locator('#jsusername').fill('admin')
            page.locator('#jspassword').fill(password)
            print('[LOGIN] Signing in to the isolated demo',flush=True)
            with page.expect_response(lambda r:r.request.method=='POST' and r.url.startswith(base+'/admin/'),timeout=20000) as login:
                page.locator('button[name=save]').click(timeout=15000)
            print('[LOGIN] HTTP status '+str(login.value.status),flush=True)
            page.locator('#dashboard').wait_for(state='visible',timeout=20000)
            shot('dashboard')
            for route,name in [('content','content'),('themes','themes'),('plugins','extensions')]:
                open_page('/admin/'+route,'.brendy-admin-main')
                if page.locator('#jsusername').is_visible() or page.url.split('?')[0].rstrip('/') != base+'/admin/'+route:
                    raise RuntimeError('Unexpected admin route: '+route)
                shot(name)
            open_page('/admin/new-content','#jstitle')
            page.locator('#jstitle').fill('Vaša priča počinje ovdje')
            page.wait_for_function("window.tinymce && tinymce.get('jseditor') && tinymce.get('jseditor').initialized",timeout=30000)
            page.evaluate("""tinymce.get('jseditor').setContent('<h2>Dobro došli</h2><p>Svaka dobra web stranica započinje idejom. Kreirajte sadržaj, podijelite svoju priču i oblikujte web prema vlastitim željama.</p><p>Brendy CMS — Vaša web stranica. Vaš sadržaj.</p>')""")
            shot('editor')
            browser.close()
        for name in names:
            f=captured/(name+'.png')
            if not f.is_file() or f.stat().st_size<10000 or f.read_bytes()[:8]!=b'\x89PNG\r\n\x1a\n':
                raise RuntimeError('Missing or invalid screenshot: '+name)
        out.mkdir(parents=True,exist_ok=True)
        for name in names: shutil.copy2(captured/(name+'.png'),out/(name+'.png'))
        gallery = """## See Brendy in action

Actual product screens from a fresh Brendy CMS installation.

<div align="center">
  <img src="branding/screenshots/dashboard.png" alt="Brendy CMS administration dashboard" width="100%">
</div>

| Content management | Visual editor |
| :---: | :---: |
| <img src="branding/screenshots/content.png" alt="Content management" width="100%"> | <img src="branding/screenshots/editor.png" alt="Visual content editor" width="100%"> |

| Themes | Included extensions |
| :---: | :---: |
| <img src="branding/screenshots/themes.png" alt="Theme selection" width="100%"> | <img src="branding/screenshots/extensions.png" alt="Extensions library" width="100%"> |

| Website | Mobile website |
| :---: | :---: |
| <img src="branding/screenshots/website.png" alt="Public website" width="100%"> | <img src="branding/screenshots/website-mobile.png" alt="Responsive mobile website" width="230"> |

| Installation | Sign in |
| :---: | :---: |
| <img src="branding/screenshots/installer.png" alt="Brendy installation" width="100%"> | <img src="branding/screenshots/login.png" alt="Brendy sign in" width="100%"> |

"""
        readme=root/'README.md'
        text=readme.read_text(encoding='utf-8')
        marker='<!-- PRODUCT_GALLERY -->'
        if text.count(marker)!=1: raise RuntimeError('README gallery marker missing or ambiguous')
        readme.write_text(text.replace(marker,gallery),encoding='utf-8')
        print('[PASS] All nine actual screens captured; README gallery prepared',flush=True)
    except Exception as exc:
        # Local server log diagnostics only; never publish the demo files or
        # request bodies. Redact generated credentials and random hex tokens.
        log=temp/'error.log'
        if log.is_file():
            lines=subprocess.run(['sudo','tail','-n','35',str(log)],capture_output=True,text=True,check=False).stdout
            lines=lines.replace(locals().get('password','__missing_password__'),'[redacted]')
            lines=re.sub(r'\b[0-9a-fA-F]{24,}\b','[redacted]',lines)
            print('[APACHE] '+lines,flush=True)
        raise
    finally:
        # Apache stops its own process group; it must never include the runner.
        if proc.poll() is None:
            subprocess.run(['sudo','apache2','-f',str(conf),'-k','stop'],check=False,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=15,start_new_session=True)
            try: proc.wait(timeout=15)
            except subprocess.TimeoutExpired:
                proc.terminate()
                proc.wait(timeout=10)
        # Return ownership only within the isolated temporary directory for cleanup.
        subprocess.run(['sudo','chown','-R',f'{os.getuid()}:{os.getgid()}',str(temp)],check=False)
