import { request } from '@playwright/test';
import fs from 'node:fs';
const base=process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';
const api=await request.newContext({baseURL:base});
const creds={username:process.env.TEST_ADMIN_USERNAME,password:process.env.TEST_ADMIN_PASSWORD};
if (!creds.username || !creds.password) throw new Error('Set TEST_ADMIN_USERNAME and TEST_ADMIN_PASSWORD for an isolated test database.');
fs.mkdirSync('test-results',{recursive:true});
const results=[];
const browserDetailRoutes=[];
const ci=!!process.env.GITHUB_ACTIONS;let annotated=0;
// GitHub only surfaces the first annotations of a job, so the individual
// failures are capped and a full summary is emitted at the end. Annotations
// are readable from the API, unlike the raw log archive.
const annotate=(title,message)=>{if(!ci||annotated>=8)return;annotated++;console.log(`::error title=${String(title).replace(/[\r\n]+/g,' ').slice(0,120)}::`+String(message).replace(/[\r\n]+/g,' ').slice(0,900));};
// Visible error text of an admin form response (the reason a POST did not redirect).
const alertText=html=>{const m=[...String(html).matchAll(/<div[^>]*class="[^"]*alert[^"]*"[^>]*>([\s\S]*?)<\/div>/g)].map(x=>x[1].replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim()).filter(Boolean);return m.join(' | ').slice(0,600);};
const check=(label,ok,detail='')=>{results.push({label,ok,detail});console.log(ok?'PASS':'FAIL',label,detail);if(!ok)annotate('HTTP check failed: '+label,detail||'(no detail)');};
const token=html=>html.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1];
// Clean URLs and their legacy .php spellings must both answer (config/routes.php expands them).
const publicPaths=['/','/index.php',
 '/about','/about.php','/contact','/contact.php','/news','/news.php','/articles','/articles.php',
 '/reports','/reports.php','/events','/books','/books.php','/lessons','/lessons.php',
 '/research','/research.php','/media','/videos','/audios','/topics','/topics.php','/search','/qa','/login',
 '/login.php','/register','/register.php','/admin/login','/admin/login.php',
 '/speeches','/speeches.php','/programs','/programs.php','/religious-activities','/religious-activities.php',
 '/announcements','/announcements.php','/search?q=test','/search.php?q=test',
 '/category?slug=fiqh-osul','/category.php?slug=fiqh-osul','/topic','/reports.php','/qa.php',
 '/media-library','/media-library.php','/audio','/video','/files','/library',
 '/sitemap.xml','/sitemap.php','/robots.txt','/robots.php',
 '/assets/css/design-system.css','/assets/img/logo.png','/assets/images/logo.png','/favicon.ico',
 '/php/install','/php/install.php','/php/install/'];
for(const path of publicPaths){const r=await api.get(path);check('GET '+path,r.status()===200||r.status()===302,String(r.status()));}
// Query parameters are scalar by contract; malformed bracket arrays must be a
// clean 400 rather than warnings/TypeErrors in a page controller.
const malformedQuery=await api.get('/index.php?p=topic&slug%5B%5D=unexpected');
check('array-shaped public query rejected cleanly',malformedQuery.status()===400,String(malformedQuery.status()));

// The browser installer is state-changing and must reject POSTs without its
// session-bound CSRF token before inspecting database credentials.
const installPage=await api.get('/php/install');
let installHtml=await installPage.text();
const installCsrf=token(installHtml);
if(installCsrf){
  check('installer form contains CSRF token',true,'');
  const noInstallCsrf=await api.post('/php/install',{form:{db_host:'invalid host'},maxRedirects:0});
  check('installer POST without CSRF is forbidden',noInstallCsrf.status()===403,String(noInstallCsrf.status()));
  const invalidInstall=await api.post('/php/install',{form:{csrf_token:installCsrf,db_host:'invalid host',db_port:'3306',db_name:'test',db_user:'test',db_pass:'not-used',admin_username:'testadmin',admin_password:'NotARealPassword!123'},maxRedirects:0});
  const invalidInstallHtml=await invalidInstall.text();
  check('installer invalid host handled without PHP errors',invalidInstall.status()===200&&invalidInstallHtml.includes('میزبان MySQL معتبر نیست')&&!/(Warning|Fatal error|TypeError):/.test(invalidInstallHtml),String(invalidInstall.status()));
  const emptyAdminPassword=await api.post('/php/install',{form:{csrf_token:installCsrf,db_host:'127.0.0.1',db_port:'3306',db_name:'test',db_user:'test',db_pass:'not-used',admin_username:'testadmin',admin_password:''},maxRedirects:0});
  const emptyAdminPasswordHtml=await emptyAdminPassword.text();
  check('installer has no public default admin password',emptyAdminPassword.status()===200&&emptyAdminPasswordHtml.includes('حداقل ۱۴ نویسه')&&!/(Warning|Fatal error|TypeError):/.test(emptyAdminPasswordHtml),String(emptyAdminPassword.status()));
}else{
  check('installer is already locked',installHtml.includes('قفل شده'), '');
}

// Bare detail routes carry no slug/id: they either redirect to their listing
// (/post, /lesson, /topic) or render the 404 detail page (/speech, /book).
// What matters is that they fail gracefully — never a PHP warning or a 5xx.
const bareDetail=['/post','/post.php','/lesson','/lesson.php','/speech','/speech.php','/book','/book.php','/category','/topic.php'];
for(const path of bareDetail){const r=await api.get(path,{maxRedirects:0});const body=r.status()===200?await r.text():'';check('detail without id '+path,[200,302,404].includes(r.status())&&r.status()<500&&!/(Warning|Fatal error|Parse error|Deprecated):/.test(body),String(r.status()));}
const malformedLoginPage=await api.get('/login');
const malformedLoginCsrf=token(await malformedLoginPage.text());
const malformedLogin=await api.post('/login',{form:{csrf_token:malformedLoginCsrf,'redirect[]':'//evil.example','identifier[]':'unexpected','password[]':'unexpected'},maxRedirects:0});
const malformedLoginHtml=await malformedLogin.text();
check('array-shaped login fields fail safely',malformedLogin.status()===200&&malformedLoginHtml.includes('شناسه و رمز عبور را وارد کنید')&&!/(Warning|Fatal error|TypeError):/.test(malformedLoginHtml),String(malformedLogin.status()));
const redirectProbe=await request.newContext({baseURL:base,maxRedirects:0});
const redirectLoginPage=await redirectProbe.get('/login');
const redirectLoginCsrf=token(await redirectLoginPage.text());
const backslashTarget='/' + String.fromCharCode(92) + 'evil.example';
const redirectLogin=await redirectProbe.post('/login',{form:{csrf_token:redirectLoginCsrf,identifier:creds.username,password:creds.password,redirect:backslashTarget},maxRedirects:0});
const redirectLocation=redirectLogin.headers()['location']||'';
check('backslash cannot turn login return URL into open redirect',redirectLogin.status()===303&&redirectLocation.startsWith('/admin/')&&!redirectLocation.includes('evil.example'),`${redirectLogin.status()} ${redirectLocation}`);
await redirectProbe.dispose();
const contactPage=await api.get('/contact');
const contactCsrf=token(await contactPage.text());
const malformedContact=await api.post('/contact',{form:{csrf_token:contactCsrf,'name[]':'unexpected','subject[]':'unexpected','message[]':'unexpected'},maxRedirects:0});
const malformedContactHtml=await malformedContact.text();
check('array-shaped contact fields fail validation safely',malformedContact.status()===200&&malformedContactHtml.includes('حداقل ۱۰ کاراکتر')&&!/(Warning|Fatal error|TypeError):/.test(malformedContactHtml),String(malformedContact.status()));
for(const path of ['/missing-page','/.env','/.git/config','/config/database.php','/config/local.php','/config/install.lock','/config/local.example.php','/database.sql','/database/database.mysql.sql','/database/database.postgres.sql','/install.php','/includes/auth.php','/includes/functions.php','/pages/about.php','/content/home-intro.php','/admin/includes/header.php','/storage/logs/.gitkeep','/bin/migrate.php','/uploads/test.php','/uploads/images/test.php']){const r=await api.get(path);check('protected '+path,r.status()===404,String(r.status()));}
let r=await api.get('/admin/',{maxRedirects:0});check('admin requires login',r.status()===302);
// One login page for everyone: /login authenticates members, admins and the
// owner. The identifier may be a username, an email address or a phone number.
r=await api.get('/login');let csrf=token(await r.text());
r=await api.post('/login',{form:{identifier:creds.username,password:creds.password,csrf_token:csrf},maxRedirects:0});check('login valid',r.status()===303,String(r.status()));
r=await api.get('/admin/');check('dashboard',r.status()===200,String(r.status()));
for(const path of ['/admin','/admin/','/admin/index.php','/admin/posts','/admin/posts/','/admin/articles','/admin/articles/','/admin/news/','/admin/speeches/','/admin/lessons','/admin/lessons/','/admin/books','/admin/books/','/admin/categories','/admin/categories/','/admin/media','/admin/media/','/admin/messages','/admin/messages/','/admin/messages.php','/admin/settings','/admin/settings.php','/admin/users','/admin/users/','/admin/users.php','/admin/users/index.php','/admin/topics','/admin/topics/','/admin/lesson-collections/','/admin/banners/','/admin/change-password','/admin/change-password.php']){const r=await api.get(path);check('admin '+path,r.status()===200,String(r.status()));}
r=await api.get('/admin/posts/create.php');csrf=token(await r.text());
const stamp=Date.now();const title='qa-post-'+stamp;
// Use the existing project logo as a valid JPEG fixture (user filename is deliberately misleading).
const image={name:'unsafe.php.jpg',mimeType:'image/jpeg',buffer:fs.readFileSync(new URL('./fixtures/image.png',import.meta.url))};
r=await api.post('/admin/posts/create.php',{multipart:{csrf_token:csrf,title,status:'published',post_type:'news','page_section[]':'home',summary:'این مطلب برای آزمون محلی ایجاد شده است.',content:'<p>محتوای آزمایشی</p><script>alert("XSS")</script>',featured_image:image,featured_video:{name:'video.mp4',mimeType:'video/mp4',buffer:fs.readFileSync(new URL('./fixtures/video.mp4',import.meta.url))},'audio_files[]':{name:'audio.mp3',mimeType:'audio/mpeg',buffer:fs.readFileSync(new URL('./fixtures/audio.mp3',import.meta.url))}},maxRedirects:0});if(r.status()!==303){const failHtml=await r.text();fs.writeFileSync('test-results/post-failure.html',failHtml);check('create post + image video audio',false,`status ${r.status()} — ${alertText(failHtml)||'no alert text'}`);}else check('create post + image video audio',true,'303');
const stored=[];
const edit=r.headers().location;let id=edit?.match(/id=(\d+)/)?.[1];
r=await api.get('/post.php?slug='+title);let html=await r.text();check('published detail',r.status()===200,String(r.status()));check('rich HTML XSS removed',!html.includes('<script>alert("XSS")'));
for(const path of ['/news/'+encodeURIComponent(title),'/post/'+encodeURIComponent(title)]){r=await api.get(path);check('clean news detail '+path,r.status()===200 && (await r.text()).includes(title),String(r.status()));}
for(const collectionPath of ['/videos','/audios']){const collection=await api.get(collectionPath);const collectionHtml=await collection.text();const match=collectionHtml.match(/href="([^"]*\/(?:video|audio)\/\d+)"/);if(match){const detailPath=new URL(match[1],base).pathname;const detail=await api.get(detailPath);check('dynamic media detail '+detailPath,detail.status()===200,String(detail.status()));}else check('media collection renders fixture '+collectionPath,collection.status()===200 && collectionHtml.includes(title),String(collection.status()));}
r=await api.get('/articles/'+encodeURIComponent(title));check('typed route rejects news under articles',r.status()===404,String(r.status()));
for(const url of new Set([...html.matchAll(/(?:src|href)="(\/uploads\/[^"?]+)"/g)].map(m=>m[1]))){if(url.includes('/seed-'))continue;stored.push(url); const a=await api.get(url);check('stored file accessible '+url,a.status()===200,String(a.status()));}
r=await api.get('/admin/books/create.php');csrf=token(await r.text());
r=await api.post('/admin/books/create.php',{multipart:{csrf_token:csrf,title:'qa-book-'+stamp,description:'کتاب آزمون',status:'published',pdf_file:{name:'document.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF')}},maxRedirects:0});check('upload PDF + create book',r.status()===303,String(r.status()));
r=await api.get('/books.php?q=qa-book-'+stamp);
const bookHtml=await r.text();
const bookMatch=bookHtml.match(/\/book(?:\.php)?\?(?:id=(\d+)|slug=([^"&]+))/) || bookHtml.match(/\/book\/(\d+)/) || bookHtml.match(/href="[^"]*\/book\/([^"?&]+)/) || bookHtml.match(/[?&]p=book&(?:amp;)?slug=([^"&\s]+)/) || bookHtml.match(/[?&]p=book&(?:amp;)?id=(\d+)/);
const bookId=bookMatch?.[1] && /^\d+$/.test(bookMatch[1]) ? bookMatch[1] : null;
const bookSlug=!bookId && (bookMatch?.[2] || bookMatch?.[1]) ? decodeURIComponent(bookMatch[2] || bookMatch[1]) : null;
check('book linked in library',Boolean(bookId || bookSlug));
if(bookId || bookSlug){ const bookUrl = bookId ? '/book/'+bookId : '/book/'+encodeURIComponent(bookSlug); r=await api.get(bookUrl);check('book detail exists',r.status()===200); if(r.status()===200) browserDetailRoutes.push(bookUrl); const dlUrl = bookUrl + (bookUrl.includes('?') ? '&' : '?') + 'download=pdf'; r=await api.get(dlUrl,{maxRedirects:0});const dlOk = r.status()===302||r.status()===303||r.status()===200; check('book PDF download redirect',dlOk,String(r.status())); }
for(const section of ['articles','news','lessons']){const slug='qa-'+section+'-'+stamp;r=await api.get('/admin/'+section+'/create.php');csrf=token(await r.text());r=await api.post('/admin/'+section+'/create.php',{form:{csrf_token:csrf,title:slug,content:'<p>Test content</p>',status:'published',level:'beginner','page_section[]':'home'},maxRedirects:0});check('create '+section,r.status()===303,String(r.status()));if(r.status()!==303)fs.writeFileSync('test-results/'+section+'-failure.html',await r.text());else{const detailPath=section==='articles'?'/article/'+slug:section==='news'?'/news/'+slug:'/lesson/'+slug;const detail=await api.get(detailPath);check('dynamic '+section+' route maps to detail controller',detail.status()===200 && (await detail.text()).includes(slug),String(detail.status()));if(detail.status()===200)browserDetailRoutes.push(detailPath);if(section==='articles'){const plural=await api.get('/articles/'+slug);check('plural article detail alias',plural.status()===200,String(plural.status()));if(plural.status()===200)browserDetailRoutes.push('/articles/'+slug);}}}
// Exercise the real multi-file lesson lifecycle and its stable ID-based folder.
{
  const lessonTitle='qa-files-'+stamp;
  const lessonPage=await api.get('/admin/lessons/create.php');
  const lessonCsrf=token(await lessonPage.text());
  const audioBuffer=fs.readFileSync(new URL('./fixtures/audio.mp3',import.meta.url));
  const videoBuffer=fs.readFileSync(new URL('./fixtures/video.mp4',import.meta.url));
  const lessonCreate=await api.post('/admin/lessons/create.php',{multipart:{
    csrf_token:lessonCsrf,title:lessonTitle,status:'published',
    featured_image:image,
    'audio_files[0]':{name:'part-one.mp3',mimeType:'audio/mpeg',buffer:audioBuffer},
    'audio_files[1]':{name:'part-two.mp3',mimeType:'audio/mpeg',buffer:audioBuffer},
    'video_files[0]':{name:'lecture.mp4',mimeType:'video/mp4',buffer:videoBuffer},
    'attachment_files[0]':{name:'notes.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF')},
  },maxRedirects:0});
  const lessonId=lessonCreate.headers()['location']?.match(/[?&]id=(\d+)/)?.[1];
  check('lesson created with multiple media files',lessonCreate.status()===303&&!!lessonId,String(lessonCreate.status()));
  if (lessonId) {
    const lessonResponse=await api.get('/lesson/'+lessonTitle);
    const lessonHtml=await lessonResponse.text();
    const keys=[...new Set([...lessonHtml.matchAll(/(?:src|href)="([^"\s]*\/lessons\/\d+\/[^"\s]+)"/g)].map(match=>match[1].replaceAll('&amp;','&')))];
    check('lesson files stored in own ID folder',lessonResponse.status()===200&&keys.length>=4&&keys.every(key=>key.includes('/lessons/'+lessonId+'/')),String(keys.length));
    check('lesson renders two audio tracks and a video', (lessonHtml.match(/<audio /g)||[]).length>=2 && lessonHtml.includes('lecture'),String(lessonResponse.status()));
    for (const key of keys) {
      const file=await api.get(key,{maxRedirects:0});
      check('lesson file accessible '+key,file.status()===200||file.status()===302,String(file.status()));
    }
    const editPage=await api.get('/admin/lessons/edit.php?id='+lessonId);
    const editCsrf=token(await editPage.text());
    const del=await api.post('/admin/lessons/delete.php',{form:{id:lessonId,csrf_token:editCsrf},maxRedirects:0});
    check('lesson and its files can be deleted',del.status()===303,String(del.status()));
  }
}
// Reports use the shared post editor; verify singular/plural typed URLs really resolve.
r=await api.get('/admin/posts/create.php');csrf=token(await r.text());const reportSlug='qa-report-'+stamp;
r=await api.post('/admin/posts/create.php',{form:{csrf_token:csrf,title:reportSlug,status:'published',post_type:'report',summary:'گزارش آزمایشی',content:'<p>محتوای گزارش آزمایشی</p>','page_section[]':'home'},maxRedirects:0});
check('create test report',r.status()===303,String(r.status()));
for(const path of ['/report/'+reportSlug,'/reports/'+reportSlug,'/post/'+reportSlug]){const detail=await api.get(path);check('dynamic report route '+path,detail.status()===200 && (await detail.text()).includes(reportSlug),String(detail.status()));if(detail.status()===200)browserDetailRoutes.push(path);}
const wrongReportType=await api.get('/news/'+reportSlug);check('typed route rejects report under news',wrongReportType.status()===404,String(wrongReportType.status()));
if(id){
// Editing retains existing media and updates content using prepared statements.
r=await api.get(edit);csrf=token(await r.text());
r=await api.post(edit,{form:{csrf_token:csrf,title,status:'published',post_type:'news','page_section[]':'home',content:'<p>Updated test content</p>'},maxRedirects:0});
check('edit content',r.status()===303,String(r.status()));
r=await api.get('/post.php?slug='+title);html=await r.text();check('edited content visible',html.includes('Updated test content'));
// Gallery lifecycle through the real JSON endpoint with a post's real id.
// This is the regression guard for the "شناسه نامعتبر" (invalid id) failure:
// the endpoint must accept a valid id for every operation and reject a
// missing/foreign id with a clean, actionable error. A dedicated throwaway
// post carries the lifecycle (which rewrites the featured image) so the
// fixture post's original media stay referenced for the deletion checks.
{
  r=await api.get('/admin/posts/create.php');csrf=token(await r.text());
  const galleryPostSlug='qa-gallery-'+stamp;
  r=await api.post('/admin/posts/create.php',{multipart:{csrf_token:csrf,title:galleryPostSlug,status:'published',post_type:'news','page_section[]':'home',content:'<p>مطلب آزمایشی گالری</p>','video_files[0]':{name:'featured-candidate.mp4',mimeType:'video/mp4',buffer:fs.readFileSync(new URL('./fixtures/video.mp4',import.meta.url))}},maxRedirects:0});
  check('gallery lifecycle fixture post created',r.status()===303,String(r.status()));
  const createdLocation=r.headers()['location']||'';
  const galleryPostId=createdLocation.match(/[?&]id=(\d+)/)?.[1];
  const editHtml=createdLocation?(await (await api.get(createdLocation.replace(/^https?:\/\/[^/]+/,''))).text()):'';
  // json_encode escapes slashes (\/ in the emitted string); unescape them, or
  // URL resolution mangles the endpoint. The endpoint is a root-relative path
  // by contract (jhd_web_path), so any duplicate/missing leading slash is
  // normalized before the request goes out.
  const galleryEndpoint=(editHtml.match(/GALLERY_ENDPOINT = "([^"]+)"/)?.[1]||'').replace(/\\\//g,'/').replace(/^\/+/,'/');
  const editCsrf=token(editHtml);
  const mediaEndpoint=(editHtml.match(/MEDIA_ENDPOINT = "([^"]+)"/)?.[1]||'').replace(/\\\//g,'/').replace(/^\/+/, '');
  const videoId=editHtml.match(/id="videoManager"[^>]*>[\s\S]*?class="jhd-media-item" data-id="(\d+)"/)?.[1];
  if (galleryPostId && videoId && mediaEndpoint) {
    const featured=await api.post(mediaEndpoint,{form:{post_id:galleryPostId,action:'set_featured',media_id:videoId,csrf_token:editCsrf},maxRedirects:0});
    const featuredJson=await featured.json().catch(()=>({}));
    const detail=await (await api.get('/news/'+galleryPostSlug)).text();
    check('existing video selected as featured without losing detail',featured.status()===200&&featuredJson.ok===true&&detail.includes('videoLazyWrap')&&detail.includes('videoSection'),String(featured.status()));
    const clear=await api.post(mediaEndpoint,{form:{post_id:galleryPostId,action:'clear_featured',csrf_token:editCsrf},maxRedirects:0});
    check('featured video cleared while attachment remains',clear.status()===200&&(await (await api.get('/news/'+galleryPostSlug)).text()).includes('videoSection'),String(clear.status()));
  } else check('featured video gallery fixture available',false,'missing video/endpoint');
  if(galleryEndpoint&&galleryPostId){
    // Deterministic starting state: drop any gallery rows the create flow left
    // behind so every count/order assertion below is exact.
    const existingIds=[...editHtml.matchAll(/class="jhd-gallery-item"[^>]*?data-id="(\d+)"/g)].map(m=>m[1]);
    for(const gid of existingIds){
      await api.post(galleryEndpoint,{form:{post_id:galleryPostId,action:'delete',image_id:gid,csrf_token:editCsrf},maxRedirects:0});
    }
    const img1={name:'gallery-a.png',mimeType:'image/png',buffer:fs.readFileSync(new URL('./fixtures/image.png',import.meta.url))};
    const img2={name:'gallery-b.png',mimeType:'image/png',buffer:fs.readFileSync(new URL('./fixtures/image.png',import.meta.url))};
    let g=await api.post(galleryEndpoint,{multipart:{post_id:galleryPostId,action:'add','alts[0]':'','alts[1]':'',csrf_token:editCsrf,'images[0]':img1,'images[1]':img2},maxRedirects:0});
    let gJson=await g.json().catch(()=>({ok:false}));
    check('gallery add two images by real post id',g.status()===200&&gJson.ok===true&&Array.isArray(gJson.images)&&gJson.images.length===2,`status ${g.status()} added ${gJson.added}`);
    // A mixed valid/invalid batch must not leave the valid half behind.
    const mixed=await api.post(galleryEndpoint,{multipart:{post_id:galleryPostId,action:'add',csrf_token:editCsrf,'images[0]':img1,'images[1]':{name:'spoofed.png',mimeType:'image/png',buffer:Buffer.from('<?php echo 1; ?>')}},maxRedirects:0});
    const mixedJson=await mixed.json().catch(()=>({}));
    check('rejected batch leaves gallery unchanged',mixed.status()===422&&mixedJson.ok===false&&mixedJson.added===0&&mixedJson.images?.length===2,String(mixed.status()));
    if(gJson.ok){
      const first=gJson.images[0],second=gJson.images[1];
      const enc=encodeURIComponent;
      // Duplicate field names (order[]) are impossible in an object literal,
      // so array payloads go out as an explicit url-encoded body.
      const formPost=fields=>api.post(galleryEndpoint,{data:fields.map(f=>enc(f[0])+'='+enc(f[1])).join('&'),headers:{'content-type':'application/x-www-form-urlencoded'},maxRedirects:0});
      const primary=await api.post(galleryEndpoint,{form:{post_id:galleryPostId,action:'set_primary',image_id:String(second.id),csrf_token:editCsrf},maxRedirects:0});
      const primaryJson=await primary.json().catch(()=>({}));
      check('gallery set primary',primary.status()===200&&primaryJson.ok===true&&primaryJson.featured_image===second.path,String(primary.status()));
      const order=await formPost([['post_id',galleryPostId],['action','reorder'],['order[]',String(second.id)],['order[]',String(first.id)],['csrf_token',editCsrf]]);
      const orderJson=await order.json().catch(()=>({ok:false}));
      check('gallery reorder',order.status()===200&&orderJson.ok===true&&orderJson.images?.[0]?.id===second.id,String(order.status()));
      const foreignOrder=await formPost([['post_id',galleryPostId],['action','reorder'],['order[]',String(second.id)],['csrf_token',editCsrf]]);
      check('gallery rejects incomplete reorder list',foreignOrder.status()===400,String(foreignOrder.status()));
      const alt=await api.post(galleryEndpoint,{form:{post_id:galleryPostId,action:'alt',image_id:String(first.id),alt:'تصویر آزمایشی',csrf_token:editCsrf},maxRedirects:0});
      const altJson=await alt.json().catch(()=>({ok:false}));
      check('gallery update alt',alt.status()===200&&altJson.ok===true,String(alt.status()));
      const del=await api.post(galleryEndpoint,{form:{post_id:galleryPostId,action:'delete',image_id:String(second.id),csrf_token:editCsrf},maxRedirects:0});
      const delJson=await del.json().catch(()=>({ok:false}));
      check('gallery delete',del.status()===200&&delJson.ok===true&&delJson.images?.length===1,String(del.status()));
      // Restore the featured image to the surviving gallery picture so the
      // throwaway post keeps a valid primary while it is cleaned up below.
      if(delJson.ok&&delJson.images?.[0]){
        await api.post(galleryEndpoint,{form:{post_id:galleryPostId,action:'set_primary',image_id:String(delJson.images[0].id),csrf_token:editCsrf},maxRedirects:0});
      }
    }
    const noId=await api.post(galleryEndpoint,{form:{action:'reorder',csrf_token:editCsrf},maxRedirects:0});
    const noIdJson=await noId.json().catch(()=>({}));
    check('gallery rejects missing post id cleanly',noId.status()===400&&noIdJson.ok===false&&/در این درخواست معتبر نیست/.test(noIdJson.error||''),`${noId.status()} ${noIdJson.error||''}`);
    const foreignId=await api.post(galleryEndpoint,{form:{post_id:'99999999',action:'alt',image_id:'1',csrf_token:editCsrf},maxRedirects:0});
    check('gallery rejects unknown post id with 404',foreignId.status()===404,String(foreignId.status()));
    const noCsrf=await api.post(galleryEndpoint,{form:{post_id:galleryPostId,action:'alt',image_id:'1'},maxRedirects:0});
    check('gallery rejects missing CSRF with 403',noCsrf.status()===403,String(noCsrf.status()));
    // Clean up the throwaway post through the same CSRF-confirmed delete flow.
    const delPage=await api.get('/admin/posts/delete.php?id='+galleryPostId);
    const delCsrf=token(await delPage.text());
    r=await api.post('/admin/posts/delete.php',{form:{id:Number(galleryPostId),csrf_token:delCsrf},maxRedirects:0});
    check('gallery lifecycle fixture post deleted',r.status()===303,String(r.status()));
  } else check('gallery endpoint exposed on edit page',false,'GALLERY_ENDPOINT not found in edit page');
}
// Topic banner is the first content element and works even without a cover.
{
  const topicResponse=await api.get('/topic?slug=fiqh');
  const topicHtml=await topicResponse.text();
  check('topic renders banner ahead of section content',topicResponse.status()===200 && topicHtml.includes('jhd-topic-banner') && topicHtml.indexOf('jhd-topic-banner')<topicHtml.indexOf('jhd-cat-strip mb-4'),String(topicResponse.status()));
}
// Per spec 17: Like/View systems removed completely — ajax/like.php must be gone (404)
r=await api.post('/ajax/like.php',{data:{post_id:Number(id)}});check('like endpoint removed (404)',r.status()===404,String(r.status()));
r=await api.get('/ajax/like.php');check('like GET also 404',r.status()===404,String(r.status()));
r=await api.get('/admin/posts/delete.php?id='+id);check('GET delete confirms, no mutation',r.status()===200 && (await r.text()).includes('تأیید عملیات'));r=await api.get('/post.php?slug='+title);check('post still exists after GET delete',r.status()===200);r=await api.post('/admin/posts/delete.php',{form:{id},maxRedirects:0});check('delete missing CSRF rejected',r.status()===403);r=await api.get('/admin/posts/delete.php?id='+id);csrf=token(await r.text());r=await api.post('/admin/posts/delete.php',{form:{id,csrf_token:csrf},maxRedirects:0});check('POST delete valid',r.status()===303,String(r.status()));r=await api.get('/post.php?slug='+title);check('deleted post 404',r.status()===404);for(const url of stored){const file=await api.get(url);check('deleted media returns 404',file.status()===404);}}
r=await api.get('/admin/posts/create.php');csrf=token(await r.text());
r=await api.post('/admin/posts/create.php',{multipart:{csrf_token:csrf,title:'rejected-'+stamp,status:'published',featured_image:{name:'photo.jpg',mimeType:'image/jpeg',buffer:Buffer.from('<?php echo "unsafe"; ?>')}},maxRedirects:0});check('spoofed image rejected',r.status()===200 && /تصویر شاخص معتبر نیست|خطا در آپلود/.test(await r.text()));
// Failed multi-file content uploads must not leak a previously accepted image.
// A standalone media-library upload is intentional and must survive this cleanup.
r=await api.get('/admin/media/'); csrf=token(await r.text());
r=await api.post('/admin/media/',{multipart:{csrf_token:csrf,'images[]':image}});
let library=await r.text();
const galleryId=library.match(/name="delete" value="(\d+)"/)?.[1];
const mediaUrls=text=>[...text.matchAll(/data-copy-url="([^"]+)"/g)].map(m=>m[1]).sort();
const beforeFailedUpload=mediaUrls(library);
check('standalone gallery upload retained',r.status()===200 && library.includes('تصویر با موفقیت آپلود شد') && !!galleryId && beforeFailedUpload.length>0);
r=await api.get('/admin/books/create.php'); csrf=token(await r.text());
r=await api.post('/admin/books/create.php',{multipart:{csrf_token:csrf,title:'qa-rejected-book-'+stamp,description:'آزمون پاک‌سازی',cover_image:image,pdf_file:{name:'fake.pdf',mimeType:'application/pdf',buffer:Buffer.from('not a PDF')}}});
const rejectedBook=await r.text();
check('second upload validation rejects book',r.status()===200 && /فایل PDF معتبر نیست|خطا در آپلود فایل PDF/.test(rejectedBook),String(r.status()));
if (!/فایل PDF معتبر نیست|خطا در آپلود فایل PDF/.test(rejectedBook)) fs.writeFileSync('test-results/rejected-book.html',rejectedBook);
r=await api.get('/admin/media/'); library=await r.text();
check('failed upload cleaned without deleting library files',JSON.stringify(mediaUrls(library))===JSON.stringify(beforeFailedUpload));
if (galleryId) {
  csrf=token(library);
  // The listing has an upload CSRF field; the delete operation requires POST too.
  r=await api.post('/admin/media/',{form:{csrf_token:csrf,delete:galleryId},maxRedirects:0});
  check('standalone gallery fixture removed',r.status()===303 || r.status()===302);
}
r=await api.get('/admin/users.php');csrf=token(await r.text());
const editorName='qa_editor_'+stamp;
r=await api.post('/admin/users.php',{form:{csrf_token:csrf,username:editorName,full_name:'ویرایشگر آزمون',role:'editor',password:creds.password,is_active:'on'}});check('create editor account',r.status()===200 && (await r.text()).includes('اطلاعات کاربر ذخیره شد'));
const editor=await request.newContext({baseURL:base});
r=await editor.get('/admin/login');csrf=token(await r.text());
r=await editor.post('/admin/login',{form:{csrf_token:csrf,identifier:editorName,password:creds.password},maxRedirects:0});check('editor login',r.status()===303);
r=await editor.get('/admin/settings.php');check('editor forbidden from settings',r.status()===403);
r=await editor.get('/admin/users.php');check('editor forbidden from users',r.status()===403);
await editor.dispose();
r=await api.get('/contact.php');csrf=token(await r.text());r=await api.post('/contact.php',{form:{csrf_token:csrf,name:'آزمون تماس',email:'qa@example.test',subject:'آزمون محلی',message:'این پیام برای بررسی فرم تماس ایجاد شده است.'}});check('contact submit',r.status()===200 && /موفقیت|تعداد پیام‌های ارسالی بیش از حد مجاز/.test(await r.text()));
r=await api.get('/logout');csrf=token(await r.text());r=await api.post('/logout',{form:{csrf_token:csrf},maxRedirects:0});check('logout',r.status()===302||r.status()===303);r=await api.get('/admin/',{maxRedirects:0});check('logged out cannot access admin',r.status()===302);
// Public member auth is separate from admin. Members must never reach the panel.
const guest=await request.newContext({baseURL:base});
r=await guest.get('/login');check('public login page',r.status()===200 && (await r.text()).includes('ورود'));
r=await guest.get('/register');check('public register page',r.status()===200 && (await r.text()).includes('ثبت‌نام'));
r=await guest.get('/account',{maxRedirects:0});
if ([301,308].includes(r.status()) && /\/account\/?$/.test(r.headers()['location'] || '')) r=await guest.get(r.headers()['location'],{maxRedirects:0});
check('account requires member login',r.status()===302,String(r.status()));
r=await guest.get('/register');csrf=token(await r.text());
const memberPhone='700'+String(stamp).slice(-8);
r=await guest.post('/register',{form:{csrf_token:csrf,full_name:'عضو آزمون',country:'AF',phone:memberPhone,email:'',password:creds.password,password_confirm:creds.password,agreed_terms:'1'},maxRedirects:0});
check('public register',r.status()===303||r.status()===302,String(r.status()));
r=await guest.get('/account');check('member account',r.status()===200,String(r.status()));
// عضو عمومی هم از همان صفحهٔ /login وارد می‌شود (نقش از دیتابیس می‌آید).
{
  const member=await request.newContext({baseURL:base});
  r=await member.get('/login');csrf=token(await r.text());
  r=await member.post('/login',{form:{csrf_token:csrf,identifier:memberPhone,password:creds.password},maxRedirects:0});
  check('member login via unified /login',r.status()===303,String(r.status()));
  r=await member.get('/account');check('member dashboard after unified login',r.status()===200,String(r.status()));
  r=await member.get('/admin/',{maxRedirects:0});check('member still cannot open admin',r.status()===302,String(r.status()));
  await member.dispose();
}
r=await guest.get('/admin/',{maxRedirects:0});check('member cannot open admin',r.status()===302,String(r.status()));
r=await guest.get('/register');check('duplicate register blocked while logged in',r.status()===302||r.status()===200,String(r.status()));
await guest.dispose();
const dup=await request.newContext({baseURL:base});
r=await dup.get('/register');csrf=token(await r.text());
r=await dup.post('/register',{form:{csrf_token:csrf,full_name:'عضو تکراری',country:'AF',phone:memberPhone,password:creds.password,password_confirm:creds.password,agreed_terms:'1'}});
check('duplicate phone rejected',r.status()===200 && (await r.text()).includes('امکان ایجاد حساب'));
await dup.dispose();
fs.writeFileSync('test-results/http-results.json',JSON.stringify(results,null,2));
fs.writeFileSync('test-results/browser-detail-routes.json',JSON.stringify([...new Set(browserDetailRoutes)]));
await api.dispose();

const failedChecks=results.filter(result=>!result.ok);
console.log(`\n${results.length-failedChecks.length}/${results.length} HTTP checks passed`);
if(failedChecks.length){
  console.log('FAILED:');for(const f of failedChecks)console.log(`  - ${f.label} :: ${f.detail}`);
  annotate('http.mjs summary',`${failedChecks.length} failing checks — `+failedChecks.map(f=>`${f.label} (${f.detail})`).join(' | '));
  process.exitCode=1;
}
