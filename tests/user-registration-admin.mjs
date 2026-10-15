/** Mutating integration test: isolated localhost fixture only, no real-user changes. */
import {request} from '@playwright/test';
import assert from 'node:assert/strict';
const base=process.env.TEST_BASE_URL||'http://127.0.0.1:8080';
assert.ok(['127.0.0.1','localhost','[::1]'].includes(new URL(base).hostname),'Never mutate production');
const admin=await request.newContext({baseURL:base});
const member=await request.newContext({baseURL:base});
const guest=await request.newContext({baseURL:base});
const editor=await request.newContext({baseURL:base});
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const token=s=>s.match(/name="csrf_token"\s+value="([^"]+)"/)?.[1];
const stamp=String(Date.now());
const full_name='کاربر ثبت‌نام آزمون '+stamp, email='registration-'+stamp+'@example.test';
const phone='079'+stamp.slice(-7), normalized='+93'+phone.slice(1), password='FixtureOnly123!@#';
async function login(context,identifier,pw) {
 const page=await context.get('/login');
 return context.post('/login',{form:{csrf_token:token(await page.text()),identifier,password:pw},maxRedirects:0});
}
try {
 const form=await member.get('/register');
 const registration=await member.post('/register',{form:{csrf_token:token(await form.text()),full_name,email,country:'AF',phone,password,password_confirm:password,agreed_terms:'1'},maxRedirects:0});
 check(registration.status()===303,'member registers with email AND phone/country');
 const loginResult=await login(admin,process.env.TEST_ADMIN_USERNAME||'admin',process.env.TEST_ADMIN_PASSWORD||'TestAdmin123!@#');
 check(loginResult.status()===303,'super administrator login');
 const list=await admin.get('/admin/users?q='+encodeURIComponent(email)); const html=await list.text();
 check(list.status()===200,'administrator sees registered member in search');
 const row=html.match(new RegExp(`<tr data-user-id="(\\d+)">(?:(?!<tr)[\\s\\S])*?${email.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')}[\\s\\S]*?<\\/tr>`));
 check(!!row,'member has a real database row');
 const id=row[1];
 for(const field of [full_name,email,'افغانستان',phone,normalized,'+93']) check(row[0].includes(field),'row independently shows '+field);
 const details=await admin.get('/admin/users?edit='+id); const detailHtml=await details.text();
 for(const field of [full_name,email,'افغانستان',phone,normalized,'پذیرش قوانین','تاریخ ثبت‌نام']) check(detailHtml.includes(field),'details show '+field);
 check(!detailHtml.includes(password)&&!/\$2[ayb]\$\d\d\$/.test(detailHtml),'no password or password hash disclosed');
 check(/no-store/.test(details.headers()['cache-control']||''),'personal details are never publicly cached');
 const saved=await admin.post('/admin/users',{form:{csrf_token:token(detailHtml),id,username:'',full_name,email,role:'user',is_active:'1',password:''},maxRedirects:0});
 check(saved.status()===303,'ordinary account editing succeeds');
 const reloaded=await admin.get('/admin/users?edit='+id); const after=await reloaded.text();
 check(after.includes(phone)&&after.includes(normalized)&&after.includes('افغانستان'),'editing name/role preserves registration phone/country');
 const publicList=await guest.get('/admin/users',{maxRedirects:0});
 check(publicList.status()===302&&!((await publicList.text()).includes(email)),'anonymous users cannot read registrations');
 const memberList=await member.get('/admin/users',{maxRedirects:0});
 check([302,403].includes(memberList.status())&&!((await memberList.text()).includes(email)),'ordinary members cannot read registrations');
 const editorLogin=await login(editor,'editor','TestAdmin123!@#');
 check(editorLogin.status()===303,'fixture content editor login');
 const denied=await editor.get('/admin/users?edit='+id,{maxRedirects:0});
 check(denied.status()===403&&!((await denied.text()).includes(email)),'content editors cannot read private user details');
 const injected=await admin.get('/admin/users?q='+encodeURIComponent("' OR 1=1 --"));
 check(injected.status()===200&&!(await injected.text()).includes(email),'prepared user search rejects SQL injection');
 console.log(`${checks} registration/admin privacy checks passed (isolated fixture).`);
} finally {await Promise.all([admin.dispose(),member.dispose(),guest.dispose(),editor.dispose()]);}
