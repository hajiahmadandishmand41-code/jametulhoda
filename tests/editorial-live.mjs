// Read-only deployed-page evidence. Never authenticates, uploads, edits or deletes data.
import fs from 'node:fs';
import assert from 'node:assert/strict';
const base=process.env.TEST_BASE_URL;
assert.ok(base && /^https:\/\//.test(base),'Explicit HTTPS deployment URL required');
const checks=[];
const check=(label,ok,detail='')=>{checks.push({label,ok,detail});console.log(`${ok?'PASS':'FAIL'} ${label} ${detail}`);};
async function hit(path) {
 try {
  const r=await fetch(new URL(path,base),{redirect:'manual',headers:{'User-Agent':'JHD-editorial-deployment-probe-bot'},signal:AbortSignal.timeout(30000)});
  return {status:r.status,body:await r.text(),location:r.headers.get('location')||''};
 }catch(e){return {status:0,body:'',location:'',error:e.message};}
}
for(const path of ['/','/news','/articles','/about']) {
 const r=await hit(path);
 check('HTTP '+path,r.status===200,`${r.status} ${r.error||''}`);
 check('no runtime/DB failure '+path,!/Warning:|Fatal error:|شناسه پیگیری|jhd-db-notice/.test(r.body));
 check('canonical '+path,/<link rel="canonical" href="https:\/\//.test(r.body));
 if(path==='/') {
  check('no home introduction',!r.body.includes('class="jhd-about"'));
  for(const key of ['latest','popular']) {
   const s=r.body.match(new RegExp(`<section[^>]*data-home-section="${key}"[\\s\\S]*?<\\/section>`))?.[0]||'';
   check('max three '+key,(s.match(/<article\b/g)||[]).length<=3);
  }
  check('new editorial code present',r.body.includes('data-home-section=') || r.body.includes('هنوز محتوای منتشرشده‌ای'));
 }
 if(path==='/news'||path==='/articles') {
  check('archive has real content '+path,/jhd-card|jhd-editorial-row/.test(r.body));
  check('archive filtering '+path,r.body.includes('name="sort"')&&r.body.includes('name="cat"'));
  check('single-image cards '+path,!r.body.includes('jhd-card-gallery--'));
 }
}
for(const path of ['/school-news','/all-news','/scientific-articles']) {
 const r=await hit(path); check('legacy redirect '+path,r.status===301,r.location);
}
for(const path of ['/config/database.php','/.env']) {
 const r=await hit(path); check('protected '+path,r.status===404,String(r.status));
}
fs.mkdirSync('test-results',{recursive:true});
fs.writeFileSync('test-results/editorial-live.json',JSON.stringify({base,sha:process.env.GITHUB_SHA,date:new Date().toISOString(),checks},null,2));
const summary=`## Editorial deployment evidence\n\nTarget: ${base}\n\nCommit: ${process.env.GITHUB_SHA||'unknown'}\n\n${checks.map(c=>`- ${c.ok?'PASS':'FAIL'} ${c.label} ${c.detail}`).join('\n')}\n\nRead-only public tests; admin persistence and production migration are NOT verified by this probe.\n`;
fs.writeFileSync('test-results/editorial-live.md',summary);
process.exitCode=checks.some(c=>!c.ok)?1:0;
