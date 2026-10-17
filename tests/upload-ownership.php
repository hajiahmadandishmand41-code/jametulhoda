<?php
/** Pure contracts; no remote calls, grants or production database access. */
declare(strict_types=1);
putenv('APP_ENV=development');
putenv('SUPABASE_URL=https://contract-test.supabase.co');
require_once __DIR__.'/../includes/storage.php';
$checks=0;
function contract(bool $ok,string $label): void {
    global $checks;
    if(!$ok) throw new RuntimeException($label);
    $checks++; echo "PASS $label\n";
}
$relative='/object/upload/sign/site-media/staging/test.mp4?token=contract';
$full='https://contract-test.supabase.co/storage/v1'.$relative;
contract(supabaseSignedUploadUrl($relative)===$full,'REST relative URL resolved against Storage, never admin origin');
contract(supabaseSignedUploadUrl('/storage/v1'.$relative)===$full,'root-relative Storage URL does not duplicate base path');
contract(supabaseSignedUploadUrl($full)===$full,'absolute URL is idempotent');
contract(supabaseSignedUploadUrl('https://foreign.example'.$relative)==='','foreign upload origin rejected');
contract(supabaseSignedUploadUrl('//foreign.example'.$relative)==='','protocol-relative upload origin rejected');
contract(supabaseSignedUploadUrl('https://contract-test.supabase.co.attacker.example'.$relative)==='','origin prefix attack rejected');
$info=supabaseNormalizeObjectInfo(['size'=>13631488,'content_type'=>'video/mp4']);
contract(!empty($info['ok']) && $info['mime']==='video/mp4' && $info['size']===13631488,'REST snake_case object info parsed without guessing MIME');
contract(supabaseNormalizeObjectInfo(['metadata'=>['size'=>100,'mimetype'=>'image/png']])['mime']==='image/png','legacy metadata remains compatible');
contract(empty(supabaseNormalizeObjectInfo(['size'=>100])['ok']),'missing MIME never becomes a fabricated successful type');
contract(empty(supabaseNormalizeObjectInfo(['size'=>0,'content_type'=>'video/mp4'])['ok']),'empty object is not a completed upload');
$path='staging/contract.mp4';
$_SESSION=[];
contract(adoptDirectUpload($path,'video','posts')==='','unowned staging path rejected before any remote or DB request');
$_SESSION['direct_uploads'][$path]=['size'=>100,'kind'=>'video','expires'=>time()-1,'verified'=>true];
contract(adoptDirectUpload($path,'video','posts')==='','expired claim rejected');
$_SESSION['direct_uploads'][$path]=['size'=>100,'kind'=>'video','expires'=>time()+60,'verified'=>false];
contract(adoptDirectUpload($path,'video','posts')==='','unfinished upload rejected');
$_SESSION['direct_uploads'][$path]['verified']=true;
contract(adoptDirectUpload($path,'image','posts')==='','media kind cannot be changed after signing');
contract(storageKey('staging/../../secret.mp4')==='','traversal rejected');
echo "$checks upload ownership contracts passed; no live Supabase verification.\n";
