# 架构清理第四批实施记录

基线 `c253596`；通知提交 `7bf1d8b`，维护提交 `e1099ee`。用户指示“可以开始第4批”，沿用验证通过后自动提交的授权。本批按 [B4 方案](architecture-fourth-batch-plan-2026-11-19.md)完成通知当前路径、维护派发归属和历史文档入口收敛，无 schema 变更。

## 通知仅传投递 ID

当前 Dispatcher 改为创建 `SendNotificationDeliveryJob(deliveryId)`，不再解密投递快照、加载事件或构造旧多参数任务；只检查原持久投递状态与原密文是否存在。新 Job 直接进入 `ExecuteNotificationDeliveryAction`，接收者、渠道与过滤后的变量仍由既有持久加密快照决定。

旧 `SendNotificationJob`、`SendEventNotificationJob`、两个兼容 Action、序列化过滤及历史任务收编保持原样，已有队列无需转换或清空。原实现已有序列化过滤，本批不把流程重复误报为已经发生的队列泄露。发送仍保持事务 outbox、afterCommit、原子 claim、发送意图、闭户抑制、60 秒 timeout、120 秒数据库租约、最多 3 次框架尝试和原退避；uncertain 不自动重发。

新增 5 项测试覆盖新对象/序列化仅业务 ID、真实 database worker、重复任务、429/release/耗尽、uncertain、租约余量以及事件/续费无解密派发。5 个既有测试文件只调整当前 Job 类别、handle 入口和快照读取，保留原数量、渠道、目标、日志、隐私、幂等及旧格式断言，Specs 未改。

## 维护派发回归领域 Action

`DispatchInstanceLifecycleAction`、`DispatchClassicCatalogAction` 增加 `executeMaintenance(MaintenanceBudget)`，负责各自手工和计划派发；共用候选资格及 Job 创建，`RunMaintenanceBatchAction` 仅按 kind 委派。没有新增通用派发框架、模式开关或队列。

| 行为 | 手工入口 | 计划维护 |
| --- | --- | --- |
| lifecycle 配额 | urgent≤100、rotation≤100，合并去重≤200 | 各≤50，共享总100单位/5秒软预算 |
| urgent 队列判断 | 本轮 urgent 已选集合 | 当前到期边界≤7天 |
| catalog 配额 | 按配置≤200 | 按配置≤100，并受传入剩余额度约束 |
| 游标及失败 | 原独立 cache cursor；异常传播 | 原 FairMaintenanceBatch 独立 cursor、公平交错与逐项退避 |

计划 catalog 复用调用方预算，不重新创建 deadline 或丢弃已消耗额度；原默认计划配额保持。CLI、scheduler、Job 类型、队列、锁键与绑定指纹保持。

锁争用测试复现原缺陷：Laravel `Cache::lock()->get(callback)` 拿锁失败返回 `false`，原 `??` 未接住，造成 int/array 返回类型错误。四入口现返回既有零数/空 summary，不派发、不推进游标、不耗预算；锁释放后恢复正常。

新增维护测试包含 23 个数据集用例，核对真实配额、独立游标、urgent 差异、资格、绑定指纹、共享锁、预算、异常传播与退避。

## 文档入口与归档

新增 [文档入口](README.md)和 [历史索引](archive/README.md)。9 份已完成 W1–W3、B1–B2 实施/验收报告移入 `docs/archive/`，原路径保留原标题、全部章节标题/锚点及直达归档对应章节的链接。归档正文除相对链接和历史提示外保持，不把历史结果当作当前发布状态。

这 9 个原入口从 63824 字节缩为 8691 字节；完整正文仍保留在归档，仓库总文件数或总字节并未因此减少。当前 B3、W4/W5、规范、活跃裁决和部署文件仍在原位。[上游考证总册](zjmf-上游API对接总册.md)中两处未收录分册改为明确存目文字并链接现有对应章节，修复两个基线断链，没有补造未交付协议。

链接检查对照固定基线 `c253596`，校验原标题/锚点集合、正文及 361 个当前本地 Markdown 链接；缺失文件、缺失锚点和新增断链均为 0。当前结果以 [发布状态](release-status.md)和 [部署手册](deployment.md)为准。

## 完整门禁与真实隔离验证

最终 Pest **1801 passed / 14895 assertions**（130.19 秒）、PHPStan **0 errors**、Pint **PASS**；12 个改动 PHP 文件 lint、通知验证脚本 Python 编译及 `git diff --check` 通过。基线为 1773 / 14699，新增 28 个用例；Specs 保持 0 且未改动。完整门禁日志在 `/tmp/laravel13idc-architecture-batch4-{pest,phpstan,pint}.log`。

`python3 scripts/verify-notification-outbox.py` 在离线一次性 MySQL 8.0.35 中通过；同库专项 Pest 20 passed /102 assertions。真实 database queue worker 验证新事件及续费任务各发送替身一次，队列载荷仅含投递 ID；并发事件唯一、认领竞争、闭户先取得原用户锁、发送期间闭户、结果落库失败后的 uncertain 和恢复不重发均通过。脚本将闭户 barrier 放在真实 Close Action 取得用户锁之后，保持 W4 锁外完整核验，不额外包裹资金事务；不改变应用锁顺序或竞争断言。证据在 `/tmp/laravel13idc-notification-outbox-b05yw47k/`。

维护专项在 network=none、无宿主端口的一次性 MySQL 8.0.35/Redis 7 中执行真实两个领域 Action 和维护总入口，使用有效安装封印及 24 条迁移。夹具为 1 个原 owner、1 个有效 classic 账户、205 个商品、先创建的 110 个普通实例及 100 个临期实例，三项批量设置均为 999。手工 lifecycle 实际派发 200（100临期/100普通），计划 100（50/50）；catalog 分别 200/100。四个游标仅各自推进，另一入口哨兵保持；独立 PHP 进程同时占用两个 Redis 领域锁时，四入口结果均为零、Queue 为零、游标不动，释放后恢复同配额。两轮合计核验 600 个 lifecycle 绑定指纹。队列/HTTP/邮件使用替身，未启动消费者、未访问真实供应商。证据在 `/tmp/laravel13idc-batch4-real-dispatch-iv6jaxsi/result.json`，`cleanup.json` 确认两个自建容器均已删除。

## 原预览只读核对

按原服务 PID 1728214 的 PHP 8.4、cwd、运行环境及独立 storage 定位原 SQLite 预览，未使用仓库 `.env` 的其他数据库目标。本批无迁移、无需重启或启动业务消费者。

前后逐表哈希核对 **57 张持久表、734 条原行**完全保持，原 `jobs=1` 未消费，迁移仍 **24 条**；`quick_check=ok`、`foreign_key_check` 无异常。`/up`、`/login`、`/admin/login` 均 HTTP 200；未发现本项目实际业务队列或调度消费者。只读证据为 `/tmp/laravel13idc-batch4-preview-before.json` 和 `/tmp/laravel13idc-batch4-preview-result.json`。会话/缓存表不计入持久业务哈希。

IMPLEMENTED: B4-01 当前通知 ID 任务、B4-02 领域维护派发及锁争用修复、B4-03 文档导航与兼容归档；按通知、维护、文档独立提交。
NOT DONE: 其余业务对审计的依赖迁移、安装封印生命周期重做、生产消费者/真实告警启用及真实支付/外付/通知/供应商操作不在本批范围。
DECISIONS: 保留旧任务兼容及两入口原有策略差异；catalog 沿用调用方预算；历史归档保留原文与链接。
CONCERNS: 旧 Job/Action 因已排队任务及旧调用兼容仍保留；当前审计表仍有业务读取，不能清空；隔离替身验收不证明生产外部送达。
GATES: pest 1801 passed /14895 assertions | phpstan 0 errors | pint clean；真实隔离通知 worker/竞争、MySQL/Redis 领域派发及跨进程锁 PASS；预览24迁移、持久数据保持、HTTP200。
SPEC CASES: 0（未改动）。
