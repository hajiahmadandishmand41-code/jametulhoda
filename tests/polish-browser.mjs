/** Final-polish integration checks; writes only to an explicitly allowed LOCAL fixture server. */
import { chromium } from '@playwright/test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const base = (process.env.TEST_BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
assert.ok(['localhost','127.0.0.1','[::1]'].includes(new URL(base).hostname), 'Never run this mutating suite against production');
assert.equal(process.env.TEST_ALLOW_WRITES, '1', 'Set TEST_ALLOW_WRITES=1 for an isolated fixture database');
assert.ok(process.env.TEST_ADMIN_USERNAME && process.env.TEST_ADMIN_PASSWORD, 'Local test administrator required');
fs.mkdirSync('test-results', {recursive:true});
const browser = await chromium.launch({executablePath:process.env.BROWSER_EXECUTABLE || undefined, args:['--no-sandbox']});
let checks = 0;
const check = (ok, label) => { checks++; assert.ok(ok, label); };
const text = value => value.replace(/&amp;/g,'&').replace(/&quot;/g,'"').replace(/&#0?39;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>');
const canonical = html => text(html.match(/<link rel="canonical" href="([^"]+)"/)?.[1] || '');
const stamp = Date.now();
const fixtures = [];
try {
  const context = await browser.newContext({baseURL:base, viewport:{width:1440,height:1000}});
  // Regression for a real editor blocker: a promise resumed requestSubmit
  // inside the original submit dispatch, so Chromium silently sent zero POSTs.
  for (const smallImage of [false,true]) {
    const probe=await context.newPage(); let posts=0;
    await probe.route('**/__submit-probe',route=>{
      if (route.request().method()==='POST') { posts++; return route.fulfill({contentType:'text/html',body:'<h1>saved</h1>'}); }
      return route.fulfill({contentType:'text/html',body:`<form method="post" enctype="multipart/form-data"><input type="file" name="image" accept="image/*"><button>Save</button></form><script>${fs.readFileSync('assets/js/upload-optimize.js','utf8')}</script>`});
    });
    await probe.goto('/__submit-probe');
    if (smallImage) await probe.locator('[type=file]').setInputFiles('tests/fixtures/image.png');
    await probe.locator('button').click();
    await probe.waitForFunction(()=>document.querySelector('h1')?.textContent==='saved');
    check(posts===1, 'upload optimizer resumes exactly once: '+(smallImage?'small image':'text-only save'));
    await probe.close();
  }
  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', e => pageErrors.push(String(e)));

  // Both spellings and conflicting foreign query routes must reach the actual
  // independent controllers, not just display a relabelled heading.
  for (const path of ['/login','/login.php','/login/','/admin/login','/admin/login.php']) {
    const r = await context.request.get(path + '?p=register');
    const html = await r.text();
    check(r.status() === 200 && /name="identifier"/.test(html) && !/name="full_name"/.test(html), path + ' is the login form despite p=register');
    check(/(?:^|,\s*)no-store(?:,|$)/.test(r.headers()['cache-control'] || ''), path + ' cannot be shared-cached');
  }
  for (const path of ['/register','/register.php','/register/']) {
    const r = await context.request.get(path + '?p=login');
    const html = await r.text();
    check(r.status() === 200 && /name="full_name"/.test(html) && /name="password_confirm"/.test(html) && !/name="identifier"/.test(html), path + ' is the independent registration form');
  }
  const wrongLogin = await context.request.post('/login?p=register', {form:{identifier:'missing@example.test',password:'invalid'}});
  check(/نشست شما منقضی شده است/.test(await wrongLogin.text()), 'polluted login POST still enforces login CSRF');
  const wrongRegister = await context.request.post('/register?p=login', {form:{full_name:'آزمون'}});
  check(/name="password_confirm"/.test(await wrongRegister.text()), 'polluted registration POST remains registration');

  await page.goto('/');
  check(await page.locator('#home-news-heading').count() === 1 && await page.locator('#home-articles-heading').count() === 1, 'homepage sections retained');
  check(await page.locator('.jhd-home-editorial-section img, .jhd-home-editorial-section .jhd-editorial-row').count() === 0, 'homepage news/articles stay text-only');
  const desktopLogin = page.locator('.jhd-account-cluster a').filter({hasText:/ورود/}).first();
  await desktopLogin.click();
  check(await page.locator('form.jhd-auth-form [name=identifier]').count() === 1, 'desktop header opens login');
  await page.setViewportSize({width:390,height:844});
  await page.goto('/');
  await page.locator('#menuToggle').click();
  await page.locator('#siteDrawer a.drawer-link').filter({hasText:/ورود/}).click();
  check(await page.locator('form.jhd-auth-form [name=identifier]').count() === 1, 'mobile drawer opens login');
  await page.goto('/');
  await page.locator('#menuToggle').click();
  await page.locator('#siteDrawer a.drawer-link').filter({hasText:/ثبت‌نام/}).click();
  check(await page.locator('form.jhd-auth-form [name=password_confirm]').count() === 1, 'mobile drawer independently opens registration');

  for (const route of ['/articles','/articles?type=research']) {
    await page.goto(route, {waitUntil:'networkidle'});
    check(await page.locator('.jhd-editorial-row').count() > 0, 'real archive content ' + route);
    for (const width of [320,390,768,1024,1440]) {
      await page.setViewportSize({width,height:1000});
      const result = await page.locator('.jhd-editorial-row').evaluateAll(cards => cards.map(card => {
        const rect = card.getBoundingClientRect();
        const col = card.parentElement.getBoundingClientRect();
        const image = card.querySelector('.jhd-editorial-thumb img');
        const body = card.querySelector('.jhd-editorial-main').getBoundingClientRect();
        const title = card.querySelector('.jhd-editorial-title');
        return {full:Math.abs(rect.width-col.width)<40, width:rect.width, height:rect.height,
          titleFits:title.scrollHeight <= title.clientHeight+1,
          noFrame:!!image || !card.querySelector('.jhd-editorial-thumb'),
          noGap:!!image || Math.abs(body.width-(rect.width-2*parseFloat(getComputedStyle(card).paddingLeft)-2))<2,
          image:image ? {fit:getComputedStyle(image).objectFit,ratio:image.clientWidth/image.clientHeight}:null};
      }));
      check(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth), route+' no overflow at '+width);
      check(result.every(r=>r.full && r.titleFits && r.noFrame && r.noGap), route+' full-width, unclipped, compact image-less cards at '+width);
      check(result.every(r=>!r.image || (r.image.fit==='cover' && Math.abs(r.image.ratio-16/9)<.04)), route+' unstretched 16:9 thumbnails at '+width);
    }
  }
  // The fixture contains one local seed-cover image. Failure must remove its
  // column as well as the img; test a genuine HTTP failure, not a CSS mock.
  const imageArchive = '/articles?q=' + encodeURIComponent('جایگاه عقل');
  await page.setViewportSize({width:1440,height:1000});
  await page.goto(imageArchive,{waitUntil:'networkidle'});
  check(await page.locator('.jhd-editorial-thumb img').count() === 1, 'fixture has a real archive image');
  const imagePattern = '**/uploads/images/seed-cover.png';
  await page.route(imagePattern, route=>route.fulfill({status:404,body:'not found'}));
  await page.reload({waitUntil:'networkidle'});
  await page.waitForFunction(()=>document.querySelector('.jhd-editorial-row--noimage') && !document.querySelector('.jhd-editorial-thumb'));
  check(await page.locator('.jhd-editorial-row').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length===1), 'broken image leaves neither a frame nor a reserved column');
  await page.unroute(imagePattern);
  await page.goto('/articles',{waitUntil:'networkidle'});
  await page.screenshot({path:'test-results/polish-articles-desktop.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});
  await page.screenshot({path:'test-results/polish-articles-mobile.png',fullPage:true});
  await page.locator('[data-theme-toggle]').first().click();
  check(await page.locator('html').getAttribute('data-theme')==='dark', 'archive retains dark mode');
  await page.screenshot({path:'test-results/polish-articles-dark.png',fullPage:true});

  // Login with the genuine form, sharing its real database-backed session with
  // subsequent admin GET/POST requests. Credentials never enter the report.
  await page.goto('/login');
  await page.locator('[name=identifier]').fill(process.env.TEST_ADMIN_USERNAME);
  await page.locator('[name=password]').fill(process.env.TEST_ADMIN_PASSWORD);
  await Promise.all([page.waitForURL('**/admin/dashboard*'), page.locator('form.jhd-auth-form button[type=submit]').click()]);
  check(await page.locator('body.jhd-admin-site').count()===1, 'successful browser login enters the protected admin');
  const samples = [
    {kind:'article',title:`polish-plain-${stamp}`,body:'بند نخست متن آزمایشی\nسطر دوم همین بند\n\nبند دوم متن با فاصله\n\nبند سوم و پایان',route:'/articles/'},
    {kind:'research',title:`polish-html-${stamp}`,body:'<h2>تیتر پژوهش</h2><p>بند اول <strong>برجسته</strong></p><p>بند دوم<br>سطر بعد</p><ul><li>مورد اول</li><li>مورد دوم</li></ul><blockquote><p>نقل‌قول علمی</p></blockquote>',route:'/research/'},
    {kind:'speech',title:`polish-legacy-${stamp}`,body:'<div>بند قدیمی نخست</div><div>بند قدیمی دوم\nادامه متن</div>',route:'/speech/'},
  ];
  for (const sample of samples) {
    await page.goto('/admin/posts/create?type='+sample.kind);
    await page.locator('[name=title]').fill(sample.title);
    await page.locator('#post-content').fill(sample.body);
    await page.locator('[name=status]').selectOption('published');
    await Promise.all([page.waitForURL(/\/admin\/.*edit/,{timeout:10000}), page.locator('button[type=submit]').filter({hasText:'ذخیره مطلب'}).click()]);
    const edit = page.url();
    const id = Number(new URL(edit).searchParams.get('id'));
    check(id>0, sample.kind+' admin create/save returned real database ID');
    await page.reload();
    check(await page.locator('#post-content').inputValue() === sample.body, sample.kind+' raw editor value survives database save/reload');
    // Exercise UPDATE separately from INSERT on our own newly-created record.
    if (sample.kind==='article') {
      sample.body += '\n\nبند چهارم پس از ویرایش';
      await page.locator('#post-content').fill(sample.body);
      await Promise.all([page.waitForNavigation(),page.locator('button[type=submit]').filter({hasText:'ذخیره تغییرات'}).click()]);
      await page.reload();
      check(await page.locator('#post-content').inputValue()===sample.body, 'updated multi-paragraph value survives reload unchanged');
    }
    fixtures.push({id,kind:sample.kind,content:sample.body});
    const r = await page.goto(sample.route+sample.title,{waitUntil:'networkidle'});
    check(r.status()===200, sample.kind+' saved content is public');
    const prose = page.locator('.jhd-prose').first();
    check(await prose.locator('p').count()>1, sample.kind+' semantic paragraphs survive public rendering');
    if (sample.kind==='research') check(await prose.locator('h2').count()===1 && await prose.locator('ul > li').count()===2 && await prose.locator('blockquote').count()===1, 'public HTML keeps headings/lists/quotes');
    for (const width of [360,768,1440]) {
      await page.setViewportSize({width,height:950});
      const style = await prose.evaluate(el=>{const s=getComputedStyle(el);return {font:parseFloat(s.fontSize),lh:parseFloat(s.lineHeight)/parseFloat(s.fontSize),margin:parseFloat(getComputedStyle(el.querySelector('p')).marginBottom)};});
      check(style.font>=14 && style.font<=15 && style.lh>=1.8 && style.lh<=1.9 && style.margin>0, sample.kind+' readable 14–15px body and real paragraph spacing at '+width);
      check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),sample.kind+' detail has no overflow at '+width);
    }
  }
  fs.writeFileSync('test-results/polish-fixtures.json',JSON.stringify(fixtures,null,2));

  const publicContext = await browser.newContext({baseURL:base});
  const sitemap = await publicContext.request.get('/sitemap.xml?p=register',{maxRedirects:0});
  check(sitemap.status()===200 && /application\/xml/.test(sitemap.headers()['content-type']), 'explicit sitemap route remains XML despite foreign p');
  const xml = await sitemap.text();
  const locs = await page.evaluate(xml=>{const doc=new DOMParser().parseFromString(xml,'application/xml');if(doc.querySelector('parsererror')) throw new Error('Invalid sitemap XML');return [...doc.querySelectorAll('url > loc')].map(e=>e.textContent);},xml);
  check(locs.length>0 && new Set(locs).size===locs.length, 'actual HTTP XML parses and canonical URLs are unique');
  for (const loc of locs) {
    check(new URL(loc).origin===new URL(base).origin, 'sitemap URL uses configured origin');
    const r=await publicContext.request.get(loc,{maxRedirects:0});
    const html=await r.text();
    check(r.status()===200 && !/name="robots" content="noindex/.test(html) && canonical(html)===loc, 'sitemap destination is 200/indexable/self-canonical: '+new URL(loc).pathname);
    check(!/(?:Warning|Fatal error|Parse error|Notice):/.test(html),'destination contains no PHP warning');
  }
  fs.writeFileSync('test-results/polish-sitemap-urls.json',JSON.stringify(locs,null,2));
  const robots=await publicContext.request.get('/robots.txt?p=register',{maxRedirects:0});
  check(robots.status()===200 && /text\/plain/.test(robots.headers()['content-type']) && (await robots.text()).includes('Sitemap: '+base+'/sitemap.xml'),'robots text and absolute sitemap URL');
  for (const route of ['/sitemap.xml','/robots.txt']) {
    const head=await publicContext.request.head(route);
    check(head.status()===200 && (await head.body()).length===0,'HEAD '+route+' has no HTML/body');
  }
  check(pageErrors.length===0, 'no browser JavaScript errors');
  console.log(`PASS ${checks} final-polish integration checks; ${locs.length} real sitemap destinations; editor create/update/reload/public, independent auth, responsive cards and prose`);
  await publicContext.close();
  await context.close();
} catch (error) {
  const lastPage=browser.contexts().at(-1)?.pages().at(-1);
  if (lastPage) {
    console.error('Last page:',lastPage.url());
    console.error('Visible errors:',await lastPage.locator('.alert-danger').allTextContents());
    console.error('Form diagnostics:',await lastPage.evaluate(()=>[...document.forms].map(f=>({id:f.id,invalid:[...f.elements].filter(e=>e.willValidate&&!e.validity.valid).map(e=>({name:e.name,message:e.validationMessage})),buttons:[...f.querySelectorAll('button[type=submit]')].map(b=>b.form?.id),optimized:f.dataset.uploadOptimized}))));
    await lastPage.screenshot({path:'test-results/polish-failure.png',fullPage:true}).catch(()=>{});
  }
  throw error;
} finally { await browser.close(); }
