// Mutates only an isolated local fixture instance; restores the entire settings form.
import assert from 'node:assert/strict';
const base = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
assert.ok(['localhost', '127.0.0.1', '[::1]'].includes(new URL(base).hostname), 'Never mutate production');
const jar = new Map();
let checks = 0;
const check = (ok, label) => { assert.ok(ok, label); checks++; console.log('PASS '+label); };
const decode = s => s.replace(/&amp;/g,'&').replace(/&quot;/g,'"').replace(/&#0?39;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>');
async function get(path, options={}) {
  const response = await fetch(new URL(path,base), {redirect:'manual',signal:AbortSignal.timeout(20000),...options,
    headers:{Cookie:[...jar].map(([k,v])=>`${k}=${v}`).join('; '),...options.headers}});
  for (const c of response.headers.getSetCookie()) { const pair=c.split(';')[0]; const i=pair.indexOf('='); jar.set(pair.slice(0,i),pair.slice(i+1)); }
  const body=await response.text();
  check(!/Warning:|Fatal error:|Uncaught|شناسه پیگیری/.test(body), 'no PHP error '+path);
  return {response,body};
}
const csrf = s => s.match(/name="csrf_token"\s+value="([^"]+)"/)?.[1];
function formValues(html) {
  const out=new URLSearchParams();
  const form=html.match(/<form[^>]*class="admin-form"[\s\S]*?<\/form>/)?.[0];
  assert.ok(form,'settings form');
  for(const m of form.matchAll(/<input\b[^>]*>/g)) {
    const tag=m[0], name=tag.match(/name="([^"]+)"/)?.[1];
    if(!name || /type="(?:file|submit)"/.test(tag) || (/type="checkbox"/.test(tag)&&!tag.includes('checked'))) continue;
    out.append(decode(name),decode(tag.match(/value="([^"]*)"/)?.[1]||''));
  }
  for(const m of form.matchAll(/<textarea[^>]*name="([^"]+)"[^>]*>([\s\S]*?)<\/textarea>/g)) out.set(decode(m[1]),decode(m[2]));
  for(const m of form.matchAll(/<select[^>]*name="([^"]+)"[^>]*>([\s\S]*?)<\/select>/g)) {
    const opts=[...m[2].matchAll(/<option\b([^>]*)>([\s\S]*?)<\/option>/g)];
    const opt=opts.find(o=>o[1].includes('selected')) || opts[0];
    out.set(decode(m[1]), decode(opt?.[1].match(/value="([^"]*)"/)?.[1]||''));
  }
  return out;
}
async function save(params) {
  const current=await get('/admin/settings');
  params=new URLSearchParams(params); params.set('csrf_token',csrf(current.body));
  const result=await get('/admin/settings',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params});
  check(result.response.status===303,'settings committed with 303');
}
let home=await get('/');
check(home.response.status===200 && !home.body.includes('class="jhd-about"') && !home.body.includes('معرفی مدرسه'),'no homepage introduction');
for(const key of ['latest','popular']) {
  const section=home.body.match(new RegExp(`<section[^>]*data-home-section="${key}"[\\s\\S]*?<\\/section>`))?.[0]||'';
  check((section.match(/<article\b/g)||[]).length<=3,'max three '+key);
}
for(const [path,target] of [['school-news','news'],['all-news','news'],['scientific-articles','articles'],['scientific-papers','articles'],['research','articles']]) {
  const r=await get('/'+path+'?q=test&page=2');
  check(r.response.status===301 && r.response.headers.get('location').includes(target) && r.response.headers.get('location').includes('q=test'),'permanent legacy redirect '+path);
}
for(const archive of ['news','articles']) {
  const r=await get('/'+archive+'?q=قرآن&sort=oldest&cat=1');
  check(r.response.status===200 && r.body.includes('name="cat"') && r.body.includes('name="sort"'),'search/category/sort '+archive);
}
let r=await get('/login');
r=await get('/login',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:csrf(r.body),identifier:process.env.ADMIN_USER||'admin',password:process.env.ADMIN_PASSWORD||'TestAdmin123!@#'})});
check(r.response.status===303,'admin login required and succeeds');
const originalPage=await get('/admin/settings');
const original=formValues(originalPage.body);
const modified=new URLSearchParams(original);
const sectionKeys=['feature','popular','latest','news','writing','research','books','lessons','media'];
modified.delete('homepage_sections[]');
for(const k of sectionKeys.filter(k=>k!=='media')) modified.append('homepage_sections[]',k);
modified.set('homepage_layout[news][title]','خبرهای منتخب آزمون');
modified.set('homepage_layout[news][order]','0');
modified.set('homepage_layout[feature][order]','99');
modified.set('homepage_layout[latest][limit]','99'); // server must clamp
modified.set('homepage_layout[news][category]','5');
try {
  await save(modified);
  home=await get('/');
  check(!home.body.includes('data-home-section="media"'),'disabled section has no markup/blank space');
  check(home.body.includes('خبرهای منتخب آزمون'),'custom title rendered');
  check(home.body.indexOf('data-home-section="news"')<home.body.indexOf('data-home-section="feature"'),'custom order rendered');
  const reload=await get('/admin/settings'); const reloaded=formValues(reload.body);
  check(reloaded.get('homepage_layout[news][title]')==='خبرهای منتخب آزمون' && reloaded.get('homepage_layout[news][category]')==='5','title/category persisted after reload');
  check(reloaded.get('homepage_layout[latest][limit]')==='3','max three enforced server side');
  const options=[...originalPage.body.matchAll(/<option value="((post|book|lesson):\d+)"[^>]*>([^<]+)<\/option>/g)];
  for(const [kind,label] of [['post','خبر'],['post','گزارش'],['post','مقاله'],['book','کتاب'],['lesson','درس']]) {
    const item=options.find(m=>m[2]===kind && decode(m[3]).trim().startsWith(label+' —'));
    assert.ok(item,'published selector includes '+label);
    modified.set('homepage_feature_ref',item[1]); modified.set('homepage_hero_mode','manual');
    await save(modified);
    home=await get('/');
    const lead=home.body.match(/<article class="jhd-lead">[\s\S]*?<\/article>/)?.[0]||'';
    check(lead.includes(decode(item[3]).split(' — ')[1].trim()) && lead.includes(label),'real featured '+label);
  }
  modified.set('homepage_hero_mode','off'); await save(modified); home=await get('/');
  check(!home.body.includes('data-home-section="feature"'),'featured can be disabled independently');
} finally { await save(original); }
console.log(`${checks} homepage HTTP/settings checks passed; original settings restored.`);
