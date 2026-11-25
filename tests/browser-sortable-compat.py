#!/usr/bin/env python3
"""Exercise the public legacy-compatible sortable helper's first-party script."""
from pathlib import Path
import os, shutil
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
script=lambda p:'<script>'+(root/p).read_text().replace('</script','<\\/script')+'</script>'
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path=os.environ.get('CHROMIUM',shutil.which('chromium') or ''),args=['--no-sandbox'])
    page=browser.new_page()
    errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<style>li{height:45px}ul{min-height:45px}</style><ul id="a" class="connected"><li id="one"><span class="handle">One</span></li><li id="two"><span class="handle">Two</span></li></ul><ul id="b" class="connected"></ul>'+script('jezgra/js/jquery.min.js')+script('jezgra/js/jquery.sortable.min.js'))
    page.evaluate("""() => {
        window.events=[];
        $('.connected').on('sortupdate sortconnect',(e,d)=>events.push([e.type,e.currentTarget.id,d.item.attr('id')]));
        $('.connected').sortable({items:'li',handle:'.handle',connectWith:'.connected',placeholderClass:'test-placeholder',forcePlaceholderSize:true});
    }""")
    assert page.locator('#one').get_attribute('draggable')=='true'
    # Dispatch a complete HTML5 drag sequence with shared DataTransfer.
    page.evaluate("""() => {
        const item=document.querySelector('#one'),target=document.querySelector('#two'),dt=new DataTransfer();
        item.querySelector('.handle').dispatchEvent(new MouseEvent('mousedown',{bubbles:true}));
        item.dispatchEvent(new DragEvent('dragstart',{bubbles:true,cancelable:true,dataTransfer:dt}));
        target.dispatchEvent(new DragEvent('dragover',{bubbles:true,cancelable:true,dataTransfer:dt,clientY:target.getBoundingClientRect().bottom-1}));
        target.dispatchEvent(new DragEvent('drop',{bubbles:true,cancelable:true,dataTransfer:dt}));
    }""")
    assert page.locator('#a > li').evaluate_all('(nodes)=>nodes.map(n=>n.id)')==['two','one']
    assert page.evaluate('events')==[['sortupdate','a','one']]
    page.evaluate("""() => {
        const item=document.querySelector('#one'),target=document.querySelector('#b'),dt=new DataTransfer();
        item.querySelector('.handle').dispatchEvent(new MouseEvent('mousedown',{bubbles:true}));
        item.dispatchEvent(new DragEvent('dragstart',{bubbles:true,cancelable:true,dataTransfer:dt}));
        target.dispatchEvent(new DragEvent('dragover',{bubbles:true,cancelable:true,dataTransfer:dt}));
        target.dispatchEvent(new DragEvent('drop',{bubbles:true,cancelable:true,dataTransfer:dt}));
    }""")
    assert page.locator('#b > li').evaluate_all('(nodes)=>nodes.map(n=>n.id)')==['one']
    assert page.evaluate('events.slice(1)')==[['sortupdate','b','one'],['sortconnect','b','one']]
    page.evaluate("$('#b').sortable('disable')")
    assert page.locator('#one').get_attribute('draggable')=='false'
    page.evaluate("$('#b').sortable('enable')")
    assert page.locator('#one').get_attribute('draggable')=='true'
    page.evaluate("$('.connected').sortable('destroy')")
    assert page.locator('#one').get_attribute('draggable') is None
    assert page.locator('#two').get_attribute('draggable') is None
    assert page.locator('.sortable-placeholder,.sortable-dragging').count()==0 and not errors,errors
    print('[PASS] Legacy sortable API: reorder, connected lists, events, enable/disable, destroy')
    browser.close()
