"""Exercise settings forms with real sessions and CSRF in a disposable SQLite site."""

import base64
import http.cookiejar
import json
import os
import pathlib
import re
import signal
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request


repo = pathlib.Path(__file__).resolve().parents[1]
php = os.environ.get("APP_PHP", "/www/server/php/84/bin/php")
with tempfile.TemporaryDirectory(prefix="phpidc-settings-http-") as directory:
    root = pathlib.Path(directory)
    database = root / "acceptance.sqlite"
    database.touch()
    for folder in ["public", "framework/views", "framework/sessions", "framework/cache/data", "logs", "app/private"]:
        (root / folder).mkdir(parents=True)
    (root / "installed.lock").write_text("isolated settings acceptance")
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    base = f"http://127.0.0.1:{port}"
    password = "Isolated-" + os.urandom(12).hex() + "!"
    environment = dict(os.environ, SETTINGS_TEST_REPO=str(repo), SETTINGS_TEST_PUBLIC=str(root / "public"),
                       SETTINGS_TEST_PASSWORD=password, APP_ENV="local", APP_DEBUG="false", APP_URL=base,
                       APP_KEY="base64:" + base64.b64encode(os.urandom(32)).decode(),
                       APP_CONFIG_CACHE=str(root / "config.php"), APP_ROUTES_CACHE=str(root / "routes.php"),
                       DB_CONNECTION="sqlite", DB_URL="", DB_DATABASE=str(database),
                       LARAVEL_STORAGE_PATH=directory, VIEW_COMPILED_PATH=str(root / "framework/views"),
                       CACHE_STORE="file", SESSION_DRIVER="file", SESSION_SECURE_COOKIE="false",
                       QUEUE_CONNECTION="database", LOG_CHANNEL="single")
    bootstrap = "require getenv('SETTINGS_TEST_REPO').'/vendor/autoload.php';\n$app=require getenv('SETTINGS_TEST_REPO').'/bootstrap/app.php';\n$app->usePublicPath(getenv('SETTINGS_TEST_PUBLIC'));\n"
    console = root / "console.php"
    console.write_text("<?php\n" + bootstrap + "exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n")
    (root / "public/index.php").write_text("<?php\n" + bootstrap + "$app->handleRequest(Illuminate\\Http\\Request::capture());\n")
    router = root / "router.php"
    router.write_text("<?php\nrequire getenv('SETTINGS_TEST_PUBLIC').'/index.php';\n")

    def command(*arguments):
        result = subprocess.run([php, str(console), *arguments], cwd=repo, env=environment,
                                capture_output=True, text=True, timeout=60)
        if result.returncode:
            raise RuntimeError("Isolated console setup failed: " + result.stderr[-500:])

    command("migrate", "--force")
    fixture = root / "fixture.php"
    fixture.write_text("<?php\n" + bootstrap + r"""
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['operator'=>['settings-payment','settings-notification','settings-identity','upstream-operator'],
    'viewer'=>['settings-viewer'], 'customer'=>[]] as $name=>$roles) {
    $user=App\Models\User::factory()->create(['name'=>$name,'email'=>$name.'@example.test','password'=>getenv('SETTINGS_TEST_PASSWORD')]);
    foreach($roles as $role) app(App\Actions\AssignAdminRoleAction::class)->execute($user,$role,'Isolated settings HTTP fixture');
}
""")
    seeded = subprocess.run([php, str(fixture)], cwd=repo, env=environment, capture_output=True, text=True, timeout=30)
    if seeded.returncode:
        raise RuntimeError("Isolated settings fixture failed")
    log = open(root / "http.log", "w+")
    server = subprocess.Popen([php, "-d", "variables_order=EGPCS", "-S", f"127.0.0.1:{port}", "-t", str(root / "public"), str(router)],
                              cwd=root / "public", env=environment, stdout=log, stderr=log, start_new_session=True)
    checks = []
    try:
        for _ in range(50):
            try:
                urllib.request.urlopen(base + "/up", timeout=1).close()
                break
            except (OSError, urllib.error.URLError):
                time.sleep(0.1)
        else:
            raise RuntimeError("Isolated settings server did not start")

        def request(client, path, body=None, accept="text/html"):
            payload = None if body is None else urllib.parse.urlencode(body).encode()
            with client.open(urllib.request.Request(base + path, data=payload, headers={"Accept": accept}), timeout=20) as response:
                return response.read().decode(), response.status, response.geturl()

        def rejected(client, path, body, status):
            try:
                request(client, path, body, "application/json")
            except urllib.error.HTTPError as error:
                assert error.code == status, f"{path}: expected {status}, got {error.code}"
            else:
                raise AssertionError(f"{path}: unexpectedly accepted")

        def token(html):
            return re.search(r'name="_token" value="([^"]+)"', html).group(1)

        def login(name):
            client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            html, _, _ = request(client, "/login")
            request(client, "/login", {"_token": token(html), "email": name + "@example.test", "password": password})
            return client

        anonymous = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        _, _, destination = request(anonymous, "/admin/settings")
        assert destination.endswith("/admin/login"), "Anonymous settings redirected to " + destination
        operator = login("operator")
        html, status, _ = request(operator, "/admin/settings?tab=mail")
        assert status == 200 and 'action="/admin/settings/identity/mail"' in html
        csrf = token(html)
        rejected(operator, "/admin/settings/identity/mail", {"settings[mail.transport]": "log"}, 419)
        checks.append("real_csrf")
        body = {"_token": csrf, "settings[mail.transport]": "log", "settings[mail.username]": "PRIVATE-HTTP-USERNAME",
                "settings[mail.password]": "PRIVATE-HTTP-PASSWORD", "settings[mail.from_name]": "隔离测试主机"}
        html, status, destination = request(operator, "/admin/settings/identity/mail", body)
        assert status == 200 and destination.endswith("/admin/settings?tab=mail") and "配置已保存" in html
        assert "PRIVATE-HTTP" not in html
        with sqlite3.connect(database) as connection:
            original = connection.execute("select value from system_settings where `group`='mail' and `key`='password'").fetchone()[0]
        request(operator, "/admin/settings/identity/mail", {"_token": csrf, "settings[mail.password]": ""})
        with sqlite3.connect(database) as connection:
            assert connection.execute("select value from system_settings where `group`='mail' and `key`='password'").fetchone()[0] == original
        checks.append("save_and_blank_secret_retention")
        html, _, _ = request(operator, "/admin/settings/identity/mail", {"_token": csrf, "settings[mail.port]": "99999", "settings[mail.password]": "PRIVATE-HTTP-FAILED"})
        assert "PRIVATE-HTTP" not in html
        with sqlite3.connect(database) as connection:
            assert connection.execute("select value from system_settings where `group`='mail' and `key`='password'").fetchone()[0] == original
        checks.append("validation_does_not_flash_secret")
        viewer = login("viewer")
        viewer_html, status, _ = request(viewer, "/admin/settings?tab=mail")
        assert status == 200 and "PRIVATE-HTTP" not in viewer_html
        rejected(viewer, "/admin/settings/identity/mail", {"_token": token(viewer_html), "settings[mail.transport]": "log"}, 403)
        checks.append("read_only_settings_role")
        customer = login("customer")
        rejected(customer, "/admin/settings", None, 403)
        checks.append("customer_denied")
        for tab in ["payment", "mail", "sms", "kyc", "github", "google", "qq", "wechat", "telegram", "upstreams"]:
            html, status, _ = request(operator, "/admin/settings?tab=" + tab)
            assert status == 200 and "PRIVATE-HTTP" not in html
        checks.append("all_external_interface_tabs")
        html, _, _ = request(operator, "/admin/settings/payment", {"_token": csrf, "url": "https://pay.example.invalid", "pid": "1000",
                               "epay_key": "PRIVATE-HTTP-EPAY", "alipay": "1", "wechat": "0"})
        assert "支付配置已保存" in html and "PRIVATE-HTTP-EPAY" not in html
        html, _, _ = request(operator, "/admin/settings/payment", {"_token": csrf, "url": "not-a-url", "pid": "1000",
                               "epay_key": "PRIVATE-HTTP-FAILED-EPAY", "alipay": "1", "wechat": "0"})
        assert "PRIVATE-HTTP" not in html
        checks.append("epay_form_and_secret_error_redaction")
        html, _, _ = request(operator, "/admin/upstreams/sub2api/configure", {"_token": csrf, "name": "隔离远程连接",
                               "url": "https://cards.example.invalid", "api_secret": "PRIVATE-HTTP-SUB2API", "active": "1"})
        assert "Sub2API 连接已保存" in html and "PRIVATE-HTTP" not in html
        with sqlite3.connect(database) as connection:
            account_id, original_url, original_key = connection.execute("select id,base_url,encrypted_secret from upstream_apis where name='隔离远程连接'").fetchone()
        html, _, _ = request(operator, "/admin/upstreams/sub2api/configure", {"_token": csrf, "account_id": account_id, "name": "隔离远程连接",
                               "url": "https://other.example.invalid", "api_secret": "PRIVATE-HTTP-FAILED-SUB2API", "active": "1"})
        assert "已有连接不能更换站点地址" in html and "PRIVATE-HTTP" not in html
        with sqlite3.connect(database) as connection:
            assert connection.execute("select base_url,encrypted_secret from upstream_apis where id=?", [account_id]).fetchone() == (original_url, original_key)
        checks.append("sub2api_original_endpoint_retained")
        # The only explicit probe uses the log transport; there are no real counterparties.
        html, _, _ = request(operator, "/admin/settings/test/mail", {"_token": csrf})
        assert "已模拟测试，没有真实发送" in html
        checks.append("explicit_probe_simulation")
        with sqlite3.connect(database) as connection:
            audits = connection.execute("select after from audit_logs").fetchall()
            assert all("PRIVATE-HTTP" not in (entry[0] or "") for entry in audits)
        checks.append("safe_audit")
        print(json.dumps({"result": "PASS", "checks": checks, "database": "disposable SQLite", "real_notifications": 0, "supplier_mutations": 0}, ensure_ascii=False))
    finally:
        os.killpg(server.pid, signal.SIGTERM)
        server.wait(timeout=10)
        log.close()
