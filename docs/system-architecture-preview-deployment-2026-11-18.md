# 第一批：原预览升级与管理员记录恢复

日期：2026-11-18（Asia/Shanghai）。执行版本：`3b11795e5df59c5551d760b3a340ad578d0d4c83`。用户已明确授权修复后的自动提交及必要迁移，本记录是本批代码提交之后的实际预览部署证据。

## 目标与维护边界

- 原预览：`http://127.0.0.1:59493`，PID `1448041`，工作目录 `/www/wwwroot/Laravel13idc/public`，实际可执行文件 `/www/server/php/84/bin/php`。
- 从运行进程确认原数据库为专用 SQLite `acceptance.sqlite`；没有把项目 `.env` 可能指向的其他数据库作为迁移目标。
- 检查本项目没有运行中的 queue worker 或 scheduler；原 jobs 表为 0，现有 fulfillment task 仅 1 条 success。没有启动或重放任何既有任务。
- 通过原运行环境执行维护模式，真实 `/login` 返回 503 后，制作同检查点备份，再清理本预览对应的配置、路由、事件及视图缓存。

同检查点备份包含 SQLite 一致性快照、原 `installed.lock`、原运行环境与 APP_KEY、项目 `.env`、storage/app 私有文件和已存在的运行缓存。备份清单有 SHA-256，位于 Web root 外的 `0700` 目录，文件 `0600`；密钥与凭据不进入仓库。

## 实际增量迁移

按明确路径执行 `migrate --force`，没有使用 `migrate:fresh`、重新安装或修改旧迁移：

| 迁移 | 内容 | 结果 |
| --- | --- | --- |
| `2026_11_18_000004` | topup 请求键 | PASS |
| `2026_11_18_000005` | 外部佣金出款及独立冻结 | PASS |
| `2026_11_18_000006` | 应收清偿及冲正 | PASS |
| `2026_11_18_000007` | 通知事件/投递 outbox | PASS |
| `2026_11_18_000008` | `wallet_clearance_frozen` 共享基础列 | PASS |

迁移记录由 9 条变为 14 条。创建管理员之前，对原 49 张业务表按原列排序、逐表比较行内容 SHA-256，原 385 行完全一致；旧余额、原冻结与旧审计没有改写。两项新增冻结列均为默认 `0.00`，4 张新增业务表为空。SQLite `quick_check=ok`、外键检查无错误，原实例凭据继续使用同一 APP_KEY 解密。

`000008` 只是 N05 容量校验和后续 N01 的基础，不表示完整钱包结清功能已经上线。本记录只证明上述 5 条迁移，后续工作包的新 schema 不由此推定已部署。

## 独立管理员与封印恢复

保留原封印执行部署检查，确认原遗留错误仍为“初始权限管理员不可用”。原封印没有 `admin_id`，原权限表没有 `access.manage`。

此次新增独立后台测试账号，ID `3`，仅授予既有 `access-manager` 固定角色；通过 `AssignAdminRoleAction` 产生角色授权审计，另追加初始管理员恢复审计，再使用 `InstallationState::seal` 和原 APP_KEY 将原封印补齐 `admin_id`。没有给它财务审批、退款或出款权限。

在授予角色前确认该角色没有被原账号使用，避免同步角色权限时使旧账号获得新权限。原 2 个账号的角色集合和有效权限在操作前后完全一致，原正常前台账号没有增权或重置密码。新账号随机密码仅保存在私有 `independent-admin.json`（`0600`），未打印、未写仓库。

恢复后，原预览实际运行环境的 `app:deployment-check` 通过；APP_KEY 与项目 `.env` 保持不变。检查结果是当前本地预览就绪，不等同生产 MySQL/Redis/TLS、`www` 身份或消费者健康验收。

## 真实登录及 HTTP 验收

使用两个独立 Playwright 浏览器会话，真实填写登录表单；没有使用模拟登录、重置原密码或给前台注入后台会话。

| 身份和入口 | 结果 |
| --- | --- |
| 已授权原前台账号：登录表单 → `/console` | 登录成功，客户总览 200 |
| 同一前台账号：`/console/affiliate` | 200，真实渲染“推广与佣金”；原缺表入口恢复 |
| 同一前台账号：`/admin` | 403，仍没有后台权限 |
| 独立新后台账号：Filament 登录 → `/admin/access` | 登录成功，账户角色管理 200 |

复用这两个真实浏览器的私有会话做 HTTP 核对，单次本地结果：客户总览约 0.544 秒、推广页约 0.422 秒、后台角色管理约 0.319 秒。这是单次功能验收记录，不是容量或 P95 性能结论。两个专用浏览器会话已经关闭，认证状态和日志仅留在私有证据目录。

维护模式已解除，原 PID 和端口保持运行。最终再次检查：迁移 14 条、jobs 0、SQLite 完整性正常、原账号权限不变；原 invoices、financial_transactions、payment_callbacks、payment_attempts、instances、redemption_codes、fulfillment_tasks、upstream_operations、system_settings 行内容均未变化。

没有真实付款、退款、外付、通知或供应商调用；没有执行 checkout、履约、续费、上游查询/变更或通用 scheduler。付款与交付链路的隔离验收仍见 [第一批隔离升级记录](system-architecture-batch1-upgrade-2026-11-18.md)，不把本轮页面 200 当作真实付款成功。

## 私有证据与恢复边界

证据目录：`/root/.local/state/laravel13idc/architecture-preview-upgrade-20261118-pfe49ci7/`。

| 文件 | 内容 |
| --- | --- |
| `backup/`、`backup/sha256.json` | 同检查点数据库、原封印、密钥/配置、私有文件及哈希 |
| `before.json`、`after-migration.json`、`verify-migration.log` | 原 49 表/385 行保留、14 迁移、余额默认值和密文检查 |
| `original-access.json`、`restore-admin.log` | 原账号角色/权限、独立管理员恢复与审计结果 |
| `before-admin-deployment-check.log`、`deployment-check.log` | 原封印失败与恢复后的真实部署检查 |
| `migration.log`、`deployment-result.json` | 实际迁移清单和执行版本、维护边界 |
| `customer-http.json`、`admin-http.json` | 真实浏览器登录后的 HTTP 状态、内容和单次耗时 |
| `final-readonly.log`、`final-result.json` | 最终原业务行/权限/队列/完整性、原 PID、维护解除、`.env` 保留 |
| `independent-admin.json` | 仅私有保存的新管理员随机凭据；不进入公开交付 |

若必须恢复，应先暂停本预览写入并同时恢复该检查点的数据库、原封印、私有文件、原密钥/环境及兼容代码；不能仅回退数据库而保留新管理员封印。发生新的真实账务后优先前向修复，恢复检查点前先核对其后的真实支付/出款/供应商事实，不能盲目 Git 回退、回滚迁移或重放队列。

IMPLEMENTED: 原预览 `000004`–`000008` 增量升级、独立管理员与合法封印恢复、真实前后台登录及推广页验收。
NOT DONE: 后续工作包 schema、通用消费者/调度启用、生产环境验收及真实付款/通知/供应商执行。
DECISIONS: 遵循本轮明确迁移授权；原账号不增权，管理员使用独立随机凭据；APP_KEY 不变。
CONCERNS: 本地预览检查不证明生产运行保障；后续新 schema 仍须按对应提交执行升级和验收。
GATES: 原预览 deployment-check PASS；14 migrations；385 原业务行保留；最终原资金/供应商事实不变；真实登录/HTTP 通过。
SPEC CASES: 0，未修改。
