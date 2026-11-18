# 五项剩余能力实施与验收

日期：2026-11-18（Asia/Shanghai）。用户明确采用 [D01–D05 全部推荐方案](remaining-capabilities-decisions-2026-11-18.md)，本轮完成实现与隔离验收，并按用户要求纳入本地 Git 提交。原有严格测试、Specs、Composer 依赖、PHPStan 配置及核心历史迁移未改。

## 交付入口与权限

| 能力 | 客户入口 | 后台入口 | 写权限与规则 |
| --- | --- | --- | --- |
| 钱包主动充值 | `/console/wallet`，建单后进入已有账单付款 | `/admin/finance` 的充值启停表单、充值单详情 | 新角色 `finance-wallet-settings`；`finance.wallet.configure` + `finance.view`；默认关闭，固定 CNY 1.00–10000.00 |
| 注销匿名化 | `/console/account/close`，账户安全页面提供入口 | 不提供强制注销 | 本人当前密码与明确确认；提交时在用户锁内重验资金、业务、权限和应收阻断 |
| 人工外部佣金提现 | `/console/affiliate/external`，推广页面提供入口 | `/admin/external-payouts` | `external-payout-reviewer/operator/confirmer` 三类角色；审批、执行、付款确认、核对、完整收款资料查看分别授权 |
| 退款佣金应收清偿 | 推广页面显示原始/剩余应收 | `/admin/finance/receivables` | 新角色 `finance-receivable`；`finance.receivable` + `finance.view`；只登记已实际收到的外部 CNY |
| 持久通知事件 outbox | 业务动作内自动记录 | `/admin/notification-outbox` | 查看 `notification.view`；处理 uncertain 要求 `notification.manage`、确认、原因和原笔证据 |

客户写入口沿用 auth、真实 CSRF 和独立限流。后台 Actions 重新读取并锁定真实操作人后授权；操作角色没有自动赋予现有预览账户。普通列表不选择外付密文，完整收款资料须单独 POST、授权和查看审计，并返回 no-store。支付凭证、收款账户和核对证据不会因校验失败写进 session old input。

## 资金与身份边界

充值有独立用户作用域 `topup_request_id`；同请求同意图重放原单，改变意图拒绝。充值只用 Epay 全额外部支付，无产品、实例、履约、优惠、计佣或钱包自充。Paid、本金 `topup:{invoice_id}` 贷项、账本及通知同事务；短款不累计，多付/重复真实交易/终态迟到款沿用原 P2/P3/P5/P6。容量不足完整拒绝自动入账，保留已验证回调、审计及运营告警后 ACK。通用商品退款的两个模式均明确拒绝充值，后台也不展示退款表单。

注销清理认证/联系资料、OAuth/通知绑定、数据库会话和重置凭据，轮换密码及 remember token，SoftDeletes + anonymized_at 同时生效；文件/cookie 会话在下次请求复验时失效。原邮箱、手机号、OAuth 标识可用于新 ID，原订单、财务、服务、投诉、审计等历史证据保留。通知密文和历史日志目标清理；已经开始的远端请求可能完成，不承诺撤回。新账号不继承旧事件或旧资金。

注销后原账单的合法迟到/重复贷项按原软删 user_id 留账并产生持久运营对账事件；不复活、不发货、不转给新注册用户。异常软删账户仍有 Unpaid 时不自动结算或交付。已经清偿后发生真实冲正，也可能在原匿名 ID 重新形成应收；保留账本并通知运营核对，不修改旧身份或自动扣新账号。

外付状态闭环如下，普通钱包始终不变：

| 当前状态 | 合法处理 | 资金影响 |
| --- | --- | --- |
| pending | approve / reject | 批准时可用佣金转冻结，唯一 `external_payout_hold:{id}` |
| reserved | start / cancel | start 持久生成唯一执行标识；只有尚无意图的取消可以解冻 |
| processing | paid / unknown / confirm_not_paid | paid 要求原执行标识、实付金额/CNY及唯一交易凭证；未知保持冻结 |
| unknown | paid / confirm_not_paid | 只能核对原笔；无重新出款或普通取消入口 |
| paid / failed / cancelled / rejected | 同操作请求幂等重放 | 不重出款、不重复消费或释放冻结款 |

`paid` 写唯一 `payout:{id}`；确证未付或执行前取消写唯一 `external_payout_release:{id}`。外部凭证按收款方式与规范化交易号唯一；操作幂等快照、收款资料和证据均加密。退款只追回可用佣金，不挪用出款冻结；后续解冻不会自动清偿退款应收。

清偿登记支持多次部分收款；冲正完整撤销一笔登记，再以新记录更正。收款凭证唯一，修订链不分叉。所有财务流水追加，原 `affiliate_clawback` 不改写；统一投影由本金减有效清偿并加冲正计算剩余应收。证据缺失或不一致保持阻断。客户推广、后台钱包划转、流水核查及外付审批共用此口径，零余额与“账户又有佣金”均不能替代清偿事实。

## 通知与跨模块锁

业务状态、稳定键事件、各渠道密文投递同事务提交；afterCommit 只派发 ID，分钟命令 `notifications:dispatch-pending` 使用 withoutOverlapping + onOneServer 补扫。sync 配置也不会在业务请求内做通知网络发送，而由数据库队列承接。新队列实际载荷只有投递 ID 及 Laravel 元数据；旧 Job 类名、构造参数和旧消息入口保留。

发送方按 user → event → delivery 认领、设置租约并持久提交发送意图；网络在锁外进行。明确未接受的临时拒绝才自动退避，最多三次。发送意图后的崩溃、超时、失联及结果事务失败进入 uncertain，补扫和重复消息不重发；人工重试还须确认原 worker 已停止且供应商未受理。sent 表示传输接受，不是送达/阅读。详见 [D05 实施记录](notification-outbox-implementation-2026-11-18.md)。

所有参与用户（包括真实管理员 actor）按 ID 升序先锁，再锁账单及后续业务行，最后写通知事件；运营告警在财务事务中只记录 operations scope，不在事务尾倒序锁财务人员。独立阶段才锁运营接收者并展开；暂无接收者时保留待展开事件。原退款缺失 actor 锁会与新增外付审批形成审计外键等待环，已在真实 MySQL 竞态中复现并修复，同时补了撤权/注销旧 actor 的回归。

## 增量迁移与运行前提

| 迁移 | 内容 |
| --- | --- |
| `2026_11_18_000004` | invoices.topup_request_id 及用户作用域唯一键 |
| `2026_11_18_000005` | affiliate_frozen_balance；外付方式、密文、操作幂等、执行标识、唯一凭证及人员/时间 |
| `2026_11_18_000006` | 应收清偿/冲正两表，收款凭证与修订链唯一约束 |
| `2026_11_18_000007` | notification_events、notification_deliveries、原日志投递/尝试关联 |

四条迁移已在独立 SQLite 和离线 MySQL 8 执行；**尚未应用到原预览/业务数据库**。当前工作区代码依赖这些新表/列；原预览数据库仍是上一轮 schema，新入口须完成对应升级后再使用。升级前备份、停止旧 worker 与调度，确认目标库后执行增量 migrate，重新加载进程并按职责分配角色；不要对有数据的库运行 migrate:fresh。新记录产生后不宜通过删除账本/投递表回退，应按原证据修复。

充值默认关闭，需财务设置角色明确启用，并配置已有 Epay 商户。外付仅人工转账记录；没有接入未经验证的自动代付 API。通知需要已有受控队列消费者和分钟调度；本轮未启动原预览通用 worker，也未修改原供应商或付款配置。换钥已经覆盖外付三列密文和 outbox 两表，注销不会使旧凭据重新有效。

## 隔离验证

完整回归：**Pest 1068 passed / 9033 assertions，PHPStan 0 errors，Pint passed，Specs 0**。Pest 使用 PHP 8.4、512 MB 测试进程上限、独立 APP_KEY/storage、SQLite 内存库；默认 128 MB 测试进程在文件 MIME 检测用例耗尽内存；使用 512 MB 后完成全套，没有跳过用例。完整结果：`/tmp/laravel13idc-five-capabilities-44ttdbop/final-pest.log`。旧测试及门禁配置的 Git diff 为空。

各模块 MySQL 8.0.35 使用独立临时库、socket 和 `--network none` 容器，没有连接现有 MySQL。专用容器结束后删除，保留 `/tmp` 证据：

| 模块 | 关键验证 | 证据目录 |
| --- | --- | --- |
| 充值 | MySQL 专项 51/380；同 key、同交易、不同真实交易、购买冻结、到期解冻、开关六组竞态 | `/tmp/laravel13idc-wallet-mysql8-_b7lb_r_/` |
| 注销/应收 | 清偿/凭证唯一、冲正/修订不分叉、注销与充值/工单双向锁序、重复注销 | `/tmp/laravel13idc-account-receivables-mysql-l012s_z5/` |
| 外付 | MySQL 专项 27 例；同 key、额度、审批、支付/释放、跨单凭证、退款六组独立进程竞态；收款/证据/幂等换钥 | `/tmp/laravel13idc-external-payout-mysql8-fv53msea/` |
| 通知 | MySQL 专项 20/102；双 worker、注销交叉、已受理后提交故障、event/renewal 两类真实 database worker | `/tmp/laravel13idc-notification-outbox-9xuzzo9v/` |

真实 HTTP 验收使用单独 SQLite、APP_KEY、local 环境 session/CSRF、普通及分离权限财务账号；只有临时 router 注入 fake 并阻断其他出网。验证充值签名回调与重复回调、充值禁退422、CSRF419/权限403、外付 unknown→paid 模拟凭证、应收登记与冲正、注销后重新注册及原 ID 迟到款、通知 uncertain 不重发。首轮证据 `/tmp/laravel13idc-five-http-o_gak940/evidence.json`；第二轮及浏览器共用独立验收库 `/tmp/laravel13idc-five-http-b48nn3pf/acceptance.sqlite`，HTTP 证据在同目录 `evidence.json`。两轮均没有启动原预览 worker。

Chromium 实际浏览器验证客户钱包建单、充值账单、客户外付记录、注销阻断、财务只读权限、应收登记、通知人工核对，以及零资金测试账户的密码确认注销。两次模拟应收登记各 1.00，剩余由 10.00 降为 8.00，钱包及佣金均未变动；待核对通知经模拟证据确认变为 sent，attempts 仍为 1，fake 发送记录仍为原来的 3 次。注销成功后跳回登录页，原凭据再次登录被拒绝。钱包、应收及通知页在 390px 手机视口复验；通知页另有 1280px 桌面截图。浏览器证据与数据库核验在 `/tmp/laravel13idc-five-http-b48nn3pf/browser-evidence.json`，截图及交互快照在 `/tmp/laravel13idc-five-capabilities-44ttdbop/`。

浏览器发现并修复两处展示问题：应收渠道 HTML pattern 的连字符按现代浏览器 `/v` 正则转义；通知表格与表单使用现有滚动、表格和表单样式，解决手机右侧被裁切。修后浏览器提交含连字符渠道成功，通知静态资源返回 200、控制台无错误；应收目标测试 33/145、通知目标测试 20/102 与对应 Pint 均通过。首轮测试站缺少 favicon 的 404 属既有站点资源问题，不计作新能力通过项。

已关闭专用浏览器会话，核验临时 PHP PID 1625734 的工作目录、命令和专用 router 后停止；47307 端口及浏览器进程均已退出。原预览 PID 1448041 / 59493 保持运行，`/login` 返回 200。专用 MySQL 验收容器已删除，共享容器保留。清理证据 `/tmp/laravel13idc-five-http-b48nn3pf/cleanup-evidence.json`。再次只读确认原预览库未应用本轮四条迁移。

复现命令（均从项目目录运行，门禁须使用独立测试环境）：

```sh
/www/server/php/84/bin/php -d memory_limit=512M vendor/bin/pest --compact
/www/server/php/84/bin/php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
/www/server/php/84/bin/php vendor/bin/pint --test
python3 scripts/verify-wallet-topup-concurrency.py
python3 scripts/verify-account-receivables-concurrency.py
python3 scripts/verify-external-payout-mysql.py
python3 scripts/verify-notification-outbox.py
python3 scripts/verify-five-capabilities-http.py
```

IMPLEMENTED: D01–D05，前后台入口、权限、账本、审计、增量迁移、并发和故障验收。
NOT DONE: 原预览/业务库升级，真实收付款/通知，以及首期范围之外的自动代付和充值退款。
DECISIONS: 采用用户明确批准的五项推荐边界；原财务和身份归属按原 ID 保留。注销/应收详细记录见 [专项实施说明](backend-review-account-receivables-fixes.md)。
CONCERNS: 远端人工付款与未知通知必须凭原笔证据核对；本地唯一键不能承诺供应商远端 exactly-once。
GATES: Pest 1068 passed | PHPStan 0 errors | Pint passed。
SPEC CASES: 0，未修改原 Specs 或计数。
