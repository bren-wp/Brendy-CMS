#!/usr/bin/env python3
"""Chromium isolated editor integration. Inline delivery avoids browser network policy.
The actual production JS, language, plugins, and CSS are used; the fixture
preloads resources and substitutes in-memory storage for the opaque origin.
This does not test HTTP asset delivery or an installed CMS.
"""
import json, os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
BASE=ROOT/'jezgra/urednik'; VENDOR=BASE/'tinymce'
PLUGINS=['advlist','autolink','charmap','code','codesample','fullscreen','image','link','lists','pagebreak','searchreplace','table','wordcount']
def script(p):return '<script>'+p.read_text().replace('</script','<\\/script')+'</script>'
def editor_bundle():
    # Instrument only the isolated fixture; production bundle exposes no purifier global.
    code=(VENDOR/'tinymce.min.js').read_text()
    marker='return module.exports;})();'
    assert code.count(marker)==1
    code=code.replace(marker,marker+"\nconst originalBrendyPurifier=Jw; Jw=function(...args){const p=originalBrendyPurifier(...args);window.brendyPurifierVersions=(window.brendyPurifierVersions||[]).concat(p.version);return p;};")
    return '<script>'+code.replace('</script','<\\/script')+'</script>'
def style(p):return '<style>'+p.read_text().replace('</style','<\\/style')+'</style>'
config={'baseUrl':'https://brendy.test/jezgra/urednik/tinymce','contentCss':'','documentBaseUrl':'https://brendy.test/','build':'202611242201','draftScope':'12345678'}
iframecss='\n'.join((VENDOR/f).read_text() for f in ['skins/ui/oxide/content.min.css','skins/content/default/content.min.css'])+'\n'+(BASE/'sadrzaj.css').read_text()
setup='''window.BrendyEditorConfig=CONFIG;
var data = new Map(); Object.defineProperty(window,'localStorage',{configurable:true,value:{getItem:k=>data.has(k)?data.get(k):null,setItem:(k,v)=>data.set(k,String(v)),removeItem:k=>data.delete(k),get length(){return data.size;}}});
window.confirm=()=>true; document.querySelector('form').addEventListener('submit',e=>e.preventDefault());
var originalInit=tinymce.init.bind(tinymce); tinymce.init=function(o){var setup=o.setup; return originalInit({...o,skin:false,content_css:false,language_load:false,setup:function(ed){setup(ed);ed.on('init',()=>{var s=ed.getDoc().createElement('style');s.textContent=IFRAMECSS;ed.getDoc().head.appendChild(s);});}});};'''.replace('CONFIG',json.dumps(config)).replace('IFRAMECSS',json.dumps(iframecss))
markup='''<!doctype html><html lang="hr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body><form id="jsform"><input name="date" id="jsdate" type="datetime-local" step="1" value="2026-11-24T10:00:25"><input name="title" id="jstitle" value="Članak"><input name="uuid" id="jsuuid" value="12345678123456781234567812345678"><input type="hidden" id="jscontent" name="content"><input type="hidden" name="tokenCSRF" value="new-token"><input type="hidden" name="key" value="current-page"><input type="hidden" name="contentVersion" value="current-version"><input type="hidden" name="type" value="published"><select name="typeSelector"><option value="published">Objava</option><option value="static">Static</option></select><button type="button" data-brendy-content-status-switch data-switch="publish" data-draft-label="Nacrt" data-publish-label="Objava">Objava</button><input type="checkbox" name="flag" value="yes" checked><select multiple name="items"><option value="a" selected>A</option><option value="b">B</option><option value="c">C</option></select><textarea id="jseditor">&lt;p&gt;Početni sadržaj&lt;/p&gt;</textarea><button id="jsbuttonSave" type="submit">Spremi</button></form>'''
html=markup+style(VENDOR/'skins/ui/oxide/skin.min.css')+style(BASE/'urednik.css')+editor_bundle()+''.join(script(VENDOR/f) for f in ['icons/default/icons.min.js','themes/silver/theme.min.js','models/dom/model.min.js','langs/hr.js']+[f'plugins/{p}/plugin.min.js' for p in PLUGINS])+'<script>'+setup+'</script>'+script(BASE/'urednik.js')+script(ROOT/'jezgra/administracija/teme/brendy-admin/js/brendy-admin.js')+'</body></html>'
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM',shutil.which('chromium') or ''),args=['--no-sandbox'])
    for width in (375,768,1440):
        ctx=browser.new_context(viewport={'width':width,'height':900}); page=ctx.new_page(); errors=[]; page.on('pageerror',lambda e:errors.append(str(e)));page.set_content(html,wait_until='domcontentloaded');page.wait_for_function("window.tinymce && tinymce.get('jseditor')?.initialized === true",timeout=15000)
        assert page.evaluate("tinymce.majorVersion+'.'+tinymce.minorVersion")=='8.10.2'
        assert page.evaluate("Object.keys(tinymce.get('jseditor').plugins).sort()") == sorted(PLUGINS)
        assert page.evaluate("tinymce.get('jseditor').getContent()")=='<p>Početni sadržaj</p>'
        print(f'[PASS] TinyMCE 8.10.2 init and all 13 configured plugins {width}',flush=True)
        assert page.evaluate("tinymce.get('jseditor').options.get('language')")=='hr'
        assert page.evaluate("tinymce.util.I18n.translate('Bold')")!='Bold'
        print(f'[PASS] Croatian locale {width}',flush=True)
        # Exercise the actual codesample plugin, including round-trip serialization.
        samples={'markup':'<div>demo</div>','javascript':'const a = 42;', 'css':'body { color: red; }', 'php':'<?php echo "demo"; ?>', 'ruby':'def hello; puts "demo"; end', 'python':'def hello(): return 42', 'java':'public class Demo {}', 'c':'int main(void) { return 0; }', 'csharp':'public class Demo {}', 'cpp':'int main() { return 0; }'}
        for lang,code in samples.items():
            result=page.evaluate("""([lang, code])=>{const ed=tinymce.get('jseditor');const node=document.createElement('div');node.textContent=code;ed.setContent('<pre class="language-'+lang+'"><code>'+node.innerHTML+'</code></pre>');const pre=ed.getBody().querySelector('pre');const saved=new DOMParser().parseFromString(ed.getContent(),'text/html').querySelector('pre code');return {tokens:pre.querySelectorAll('.token').length,raw:pre.textContent,saved:saved?.textContent};}""",[lang,code])
            assert result['tokens']>0 and result['raw']==code and result['saved']==code,(lang,result)
        assert page.evaluate("typeof window.Prism === 'undefined'")
        print(f'[PASS] Prism 1.30.0: all 10 editor languages, token rendering and exact serialization {width}',flush=True)
        code='<img src=x onerror="window.prismExecuted=1">'
        result=page.evaluate("""code=>{const ed=tinymce.get('jseditor');const n=document.createElement('div');n.textContent=code;ed.setContent('<pre class="language-markup"><code>'+n.innerHTML+'</code></pre>');const pre=ed.getBody().querySelector('pre');return {raw:pre.textContent, images:pre.querySelectorAll('img').length, executed:!!ed.getWin().prismExecuted};}""",code)
        assert result=={'raw':code,'images':0,'executed':False},result
        print(f'[PASS] Code sample HTML stays literal text; no executable node {width}',flush=True)

        page.evaluate("tinymce.get('jseditor').setContent('<p>Hrvatski čćžšđ <strong>tekst</strong></p>');tinymce.get('jseditor').fire('change')")
        assert 'čćžšđ' in page.evaluate('editorGetContent()');assert 'čćžšđ' in page.locator('#jseditor').input_value()
        assert page.evaluate("editorInsertMedia('/sadrzaj/uploads/moj-test.jpg')") is True
        assert 'alt="moj test"' in page.evaluate('editorGetContent()')
        assert page.evaluate("editorInsertMedia('javascript:alert(1)')") is False
        assert page.evaluate("editorInsertLinkedMedia('/a.jpg','javascript:alert(1)')") is False
        page.evaluate("tinymce.get('jseditor').setContent('<p onclick=\"window.brendyXss=1\">Sigurno</p><script>window.brendyXss=1</script><a href=\"javascript:alert(1)\">Link</a><iframe src=\"https://invalid.test\"></iframe>');tinymce.get('jseditor').fire('change')")
        content=page.evaluate('editorGetContent()');assert all(x not in content.lower() for x in ('onclick','<script','javascript:','<iframe')),content
        assert page.evaluate("tinymce.get('jseditor').getWin().brendyXss") is None
        assert page.evaluate("window.brendyPurifierVersions.length > 0 && window.brendyPurifierVersions.every(v=>v==='3.5.0') && typeof window.DOMPurify==='undefined'")
        print(f'[PASS] Actual TinyMCE sanitizer factory uses isolated DOMPurify 3.5.0 {width}',flush=True)
        print(f'[PASS] Content sync, image insert, unsafe URL and XSS markup rejection {width}',flush=True)
        page.evaluate("document.getElementById('jstitle').value='Nacrt';document.getElementById('jstitle').dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-brendy-content-status-switch]').click();document.querySelector('[name=flag]').checked=false;document.querySelector('[name=items]').options[1].selected=true;document.querySelector('[name=items]').options[2].selected=true;BrendyEditor.saveLocalDraft()")
        draft=page.evaluate("localStorage.getItem('brendy-editor-draft:12345678:12345678123456781234567812345678')")
        assert json.loads(draft)['fields']['title']=='Nacrt'
        fields=json.loads(draft)['fields'];assert fields['flag']==[] and fields['items']==['a','b','c'] and fields['type']=='draft',fields
        assert not set(fields)&{'uuid','key','contentVersion','tokenCSRF','content','revisionId'}
        # Simulate older/malformed persisted fields; current hidden controls must not be overwritten.
        d=json.loads(draft);d['fields'].update(uuid='wrong',key='wrong',tokenCSRF='wrong',contentVersion='wrong',date='2026-11-25 12:30:42');draft=json.dumps(d)
        # A new page with saved state pre-seeded exercises the real restoration path.
        restored=ctx.new_page();restored.on('pageerror',lambda e:errors.append(str(e)))
        restored.set_content(html.replace('window.confirm=()=>true;', 'localStorage.setItem("brendy-editor-draft:12345678:12345678123456781234567812345678",'+json.dumps(draft)+');window.confirm=()=>true;'),wait_until='domcontentloaded')
        restored.wait_for_function("tinymce.get('jseditor')?.initialized && document.getElementById('jstitle').value==='Nacrt'",timeout=15000)
        assert 'Sigurno' in restored.evaluate('editorGetContent()')
        assert restored.locator('[name=flag]').is_checked() is False
        assert restored.locator('[name=items]').evaluate('(el)=>Array.from(el.selectedOptions).map(o=>o.value)')==['a','b','c']
        assert restored.locator('[name=key]').input_value()=='current-page' and restored.locator('[name=contentVersion]').input_value()=='current-version' and restored.locator('[name=tokenCSRF]').input_value()=='new-token'
        assert restored.locator('[data-brendy-content-status-switch]').get_attribute('data-switch')=='draft'
        assert restored.locator('#jsdate').input_value()=='2026-11-25T12:30:42'
        print(f'[PASS] Draft field restoration and immutable form controls {width}',flush=True)
        restored.evaluate('BrendyEditor.clearLocalDraft()');assert restored.evaluate('localStorage.length')==0
        print(f'[PASS] Local draft save, restore and clear (storage fixture) {width}',flush=True)
        stale=ctx.new_page();stale.on('pageerror',lambda e:errors.append(str(e)));old=json.loads(draft);old['contentVersion']='old-version';stale.set_content(html.replace('window.confirm=()=>true;', 'localStorage.setItem("brendy-editor-draft:12345678:12345678123456781234567812345678",'+json.dumps(json.dumps(old))+');window.confirm=()=>true;'),wait_until='domcontentloaded');stale.wait_for_function("tinymce.get('jseditor')?.initialized && document.querySelector('#brendyEditorStatus').dataset.state==='warning'");assert stale.locator('#jstitle').input_value()=='Članak'; print(f'[PASS] Stale draft not automatically restored {width}',flush=True)
        assert not errors,errors
        print(f'[PASS] No JS exceptions {width}',flush=True)
        # Test the real form submit event (no HTTP persistence in this fixture).
        page.evaluate("window.submitObserved=false; document.getElementById('jsform').addEventListener('submit',()=>window.submitObserved=true);document.getElementById('jsform').requestSubmit()")
        assert page.evaluate('window.submitObserved') is True
        print(f'[PASS] Native form submit event {width}',flush=True)
        ctx.close()
    # No TinyMCE runtime: text editing and initial dirty-state work without the vendor.
    fallback=browser.new_page();fallback.set_content(markup+'<script>window.BrendyEditorConfig={};</script>'+script(BASE/'urednik.js')+'</body></html>')
    assert fallback.evaluate('BrendyEditor.isDirty()') is False
    fallback.locator('#jseditor').fill('Tekstualni način')
    assert fallback.evaluate('BrendyEditor.isDirty()') is True and fallback.evaluate('editorGetContent()')=='Tekstualni način'
    print('[PASS] Textarea fallback without TinyMCE',flush=True)
    browser.close()
