<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/common.php';
require dirname(__DIR__) . '/src/AppleIdCardReport.php';

function card_check(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException('[Apple ID Card] ' . $message);
}

$manager = new RecordManager(db(), config()['DB_PREFIX']);
$manager->migrate();
$service = new AppleIdCardReport();
$before = $service->listing([]);
card_check($before['total'] >= 30, 'the existing record fixtures were retained');
card_check(count($before['rows'])===25, 'default first page contains 25 records');

$customer = db()->query('SELECT id FROM ' . table('customers') . ' WHERE mobile="09123456789" LIMIT 1')->fetchColumn();
card_check($customer !== false, 'test customer exists');
$add = db()->prepare('INSERT INTO ' . table('apple_records') . ' (customer_id,apple_email) VALUES (?,?)');
$add->execute([$customer, 'outdated@example.test']);
$recordWithValues = (int)db()->lastInsertId();
$add->execute([$customer, 'card-two@example.test']);
$recordWithLegacyEmail = (int)db()->lastInsertId();
$add->execute([$customer, 'hidden-card@example.test']);
$hiddenRecord = (int)db()->lastInsertId();

$byKey = array_column($manager->fields(), null, 'field_key');
$insertValue = db()->prepare('INSERT INTO ' . table('record_values') .
    ' (record_id,field_id,value_text) VALUES (?,?,?)');
$insertValue->execute([$recordWithValues, (int)$byKey['customer_name']['id'], 'نام کارت اختصاصی']);
$insertValue->execute([$recordWithValues, (int)$byKey['mobile']['id'], '09123456789']);
$insertValue->execute([$recordWithValues, (int)$byKey['apple_email']['id'], 'card-one@example.test']);
$manager->deleteRecord($hiddenRecord);

$all=$service->listing(['per_page'=>'25']);
card_check($all['total']===$before['total']+2, 'soft deleted records are not listed');
card_check(count($all['rows'])===25, 'records are actually paginated');
card_check($all['pages']>=2, 'a second page exists');
card_check($service->listing(['per_page'=>'50'])['per']===50, '50 rows per page allowed');
card_check($service->listing(['per_page'=>'100'])['per']===100, '100 rows per page allowed');
card_check(count($service->listing(['per_page'=>'100'])['rows'])===$all['total'], '100 item page contains all current fixtures');
card_check($service->listing(['per_page'=>'1000'])['per']===25, 'unauthorized page size falls back to 25');
card_check($service->listing(['page'=>9999])['page']===$all['pages'], 'out of range page clamped safely');
card_check(count($service->listing(['page'=>2])['rows'])>0, 'second page populated');

$byName=$service->listing(['q'=>'نام کارت اختصاصی']);
card_check($byName['total']===1, 'unified name search finds per-record customer name');
card_check((int)$byName['rows'][0]['id']===$recordWithValues, 'search returns the correct record');
$byPhone=$service->listing(['q'=>'۰۹۱۲۳۴۵۶۷۸۹', 'per_page'=>100]);
card_check($byPhone['total']>=2, 'mobile search accepts Persian digits');
$byEmail=$service->listing(['q'=>'card-one@example.test']);
card_check($byEmail['total']===1 && (int)$byEmail['rows'][0]['id']===$recordWithValues,
    'Apple ID search uses saved EAV value, not stale record email');
card_check($byEmail['rows'][0]['apple_id']==='card-one@example.test', 'correct Apple ID exposed');
card_check($byEmail['rows'][0]['customer_name']==='نام کارت اختصاصی','record-specific name exposed');
$byLegacy=$service->listing(['q'=>'card-two@example.test']);
card_check($byLegacy['total']===1 && (int)$byLegacy['rows'][0]['id']===$recordWithLegacyEmail,
    'legacy Apple ID column is searched when EAV missing');
card_check($service->listing(['q'=>'hidden-card@example.test'])['total']===0,
    'deleted record cannot appear even when searched by email');
card_check($service->listing(['q'=>'%'])['total']===0, 'LIKE percent is treated as literal');
card_check($service->listing(['q'=>"' OR 1=1 --"])['total']===0, 'SQL injection string returns no data');

$ascending=$service->listing(['q'=>'card-','sort'=>'apple_id','direction'=>'asc']);
$descending=$service->listing(['q'=>'card-','sort'=>'apple_id','direction'=>'desc']);
card_check($ascending['total']===2 && $descending['total']===2, 'sorting does not change match count');
card_check($ascending['rows'][0]['apple_id']==='card-one@example.test'
    && $descending['rows'][0]['apple_id']==='card-two@example.test',
    'Apple ID sort toggles correctly in MySQL');
foreach (['customer_name','mobile','apple_id'] as $key) {
    card_check($service->listing(['sort'=>$key,'direction'=>'asc'])['sort']===$key,
        'safe column sort '.$key);
}
$unsafe=$service->listing(['sort'=>'apple_id;DROP TABLE customers;','direction'=>'asc']);
card_check($unsafe['sort']==='id', 'untrusted sort identifiers are not interpolated into SQL');
try {
    $service->listing(['q'=>str_repeat('a',121)]);
    throw new RuntimeException('Long search should have been rejected.');
} catch (DomainException $expected) {}
card_check((int)db()->query('SELECT COUNT(*) FROM ' . table('customers'))->fetchColumn()>0,
    'attempted injection has not damaged database tables');

echo "[apple-card] per-record EAV, legacy fallback, 3-field live search, 25/50/100 paging, sorts and soft-delete security: PASS\n";
