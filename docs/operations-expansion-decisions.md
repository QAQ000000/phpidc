# 运营扩展：已批准裁决与实施契约

日期：2026-11-17。状态：**C16–C18 已由人工批准，按本文实施。批准包含定向修订 INV-K4/INV-L2 和新增 request_key；不包含执行业务数据库迁移或真实打款。**

本轮请求为优惠券、推广佣金、提现、工单、通知渠道、下载中心。开工 Gate：Pest 386 passed / 3136 assertions，PHPStan 0 errors，Pint clean；Specs 0。现有未提交工作全部保留。

## 1. 开工盘点（实施前）

| 模块 | 已存在 | 真实缺口 |
|---|---|---|
| 优惠券 | coupons、coupon_claims、coupon_usages；Coupon 模型；账单 coupon_id | checkout 的 discount_amount 固定 0，无领取、发放、限次核查、折扣计算和后台 |
| 推广佣金 | users.referred_by/affiliate_balance、规则/商品关联、佣金表及模型 | 无推广归因、规则管理、支付后计佣、到期成熟、客户明细；只有退款追回与应收记录 |
| 提现 | affiliate_withdrawals；affiliate_payout 枚举与 payout 唯一流水约定 | 无申请、幂等请求键、余额占用核查、审核、转钱包或外部打款实现 |
| 工单 | tickets、ticket_replies | 无模型、客户/客服入口、回复/关闭状态机、关联服务归属核查 |
| 通知 | 模板/日志表、已验证绑定、三个渠道契约、续费专用 Job/Action、Telegram/idcsmart SMS 适配器 | 无通用 NotificationManager、模板管理、全渠道事件扇出/日志后台；DriverManager::mail() 仍指向日志驱动 |
| 下载中心 | download_resources；public/authenticated/active_customer 枚举 | 无模型、私有文件管理、权限下载、公开列表和后台 |

通知并非完全没有实现：已有续费提醒、账号验证/找回邮件和 Telegram 订阅。用户之前明确跳过不可用短信/实名供应商，本轮不重新启用或调用它们。

## 2. C16：区分领券资格与每人使用次数

### 冲突证据

- INV-K4 要求按 coupon_claims 核查 per_user_limit。
- 迁移规定 coupon_claims 对 coupon_id + user_id 唯一；它记录一次领取，不能表示同一用户用了第二次、第三次。
- coupon_usages 已有 coupon_id、user_id、invoice_id，且 invoice_id 唯一，适合作为实际用券次数来源。

### 建议定向修订

仅修订 INV-K4 的限次依据：

> 锁定用户及 coupons 行后，在同一建单事务内核实 coupon_claims 中的领取资格；按 coupon_usages 中该用户对该券的已使用行数核实 per_user_limit。检查有效期、商品/订单类型、最低订单额、claim_conditions、total_quota 和 used_count 后，写入 coupon_usages 并增加 used_count。invoice_id 唯一，重试不得重复核销。

不修改表或测试原断言。领取与使用分开：领取不预占用券总额度，重复领取返回既有领取记录；用户必须先领取或由有权运营明确发放，猜中优惠码不等于取得资格。运营发券写真实 actor、原因及审计（INV-A1/A2）。

### 首轮用券语义

- percent 的 value 是减免百分比：10.00 表示减免 10%，不是打一折；范围大于 0 且不超过 100。fixed 是减免固定金额。最低订单额使用优惠前 subtotal；折扣不得超过 subtotal。
- 所有金额由 Money 计算，百分比在折扣产生时只舍入一次；subtotal = total + discount，credit + gateway = total，两式同事务校验。先确定折后总额，再冻结钱包（INV-I1/I2、M3、INV-W3）。
- null total_quota 表示不限制，0 表示无可用名额；有效期为 valid_from <= 当前时刻 < valid_until，空边界不限制。claim_conditions 在领取和建单时都复核；已领取不能绕过后续实名或绑定变化。
- per_user_limit 以建单核销次数计算；取消、过期、退款不自动恢复次数或 used_count。退款仍只退已存折后 total_amount，不重新减优惠。不存在领取额度扣一次、付款再扣一次的双重核销。
- 首轮支持新购和新建续费账单，遵守 applies_to。升级/降级不用券；既有账单（含调度器已生成的续费单）不重定价或补挂券。续费同一 billing_period_key 重试返回原单，不改折扣。
- coupons 没有币种列，首轮优惠券只对 CNY 账单开放，其他币种明确拒绝用券，不做隐含汇率换算；普通不使用优惠券的其他币种订单不受本决策改变。
- 客户中心提供输入券码领取、已领券及使用记录；不在公开页面列出所有券码，现有表没有“公开券/定向券”区分字段。

## 3. C17：为佣金转钱包明确唯一写入者

### 冲突证据

章程 §3.5 允许 affiliate_withdrawals.destination=wallet，但 INV-L2 当前只允许五个 Action 写 invoice/wallet 状态，没有处理提现的 Action。转钱包必然增加 users.credit，不能塞进退款、任务重试或 Controller 来绕过该规则。

### 建议定向修订

在 INV-L2 的既有名单后，**仅新增 ProcessAffiliateWithdrawalAction**，限定其钱包写入能力：

> ProcessAffiliateWithdrawalAction 只能结算经有权人员批准的、当前用户本人提交的佣金转钱包申请；以提现记录 ID 为幂等依据，同事务扣 affiliate_balance、增加 credit、写唯一 payout:{withdrawal_id} 的 affiliate_payout 流水、写审核人/原因及前后审计，并将申请改为 approved。它不能手工调整任意钱包金额、修改订单价格或状态，也不能替代充值/退款入口。

先锁用户再锁申请，审核金额只取申请中已存金额；使用 Money 并检查两个余额范围，frozen_credit 不变。直接调用 Action 也必须检查明确的审核 actor 和独立权限，页面隐藏按钮不能代替授权。重复批准原样返回已完成结果，批准后不得改金额/目标或再驳回。

成熟佣金只改 affiliate_balance，不写普通钱包；退款继续只由 RefundInvoiceAction 按 C11 追回，不扩展其他任意资金写入者。

## 4. C18：提现幂等字段、占用口径与首轮结算范围

### 必要的最小迁移

affiliate_withdrawals 当前没有申请请求键。payout:{withdrawal_id} 能阻止同一申请重复审核，却不能阻止一次申请 POST 因超时重发产生两个不同申请，之后各付一次。

建议批准独立增量迁移：

| 表 | 新字段/约束 | 兼容处理 |
|---|---|---|
| affiliate_withdrawals | request_key VARCHAR(64) NULL，唯一索引 | 历史记录保持 NULL；新申请由 Action 强制必填，按用户 ID + 表单 ULID 作用域生成 |

不重写核心迁移，不清空数据，不伪造历史请求键。相同键、相同申请返回同一记录；相同键但金额/目标不同则拒绝。客户端重发不能生成新业务申请；审核仍以 payout:{id} 唯一流水作最终幂等约束。迁移只在隔离数据库验证，本次批准不包含执行业务数据库迁移。

### 首轮状态与余额

- 复用既有 pending/approved/rejected，不新增枚举、状态或冻结余额列。
- pending 仅占用可提现额度，不减少 affiliate_balance、不动普通钱包；创建时在用户行锁下检查 `affiliate_balance >= 已有 pending 申请合计 + 本次金额`，防止多个申请超额占用。页面明确区分账面余额、申请占用和可申请额。
- approved 才按 C17 完成转钱包与唯一流水；rejected 只取消占用并审计，不虚构一笔“退款”或增加普通钱包。
- 退款可以按 C11 追回 affiliate_balance，即使已有 pending 申请。批准时必须再次核查余额；不足就保持 pending 并提示人工驳回/核对，不自动负数、不自动挪用普通钱包、不宣称占用余额受退款保护。
- C11 既有 receivable_amount 一旦为正，阻止该推广人新申请及批准任何提现。首轮不实施自动清偿/净额抵扣，不把新成熟佣金或普通钱包余额当作债务已收回；有未清偿应收即使账面余额足够也不能付款。已存在应收没有清偿流程时保持阻止，并展示原退款流水供人工核对。
- **首轮仅交付钱包划转。外部提现/自动打款保持关闭**：当前既无已核实的支付供应商协议，也无付款执行中/不明结果/回滚所需的契约。本轮不把 approved 伪装成真实外部付款成功；destination=external 的历史记录可只读核查，新申请与批准均拒绝并说明尚未接入。

### 推广与佣金口径（与提现一起批准）

- 单层推广，签名推广链接只影响新注册用户，归因写入既有 users.referred_by 后不可由客户改写。登录用户访问链接不改归属；禁止自推、失效推广人或软删除账号。
- 首轮计佣限 CNY 新购账单；续费、升级、外币暂不计佣，界面明确说明。现有 affiliate_balance 没有币种维度，不能混入多币种或做换汇。
- 计佣基础为账单已存折后 total_amount，含钱包付款部分；不使用 gateway_amount，也不把超额支付、晚到入钱包、上游成本、退款或应收当作推广收入。金额为 0 不产生可提现佣金。
- 匹配当前商品的启用规则，priority 高者优先，同优先级按规则 ID 升序；未绑定任何商品的规则适用所有商品。无规则不计佣。费率 0–100%，用 Money 在产生佣金时只舍入一次。
- 建单时把推广人、规则 ID、费率、冻结天数写入既有 line_items 的推广快照；付款后不再按当前规则重算。默认冻结 7 天，运行时配置走 SettingService。无快照的历史账单不追溯发佣金。
- 付款后计入 pending，available_at 以实际 paid_at 加冻结期确定；只在成熟时增加 affiliate_balance 并写唯一 affiliate:{commission_id} 流水。invoice_id 唯一防止重复计佣。退款先发生则不得新建佣金；已有 pending/available 的追回仍完全遵守 C11。
- 计佣/成熟在独立 Action 中按参与用户 ID 升序锁用户，然后锁账单及佣金。提交后调用与带互斥的定时补偿扫描配合，Paid 账单与唯一 invoice_id 是可恢复来源，不依赖进程内回调唯一触发。不得在已锁实例后再锁推广人。

## 5. 不涉及规则修改的配套交付契约

以下为本次扩展的实施范围，不增加未批准表字段或冻结接口。

### 工单

- 客户：本人列表、详情、创建、回复、明确关闭/重开；可选实例必须本人所有，不接收客户端 is_admin、user_id 或任意状态。客服：列表/筛选、回复、关闭/重开，回复者使用真实账号、服务端确定 is_admin；涉及客服管理动作要求原因并审计。
- 复用 open/answered/closed；客服回复为 answered，客户回复为 open；closed 不接受普通回复，必须先显式重开。工单/回复事务内写入，不删除历史。正文按文本转义输出，不渲染不受信 HTML。
- 首轮无附件、工单分配/部门/SLA/站内聊天；现有表没有对应字段，不能悄悄实现假的状态。

### 通知渠道

- 复用 email/sms/telegram 契约和已验证绑定，新增通用模板管理、NotificationManager、事件 Job 与日志页面；每个绑定独立队列执行，某渠道失败不阻止其他渠道，失败记录脱敏类别和告警。
- 新记录统一用 NotificationChannel::EMAIL 的 email；兼容读取历史 mail 别名，不改枚举、不批量改历史数据。无已验证邮件绑定时回退账户邮箱并留记录；邮件绑定未验证不当作新的可信目标。
- 支持付款/交付、退款、佣金/提现、工单回复和既有续费/逾期事件；模板变量白名单与 variable_mapping 在服务端应用，不允许模板读取任意模型属性或私密快照。通知不包含卡密、密码、密钥、证件原文、提现账户明文。
- 深读驱动后修正初始盘点：虽然名为 LogMailDriver，现有实现已经按 SettingService 支持 SMTP 与日志两种 transport；复用该真实邮件实现，不重复造适配器。日志 transport 仅用于开发并明确记录模拟发送。既有通知 Job 的构造参数保持兼容，新增通用事件 Job 不破坏已有序列化队列任务。
- 无模板与显式停用必须有可追踪处理结果；账单事件有默认模板，不能因未配置模板静默消失。后台日志不输出完整收件人、凭据或异常原文。
- 按用户之前的决定，真实 idcsmart SMS/KYC 暂不启用。Telegram/SMTP 仅在隔离替身验证，不擅自对真人发消息。投递受理不是送达证明；远程已发送但日志丢失可能导致重复通知，本轮不宣称外部投递 exactly-once。

### 下载中心

- 公开列表展示已启用资源的标题、分类、版本、描述、大小/哈希和访问要求，不暴露本地 file_path 或受限外链；停用/不存在资源不提供下载。
- public 允许游客，authenticated 要求登录，active_customer 要求本人存在 Active 实例；不能拿他人的服务、只付过款、Pending 或 Suspended 充当 Active。
- 本地文件保存在私有下载目录，经 Controller 服务端授权后响应；不生成绕过权限的静态 public 链接，不接受客户传入路径。后台上传计算大小与 SHA256，保存元数据、启停/访问级别变更均记录 actor 和原因。
- external_url 只允许管理员配置 HTTPS 链接；授权后才重定向，不从服务器抓取任意地址。外部站点有自己的访问策略，不能保证授权用户不会分享已获链接。
- download_count 表示通过本系统权限检查并接受的下载请求数，不宣称客户端下载完成；不靠客户端回报增加次数。

## 6. 页面、权限与验收

2026-11-19 人工输入调整：用户已批准日常操作使用可选备注及稳定的服务器审计说明，商品范围、领取条件及模板映射使用结构化控件；原资金、权限、事务和审计保证保持。下述“非空管理原因”仍约束审计记录，不再普遍要求人工填写，详见 [操作精简](usability-simplification-2026-11-19.md)。

沿用 Filament `/admin` 与现有 Blade `/console`；新增固定角色和明确权限：promotion-viewer/operator、affiliate-viewer/operator、support-viewer/agent、notification-viewer/operator、download-viewer/operator；提现审批另用 finance.payout，不把既有 finance.refund 自动扩大为打款权。角色授撤继续走上一轮带原因的审计 Action；不会创建默认管理员、授予真实账号或开启万能 super_admin。

客户增加优惠券、推广/佣金/提现、工单页面；下载中心有公开列表和受控下载。管理员增加对应运营页面；全部写操作 CSRF、独立授权、非空管理原因、Action 再校验、事务内审计。页面仅使用安全投影，窄屏单栏操作，普通客户只能查看本人数据。

批准后按以下依赖次序实施与验收，而不是只建六组 CRUD：

1. 优惠券领取/发放→新购/新建续费用券→完整金额恒等式、限次/并发及退款回归。
2. 签名推广归因→建单快照→支付计佣→成熟→C11 退款/应收兼容。
3. request_key 增量迁移→提现占用→带独立财务权限的转钱包/驳回→幂等/余额/应收阻断。
4. 工单客户/客服闭环、通知模板/事件扇出/失败隔离、下载资源管理/访问矩阵。
5. Filament 与客户入口、独立权限、日志/审计核查，隔离 SQLite + 回环 HTTP/队列/SMTP，桌面/手机浏览器；有条件时独立 MySQL 并发验证，不能用 SQLite 冒充。

每步新增业务验收测试，不改旧断言、不改 Specs、不加 ignore。最终三 Gate 必须全绿。C16–C18 已获人工批准，定向实施范围如上；其余规则、schema 和冻结契约修改仍须另行批准。业务库迁移、真实账号授权及真实外部打款不在本轮授权内。

实施与验收记录见 [operations-expansion.md](operations-expansion.md)。
