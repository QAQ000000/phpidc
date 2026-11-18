# 本轮履约与同步修复记录

日期：2026-11-18（Asia/Shanghai）。基线为当前未提交工作区，保留此前所有修改。本轮未提交 Git、未迁移业务库、未调用真实供应商写接口。

## 已实现：升级锁竞争恢复

`ExecuteServiceTaskAction` 原先把配置锁忙造成的明确“未发送”也写成 `create_uncertain`，使已付款升级永久停留在人工核对状态。

现在仅在适配器同时返回 `retryable=true` 和严格布尔 `rawResponse.not_sent=true` 时，按账单→实例→任务的锁顺序重新验证当前绑定、付款和租约；只有本次 `create_requested` 可以移除。随后使用现有失败退避和派发路径等待配置锁释放。尝试次数仍按 C21 每次认领计数，最多五次，不另设无限重试。

真正发出创建请求后的未知结果继续保留意图。超时、丢失响应、普通拒绝均不能通过此分支再次创建上游账单。旧租约或绑定变化也不能清理其他 worker 的意图。未批量清理历史 `create_uncertain`，因为历史持久状态缺少证明“从未发送”的证据。

涉及文件：`app/Actions/ExecuteServiceTaskAction.php`。

## 已实现：回调同步校验账号配置

`ReconcileHostSyncAction` 在 HTTP 前保存实例绑定及账号认证指纹。HTTP 后在同一提交事务内锁定实例和对应账号，重新检查启用状态、端点、归属/绑定及认证指纹，检查通过才调用现有快照写入 Action。账号变更时丢弃旧快照，保留脱敏操作日志，不把旧供应商的 Deleted 状态或密码写入新配置。所有远端读取仍位于该提交事务外。

共享 `MachineOperation::accountFingerprint()` 也纳入 `auth_header_mode`，所以修改 Bearer/raw JWT 模式同样使在途结果失效；原有用户名、驱动、账号配置和加密秘密比较保留。实例状态变化的拒绝仍由 `ApplyInstanceSnapshotAction` 负责。

涉及文件：`app/Actions/ReconcileHostSyncAction.php`、`app/Support/MachineOperation.php`。

## 已实现：经用户批准的终止后显式换绑

原 `HotSwapInstanceDriverAction` 要求本地 Active/Suspended，而 `terminate` 处置要求旧资源已认证 Deleted。正常终止任务确认 Deleted 后，本地实例也成为 Deleted，导致管理界面的“已终止且确认 Deleted”选项无法完成正常流程。

用户已明确采用 `docs/backend-review-fix-decisions.md` 的定向方案 B；共享章程、INV-S1 与 guardrails 已记录该授权。实现只在现有管理员换绑 Action 中增加 **Deleted + terminate** 的例外，未修改 `InstanceStatus` 或通用转换表。

1. 操作者具备 `upstream.hotswap` 权限，有明确原因及确认；提交前重新查询并锁定操作者，复验权限。Deleted + keep_orphan、Cancelled/Fraud/ProvisionFailed/Pending 均拒绝。
2. 旧 Deleted 必须保留原始上游绑定，并通过当前有效账号重新认证 Deleted；受理删除 ACK、本地状态或客户输入不能替代认证。新账号、host、商品、周期、币种及目标 Active/Suspended 继续按既有协议认证。
3. 锁定所属用户、全部关联账单和实例后，拒绝任何 Unpaid、Refunded 账单、退款流水或 `invoice.refund` 审计，也拒绝所有 pending/running/failed 履约任务，包括无账单的生命周期任务。其他 Paid 单不能抵消退款历史。本次批准未要求新增原始购买单存在性规则，也不新增账单或收退款。
4. 网络读取均位于提交事务外。提交时复验完整原实例、旧/新账号端点、启用状态及认证指纹；按 ID 顺序锁定相关用户、账单和账号。账号或绑定发生变化则拒绝旧验证结果，未决任务的查询在实例锁后执行。
5. 同一事务保存旧资源 terminated 历史和新绑定、轮换 token、清理旧绑定控制标记、归档机器操作、使旧待办 stale，并创建新回调注册任务。审计明确记录原 Deleted、新状态、操作者、原因及 `terminal_rebinding=true`。目标唯一冲突整笔回滚。
6. 普通同步、续费、回调、任务重试和通用 `canTransitionTo()` 继续禁止终态复活。原有活动服务换绑行为保留；退款检查的新增限制仅用于上述 Deleted 例外。

### 现有财务关联是否够用

正常应用路径的退款证据已经可以关联，不必为了该判断新增字段：`invoices.instance_id` 指向实例；`RefundInvoiceAction` 同一事务把账单设 Refunded，并写唯一 `financial_transactions.reference_key=refund:{invoice_id}`（`invoice_id` 外键保留）及 `invoice.refund` 审计。退款与现有换绑都先锁所属用户，因此可在锁后查询并复验全部关联账单来串行化正常并发。

修复后的终态换绑核对以上三类证据；任一证据都足以拒绝，即使其他历史字段不一致。代码无法证明没有记录的外部退款不存在，也不执行历史数据修复。

涉及文件：`app/Actions/HotSwapInstanceDriverAction.php`、`tests/Feature/ReviewDeliveryFixesTest.php`；共享规则记录由主任务同步。

## 本轮验证

新增 `tests/Feature/ReviewDeliveryFixesTest.php` 覆盖升级锁忙时无 HTTP、延迟后完整创建/支付/配置确认一次、真实未知创建重试不重放；账号六种变更发生在登录或 header 阶段时拒绝旧回调；正常终止确认→显式换绑 Active/Suspended；权限/确认/其他终态/目标属性拒绝；退款三类证据及真实退款；全部未决任务；HTTP 期间退款、账号、绑定或权限变化；目标唯一冲突完整回滚；审计、token、旧回调与新回调任务。

隔离 SQLite（独立临时 storage、独立随机密钥、`:memory:`、禁真实 HTTP、模拟队列）运行新测试及既有 HostSync、MachineOperationReconciliation、ClassicExpansion：**147 passed / 1498 assertions**，日志 `/tmp/review-delivery-fixes-sqlite.log`。

独立离线 MySQL **8.0.35**（本机已有镜像、`--pull=never --network none`、私有 Unix socket、tmpfs 数据目录、专用 schema）运行新测试及既有 HostSync、MachineOperationReconciliation：**106 passed / 1100 assertions**。另运行 ClassicExpansion 中 `renewal|upgrade|termination|suspend|swap|bind two|operation|delivery|notification|restor` 筛选：**25 passed / 284 assertions**；未改变旧测试中的严格 JSON 断言。日志 `/tmp/review-delivery-fixes-mysql.log`。

真实双进程、独立 MySQL 连接另行验证：退款先持有 buyer 行锁；换绑完成模拟供应商认证后尝试同一行锁；退款在另一个进程完成并提交，换绑等待约 **0.457 秒**后读取到 Refunded 并拒绝。账单维持 Refunded、服务维持 Deleted 与旧绑定、钱包只退款一次，未产生换绑历史/审计/新回调任务。此项 **PASS**，独立日志 `/tmp/review-delivery-fixes-mysql-race.log`。最初临时并发脚本的缺省参数读取和屏障位置错误已修正；原始失败记录保留，未将其报告为应用测试通过。

四个修改的应用文件 PHPStan：**0 errors**；上述应用文件及新测试 PHP lint、Pint：**passed**，本组文件 `git diff --check` 通过。日志 `/tmp/review-delivery-fixes-phpstan.log`。相关临时脚本与原始日志归档于 `/tmp/laravel13idc-review-delivery-evidence-20261118/post-fix/`，测试容器均已删除。隔离验证不代表生产部署或真实供应商验收。
