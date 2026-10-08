import {chromium} from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';
fs.mkdirSync('test-results',{recursive:true});
const base=process.env.TEST_BASE_URL || 'http://127.0.0.1:8080/';
const browser=await chromium.launch({executablePath:process.env.BROWSER_EXECUTABLE || undefined,args:['--no-sandbox'],headless:true});
try {
  const page=await browser.newPage({viewport:{width:1440,height:1000}});
  const errors=[];
  page.on('pageerror',e=>errors.push(String(e)));
  page.on('response',r=>{if(r.status()>=400) errors.push(r.status()+' '+r.url())});
  await page.goto(base,{waitUntil:'networkidle'});
  assert.equal(await page.locator('html').getAttribute('data-theme'),'light','first visit defaults to light theme');
  await page.screenshot({path:'test-results/home-desktop.png',fullPage:true});

  const homeHero=page.locator('.jhd-home-hero');
  assert.equal(await homeHero.isVisible(),true,'homepage introduction is visible');
  assert.equal(await page.locator('#home-brand-title').isVisible(),true,'homepage has a visible brand heading');
  assert.equal(await page.locator('.jhd-home-quick-links a').count(),4,'homepage has four quick-access destinations');
  const homeSearch=page.locator('.jhd-home-search');
  const homeSearchInput=page.locator('#home-search-query');
  assert.equal(await homeSearch.isVisible(),true,'homepage archive search is visible');
  assert.equal(await page.locator('label[for="home-search-query"]').count(),1,'homepage search has an associated accessible label');
  await homeSearchInput.fill('قرآن');
  await Promise.all([
    page.waitForURL(url=>url.pathname.replace(/\/+$/,'').endsWith('/search')||url.searchParams.get('p')==='search',{timeout:30000}),
    homeSearch.locator('button[type=submit]').click(),
  ]);
  assert.equal(new URL(page.url()).searchParams.get('q'),'قرآن','homepage search submits the entered query');
  assert.equal(await page.locator('#archive-search-query').inputValue(),'قرآن','homepage search opens the archive with the query');
  await page.goto(base,{waitUntil:'networkidle'});
  await page.setViewportSize({width:390,height:844});
  const homeSearchFontSize=await homeSearchInput.evaluate(el=>parseFloat(getComputedStyle(el).fontSize));
  assert.ok(homeSearchFontSize>=16,'homepage search avoids iOS input zoom on phones');

  const viewportAudit=[];
  for (const width of [320,360,375,390,430,768,992,1024,1280,1440]) {
    await page.setViewportSize({width,height:900});
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
    const navVisible=await page.locator('.jhd-navbar-desktop').isVisible();
    const menuVisible=await page.locator('#menuToggle').isVisible();
    viewportAudit.push({width,overflow,navVisible,menuVisible});
    assert.equal(overflow,false,`horizontal overflow at ${width}px`);
    assert.equal(navVisible,width>=992,`desktop navigation breakpoint at ${width}px`);
    assert.equal(menuVisible,width<992,`drawer toggle breakpoint at ${width}px`);
  }

  // Audit every requested public route in a real browser, not just the landing page.
  // Route smoke checks status/canonical metadata; this layer checks visible content,
  // responsive overflow and actual browser resource/runtime errors.
  const publicRoutes=['news','articles','reports','events','books','lessons','research','media','videos','audios','topics','search','about','contact','qa','login'];
  const pageViewportAudit=[];
  for (const route of publicRoutes) {
    const response=await page.goto(new URL(route,base.endsWith('/')?base:base+'/').href,{waitUntil:'networkidle'});
    assert.equal(response?.status(),200,`HTTP ${route}`);
    assert.equal(await page.locator('h1').first().isVisible(),true,`visible page heading ${route}`);
    assert.equal(route==='login' ? await page.locator('body.jhd-login-site').count()===1 : await page.locator('body.jhd-public-site').count()===1,true,`shared visual shell ${route}`);
    const description=await page.locator('meta[name="description"]').getAttribute('content');
    assert.ok((description||'').trim().length>20,`descriptive metadata ${route}`);
    for (const width of [360,768,1280]) {
      await page.setViewportSize({width,height:900});
      const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
      assert.equal(overflow,false,`horizontal overflow ${route} at ${width}px`);
    }
    pageViewportAudit.push(route);
  }
  console.log('PASS public browser audit: '+pageViewportAudit.length+' routes × 3 widths');
  // The archive search should stay compact and usable instead of inheriting
  // Bootstrap's oversized input-group-lg typography, especially on phones.
  await page.setViewportSize({width:390,height:844});
  await page.goto(new URL('search?q=قرآن',base.endsWith('/')?base:base+'/').href,{waitUntil:'networkidle'});
  const archiveSearch=page.locator('.jhd-search-form');
  const archiveQuery=page.locator('#archive-search-query');
  assert.equal(await archiveSearch.isVisible(),true,'visible archive search form');
  assert.equal(await archiveQuery.isVisible(),true,'visible archive query input');
  assert.equal(await archiveQuery.getAttribute('autofocus'),null,'search does not unexpectedly open the mobile keyboard');
  assert.equal(await page.locator('label[for="archive-search-query"]').count(),1,'visible search label is associated with its input');
  assert.equal(await page.locator('#archive-search-type').count(),1,'content type filter is available');
  for (const width of [360,768,1280]) {
    await page.setViewportSize({width,height:900});
    const searchMetrics=await page.locator('#archive-search-query').evaluate(el=>({
      fontSize:parseFloat(getComputedStyle(el).fontSize),
      height:el.getBoundingClientRect().height,
      overflow:document.documentElement.scrollWidth>innerWidth,
    }));
    assert.ok(searchMetrics.fontSize<=16.1,`search text remains appropriately sized at ${width}px (got ${searchMetrics.fontSize}px)`);
    assert.ok(searchMetrics.height<=52,`search control is not oversized at ${width}px (got ${searchMetrics.height}px)`);
    assert.equal(searchMetrics.overflow,false,`search page has no horizontal overflow at ${width}px`);
  }
  console.log('PASS compact, labelled, responsive archive search');

  const detailFixture='test-results/browser-detail-routes.json';
  const detailRoutes=fs.existsSync(detailFixture)?JSON.parse(fs.readFileSync(detailFixture,'utf8')):[];
  for (const route of detailRoutes) {
    const siteRoot=new URL(base.endsWith('/')?base:base+'/');
    const response=await page.goto(new URL(route.replace(/^\/+/,''),siteRoot).href,{waitUntil:'networkidle'});
    assert.equal(response?.status(),200,`HTTP detail ${route}`);
    assert.equal(await page.locator('h1').first().isVisible(),true,`visible detail heading ${route}`);
    assert.equal(await page.locator('body.jhd-public-site').count(),1,`shared detail shell ${route}`);
    for (const width of [360,768,1280]) {
      await page.setViewportSize({width,height:900});
      const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
      assert.equal(overflow,false,`horizontal overflow ${route} at ${width}px`);
    }
  }
  console.log('PASS browser detail audit: '+detailRoutes.length+' database-backed detail routes × 3 widths');

  await page.setViewportSize({width:390,height:844});
  await page.goto(base,{waitUntil:'networkidle'});
  await page.screenshot({path:'test-results/home-mobile.png',fullPage:true});
  await page.locator('#menuToggle').click();
  assert.equal(await page.locator('#menuToggle').getAttribute('aria-expanded'),'true','mobile menu opens');
  assert.equal(await page.locator('#siteDrawer').getAttribute('aria-hidden'),'false','drawer is exposed to assistive technology');
  await page.keyboard.press('Shift+Tab');
  assert.equal(await page.evaluate(()=>document.querySelector('#siteDrawer').contains(document.activeElement)),true,'drawer contains keyboard focus');
  await page.keyboard.press('Escape');
  await page.locator('[data-theme-toggle]').click();
  await page.reload();
  assert.equal(await page.locator('html').getAttribute('data-theme'),'dark','theme persists');
  console.log('PASS responsive widths ' + viewportAudit.map(v=>v.width).join(', ') + ', drawer keyboard focus, no overflow, theme persistence');
  if (process.env.TEST_ADMIN_PASSWORD) {
    await page.goto(new URL('login',base).href,{waitUntil:'networkidle'});
    await page.locator('[name=identifier]').fill(process.env.TEST_ADMIN_USERNAME || 'qa_admin');
    await page.locator('[name=password]').fill(process.env.TEST_ADMIN_PASSWORD);
    // The login page also renders the site header + drawer search forms, so the
    // submit button must be scoped to the authentication form itself.
    await Promise.all([page.waitForURL('**/admin/dashboard*'),page.locator('form.jhd-auth-form button[type=submit]').click()]);
    assert.equal(await page.locator('html').getAttribute('data-theme'),'dark','admin inherits theme');
    assert.equal(await page.locator('body.jhd-admin-site').count(),1,'admin shared shell');
    await page.goto(new URL('admin/dashboard',base.endsWith('/')?base:base+'/').href,{waitUntil:'networkidle'});
    assert.equal(await page.locator('#admin-dashboard-title').isVisible(),true,'admin dashboard has a visible welcome heading');
    assert.equal(await page.locator('[data-dashboard-section]').count(),3,'dashboard metrics are separated into three clear sections');
    assert.equal(await page.locator('.admin-quick-group').count(),2,'quick actions are separated by task');
    assert.equal(await page.locator('#admin-nav-content .sidebar-link').count(),8,'content navigation lists each content type separately');
    for (const width of [360,768,1280]) {
      await page.setViewportSize({width,height:900});
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`admin dashboard has no horizontal overflow at ${width}px`);
    }
    await page.setViewportSize({width:390,height:844});
    // The width loop above crossed back over the desktop breakpoint, where the
    // matchMedia handler (an async change event) stripped the mobile
    // accessibility attributes. Wait for the round trip to settle so the
    // closed-drawer state below is observed deterministically.
    await page.waitForFunction(()=>document.getElementById('adminSidebar')?.getAttribute('aria-hidden')==='true',{timeout:5000});
    const sidebar=page.locator('#adminSidebar');
    const sidebarToggle=page.locator('#sidebarToggle');
    const sidebarClose=page.locator('#sidebarClose');
    const adminMain=page.locator('#adminMain');
    assert.equal(await sidebar.getAttribute('aria-hidden'),'true','closed mobile admin navigation is hidden from assistive technology');
    assert.notEqual(await sidebar.getAttribute('inert'),null,'closed mobile admin navigation cannot receive keyboard focus');
    assert.equal(await adminMain.getAttribute('inert'),null,'closed drawer leaves the main page interactive');
    await sidebarToggle.click();
    assert.equal(await sidebarToggle.getAttribute('aria-expanded'),'true','admin navigation opens');
    assert.equal(await sidebar.getAttribute('aria-hidden'),'false','open admin navigation is exposed to assistive technology');
    assert.equal(await sidebar.getAttribute('inert'),null,'open admin navigation is interactive');
    assert.equal(await adminMain.getAttribute('aria-hidden'),'true','open modal drawer hides background content from assistive technology');
    assert.notEqual(await adminMain.getAttribute('inert'),null,'open modal drawer prevents background keyboard interaction');
    assert.equal(await page.evaluate(()=>document.activeElement.id),'sidebarClose','opening admin navigation moves focus to its close control');
    assert.equal(await page.evaluate(()=>document.body.style.overflow),'hidden','open admin navigation locks background scroll');
    await sidebarClose.click();
    assert.equal(await sidebarToggle.getAttribute('aria-expanded'),'false','close control closes the admin navigation');
    assert.equal(await adminMain.getAttribute('inert'),null,'closing drawer restores background interaction');
    assert.equal(await page.evaluate(()=>document.activeElement.id),'sidebarToggle','close control restores focus to its toggle');
    await sidebarToggle.click();
    assert.equal(await page.evaluate(()=>document.body.style.overflow),'hidden','open admin navigation locks background scroll');
    await page.locator('#adminSidebarOverlay').click({position:{x:10,y:10}});
    assert.equal(await sidebarToggle.getAttribute('aria-expanded'),'false','backdrop closes the admin navigation');
    assert.equal(await page.evaluate(()=>document.body.style.overflow),'','backdrop restores background scrolling');
    await sidebarToggle.click();
    await page.keyboard.press('Escape');
    assert.equal(await sidebarToggle.getAttribute('aria-expanded'),'false','Escape closes the admin navigation');
    assert.equal(await sidebar.getAttribute('aria-hidden'),'true','closed admin navigation is hidden again');
    assert.equal(await page.evaluate(()=>document.activeElement.id),'sidebarToggle','closing admin navigation restores focus to its toggle');
    await sidebarToggle.click();
    await page.setViewportSize({width:1280,height:900});
    // The breakpoint handler reacts to the async matchMedia `change` event. Wait
    // until it has applied the desktop layout — it clears the scroll lock and
    // the accessibility attributes in the same task, so this settles all three
    // assertions below deterministically instead of racing the event delivery.
    await page.waitForFunction(()=>document.body.style.overflow==='','', {timeout:5000});
    assert.equal(await page.evaluate(()=>document.body.style.overflow),'','desktop resize restores background scrolling');
    assert.equal(await sidebar.getAttribute('aria-hidden'),null,'desktop sidebar remains exposed to assistive technology');
    assert.equal(await adminMain.getAttribute('inert'),null,'desktop page remains interactive after resizing');
    await page.goto(new URL('admin/posts/create?post_type=research',base.endsWith('/')?base:base+'/').href,{waitUntil:'networkidle'});
    assert.equal(await page.locator('#admin-nav-content').evaluate(el=>el.open),true,'typed content creation opens its sidebar group');
    assert.match((await page.locator('#admin-nav-content [aria-current="page"]').textContent())||'',/پژوهش‌ها/,'research creation selects the research navigation entry');
    await page.setViewportSize({width:390,height:844});
    await page.goto(new URL('admin/settings',base.endsWith('/')?base:base+'/').href,{waitUntil:'networkidle'});
    assert.equal(await page.locator('#setting-site-name').isVisible(),true,'site settings form is visible to super admin');
    assert.equal(await page.locator('#admin-nav-system').evaluate(el=>el.open),true,'active system section expands in the sidebar');
    assert.equal(await page.locator('#admin-nav-system [aria-current="page"]').count(),1,'active settings route is identified in the sidebar');
    for (const width of [360,768,1280]) {
      await page.setViewportSize({width,height:900});
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`admin settings has no horizontal overflow at ${width}px`);
    }
    await page.goto(new URL('admin/media/',base).href,{waitUntil:'networkidle'});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'admin mobile overflow');
    assert.equal(await page.evaluate(()=>[...document.images].some(i=>i.complete&&!i.naturalWidth)),false,'broken admin images');
    await page.screenshot({path:'test-results/admin-mobile.png',fullPage:true});
    console.log('PASS browser staff login, admin mobile navigation, media, theme');
  }
  assert.deepEqual(errors,[],'browser errors / failed resources');
} finally { await browser.close(); }
