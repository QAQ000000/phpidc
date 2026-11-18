# 全项目架构与完成度审查

日期：2026-11-18（Asia/Shanghai）。代码基线：`542334858809881523309b83a9812d9636a348bb`。由独立系统架构代理主审，运维代理和主代理交叉核验。审查覆盖代码、规范、发布入口、已有验收证据和当前预览状态；本轮仅新增本报告，没有修改实现、规则、迁移、测试或服务。

本报告保留上述审查时点的事实；后续 W0–W5 修复和部署统一见[当前发布状态](release-status.md)与[修复方案](system-architecture-remediation-plan-2026-11-18.md)，不能把审查基线的缺陷状态当作后续代码当前状态。

## 结论

项目已具备限定业务范围的销售平台代码闭环，可以继续作为候选版本验收。**目前不能认定整个项目完成，也不满足完整商业平台的直接上线条件。** 主要缺口集中在共享模块契约、资金与身份退出、历史异常处置、实际升级部署和运行监测。

已有完整回归为 1068 项测试 / 9033 断言，PHPStan、Pint 通过，另有隔离 MySQL 并发、HTTP 和浏览器验收。这些结果确认已覆盖场景的实现质量。本轮仍发现测试边界之外的跨模块问题，包括可复现的身份邮件异常，以及普通商品超付款的金额容量错误。Specs 剩余 0 仅代表原执行规格已实现。

不使用单一百分比表示全项目完成度：当前没有覆盖所有延期能力、实际供应商、发布和恢复条件的统一验收分母。以下分别标记实现、验证和运行状态。

## 业务域完成度

| 业务域 | 实现状态 | 验证与交付边界 |
| --- | --- | --- |
| 本地卡密 / 本地 Sub2API | 主要销售流程已闭环 | 购买、组合支付、库存、交付、退款保护和本人读取已验证；一次性交付权益的账户退出规则缺失 |
| 远程 Sub2API | 固定面额交付已实现 | 有专用账号真实生成/兑换历史证据；完整远程管理、直接账号加额不在范围内 |
| 经典 ZJMF 新购 | 主要代码流程已闭环 | 有历史真实小额新购、成本和 Active 验收；完整生产公网回调与负载未验收 |
| 经典续费 / 升级 / 终止 / 热切换 | 部分完成 | 状态机、幂等和未知结果保护已实现；真实生命周期未全验，热切换后配置复核没有完成入口 |
| 钱包 / 佣金 / 外付 / 应收 | 主要账务流程已实现，退出流程部分完成 | CNY 充值、人工佣金外付和应收清偿已隔离验收；普通钱包结清、匿名身份迟到款清退缺少执行闭环 |
| 注册 / 登录 / 身份绑定 / 注销 | 部分完成 | 当前身份匿名化和并发保护已实现；SMTP 异常兼容存在缺陷，多类注销阻断缺少解除路径；真实邮件/OAuth 未全验 |
| CMS / 下载 / 优惠券 / 工单 / 后台权限 | 主要范围已实现 | 有路由、权限、历史 slug 与页面验收；生产内容规模、复杂长文冷解析和真实运营负载未验收 |
| 通知持久事件 outbox | 已接入事件的发送闭环已实现 | 已验证事务记录、补扫、租约、未知结果人工核对；关键运营异常接入和实际巡检尚未形成完整保障 |
| PVE / 额外支付渠道 | 未实现，明确延期 | 存在枚举、接口或依赖不代表驱动已实现；当前 DriverManager 仍拒绝这些能力 |
| 安装 / 升级 / 发布 / 恢复 | 隔离安装已验收；实际运行交付未完成 | 原预览缺四条新迁移；服务模板未安装，运行账户权限未落地；生产 MySQL/Redis 恢复和容量未验收 |

主要依据：[五项能力实施验收](remaining-capabilities-implementation-2026-11-18.md)、[候选版本历史记录](release-0.1.0-rc.1.md)、[现有产品预览验收](backend-review-preview-acceptance-2026-11-18.md)、[驱动解析](/www/wwwroot/Laravel13idc/app/Services/DriverManager.php:87)。历史真实验收只证明对应时点与动作，本轮没有重新请求真实供应商。

## 优先发现

P1 表示应在正式开放相关业务前处理的运行阻断、常见关键旅程问题或必要业务缺口；P2 表示确认的限定场景问题或有明确触发条件的容量/运行缺口。条件风险会单独标明未作负载实测。

### ARCH-01 · P1 · 当前运行代码与 schema 不一致，影响旧业务

**类型：已确认部署阻断。** 原预览只读查询确认四条 `2026_11_18_000004`—`000007` 未执行，`affiliate_frozen_balance`、应收清偿表、notification_events 均不存在。与此同时，`/up`、前后台登录页和商城仍返回 200；这些 GET 的成功仅说明对应入口存活。

影响覆盖已存在的业务：旧推广页在 [CustomerOperationsController](/www/wwwroot/Laravel13idc/app/Http/Controllers/CustomerOperationsController.php:63) 调用新应收投影，[AffiliateReceivableBalance](/www/wwwroot/Laravel13idc/app/Services/AffiliateReceivableBalance.php:50) 查询新表；普通商品结算经 [CreatePurchaseFulfillmentAction](/www/wwwroot/Laravel13idc/app/Actions/CreatePurchaseFulfillmentAction.php:52) 写入 outbox。旧库下相关读取会缺表，结算事务也不能完成。仅关闭充值等新入口无法隔离这些依赖。

最小处理：完成维护边界、备份、四条增量迁移、配置/资源与进程一致性检查，再开放相关业务。`app:deployment-check` 已有待迁移检查，应作为实际发布步骤执行；不要用 `/up` 替代。用户此前批准 D01–D05 的设计不包含对原库执行这四条迁移，本轮审查未擅自执行。

生产运行还缺实际交付：六个相关 systemd unit 未加载；模板使用的 `www` 对当前 root:root / 0755 的 storage/cache 目录没有写权限。这属于环境准备未完成，不能据此否定隔离代码测试，也不能直接照模板 start 便宣称上线完成。

### ARCH-02 · P1 · 共享邮件适配器的异常协议没有同步到身份流程

**类型：已复现实现缺陷。** [LogMailDriver](/www/wwwroot/Laravel13idc/app/Drivers/Notification/LogMailDriver.php:34) 将 SMTP 临时拒绝转换成 `RetryableNotificationException`，将传输失联转换成 `UncertainNotificationException`；[CustomerIdentityAction](/www/wwwroot/Laravel13idc/app/Actions/CustomerIdentityAction.php:107) 仍只捕获 Symfony 的 `TransportExceptionInterface`。

找回密码或发送验证邮件遇到上述异常时，异常会逃逸到 HTTP 入口，产生 500；原计划的失败审计也不执行。找回密码 token 已在发送前生成，随后重试还受 token 节流影响。已使用不启动 Laravel、不连接数据库或网络的最小容器复现，两类异常均逃逸。

最小处理：统一邮件调用者对明确拒绝、发送结果未知和受理成功的处理，保留身份接口防枚举语义和失败审计；增加“真实适配器异常分类 → 身份 Action → HTTP”集成验收。未知结果不能通过无条件自动重发解决。

### ARCH-03 · P1 · 钱包与一次性交付用户缺少合法账户退出路径

**类型：已批准首期边界组合后的全局能力缺口，需要业务决议。** [AccountClosureEligibility](/www/wwwroot/Laravel13idc/app/Services/AccountClosureEligibility.php:38) 阻断非零钱包；[D01 决议](remaining-capabilities-decisions-2026-11-18.md) 明确不开放充值退款，现有外部提现只处理佣金。普通钱包充值余款或退款余款没有对应的结清出款流程。注销后原 ID 收到真实迟到贷项时，目前也只有保留余额、账本与告警，没有可操作的清退出款闭环。

此外，[注销资格](/www/wwwroot/Laravel13idc/app/Services/AccountClosureEligibility.php:56) 要求所有实例终止。已交付卡密保持 Active，[RedemptionCodeDriver](/www/wwwroot/Laravel13idc/app/Drivers/SelfHosted/RedemptionCodeDriver.php:20) 不实现 RenewableResource，而[服务终止入口](/www/wwwroot/Laravel13idc/app/Actions/RequestInstanceOperationAction.php:33) 仅接受该能力。正常完成一次性购买、资金已为零的用户，仍可能无法注销；真实退款取消实例不能替代正常交付后的权益归档。

最小处理：先决定普通钱包结清及一次性交付权益归档规则，再补与账本、付款凭证、历史身份留存协调的动作。不能强制清零、回收已售卡或删除历史实例来绕过现有保护。此项不表示先前批准的 D01/D02 规则被实现违反。

### ARCH-04 · P2 · 历史异常记录被当作永不结束的阻断

**类型：已确认处置流程缺失。** [HotSwapInstanceDriverAction](/www/wwwroot/Laravel13idc/app/Actions/HotSwapInstanceDriverAction.php:120) 写入旧资源 `kept_orphan`；[AccountClosureEligibility](/www/wwwroot/Laravel13idc/app/Services/AccountClosureEligibility.php:59) 查到任意该历史值就阻断注销。未找到旧资源实际终止或移交后的核对、记录和解除入口。

同样，[付款核对阻断](/www/wwwroot/Laravel13idc/app/Services/AccountClosureEligibility.php:50) 根据永久保留的 `payment.callback.attention` 审计判断；没有读取对应的核对完成结果。即使财务线下处理了容量异常，该历史审计仍持续阻断。

最小处理：为这些异常补带权限、原笔证据和原因的追加处置记录，资格检查读取最终处置投影。保留旧审计和绑定历史，不能通过删除历史证据解除。

### ARCH-05 · P2 · 热切换后的配置复核没有完成入口

**类型：已确认流程缺口。** [热切换](/www/wwwroot/Laravel13idc/app/Actions/HotSwapInstanceDriverAction.php:135) 清空选项映射并设置 `mapping_requires_review=true`；[升级](/www/wwwroot/Laravel13idc/app/Actions/UpgradeCalculatorAction.php:16) 和[续费降价](/www/wwwroot/Laravel13idc/app/Actions/DowngradeCalculatorAction.php:34) 据此持续拒绝。全仓搜索仅找到置位和读取，没有审核新映射、重建配置/计费快照和解除的 Action、CLI 或页面。

结果是热切换完成后，页面所称“暂不可用”没有系统内的结束路径。最小处理：补显式的目标主机、商品、周期、金额和选项映射复核动作，完成审计后恢复能力；单独修改布尔值不能证明映射正确。

### ARCH-06 · P2 · 通用商品超付款遗漏钱包容量保护

**类型：独立 MySQL 8 已复现的金额边界缺陷。** 充值及终态付款路径有容量判断，但[普通商品结算](/www/wwwroot/Laravel13idc/app/Actions/HandlePaymentCallbackAction.php:118) 的超付额直接调用 [credit](/www/wwwroot/Laravel13idc/app/Actions/HandlePaymentCallbackAction.php:134)。

独立 MySQL 8.0.35：钱包 `9999999999.99`、商品账单 `10.00`、模拟实收 `10.01`，同一回调连续两次触发 `SQLSTATE 22003 / 1264`。每次整体回滚，账单仍 Unpaid，回调、账本、任务、事件及核对审计均为 0。将测试钱包设为 0 后，同一模拟回调成功，账单 Paid、超付贷项 0.01、回调 1 条。该场景需要接近字段上限，不代表普通金额支付普遍失败。

最小处理：统一所有收款路径的金额容量预检和完整收款留证策略，明确无法自动入账时的原笔核对规则；不能截断、静默丢弃或只入一部分后 ACK。补普通商品超付款的 MySQL 回归。

### ARCH-07 · P2 · 通知持久化、运营告警和巡检尚未形成整体闭环

**类型：已确认运行保障缺口。** [通知执行](/www/wwwroot/Laravel13idc/app/Actions/ExecuteNotificationDeliveryAction.php:90) 进入 uncertain 后可正常返回，队列会 ACK，failed_jobs 可能为空。现有[部署巡检](/www/wwwroot/Laravel13idc/docs/deployment.md:69) 主要覆盖同步年龄、worker 和失败队列，尚未定义 outbox pending 年龄、uncertain、无运营收件人事件的巡检责任与触发门槛。

关键异常也未全部接入持久运营事件：[履约最终失败](/www/wwwroot/Laravel13idc/app/Actions/ExecuteFulfillmentTaskAction.php:92)、[恢复耗尽](/www/wwwroot/Laravel13idc/app/Actions/DispatchStalePendingTasksAction.php:48)、[供应商回滚失败](/www/wwwroot/Laravel13idc/app/Actions/CallUpstreamAction.php:33)、[原始佣金应收产生](/www/wwwroot/Laravel13idc/app/Actions/RefundInvoiceAction.php:155) 主要写日志，部分另有数据库状态或审计，仍需主动查询。已有 outbox 核对页不等于有人会及时发现每类故障。

最小处理：确定首批必须处理的故障、责任人、可查询状态及核对完成动作，接入已有 outbox 或实际部署侧日志告警，并将业务状态纳入巡检。保持 uncertain 不自动重发的原规则。

### ARCH-08 · P2 · 串行分钟调度存在共享预算饥饿条件

**类型：已确认结构，负载触发未实测。** [调度服务](/www/wwwroot/Laravel13idc/deploy/cloudcms-schedule.service:12) 限定整个 schedule:run 为 55 秒；[调度顺序](/www/wwwroot/Laravel13idc/routes/console.php:7) 将通知补扫放在账单过期、履约恢复及佣金处理之后。[ExpireUnpaidInvoicesAction](/www/wwwroot/Laravel13idc/app/Actions/ExpireUnpaidInvoicesAction.php:53) 和佣金处理虽然 chunkById，但没有本轮总量/时长预算。

前置批处理积压或锁等待耗尽共享时间时，后续补扫可能根本没有运行机会；持续积压可能跨分钟重复该情况。最小处理：给本地批处理明确的每轮预算和跨轮进度，或拆分独立执行边界；用积压和锁等待场景验收公平性。没有在业务环境制造负载，也没有把该风险表述为已经发生的漏发。

### ARCH-09 · P2 · 应收投影全量加载历史并进入资金锁持有区间

**类型：代码中的无界查询已确认，容量与延迟影响未实测。** [forUser](/www/wwwroot/Laravel13idc/app/Services/AffiliateReceivableBalance.php:22) 读取全部历史 clawback，[many](/www/wwwroot/Laravel13idc/app/Services/AffiliateReceivableBalance.php:50) 再读取关联清偿、冲正、账本和佣金。旧推广页先执行 hasOutstanding，随后再次执行 forUser；外付申请则在[用户锁内](/www/wwwroot/Laravel13idc/app/Actions/RequestExternalAffiliateWithdrawalAction.php:32) 检查应收。后台总列表有分页，选中某笔后的清偿历史仍全量读取。

历史增长会扩大 PHP 内存、SQL 参数集合和资金锁持有时间，可能将一个读取功能的成本传递给充值、退款和提现竞争。当前不声称已测得生产超时或 QPS 上限。最小处理：减少页面重复投影、分页历史展示，再为资金准入建立经证据校验且可对账的有界查询/投影；保留原始账本和异常证据验证，不能只做余额求和来省略业务规则。

### ARCH-10 · P2 · 主发布索引和恢复说明与当前交付矛盾

**类型：已确认文档缺陷。** [README](/www/wwwroot/Laravel13idc/README.md:3) 指向的[版本说明当前状态](/www/wwwroot/Laravel13idc/docs/release-0.1.0-rc.1.md:7) 仍称“当前未提交”，且仍把钱包充值、注销、外部提现与通知 outbox 列为未交付。历史矩阵所述“应收自动清偿未做”与本次 D04 人工登记清偿属于不同能力，不以该句判定人工清偿未实现。[Redis 恢复说明](/www/wwwroot/Laravel13idc/docs/deployment.md:89) 仍称通知没有持久事件 outbox；[换钥清单](/www/wwwroot/Laravel13idc/docs/deployment.md:104) 未反映已实现的外付三列密文和 outbox 两表。

最小处理：建立一个面向当前提交的完成度入口，同步能力、迁移、队列、恢复和换钥说明；历史验收矩阵继续保留并标注历史。发布索引应能直接回答“实现了什么、当前运行了什么、还有什么阻断”。

## 全局处理顺序与验收条件

| 顺序 | 工作 | 完成依据 |
| --- | --- | --- |
| 1 | 统一代码/schema/运行进程，修共享邮件异常与收款容量覆盖 | 完整升级演练；旧推广页与普通商品结算通过；SMTP 拒绝/未知结果的身份 HTTP 行为和审计通过；普通超付 MySQL 回归通过 |
| 2 | 决定资金退出、一次性交付归档及历史异常处置规则 | 正常已购卡用户、钱包余款用户、原笔核对完成用户均有合法后续路径；不得通过改账本或删审计过关 |
| 3 | 完成热切换后的配置复核与恢复 | 目标资源和计费快照有可追踪核准；升级、续费和原资源处置能衔接 |
| 4 | 将业务告警、人工核对与调度运行保障连起来 | uncertain/积压/履约失败可被负责人员发现并处理；积压下各调度均有执行机会；运行用户与服务实际验收 |
| 5 | 验证计划启用的真实生命周期、容量与恢复 | 在明确测试商品/额度/动作授权下验收；MySQL/Redis/文件/密钥同检查点恢复演练；提供容量假设与实际测量 |

PVE、更多支付渠道等扩展应在上述闭环后按实际业务需要安排。现有“降配”只降低未来售价、保留高配资源与上游成本，符合已批准规则；应向运营明确其价格优惠性质，真实资源降配另行定义。

后续每一项修复以完整旅程为验收单位，例如“购买卡密 → 交付 → 资金结清 → 注销”“热切换 → 原资源核对 → 配置核准 → 再次升级”“收款异常 → 告警 → 原笔核对 → 解除阻断”。同时检查共享驱动的全部调用者、页面入口、运行脚本及文档，避免仅让新增 Action 的测试通过。

## 本轮证据与限制

- 独立架构代理和运维代理均只读审查；主代理只读访问原预览库，设置 SQLite query_only，没有在该库运行 fixture、迁移或资金动作。
- SMTP 最小复现：`/tmp/laravel13idc-system-architecture-a469h9wl/smtp-identity-exception.php` 与同目录 `.log`，两种新异常均逃逸，无数据库或网络调用。
- 当前运行核验：`/tmp/laravel13idc-system-architecture-a469h9wl/runtime-evidence.json`，包含原预览四条待迁移、缺表/列、只读 HTTP 200 及 PID 1448041 证据。
- 运维只读核验：同目录 `operations-evidence.json`，保留服务未加载、运行账户写权限、关键源码位置及限定范围进程证据，不含凭据或环境密钥。
- 普通超付款复现：`/tmp/laravel13idc-architecture-overpay-zwzq78_5/reproduction.json`；同目录保留隔离脚本、迁移和清理日志。使用离线 `mysql:8.0.35`、network none、专用 socket、tmpfs、独立 APP_KEY/storage、Queue fake 和 Http::preventStrayRequests。回调数据全部模拟，未请求网关或供应商；专用容器已删除。
- 保留既有 1068/9033 门禁与专项验收结果，本轮没有改代码、改测试或重复跑全套；新增复现用于确认原覆盖之外的缺口。
- 条件容量风险未作生产压测；未启动原预览 worker/scheduler，未进行真实付款、退款、提现、通知或供应商变更；未修复本报告发现、未创建提交。

IMPLEMENTED: 全项目架构完成度矩阵、10 项跨模块发现、分级处理顺序及独立复现证据。
NOT DONE: 发现项修复、业务规则决议、原预览四条迁移及生产部署/容量/恢复验收。
DECISIONS: 保留用户已批准 D01–D05 和历史规则，以跨模块实际旅程判定完成度。
CONCERNS: 代码/schema 运行不一致；资金与身份退出、异常解除、共享适配器错误处理尚有断点。
GATES: 既有 Pest 1068/9033、PHPStan/Pint 通过；本轮 SMTP 与 MySQL 针对性复现完成；报告链接与 diff 检查通过，独立架构代理复核可交付。
SPEC CASES: 0，未修改。
