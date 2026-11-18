# 第四批：应收准入、维护调度和故障发现

日期：2026-11-19。基线：`ea12041`。依据用户批准的 N06 和“改完自动提交、需要迁移就迁移”的指示实施。本文记录代码和隔离验收；原预览升级另记。

## 应收资金链路

完整原始证据核验移至资金锁外。MySQL 使用同连接显式 REPEATABLE READ，批量读取 100 根应收、500 条清偿证据，保留原账单、佣金、清偿、冲正和唯一账本的逐项验证，单根超长历史也按批流式核验。所有合法证据写入者在原用户锁内增版；新资金事务只比较版本、算法和持久失效标志，不再长占资金锁扫描历史。

前台软预算 2 秒，超时转独立 receivable-check 队列，单次核验预算 90 秒、Job timeout 120 秒、tries 1、一个长核验 worker。Redis 结果仅用于原用户、原动作、原请求及原意图，TTL 600 秒；不能由后台任务自动执行资金动作。版本竞争回滚后重算，坏证据保持阻断；授权完整复核才可清除失效标志并增版审计。状态页、失败重试和原表单参数使长核验后可用同一请求续行。

提现、外付、钱包结清、匿名身份结清、注销、清偿和冲正均已接入。页面采用短预算与明确待核验状态，避免把检查未完成显示成无应收。交叉审发现并修复推广页三根以上应收可能 500、后台提现列表逐用户长扫，以及每次刷新重建表单键使大账户无法继续的问题；新增 3/20/21 根真实 HTTP 分页回归。

## 维护与通知

分钟调度投递独立 maintenance 队列；两个本地 worker 处理八类批次。旧 invoices:expire、fulfillment:dispatch-stale、cards:reap-reservations 调度入口保留，带 --maintenance 时实际只投递有界 Job，不带参数的手动命令保持原完整处理及异常语义。

每类使用最早项与 ID 轮转交错、独立游标、单项失败退避，最多 100 单位、5 秒软预算、2 秒行锁等待、25 秒 Job 超时和单次队列尝试。通知四子类共享总预算；接收者有持久用户/绑定游标和固定上界，部分提交后失败也保守计费。无运营接收者继续 awaiting；权限或绑定变化、闭户及中断不会静默截断或重复投递。

四类已观察执行故障在 operational_events 保留唯一事实：最终履约失败、恢复耗尽、上游回滚未确认、通知结果未知。正常状态与事实同事务；自己的源事务回滚后，仅这四类已观察故障可独立补记，财务拟议结果不能借此保留。通知构造和事实投影标记另一个事务原子完成；持久化/普通日志故障保留独立安全 PHP 结构日志和原始异常。未知通知不自动重发，告警自身发送未知时只保留抑制递归的事实。

app:operations-check 只读数据库、Redis、心跳、积压、无接收者、失败及未知记录，各类最多 20 个安全 ID。Python checker/watchdog 和两个 60 秒 timer 独立于业务 scheduler、worker 和 outbox；外部监控还须监测 checker 自身停止。独立五分钟应收审计只派发核验任务，完整坏证据的版本仍当前时才持久标记。

## 隔离实测

### 大账户与真实版本竞争

`scripts/verify-receivable-capacity-mysql.py`：network=none 的 MySQL 8.0.35、Redis 7 和私有 socket，10000 根、60000 清偿、40000 冲正及 100000 对应账本。数据库默认 READ COMMITTED，核验明确使用 REPEATABLE READ。

- 8 次完整扫描 56.79–66.78 秒，P95/P99 66.78 秒，每次 1304 SQL，最高 48.5 MiB。
- 实际原申请、审批、真实冲正/清偿共 8 次资金锁样本，最高 77.13 ms。样本少，不能当作生产延迟 SLA。
- reader 在真实用户锁前停于版本 1；独立 writer 通过真实冲正增版至 2。旧结果锁内拒绝，无提现；完整重算发现剩余 1.00，真实清偿增版至 3，同一原 ULID 重新核验并审批成功。
- 最终钱包 19.00、佣金 98.00、两笔唯一 payout，无重复。没有真实外付。
- 实际 FinanceReceivables 数据层 20 次、每页 20 行，P95 136.05 ms、P99 145.74 ms，共 220 SQL、最高 40.5 MiB；不包含 HTTP 与渲染。

私有结果：`/tmp/laravel13idc-receivable-capacity-ethw2doc/result.json`，容器已删除。

### 真实维护基础设施

`scripts/verify-maintenance-fairness-mysql.py` 使用真实 Redis 队列、两个独立 worker、真实 RunMaintenanceBatchJob/FairMaintenanceBatch 与 MySQL 行锁；八类各 160 条合成候选，consumer 不执行真实业务动作。实际业务候选接入由严格业务回归另证。

毒前缀首轮 2.855 秒，后续轮转 2.155 秒；旧 ID 后来到期 1.599 秒，单独丢失游标 1.537 秒。八类同时前 100 行真实锁忙，一轮 25.549 秒，各 Job 尝试 3 条、耗时 6.016–6.067 秒，符合软预算加当前行等待边界；不宣称 5 秒硬截止或两分钟清完锁忙积压。中断 worker 并丢失完全隔离 Redis 后重扫 2.586 秒，每个 Job ≤100 单位；八类均在 120 秒内获得处理轮次。25 秒硬超时未触发，原 240 秒 reservation 自动重投未实测。

私有结果：`/tmp/laravel13idc-maintenance-fairness-u80wq_v1/result.json`。一次性容器已删除，原预览 worker/timer 未启动。

### 迁移与恢复

`scripts/verify-architecture-recovery.py` 在隔离 MySQL 验证 18→21 增量迁移的原列、原行和默认值，再备份同一检查点的 DB、私有文件、APP_KEY 和安装封印。销毁源 MySQL/Redis 后，在全新实例恢复，验证全库清单、已交付卡密/权益、原购买/结清幂等、unknown 出款保留冻结、发送意图恢复为 uncertain 且不重发、运营事实唯一投影和缓存/部署检查。队列与邮件调用另有零调用断言。

最终结果：18→21 保留 59 张原业务表、37 条合成原行，新增版本/游标默认值符合预期，无推断业务事实；恢复检查点共 61 表、85 行、21 条迁移。私有证据：`/tmp/laravel13idc-architecture-recovery-zg64a0xo/result.json`。

这是单宿主、合成数据、停止写入检查点的逻辑演练；生产异机恢复及真实外部送达未验收。完整发布入口在 W5 更新。

## 门禁及部署边界

最终完整 Pest **1402 passed / 11415 assertions**；PHPStan **0 errors**，Pint **PASS**；watchdog Python **6 passed**，systemd 模板静态检查通过。日志：`/tmp/laravel13idc-w4-final-{pest,phpstan,pint}.log` 与 `/tmp/laravel13idc-w4-watchdog.log`。旧严格断言、旧迁移和 Specs 保持。原 Telegram 时间边界用例曾因生成 +31 秒签名后跨秒进入合法 +30 秒窗口而偶发失败；隔离探针已确定性重现。该用例仅增加 freezeTime 固定时钟，所有 dataset、预期结果及生产 [-300,+30] 秒规则保持；固定后该文件再次 **24 passed / 185 assertions**。新增用例覆盖资金版本、页面续行、通知扇出、故障事实、调度预算、独立审计和只读巡检。

本批仅新增 2026_11_19_000013–000015 三条迁移。原预览在备份后由部署脚本明确执行 18→21，保持原账户、权限、财务、历史密文和原进程；实际执行结果独立登记。生产值班人/监控接收方、TLS/FPM、业务消费者和真实外部通道仍须按部署手册验收。

IMPLEMENTED: W4 / N06、八类有界维护、通知游标、运营事实 outbox、独立巡检与恢复演练。
NOT DONE: 原预览迁移结果待独立部署记录；生产常驻进程、值班接收者及真实外部验收。
DECISIONS: 按已批准 N06/W4 实施；保持原资金规则、CLI 行为和真实外部执行边界。
CONCERNS: 软预算可被当前事务/行等待延长；容量样本不表示生产 SLA；通知持久化不等于送达。
GATES: pest 1402 passed / 11415 assertions；phpstan 0 errors；pint PASS；watchdog 6 passed；隔离 MySQL/Redis 容量、公平性、增量迁移与恢复 PASS。
SPEC CASES: 0。
