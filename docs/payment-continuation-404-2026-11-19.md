# 继续支付校验失败后返回 404

日期：2026-11-19（Asia/Shanghai）。用户反馈下单后的“继续支付”返回 404。本轮只修错误返回目标和可读提示，不新增支付渠道、模拟成功或修改账单资金。

## 原因与修复

账单表单正确 POST 到 `/console/invoices/{id}/pay`，路由和控制器均存在。支付发起校验可能因支付渠道未配置、账单过期或状态不允许而抛出 ValidationException；此前 HTML 请求依赖框架默认的 `back()`。客户中心设置 `Referrer-Policy: no-referrer`，因此默认回跳依赖 session 中的上次 URL。浏览器对 `/.well-known/appspecific/com.chrome.devtools.json` 的 GET 探测也经过 session 中间件并保存该地址，虽然探测本身为 404；支付失败后就可能回跳到它，隐藏原始错误。

`CustomerSalesController::pay` 现在仅捕获支付校验异常：HTML 固定返回当前账单，携带具体字段错误；JSON 仍返回原 422 校验响应。授权失败、系统异常和网关调用没有被吞掉。支付渠道未配置和账单不可支付的提示改为中文。成功支付页面、网关接口与付款通知逻辑保持。

原预览日志在北京时间 11:47:00 记录 GET 账单 #3 及浏览器探测，11:47:09 记录 POST 支付后又 GET 探测地址；11:47:22 的原用户 session 中 `_previous.url` 正是该探测地址。请求序列、session 与框架保存上次 URL 的行为吻合。

北京时间 11:55 复核：最新账单 #3 仍为 Unpaid，待付 16.00 CNY，12:17 到期；支付尝试为 0，支付渠道配置为空。这说明没有生成网关支付请求，修正回跳之后仍须由管理员配置真实支付渠道才能在线付款。本轮不猜测或借用其他项目的商户配置，不执行真实支付或补记付款。

## 验证

新增 `tests/Feature/PaymentContinuationTest.php`，专项 9 passed / 161 assertions：先打开账单，再发起浏览器探测 GET，严格确认 session 上次 URL 被覆盖，在无 Referer 的情况下继续支付；应回到原账单并显示校验错误。另覆盖 JSON 422、他人账单 403、已付款/Expired/超过截止时间的 Unpaid 账单、已配置网关的正常支付链接、缺失账单和 GET 支付路径不变。错误分支严格验证账单原行不变、钱包不变、零支付尝试/资金流水/交付任务/HTTP；正常支付分支使用隔离 HTTP 替身，验证尝试先提交、HTTP 在事务外及账单仍未付。

已有支付专项 75 passed / 582 assertions。独立交叉审另复现旧 Handler 回跳探测地址、新 HTML 回原账单、JSON 原异常对应 422 及真实 Action 越权 403，无阻断项。

完整门禁：1536 passed / 12765 assertions（94.20 秒），PHPStan 0 errors，Pint PASS；三个变更 PHP 文件语法检查及 `git diff --check` 通过。原预览 `/up` 与 `/login` 均 HTTP 200，原 PHP 8.4 服务进程及四个预览子进程保持，业务任务为 0。本轮无 schema 变更，无需迁移。预览只做只读诊断，未点击真实支付，未改用户账单、账号或支付配置，未启动消费者。

日志：`/tmp/laravel13idc-continue-payment-targeted.log`、`/tmp/laravel13idc-payment-continuation-{pest,phpstan,pint}.log`。私有脱敏运行证据：`/root/.local/state/laravel13idc/preview-performance-c5__2nj8/payment-404-session-contamination-diagnosis.json`（0600），仅保留相关路径、时间与计数，不含整 session、密码或密钥。

IMPLEMENTED: HTML 继续支付校验失败固定返回当前账单，显示具体原因。
NOT DONE: 配置真实支付商户和实际收款不在本轮范围。
DECISIONS: 保留 JSON、权限及金融契约；不扩展 GET 支付或伪支付路径。
CONCERNS: 当前预览未配置支付渠道，不能实际在线收款。
GATES: pest 1536 passed / 12765 assertions | phpstan 0 errors | pint clean。
SPEC CASES: 0。
