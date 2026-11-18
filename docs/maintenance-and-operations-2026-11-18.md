# 有界维护批次与独立巡检

起始日期：2026-11-18；接入与隔离验收更新：2026-11-19。对应 W4 / ARCH-07–08。代码已绑定维护 handler 并切换原调度；模板存在不表示实际服务已经运行。

## 批次运行边界

七类业务调度保持原周期，只派发本地 `maintenance` 工作。佣金计提和成熟使用两个独立 Job，因此共有八个 kind：invoices、fulfillment、cards、lifecycle、catalog、affiliate-accrue、affiliate-mature、notifications。

`RunMaintenanceBatchJob` 仅携带 kind，独立于 scheduler 使用单类队列去重 180 秒、执行锁 30 秒、timeout 25 秒、tries 1。`maintenance:dispatch` 拒绝同步队列及内部子类；维护 handler 未绑定时明确拒绝派发。真实供应商查询、履约和发送继续使用原队列，不在维护事务中执行。

每个 `FairMaintenanceBatch` 最多选择 100 个候选，默认 50 个最早项与 50 个 ID 轮转项交错处理。轮转游标只推进已取出的轮转项；尾部回绕，缓存丢失可从原业务条件重新扫描。软预算为 5 秒，只在单项边界停止，不拆开业务事务。维护连接临时使用 2 秒行锁等待，退出时恢复原连接参数。该软预算允许最后一项锁等待完成后略超过 5 秒；25 秒 Job 超时仍是独立硬限制。

通知维护使用展开、过期租约恢复、到期待派发、持久运营事件转通知四个子批次，各最多 25 单位，交错使用最早 12 / 轮转 13 个候选，四者共用一个总计 100 单位 / 5 秒预算。每次 Job 轮换首个子批次，避免前面的慢项持续占用后面机会。展开按实际扫描收件者、绑定及 fallback 单位计费；单事件最多消费本子批配额的一半，部分页已提交后发生异常也保守扣除本次页配额。每页检查同一个共享截止时间，不在下一阶段重新获得 5 秒。

`RunMaintenanceBatchAction` 分别查询原业务待办条件，调用账单、履约和卡密的单项 Action；佣金计提与成熟继续调用原资金 Action。目录和生命周期维护只向原业务队列派发；紧急和普通生命周期配额分别最多 50。账单到期、履约恢复与卡密回收保留原命令名，定时明确传入真实 `--maintenance` 选项派发 Job；不带选项的手动命令仍调用原 Action。既有手动目录/生命周期 CLI 仍使用原已验证的批量规则，定时入口走新的有界维护 Job；原履约恢复手动入口仍逐项传播派发异常，缓存去重失败后立即允许重派。

单项失败不终止后续机会，保留原 kind/ID、安全错误码及 60/300/900 秒递增重试时间。这个计数仅用于维护重试，不写入履约的消费 attempts，不改变未知付款或原租约规则。失败样例最多保留 100 条；巡检只输出其中 20 条。异常消息、SQL、目标地址和正文不会进入心跳或样例。

调度去重、执行互斥和业务幂等各自承担不同职责。存在大量锁忙项时，2 秒等待仍会消耗软预算；这些初始参数不表示任意积压在两分钟内全部清空。Redis 缓存值与原子锁可能使用不同 connection / logical DB，仅清游标不会解除仍存在的队列去重或执行锁。

## 独立应收核验

独立 `app:receivables-audit` 每五分钟最多轮转 100 名含应收证据的用户（包含软删除用户），仅派发 `AuditReceivableEvidenceJob`，不在 scheduler 扫完整账史。该 Job 在 `receivable-check` 单 worker 使用 90 秒软预算、120 秒超时、tries 1；锁外完整读取 `ReceivableSnapshot`，仅把完整结果交给 `flagIfUnchanged` 在短用户锁内核版。超时或失败保留异常和结构化失败记录，不以未完成结果设置财务 reconciliation flag。此调度独立于只读 operations-check。

## 心跳与只读巡检

所有批次状态通过本应用缓存前缀保存在 Redis，默认 `MAINTENANCE_CACHE_STORE=redis`：

| 键 | 内容 |
| --- | --- |
| `maintenance:heartbeat:{kind}` | dispatched/started/completed/failed 时间及有界批次统计 |
| `maintenance:cursor:{kind}` | 轮转 ID |
| `maintenance:retry:{kind}:{id}` | 原项安全错误码、维护重试次数与 retry_at，TTL 3600 秒 |
| `maintenance:failures:{kind}` | 最多 100 条仍可关联重试键的样例 |
| `maintenance:notifications:phase` | 下个通知 Job 的首个子批次 |

`failed_at` 历史不会因一次完成被删除；只有它晚于 `completed_at` 时才表示最近批次失败。

```bash
/www/server/php/84/bin/php artisan app:operations-check --json
```

该命令只读，不修复、不派发、不发送，也不确认告警。检查数据库/Redis 可达性、八类维护最近完成及未消除的安全失败样例、outbox 到期年龄、uncertain、待配置运营接收者、最终履约失败及实际供应商回滚失败。同时观察 `operational_events` 尚未转通知的积压，以及独立保留的履约/回滚失败事实。每类最多 20 个样例 ID，不读取或输出 variables、通知正文/目标密文。Redis/DB 异常只输出固定错误码。

| 指标 | 初始阈值 |
| --- | --- |
| 分钟维护最近完成 | >150 秒 warning，>180 秒 critical；从未完成为 critical |
| 五分钟 catalog 最近完成 | >450 秒 warning，>480 秒 critical |
| 到期 outbox 最老年龄 | 120 秒 warning，>300 秒 critical |
| uncertain | 立即 warning，>900 秒 critical |
| operational_events 尚未转通知 | 120 秒 warning，>300 秒 critical |
| 最近批次完成但仍有维护单项失败样例 | warning |
| 无运营接收者、最终履约失败、供应商回滚失败、已记录的失败事实 | critical |

命令退出码：0 健康、1 警告、2 严重故障。具有 `finance.view` 的活跃身份计为“已配置接收者”；这不能证明地址真实可达或已经有人承担值班。实际接收目标、责任人和送达验收仍由部署负责人核实。

## 独立 watchdog 与服务模板

- `cloudcms-maintenance@1`、`@2`：只消费 maintenance，timeout 25，停止余量 35 秒；原队列 `retry_after=240` 继续大于 Job 超时。
- `cloudcms-receivable-check`：只启一个进程，消费 receivable-check，timeout 120，停止余量 150 秒；不得混入供应商任务。
- `cloudcms-operations-check.timer`：每 60 秒通过独立 Python 进程执行只读命令，子进程硬限制 10 秒；systemd 外层 15 秒。
- `cloudcms-operations-watchdog.timer`：另一个每 60 秒进程读取运行心跳；90 秒未更新、命令无法启动、数据库/Redis 故障或检查超时都会产生非零退出和结构化 journal。它不依赖应用 scheduler、worker 或通知 outbox。

`scripts/operations-watchdog.py` 原子写入 `/run/cloudcms-operations/check.json`（0640）。RuntimeDirectory 由 check service 建立并保留，两个服务均以 www 身份运行。部署前核实 PHP、项目目录及真实环境；实际启用时先确认只读检查及两类消费者就绪，再启动业务调度。该记录不授权启用服务。

外部监控必须订阅这两个 service 的失败状态和 watchdog 自身停止/缺失，并配置实际接收方。模板只负责独立检测和 journal，不会把 outbox 当成自己的告警出口；本轮没有发送任何真实外部通知，也没有安装或启用 systemd 服务。

## 已执行检查

- 公平批次、共享通知预算、只读巡检、独立应收调度四个专项：27 项、178 个断言通过，覆盖部分展开后失败计费、四阶段首机会轮换、写 SQL 为零、20 个样例上限、Redis/DB 失联、到期阈值、持久事件积压及心跳恢复。
- 接入后的原严格回归及维护基础专项：107 项、731 个断言通过，包含履约 claim budget / recovery、原目录与生命周期批次、通知 outbox、闭户账单释放等旧约定。
- 独立 watchdog：6 个 Python 测试通过，覆盖检查超时、缺失/陈旧心跳、原始错误不外泄及警告升级。
- 新 PHP 实现定向 PHPStan 0 errors、Pint 通过；全部新增 systemd 模板静态 verify 通过。

`scripts/verify-maintenance-fairness-mysql.py` 使用禁止网络与端口映射的临时 MySQL 8.0.35 / Redis 7 容器及 Unix socket，运行真实 RunMaintenanceBatchJob、两个真实 Redis worker 与 FairMaintenanceBatch。8 类各 160 条合成候选；专用无业务副作用 consumer 验证基础设施公平性，真实业务 Action 由上述专项另外验证。该脚本覆盖毒前缀、后到期旧 ID、仅游标丢失、真实 InnoDB 前 100 行锁忙，以及实际 kill worker 后完整 Redis 丢失的重新扫描。完整 Redis 丢失同时移除队列 reservation 与原子锁；没有声称原 240 秒 reservation 自动重投已验收。实际结果：毒前缀首轮 2.855 秒、越过毒前缀的下一轮 2.155 秒；后到期旧 ID 1.599 秒、游标丢失 1.537 秒；8 类真实锁忙轮次 25.549 秒，每个 Job 3 次尝试、约 6 秒后结束；kill 后完整 Redis 丢失重新扫描 2.586 秒。均以两个真实 worker 执行，未达到 25 秒 Job 超时，8 类每轮均在 120 秒内取得有界进度。私有证据 `/tmp/laravel13idc-maintenance-fairness-u80wq_v1/result.json`，两个临时容器均已移除。

以上检查不表示生产或原预览 worker/scheduler 已启动；本轮未安装或启用 systemd 服务，实际外部告警接收者仍未配置。
