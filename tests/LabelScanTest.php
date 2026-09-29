<?php
declare(strict_types=1);
require_once APP_ROOT.'/app/Wardrobe.php';
require_once APP_ROOT.'/app/ProductLink.php';
require_once APP_ROOT.'/app/Images.php';
require_once APP_ROOT.'/app/LabelScan.php';

describe('Label recognition and reference matching');
test('preserves leading zeroes and accepts printed separators', function () {
    foreach (['0123/045/006','0123045006','0123 045 006','0123-045-006'] as $ref) same('0123/045/006',label_reference($ref));
    same('0123/045',label_reference('0123045'));
    foreach (['8412345678901','0123/045/006;id','12O3/045/006',str_repeat('1',50),''] as $ref) same(null,label_reference($ref));
});
test('extracts reference, brand and explicit size without confusing an EAN', function () {
    $label=label_parse_text("PULL & BEAR\nART: 0123/045/006\nTALLA M\n8412345678901\n29.99 EUR");
    same('Pull&Bear',$label['brand']);same('0123/045/006',$label['reference']);same('M',$label['size']);
    same('',label_parse_text('8412345678901')['reference']);
    same('',label_parse_text('ZARA HOME')['brand']);
});
test('keeps multiple references as choices and does not invent a size', function () {
    $label=label_parse_text("ZARA\n0123/045/006\n7890/123/800\nEUR 29.99");
    same('',$label['reference']);same(2,count($label['references']));same('',$label['size']);
    same('',label_parse_text("ZARA\n0123/045/006")['size']);
});
test('recognizes compact references only with a printed reference marker', function () {
    same('0123/045/006',label_parse_text('REF. 0123045006')['reference']);
    same('',label_parse_text('0123045006')['reference']);
});

function label_test_product(): array {
    return ['bundleProductSummaries'=>[
        ['detail'=>['colors'=>[
            ['id'=>'800','reference'=>'C00123045800-I2026','name'=>'Negro','sizes'=>[['name'=>'M'],['name'=>'L']]],
            ['id'=>'006','reference'=>'C00123045006-I2026','name'=>'Crudo','sizes'=>[['name'=>'S']]],
            ['id'=>'500','reference'=>'C07890123500-I2026','name'=>'Verde'],
        ]]],
    ], '_normalized'=>['media'=>[
        ['url'=>'https://static.zara.net/00123045800-r.jpg','name'=>'r1'],
        ['url'=>'https://static.zara.net/00123045006-p.jpg','name'=>'p'],
        ['url'=>'https://static.zara.net/00123045800-p.jpg','name'=>'p'],
    ]]];
}
test('bundles do not mix colors of different models and swatches do not become photos', function () {
    $raw=label_test_product();$colors=label_product_colors($raw,'0123/045');
    same(2,count($colors));falsy(isset($colors['500']));
    same(1,label_image_position($raw,'0123/045','800'));
    same(0,label_image_position($raw,'0123/045','006'));
    same(null,label_image_position($raw,'0123/045','999'));
});
test('catalog lookup requires exact model, brand and color and deduplicates variants', function () {
    if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) skip('PDO SQLite needed');
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE products(id INTEGER,brand TEXT,reference TEXT,name TEXT,raw_json TEXT,url TEXT,price REAL,currency TEXT,description TEXT,captured_at TEXT)');
    $insert=$db->prepare('INSERT INTO products VALUES(?,?,?,?,?,?,?,?,?,?)');
    foreach ([[1,'zara'],[2,'zara'],[3,'bershka']] as [$id,$brand]) $insert->execute([$id,$brand,'0123/045','CAMISA LINO',json_encode(label_test_product()),'https://www.'.$brand.'.com/es/camisa.html',29.99,'EUR','<p>Lino</p>','2026-09-29']);
    $found=label_catalog_matches($db,'0123/045/800','Zara');
    same(1,count($found));same('Negro',$found[0]['color']);same(['M','L'],$found[0]['sizes']);same('Lino',$found[0]['notes']);
    same(2,count(label_catalog_matches($db,'0123/045','Zara')));
    same(2,count(label_catalog_matches($db,'0123/045/800','')));
    same([],label_catalog_matches($db,'0123/045/999','Zara'));
    same([],label_catalog_matches($db,'0124/045','Zara'));
});
test('photo choices expire and cannot be used from another session', function () {
    $_SESSION['label_choices']=[];
    $offered=label_offer_matches([['product_id'=>12,'image_position'=>1,'name'=>'Camisa']]);
    $token=$offered[0]['token'];falsy(isset($offered[0]['product_id']));same(12,label_choice($token)['product_id']);
    same(null,label_choice('../etc/passwd'));
    $_SESSION['label_choices'][$token]['expires']=time()-1;same(null,label_choice($token));
    $_SESSION['label_choices']=[];same(null,label_choice($token));
});
test('local OCR reads a label image and rejects a disguised non-image', function () {
    if (!extension_loaded('gd') || !is_executable('/usr/bin/tesseract')) skip('GD and Tesseract needed');
    $file=tempnam(sys_get_temp_dir(),'label-test-');
    try {
        $image=imagecreatetruecolor(1000,500);$white=imagecolorallocate($image,255,255,255);$black=imagecolorallocate($image,0,0,0);imagefill($image,0,0,$white);
        $font='/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        if (!is_file($font)) skip('DejaVu font needed');
        foreach (['ZARA','ART. 0123/045/006','TALLA M'] as $i=>$line) imagettftext($image,38,0,65,110+$i*110,$black,$font,$line);
        imagepng($image,$file);imagedestroy($image);
        $label=label_read_image($file);same('0123/045/006',$label['reference']);same('Zara',$label['brand']);same('M',$label['size']);
        file_put_contents($file,'<?php echo "not an image";');
        try { label_read_image($file); throw new RuntimeException('Non-image accepted'); } catch (InvalidArgumentException) {}
    } finally { unlink($file); }
});
