# 架构清理第二批实施记录

> 历史实施与验收记录：本文保留原阶段事实和验收边界。当前能力、部署及未完成事项以 [发布状态](../release-status.md) 为准；返回 [文档入口](../README.md) 或 [历史索引](README.md)。

基线：`7aab103`。用户指示“可以开始第2批”，并批准 [B2-01 两列最小方案](../architecture-second-batch-plan-2026-11-19.md)。本批分别提交支付来源与零佣金改动；沿用验证后自动提交、备份后必要预览迁移授权。

## 实现

| 链路 | 改动与保留边界 |
| --- | --- |
| 支付关注 | 新增 `reconciliation_cases.source_snapshot`，保存原回调、账单、用户、原因、结果、金额和指纹。新案与原财务事务原子保存；核验、补记、结案和相应注销判断读取业务事实，不再读取 `audit_logs`。原审计、权限、原身份、唯一收款和处置证据保持。 |
| 支付历史转换 | `000016` 在加列后分批登记原关注，完整匹配的历史证明仅补空快照，不改已有案件时间戳、revision、状态和处置记录；缺证明的来源保持阻断，矛盾、失去原主体或遗漏令迁移失败。DDL 后可安全重跑。已有结案若来源证明缺失也继续阻断，不能仅凭已结案状态放行。 |
| 零佣金结转 | 新增 `invoices.affiliate_zero_accrual_at`，在原用户→账单锁内按原金额/推广快照核算，结果为零时与原审计同事务写入。重复执行、维护选择和注销改读该列，归档对应审计不会重新核算或错误阻断。原零佣金无佣金行、资金流水和通知，`line_items` 不变。正佣金和成熟流程保持。 |
| 零佣金历史转换 | `000017` 只从完整匹配的原系统审计、原账单及推广事实回填确实为零的完成时间，保持历史 `updated_at` 和其他原字段。缺字段、损坏或矛盾历史保持未完成，不执行正佣金、资金或通知。 |

两处旧测试只纠正已批准的夹具：当前业务状态改为持久案件；旧 audit-only 阻断另外通过真实旧 schema 升级验证。原严格阻断、资金、权限和幂等断言保留，Specs 和旧迁移未改。

## 验证

基线：Pest **1644 passed / 13915 assertions**，PHPStan 0 errors，Pint PASS。最初默认 PHP 128 MiB 无法承载完整套件，随后仅为测试进程使用 `-d memory_limit=512M`，未修改运行配置。

最终合并代码：Pest **1715 passed / 14374 assertions**，119.55 秒；新增 71 项。PHPStan **0 errors**，Pint **PASS**，18 个变更 PHP 文件语法检查及 `git diff --check` 通过。分支专项有重叠，不累计：

- 支付来源及升级：67 passed / 370 assertions；扩大支付/钱包回归 131 passed / 788 assertions。
- 零佣金业务/升级及原维护回归：76 passed / 508 assertions，其中新增 45 项。
- 注销/验密/新集成：42 passed / 314 assertions；一次性交付归档及已注销原身份清退：43 passed / 268 assertions。

专项验证移走对应审计后，合法原收款仍可补记、结案与幂等重放；缺失或篡改来源仍拒绝。升级包含 audit-only、缺字段、损坏、矛盾、已有结案补源、部分转换后失败重跑及零遗漏断言。零佣金覆盖舍入边界、游标丢失、审计/业务写故障回滚和其他佣金义务不被掩盖。

离线 MySQL **8.0.35** 使用一次性 `network=none`、无宿主端口容器，从 21 条旧迁移与合成历史开始，实际执行 21→23 增量迁移：完整及已有来源补齐，不完整来源保持空且未结；原行原列、旧处置记录保持；零完成受限回填；两条转换直接重跑幂等；移走对应审计后业务证明仍验证。无资金、交付或通知新增，容器已删除。该验收覆盖 MySQL 升级/JSON/转换，不是新增真实并发或真实外部通道验收。

另以只读 SQLite backup API 复制原预览，在副本执行同样 21→23 迁移，原表原列数据、quick_check 与外键检查通过，再升级原预览。

临时本机日志不进入版本库：

- `/tmp/laravel13idc-architecture-batch2-{baseline,final}-{pest,phpstan,pint}.log`
- `/tmp/laravel13idc-batch2-payment-{targeted,regression,phpstan,pint}.log`
- `/tmp/laravel13idc-batch2-zero-{targeted,phpstan}.log`
- `/tmp/laravel13idc-batch2-closure-{targeted,history}.log`
- MySQL 私有证据：`/tmp/laravel13idc-audit-state-mysql-z__lpi_9/`。

## 当前原预览升级

从实际 PID `1728214` 环境确认目标为 `http://127.0.0.1:59493`、PHP `/www/server/php/84/bin/php`、本项目 `public`、原 `classic-live-20261117-5bbd1a71` 专用 SQLite/storage，未按项目 `.env` 的 MySQL 配置猜测目标。精确检查没有本项目业务 worker/scheduler。

进入维护模式并验证 `/login` 503 后，一致备份数据库、原环境/密钥、安装封印、`.env`、私有文件与运行缓存，私有目录 0700、文件 0600。备份及 SHA-256 清单保存于：

`/root/.local/state/laravel13idc/architecture-batch2-preview-deploy-gjxr0h8m/`

仅执行 `000016`、`000017`，迁移数 **21→23**。对照维护检查点逐表核对 **57 张持久表、734 条原记录的原列值全部保持**；会话和临时缓存另行排除。原预览无历史支付关注或零佣金审计，因此来源/完成列保持空，无合成业务写入。原有 **1 条 jobs 记录保持，未启用消费者或执行它**。

SQLite quick_check、外键检查、备份哈希及真实 `app:deployment-check`（local）通过；项目 `.env`、原进程环境（含密钥）和安装封印哈希保持。解除维护后 `/up`、`/login`、`/admin/login` 全部 200，原服务监听保持。未执行真实收款、外付、注销、供应商变更或通知发送。

## 剩余边界

本批移除的是点名的支付关注和零佣金业务读取依赖。机器请求去重/未知结果核对、应收证据人工复核重放、换绑额外退款保护、安装恢复等仍有审计依赖；不允许由本批推导出整表审计可删除或归档。应收全历史扫描演进、外键/软删、通知透传层与重复维护入口继续独立设计。

IMPLEMENTED: B2-01 两列、支付来源与零佣金业务状态分离、历史转换、注销/维护消费端及原预览升级。
NOT DONE: 其余审计依赖和后续独立设计；未启用生产消费者或真实外部业务。
DECISIONS: 用户已批准两列最小方案与指定旧夹具调整；正常审计输出和原资金/身份/证据边界保持。
CONCERNS: 缺历史证明继续阻断；当前预览为 SQLite，离线 MySQL 升级不代表生产并发或外部通道已验收。
GATES: pest 1715 passed / 14374 assertions | phpstan 0 errors | pint clean
SPEC CASES: 0（未改动）
