#!/usr/bin/env python3
"""Local icon font and native date input checks; no live CMS or network."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import base64, json, re, shutil, os, subprocess
ROOT=Path(__file__).resolve().parent.parent
base=ROOT/'jezgra/css/bootstrap-icons'
icons=json.loads((base/'bootstrap-icons.json').read_text())
css=(base/'bootstrap-icons.css').read_text()
for fmt in ['woff2','woff']:
    font=base/'fonts'/('bootstrap-icons.'+fmt)
    css=re.sub(r'\./fonts/bootstrap-icons\.'+fmt+r'\?[^"\s]+', 'data:font/'+fmt+';base64,'+base64.b64encode(font.read_bytes()).decode(),css)
css+=(base/'brendy-compat.css').read_text()
used=set()
for top in ['jezgra','teme','dodaci']:
    for file in (ROOT/top).rglob('*.php'):
        used.update(re.findall(r'\bbi-([a-z0-9]+(?:-[a-z0-9]+)*)',file.read_text()))
assert used <= icons.keys(), sorted(used-icons.keys())
print(f'[PASS] All {len(used)} literal PHP icon names exist')
script=lambda name:'<script>'+ (ROOT/name).read_text().replace('</script','<\\/script')+'</script>'
markup=subprocess.check_output(['php','-r', '''require 'tests/bootstrap.php'; require 'jezgra/pomocnici/date.class.php'; require 'jezgra/administracija/teme/brendy-admin/init.php'; echo BrendyAdminUi::formInputDateTime(['name'=>'date','label'=>'Datum','value'=>'2026-11-24 14:30:25']);'''],cwd=ROOT,text=True)
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path=os.environ.get('CHROMIUM',shutil.which('chromium') or ''),args=['--no-sandbox'])
    for width in (375,768,1440):
        page=browser.new_page(viewport={'width':width,'height':800}); errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
        sample=sorted(used|{'cloud-haze-1','123','alphabet-uppercase'})
        html='<meta name="viewport" content="width=device-width, initial-scale=1"><style>'+css+'</style>'+''.join(f'<i class="bi bi-{n}" data-icon="{n}"></i>' for n in sample)+'<form>'+markup+'</form>'
        page.set_content(html); page.evaluate('document.fonts.ready'); assert page.evaluate('document.fonts.check("16px bootstrap-icons")')
        for n,code in page.locator('[data-icon]').evaluate_all('(els)=>els.map(e=>[e.dataset.icon,getComputedStyle(e,"::before").content.codePointAt(1)])'):
            assert code==icons['cloud-haze' if n=='cloud-haze-1' else n], (n,code)
        print(f'[PASS] Icon font and legacy alias at {width}px')
        date=page.locator('#jsdate'); assert date.get_attribute('type')=='datetime-local'; assert date.input_value()=='2026-11-24T14:30:25'; date.fill('2026-11-25T17:31:42'); assert date.evaluate('(el)=>el.checkValidity()'); assert page.evaluate('new FormData(document.querySelector("form")).get("date")')=='2026-11-25T17:31:42'; date.fill(''); assert not date.evaluate('(el)=>el.checkValidity()'); date.fill('2026-11-25T17:31'); assert date.evaluate('(el)=>el.checkValidity()')
        assert not errors,errors;print(f'[PASS] Native date input preserves seconds, serializes, rejects blank at {width}px');page.close()
    browser.close()
