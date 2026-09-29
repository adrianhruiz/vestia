"""Slot smoke test: real OCR, matching, photo import, privacy and mobile UI.

Run on the VPS using /opt/inditex-catalog/.venv/bin/python. Creates one garment
per slot in an existing test account and removes only those test rows in finally.
"""
import asyncio
import json
import os
import pathlib
import subprocess
import tempfile
import uuid

from PIL import Image, ImageDraw, ImageFont
from playwright.async_api import async_playwright

SESSION = r'''
require '/home/saymon/php-projects/armario/slots/'.$argv[1].'/app/bootstrap.php';
$s=db()->prepare('SELECT id FROM users WHERE email=?');$s->execute([$argv[2]]);
$uid=$argv[2]==='__nonadmin__' ? db()->query('SELECT u.id FROM users u LEFT JOIN catalog_administrators a ON a.user_id=u.id WHERE a.user_id IS NULL LIMIT 1')->fetchColumn() : $s->fetchColumn();if(!$uid)exit(2);
session_id(bin2hex(random_bytes(24)));session_start();$_SESSION['user_id']=(int)$uid;
echo session_id();session_write_close();
'''
DESTROY = "session_name('vestia_session');session_id($argv[1]);session_start();session_destroy();"
CLEANUP = r'''
require '/home/saymon/php-projects/armario/slots/'.$argv[1].'/app/bootstrap.php';
$s=db()->prepare('SELECT g.id,g.image_path,g.thumb_path FROM garments g JOIN users u ON u.id=g.user_id WHERE u.email=? AND g.notes=?');$s->execute([$argv[2],$argv[3]]);
foreach($s->fetchAll() as $g){
 db()->prepare('DELETE FROM garments WHERE id=?')->execute([$g['id']]);
 foreach([$g['image_path'],$g['thumb_path']] as $path)if($path&&str_starts_with($path,'/uploads/'))@unlink(APP_ROOT.'/public'.$path);
}
'''


def php(code, *args):
    return subprocess.check_output(['runuser', '-u', 'www-data', '--', 'php', '-r', code, *args], text=True).strip()


async def main():
    with tempfile.TemporaryDirectory(prefix='vestia-label-browser-') as tmp:
        photo = pathlib.Path(tmp) / 'label.png'
        im = Image.new('RGB', (1000, 520), 'white')
        draw = ImageDraw.Draw(im)
        font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 48)
        for i, line in enumerate(['ZARA', 'ART. 1165/738/800', 'TALLA M']):
            draw.text((60, 65 + 125*i), line, fill='black', font=font)
        im.save(photo)
        async with async_playwright() as p:
            browser = await p.chromium.launch(headless=True, args=['--host-resolver-rules=MAP *.armario.89.116.30.213.sslip.io 127.0.0.1'])
            for slot, email in [('raul', 'raul@vestia.com'), ('adrian', 'adri@vestia.com')]:
                sid = php(SESSION, slot, email)
                marker = 'label-smoke-' + uuid.uuid4().hex
                host = slot + '.armario.89.116.30.213.sslip.io'
                context = await browser.new_context(viewport={'width': 390, 'height': 844})
                await context.add_cookies([{'name':'vestia_session','value':sid,'domain':host,'path':'/','secure':True}])
                page = await context.new_page()
                errors = []
                page.on('pageerror', lambda error: errors.append(str(error)))
                try:
                    await page.goto('https://'+host+'/', wait_until='domcontentloaded')
                    assert '0.0.7' in await page.locator('.app-version').inner_text()
                    await page.locator('#add-garment').click()
                    await page.locator('#label-photo').set_input_files(photo)
                    await page.wait_for_function("document.querySelector('#label-reference').value === '1165/738/800'", timeout=30000)
                    assert await page.locator('#label-brand').input_value() == 'Zara'
                    assert await page.locator('#label-size').input_value() == 'M'
                    await page.locator('#label-search').click()
                    await page.locator('.label-match').first.wait_for(timeout=30000)
                    await page.locator('.label-match').first.click()
                    assert await page.locator('[name=reference]').input_value() == '1165/738/800'
                    assert await page.locator('[name=color]').input_value() == 'Negro'
                    assert await page.locator('[name=size]').input_value() == 'M'
                    assert await page.locator('[name=use_catalog_image]').is_checked()
                    photo_token = await page.locator('[name=catalog_token]').input_value()
                    await page.wait_for_function("[...document.querySelectorAll('.label-match img')].some(i=>i.complete&&i.naturalWidth>0)", timeout=60000)
                    if await page.evaluate('document.documentElement.scrollWidth > innerWidth'):
                        await page.screenshot(path=f'/tmp/vestia-label-{slot}-overflow.png')
                        print(await page.evaluate("[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>innerWidth+1&&e.getBoundingClientRect().width>0).slice(0,15).map(e=>({tag:e.tagName,cls:e.className,width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right}))"), flush=True)
                        raise AssertionError('Mobile overflow')
                    await page.locator('#label-title').scroll_into_view_if_needed()
                    await page.screenshot(path=f'/tmp/vestia-label-{slot}-mobile.png')
                    await page.set_viewport_size({'width':1440,'height':1000})
                    await page.screenshot(path=f'/tmp/vestia-label-{slot}-desktop.png')
                    await page.locator('[name=notes]').fill(marker)
                    async with page.expect_response(lambda r:r.url.endswith('/api/garments') and r.request.method=='POST') as saving:
                        await page.locator('#garment-form button.full').click()
                    response = await saving.value
                    answer = await response.json()
                    assert response.status == 201, answer
                    await page.wait_for_function("document.querySelector('#garment-modal').hidden", timeout=15000)
                    garment = await page.evaluate("async id => (await (await fetch('/api/garments')).json()).items.find(x=>Number(x.id)===id)", answer['id'])
                    assert garment['image_path'].startswith('/uploads/'), garment
                    assert garment['reference'] == '1165/738/800'
                    assert garment['size'] == 'M'
                    assert garment['source_url'].startswith('https://www.zara.com/')
                    # The source is a local saved derivative, independent of the catalog session.
                    status = await page.evaluate("async path => (await fetch(path)).status", garment['image_path'])
                    assert status == 200
                    await page.locator('#add-garment').click()
                    assert await page.locator('[name=catalog_token]').input_value() == ''
                    await page.locator('#label-reference').fill('9999/999/999')
                    await page.locator('#label-search').click()
                    await page.wait_for_function("document.querySelector('#label-status').textContent.includes('No aparece')")
                    # Missing CSRF cannot trigger work; tokens cannot be guessed.
                    csrf_check = await page.evaluate("async()=> {const r=await fetch('/api/label-lookup',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({reference:'1165/738/800'})});return {status:r.status,body:await r.text()}}")
                    assert csrf_check['status'] == 403, csrf_check
                    assert await page.evaluate("async()=> (await fetch('/api/label-image?token=invalid')).status") == 404
                    anonymous = await browser.new_context()
                    stranger = await anonymous.new_page()
                    await stranger.goto('https://'+host+'/', wait_until='domcontentloaded')
                    assert await stranger.evaluate("async token=>(await fetch('/api/label-image?token='+token)).status", photo_token) == 401
                    await anonymous.close()
                    other_sid = php(SESSION, slot, '__nonadmin__')
                    other = await browser.new_context()
                    try:
                        await other.add_cookies([{'name':'vestia_session','value':other_sid,'domain':host,'path':'/','secure':True}])
                        other_page = await other.new_page()
                        await other_page.goto('https://'+host+'/api/label-image?token=invalid', wait_until='domcontentloaded')
                        assert await other_page.evaluate("async()=> (await fetch('/api/label-lookup',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({reference:'1165/738/800'})})).status") == 403
                        await other_page.goto('https://'+host+'/', wait_until='domcontentloaded')
                        assert await other_page.evaluate("async token=>(await fetch('/api/label-image?token='+token)).status", photo_token) == 404
                        assert await other_page.evaluate("async()=>(await fetch('/catalog.php')).status") == 403
                        found = await other_page.evaluate("async()=> {const r=await fetch('/api/label-lookup',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.APP.csrf},body:JSON.stringify({brand:'Zara',reference:'1165/738/800'})});return {status:r.status,data:await r.json()}}")
                        assert found['status'] == 200 and found['data']['items'], found
                    finally:
                        await other.close()
                        php(DESTROY, other_sid)
                    assert not errors, errors
                    print(json.dumps({'slot':slot,'ocr':'passed','lookup':'passed','photo_saved':'passed','mobile':'passed','no_match':'passed','csrf':'passed','non_admin_lookup':'passed','image_privacy':'passed','javascript_errors':errors}), flush=True)
                finally:
                    php(CLEANUP, slot, email, marker)
                    await context.close()
                    php(DESTROY, sid)
            await browser.close()


asyncio.run(main())
