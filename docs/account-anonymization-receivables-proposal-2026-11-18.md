# 账户匿名注销与退款应收清偿定向方案

日期：2026-11-18（Asia/Shanghai）。状态：**D02/D04 已批准并实现。** 本文保留提案依据与定向规则；交付、隔离验证及部署边界见 [五项能力实施验收](remaining-capabilities-implementation-2026-11-18.md)。旧严格断言和 Specs 保留，未访问原预览库或真实供应商。

## 1. 最小人工选择点与推荐

1. **注销资格与身份释放**：推荐只开放本人当前密码加明确不可逆确认；余额、冻结、待办、应收及后台权限未清理则阻断，不提供管理员强制没收/强制注销。成功后释放原邮箱、手机号和 OAuth subject 供重新注册/绑定；新用户 ID 不继承旧用户的订单、服务、余额、实名或推广关系。永久保留原身份黑名单需另行批准 HMAC tombstone 和密钥策略，本方案不默认保留。
2. **注销后真实迟到款**：推荐仅财务回调用 `withTrashed()` 解析原 user_id，按既有验签、币种、去重和终态决策表追加记账；不复活账号、不发货、不归到同邮箱新账号。新增贷项进入原 ID 的保留钱包并产生人工清退告警，不能丢弃收款或自动没收。零余额是注销提交时的前提，不承诺所有未来迟到款永远为零。
3. **应收清偿来源与授权**：推荐首轮只登记财务人员核实已经完成的外部 CNY 收款，新增独立 `finance.receivable` 权限；不自动抵扣普通钱包或新成熟佣金。允许部分清偿、完整冲正单笔误登记和再次正确登记；所有财务流水追加。需批准两张新表、两个 TransactionType、固定财务角色及 INV-R4 的定向补充。清偿登记不调用收款/付款供应商。
4. **留存范围**：推荐本轮为“账号认证/联系资料匿名化”，保留既有金融、交付、投诉及审计证据。产品文案必须列明下表中仍保留的业务快照，不能宣称所有历史个人数据或供应商资料已删除；若要求工单正文、历史支付 payload、实名外部关联、历史服务凭据等全面擦除，应另定保留期限、字段和例外，不能静默破坏 F01/幂等轨迹。

## 2. 已核实的现有事实

- `CLAUDE.md` §3.1/F01 要求有财务、履约、推广记录的用户永不物理删除，注销为 SoftDeletes + `anonymized_at`；INV-A1 要求匿名化审计。`users` 已有 `phone`、`credit`、`frozen_credit`、`affiliate_balance`、`referred_by`、`anonymized_at`、`deleted_at`，无需为基础注销新增字段。
- `User` 使用 SoftDeletes；密码为 hashed cast；`AuthenticateCustomerAction` 和 `CustomerEmailLookup` 排除软删用户。`CustomerIdentityAction` 重置密码会轮换 remember token、删数据库 sessions；全站 web 已启用 AuthenticateSession。`RegisterCustomerAction` 保留未匿名化软删身份的邮箱；`PhoneBindingAction` 同样检查软删身份的手机号。
- `SocialLoginAction` 按 provider/subject 绑定原用户，绑定/解绑需要本地密码；OAuth 不靠同邮箱自动合并。`user_oauth_accounts` 和 `user_notification_bindings` 不属于金融账本，可取消绑定；目前没有实际使用的 OAuth access/refresh token 保存逻辑。
- `RefundInvoiceAction::clawback()` 唯一追加 `clawback:{commission_id}`、类型 `affiliate_clawback`。原始应收仅在 `financial_transactions.meta.receivable_amount`，原始金额不是流水的 `amount`：后者是整笔佣金原额。pending 佣金撤销应收为 0；available 追回不足才有应收。
- `AffiliateFunds::hasReceivable()` 目前只看任何历史正应收；`RequestAffiliateWithdrawalAction`、`ProcessAffiliateWithdrawalAction` 依此阻断申请和批准。客户 `/console/affiliate` 与后台 payouts/ledger 直接投影原始 meta，目前无清偿/冲正记录。
- `financial_transactions` 金额是 UNSIGNED DECIMAL，`reference_key` 唯一；模型拒绝更新/删除。INV-R4 禁止自动扣普通钱包，未来清偿必须读取原始应收；INV-L1 要求 users→invoices→payment_attempts→instances→redemption_codes→fulfillment_tasks 的锁序，INV-L3 禁止锁内 HTTP。

## 3. 模块一：本人匿名注销

### 3.1 入口和确认

- 在 `/console/account` 增加本人注销的独立区域，GET 资格预检只读，POST `/console/account/close` 才提交；session、CSRF、按用户限流、`private, no-store`。不接受任意 target user_id，不以后台权限替代本人密码。
- 必须提供当前密码和明确确认项（如 `confirm=1`，文案“注销无法恢复，历史财务和服务证据依法及按业务规则保留”）。事务锁定用户后再核对当前 password hash，旧的密码重置、OAuth、手机挑战或已撤销会话不能授权本次操作。不把明文密码、原邮箱或手机号复制进审计/错误日志/old input。
- 不强依赖 SMTP、短信或 KYC 供应商；现有账号都有本地密码，忘记密码走既有找回流程。普通登录成功或点击 GET 不能视为注销授权。
- 用同一用户行上的 `anonymized_at` 串行化一次性效果；已完成的内部重复调用返回相同终态，不再审计或更换占位身份。已软删但未匿名化的旧账号不被此入口擅自处理。HTTP 注销后未登录重试按认证规则处理，不恢复旧身份。

### 3.2 提交时阻断项

预检给出安全、可操作的原因；最终事务必须重新检查，不能凭预检 token 放行。只查 exists/金额投影，不全量加载用户历史。

| 范围 | 阻断条件 | 用户/运营后续动作 |
|---|---|---|
| 普通资金 | `credit`、`frozen_credit` 任一非 0 | 先按既有或另行批准的钱包退款/支付规则清理；本 Action 不扣除、赠送或改账 |
| 推广资金 | `affiliate_balance` 或外部提现方案拟新增 `affiliate_frozen_balance` 非 0 | 先完成提现/核对；冻结字段由 root 统一迁移，本模块只兼容读取 |
| 账单/付款 | 本人任意 Unpaid 账单（含充值）、未决支付/退款财务核查 | 先完成或按原 Action 取消/到期；不能由注销悄悄改终态或解冻 |
| 资源 | 任意非 Cancelled/Deleted 的服务，或历史 `kept_orphan` 资源尚未被认证终止/移交 | 明确终止/移交后再注销，ProvisionFailed/Fraud 不能仅凭名字认作已终止 |
| 任务/远端结果 | pending/running、可能已发送未对账、create_requested/control_accepted 等未决意图 | 原流程认证结果；仅 failed/stale 状态不自动等于没有远端业务义务 |
| 提现 | pending、approved 待付款、processing/unknown 等未决提现 | 先完成/证明未支付并释放冻结；不能把未知外付标记失败 |
| 推广待结 | referrer 身份下 pending 佣金、已存未结转推广快照（未付或已付待计佣） | 先成熟/撤销/核对；不能利用软删让计佣器忽略既有权利 |
| 应收 | 依模块二投影仍有正数未清偿应收 | 先清偿；出现不能解析的历史金额/引用则待人工核对，不能当作 0 |
| 身份/权限 | pending KYC、持有后台角色或直接后台权限 | 先核对实名远端状态；先移交并由受信权限流程撤权，不允许通过注销删除最后管理员 |
| 工单/通知 | 非 closed 工单；已经进入发送中、仍含可投递身份的通知 | 先解决工单；通知按下节与 outbox 模块协调，不承诺撤回已开始的 HTTP |

已完成的金融/履约记录本身不阻断注销，否则 F01 将变成“所有付过款的用户永远不能注销”。历史 Expired/Cancelled/Paid/Refunded 账单保留；未来仍可能有真实迟到回调，按 §3.5 处理。

### 3.3 同一事务内的数据处理

先锁 active 用户，按原全局锁序检查有关账单、attempt、实例、卡、任务；其他用户关联记录依稳定 ID 排序。通知锁统一在 users 之后并与 outbox 模块确定 events→deliveries 顺序，禁止反向等待用户锁。

1. 重新核对密码、确认和所有阻断；任一失败，身份与会话保持原状。
2. `name` 改固定“已注销用户”，`email` 改 `closed-{id}-{随机不可预测串}@deleted.invalid`，`phone=NULL`；`email_verified_at/kyc_verified_at=NULL`。生成未知随机密码并仅保存 hash，remember token 轮换/清空。`created_at`、原 user_id、金融关系和零余额不改。
3. 取消 OAuth 绑定、通知绑定，清理原邮箱的 password_reset_tokens 和该用户数据库 sessions。清理该用户 phone-binding、phone-send 等挑战缓存；缓存清理在 commit 后补偿执行，缓存失败也不能重新授权已注销用户。OAuth 回调和 KYC/手机确认在各自用户锁内复查 active 状态，不接受旧确认。
4. outbox 协作：锁用户后取消尚未开始发送的事件/投递，清除其收件人密文，保留事件键及业务关联；同时将该用户既有 notification_logs.target 替换为匿名占位，保留投递状态、时间和业务关联，不保留旧收件目标。新队列只带 delivery ID。旧 job 若失效，只记匿名 target，不能 skipped 后把旧邮箱/手机号重新写回 notification_logs。历史发送中的 HTTP 无法撤回，需明确记录“可能已投递”；不能长事务跨外部 I/O 来制造绝对撤回保证。
5. 同事务设 `anonymized_at` 和 `deleted_at`，追加唯一效果的 `identity.account_anonymized` 审计：actor/subject 原 ID、确认版本、阻断检查通过、清理对象计数、时间、IP/request_id 和非空系统描述；不记录原 PII 或密码。审计失败则身份、绑定和数据库 session 处理一起回滚。
6. commit 后 Controller `Auth::logout()`、invalidate 当前 session、regenerateToken，跳到登录页显示完成。文件/Redis/cookie 的其他 session 不靠全库扫描删除；SoftDeletes 的用户 provider 加密码摘要变化保证下次请求拒绝，数据库 sessions 额外即时清理。旧 session 不得继续进入 `/admin` 或 `/console`。

### 3.4 PII 与业务证据留存清单

本轮不会将“可识别信息已从所有历史记录彻底消失”作为验收标准。以下选择须进入最终用户文案及人工决议。

| 数据 | 推荐处理 | 仍保留的内容/原因 |
|---|---|---|
| users 的 name/email/phone/password/remember_token/验证标记 | 替换或清空，禁用登录 | ID、时间、零余额、anonymized_at/deleted_at、referred_by 数字关系用于既有归因；不自动把邀请链转给新账号 |
| OAuth subject/meta、通知绑定 target/meta | 删除绑定行 | 保留无 subject/target 的匿名化计数审计；不向第三方发解除授权请求，不宣称供应商资料已删除 |
| 原邮箱重置 token、数据库 sessions、当前 session、手机挑战缓存 | 删除/失效 | 不保留 session payload、IP/UA 副本；已有独立安全审计按下项留存 |
| invoices、payment_attempts/callbacks、financial_transactions、优惠券及推广记录、audit_logs | 不删不改既有账本/审计，前台不再可访问 | 原 ID、金额、币种、外部交易号、时间、引用键、财务证据；已有 remark/meta/raw_payload 可能含历史 PII，不做不可靠的全文替换 |
| instances、binding 历史、已售卡、fulfillment/upstream 记录 | 保留业务证据与 FK；旧用户无法查看 | 终态资源标识、主机名/IP、配置、加密凭据、encrypted_request/encrypted_operation 及幂等身份；删除/重写会破坏已批准的重放/审计契约，全面销毁需单独定向决定 |
| KYC 历史 | 保留状态、外部申请 ID、时间及现有脱敏摘要 | 不再保留 users 的当前已认证标记；本地从未保存完整证件号。已脱敏摘要和外部关联也属于受限业务留存，并非完全匿名数据 |
| 已关闭工单/回复、已处理提现收款资料 | 保留投诉/财务证据，不复制到新匿名化日志 | 可能含自由文本或历史收款资料；仅按既有权限可看，不能承诺账号注销自动擦掉这些记录 |
| 既有 notification_logs | 将该用户的 target 替换为匿名占位，不保留旧邮箱、手机号或其他收件目标 | 保留 user_id、事件/渠道、投递状态、时间和业务关联；旧 job 与并发日志写入也必须复查匿名化状态，禁止写回旧 target |
| 新通知 outbox 未发送目标 | 取消并清密文；兼容旧 job 的匿名 skipped 路径 | 仅保留事件键、状态及业务关联，不再向释放后同邮箱/subject的新用户投递 |

### 3.5 历史迟到及并发集成

- 当前 `HandlePaymentCallbackAction` 用 active `User::whereKey()->firstOrFail()`。匿名化后旧有效回调会 500，因此 **只做 SoftDeletes 页面而不修此边界不能交付**。财务 owner 定位改为按原 ID `withTrashed()`；同样适用于充值模块的已验签历史回调。
- 回调维持原 gateway/out_trade_no/trade_no 唯一核验、CNY 前置规则、Paid 第二笔/Expired/Cancelled 钱包处理、Refuned 拒绝等原决策。注销不重开 invoice，也不让原订单产生新服务。若发现注销后的异常 Unpaid/未决交付状态，不能直接发货，应保存已认证收款证据并告警人工核对，新增 outcome/隔离模型由 root 同钱包模块统一决议。
- 因原 ID 软删而新增的 wallet 贷项必须告警人工清退；不能按邮箱查新 user。外部支付已被受理是事实，用户资料清理不能吞款。账户解禁、继承旧余额或自动付款退款不在本方案内。
- 新购、充值、支付发起、提现、绑定、权限写入等已有/新增 Action 均在 active 用户锁后重新验证；注销若先获得用户锁，后续业务不得创建。业务若先获得锁并创建义务，注销随后的重新检查应阻断。回调若先入账，余额阻断；注销若先提交，回调进入原 ID 的金融留存路径。
- 已保留推广快照的结转不能因软删悄悄丢弃；提交前阻断未结转快照，历史后续退款仍可对原推广 ID 形成应收并告警。`RefundInvoiceAction` 当前已对买方/推广人使用 withTrashed，清偿模块也要支持原软删财务 owner。

### 3.6 验收与 schema

基础匿名化不需新表/用户字段。读取 root 外部提现增量的 `affiliate_frozen_balance`；通知 outbox schema 由对应模块负责。测试须覆盖每类阻断、错密码/缺确认/跨用户、三种 session 后端失效、旧 remember/reset/OAuth/短信/KYC proof、邮箱/手机/subject 释放后新 ID 隔离、审计故障回滚、幂等、注销与下单/回调/提现并发、后台角色阻断、未来迟到款、不向旧 target 重新发信。仅使用隔离 SQLite/MySQL、临时 storage/APP_KEY 和假驱动。

## 4. 模块二：退款佣金应收的外部收款登记与冲正

### 4.1 最小业务合同

- 仅处理原始类型 `affiliate_clawback` 且 `meta.receivable_amount > 0`、CNY、commission/user/invoice 引用一致的应收。原始金额不能解析、币种/引用冲突则拒绝登记并待人工核对。原始 `amount` 是整笔佣金，不能当作应收本金。
- 管理页只读权限 `finance.view`；记收款/冲正要求额外 `finance.receivable`，建议固定角色 `finance-receivable`。事务内锁实际 actor 并重查权限，required reason/确认/当前展示版本；无普通客户写入口，CLI 也必须提供真实有权 actor，不能用 null 冒充系统确认收款。
- `source=external_received`：财务核实已收款后输入正数金额、外部渠道和唯一收款凭证引用，明确勾选“已实际收到 CNY”。不收银行卡号、证件号或任意账号密码作为凭证；不调用外部支付接口，不声明登记即产生银行到账。普通钱包和 affiliate_balance/affiliate_frozen_balance 均不动。
- 只接受 `0 < amount <= 当前剩余应收`，禁止超收、负数、0、其他币种。一次记录只分配到一笔原应收；一张银行收款凭证首轮不能拆到多笔应收或多用户。金额多于单笔应收的收款需先人工分清来源，不能把差额偷偷记钱包。
- 一笔应收可多次部分清偿；每次都保留自己的 request ULID/凭证/actor/ledger。旧请求同意图重放返回原记录；变更本金、金额、渠道、凭证或操作类型必须拒绝，且每次重放也重新验证权限。

### 4.2 追加账本与剩余应收

新增 TransactionType：`affiliate_receivable_settle`、`affiliate_receivable_reverse`（均小于现有 type 32 字符）。金额始终为正数；两者都不增加 wallet、不表示推广余额成熟或外付，`isCredit/isAffiliateBook` 不把它们当余额增减。

每次登记在同一事务写结算记录、唯一流水 `receivable_settle:{request_ulid}` 和审计；流水带原 user_id/invoice_id、currency、原 clawback_transaction_id、request_id、source、明确“external_received/no_wallet_movement”语义及当前钱包/冻结快照。每次冲正追加 `receivable_reverse:{request_ulid}`、冲正记录和审计；不更新或删除原 clawback/settle/reverse 财务流水，不把原 meta.receivable_amount 改为 0。

对每笔原始 clawback：`剩余 = 原 meta.receivable_amount - Σ已登记清偿 + Σ已完整冲正清偿`。用 Money 和固定点字符串计算，不用 float；无冲正时只减净有效清偿。结果不许小于 0 或大于原始本金，异常应保持提现阻断并告警。用户总应收是各原始记录的剩余合计，不能用后续正佣金余额替代清偿证据。

`AffiliateFunds::hasReceivable()` 改用上述统一投影；客户 affiliate、后台 payouts/ledger 同时展示本金、有效清偿、剩余、历史记录，避免后台显示已清但提现仍永远阻断。有一笔剩余 > 0，继续阻断新申请/批准；成熟佣金仍正常入佣金余额但不自动抵销。

### 4.3 冲正语义

- 首轮一次冲正完整撤销**一笔清偿登记**；原应收可分多笔清偿，因此需要部分纠正时完整冲正错误那一笔，再按正确金额新登记，避免额外部分冲正余额系统。冲正必须独立确认“原登记不成立/已完成外部退回且现需恢复债务”，提供原因和证据引用；它不自动退钱、转账或更改银行事实。
- 同一清偿最多完整冲正一次；重复同请求返回同结果，另一个冲正请求不得再次恢复同一金额。不能冲正原 clawback 或冲正一个 reverse；不能以冲正修改 commission 的 clawed_back 状态。
- 冲正后可沿明确 `corrects_settlement_id` 链使用同一外部凭证重新正确登记，但同一时刻只能有一个有效登记，不能分叉。原错误记录和凭证引用保留。更正不同应收/用户时同样有权验证、当前未清余额和单一有效凭证约束，不能通过换 request_id 复用已生效收款。
- 冲正重新产生正应收时立即恢复未来提现阻断。已经实际付出的外部款或已完成转钱包不会被倒改；与已冻结/发送中的外付冲突应告警人工核对，不能谎称已经撤回，也不能因新债务把外付 unknown 冻结释放。

### 4.4 拟新增 schema（待批准，文件名由 root 统一分配）

`affiliate_receivable_settlements`：

- id；user_id、clawback_transaction_id、invoice_id（均 restrict FK）；amount DECIMAL(12,2) UNSIGNED、currency CHAR(3)；source（仅 external_received）；external_channel、external_reference（收款凭证号，不是收款人账号）；request_id CHAR(26) UNIQUE；processed_by（restrict users FK）、reason、received_at、timestamps。
- ledger_transaction_id（restrict financial_transactions FK，UNIQUE）。可先写包含 request_id 的 ledger 再写本记录，整体同事务，避免已插入不可变核心字段后二次补写。
- `active_receipt_key` CHAR(64) nullable UNIQUE：规范化渠道与完整凭证引用的稳定 SHA-256；有效登记必须非空，防止跨应收/用户的同凭证并发重复。这里只哈希非秘密凭证标识，不存账号/密码，不新增需轮换的身份 HMAC 黑名单。
- `corrects_settlement_id` nullable restrict self-FK UNIQUE，修订只接已完整冲正的上一笔，禁止分叉。`reversed_at` nullable 只可由唯一冲正 Action 从 NULL 写一次；同时把 active_receipt_key 置 NULL，允许后续正确修订。核心金额/引用/ledger/外部凭证字段不可改；这些操作状态字段不替代下表及财务账本。
- 索引 `(user_id, clawback_transaction_id, id)`、`(clawback_transaction_id, id)`；原金融表已有 `(user_id,type)` 可定位应收根，不新增原账本字段。

`affiliate_receivable_reversals`：id；settlement_id（UNIQUE、restrict FK）、user_id、clawback_transaction_id、invoice_id（restrict FK）；amount/currency 为原 settlement 的服务器快照；request_id CHAR(26) UNIQUE；ledger_transaction_id（UNIQUE、restrict FK）；processed_by（restrict FK）、reason、correction_reference、created_at。记录不更新/删除。不能依 request 的 amount 或 user_id 改变原身份。

金额权威始终是原始 clawback 和清偿/冲正 ledger；reversed_at/active_receipt_key 是唯一凭证占用与工作流投影。若投影/唯一 ledger/冲正行不一致则停止新钱操作并告警，不静默推断“已清”。撤销有效凭证占用、追加冲正、恢复剩余和审计在一个事务中完成。

### 4.5 锁序与竞争

- 无锁预读只发现原 owner/actor/原 invoice 和修订链 ID；事务先将所有涉及用户按 ID 升序锁定（财务 owner 可 withTrashed，actor 必须 active），再按既有顺序锁有关 invoices。清偿原 clawback、settlement/reversal 记录按稳定 ID 顺序在后锁定；不拿 receipt 行锁后再回头拿 users/invoices。
- 在用户锁内重查原始币种/引用、本金、清偿/冲正完整性和剩余；双请求不能都基于旧剩余超额收款。request、ledger、active receipt、corrects parent 以及 reversal UNIQUE 是持久后备；冲突整事务回滚，不让完整流水孤立于清偿记录之外。
- 新提现/批准/外付冻结、RefundInvoiceAction 新应收、成熟佣金、注销均先锁同一 user；先后顺序由持锁时的最终数据决定。撤权也锁 actor，使先撤权的请求不能沿用页面权限。没有 lock 内供应商或银行 HTTP。
- 两用户拿同一外部凭证时即使各自 user 锁不冲突，active_receipt_key UNIQUE 仍只允许一笔生效；失败者得到凭证已登记的字段错误，不能重试换 key 绕过。

### 4.6 验收

本金/部分/多笔/全清、同请求重试及改意图拒绝、零/负/超额/非 CNY、错误 clawback/commission/user 引用、全额冲正/重复冲正/纠正复用凭证、跨用户同凭证并发、两清偿竞争剩余、清偿与提现/注销/新退款并发、冲正恢复阻断、撤权与越权、审计/ledger/记录任一步失败整体回滚；原 financial_transactions 逐字段不变、两个余额均不动；历史无清偿记录仍按原应收阻断。隔离 MySQL 验证 row locks/unique，SQLite 不代替并发验收。

## 5. 两个实现模块与文件所有权建议

| 模块 | 独占新文件/现有文件 | 需由 root 或对方统一编辑 |
|---|---|---|
| A 账户匿名化 | 新 `CloseCustomerAccountAction`、`AccountClosureEligibility`、`CustomerAccountClosureController`、独立 `sales/account-close.blade.php`、新 `AccountAnonymizationTest` 及隔离验收脚本；账户专属身份失效兼容改动 | `routes/web.php`、`bootstrap/app.php`、原 account 页链接、回调 `withTrashed`/迟到分支由 root 合并；notification outbox 文件由通知模块独占；不编辑其 schema |
| B 应收清偿 | 新 `RecordAffiliateReceivableSettlementAction`、`ReverseAffiliateReceivableSettlementAction`、`AffiliateReceivableBalance`、两模型/迁移、专用 Controller/Filament 页面/view、新专项测试；`AffiliateFunds` 和客户/后台应收投影由 B 统一改 | `TransactionType`、`AdminRoles`、AdminPanelProvider、routes、规范文档及迁移序号由 root 合并；与外部提现模块约定只调用统一应收投影，不双方修改提现 Action |

建议 A 与 B 分开实现和验收：A 依赖 B 的统一剩余投影、外付冻结字段和通知取消接口，但通过稳定服务方法集成；未交付依赖时保守阻断，不把未知状态当作 0。两模块都不改旧严格断言/Specs，不直接写预览/业务数据库，不自动清理历史数据或执行真实收款/退款/供应商写入。需要针对 F01、INV-R4、新账本词汇/权限/schema 的批准记录后才能进入实现。

## 6. 源码核对入口

`app/Models/User.php`；`app/Actions/{RegisterCustomerAction,CustomerIdentityAction,SocialLoginAction,PhoneBindingAction,InitiateKycAction,ApplyKycStatusAction,HandlePaymentCallbackAction,RefundInvoiceAction,AccrueAffiliateCommissionAction,MatureAffiliateCommissionsAction,RequestAffiliateWithdrawalAction,ProcessAffiliateWithdrawalAction}.php`；`app/Services/{AffiliateFunds,NotificationManager}.php`；`app/Actions/SendEventNotificationAction.php`；`app/Models/FinancialTransaction.php`；`app/Enums/TransactionType.php`；`database/migrations/2026_11_16_000001_create_cloud_cms_core_tables.php`；`bootstrap/app.php`；`docs/{domain-invariants,agent-guardrails,refund-decisions,operations-expansion-decisions,cms-identity-decisions,external-drivers-spec}.md`。本方案基于当前源代码，未通过真实业务数据判断可注销人数或应收金额。
