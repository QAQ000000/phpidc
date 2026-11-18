# 通知持久事件 outbox 定向方案

日期：2026-11-18。状态：**D05 已获批准并实现**。本文保留批准时的设计依据；最终实现、运营事件扩展及隔离验证见 [notification-outbox-implementation-2026-11-18.md](notification-outbox-implementation-2026-11-18.md)。实现使用新迁移 `2026_11_18_000007`，未修改旧严格断言，未连接预览/业务数据库，未向真人发送通知。

## 最小人工决定

建议批准以下整组限定方案：新增 `notification_events`、`notification_deliveries` 两表及 `notification_logs` 的可空投递/尝试关联；业务状态与通知事件、渠道投递在同一数据库事务提交，队列只作唤醒并由分钟补扫恢复。每个业务发生点提供稳定事件键，各渠道收件人使用事件发生时的快照，并在发送前重新验证用户与订阅。

**发送意图一旦持久化，超时、未知响应、进程崩溃或租约过期均进入 uncertain，不自动重发。** 即使实际没有发出，也采用这一保守边界；人工只能凭明确未受理证据、权限及原因重新开放同一投递。已被供应商接受但本地提交前崩溃，无法保证自动判定成功，必须人工核对。`sent` 只表示传输接受，不是实际送达/阅读，也不承诺 exactly-once。

批准范围还包括在本文列出的现有 producer 上增加事件键、把续费通知入库移回建单事务、扩展换钥/注销清理与后台状态展示；保留现有冻结驱动接口及旧严格断言。旧队列没有业务事件标识的事实不能靠猜测补齐，不回填或重发历史业务通知。迁移或真实通知测试的执行仍由 root 按本次总授权统一安排。

## 批准前的证据与缺口

- `NotificationManager::queue()` 当前只遍历已验证绑定，直接派发 `SendEventNotificationJob(...)->afterCommit()`，没有持久业务发生记录。事务已提交、回调尚未完成时进程退出，会漏通知；Redis/队列丢失后没有可补扫的事件。
- `CreateComboInvoiceAction` 在 `afterCommit` 内调用 `NotifyRenewalAction`，后者直接派发各渠道 `SendNotificationJob`。续费建单成功不能证明提醒事件已持久化。
- `SendEventNotificationAction` / `SendLifecycleNotificationAction` 先发送、后写 `notification_logs`；日志没有投递唯一键、租约或发送意图。因此供应商接受后崩溃，队列重投可能重复。
- 两个旧 Job 均为 timeout=60、tries=3、backoff=[60,300,900]；明确 Telegram 429 / SMTP 4xx 才允许重试，未知结果不重发。idcsmart SMS 通过 `deliveryUncertain` 暴露不确定结果。保持这些已批准语义。
- 现有 `notification_logs.target` 保存收件人，旧 Job payload 也包含 target/variables；后台虽然不展示地址和原异常，匿名化仍必须覆盖这些持久副本。
- 依据：CLAUDE F02/F08、INV-L1/L3/L4、INV-K3、INV-Q1/Q2；`docs/operations-expansion.md` 已明确没有事件 outbox，存在漏发/重复窗口。guardrails §1.3 要求人为决定 schema，故先提交本方案。

## 建议 API 和稳定身份

保留调用名与前三个参数，新增末尾可选参数，避免破坏既有 mock/直接调用：

```php
NotificationManager::queue(
    int $userId,
    string $event,
    array $variables = [],
    ?string $eventKey = null,
): void;

// 业务 producer 必须显式提供稳定发生键。
$notifications->queue($invoice->user_id, 'invoice.paid', $variables,
    eventKey: 'invoice:'.$invoice->id.':paid');
```

`event` 是现有模板类型，`eventKey` 是某一次业务发生的稳定身份，二者不得混用。同 user/eventKey 必须返回原事件，不能再次展开收件人或再派生渠道；复用键但类型/白名单变量不一致时报错，不能覆盖原内容。不要用时间戳、通知正文哈希或每次调用的新 ULID 冒充业务去重键。

旧无 key 调用可生成一次调用级 `legacy-call:{ULID}` 并持久化，保证该次调用的渠道可恢复，但不声称重复调用幂等。所有正式 producer 必须在合并前显式传 key；原无 key 调用及旧 Job 的测试/兼容入口继续可用。

| 事件类型 | 稳定 eventKey | 当前 producer / 必须调整点 |
|---|---|---|
| invoice.paid | `invoice:{id}:paid` | CreatePurchaseFulfillmentAction、CreateServiceFulfillmentAction；同 Paid 事务 |
| instance.delivered | `fulfillment:{task_id}:delivered` | ExecuteFulfillmentTaskAction 成功提交事务 |
| invoice.refunded | `invoice:{id}:refunded` | RefundInvoiceAction 的退款/唯一流水事务 |
| affiliate.pending | `commission:{id}:pending` | AccrueAffiliateCommissionAction，仅真实创建时 |
| affiliate.available | `commission:{id}:available` | MatureAffiliateCommissionsAction 的推广入账事务 |
| affiliate.withdrawal | `withdrawal:{id}:{terminal_status}` | ProcessAffiliateWithdrawalAction；root 同时协调外部提现终态，不把审核中状态冒充完成 |
| ticket.reply | `ticket-reply:{reply_id}` | ManageTicketAction 必须取得新回复 ID，不能只用 ticket ID，否则第二次回复被吞 |
| renewal.invoice | `invoice:{id}:renewal-created` | CreateComboInvoiceAction 在建单事务调用 NotifyRenewalAction 记录；移除 afterCommit 才创建通知事件的窗口 |
| instance.overdue | `fulfillment:{suspend_task_id}:overdue` | ExecuteServiceTaskAction 确认逾期暂停的同一事务 |

新钱包充值、外部提现、应收清偿若增加通知：由 root 统一登记事件类型、默认模板和安全变量；分别用不可变充值单/回调结算 ID、提现 ID+终态、清偿记录 ID 作 key，业务重放必须沿用。注销只取消旧事件，不把旧事件转送给同邮箱的新 user。

## 必要 schema（新迁移，不改核心历史迁移）

### notification_events

`id` bigint；`user_id` 外键 restrictOnDelete；`event_type` varchar(64)；`event_key` varchar(191)；`encrypted_payload` nullable LONGTEXT（白名单变量及版本）；`suppression_reason` nullable varchar(64)；timestamps。

唯一索引 **(user_id, event_key)**；按 user/id 索引供注销与排查。payload 必须加密，不写密码、API 密钥、兑换码或原工单正文；保留现有变量白名单与长度限制。`suppression_reason` 显式记录注销等无投递原因，不能静默消失。

### notification_deliveries

`id` bigint；`notification_event_id` 外键 restrictOnDelete；`channel` varchar(32)，mail 统一为 email；`recipient_key` varchar(96)，取 `binding:{binding_id}` 或 `account-email`；`binding_id` nullable bigint（历史引用，不以级联删除丢投递）；`is_fallback` bool；`encrypted_payload` nullable LONGTEXT；`status` varchar(32)；`attempts`、`max_attempts`（默认3）；`available_at`、`last_dispatched_at`；`locked_by` nullable UUID、`locked_at`、`lease_expires_at`、`send_started_at`、`finished_at`；`error_code` nullable安全分类；timestamps。

唯一索引 **(notification_event_id, channel, recipient_key)**；补扫索引 `(status, available_at, id)` 与 `(status, lease_expires_at, id)`。同渠道多个已验证绑定仍各有一个投递，保持“ALL verified bindings”规则；不擅自改变现有多绑定语义。payload 包含收件人快照、当前路径所需的模板/正文/参数快照及版本，不含供应商密钥。fallback 沿用当前账户邮箱，并明确标记。

### notification_logs 增量

新增 nullable `notification_delivery_id`（restrictOnDelete）与 nullable `attempt_no`；唯一 `(notification_delivery_id, attempt_no)`，旧行均 NULL，保留原字段/状态和历史查询。一次业务尝试的结果与投递终态在同一事务写入；补扫写崩溃 uncertain 也受该唯一键约束。

为保持现有严格断言，新日志对仍有效的用户沿用现有 target/event_key 语义，包括 `renewal.invoice.{id}.fallback`；**匿名化用户一律不再写回旧 target**，写脱敏标记。注销方案必须清理该用户历史 notification_logs.target；后台继续不显示收件人与原始传输异常。

## 事务、调度与发送状态机

1. RecordNotificationEventAction 在业务事务内插入唯一事件，并展开全部渠道投递；二者与账务/履约/回复一起回滚。`NotificationManager` 仅委托 Action，不在 Driver 写模型。事务外的兼容调用由 Action 自建事务；生产业务事件不得借此拆成两次提交。
2. 只在最外层提交后派发投递 ID；`notifications:dispatch-pending` 每分钟、withoutOverlapping + onOneServer、按索引分批重派到期 pending/retry-wait，并处理到期租约。派发/Redis失败只记安全告警，已持久事件仍由补扫可见；不得把“发送成功”依赖于 afterCommit 回调一定执行。
3. worker 按 `users → notification_events → notification_deliveries` 顺序锁定，复验原 user ID 未软删/匿名化、原绑定仍已验证且 target/channel一致、fallback 邮箱及无已验证邮件绑定条件仍成立、模板未停用。失效为 skipped/disabled，不能改送当前新地址或重新展开新绑定。认领设置 worker UUID/租约并原子消耗一次尝试；重复队列消息不能同时认领。
4. 固定白名单变量与接收快照；发送前的控制检查仍使用当前用户/订阅/模板启停状态。模板正文和变量映射快照保证同一次投递在重试时不变，停用是发送否决条件。不得缓存供应商密码到投递载荷。
5. 在短事务内、网络之前持久化 `send_started_at`，然后释放所有锁，调用既有纯 Driver。发送前再次复验租约/token及用户状态；本地发送门槛提交后才允许网络。HTTP 无事务/行锁跨越。
6. 提交结果时重新按同一锁序锁定并检查 UUID/租约。接受→sent；开发 log→simulated；明确永久拒绝/未配置→failed；明确未接受临时拒绝且未满3次→retry-wait，至少60/300秒并遵守 retry_after；未知/超时→uncertain。结果和日志原子提交，日志不持久化原异常/密钥。新队列消息重复执行已终态投递一律无外部发送。
7. `HTTP≤30s < notification job60s < lease120s < queue retry_after240s`。补扫认领/发送意图均由DB控制，不靠Laravel队列 attempts判断业务重放；队列异常可进 failed_jobs，但同一 delivery 的数据库状态始终是恢复依据。

| 崩溃点 | 补偿 |
|---|---|
| 业务提交前 | 业务、事件、渠道全部回滚 |
| 业务已提交，afterCommit/派发前 | 分钟补扫重新派发同一 delivery ID |
| 认领后、send_started_at前 | 到期租约释放后按剩余尝试重排；没有发送意图，才允许自动恢复 |
| send_started_at之后，包括真正HTTP之前 | 到期进入 uncertain；可能漏发，但不会靠猜测自动重复收费短信 |
| 供应商已接受、本地sent提交前 | uncertain，人工核对；当前 bool Driver 契约没有可查询的通用消息ID或幂等协议 |
| sent与日志已提交，queue ACK前 | 后续同ID消息无操作 |

人工 uncertain 处理只允许 notification.manage、明确原因与供应商证据：标记已接受；或证明旧worker已退出、没有在途/排队请求且供应商未接受后，重置同投递的发送门槛并审计，再按有界预算重试。不能凭“尚无回执”推断未发送，不能 `queue:retry all` 绕过发送意图，不伪称提供未核实的供应商送达查询。

## 注销、锁序与 root 协调

注销锁原 user 后，按 event/delivery ID 锁定并取消尚未开始发送的投递，清理 event/delivery 密文及该用户日志 target；保留 ID、稳定事件键、状态及审计，不物理删除历史关系。已开始HTTP不能撤回，必须保留“可能已投递”状态，不能宣称注销能撤销远端接受。结果回写再次检查软删状态，只保存脱敏结果。账户邮箱被重新注册也不能转移原 user_id 的事件。

发布、发送认领/结果、注销均需 user 锁序协调。**不能在已锁 invoice/instance/task 后临时倒序锁 user。** root 必须调整当前 ExecuteFulfillmentTaskAction 成功事务、ExecuteServiceTaskAction.commit、ManageTicketAction 客服回复事务的初始用户锁：先识别并按ID锁全部参与用户，再锁原业务行，末尾记录 outbox。计佣现有 buyer/referrer 有序锁可复用。RecordAction 不得自行在未知外层锁序中补锁。

需 root 统一合并：CLAUDE §3.2/3.7、domain-invariants 对通知持久handoff/发送意图/锁序的补充、guardrails批准记录、external-drivers-spec通知说明；NotificationManager事件表和所有producer稳定key；routes/console与部署scheduler/队列配置；OperationsPage/通知页新增pending/uncertain状态及安全人工处理；ReencryptSecretsAction轮换两表密文；注销Action及旧通知Job失效分支避免PII回写。迁移编号只由 root 分配。

## 旧队列及903项旧断言兼容边界

保留 SendEventNotificationJob、SendNotificationJob 的既有类名、构造参数和旧payload执行入口；新增可选 delivery ID 模式，带ID模式只序列化ID及Laravel队列元数据，由Action从DB读取载荷。旧直接构造/handle测试及安全变量、渠道数量、fallback、日志状态和精确事件键仍需成立；不能为新设计弱化旧断言。必要时在内存对象保留既有安全属性供旧调用兼容，但实际队列序列化必须验证不带target/variables/密钥，反序列化也要覆盖旧payload。

旧 SendNotificationJob 可通过invoice ID定位续费业务，但旧 SendEventNotificationJob通常只有event类型和variables，没有不可变source ID；同一ticket的两次回复尤其不能用正文/variables哈希合并。旧队列与failed_jobs不自动回填、不重写payload、不批量重放；保留有边界的旧消费路径及失效收件人检查，明确不追溯承诺持久业务幂等。切换后新producer一律稳定key，新消息使用ID模式。实际旧消息盘点/处理只能由root在备份后按授权执行，不能以启动通用worker代替设计确认。

已知需重点兼容的旧测试：OperationsExpansionTest直接查看旧Job.variables并调用handle；ClassicExpansionTest要求三个SendNotificationJob及精确fallback日志键/target；FulfillmentClaimBudgetTest要求成功后一个SendEventNotificationJob；RenewalRecoveryTest通过mock NotificationManager::queue异常验证整事务回滚。不得移到afterCommit来绕过该回滚断言。若实现无法维持这些约束，先报告具体冲突，不编辑旧断言。

## 批准后的验收

SQLite与独立MySQL验证：业务/事件/渠道整事务回滚；同事件并发唯一与同key参数冲突；多绑定/fallback/白名单；提交后派发丢失补扫；重复消息/两个worker仅一个认领；租约前后崩溃边界；已接受后强制结果提交失败不重发；明确临时拒绝三次预算；发送与注销并发、换邮箱/退订/模板停用及复用邮箱隔离；加密/换钥/日志脱敏；旧payload反序列化与903旧严格断言全绿。只使用Http fake/本地SMTP接收器，不向真人发邮件、Telegram或收费短信。生产迁移/真实通知接收目标、预算及消息内容须由root按已获得授权另行落实。
