<?php
/** New isolated database; never modifies an existing database or users. */
putenv('APP_ENV=local'); putenv('DB_DRIVER=sqlite'); putenv('SESSION_DRIVER=files');
putenv('SQLITE_PATH=' . tempnam(sys_get_temp_dir(), 'jhd-user-directory-'));
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/identity.php';
$db=getDB();
$db->exec(file_get_contents(__DIR__.'/fixtures/schema.sqlite.sql'));
$db->exec(file_get_contents(__DIR__.'/fixtures/seed.sqlite.sql'));
$insert=$db->prepare("INSERT INTO users(full_name,email,phone,phone_normalized,country,country_code,password,role) VALUES(?,?,?,?,?,?,?,'user')");
for($i=0;$i<305;$i++) $insert->execute(['کاربر '.$i,'directory-'.$i.'@example.test','079'.str_pad((string)$i,7,'0',STR_PAD_LEFT),'+9379'.str_pad((string)$i,7,'0',STR_PAD_LEFT),'افغانستان','93','not-a-real-password']);
$checks=0;
function expectDirectory(bool $ok,string $label): void {
    global $checks; if(!$ok) {error_log('FAIL '.$label);throw new RuntimeException($label);} $checks++;
}
expectDirectory(jhd_count_filtered_users('user')===305,'all 305 registrations counted');
$all=[];
for($offset=0;$offset<305;$offset+=50) $all=array_merge($all,jhd_list_users('user',50,$offset));
expectDirectory(count($all)===305&&count(array_unique(array_column($all,'id')))===305,'all pages, no 300 account cutoff/duplicates');
foreach(['directory-304@example.test','0790000304','+93790000304'] as $search) {
    $rows=jhd_list_users('user',50,0,$search);
    expectDirectory(count($rows)===1,'search email/phone '.$search);
    expectDirectory(jhd_count_filtered_users('user',$search)===1,'matching pagination count');
}
expectDirectory(count(jhd_list_users('user',50,0,'افغانستان'))===50,'country search');
expectDirectory(jhd_count_filtered_users('user',"' OR 1=1 --")===0,'bound search values');
foreach($all as $row) expectDirectory(!isset($row['password'])&&$row['country']==='افغانستان'&&$row['phone']!==''&&$row['email']!=='','all safe profile fields retained');
echo "$checks user-directory checks passed (isolated SQLite).\n";
