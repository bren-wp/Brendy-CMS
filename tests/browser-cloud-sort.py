#!/usr/bin/env python3
"""Isolated browser fixture: cloud script failure and real plugin ordering JS.
No live API key or cloud request is used by this test.
"""
from pathlib import Path
import os,re,shutil
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
read=lambda p:(ROOT/p).read_text()
script=lambda p:'<script>'+read(p).replace('</script','<\\/script')+'</script>'
source=read('jezgra/administracija/pogledi/plugins-position.php')
match=re.search(r'<script[^\n]*>\s*(\$\(function\(\)\s*\{.*?</script>)',source,re.S)
assert match, 'Plugin ordering script not found'
code=match.group(1).removesuffix('</script>')
assert 'list-group-sortable' in code
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path=os.environ.get('CHROMIUM',shutil.which('chromium') or ''),args=['--no-sandbox'])
    for width in (375,768,1440):
        page=browser.new_page(viewport={'width':width,'height':800});page.set_default_timeout(4000);errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        markup='<form id="jsform"><input id="jsplugin-list" type="hidden"><ul class="list-group-sortable">'+''.join(f'<li style="height:50px" data-plugin="{i}"><i class="bi-grip-vertical">↕</i>{i}<button type="button" class="jsplugin-up">Up</button><button type="button" class="jsplugin-down">Down</button></li>' for i in ['a','b','c'])+'</ul><button class="jsbuttonSave" type="button">Save</button></form>'
        page.set_content(markup+script('jezgra/js/jquery.min.js')+script('jezgra/js/jquery-ui.min.js')+'<script>document.querySelector("form").addEventListener("submit",e=>e.preventDefault());'+code+'</script>')
        page.wait_for_function("$('.list-group-sortable').hasClass('ui-sortable')",timeout=5000)
        assert page.evaluate('$.ui.version')=='1.14.2'
        page.locator('[data-plugin=b] .jsplugin-up').click();assert page.locator('li').evaluate_all('(els)=>els.map(x=>x.dataset.plugin)')==['b','a','c']
        page.locator('[data-plugin=b] .jsplugin-down').click();assert page.locator('li').evaluate_all('(els)=>els.map(x=>x.dataset.plugin)')==['a','b','c']
        # Drag C above A using the actual jQuery UI mouse interaction.
        start=page.locator('[data-plugin=c] .bi-grip-vertical').bounding_box();end=page.locator('[data-plugin=a]').bounding_box()
        page.mouse.move(start['x']+start['width']/2,start['y']+start['height']/2);page.mouse.down();page.mouse.move(end['x']+20,end['y']+5,steps=12);page.mouse.up()
        assert page.locator('li').evaluate_all('(els)=>els.map(x=>x.dataset.plugin)')==['c','a','b']
        page.locator('.jsbuttonSave').click();assert page.locator('#jsplugin-list').input_value()=='c,a,b'
        assert not errors,errors;print(f'[PASS] Real jQuery UI sorting, accessible controls and submit order {width}')
        page.close()
    # Simulate cloud network failure, then verify local script load and initialize.
    for reject_init in (False,True):
        page=browser.new_page(); page.set_default_timeout(4000); errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        fixture='''<textarea id="jseditor">Content</textarea><script>
        window.BrendyEditorConfig={cloudScript:'https://cdn.example.test/cloud.js',localScript:'https://local.example.test/editor.js',baseUrl:'https://local.example.test/tinymce'};
        window.loads=[];const append=document.head.appendChild.bind(document.head);
        document.head.appendChild=function(s){if(s.tagName!=='SCRIPT')return append(s);window.loads.push(s.src);
          if(s.src.includes('cloud.js')){if(REJECT){window.tinymce={get:()=>null,init:()=>Promise.reject(new Error('fixture'))};queueMicrotask(()=>s.onload());}else queueMicrotask(()=>s.onerror());}
          else{window.tinymce={get:()=>null,init:o=>{window.localOptions=o;return Promise.resolve([{}]);}};queueMicrotask(()=>s.onload());}return s;};
        </script>'''.replace('REJECT','true' if reject_init else 'false')
        page.set_content(fixture+script('jezgra/urednik/urednik.js'))
        page.wait_for_function('window.localOptions && window.loads.length===2',timeout=5000)
        assert page.evaluate('window.localOptions.license_key')=='gpl' and page.locator('#brendyLocalEditorLink').count()==1
        assert page.locator('#jseditor').input_value()=='Content' and not errors,errors
        print('[PASS] Cloud '+('initialization' if reject_init else 'network')+' failure falls back to local editor (simulated)');page.close()
    browser.close()
