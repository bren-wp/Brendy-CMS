#!/usr/bin/env python3
"""Local Chromium component tests; not a full installed CMS E2E suite."""
from pathlib import Path
import subprocess, tempfile, threading, functools, http.server, shutil, os, re
from playwright.sync_api import sync_playwright, expect
ROOT=Path(__file__).resolve().parent.parent
class Quiet(http.server.SimpleHTTPRequestHandler):
    def log_message(self,*args): pass
# PHP helper output is actually rendered, not hand-copied.
helper=subprocess.check_output(['php','-r', '''require 'tests/bootstrap.php'; require 'jezgra/administracija/teme/brendy-admin/init.php'; echo BrendyAdminUi::modal(['modalId'=>'demoModal','modalTitle'=>'Test modal','buttonSecondary'=>'Cancel','buttonPrimary'=>'OK']); echo BrendyAdminUi::formInputFile(['id'=>'testFile','label'=>'Upload','name'=>'file']);'''],cwd=ROOT,text=True)
with tempfile.TemporaryDirectory() as tmp:
    tmp=Path(tmp); (tmp/'jezgra').symlink_to(ROOT/'jezgra',target_is_directory=True); (tmp/'teme').symlink_to(ROOT/'teme',target_is_directory=True)
    css=['jezgra/css/bootstrap.min.css','jezgra/css/brendy-bootstrap-compat.css','jezgra/css/select2.min.css','jezgra/css/select2-brendy5.min.css','jezgra/administracija/teme/brendy-admin/css/brendy.bootstrap.css','jezgra/administracija/teme/brendy-admin/css/brendy.css']
    js=['jezgra/js/jquery.min.js','jezgra/js/bootstrap.bundle.min.js','jezgra/js/select2.full.min.js']
    markup='''<button id="theme" type="button" data-brendy-admin-theme-toggle>Theme</button>
    <div class="nav nav-tabs" role="tablist"><button id="t1" class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane1">First</button><button id="t2" class="nav-link" data-bs-toggle="tab" data-bs-target="#pane2">Second</button></div><div class="tab-content"><div id="pane1" class="tab-pane active">One</div><div id="pane2" class="tab-pane">Two</div></div>
    <button id="open" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#demoModal">Open</button>
    <div class="dropdown"><button id="drop" data-bs-toggle="dropdown" class="btn btn-light dropdown-toggle">Actions</button><div class="dropdown-menu"><button class="dropdown-item">Item</button></div></div>
    <button id="collapse" data-bs-toggle="collapse" data-bs-target="#panel">Collapse</button><div id="panel" class="collapse">Panel</div>
    <select id="select" style="width:100%"><option value="a">Alpha</option><option value="b">Beta</option></select><select id="multi" multiple style="width:100%"><option value="a">Alpha</option><option value="b">Beta</option></select>'''
    (tmp/'index.html').write_text('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">'+''.join(f'<link rel="stylesheet" href="/{c}">' for c in css)+'</head><body class="brendy-admin"><main class="container p-3">'+markup+helper+'</main>'+''.join(f'<script src="/{j}"></script>' for j in js)+'<script>$("#select,#multi").select2({theme:"brendy5"})</script><script src="/jezgra/administracija/teme/brendy-admin/js/brendy-admin.js"></script></body></html>')
    # Shared actual frontend script tested with theme-specific CSS and navigation target contracts.
    themes={'alternativa':['css/stil.css'],'blog':['css/stil.css'],'fokus':['css/01-stil.css','css/02-pomocnici.css'],'novosti':['css/stil.css'],'okus':['css/osnova.css','css/stil.css']}
    for name,styles in themes.items():
        common=[] if name=='okus' else ['jezgra/css/bootstrap.min.css','jezgra/css/brendy-bootstrap-compat.css']
        common+=['jezgra/css/brendy-theme.css']+[f'teme/{name}/{s}' for s in styles]
        (tmp/f'{name}.html').write_text('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">'+''.join(f'<link rel="stylesheet" href="/{c}">' for c in common)+'</head><body><button id="nav" data-brendy-collapse-target="#navigation" aria-expanded="false">Menu</button><div id="navigation" class="collapse">Navigation</div><script src="/jezgra/js/brendy-theme.js"></script></body></html>')
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),functools.partial(Quiet,directory=str(tmp))); threading.Thread(target=server.serve_forever,daemon=True).start(); base=f'http://127.0.0.1:{server.server_port}'
    def load(page,path):
        # Inline local assets in an isolated document; no network is required.
        html=path.read_text()
        html=re.sub(r'<link rel="stylesheet" href="/([^"<>]+)">',lambda m:'<style>'+ (ROOT/m[1]).read_text().replace('</style','<\\/style')+'</style>',html)
        html=re.sub(r'<script src="/([^"<>]+)"></script>',lambda m:'<script>'+ (ROOT/m[1]).read_text().replace('</script','<\\/script')+'</script>',html)
        page.set_content(html,wait_until='domcontentloaded')
    try:
        with sync_playwright() as p:
            browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM',shutil.which('chromium') or ''),args=['--no-sandbox']);
            for width in [375,768,1440]:
                page=browser.new_page(viewport={'width':width,'height':900}); errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
                load(page,tmp/'index.html'); assert page.evaluate('bootstrap.Modal.VERSION')=='5.3.9'; assert page.evaluate('jQuery.fn.jquery')=='4.1.0'
                page.locator('#t2').click(); expect(page.locator('#pane2')).to_be_visible(); print(f'[PASS] Tabs {width}')
                page.locator('#open').click(); expect(page.locator('#demoModal')).to_be_visible(); page.locator('#demoModal [data-bs-dismiss]').click(); expect(page.locator('#demoModal')).to_be_hidden(); print(f'[PASS] Modal {width}')
                page.locator('#drop').click(); expect(page.locator('.dropdown-menu')).to_be_visible(); page.locator('#drop').click(); print(f'[PASS] Dropdown {width}')
                page.locator('#collapse').click(); expect(page.locator('#panel')).to_have_class('collapse show'); page.locator('#collapse').click(); expect(page.locator('#panel')).to_be_hidden(); print(f'[PASS] Collapse {width}')
                page.locator('#select + .select2 .select2-selection').click(); page.get_by_role('option',name='Beta').click(); assert page.locator('#select').input_value()=='b'; print(f'[PASS] Select2 {width}')
                page.locator('#testFile').set_input_files({'name':'sample.txt','mimeType':'text/plain','buffer':b'test'}); assert page.locator('#testFile').evaluate('(el)=>el.files[0].name')=='sample.txt'; print(f'[PASS] File input {width}')
                old=page.locator('html').get_attribute('data-bs-theme'); page.locator('#theme').click(); assert page.locator('html').get_attribute('data-bs-theme')!=old; assert not errors,errors; print(f'[PASS] Theme toggle and no JS errors {width}')
                page.close()
            for name in themes:
                page=browser.new_page(viewport={'width':375,'height':800}); errors=[]; page.on('pageerror',lambda e:errors.append(str(e))); load(page,tmp/f'{name}.html'); page.locator('#nav').click(); expect(page.locator('#nav')).to_have_attribute('aria-expanded','true'); page.locator('#nav').click(); expect(page.locator('#nav')).to_have_attribute('aria-expanded','false'); assert not errors,errors; print(f'[PASS] Shared mobile navigation fixture {name}'); page.close()
            browser.close()
    finally: server.shutdown()
