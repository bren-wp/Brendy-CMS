#!/usr/bin/env python3
"""Read-only smoke check for an authorized, already installed CMS staging site.
Does not authenticate, mutate state, or substitute for an end-to-end suite.
"""
import argparse, re, sys, urllib.request, urllib.error, urllib.parse, xml.etree.ElementTree as ET
from html.parser import HTMLParser

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class Metadata(HTMLParser):
    def __init__(self):
        super().__init__(); self.canonical=[]; self.robots=[]; self.titles=0
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs)
        if tag=='title': self.titles+=1
        if tag=='link' and 'canonical' in attrs.get('rel','').lower().split(): self.canonical.append(attrs.get('href',''))
        if tag=='meta' and attrs.get('name','').lower()=='robots': self.robots.append(attrs.get('content',''))

p=argparse.ArgumentParser(description=__doc__);p.add_argument('base',help='Authorized staging CMS base URL, including subdirectory');p.add_argument('--limit',type=int,default=500);args=p.parse_args()
base=args.base.rstrip('/')+'/';origin=urllib.parse.urlsplit(base)
if origin.scheme not in ('https','http') or not origin.hostname or origin.username or origin.password or origin.query or origin.fragment or not 1<=args.limit<=10000:p.error('Invalid base or limit')
opener=urllib.request.build_opener(NoRedirect())
failures=[];checked=0

def fetch(url):
    parts=urllib.parse.urlsplit(url)
    if (parts.scheme,parts.netloc)!=(origin.scheme,origin.netloc) or not parts.path.startswith(origin.path) or parts.fragment:raise ValueError('Outside configured staging origin/path')
    try: response=opener.open(urllib.request.Request(url,headers={'User-Agent':'BrendyStagingSmoke/1.0'}),timeout=10)
    except urllib.error.HTTPError as e: response=e
    with response:
        data=response.read(8*1024*1024+1)
        if len(data)>8*1024*1024:raise ValueError('Response exceeds cap')
        return response.status,response.headers,data

def verify_page(url):
    global checked
    checked+=1
    try:
        status,headers,data=fetch(url)
        if status!=200:raise ValueError(f'HTTP {status}')
        if 'text/html' not in headers.get('Content-Type','').lower():raise ValueError('Not HTML')
        parser=Metadata();parser.feed(data.decode('utf-8',errors='replace'))
        if parser.titles!=1 or len(parser.canonical)!=1:raise ValueError('Expected one title and canonical')
        if parser.canonical[0]!=url:raise ValueError('Canonical differs from sitemap URL')
        if re.search(r'\bnoindex\b',','.join(parser.robots+[headers.get('X-Robots-Tag','')]),re.I):raise ValueError('Sitemap page marked noindex')
        print('[PASS]',url)
    except (OSError,ValueError) as e:failures.append((url,str(e)));print('[FAIL]',url,str(e))
try:
    status,headers,data=fetch(urllib.parse.urljoin(base,'sitemap.xml'))
    if status!=200:raise ValueError(f'Sitemap HTTP {status}')
    root=ET.fromstring(data);ns='{http://www.sitemaps.org/schemas/sitemap/0.9}'
    if root.tag!=ns+'urlset':raise ValueError('Expected sitemap urlset; sitemap index is not supported by this smoke runner')
    urls=[e.text for e in root.findall(ns+'url/'+ns+'loc') if e.text]
    if not urls or len(urls)!=len(set(urls)):raise ValueError('Empty or duplicate sitemap URLs')
    if len(urls)>args.limit:raise ValueError(f'Sitemap has {len(urls)} URLs; explicitly raise --limit to cover all')
    for url in urls:verify_page(url)
except (OSError,ValueError,ET.ParseError) as e:failures.append(('sitemap',str(e)));print('[FAIL]',str(e))
print(f'Public pages checked: {checked}; failures: {len(failures)}. Authentication, admin mutations, all theme configurations, and penetration tests not covered.')
sys.exit(1 if failures or not checked else 0)
