// Read-only browser checks. Set BROWSER_EXECUTABLE if Chromium is externally provided.
import {chromium} from '@playwright/test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const base=(process.env.TEST_BASE_URL||'http://127.0.0.1:8080').replace(/\/$/,'');
const browser=await chromium.launch({executablePath:process.env.BROWSER_EXECUTABLE||undefined,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label); checks++;};
fs.mkdirSync('test-results',{recursive:true});
try {
 const context=await browser.newContext();
 const page=await context.newPage(); const errors=[]; const bad=[];
 page.on('pageerror',e=>errors.push(String(e)));
 page.on('response',r=>{if(r.status()>=400 && !new URL(r.url()).pathname.startsWith('/uploads/')) bad.push(`${r.status()} ${r.url()}`);});
 for(const path of ['/','/news','/articles']) {
  for(const width of [360,768,1440]) {
   await page.setViewportSize({width,height:950});
   const response=await page.goto(base+path,{waitUntil:'networkidle'});
   check(response.status()===200,`HTTP ${path} ${width}`);
   check(await page.locator('html').getAttribute('dir')==='rtl','RTL');
   check(await page.locator('main').count()===1,'one main landmark');
   check(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),`no overflow ${path} ${width}`);
   check(await page.locator('.jhd-card-gallery').count()===0,'no card mosaic');
   const images=await page.locator('.jhd-card').evaluateAll(cards=>cards.map(c=>c.querySelectorAll('img').length));
   check(images.every(n=>n<=1),'one image per card');
   if(path==='/') {
    check(await page.locator('.jhd-about').count()===0,'no introduction');
    for(const key of ['popular','latest']) check(await page.locator(`[data-home-section="${key}"] .jhd-card`).count()<=3,'max three '+key);
    if(width===1440) {
     for(const label of ['اخبار','مقالات']) check(await page.locator('.jhd-navbar-desktop').getByRole('link',{name:label,exact:true}).count()===1,'unique menu '+label);
    }
   }
   await page.screenshot({path:`test-results/editorial-${path==='/'?'home':path.slice(1)}-${width}.png`,fullPage:true});
  }
 }
 await page.goto(base+'/news',{waitUntil:'networkidle'});
 const card=page.locator('.jhd-card a.jhd-card-media').first();
 const target=await card.getAttribute('href');
 await card.click(); await page.waitForLoadState('networkidle');
 check(new URL(page.url()).pathname!=='/news','card image navigates to actual content');
 check(await page.locator('dialog[open],.jhd-lightbox.is-open').count()===0,'no unexpected popup');
 for(const link of await page.locator('.jhd-post-nav-wrap a, [aria-labelledby="related-heading"] a').evaluateAll(a=>[...new Set(a.map(x=>x.href))])) {
  const r=await context.request.get(link); check(r.status()===200,'related/neighbour destination '+link);
 }
 check(errors.length===0,'no JavaScript errors: '+errors.join('\n'));
 check(bad.length===0,'no failed non-upload resources: '+bad.join('\n'));
 console.log(`${checks} editorial browser checks passed; screenshots in test-results/.`);
} finally {await browser.close();}
