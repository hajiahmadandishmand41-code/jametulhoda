import fs from 'node:fs';
fs.mkdirSync('test-results',{recursive:true});
const origin=(process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/,'');
// Runtime user content (uploads/) is intentionally not part of the repository and
// is expected to exist only where an operator provisioned it; the seed database
// references sample files that a fresh checkout does not ship. Route integrity is
// what this crawler checks, so uploaded media paths are recorded as skipped.
const seen=new Set(), queue=['/'], errors=[], skipped=[];
while(queue.length && seen.size<180) {
  const path=queue.shift();
  if(seen.has(path)) continue;
  seen.add(path);
  try {
    const response=await fetch(origin+path,{signal:AbortSignal.timeout(30000)});
    const isHtml=response.headers.get('content-type')?.includes('text/html');
    let html='';
    if(isHtml) html=await response.text();
    else if(response.body) {
      // Drain every response, including large assets: abandoned fetch bodies can
      // exhaust connections or block the single-worker PHP development server.
      for await (const chunk of response.body) { void chunk; }
    }
    const finalPath=new URL(response.url).pathname;
    if(response.status>=400) {
      // A redirect that lands on runtime uploads (e.g. a book download target) is
      // the same class of missing operator-provisioned file handled above.
      if(finalPath.startsWith('/uploads/')) { if(!skipped.includes(finalPath)) skipped.push(finalPath); continue; }
      errors.push({path,status:response.status}); continue;
    }
    for(const match of html.matchAll(/(?:href|src)="(\/[^"#]*|index\.php\?[^"#]*)"/g)) {
      let link=match[1].replaceAll('&amp;','&');
      if(!link.startsWith('/')) link='/'+link;
      if(link.startsWith('//') || link.startsWith('/admin/')) continue;
      if(link.startsWith('/uploads/')) { if(!skipped.includes(link)) skipped.push(link); continue; }
      if(!seen.has(link)) queue.push(link);
    }
  } catch(error) { errors.push({path,error:String(error)}); }
}
const result={checked:seen.size,skippedUploads:skipped.length,errors};
console.log(JSON.stringify(result,null,2));
fs.writeFileSync('test-results/link-results.json',JSON.stringify(result,null,2));
if(errors.length) process.exitCode=1;
