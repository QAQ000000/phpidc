# 本地卡密销售闭环

本轮完成本地卡密的商品创建、库存导入、客户注册登录、下单、纯钱包或组合付款、易支付发起与验签回调、异步发卡、本人领取，以及过期解冻和任务恢复。它是本地卡密的最小可运行闭环；不是整个 Phase 1 的完成声明。当前客户页为 Blade 页面，管理操作通过受信任的服务器命令执行，尚未提供 Filament 管理面板。

## 运行前提

沿用已安装环境的数据库和 APP_KEY。Web 安装器仍为 Phase 0 占位入口，本轮没有伪造安装成功，也没有在实际环境创建 installed.lock。已有安装门禁保持有效。

运行环境需要项目已迁移完成、有效的 `storage/installed.lock`、可写 storage，以及持续运行的队列与 scheduler。外部支付需要设置正确的 APP_URL，让网关能访问回调地址。全局支付凭据存入 `system_settings`，不增加支付相关 `.env` 键。

以下命令需在项目目录由受信任的服务器操作员执行。命令的权限来源是服务器账户；审计使用 `actor_type=system` 并要求原因，不等同于客户拥有管理权限。所有写库仍由 Action 完成。明文卡密文件应保存在 Web 根目录之外并限制文件权限。

```bash
php artisan cards:create-product '充值卡' '10.00' --setup-fee=0.00 --currency=CNY --reason='首次上架'
# 使用上一条输出的 product ID；每行一张卡，重复导入不会改变已售库存。
php artisan cards:import 1 /secure/path/cards.txt --reason='导入首批库存'

# 密钥通过交互输入，不放在命令行参数中；数据库加密保存，审计不记录明文密钥。
php artisan payments:configure-epay https://pay.example.com MERCHANT_ID --reason='配置支付渠道'
```

修改支付配置后重启长期存活的 queue/Octane worker，使其重新读取 SettingService 的进程内缓存。APP_KEY 不可随意更换。

用户从 `/login` 注册或登录，进入 `/console` 选购。新账户余额为 `0.00`，可通过易支付全额付款；已有余额可与网关组合。金额来自服务端商品 `onetime` 价格，客户端提交总价不起作用。本轮不提供钱包充值或管理员调账入口。

- `POST /checkout`：商品、周期、钱包选择和渠道；校验失败时不生成资金或交付写入。
- `GET /console/invoices/{invoice}`：本人账单，支付按钮调用 `POST /console/invoices/{invoice}/pay`；显式重试生成新的支付尝试。
- `GET|POST /api/payments/{epay_wechat|epay_alipay}/notify`：服务器验签通知；浏览器返回地址仅显示账单，不作为付款证据。
- `GET /console/instances/{instance}`：仅本人可以领取已交付的卡密，响应为 `private, no-store`。列表页不展示卡密。

## 队列与恢复

```bash
php artisan queue:work --tries=3 --timeout=180
php artisan schedule:work

# 排查时可单独运行；生产中由 schedule:run/schedule:work 每分钟调用。
php artisan invoices:expire
php artisan fulfillment:dispatch-stale
php artisan cards:reap-reservations

# 查看失败任务与原因后，由操作员补库存等，再显式重试。
php artisan fulfillment:retry TASK_ID --reason='已补充库存，批准重试'
```

调度器三项任务均使用 `withoutOverlapping()` 和 `onOneServer()`。共享环境应使用支持共享原子锁的缓存。任务 timeout 为 180 秒，任务 lease 为 210 秒，queue retry_after 默认 240 秒；使用配置覆盖时需保持这一顺序。队列 Job 有每实例防重叠锁；交付前还会在事务内复核任务 token、租约和实例绑定，旧 worker 不能提交凭据。

卡密库存只在交付时预留，不在未付款账单创建时扣库存。付款后如果库存已耗尽，账单保持 Paid，任务退避重试并最终失败告警，补货后可审核重试；本轮没有擅自自动退款。已交付的卡密永远不回收。

目前异常告警写入应用日志；Telegram 通知及 Filament 重试 Resource 尚未实现。操作员应检查 `fulfillment_tasks`、应用日志和 `queue:failed`。失败的网关调用保留 payment_attempt，不吞异常，不自动重放付款。

## 验证证据

```bash
./vendor/bin/pest
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
./vendor/bin/pest --group=spec
python3 scripts/verify-local-card-sale.py
```

默认 Gate 已验证：91 passed、506 assertions；PHPStan 0 errors；Pint clean。规格组剩 5 个预期失败，全部为未实现的 ZJMF 和退款用例。

隔离 HTTP 验收脚本使用临时 SQLite 文件、storage、APP_KEY、本机模拟网关及临时 PHP 服务器；请求包含真实 session、CSRF 和签名。模拟网关从独立数据库连接确认 attempt 已提交；重复回调只生成一次交付任务，独立数据库队列 worker 完成发卡，其他用户得到 403。脚本结束关闭临时服务并删除临时文件，不连接真实支付提供商或业务数据库。普通 Pest 同时验证纯钱包和组合付款、outbox 故障回滚、密码规则、驱动只读、库存加密、租约失效丢弃结果及过期恢复。

这些结果不证明 MySQL 并发锁、真实 Redis 集群或真实易支付提供商行为。本轮未修改迁移、枚举、DTO 或既有契约接口，未部署或发起真实支付。

## 迁移的 11 个规格

以下用例正文均与本轮开始时逐字一致，每次仅迁移一个规格；`EXPECTED_SPEC_CASES` 从 16 逐次减为 5。

1. `delivers the exact same encrypted card key idempotently on repeated redemption provisioning`
2. `freezes wallet credit on combo payment and unfreezes once when expired`
3. `credits a late payment on an expired invoice without provisioning`
4. `ignores a duplicate callback and records exactly one callback row`
5. `credits the excess on overpayment and never provisions twice`
6. `rejects a short payment without marking the invoice paid`
7. `records an unknown invoice callback instead of failing the webhook`
8. `prevents physical user deletion when financial or fulfillment records exist and enforces tenant isolation`
9. `recovers stale pending tasks via the outbox sweeper`
10. `reclaims a running task whose lease expired`
11. `fails a task permanently once max_attempts is exhausted`

## 规则审查

- INV-I1/I2、M1/M3：本地可信报价包含产品和 setup fee，金额只经 Money 计算，金额恒等式入库前复核，保存价格和绑定快照。
- INV-W2/W3、INV-L1：冻结、结算、解冻及回调入钱包有唯一流水，先锁 users 再锁 invoice/attempt；库存路径先锁 instance 再锁 code/task。
- F02、INV-I3/P2/P3/K2：Paid、实例及唯一 outbox 同事务，提交后 dispatch；HTTP 与签名适配器不写模型，外部 HTTP 不跨数据库事务。
- F10、INV-C1/C2/C3/R3：驱动只读；Action 预留时绑定实例，售出与凭据原子提交；密文之外使用 HMAC 去重，只释放未交付的过期预留。
- INV-T1/T2/T3/T5、INV-Q1/Q2：验证任务与租约，过期恢复、退避、最大次数终止、显式审计重试；取消或换绑的旧任务不执行。
- INV-A1/A2：操作命令要求非空原因，库存/商品/配置/重试记 system 审计，实例状态转换也记审计，不记录明文卡密和支付密钥。

后续进展：上游成本和首次密码规则已按人工批准核实并实施，见 `classic-zjmf-integration.md`；退款流水命名与佣金追回已按 C10/C11 实施，见 `refund-decisions.md`。本文件上面的 Gate 和规格数量是本地售卡阶段的历史验收，不代表当前累计数量。退款 Action 尚未接入运营后台，不提供自动网关退款或应收清偿。
