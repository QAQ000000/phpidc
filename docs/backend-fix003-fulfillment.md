# FIX-003：履约认领预算、补派发与租约恢复

日期：2026-11-18（Asia/Shanghai）。依据用户已批准的 [C21](backend-remediation-decisions.md#c21--fix-003履约认领计数与补派发)；对应 [执行清单](backend-review-remediation-2026-11-18.md) FIX-003。

## 交付与版本

代码及隔离验收完成。HEAD 基底仍为 `16b5e194de21ca8b942eb698a790fb7cfa2d1bd5`；未提交、未新增字段/表/枚举、未修改真实供应商状态或重置历史任务。

八个实施/测试文件的排序后逐文件 SHA-256 清单摘要为 `cb36157660b742f6c2f3b8d3cbaf4e713ec1f7c24da9ebe4d442902aa40dd8d7`。这是工作区快照，不是 Git 提交。文件为 ExecuteFulfillmentTaskAction、DispatchStalePendingTasksAction、FulfillmentClaimBudgetTest、FulfillmentRecoveryTest、ClassicExpansionTest、ZjmfPurchaseTest、Sub2ApiRemoteSalesTest 和 verify-fulfillment-recovery.py。

## 行为与规则

- `attempts` 在 worker 成功认领、写 running/worker/租约的同一个事务内加一；执行成功、失败、租约回收和未认领的重复消息均不再加。进程中断消耗已认领的次数（C21、INV-T2）。
- due pending 仅补派发，不修改 attempts、已到期 available_at 或 updated_at。过期 running 清空租约，按已认领次数退避 60/300/900 秒，第三次及以后保持九百秒；有效租约和未来任务保持原状。
- 最多五次认领，第五次仍可成功。失败结果或过期租约已耗尽预算时转 failed 并告警；永久失败提前停止。人工恢复保留权限、原因、审计和显式 reset=0，没有扩大自动重试权限（INV-T3）。
- 认领与回收先锁关联 invoice，再锁 instance/task，核对付款、账单/实例归属及当前绑定；非 Paid 的关联任务变 stale，不执行驱动。无关联账单的显式生命周期任务保留既有路径（INV-L1、INV-T5）。
- 可重试失败持久保存 pending/backoff，再 afterCommit 投递延迟 job；补派发采用六十秒短期缓存去重，缓存失效时仍由数据库认领排他。投递失败清除去重标记并向外抛出，已提交状态可被 sweeper 恢复；没有吞掉异常。缓存操作和队列投递都在事务外（F02、INV-L3）。
- 旧 worker 的返回仍须通过 worker ID/租约校验。经典购买仍走 P2 对账；续费/升级和 Sub2API 的持久意图不会被回收清除或当作新请求重发（INV-U2）。
- 保留 job timeout=180、lease=210、retry_after=240、框架 tries=3 及 backoff；队列重投不能绕过业务五次预算（INV-Q1/Q2）。

章程、INV-T2/T3、guardrails 与决策记录同步。既有测试只对 `reclaims a running task whose lease expired` 补充 attempts=1 夹具，回收后的严格 `toBe(1)` 原样保留；其他既有断言和 Specs 未变。另在相关上游测试中新增崩溃回收案例，未改写旧用例。

## 自动验收

新增 **31 项**：FulfillmentClaimBudgetTest **27 项 / 220 assertions**；经典购买崩溃恢复一项；续费/升级未决创建各一项；远程发码未决意图一项。验证了：

1. 调度先运行五次后仍可执行，次数一直为零，到真正认领才为一；丢失缓存或重复消息也只有一份交付。
2. 1–5 次认领、退避、第五次成功、第五次失败/崩溃、永久失败、未来任务、旧绑定及四种非 Paid 账单。
3. 嵌套事务提交前不派发，回滚不留派发标记，提交后队列失败仍保留 pending 并允许恢复。
4. 活跃租约不回收、崩溃租约过期后才恢复，旧结果不能交付凭据；经典购买从 P2 恢复，续费/升级/Sub2API 不重放未决创建或付款。

`python3 scripts/verify-fulfillment-recovery.py` 使用已有 mysql:8.0.35 镜像，新建无网络、tmpfs 数据、私有 socket 的独立容器，不拉镜像、不连接业务库。MySQL **83 项 / 817 assertions**及经典操作专项 **24 项 / 269 assertions**通过。另使用独立 PHP 进程与启动屏障验证：

- 双 worker 从 attempts=0 和 attempts=4 竞争，均只有一次认领、一次售卡、一次交付审计。
- 调度器与 worker 竞争，任务不被反复推迟；双调度器使用共享 file cache，仅一份补派发，任务仍可执行。
- 连续五轮双 worker 竞争失败，仅五次驱动执行、四份延迟重试，无第六次。
- 将脚本自己的 worker 子进程在已提交认领后以 SIGKILL 中断，验证首次崩溃可恢复、第五次崩溃停止；未向既有项目进程发送信号。
- 旧 worker 卡在驱动结果返回前，另一进程回收并成功交付后再释放旧结果，状态和凭据不被覆盖，仅一张 sold 卡和一条交付审计。

所有上述并发夹具的可用/冻结余额保持 **17.00 / 4.00**，无新增资金流水。PHP 异常渲染不能代表进程中断，最终中断验收以子进程退出信号 -9 和持久任务状态为依据。脚本仅清理自身容器和临时文件。

开工 Gate 为 **611 passed / 4940 assertions**；收尾 Gate 为 **642 passed / 5242 assertions | PHPStan 0 errors | Pint clean | Specs 0**，`git diff --check` 通过。日志为 `/tmp/backend-fix003-before-*.log`、`/tmp/backend-fix003-after-*.log`、`/tmp/backend-fix003-mysql8.log`。

## 已解释的失败与未完成边界

新增测试首次有两个准备问题：创建后未重读完整行就做全行比较、把正常交付通知误当作额外履约任务。已改为重读基准并严格分别验证零履约重试/一次交付通知；没有削弱既有断言。首次并发夹具使用普通 update 写受保护的钱包字段被模型忽略，已在独立测试夹具使用 forceFill，并持续断言资金不变。

首次扩大 MySQL 运行范围时，临时 storage 未创建 sessions 目录导致七项 HTTP 测试失败，补齐后相关 HTTP 测试通过；另一个旧目录测试 `syncs period and setup option prices separately without repricing existing services` 在 ClassicExpansionTest 的严格数组比较处失败：MySQL JSON 将 `price, setup_fee, enabled` 重排为 `price, enabled, setup_fee`。本项没有修改该旧断言或序列化规则，也不将它标为 MySQL 全套通过。探索日志保留于 `/tmp/backend-fix003-mysql8-exploratory.log`；最终 MySQL 范围为履约相关套件及明确的经典操作筛选，此目录键序兼容问题留待人工裁决。默认 SQLite 全套已通过。

旧 attempts 不能反推历史真实执行次数，本轮不自动清零。真实供应商故障、生产积压及生产 Redis 多节点行为未做实测；共享 file cache 的双进程验证不等于 Redis 集群验收。没有新增真实采购、续费、发码或机器命令。

原隔离预览 PID 1448041 保留；最后 `/up`、`/login` 均 HTTP 200。隔离库钱包仍 **0.99 CNY**、冻结 **0**，账单/流水/任务仍 **1/2/1**，原成功任务的历史 attempts=0 保持不变。项目未关闭或重启，生产历史任务未盘点。
