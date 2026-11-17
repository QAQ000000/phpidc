# 文档、接口与规格冲突裁决记录

状态：**C01–C09 已由人工于 2026-11-17 批准，定向修订已应用**。本文件保留修订前证据并记录裁决与修改范围；规范内容已同步到 `CLAUDE.md`、业务不变量、接口和指定规格。本记录不是后续修改受保护规则或测试的通用授权。

本轮按批准范围修改规则、支付接口、金额表示及指定测试契约。上一轮基线修复仍保留在工作区；17 个待实现规格的名称及计数没有变化，首个 checkout 用例逐字保留。尚未实现业务 Action、Driver 或 Job。

## 1. 修订前验证的事实（历史证据）

以下结果是在修订前，使用独立 SQLite `:memory:` 数据库迁移并创建测试数据后确认的；不是修订后行为：

| 读取方式 | 实际结果 |
|---|---|
| `User::factory()->create(['credit' => '100.00'])->fresh()->credit` | 字符串 `"100"` |
| `$invoice->fresh()->status` | `InvoiceStatus` 枚举 |
| `PaymentCallback::whereKey($id)->value('outcome')` | `PaymentCallbackOutcome` 枚举 |
| `$task->fresh()->status` | `FulfillmentTaskStatus` 枚举 |
| 规格中的 `invoice_no . '1'`，没有对应 payment attempt | attempt 查询和 invoice_no 精确回退查询均不匹配原账单 |
| `Money::of(0.1)` | 修订前接受 float，返回 `"0.10"` |

这些验证没有连接业务数据库，也没有调用外部 API。它们只证明修订前的类型、金额表示和查询契约，不构成并发或上游验收。

## 2. 已批准并应用的裁决

### C01：模型状态使用枚举，传输和数据库存储使用字符串

修订前证据：`app/Models/Invoice.php:29`、`Instance.php:31`、`FulfillmentTask.php:28` 和 `PaymentCallback.php:30` 已配置枚举 cast；规格第 95、121、172、196、265、280、366 行比较的是字符串，第 122 等行的 Eloquent `value('outcome')` 同样会返回枚举。

裁决：保留现有枚举 cast。规格在比较模型属性或 Eloquent 返回值时，严格比较对应枚举 case；API/DTO 序列化和直接数据库快照使用 `->value`。

已应用的定向修改：仅修正这些类型预期，保留具体状态与严格 `toBe`，不得改成宽松匹配。若确需验证原始持久化字符串，另以数据库断言证明，不能把模型接口改成随读取方式变化。

### C02：驱动不写模型；领取库存、交付与状态同步由 Action 编排

修订前证据：F10、INV-L4、`FulfillmentDriver` 和 `ProvisionResult` 均要求驱动不修改模型；但章程第 106 行把卡密领取事务放在 `RedemptionCodeDriver::provision()`，卡密规格第 220–228 行、预同步 suspend 规格第 363–366 行也要求直接调用驱动后模型已经改变。章程内部存在冲突，不能仅靠优先级自动解决。

裁决：保留驱动纯度。Action 负责库存预留、实例和任务写入；驱动读取明确绑定的资源或执行上游调用，返回 DTO。对于本地卡密，Action 先预留并绑定现有 `redemption_codes.instance_id`；驱动读取该实例已预留的卡密；Action 再原子提交 `sold` 与实例加密凭据，提交后才允许客户读取。

预留时就绑定 instance_id，避免预留事务提交后崩溃，重试因查不到绑定而领取第二张卡。回收仅释放未完成交付的过期预留；已交付卡密保持 sold（INV-R3）。不增加字段。

已应用的定向修改：章程卡密段落、INV-C2 的事务归属和绑定时机、两处规格分别改用 `ProvisionInstanceAction::execute()`、`SuspendInstanceAction::execute()`，并同步驱动文档说明。规格仍断言同一张卡、只领取一次、凭据持久化和状态同步；改为通过 Action 验证完整效果。驱动不写模型的独立验证在实现该驱动时补充，不提前添加必红的默认测试。

### C03：保留服务退款必须带明确操作者与非空原因

修订前证据：INV-R1、INV-A1/A2 要求管理员显式批准并写审计；退款规格第 388 行只传 `keepService: true`，没有原因或管理员上下文。

裁决：保留业务限制。规格构造有权限的管理员上下文，传入非空原因，并检查审计记录。缺原因或缺权限应拒绝，不能自动生成理由，也不能因为实例关联为空就跳过审计。

已应用的定向修改：该规格的准备与调用，保留原佣金追回和 expense 金额断言，增加审计断言。Action 调用显式传入 `actor` 和 `reason`；授权约定为 invoice 范围的 `refund-invoice` Gate ability，审计使用 `action=invoice.refund` 及 Invoice subject。规格以仅授权指定操作者和账单的 Gate 定义准备权限，不声称角色权限系统已经实现；不新增 DTO 或数据库字段。

### C04：回调只接受精确的支付尝试映射或完整账单号回退

修订前证据：不变量第 123 行要求 `(gateway, out_trade_no)` 查询 attempt，失败后精确匹配 invoice_no；四个回调规格使用 `invoice_no . '1'`，但没有创建 attempt。

裁决：保留精确解析规则。分别为迟到、重复、超额、短款规格创建真实关联的 payment_attempt，out_trade_no 与回调输入一致。未知账单规格继续不创建映射。

已应用的定向修改：四个规格仅补准备数据，业务断言不变。禁止在处理器里猜测、截断或移除订单号末尾字符来寻找账单。

### C05：创建购买账单必须携带商品与计费选择，金额来自服务端报价

修订前证据：INV-I1 要求账单 Action 计算并存储价格快照，INV-I4 要求对应一个商品；规格第 78–83 行却只传 user、totalAmount、useCredit 和 gateway，没有商品或报价来源。

裁决：购买入口接收商品、计费周期、配置与钱包选择；通过服务端可信报价计算并保存金额、币种和 line_items，不把客户端提交的 totalAmount 当价格依据。该规格准备 `onetime` 价格为 `10.00`、setup fee 为 `0.00` 且启用的商品，传入 `BillingCycle::ONETIME` 和空选项，再调用账单 Action；保留原冻结、到期释放和唯一流水断言。

已应用的定向修改：规格的准备与 Action 调用约定，明确 INV-I4 中首次购买可以没有 instance_id，但购买账单必须知道 product_id。不在业务 Action 内临时创建商品来补测试数据。

### C06：禁止 float 输入，同时保证金额重读后仍是两位小数字符串

修订前证据：M1/F03 禁止 float，但 `Money::of(int|string|float)` 接受它，`tests/Unit/MoneyTest.php:11` 也用浮点数验证正常化；模型的 string cast 在 SQLite 重读后不保留小数位。

裁决：`Money::of()` 接受 `int|string`，拒绝 float；把现有 `10.555` 输入改为字符串 `'10.555'`，保留预期 `'10.56'`，新增拒绝 float 的验证。模型金额列使用 Laravel 的 `decimal:2` cast 保证读取契约，所有业务金额计算继续通过 Money。这里的 cast 负责数据库表示，不作为第二个报价舍入点；报价仍按 M3 只舍入一次。

已应用的定向修改：Money 入参、该 Unit 测试的输入和新增拒绝断言，以及现有模型的金额 cast。新增整额和小数金额的持久化重读验证；保留首个规格的 `'100.00'` 断言。百分比等字段是否需要同样格式化，按其独立契约处理，不顺带扩大修改。

### C07：支付尝试由 Action 创建，网关适配器只处理协议与外部 I/O

修订前证据：F10 要求模型修改在 Action；但章程第 105 行及 external-drivers-spec §1.4 要求 `charge()` 创建 payment_attempt，且目前接口只有 `charge(Invoice, amount)`。

裁决：发起支付的 Action 创建并提交 payment_attempt，生成唯一 out_trade_no；提交后调用适配器；失败也保留这次尝试供重试和诊断。HTTP 不在数据库锁内（INV-L3）。

已采用的接口：`charge(Invoice $invoice, string $amount, string $outTradeNo): GatewayChargeResult`。适配器使用注入的号码，返回结果，不自行插入或修改账单、支付尝试。验证回调仍只返回 VerifiedPaymentResult。

已应用的定向修改：PaymentGatewayInterface 的 charge 签名、章程支付尝试段落和驱动协议文档；无需改变现有 GatewayChargeResult 字段。

### C08：纯钱包和网关回调都可以原子完成 Paid 结算

修订前证据：章程 §3.3 / INV-I3 明确纯钱包由账单创建路径完成 Paid 与交付任务；INV-P2 又写“没有其他代码路径为已付订单创建实例”，容易被理解为仅允许回调处理器。

裁决：明确两个合法入口：CreateComboInvoiceAction 的零网关金额分支，以及 HandlePaymentCallbackAction 的有效付款分支。两者都必须在各自同一事务内完成 Paid、钱包结算、实例和唯一任务，并在提交后分发。不得另设一个先创建实例、后补 Paid 的旁路。

已应用的定向修改：INV-P2 的入口描述；不改变原子性、单一资金写入者和幂等要求。

### C09：回调无效状态检查必须先于金额分支

修订前证据：回调表 P4/P5/P6 穷尽了金额的等于、大于、小于，而 P7 无效状态检查排在它们之后。比如 Refunded 账单收到等额的新交易，将按文字顺序落到 P8，P7 无法阻止。

裁决顺序：网关不匹配 → Expired/Cancelled 迟到入账 → Paid 重复付款入账 → 其余非 Unpaid 拒绝 → 对 Unpaid 才比较金额。超额付款在完成 P8 后仍保持 processed_overpayment，不被 P8 的 processed_paid 覆盖。

所有结果都在事务提交后发送 HTTP 响应；可以在事务内决定拒绝结果，但不能先响应成功、再提交去重记录。

已应用的定向修改：回调决策表顺序、P8 的 outcome 描述及 INV-P3 的响应时机文字；保留已有的金额比较基准和具体回调结果枚举。

## 3. 首个 checkout 用例无需修改的内容

- 原用例逐字保留，实施后原样移到 Feature。
- 安装门禁继续有效。在 Feature 文件的测试前置准备中使用隔离的测试 storage 和 installed.lock，不给 checkout 加全局旁路，也不改 Specs 文件来补安装状态。
- `hostname_regex` / `password_regex` 按章程指定方式包装分隔符；共同执行密码长度和格式约束。
- 金额读取格式问题按 C06 修正，不把 `'100.00'` 断言改为 `'100'`。
- 密码、长度边界和含 `/` 的正则属于现有验收要求；在 Feature 中补充覆盖，不把当前只测 hostname 的规格描述当成完整覆盖证明。

## 4. 后续模块实施前需要处理，暂不纳入本次定向修订

1. **退款流水类型。** INV-W5 / TransactionType 存在 refund_wallet，但章程 F06 与 INV-R2 指向 credit_in。建议按现行优先级先以 credit_in 为退款入钱包的标准记录；退款模块开工前明确 REFUND_WALLET 的用途或处置，不让两种实现各写一套。
2. **佣金追回。** pending 冻结佣金尚未入可用余额时，不应把零余额扣成欠款。需要明确何时增加 affiliate_balance，以及 pending、available、已提现三种情况下的追回；已提现欠款的记录与后续抵扣规则尚不完整。不能用归零或负数写入规避。
3. **上游成本与客户支付。** `docs/zjmf-api-spec.md:54` 把上游支出写成 gateway_amount，但 QuoteResult 已区分 upstreamBaseCost 与 downstreamPrice。客户全额用钱包时 gateway_amount 为零，并不使上游成本为零。建议按上游账单支付并对账 upstream_expected_cost；实际协议及金额字段在 ZJMF 阶段核实，不在本轮发出真实订单。
4. **首次开通的上游密码规则来源。** `/host/header` 查询已有 host；新购尚无 host 时如何取得有效规则未定义。第一例先落实本地 provisioning_rules；Phase 2 前须确认可用的上游规则来源，不能承诺已验证交集。
5. **文字错误与历史状态。** `RenewableResource::suspend()` 上方“续费后延长到期日”注释应属于续费编排；ProvisionResult::reconciled() 不应声称 DTO 创建时已写库。phase0-bootstrap.md 的开头仍描述没有运行时，是历史步骤，应标记为历史说明。

## 5. 批准范围与执行纪律

用户已批准 C01–C09 的上述定向修订，允许修正指定规格的准备、调用与类型预期，以及 C06 的 Unit 测试输入。业务断言的语义、严格性和用例名称保留，修订后仍为 **17 个待实现规格**；仅修改契约不算实现，`EXPECTED_SPEC_CASES` 保持 17。该次授权记录同时写入 `docs/agent-guardrails.md`，不形成持续编辑规格的权限。

本轮先完成契约修订并验证默认三道 Gate；第一个 checkout 用例仍待下一步单独实施，完成后按原样迁移与减一流程处理。后续模块的五项问题保持显式待办，不以此次批准视为上游验收或业务规则已定。新增的金额重读测试只证明隔离 SQLite 环境的表示契约，不证明 MySQL 并发锁行为、业务结算或上游协议。

## 6. 本轮验证结果

- 默认 Pest：43 passed，169 assertions；PHPStan：0 errors；Pint：clean。
- 规格组：17 failed，仍为预期未实现状态。失败分别来自安装门禁与缺失的 checkout/admin 行为、缺失 Action/模型，以及 host sync 的 501 占位响应；未把这些失败当成实现验收。
- 17 个用例名称及顺序与修订前一致；首例逐字未改，`EXPECTED_SPEC_CASES` 仍为 17。
- 未改迁移、枚举定义或 DTO 字段；未调用外部 API，未创建提交。

## 7. 经典 ZJMF 阶段补充批准（2026-11-17）

用户明确同意 §4 第 3、4 项的实施方向，并要求核实协议后实施新购：上游账单金额与 `upstream_expected_cost` 对账，客户钱包比例不改变上游成本；从已核实的商品配置读取首次开通密码规则，无法取得则拒绝新购。

现已按参考源码核实并实施，协议更正在 `docs/zjmf-api-spec.md`，源码方法、决策、操作方式和验收限制见 `docs/classic-zjmf-integration.md`。参考工程是 3.7.7 自维护分支（3.5.8 解密基线），不声称已完成目标供应商 3.7.6 的真实联调。1001 仅证明付款完成，交付前另确认 Active。

此次批准不覆盖退款流水/佣金追回的未决规则，不授权修改迁移、枚举、DTO、契约或其他 Specs。§1–6 是此前定向修订的历史记录，保留当时的 17 个规格和 Gate 数字；当前剩余规格与 Gate 以最新接入验收记录为准。

## 8. 退款阶段补充批准（2026-11-17）

用户明确批准 `docs/refund-decisions.md` 的 C10/C11，允许定向澄清 INV-W5/INV-R4 后实施退款：钱包退款统一记 credit_in；pending 佣金只撤销、不扣余额；available 佣金扣回可用佣金余额，差额以唯一流水明确登记应收款，不扣普通钱包。本轮不实现应收款自动清偿。§4 第 1、2 项以这次批准为准，其余历史批准范围不变。

此次批准不允许修改迁移、枚举、DTO、契约或规格断言。仍逐个实现并原样迁移规格，每个提交只迁移一个用例。

## 9. 经典 ZJMF 完整生命周期补充批准（2026-11-17）

用户明确批准 `docs/classic-zjmf-expansion-decisions.md` 的 C12–C14：新增纯商品配置快照 DTO，SyncableCatalog 返回快照并由 Action 落库；RenewableResource 新增独立 quoteRenewal；续费唯一键改为续费前认证到期边界，支持同月多次时付/日付。已定向修改接口、DTO 和 INV-K1/章程对应文字，其余原规则不变。

降级继续仅降低未来续费价，不执行上游降配、不退款；取消/过期续费单不隐式重开；未授权真实供应商交易与业务数据库迁移。本轮 Specs 已为 0，不改任何原测试或计数。现状与验收边界以 `docs/classic-zjmf-integration.md` 为准。
