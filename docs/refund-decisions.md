# 退款闭环裁决与实施记录

状态：**C10/C11 已由人工批准并实施，两个退款规格均已迁移**。日期：2026-11-17。

本文件记录 `docs/contract-clarifications.md` §4 第 1、2 项的裁决；用户已明确选择“批准 C10/C11，按草案实施”。对应 INV-W5/INV-R4 已同步，未授权修改其他业务不变量或规格断言。

## 现有冲突与证据

- 章程 F06 与 INV-R2 规定钱包退款写 `credit_in`；INV-W5 同时把 `refund_wallet` 定义为钱包退款流水类型。现有枚举保留两个 case，尚无退款 Action。
- INV-R4 笼统要求追回时反向扣减 `affiliate_balance`，未区分未入账的 pending 佣金。首个剩余规格明确使用 pending 佣金 `10.00`、推广人余额 `0.00`。直接扣款违反 M2/W1，直接把差额记成债务则把未支付的佣金当成已支付。
- 佣金表只有 pending、available、clawed_back 等字符串状态、金额、可用时间和追回时间；提现表不关联具体佣金。不能凭佣金本身推断哪次提现用了它。
- 现有 `financial_transactions.meta` 为 JSON，允许在不增加字段的前提下保存追回分解；`reference_key` 已有唯一索引。现有规则没有规定应收款的后续收款/抵扣流程。

## C10：统一退款流水

已批准：钱包退款一律写 `TransactionType::CREDIT_IN`，使用 `refund:{invoice_id}`；外部退款登记写 `EXPENSE`，使用同一个唯一键。`REFUND_WALLET` 枚举保留，不删除、不新写；`refund_wallet` 仍是 Action 的退款模式名。同步澄清 INV-W5，避免两套流水口径。

本轮仅支持已付账单的全额退款，金额使用已存 `total_amount`，不误用 `gateway_amount`，不重新扣优惠，不退已另行入钱包的超额付款。重复调用返回既有结果；模式不一致的重复请求拒绝，不执行第二笔退款。外部模式只登记操作员确认已完成的线下退款，不调用尚未定义的支付退款 API。

## C11：按佣金入账阶段追回

已批准以下规则，定向替换 INV-R4 的笼统扣减描述：

| 原状态 | 退款时处理 | 应收款 |
|---|---|---|
| pending | 改为 clawed_back，记录追回时间；未增加过可用余额，因此不扣 affiliate_balance | `0.00` |
| available，余额足够 | 改为 clawed_back，从推广人 affiliate_balance 扣回佣金全额 | `0.00` |
| available，余额不足 | 改为 clawed_back，扣回当前可用佣金余额，差额明确记为应收款 | 佣金金额减实际扣回金额 |
| clawed_back | 不重复扣减、不重复记应收款 | 沿用既有记录 |

每笔首次追回均追加唯一 `affiliate_clawback` 流水，键为 `clawback:{commission_id}`，金额为佣金原额。`meta` 必须保存 commission_id、原状态、affiliate_balance_before/after、recovered_amount、receivable_amount。pending 撤销的 recovered_amount 和 receivable_amount 均为零；流水表示撤销佣金权利，不表示扣了客户钱包。

余额不足时必须保存实际扣回额与应收额，不能仅把余额归零、丢掉差额，也不允许把余额写成负数。不得自动从推广人的普通钱包扣款。未来佣金成熟、提现和应收清偿流程必须读取这类应收记录；本轮只记录并告警，不宣称已有债务清偿或自动抵扣功能。

## 实施约束与验收范围

- 退款、佣金追回、账单 Refunded 状态和审计同事务；用户行（涉及买家和推广人时按 ID 升序）先锁，再按 INV-L1 锁账单、实例、卡密和任务；佣金在用户与账单锁之后复核。无事务内外部 HTTP。
- 只接受有 invoice 范围 `refund-invoice` 权限的显式 actor 和非空 reason。继续执行 INV-R1 的服务先终止要求；keepService 是显式授权例外，不自动终止上游。
- 已交付卡密永不回收；保留 sold 与原绑定。卡密取消和损失登记遵守 INV-R3，不能通过退款重新上架卡密。
- 不增加迁移字段，不修改枚举、DTO、契约或规格断言；新增必要模型/factory。
- 仍按一个规格一个提交执行，先实现并原样迁出 `claws back frozen affiliate commission on refund and records expense on external refund`；随后再处理运行中实例拒退规格。
- 补测钱包/外部全额退款、重复调用、授权和原因、服务状态、pending 撤销不扣余额、available 追回与应收差额、卡密不回收、事务失败全回滚。分别跑三道 Gate。

## 本次只读基线

2026-11-17：Pest 134 passed / 1009 assertions；PHPStan 0 errors；Pint clean。Specs 2 个预期失败，分别由缺少 AffiliateCommission 和 RefundInvoiceAction 引起。未调用真实支付或上游，也未触碰业务数据库。

## 首个规格交付

新增 `RefundInvoiceAction`、`AffiliateCommission` 及其 factory。首个佣金追回/外部退款规格原样移到 `tests/Feature/AffiliateRefundTest.php`，随用例迁移其专用 imports；计数从 2 减为 1，剩余用例正文未改。

Action 返回本次退款的 FinancialTransaction；重试同模式返回同一流水，改变模式拒绝。所有退款均要求显式有权 actor 与非空 reason，包括无实例账单。权限通过 invoice 范围 `refund-invoice` Gate 检查；未注册该 ability 时默认拒绝，不以登录或传入任意用户代替管理员权限。本轮没有新增退款 HTTP 路由、命令或自动授权规则，后续管理面板须配置实际权限后调用 Action。

退款账单的 pending/running 任务变为 stale 并清除租约，阻止旧 worker 提交交付结果；保持服务的显式豁免不发起上游终止。对于已售卡密，保留 sold、绑定与密文，服务取消且写实例审计；外部退款的 expense 同时标记不可回收损失，钱包退款另写唯一 `refund_card_loss:{invoice_id}` 非现金 expense，金额以本次退款的账单总额为基础，不声称是上游采购成本。keepService 不能让已退款卡密重新成为可售库存。

新增测试覆盖钱包/外部退款、幂等与模式冲突、权限/原因、pending/available 佣金追回、应收告警、事务故障全回滚、售卡后退款、回调/旧任务不得再次交付、本人及非本人账单访问。纯钱包与组合付款均经过真实 checkout/发卡 Action 后退款。全部使用隔离 SQLite；未执行真实网关退款或上游资源终止，未实测 MySQL 多进程竞争。应收告警当前写应用日志，尚无清偿/抵扣或通知渠道。

第一步 Gate：Pest 163 passed / 1226 assertions，PHPStan 0 errors，Pint clean。剩余 1 个规格已因服务/授权前置校验通过，将在下一提交单独迁移并补充授权操作员下的服务状态测试。

## 服务保护规格与最终验收

第二个规格 `refuses to refund while the instance is still running` 原样迁到 `tests/Feature/RefundServiceGuardTest.php`，计数从 1 减到 0。两个原始用例均与本轮起点逐字核对，未改断言或业务预期，分别提交。Specs 文件保留原说明与测试配置，未删除文件或取消完整性检查。

补测授权操作员在 Active、Suspended、Pending、ProvisionFailed、Fraud 或无关联实例时，未显式 keepService 一律拒退；确认 Cancelled/Deleted 后可不传豁免退款。校验实例归属与授权账单范围，不能凭某张账单的权限退款另一张账单。

模拟 worker 已预留卡密、尚未提交交付时发生退款：退款使租约失效，旧结果丢弃、凭据不入库，未交付预留到期后可以回收。这是确定执行顺序的竞态测试，不是 MySQL 多进程压测。已售卡密始终不可回收。人工重试现在先锁账单再锁实例/任务，已退款账单的旧失败任务也不能重新执行。

最终三道 Gate：Pest **175 passed / 1312 assertions**；PHPStan **0 errors**；Pint **clean**。`pest --group=spec` 显示 **No tests found** 并返回 1，因为 Specs 剩余 0 个用例；未屏蔽或改写这个退出码。PHP 语法与 `git diff --check` 通过。

未新增迁移字段、枚举 case、DTO 字段、契约方法或业务数据库数据。规范只应用已批准的 INV-W5/INV-R4 定向修订。退款业务 Action 和售卡退款链路已验证，管理面板/实际 Gate 权限配置、真实网关退款、自动上游终止、应收清偿、MySQL 并发和生产部署尚未交付或验收。Specs 清空不代表章程所有 Phase 功能完成。
