/** Mutates ONLY an explicitly supplied disposable local SQLite fixture. */
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import {request,chromium} from '@playwright/test';
const base=process.env.TEST_BASE_URL||'http://127.0.0.1:8080';
assert.ok(['127.0.0.1','localhost'].includes(new URL(base).hostname),'local fixture only');
const db=process.env.TEST_DB_PATH;
assert.ok(db && db.includes('/test-results/'),'explicit disposable fixture DB in test-results required');
const sql=(query,args=[])=>JSON.parse(execFileSync('python3',['-c',`import sqlite3,json,sys
c=sqlite3.connect(sys.argv[1]);c.row_factory=sqlite3.Row
s=c.execute(sys.argv[2],json.loads(sys.argv[3]));r=[dict(x) for x in s.fetchall()] if s.description else [];c.commit();print(json.dumps(r))`,db,query,JSON.stringify(args)],{encoding:'utf8'}));
const api=await request.newContext({baseURL:base});
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const token=html=>html.match(/name="csrf_token"\s+value="([^"]+)"/)?.[1];
async function post(path,fields,fileField){
 const csrf=token(await (await api.get(path)).text());
 const multipart={csrf_token:csrf,...fields};
 if(fileField) multipart[fileField]={name:'test.png',mimeType:'image/png',buffer:fs.readFileSync(new URL('./fixtures/image.png',import.meta.url))};
 const response=await api.post(path,{multipart,maxRedirects:0});
 const body=await response.text();
 check(!/Warning:|Fatal error:|Uncaught/.test(body),'no PHP failure '+path);
 return {response,body};
}
let bannerId=0,topicId=0,postId=0;
const originalSettings=sql('SELECT setting_key,value FROM settings');
const suffix=Date.now();
const banner={title:'بنر آزمون '+suffix,description:'توضیح واقعی آزمون',link_url:'/news',button_text:'خبرها',is_active:'1',sort_order:'-99',placement:'main'};
const topic={name:'موضوع آزمون '+suffix,slug:'qa-topic-'+suffix,description:'توضیح موضوع',is_active:'1',is_featured:'1',sort_order:'-99',display_mode:'minimal',content_limit:'2'};
let browser;
try {
 const login=await api.get('/login');
 const auth=await api.post('/login',{form:{csrf_token:token(await login.text()),identifier:'admin',password:'TestAdmin123!@#'},maxRedirects:0});
 check(auth.status()===303,'administrator authentication');
 const unauthorized=await request.newContext({baseURL:base});
 check((await unauthorized.post('/admin/storage/direct',{form:{action:'sign',name:'file.mp4',size:'13631488',kind:'video'}})).status()===403,'unauthenticated signing rejected');
 check((await api.post('/admin/storage/direct',{form:{action:'sign'}})).status()===403,'missing CSRF rejected');
 await unauthorized.dispose();
 let result=await post('/admin/banners/',banner,'image');
 check(result.response.status()===303,'A/F small image and banner saved');
 // Controlled storage validation failure: invalid bytes with an image name.
 const csrfBad=token(await (await api.get('/admin/banners/')).text());
 const bad=await api.post('/admin/banners/',{multipart:{csrf_token:csrfBad,...banner,title:'invalid-image-'+suffix,image:{name:'spoof.png',mimeType:'image/png',buffer:Buffer.from('not an image')}} ,maxRedirects:0});
 check(bad.status()===200 && (await bad.text()).includes('آپلود یا تأیید تصویر بنر ناموفق'),'C invalid storage input never reports success');
 check(sql('SELECT COUNT(*) AS n FROM featured_banners WHERE title=?',['invalid-image-'+suffix])[0].n===0,'C no database record on failed image upload');
 const row=sql('SELECT * FROM featured_banners WHERE title=?',[banner.title])[0];bannerId=row.id;
 check(!!row.image,'banner database references an actual image');
 check((await api.get(row.image)).status()===200,'A actual image retrieval');
 let home=await (await api.get('/')).text();
 check(home.includes('data-banner-id="'+bannerId+'"') && home.includes(row.image),'F banner rendered after reload');
 result=await post('/admin/banners/',{...banner,edit_id:String(bannerId),is_active:'',placement:'news'});
 // isset checkbox semantics: omit unchecked rather than empty string.
 const inactive={...banner,edit_id:String(bannerId),placement:'news'};delete inactive.is_active;
 await post('/admin/banners/',inactive);
 home=await (await api.get('/')).text();
 check(!home.includes('data-banner-id="'+bannerId+'"'),'F disabled banner absent');
 await post('/admin/banners/',{...banner,edit_id:String(bannerId),placement:'news',sort_order:'-98'});
 home=await (await api.get('/')).text();
 check(home.includes('data-banner-id="'+bannerId+'"') && home.indexOf('data-banner-id="'+bannerId+'"')>home.indexOf('data-home-section="latest"'),'F section placement applied');
 await post('/admin/banners/',{...banner,edit_id:String(bannerId),starts_at:'2099-01-01T00:00'});
 check(!(await (await api.get('/')).text()).includes('data-banner-id="'+bannerId+'"'),'F future schedule hidden');
 await post('/admin/banners/',{...banner,edit_id:String(bannerId)},'image');
 const replaced=sql('SELECT image FROM featured_banners WHERE id=?',[bannerId])[0].image;
 check(replaced!==row.image && (await api.get(replaced)).status()===200,'F small image replacement persists');
 // Inject a database failure AFTER upload, without affecting any existing file.
 sql(`CREATE TRIGGER qa_fail_banner BEFORE UPDATE ON featured_banners WHEN OLD.id=${bannerId} BEGIN SELECT RAISE(FAIL,'injected database failure'); END`);
 result=await post('/admin/banners/',{...banner,edit_id:String(bannerId)},'image');
 check(result.response.status()===200 && result.body.includes('ثبت دیتابیس بنر ناموفق'),'D database failure is not success');
 check(sql('SELECT image FROM featured_banners WHERE id=?',[bannerId])[0].image===replaced && (await api.get(replaced)).status()===200,'D failed replacement retains old image');
 sql('DROP TRIGGER qa_fail_banner');
 result=await post('/admin/banners/',{...banner,edit_id:String(bannerId),title:''});
 check(result.body.includes(banner.description),'failed validation preserves form fields');
 result=await post('/admin/topics/',topic,'cover_image');
 check(result.response.status()===303,'H topic with cover created');
 const tr=sql('SELECT * FROM topics WHERE slug=?',[topic.slug])[0];topicId=tr.id;
 check(!!tr.cover_image && (await api.get(tr.cover_image)).status()===200,'H topic cover saved and accessible');
 home=await (await api.get('/')).text();
 check(home.includes(topic.name),'H featured topic appears');
 let detail=await api.get('/topic/'+topic.slug);
 check(detail.status()===200 && (await detail.text()).includes('محتوای منتشرشده‌ای برای این موضوع یافت نشد'),'H empty topic has valid page and message');
 postId=sql("SELECT id FROM posts WHERE status='published' LIMIT 1")[0].id;
 sql('INSERT INTO post_topics(post_id,topic_id) VALUES (?,?)',[postId,topicId]);
 for(const kind of ['audio','video','document']) {
   const mediaPath='/admin/content/media';
   const media=await api.post(mediaPath,{form:{csrf_token:token(await (await api.get('/admin/topics/')).text()),post_id:String(postId),action:'add',kind,'jhd_direct[files][]':'staging/unowned.mp4'},maxRedirects:0});
   const payload=await media.json();
   check(media.status()===422 && !payload.ok && payload.error.includes('مستقیم'),'E AJAX '+kind+' handles direct-only fields and rejects unowned object');
 }
 const hidden={...topic,edit_id:String(topicId),sort_order:'-98'};delete hidden.is_featured;
 await post('/admin/topics/',hidden);
 home=await (await api.get('/')).text();
 // It may still appear in public navigation; assert the homepage SECTION only.
 const topicSection=home.match(/data-home-section="topics"[\s\S]*?<\/section>/)?.[0]||'';
 check(!topicSection.includes(topic.name),'H homepage opt-out respected without fallback');
 const topicPage=await (await api.get('/topic/'+topic.slug)).text();
 check(topicPage.includes('data-display-mode="minimal"') && topicPage.includes('jhd-home-minimal'),'H related-content display control applied');
 check(sql('SELECT COUNT(*) AS n FROM post_topics WHERE topic_id=?',[topicId])[0].n===1,'H associations retained when hidden');
 // Persist each mode through the actual settings form, inspect public output.
 const settingsPage=await (await api.get('/admin/settings')).text();
 const settingsToken=token(settingsPage);
 for(const mode of ['list','horizontal','featured','slider','minimal','grid']) {
   const saved=await api.post('/admin/settings',{form:{csrf_token:settingsToken,site_name:'جامعه‌الهدی',homepage_sections_present:'1','homepage_sections[]':'news', 'homepage_layout[news][mode]':mode,'homepage_layout[news][limit]':'3','homepage_layout[topics][mode]':mode},maxRedirects:0});
   check(saved.status()===303,'G mode persisted via admin: '+mode);
   const output=await (await api.get('/')).text();
   const section=output.match(/data-home-section="news"[\s\S]*?<\/section>/)?.[0]||'';
   check(section.includes('data-display-mode="'+mode+'"'),'G mode read by public page: '+mode);
   if(mode==='minimal') check(section.includes('jhd-home-minimal')&&!section.includes('jhd-card-media'),'G minimal mode actually removes images');
   if(mode==='horizontal') check(section.includes('jhd-card--row'),'G horizontal variant actually rendered');
   if(mode==='featured') check(section.includes('jhd-card--featured'),'G large lead variant actually rendered');
   if(mode==='slider') check(section.includes('jhd-home-slider')&&section.includes('tabindex="0"'),'G slider is keyboard/touch scrollable');
 }
 const logout=await api.get('/logout');
 await api.post('/logout',{form:{csrf_token:token(await logout.text())}});
 const loginAgain=await api.get('/login');
 const relogin=await api.post('/login',{form:{csrf_token:token(await loginAgain.text()),identifier:'admin',password:'TestAdmin123!@#'},maxRedirects:0});
 check(relogin.status()===303,'G/H login again after save');
 check((await (await api.get('/admin/settings')).text()).includes('value="grid" selected'),'G saved display configuration survives new login');
 check((await api.get(replaced)).status()===200,'A media remains accessible after new login');
 browser=await chromium.launch({executablePath:process.env.BROWSER_EXECUTABLE||undefined,args:['--no-sandbox'],headless:true});
 const page=await browser.newPage({viewport:{width:390,height:844},isMobile:true,hasTouch:true});
 await page.context().addCookies((await api.storageState()).cookies);
 await page.goto(base+'/admin/banners/?edit='+bannerId);
 await page.locator('input[name="image"]').setInputFiles({name:'preview.png',mimeType:'image/png',buffer:fs.readFileSync(new URL('./fixtures/image.png',import.meta.url))});
 check(await page.locator('[data-banner-preview] img').isVisible(),'F/I unpublished mobile image preview');
 await page.goto(base+'/');
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'I mobile homepage has no horizontal overflow');
 await page.screenshot({path:'test-results/admin-media-home-mobile.png',fullPage:true});
 await page.setViewportSize({width:1440,height:1000});
 await page.reload();
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'I desktop homepage has no horizontal overflow');
 console.log(`${checks} local media/display checks passed. Supabase network and PostgreSQL NOT covered.`);
} finally {
 for(const row of originalSettings) sql('UPDATE settings SET value=? WHERE setting_key=?',[row.value,row.setting_key]);
 try{sql('DROP TRIGGER IF EXISTS qa_fail_banner');}catch{}
 if(bannerId) await post('/admin/banners/',{delete:String(bannerId)});
 if(topicId){if(postId)sql('DELETE FROM post_topics WHERE post_id=? AND topic_id=?',[postId,topicId]);await post('/admin/topics/',{delete:String(topicId)});}
 await browser?.close();await api.dispose();
}
