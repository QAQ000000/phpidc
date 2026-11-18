# W3 原预览实际升级与只读入口验收

日期：2026-11-19（Asia/Shanghai）。部署提交：`a3f81cda643c56305d4ae98f2eb69e3b0d4c384e`。本记录仅覆盖 W3 的 `000011` / `000012` 和相应历史登记，不将同时开发中的 W4 schema 计入已部署版本。

## 维护、备份和迁移

从运行进程确认目标为原预览 `http://127.0.0.1:59493`，PID `1448041`，实际 PHP `/www/server/php/84/bin/php`，cwd `/www/wwwroot/Laravel13idc/public`；数据库和 storage 为原专用 `classic-live-20261117-5bbd1a71`。没有按项目 `.env` 猜测迁移目标。

核对本项目无运行中的 queue worker / scheduler、jobs=0。进入维护模式并验证 `/login` 返回 503 后，制作同检查点备份：一致性 SQLite backup、原安装封印、APP_KEY/进程环境、项目 `.env`、storage/app 私有文件和已存在的运行缓存。备份清单含 SHA-256，保存在 Web root 外私有目录，目录 0700、文件 0600。

清理原预览的配置、路由、事件与视图缓存，然后只执行：

```text
migrate --force
  --path=database/migrations/2026_11_18_000011_add_instance_mapping_reviews.php
  --path=database/migrations/2026_11_18_000012_add_binding_reconciliation_sources.php
```

| 迁移 | 实际结果 |
| --- | --- |
| `000011`：instance_mapping_reviews | PASS，新增表为空 |
| `000012`：绑定 source_snapshot、case 绑定 FK | PASS，未推断或补造历史端点 |

迁移由 16 条增至 18 条。历史登记前后分别核对原 58 张业务表按原列计算的逐表行内容 SHA-256：397 行全部一致。原 4 个身份的角色和有效权限不变，旧实例凭据使用原 APP_KEY 解密正常，SQLite quick_check 和外键检查通过。

| 历史登记命令 | 实际结果 |
| --- | --- |
| `app:backfill-payment-attention-cases` | callbacks=0，refunds=0，releases=0 |
| `app:backfill-binding-orphan-cases` | registered_or_verified=0 |

原库没有待回填的 kept_orphan 绑定。两个登记命令未产生资金、清退、映射变更或处置结果。W3 迁移与回填完成时，原环境真实 `app:deployment-check` 通过；封印、密钥与 `.env` 保持不变。维护解除后 `/login` 返回 200，原 PID 持续存活。

## 真实登录和只读 HTTP

原客户继续使用既有授权凭据，原 ID `4` finance-viewer 继续只有 finance.view。另创建独立映射验收账号 ID `5`，仅授予固定 upstream-mapping-reviewer，权限严格为 `upstream.view` 和 `upstream.mapping-review`。授权通过原生 AssignAdminRoleAction 追加审计，没有给原客户、access-manager 或 finance-viewer 增权，没有重置旧密码。

| 身份 / GET 入口 | 结果 |
| --- | --- |
| 原客户 `/console/instances/3/mapping-price` | 200，237 ms，no-store/private；当前没有价格确认提交表单 |
| 原客户 `/admin/upstream-instances/3/mapping-review` | 403 |
| 原 finance-viewer `/admin/reconciliations` | 200，376 ms；无补记或受限证据提交表单 |
| 原 finance-viewer `/admin/upstream-instances/3/mapping-review` | 403 |
| 新独立 reviewer `/admin/upstream-instances/3/mapping-review` | 200，247 ms，no-store/private；使用已有上游实例 #3 |

映射页面展示核准历史及检查表单，本次没有点击检查、提交映射、确认未来价格、调用供应商接口或创建测试实例。页面 200 证明已注册入口和权限边界，不证明某个历史服务已完成映射核准。后台案件列表当前为空，含案件的来源验证、证据脱敏和补记幂等以 W3 专项测试为依据。

三个专用浏览器已关闭。上述耗时是单次本地功能验收，不是容量或 P95 结论。

## 最终数据与待部署边界

直接比较同检查点备份和最终数据库，原 58 张业务表的 397 行均完整保留。只追加独立 reviewer 所需的 users、model_has_roles、audit_logs 各 1 行，roles 和 permissions 各 1 行、role_has_permissions 2 行；原身份的行内容、密码和有效权限保持。新增 mapping_reviews、reconciliation_cases / entries 仍为空，jobs=0，完整性与外键检查正常。

最终核对期间，主任务已落盘 W4 的以下两条未提交迁移：

- `2026_11_19_000013_add_receivable_evidence_revision_to_users`
- `2026_11_19_000014_add_notification_recipient_cursors`

这两条没有执行。当前工作区的完整 deployment-check 因它们返回“存在未执行的迁移”，记录为 `BLOCKED_PENDING_W4`；不能把 W3 完成时的 PASS 写成整个 W4 工作区已经部署就绪。W4 后续仍须完成自己的提交、备份、明确迁移和验收。

本轮没有执行原客户清退、归档、注销、原回调补记或原资源结案，没有真实付款、通知或供应商调用，没有启用任何 worker / scheduler。

## 私有证据与恢复

证据目录：`/root/.local/state/laravel13idc/architecture-preview-w3-20261119-ya63kccm/`。

| 文件 | 内容 |
| --- | --- |
| `backup/`、`backup/sha256.json` | 同检查点 DB、封印、环境/密钥、私有文件及哈希 |
| `prepare.json`、`before.json`、`after.json` | 精确版本/PID、原 58 表/397 行核对 |
| `migration.log`、`after-migration.log`、`after-backfill.log` | 仅 011/012、18 条迁移、当时部署检查 PASS |
| `attention-backfill.json`、`binding-backfill.json` | 两类历史登记实际结果 |
| `original-access.json`、`create-reviewer.log` | 旧身份权限、新独立 reviewer 最小权限 |
| `customer-http.json`、`finance-http.json`、`mapping-http.json` | 真实登录后的只读入口和权限结果 |
| `mapping-reviewer.json`、`*-fill-*.private.log` | 独立随机凭据与私有登录记录，不可公开 |
| `final-result.json`、`final-readonly.log`、`final-deployment-check.log` | 旧行保留、18 迁移、完整性与明确 W4 待部署边界 |

恢复时先暂停本预览写入，按同一检查点恢复数据库、封印、原密钥/环境、私有文件和兼容代码。若检查点之后已发生真实资金或供应商事实，须先核对这些事实，再决定前向修复或恢复，不能盲目回滚迁移、删除核对证据或重放旧任务。

IMPLEMENTED: 原预览 W3 两条增量迁移、两项历史登记、映射/核对新入口与权限只读验收。
NOT DONE: W4 两条迁移及运行机制部署；原客户实际补记/结案/映射确认；真实外部动作。
DECISIONS: 保留原账号与密码；授权页面使用独立最小角色；既有历史端点缺失保持未知。
CONCERNS: 当前完整工作区含未部署 W4 schema，deployment-check 明确阻断；GET 成功不代替供应商或真实账务验收。
GATES: W3 deployment-check PASS at migration | 18 migrations | 397 old rows preserved | existing access unchanged | GET/authorization PASS | W4 pending explicitly recorded。
SPEC CASES: 0，未修改。
