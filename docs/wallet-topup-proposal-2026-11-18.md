# 钱包主动充值方案（2026-11-18，D01 已批准）

用户已明确采用 D01–D05 全部推荐方案。本文保留批准时的设计依据；实现和隔离验收见 [五项能力实施验收](remaining-capabilities-implementation-2026-11-18.md)。现有预览/业务库未执行本轮新增迁移，未请求真实充值付款。

## 1. 最少需要确认的业务决议

| 决议项 | 建议 | 确认后的影响 |
| --- | --- | --- |
| 充值范围与创建幂等 | 首轮仅 CNY，金额 `1.00–10000.00`，仅已配置的易支付渠道；无赠送、手续费、优惠券、佣金及钱包自充。新增独立 `topup_request_id` 与用户范围唯一索引，复用现有账单及支付尝试 | 需要一条迁移，并对账单公式、商品关联及合法写入者作限定例外；不改变购买专用 `purchase_request_id` |
| 成功及异常收款 | 充值金额足额才结清账单；不足不累计。多付、终态后迟到及另一笔真实付款沿用现有 P2/P3/P5/P6；合法贷项仅记到原 `user_id`，匿名账户保留财务记录并告警人工清退 | 充值成功以 Paid、余额及流水同事务替代商品交付；不创建实例或交付任务。金额容量或不一致证据问题保留回调并转人工核对 |
| 充值退款 | 首轮关闭主动充值退款，并在 `RefundInvoiceAction` 明确拒绝充值类型，即使传 `keepService=true` 也拒绝 | 通用商品退款会再次增加余额，或记录外部退款而没有扣除已入账余额。以后开放必须另定充值扣减/冻结、已消费余额、外部退款幂等及失败恢复协议 |

上述规则按 D01 批准；匿名账户与通知 outbox 的公共规则分别按 D02/D05 批准。

## 2. 提案时的实现与缺口

| 已有能力/证据 | 当前缺口 | 本方案的使用方式 |
| --- | --- | --- |
| `app/Enums/InvoiceType.php` 已有 `WALLET_TOPUP`，且 `createsFulfillmentTask()` 为 false | 枚举存在不等于充值已经可用 | 保留该类型，不新增第二种充值类型 |
| `invoices` 已有金额、币种、状态、网关、到期时间和 `line_items`，商品/实例外键允许为空 | INV-I4 仍要求每张账单一个商品；Paid 路径的全局表述以商品交付为前提 | 为充值增加窄范围规则例外，使用无商品账单 |
| `app/Actions/InitiatePaymentAction.php` 已先提交 attempt 再请求网关，保留历史商户配置 | 没有充值意图与分配公式校验 | 复用入口、attempt 编号和不可变 Epay profile；补充充值校验 |
| `app/Actions/HandlePaymentCallbackAction.php` 已有真实交易去重、迟到/多付处理 | Unpaid 成功分支只接受购买、续费、升配；非购买还要求服务操作快照，充值不能正常结算 | 在同一 Action 增加独立充值结算分支，不把充值伪装成商品购买 |
| 同一 Action 当前使用 `User::whereKey()` | 软删除账户的历史回调找不到用户，会回滚并失败 | 历史财务查找使用 `withTrashed()`，保留原财务身份；新交易仍要求活跃账户 |
| `app/Actions/RefundInvoiceAction.php` 支持 `refund_wallet`/`external_record` | `keepService=true` 可越过无实例限制；前者会给已充值钱包再加钱，后者不会扣除已充值余额 | Action 强制拒绝充值，后台同时隐藏不适用按钮 |
| `financial_transactions.reference_key` 唯一，金额有 `CREDIT_IN`/`PAYMENT` | 没有主动充值本金的唯一键约定 | 新增本金键协议，复用流水枚举及表 |
| `2026_11_18_000001_add_purchase_request_id_to_invoices.php` 和 INV-K5 | 购买幂等明确约定非购买字段为 NULL | 不复用购买键，避免把已批准购买契约悄悄扩大 |

现有整库基线由主协调方报告为 903 tests / 7999 assertions，PHPStan、Pint 通过；它证明现有实现的回归基线，不证明本文充值流程、迁移或并发路径已验收。充值专项与并发验证结果以实施验收记录为准。

## 3. 用户入口与可信输入

建议新增 `GET /console/wallet`，分页显示可用余额、冻结余额、充值记录和充值表单；新增 `POST /console/wallet/topups`。沿用现有登录、CSRF 及客户区权限，充值创建入口设置限流。创建后跳转现有 `/console/invoices/{invoice}`，支付沿用该账单的 `/pay`。回跳页只展示服务器账单状态，不以客户端支付回跳参数确认到账。

POST 仅接受稳定 ULID `request_id`、字符串 `amount` 和支持的 `gateway`。用户、币种、类型、状态、各金额列、商品/优惠/实例关联均由服务器确定；请求附带余额、到账金额、优惠券或其他账户 ID 不能改变这些值。

金额输入要求十进制字符串，至多两位小数；拒绝 float、指数、正负号、零、负数及超精度输入。使用 `Money` 规范为两位小数；`"1"`、`"1.0"`、`"1.00"` 视为相同意图。新建时校验范围 `1.00–10000.00` CNY 及列容量，永不使用 float 算术。后续调整限额不得重算既有有效账单。首轮没有充值金额或渠道修改入口；新意图使用新 key 创建新账单。

开关及上下限可按现有 `system_settings` 模式保存为 `wallet.topup_enabled`、`wallet.topup_min`、`wallet.topup_max`，默认关闭，在隔离验收及部署完成后启用；不新增独立配置表。充值有效期复用当前账单有效期策略。

前台与后台账单标题显示“钱包充值”；Paid 显示“已到账”，不能显示“等待开通”或“历史商品”。没有实例链接、交付按钮、产品续费按钮、优惠券及商品退款入口。列表按用户及时间分页，不能为了显示充值记录扫描或加载全部账单/流水。

## 4. 数据与幂等契约

### 4.1 复用与新增字段

唯一需要新增的充值业务列建议为 `invoices.topup_request_id VARCHAR(26) NULL`，并加 `UNIQUE(user_id, topup_request_id)`。新充值写大写规范 ULID；购买、续费、历史记录等保持 NULL，不回填或推断历史充值。不修改购买唯一键和续费恢复链接。

其余使用现有列：`type=wallet_topup`、`currency=CNY`、创建时 `status=Unpaid`，`product_id/instance_id/coupon_id/billing_period_key/purchase_request_id/renewal_root_id/replaces_invoice_id=NULL`，`discount_amount=credit_applied=0.00`，`upstream_expected_cost=0.00`。令规范充值金额为 A：

```text
subtotal = total_amount = gateway_amount = A
discount_amount = credit_applied = 0.00
credit_applied + gateway_amount = total_amount
```

充值金额由用户提出，经服务器范围校验确定；不是商品报价，也不能由商品定价或外部回调改写。`gateway` 必须是已支持且启用的渠道。没有钱包冻结、库存预留、优惠券使用、佣金或资源操作。

建议 `line_items` 保留公共展示结构，并增加非敏感版本化充值意图：

```json
{
  "items": [
    {"kind":"wallet_topup","ref_id":null,"label":"钱包充值","qty":1,"unit_price":"100.00","amount":"100.00","cycle":null}
  ],
  "subtotal":"100.00",
  "discount":"0.00",
  "total":"100.00",
  "wallet_topup": {
    "version":1,
    "request":{"amount":"100.00","currency":"CNY","gateway":"epay_alipay"}
  }
}
```

该意图只有与账单公开列相同的金额、币种和网关，无密码、身份资料或支付密钥；直接比较规范对象即可，不需要为它引入加密哈希或新密钥轮换规则。商户密钥仍只存在现有加密 Epay profiles，不进入 `line_items`。

### 4.2 创建与重放顺序

1. 认证、规范 ULID 与金额格式、建立规范意图；仅查本用户同一 `topup_request_id`。命中则比较原意图，相同返回原账单，变化拒绝，不先受当前充值开关、限额或网关配置变化影响。
2. 未命中才检查当前开关、金额范围和网关可用性；创建过程不请求远端。
3. 事务内先锁当前活跃用户，再以锁定读重查该用户/key，重复第一步判断；未命中才按当前规则建单。唯一索引作为最终并发后盾。
4. 只写一张 Unpaid 账单，不写金额流水、不变余额、不创建实例、attempt 或任务。相同 key 返回 Paid/Expired/Cancelled 等终态原单，不复活、不创建第二张。
5. 后续支付请求仍由 `InitiatePaymentAction` 锁活跃用户与账单，校验充值快照及恒等式，提交新 attempt 后再请求网关。网关超时/失败保留 attempt，显式重试创建新编号。

请求幂等不能替代真实收款去重；真实交易仍唯一于 `(gateway, gateway_trade_no)`，支付 attempt 仍使用现有独立 `out_trade_no`。

## 5. 到账、迟到及重复收款

所有分支继续由 `HandlePaymentCallbackAction` 处理。纯 Epay driver、DTO、验签、历史商户 profile 选择和已有路由协议不变。回调先去重，再按现有锁序锁原 user、invoice、attempt；配置元数据锁的使用继续遵循现有支付发起约束，不新增相反顺序。

### 5.1 首次足额充值

在网关匹配、CNY、Unpaid、充值快照及金额恒等式全部通过后，设实际付款为 P，P ≥ A。一个事务内执行：

| 财务效果 | 流水 | 唯一键 |
| --- | --- | --- |
| 可用余额增加 A，冻结余额不变 | `CREDIT_IN`, amount=A | 新协议 `topup:{invoice_id}` |
| 网关结算记录，只作信息展示，不再次改变钱包 | `PAYMENT`, amount=A | 复用 `payment:{invoice_id}` |
| 如果 P>A，可用余额再增加 P−A | `CREDIT_IN`, amount=P−A | 复用 `overpaid:{callback_id}` |

同时写 Paid、`paid_at`、网关交易号、attempt 成功状态、callback outcome，以及持久通知事件。P=A 为 `processed_paid`；P>A 为 `processed_overpayment` 并告警。钱包总增量严格为 P。每条余额流水记录当时 `balance_after/frozen_after`。

不写零金额 `credit_settle`，不冻结/解冻，不创建实例、交付任务、优惠券使用或佣金。`PAYMENT` 是现有信息性流水，报表不能将其与 `CREDIT_IN` 相加当作钱包增量或两笔收款；充值本金也不能直接并入商品销售收入。

### 5.2 回调路径矩阵

| 条件 | 建议处理 | 明确不发生的效果 |
| --- | --- | --- |
| 同一网关真实交易号再次通知，含并发重试 | 复用现有回调唯一约束，事务完成后 ACK | 不重复增加余额、流水或通知事件 |
| Unpaid，P<A | `rejected_short_amount`，保留原 Unpaid 与真实收款证据，持久告警人工核对 | 不先到账、不累计多笔不足付款、不结清 |
| Expired/Cancelled 后收到真实付款 | 现有 P2：按实际 P 一次 `credit_in:callback:{callback_id}`，`processed_late_credit` | 不复活账单、不改为 Paid、不双解冻、不交付 |
| Paid 后另一笔真实付款，包括另一 attempt | 现有 P3：按实际 P 一次 callback-keyed CREDIT_IN，`processed_overpayment` | 不再写本金键、不改首次到账证据、不交付 |
| 错误网关、非 CNY、未知订单，或 Unpaid 首次结算时充值快照/公式损坏 | 沿用对应 rejected/unknown outcome，保留证据并告警；P2/P3 保持现有状态分支顺序 | 不变余额、不结清、不依据客户端字段推断修复 |
| 未验签或非成功交易状态 | 保留现有协议层拒绝规则 | 不以浏览器回跳或未验证内容记账 |

过期扫单可复用现有路径：充值 `credit_applied=0.00`，不产生虚假的解冻流水。首轮不新增手工取消充值功能；以后如开放，仍适用 Cancelled 后 P2。

列容量上限为 `9999999999.99`。回调必须在用户锁内先确认本次全部预计贷项可写入，不能只检验 A 后再让多付款部分溢出，也不能截断金额或部分入账。建单时的容量检查不能替代收款时检查。建议容量不足统一保存 `rejected_invalid_state`、可检索的 `wallet_capacity` 对账原因及持久人工告警，金额、Paid 与流水全部不变，提交后 ACK。损坏快照采用同样的“保留证据、拒绝自动变钱、人工核对”原则；原因随持久通知事件/审计证据保存，不增加一套充值状态。

此容量处理需要随异常收款决议确认：ACK 后同一真实交易的重放只去重，不会在余额后来下降时自动重入账；人工核对必须基于原 callback，以另行授权的唯一补偿记录结案。不能通过删 callback、反复改账单或伪造另一交易号恢复。

### 5.3 匿名与注销账户

新建充值和发起支付必须锁定未软删除账户；与注销共用用户行锁。注销的前置条件应阻止存在 Unpaid 账单或非零钱包余额的账户关闭。双方不能在同一时间各自通过过期的无锁检查。

历史回调仍按保留的原 `user_id` 用 `withTrashed()` 锁财务主体。对现有 P2/P3 允许的合法贷项，记入原账户保留钱包，写唯一流水及人工清退告警，不恢复登录、不创建服务、不发到已匿名渠道、不转移至后来用相同邮箱/手机号注册的账户。该资金是历史财务待清退项，不是新账户可消费余额。

若发现已注销用户仍有 Unpaid 等违反注销前置条件的历史数据，保留证据并拒绝自动交付，转人工核对；不能以“找不到活跃用户”返回 500，也不能默默创建用户。账号关闭模块与钱包模块在此共用一份 callback 改动，由主协调方统一合入。

## 6. 通知与对账交接

充值到账事件和不足、多付、迟到、容量不足等需处理的事件，应使用正在统一设计的持久 notification outbox，在同一个记账事务中写入。发送和第三方 HTTP 在提交后进行，扫单补发可以恢复“事务提交成功但 queue 投递丢失”。仅 `afterCommit` 写日志或仅 queue dispatch 不等于持久事件已送达。

事件应有稳定去重身份，例如 `wallet.topup.credited:{invoice_id}` 和 `payment.callback.attention:{callback_id}`。事件携带原 user/invoice/callback ID、金额和原因，不复制支付密钥或匿名前联系方式；接收者与匿名账户策略由公共通知方案确定。通知失败不回滚已提交的收款；事件写入失败则整个收款事务回滚，网关重试仍安全。

不足付款、无法自动入账款及匿名后贷项必须能按 callback 查到处理责任，不把告警视为已经退款或清退。本期不顺带实现外部自动退款、钱包提现、赠送额度或跨账户划转。

## 7. 需要限定修改的规范

以下仅列出批准后需要修改的契约位置，本轮不改这些规范：

| 规则 | 所需限定 |
| --- | --- |
| F02、INV-I3、INV-P1 P8 | 商品 Paid 必须同事务建交付；充值 Paid 必须同事务增加钱包、写唯一流水与持久通知事件，不建资源任务。充值不允许零金额快捷结算 |
| INV-I1/I2/I5 | 增加充值无折扣、无钱包抵扣的恒等式，以及 `wallet_topup` 展示行/意图版本结构 |
| INV-I4 | “一个商品”限定到商品/服务账单；充值商品及实例关联为空，不引入多商品账单 |
| INV-W2/W5 | 本金键 `topup:{invoice_id}`，明确 PAYMENT 信息项不等于第二笔钱包变动；沿用已有 callback/excess 键 |
| INV-L2 | 增加 `CreateWalletTopupInvoiceAction` 为充值账单创建者；钱包到账仍只由 HandlePaymentCallbackAction 写入 |
| INV-K5 旁的新充值幂等规则 | 保持购买专用规则不变；单列充值用户/key 唯一、规范意图比较、终态重放及锁内复查 |
| F01 | 保留原财务用户标识，财务历史查询含软删除；活跃交易与注销的锁序一致 |
| INV-R1 / F06 | 通用商品退款明确不接受充值；关闭首轮充值退款，不借 `keepService` 绕过 |

## 8. 后续实现文件责任

| 负责人范围 | 文件/入口 | 修改责任与协作边界 |
| --- | --- | --- |
| 钱包模块 | 新 `app/Actions/CreateWalletTopupInvoiceAction.php` | 仅建单、意图规范与幂等；余额到账不拆成另一个公开写入入口 |
| 钱包模块 | 新客户 WalletTopupController、充值 Blade 页、`routes/web.php` | 鉴权/CSRF/限流、分页、稳定表单 key、只读余额和充值提交 |
| 钱包模块 | 新迁移及 `Invoice` 使用处 | 充值独立列和复合唯一索引；不扩展 purchase_request_id，不迁现有数据库 |
| 主协调方统一合入，钱包模块提供结算实现 | `HandlePaymentCallbackAction.php` | 类型明确的充值分支、钱包锁/容量/流水、历史用户 withTrashed；与注销和通知 outbox 避免覆盖 |
| 主协调方统一合入 | `InitiatePaymentAction.php` | 活跃账户与充值快照校验，保持 metadata→user→invoice→attempt 及 HTTP 在事务外 |
| 主协调方统一合入 | `RefundInvoiceAction.php`、后台财务退款入口 | 业务 Action 拒绝充值，并隐藏相应退款操作；保留现有商品退款严格断言 |
| 钱包与通知模块协作 | 持久通知事件创建 API | 在原事务写事件，提交后发送/重试；不各自新建重复 outbox |
| 钱包模块 | `resources/views/sales/invoice*.blade.php`、后台财务账单视图 | 充值类型/金额/状态文案，无商品/实例/退款误导 |
| 主协调方 | 上述规范文件及批准记录 | 人工确认后才修正文案，不用提案替换现行财务规则 |

现有 `EpayGateway`、`VerifiedPaymentResult`、支付协议、已有枚举、上游驱动与商品履约不需要为充值另建版本。若实现发现必须增加协议字段或状态，先提交具体差异，不静默扩展。

## 9. 实施后验收矩阵

| 组别 | 必测场景 | 必须验证的结果 |
| --- | --- | --- |
| 输入/范围 | 最小、最大、低/高一分、零、负数、float、指数、三位小数、非 CNY、伪造 owner/分配字段 | 规范金额或验证失败，所有失败零副作用 |
| 创建幂等 | 同 key 等价金额、多次提交、改变金额/网关、大小写 ULID、两用户同 key、终态重放、开关/限额后变 | 一用户一账单；变更意图拒绝；重放不请求网关、不复活 |
| 创建副作用 | 普通创建和并发两个相同创建 | 一账单，余额/冻结/流水/库存/实例/任务不变；真实 MySQL 唯一约束有效 |
| 支付尝试 | HTTP 前 attempt 已提交，超时/显式失败重试，商户切换及历史回调 | 每次显式支付新 out_trade_no，旧编号与原 profile 保留且可验签 |
| 足额/多付 | P=A、P>A | 钱包分别增加 A/P，本金及 excess 键各一次；Paid/outcome/attempt 正确，无商品副作用 |
| 不足 | 一笔不足、两笔各不足、重复不足、后来足额 | 不累加，不足有独立证据/告警；足额只到账本次合法金额，不吞并不足款 |
| 回调并发 | 同 trade 并发、同账单两 distinct trades、多个 topup 与购买冻结/过期解冻并发 | 去重唯一，实际应记贷项均一次，不丢余额、不负冻结、不双履约 |
| 终态 | Expired/Cancelled 迟到，Paid 第二笔真实款，已过期 UI 支付 | P2/P3 wallet-only，终态不复活、不双解冻、不建立任务 |
| 注销/重注册 | 新建充值/发起支付与注销竞态，匿名后迟到、相同邮箱重注册 | 活跃入口互斥；历史贷项只落原 ID，人工告警，无原渠道通知/新账号继承 |
| 退款隔离 | 两种通用退款模式，包含 keepService=true | 充值均拒绝且零副作用；原商品退款/佣金追回断言保持通过 |
| 事务故障 | 本金流水、excess、账单或 outbox 写入失败后重试 | 整体回滚，无“Paid 未到账/到账无凭据”；重试完整一次 |
| 异常证据 | 列容量不足、损坏快照/公式、错误网关、非 CNY、未知单 | 原回调及原因可检索，按规则 ACK/告警，不部分记钱、不偷偷修复 |
| 通知恢复/UI | 提交后 queue 丢失，失败渠道，事件重放，充值账单前后台显示 | 扫描持久事件可恢复，发送不重复记账，文案无交付/退款误导 |

开发验证使用临时 SQLite/MySQL、随机 APP_KEY 与临时 storage，HTTP/queue fake；真正的锁竞争需要独立 MySQL 多连接测试，SQLite 不能证明行锁。不得向现有验收/业务库运行 migrate 或 migrate:fresh，不触发真实支付、供应商或 worker。

保持全部现有测试名、严格业务断言与 Specs；新增独立充值测试，并跑相应目标测试、真实 MySQL 并发矩阵、全量 Pest、PHPStan、Pint。只有这些新增路径实际执行后才能报告“充值已验收”；预览环境数据库迁移、启用充值、真实支付/外部退款仍是单独的部署和验收动作。
