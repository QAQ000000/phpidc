# 经典 ZJMF 真实 CDN 联调与价格裁决

日期：2026-11-17。目标供应商：`https://sakuraidc.cc`。用户授权一次 0.10 元体验 CDN 测试；未授权额外付费续费、升级或破坏性操作。凭据不写入此文档或 Git。

## 已执行的只读验收

使用独立 SQLite、storage、APP_KEY 和加密上游账户，没有迁移或写入正式业务数据库，没有启动正式 worker/cron。

- TLS、API 登录、Bearer 认证、余额、目录、商品配置及库存均可用。
- `/user_info` 实际返回 `status=200, user.currency=1`，不是原实现仅接受的 `data.user.currency`。已兼容两种形式，报价和新购共用该读取方式；新增顶层响应的报价至交付回归测试，旧测试和冻结接口未改。
- PID 391「CDN体验」库存为 75，无配置选项或自定义字段。月付商品配置返回 `monthly="0.01", msetupfee="0.00"`。
- 实际 `POST /cart/get_total` 返回 CNY、`product_price="0.01"`、`product_setup_fee="0.00"`、`total="0.01"`、`setupfee_total="0.00"`、`type=false`。真实驱动报价为 **0.01 CNY/月**，不是用户描述的 0.10 元。
- 其他体验商品配置：PID 595 月付 18.00 / 试用 0.50；748 月付 5.00 / 试用 0.50；753 月付 28.00 / 试用 0.50；904 月付 99.00 / 试用禁用。这里只列配置值，不宣称已取得这些商品的实际折扣报价。
- 获批前未创建本地账单、未调用 add_to_shop / settle / apply_credit / apply_credit_limit，也未执行续费、控制或撤销。余额只读探测为 5.86 CNY。获批后的实际新购结果见下节。

私有验收目录保留独立数据库、APP_KEY、加密凭据、脱敏操作日志和原始配置快照，供获批后在同一状态继续；不重新初始化以抹去操作证据。

## C19：纠正经典周期价格映射（已批准并实施）

开工时 `CLAUDE.md §3.4`、`docs/zjmf-api-spec.md §2` 和 `BillingCycle::priceKey()` 把 `*setupfee` 定义为周期价格，并规定初装费恒为零。真实响应和报价证明，至少本供应商的 `monthly` 才是周期价格，`msetupfee` 是初装费。旧导入逻辑会把 PID 391 展示为 0.00，而服务端实时报价收取 0.01。C19 已纠正该差异。

只读参考源码亦区分两者：`zjmf-manger-decoded/app/home/controller/CartController.php:1365` 起分别声明 product_price / product_setup_fee；getTotal 的返回同时包含 total / setupfee_total。`app/common/logic/Cart.php:290` 使用 price_type 的第一个字段累加周期配置费，第 299 行单独累加第二个字段的初装费。这只是辅助证据，真实供应商的非零初装费尚未联调。

用户已明确批准 C19，并改以 PID 391 的 0.01 CNY 月付完成一次新购，仍以 0.10 CNY 为总支出上限。已按以下范围实施：

1. 修正章程和 wire 文档的上述价格语义；`BillingCycle::priceKey()` 返回周期本身的价格字段，配套显式初装费字段映射。18 个周期值、已有 DTO 字段、财务不变量和数据库结构保持不变。
2. 商品及选项的导入/同步分别保存周期价和初装费；负周期价仍表示禁用。缺失、异常或不能解释的金额拒绝，不回退成零价。既有商品需显式重新同步；不批量修改已出账单或既有实例的 recurring_amount。
3. 实时报价保留预计上游支出的完整总额；客户周期价与一次性初装费分开，避免初装费进入 recurring_amount 或重复收费。有折扣时分别核对对应字段。金额仍全部使用 Money；付款前仍按 upstream_expected_cost 和币种精确对账（INV-M1/M4、INV-I1/I2、INV-W2/W3、INV-U1/U2）。
4. 定向修正 `tests/Unit/EnumsTest.php` 的价格字段期望，以及 ClassicOperationsTest / ClassicExpansionTest / ZjmfPurchaseTest 和模拟验收脚本中的价格 wire fixture。除批准的枚举价格字段期望外，所有旧用例的断言保持原样；增加非零初装费、不重复计费和续费排除初装费的回归。不改 Specs，不放宽断言或静态检查。

本轮先提交草案并停止购买，收到用户批准后才修改章程、枚举和价格逻辑。审批原因来自 `docs/agent-guardrails.md §1.1/§1.3`：错误规则由人工裁决，冻结枚举/契约不能自行修改。

## 获批后真实新购结果

使用同一隔离库、同一上游账户，导入并完整同步 PID 391，再通过 CreateComboInvoiceAction 创建钱包支付账单；独立 database worker 实际消费 outbox、交付及主动同步任务。未直接调用 API 绕过 CMS 账务或交付流程。

| 核对项 | 实际结果 |
|---|---|
| 商品与周期 | PID 391「CDN体验」，monthly，数量 1，CNY |
| 导入价格 | 周期价 0.01，初装费 0.00，加价 0.00% |
| 本地账单 / 任务 / 服务 | 1 / 1 / 1；Paid / success / Active |
| 上游账单 / 服务 | 811446 / 4291 |
| 上游账单成本 | use_credit_page：0.01 CNY，与 upstream_expected_cost 精确一致 |
| 真实上游余额 | 5.86 → 5.85 CNY，支出 0.01 |
| 隔离测试钱包 | 1.00 → 0.99 CNY，frozen_credit=0.00；初始 1.00 是测试 fixture，不是正式客户充值 |
| 唯一流水 | freeze:1 / credit_freeze / 0.01；settle:1 / credit_settle / 0.01 |
| 创建与付款次数 | add_to_shop=1、settle=1、apply_credit=1；未调用 apply_credit_limit |
| 认证同步 | header 确认 Active，队列主动同步与随后独立查询均成功 |
| 到期时间 | 本地与认证上游均为 2026-12-17 15:23:22 UTC |
| 凭据 | username/password/port 通过加密模型交付；数据库密文和操作日志中未发现 API 密钥、开通密码或下游 token 的明文 |
| 队列 | jobs=0，failed_jobs=0 |
| 完成任务再次执行 | 原任务返回无操作，没有新增上游请求或账务流水 |

P4 返回 200 和上游 invoiceid/hostid；付款前核对真实账单；P5 返回 1001 后才以认证 header 的 Active 确认交付。测试资源保持 Active，未擅自终止；私有验收状态继续保留，后续核对使用原账单/主机，不能新建订单代替恢复。

## 验收边界

- 用户已批准 C19 和 PID 391 的 **0.01 CNY 月付**目标；不通过加价或改变数量凑成 0.10 元。
- 执行前已持久化一次性费用授权和 worker_requested 阶段。后续仍使用同一隔离状态；任何费用漂移停止付款，任何不明创建/支付结果只通过现有 P2 恢复，不盲重放。
- 本地 APP_URL 使用隔离的 `.invalid` 地址，无法收到上游公网回调。本轮主动同步与交付成功不代表真实公网回调成功；需要另行提供可访问的验收回调入口。
- 本轮已验证真实新购、上游账单对账、扣款和 Active 交付。未添加 CDN 域名或产生流量，故 CDN 流量服务可用性未验证。未执行真实续费、升级、暂停/恢复、终止或热切换；非零初装费/折扣仅有回归测试与参考源码证据。
- 原有本地 v0.1.0-rc.1 标签没有移动，本轮修复与记录留在工作区，未创建新发布或改动正式站点部署。

## 本轮检查

后续面板登录补验（2026-11-17 至 18）：同一主机 4291 的客户服务页已显示「登录面板」，实际点击进入已认证 EasyPanel 首页。上游余额仍 5.85 CNY，没有新增购买或扣款。此项不补足公网回调与 CDN 流量验收；详见 [协议和浏览器证据](classic-zjmf-panel-login.md)。以下为 C19 新购轮次的历史 Gate。

- 开工：Pest 452 passed / 3495 assertions；PHPStan 0 errors；Pint clean。
- 收尾：Pest **460 passed / 3616 assertions**；PHPStan **0 errors**；Pint **clean**。
- 隔离 HTTP / CSRF / 支付回调 / database worker / 临时 Redis 生命周期模拟验收通过，覆盖新购、续费、升级和防重复扣款；没有借此执行真实上游续费/升级。
- Specs 仍为 0；本轮没有移动或改写任何原规格。
