# 架构清理第四批：文档与流程收敛

基线 `c253596`。用户指示“可以开始第4批”，对应终审计划中的文档与流程收敛；沿用验证通过自动提交、必要时备份迁移预览的执行授权。本批不需要 schema 变更。

## B4-01：当前通知任务只传投递 ID

当前持久 outbox 已保存加密投递快照，但派发仍先解密快照、读事件，再构造旧多参数 Job，经透传 Action 转回投递 ID。现有序列化过滤已经保护队列载荷，不能把它误报为已证实泄露。

- 新增当前专用 `SendNotificationDeliveryJob(deliveryId)`，直接调用既有 `ExecuteNotificationDeliveryAction`。Dispatcher 仅检查持久投递状态和原密文是否存在，不解密、不加载事件。
- 保留旧 `SendNotificationJob`、`SendEventNotificationJob`、旧 Action 和序列化过滤，兼容已排队任务和明确旧调用。当前派发不再绕经这些兼容层。
- 保留业务事务内事件/快照、afterCommit 派发、原子认领、3 次框架尝试、60 秒 Job timeout、120 秒数据库租约、原退避、发送意图、闭户抑制和 uncertain 不自动重发。
- 当前测试的队列类别和 handle 入口随当前 Job 调整；变量过滤改读持久快照，保持原精确数量、目标、渠道、日志、隐私与幂等断言。旧直接构造和反序列化测试继续覆盖旧路径，Specs 不改。

## B4-02：维护派发集中到所属领域

核对代码与现行 W4 记录后，手工和计划入口的配额及队列判定是有意不同的，不按审计报告的概括直接统一。

| 行为 | 原手工入口 | 计划维护 |
| --- | --- | --- |
| 生命周期配额 | urgent≤100、rotation≤100，合并去重≤200 | 两类各≤50，共享总≤100/5 秒预算 |
| 生命周期 urgent 队列 | 属于本轮 urgent 已选集合 | 每项当前到期边界≤7 天 |
| 商品目录配额 | ≤200 | ≤100，并受配置限制 |
| 游标/失败 | 原独立 cursor；异常向调用者传播 | FairMaintenanceBatch 独立 cursor、交错选择、逐项退避 |

将计划专用策略归入现有 `DispatchInstanceLifecycleAction`、`DispatchClassicCatalogAction` 的 `executeMaintenance(MaintenanceBudget)`，私有方法共用候选资格及实际 Job 派发。`RunMaintenanceBatchAction` 只作 kind 委派；保留原 CLI、Job、scheduler、锁键、绑定指纹和队列。计划 catalog 沿用调用方剩余额度与 deadline，不重建预算。

交叉锁测试还复现一处真实缺陷：`Cache::lock()->get(callback)` 争用时返回 false，原 `??` 没有接住，导致 int/array 返回类型错误。两领域入口改为返回原零结果/空 summary；不抢派发、不消耗预算，锁释放后恢复正常。

## B4-03：当前入口与历史记录分开

- 新增 `docs/README.md` 任务导航及历史索引，保留现行规范、运维、协议、活跃裁决和当前验收记录在原位置。
- 仅归档 9 份已完成的 W1–W3、B1–B2 实施/验收记录，原正文除相对链接和历史提示外保持。原路径保留短入口、原标题和章节锚点，已有链接可继续追溯。
- 总册中未收录分册改为明确存目文字并链接现有对应章节，修复两个真实断链；不补造新协议。
- 既有无关工作区文件保持，不归档或提交它们。本批不重写工程约定，也不以历史报告替代当前发布状态。

## 验证与交付

基线复用同一源码检查点的第三批最终门禁：1773 passed /14699 assertions、PHPStan 0、Pint PASS；开工前 Git 无其他代码改动。

1. 新通知路径覆盖对象/序列化仅 ID、无需解密派发、真实 database worker、重试/耗尽、uncertain 和租约；旧格式继续执行。
2. 手工/计划入口核对真实配额、独立游标、urgent 差异、资格、预算、异常/退避与共同排斥锁。
3. 隔离 MySQL/database worker 验证事件唯一、认领竞争、闭户竞争、结果落库失败后的 uncertain；独立 MySQL/Redis 检查实际维护派发及跨进程锁竞争。所有外部发送和供应商调用使用替身。
4. 检查归档正文/锚点保持及本地链接；运行完整 Pest、PHPStan、Pint 和语法检查。
5. 原预览只读核对 24 条迁移、持久数据及待处理任务保持、登录/健康正常；无迁移需要，不启用业务消费者。通过后按通知、维护、文档分开自动提交。

IMPLEMENTED: B4-01～03 全部完成，见 [实施与验收记录](architecture-fourth-batch-2026-11-19.md)。
NOT DONE: 其余审计业务依赖迁移、安装封印生命周期改造、生产消费者及真实业务启用不在本批范围。
DECISIONS: 保留旧任务兼容与两套不同的维护策略，归档不改变现行契约。
CONCERNS: 旧 Job/Action 因历史兼容仍保留；文档归档不代表审计数据库允许清空。
GATES: 最终 pest 1801 passed /14895 assertions | phpstan 0 errors | pint clean；隔离真实通知/领域派发/跨进程锁、文档链接和原预览只读核对 PASS。
SPEC CASES: 0（未改动）。
