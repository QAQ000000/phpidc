# Isolated CMS + Sub2API acceptance; --preview keeps only the temporary server open for browser review.
import argparse, xml.etree.ElementTree as ET
import base64, hashlib, http.cookiejar, http.server, json, os, pathlib, re, signal, socket, sqlite3, subprocess, tempfile, threading, time, urllib.parse, urllib.request
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--preview',action='store_true',help='Wait for Enter after acceptance so local browser review can run')
args=parser.parse_args()
repo=pathlib.Path(__file__).resolve().parents[1]
def freeport():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); return sock.getsockname()[1]
with tempfile.TemporaryDirectory(prefix='laravel13idc-http-sale-') as tmp:
    root=pathlib.Path(tmp); db=root/'sale.sqlite'; db.touch()
    for folder in ['framework/views','framework/sessions','framework/cache/data','logs','app/private']:
        (root/folder).mkdir(parents=True)
    (root/'installed.lock').write_text('isolated acceptance')
    webport=freeport(); base=f'http://127.0.0.1:{webport}'
    # Ignore deployment config/route caches and connection URL overrides.
    env=dict(os.environ, LOCAL_CARD_TEST_REPO=str(repo), APP_ENV='local', APP_DEBUG='false', APP_URL=base, APP_KEY='base64:'+base64.b64encode(os.urandom(32)).decode(), APP_CONFIG_CACHE=str(root/'config.php'), APP_ROUTES_CACHE=str(root/'routes.php'), DB_CONNECTION='sqlite', DB_URL='', DB_DATABASE=str(db), DB_QUEUE_CONNECTION='sqlite', DB_QUEUE_TABLE='jobs', LARAVEL_STORAGE_PATH=tmp, CACHE_STORE='file', SESSION_DRIVER='file', SESSION_SECURE_COOKIE='false', QUEUE_CONNECTION='database', LOG_CHANNEL='single')
    def cli(*args):
        run=subprocess.run(['php','artisan',*args],cwd=repo,env=env,capture_output=True,text=True,timeout=40)
        if run.returncode: raise RuntimeError(run.stdout+run.stderr)
        return run.stdout
    cli('migrate','--force')
    cli('cards:create-product','Sub2API 充值卡','10.00','--driver=sub2api','--reason=Isolated acceptance')
    inventory=root/'keys.txt'; inventory.write_text('SUB2API-ACCEPTANCE-CARD\n')
    cli('cards:import','1',str(inventory),'--reason=Isolated inventory')
    assert 'Imported 0' in cli('cards:import','1',str(inventory),'--reason=Idempotency check')
    description=root/'product.txt';description.write_text('为已有 Sub2API 账号准备的充值卡。\n购买后在客户中心领取卡密，并在所属服务站点兑换。',encoding='utf-8')
    cli('products:describe','1',str(description),'--reason=Isolated product presentation')
    cli('cards:create-product','数字服务激活码','24.00','--reason=Isolated catalog')
    extra=root/'extra.txt';extra.write_text('EXTRA-CARD\n')
    cli('cards:import','2',str(extra),'--reason=Isolated catalog inventory')
    cli('cms:category','help','guides','使用指南','--reason=Isolated CMS')
    guide=root/'guide.md';guide.write_text('## 三步开始使用\n\n1. 选择适合的产品并完成付款。\n2. 在客户中心领取属于自己的卡密。\n3. 前往所属服务站点兑换，妥善保管卡密。\n\n## 订单与交付\n\n付款后若暂未交付，请保留账单并联系管理员。',encoding='utf-8')
    cli('cms:article','1','recharge','API 充值卡使用指南',str(guide),'--publish','--reason=Isolated publish')
    cli('cms:article','1','draft','PRIVATE-DRAFT',str(guide),'--reason=Isolated draft')
    cli('cms:category','news','updates','平台动态','--reason=Isolated CMS')
    news=root/'news.md';news.write_text('## 从发现产品到领取服务\n\n浏览产品与使用指南，核对账单，再在客户中心查看订单与交付结果。',encoding='utf-8')
    cli('cms:article','2','store-launch','产品商城与帮助中心上线',str(news),'--publish','--reason=Isolated publish')
    received=[]
    class Gateway(http.server.BaseHTTPRequestHandler):
        def do_POST(self):
            params={k:v[0] for k,v in urllib.parse.parse_qs(self.rfile.read(int(self.headers['Content-Length'])).decode()).items()}
            with sqlite3.connect(db) as connection:
                attempt=connection.execute('select out_trade_no from payment_attempts where out_trade_no=?',(params['out_trade_no'],)).fetchone()
            assert attempt, 'Attempt was not committed before HTTP'
            assert params['money']=='10.00'
            signed={k:v for k,v in params.items() if k not in ['sign','sign_type'] and v!=''}
            expected=hashlib.md5(('&'.join(k+'='+signed[k] for k in sorted(signed))+'local-test-secret').encode()).hexdigest()
            assert params['sign']==expected
            received.append(params)
            result=json.dumps({'code':1,'payurl':base+'/payment-simulator'}).encode()
            self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(result)
        def log_message(self,*args): pass
    gateway=http.server.ThreadingHTTPServer(('127.0.0.1',0),Gateway)
    threading.Thread(target=gateway.serve_forever,daemon=True).start()
    config=root/'configure.php';config.write_text("<?php\nrequire getenv('LOCAL_CARD_TEST_REPO').'/vendor/autoload.php';\n$app=require getenv('LOCAL_CARD_TEST_REPO').'/bootstrap/app.php';\n$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();\n$app->make(App\\Actions\\ConfigureEpayAction::class)->execute('http://127.0.0.1:"+str(gateway.server_port)+"', 'local-merchant', 'local-test-secret', 'Isolated test gateway');\n")
    subprocess.run(['php',str(config)],cwd=repo,env=env,check=True,capture_output=True)
    logs=open(root/'http.log','w+')
    server=subprocess.Popen(['php','-d','variables_order=EGPCS','-S',f'127.0.0.1:{webport}','-t',str(repo/'public'),str(repo/'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],cwd=repo/'public',env=env,stdout=logs,stderr=logs,start_new_session=True)
    try:
        for _ in range(50):
            try: urllib.request.urlopen(base+'/up',timeout=1).read();break
            except Exception: time.sleep(.1)
        else: raise RuntimeError('Temporary HTTP server did not start')
        def client(): return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        buyer=client()
        def request(opener,path,body=None,accept='text/html'):
            data=None if body is None else urllib.parse.urlencode(body).encode()
            with opener.open(urllib.request.Request(base+path,data=data,headers={'Accept':accept}),timeout=20) as response:
                return response.read().decode(),response.status,response.headers
        def token(html): return re.search(r'name="_token" value="([^"]+)"',html).group(1)
        for path in ['/', '/store', '/news', '/help', '/help/guides/recharge']:
            public, status, _ = request(buyer, path)
            assert status == 200 and 'PRIVATE-DRAFT' not in public, path
        with sqlite3.connect(db) as connection:
            product_slug, category_slug = connection.execute('select p.slug,c.slug from products p join categories c on c.id=p.category_id where p.id=1').fetchone()
        product_path=f'/store/{category_slug}/{product_slug}'
        product_html,_,_=request(buyer,product_path)
        assert '10.00' in product_html and 'SUB2API-ACCEPTANCE-CARD' not in product_html
        markdown,_,headers=request(buyer,'/help/guides/recharge.md')
        assert headers['Content-Type'].startswith('text/markdown') and markdown.startswith('# API 充值卡使用指南')
        negotiated,_,_=request(buyer,'/help/guides/recharge',accept='text/markdown')
        assert markdown==negotiated and 'Accept' in headers['Vary']
        sitemap,_,_=request(buyer,'/sitemap.xml'); ET.fromstring(sitemap)
        llms,_,_=request(buyer,'/llms.txt')
        assert '/help/guides/recharge.md' in llms and product_path in sitemap
        assert '/draft' not in sitemap+llms and '/console/' not in llms
        for path in ['/help/guides/draft', '/help/guides/draft.md']:
            try: request(buyer,path)
            except urllib.error.HTTPError as error: assert error.code==404
            else: raise AssertionError('Draft exposed over HTTP')
        login,_,_=request(buyer,'/login')
        html,_,_=request(buyer,'/register',{'_token':token(login),'name':'Acceptance Buyer','email':'acceptance@example.test','password':'AcceptancePassword123!','password_confirmation':'AcceptancePassword123!'})
        csrf=token(html)
        checkout,status,_=request(buyer,'/checkout',{'request_id': '01KAD000000000000000000001', '_token':csrf,'product_id':1,'billing_cycle':'onetime','gateway':'epay_wechat'},'application/json')
        assert status==201, (status,checkout[:1200])
        invoice=json.loads(checkout)['invoice_id']
        payment,status,_=request(buyer,f'/console/invoices/{invoice}/pay',{'_token':csrf},'application/json')
        assert json.loads(payment)['success'] and len(received)==1
        order=json.loads(payment)['out_trade_no']
        payload={'pid':'local-merchant','type':'wxpay','out_trade_no':order,'trade_no':'ISOLATED-TRADE','money':'10.00','trade_status':'TRADE_SUCCESS'}
        payload['sign']=hashlib.md5(('&'.join(k+'='+payload[k] for k in sorted(payload))+'local-test-secret').encode()).hexdigest().upper()
        ack,_,_=request(buyer,'/api/payments/epay_wechat/notify',payload)
        assert ack=='success'
        ack,_,_=request(buyer,'/api/payments/epay_wechat/notify',payload)
        with sqlite3.connect(db) as connection:
            assert connection.execute('select status from invoices').fetchone()[0]=='Paid'
            assert connection.execute('select count(*) from fulfillment_tasks').fetchone()[0]==1
            assert connection.execute('select count(*) from payment_callbacks').fetchone()[0]==1
            assert connection.execute('select count(*) from jobs').fetchone()[0]==1
            instance=connection.execute('select instance_id from invoices').fetchone()[0]
        pending,_,_=request(buyer,f'/console/instances/{instance}')
        assert 'SUB2API-ACCEPTANCE-CARD' not in pending
        cli('queue:work','database','--stop-when-empty','--tries=3','--timeout=180')
        delivered,_,headers=request(buyer,f'/console/instances/{instance}')
        assert 'SUB2API-ACCEPTANCE-CARD' in delivered and 'no-store' in headers['Cache-Control']
        with sqlite3.connect(db) as connection:
            assert connection.execute('select status from fulfillment_tasks').fetchone()[0]=='success'
            assert connection.execute('select status from redemption_codes where product_id=1').fetchone()[0]=='sold'
            assert connection.execute("select count(*) from redemption_codes where status='sold'").fetchone()[0]==1
            assert 'SUB2API-ACCEPTANCE-CARD' not in connection.execute('select code from redemption_codes where product_id=1').fetchone()[0]
            assert 'SUB2API-ACCEPTANCE-CARD' not in connection.execute('select encrypted_credentials from instances').fetchone()[0]
            assert connection.execute('select count(*) from jobs').fetchone()[0]==0
        other=client();login,_,_=request(other,'/login')
        request(other,'/register',{'_token':token(login),'name':'Other Buyer','email':'other@example.test','password':'OtherPassword123!','password_confirmation':'OtherPassword123!'})
        try: request(other,f'/console/instances/{instance}')
        except urllib.error.HTTPError as error: assert error.code==403
        else: raise AssertionError('Another buyer accessed card credentials')
        print('PASS: real HTTP registration and CSRF-protected checkout; committed attempt visible to simulated gateway before HTTP response.')
        print('PASS: signed callback replay produced exactly one Paid invoice, one callback, one database queue job.')
        print('PASS: separate queue worker delivered exactly one encrypted card; owner-only page returns no-store; intruder gets 403.')
        print('PASS: real CMS routes, Markdown negotiation, valid sitemap, public-only llms and draft exclusion.')
        print('ISOLATION: temporary SQLite, storage, app key, gateway and HTTP server; no business data or real payment provider used.')
        if args.preview:
            print('PREVIEW_URL='+base,flush=True)
            print('PRODUCT_URL='+base+product_path,flush=True)
            input('Temporary preview is ready; press Enter to close it. ')
    finally:
        os.killpg(server.pid,signal.SIGTERM)
        try: server.wait(timeout=5)
        except subprocess.TimeoutExpired: os.killpg(server.pid,signal.SIGKILL);server.wait()
        gateway.shutdown();gateway.server_close();logs.close()
