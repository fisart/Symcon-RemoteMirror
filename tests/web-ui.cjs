'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.argv[2] || 'playwright');
(async () => {
    const browser = await chromium.launch({headless:true, executablePath:process.argv[3] || undefined});
    let checks = 0;
    try {
        for (const width of [390, 1440]) {
            const page = await browser.newPage({viewport:{width,height:900}});
            const errors = []; page.on('pageerror', error => errors.push(error.message));
            const row = {ObjectID:20001,Folder:'Fixture',Name:'Hall sensor',LocalRootID:10001,Active:true,FullHistory:false,Immediate:false,Action:false,Delete:false};
            let config = {Properties:{SyncEnabled:true,LocalServerKey:'site',Roots:'[]',Targets:'[]'},Attributes:{SyncListCache:JSON.stringify([row])}};
            let revision = 1;
            await page.route('**/*', async route => {
                const request = route.request(), url = new URL(request.url());
                if(url.hostname!=='remote.test') return route.abort();
                if (request.method()==='POST') {
                    const data=request.postDataJSON();assert.equal(data.csrf,'fixture');
                    if(data.action==='pause'){config.Properties.SyncEnabled=false;revision++;}
                    if(data.action==='save'){assert.equal(data.revision,String(revision));config=data.config;revision++;}
                    return route.fulfill({json:{ok:true,message:data.action}});
                }
                if(url.searchParams.get('action')==='status') return route.fulfill({json:{ok:true,version:'1.14.0',config,selections:[row],revision:String(revision),
                    csrf:'fixture',delivery:{lanes:[{folder:'Fixture',lane:1,normalPending:0,historyPending:2,historyBytes:812}],retry:{}},traffic:{},debug:'debug fixture'}});
                return route.fulfill({contentType:'text/html',body:fs.readFileSync(path.join(__dirname,'../RemoteSync/web.html'),'utf8')});
            });
            await page.goto('https://remote.test/hook/remote_sync_321');
            await page.getByText('Version 1.14.0 · Capture enabled').waitFor();checks++;
            assert.equal(await page.locator('#lanes td').last().textContent(),'812');checks++;
            await page.getByRole('button',{name:'Pause capture'}).click();
            await page.getByText('Version 1.14.0 · Capture paused').waitFor();checks++;
            await page.getByLabel('FullHistory Hall sensor (20001)').check();
            await page.getByRole('button',{name:'Save configuration'}).click();
            await page.waitForFunction(()=>document.getElementById('feedback').textContent==='save');
            assert.equal(JSON.parse(config.Attributes.SyncListCache)[0].FullHistory,true);checks++;
            await page.getByLabel('Filter').fill('unknown');
            assert.equal(await page.locator('#rows tr').count(),0);checks++;
            assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);checks++;
            assert.deepEqual(errors,[]);checks++;
            await page.close();
        }
    } finally { await browser.close(); }
    console.log('PASS browser: '+checks+' checks across mobile and desktop widths');
})().catch(error=>{console.error(error);process.exitCode=1;});
