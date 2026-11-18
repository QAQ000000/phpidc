# 运营扩展交付与运行说明

2026-11-17。本轮按人工批准的 [C16–C18](operations-expansion-decisions.md) 实施。开工前已有的工作区变更全部保留，未提交 Git；未迁移业务库、未给真实账号授权、未启用真实外部打款。原 Specs 已清空，本轮新增 Feature 验收，不改旧断言或 Specs 计数。

> 2026-11-18 更新：D01–D05 已增加主动充值、注销匿名化、人工外部提现、应收清偿及通知 outbox，见 [实施验收](remaining-capabilities-implementation-2026-11-18.md)。下文原验收计数为 2026-11-17 历史结果。

## 已交付入口

| 模块 | 客户入口 | Filament 入口 | 写权限 |
|---|---|---|---|
| 优惠券 | `/console/coupons`；checkout / 新建续费填写券码 | `/admin/coupons`：创建、编辑、停用、定向发放 | `promotion.manage` |
| 推广佣金 | `/console/affiliate`：签名推广链接、成熟时间、佣金明细 | `/admin/affiliates`：费率、优先级、商品范围、启停、佣金核查 | `affiliate.manage` |
| 转钱包 | `/console/affiliate`：账面余额、申请占用、可申请额、申请记录、退款应收 | `/admin/payouts`：筛选、余额/应收核查、带原因批准/驳回 | **独立 `finance.payout`** |
| 工单 | `/console/tickets`：新建、本人详情、回复、关闭/重开 | `/admin/support`：筛选、详情、客服回复、关闭/重开 | `support.reply` |
| 通知 | 复用已验证绑定；无已验证邮件绑定时回退账户邮箱 | `/admin/notifications`：模板、变量映射、停用、投递状态 | `notification.manage` |
| 下载 | `/downloads`：资源列表、按访问级别授权下载 | `/admin/downloads`：私有上传、HTTPS 外链、版本、访问要求、启停 | `download.manage` |

客户只能操作本人账户；工单可选服务也必须本人所有。客服身份由服务端权限和入口决定，不接受客户的 `is_admin`。工单正文按文本转义输出，关闭后须先明确重开。下载列表不泄露本地路径和受限外链；`active_customer` 只认可本人的 Active 实例。

后台写入均再次授权、校验原因，在同一事务内写业务记录与真实 actor / before / after 审计。优惠值和佣金费率保留修改前后值；通知模板审计使用内容哈希，避免整段正文进入通用审计列表。查看员看不到操作表单，伪造 POST 仍被拒绝。后台表单时间使用站点时区。

## 金额与幂等口径

- 优惠券先领取、后核销，领取不占总名额。用户锁和券锁保护限次与额度；次数按 `coupon_usages`，而非领取次数。折扣在钱包冻结前确定，Money 校验 `subtotal = total + discount`、`credit + gateway = total`；取消、过期、退款不恢复核销次数。固定折扣封顶 subtotal，percent `10.00` 表示减免 10%。
- 只对 CNY 新购 / 新建续费用券；升级不用券。已有续费账单按原账期键返回，不补券、不重新定价。新购快照另存 `purchase.recurring_amount`，一次优惠及 setup fee 不进入未来周期价格；没有该字段的历史单继续使用旧回退口径，不批量改历史服务。
- 仅新注册账户按服务端保存的签名推广链接归因，不接受注册请求中的 `referred_by`。现有第三方登录只登录/绑定已有账户，不会另外创建推广归属。计佣限 CNY 新购，规则优先级降序、同优先级 ID 升序，商品未关联表示全局；胜出规则费率为 0 时不计佣。
- 建单冻结推广人、规则、费率及冻结天数；按折后 `total_amount` 计佣，包括钱包部分，完全不改变 `upstream_expected_cost`。冻结期从实际 `paid_at` 起算，默认 7 天，运行时读取 `SettingService` 的 `affiliate.freeze_days`。历史无快照订单不追溯计佣。
- Paid 提交后派发独立计佣 Job；唯一 invoice_id 防重复。`affiliate:process` 恢复漏计佣金并成熟到期 pending；成熟仅加推广余额，唯一 `affiliate:{id}` 流水。参与用户先按 ID 升序锁，再锁账单/佣金；禁止交付事务锁实例后倒序锁推广人。
- 提现新申请必须带用户作用域 ULID `request_key`，相同键和相同金额返回原申请，冲突拒绝。pending 只占可申请额度，不扣钱包或推广余额。批准时再次校验余额和应收，同事务扣推广余额、加钱包、写唯一 `payout:{id}`、审核人/时间和审计；重复批准验证原流水后返回，不能重复入账。
- 延续 C11：退款撤销 pending 佣金不扣余额；available 追回不足会登记应收。pending 提现不受退款保护；余额不足保持 pending，财务可明确驳回。存在任何正数退款应收，即使后续又有正余额也禁止新申请/批准；**没有自动清偿或净额抵销**。

## 通知兼容与界限

`NotificationManager` 对付款、交付、退款、佣金、提现、客服回复、续费和逾期事件逐渠道派发提交后 Job。只传安全变量白名单，不带卡密、开通密码、证件、密钥、提现账户原文；某渠道失败独立记脱敏类别和告警。无模板使用内置模板；显式停用记 `disabled`，绑定失效记 `skipped`，传输失败记 `failed`。所有新记录（含旧续费Job）的邮件/SMS日志模式记 `simulated`，不得当作实际发送。

保留旧 `SendNotificationJob` 构造参数及续费默认行为，支持新模板并在执行时重新核实绑定；兼容历史 `mail` 别名。历史旧续费日志的 `sent` 可能来自日志驱动，不应据此追认真实发送；FIX-012后的新记录统一区分模拟。新记录统一使用 `email`。后台投递列表不展示完整目标和异常原文。

已存在的 `LogMailDriver` 实际支持 SettingService 配置的 SMTP，直接复用；本轮用独立回环 SMTP 验证。真实 Telegram 和 idcsmart SMS 未向真人发送，SMS/KYC 按用户先前决定继续暂停。`sent` 仅表示传输受理，不是送达证明。D05 已增加本地持久事件与渠道投递，业务提交后补扫恢复；发送意图后未知结果保持 uncertain，不自动重发。供应商未提供通用幂等协议，仍不承诺远端 exactly-once。资金流水及计佣补偿独立于通知投递结果。

## 部署前提与有意保留范围

1. 先备份并审查待执行迁移；新增 `2026_11_17_000002_add_request_key_to_affiliate_withdrawals.php` 只给提现表增加 nullable unique request_key，历史记录保留 NULL。本轮仅在隔离 SQLite 和独立 MySQL 执行迁移。
2. 运维按现有部署流程执行迁移、维护队列 worker 和调度器；`affiliate:process` 已登记每分钟执行，含 `withoutOverlapping()`、`onOneServer()`，多机环境需共享支持锁的缓存。没有为真实站点启动新常驻服务。
3. 使用既有带原因命令分配所需角色，例如 `php artisan admin:role <用户ID> promotion-operator --reason='明确授权原因'`。其他固定角色为 promotion-viewer、affiliate-viewer/operator、support-viewer/agent、notification-viewer/operator、download-viewer/operator；转钱包审核另授 **finance-payout**。既有 finance-operator 仍只有退款权，不自动扩大为提现权。
4. 工单附件/部门/SLA 和自动外部代付供应商仍未实现。D03 已交付人工转账/冻结/凭证确认状态，D04 已交付外部已收款的应收清偿/冲正；优惠券/佣金仍仅 CNY。
5. 下载上传限制 50 MB，保存在私有 downloads 磁盘并计算大小/SHA256。元数据/审计失败会删除本次新上传并重新抛出错误；替换后的旧版本文件保留，不做无审计清理。进程被强制终止仍可能留下未引用私有文件，需人工核对。外链授权后由浏览器跳转，不能阻止获授权的人分享链接；download_count 是接受请求数，不是完成数。

## 可复现验收

```sh
./vendor/bin/pest
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
./vendor/bin/pest --group=spec
python scripts/verify-operations-expansion.py
python scripts/verify-operations-concurrency.py
```

两个脚本自动创建并清理临时数据目录，覆盖独立 APP_KEY / storage / DB / public。HTTP 脚本使用真实 session、CSRF、数据库队列和回环 SMTP；不会跟随受限下载的第三方重定向。并发脚本自行启动 **skip-networking、独立 socket / datadir 的 MySQL**，不连接现有数据库；可用 `--mysqld` 指定本地二进制。

并发场景：两个用户争最后一个用券名额、同一用户超额申请、相同 request_key 重试、同一申请并发批准。核对最终钱包、推广余额、核销和唯一流水。Feature 另覆盖强制账本/审计失败的整事务回滚、退款后应收阻断、本人范围、工单文本转义、下载权限矩阵、通知模板停用/退订及渠道异常隔离。

浏览器已在临时站点用真实 Filament 登录，检查 1280px 桌面和 390px 手机：优惠券创建带原因提交成功、工单详情、推广账户与响应式布局。手机后台和客户页面均无页面级横向溢出；仅有临时站点缺 favicon 的 404。临时浏览器、HTTP/SMTP/MySQL 进程均在验收后关闭。

最终结果：**Pest 433 passed / 3398 assertions；PHPStan 0 errors；Pint clean；Specs 0（命令返回 No tests found，符合已清空状态）**。新增 `OperationsExpansionTest.php` 共 47 个展开后用例，其余旧断言未修改。两份独立验收脚本最终版本均返回 PASS；临时服务 PID 检查为空，原业务 `storage/installed.lock` 仍不存在。

本轮验收输出：`/tmp/operations-expansion-{pest,phpstan,pint}.log`、`/tmp/operations-http-final.log`、`/tmp/operations-mysql-final.log`；浏览器截图为 `/tmp/operations-coupons-desktop.png`、`/tmp/operations-coupons-mobile.png`、`/tmp/operations-affiliate-mobile.png`。这些是本机临时验收产物，不加入版本库，也不包含真实站点凭据。


## 2026-11-18 通知恢复补充（FIX-012）

两个通知Job均最多3次总尝试。仅协议明确未接受的临时拒绝可重放（Telegram ok=false/error_code=429，遵守retry_after；SMTP4xx），退避至少60/300秒，第三次失败进入failed_jobs。超时、丢响应、未核实5xx为结果不确定，不盲目重发收费短信。永久拒绝/未配置不自动重试。每次重试先保存脱敏失败记录；最终失败的安全异常不串联原传输异常。通知Job沿用安全变量/收件人，不包含供应商密钥；失败库及备份仍须受控。

`/admin/notifications`支持用户/状态过滤及安全失败分类，始终不输出收件人或原异常；最终耗尽在`/admin/tasks`核查failed_jobs的UUID。先核对供应商是否接受，再决定人工重试，禁止queue:retry all。D05 已补持久事件 outbox；发送意图后的崩溃保留 uncertain 供人工核对，不自动重发。供应商送达幂等协议仍未新增，没有向真人发送通知。证据见backend-fix007-017-verification.md。
