# W4 原预览实际升级与只读页面验收

日期：2026-11-19（Asia/Shanghai）。部署代码提交：`c63ed865870b611cdf812e010b47c35509c6faac`。本记录覆盖 W4 的 `000013` / `000014` / `000015` 实际迁移及既有身份的只读页面验收；未启用 worker、scheduler 或 systemd timer。

## 目标、维护和备份

从运行进程确认原预览为 `http://127.0.0.1:59493`，PID `1448041`，实际 PHP `/www/server/php/84/bin/php`，cwd `/www/wwwroot/Laravel13idc/public`。数据库为 `/root/.local/state/laravel13idc/classic-live-20261117-5bbd1a71/acceptance.sqlite`，storage 为同一专用目录。按实际进程环境执行，没有按项目 `.env` 猜测数据库目标。

迁移前检查项目没有运行中的 queue worker / scheduler，数据库 jobs=0。进入维护模式并验证 `/login` 返回 503 后，使用 SQLite backup API 生成一致性数据库备份，同一检查点保存原安装封印、项目 `.env`、含原 APP_KEY 的进程环境，并复制存在的 storage/app 目录与运行缓存。该预览实际备份清单为 4 个文件；私有目录 0700、文件 0600，SHA-256 清单最终再次验证通过。

迁移前现场只读验证：原预览 `CACHE_STORE=file`、`QUEUE_CONNECTION=database`；W4 maintenance 和 receivable-check 专用缓存为 redis，实际 Redis ping 及应收缓存 get 通过。没有修改原 `.env`、APP_KEY 或已有账号授权。

## 明确增量迁移与数据保持

清理原预览的配置、路由、事件和视图缓存，然后仅执行：

```text
migrate --force
  --path=database/migrations/2026_11_19_000013_add_receivable_evidence_revision_to_users.php
  --path=database/migrations/2026_11_19_000014_add_notification_recipient_cursors.php
  --path=database/migrations/2026_11_19_000015_add_operational_event_outbox.php
```

| 迁移 | 实际结果 |
| --- | --- |
| `000013`：用户应收 revision / reconciliation flag | PASS，既有用户 revision=0、flag=false |
| `000014`：通知收件者与绑定四个游标列 | PASS，cursor=0、upper_bound=null |
| `000015`：operational_events 持久事实表 | PASS，新表为空 |

迁移由 18 条增至 21 条，没有执行全库重建或历史资金回填。迁移完成后、解除维护前、11 页登录验收后共重复核对原 59 张业务表，按原列对全部行排序计算逐表 SHA-256：原 404 行全部一致。既有 5 个身份的角色、有效权限和密码行内容保持，旧实例凭据仍能使用原 APP_KEY 解密。

SQLite quick_check、外键检查及原环境真实 `app:deployment-check` 均通过。原封印、APP_KEY 和项目 `.env` 哈希保持；jobs=0，新增运营事实表为空，未创建应收 reconciliation flag。维护解除后 `/login` 返回 200，原 PID 与 `127.0.0.1:59493` 监听保持。

## 既有身份的真实登录和只读 HTTP

客户使用原授权凭据；后台使用既有 ID `4` finance-viewer，仍仅具 finance.view。没有新增账号、重置密码或追加角色。登录 POST 仅用于认证；下列业务页面均只执行 GET，没有点击注销、结清、提现、审核或核验重试。

| 身份 | GET 入口 | 状态 | 单次耗时 |
| --- | --- | --- | --- |
| 原客户 | `/console/affiliate` | 200 | 282.85 ms |
| 原客户 | `/console/account/close` | 200 | 289.05 ms |
| 原客户 | `/console/affiliate/external` | 200 | 257.17 ms |
| 原客户 | `/console/wallet` | 200 | 269.61 ms |
| 原客户 | `/console/wallet/clearances` | 200 | 277.72 ms |
| 原 finance-viewer | `/admin/finance` | 200 | 500.26 ms |
| 原 finance-viewer | `/admin/finance/receivables` | 200 | 455.04 ms |
| 原 finance-viewer | `/admin/finance/ledger` | 200 | 377.21 ms |
| 原 finance-viewer | `/admin/payouts` | 200 | 418.64 ms |
| 原 finance-viewer | `/admin/external-payouts` | 200 | 527.51 ms |
| 原 finance-viewer | `/admin/wallet-clearances` | 200 | 470.16 ms |

11 个页面均无重定向、无 404/500。这些是单次本地渲染验收，不能作为容量或 P95 结论，也没有执行原客户的财务状态转换。原预览没有通过合成账务构造应收历史；大账史、并发版本和原请求恢复依赖 W4 隔离容量与专项证据。

## 门禁、运行边界和证据

代码提交门禁为完整 Pest 1402 项 / 11415 个断言通过、PHPStan 0 errors、Pint 通过、独立 watchdog 6 项通过；隔离 MySQL/Redis 容量、公平性、增量迁移与恢复验证通过。维护公平性使用两个真实隔离 worker 和无业务副作用 consumer，8 类真实锁忙轮次 25.549 秒，每个 Job 尝试 3 项后结束；详见 `docs/maintenance-and-operations-2026-11-18.md`。

实际预览的代码和 21 条迁移已部署。维护及应收队列消费者、scheduler、只读巡检 timer、watchdog timer 与外部告警接收方均未在本轮启用。不能用页面成功或服务模板存在推断定时处理已在运行。

私有部署证据目录：`/root/.local/state/laravel13idc/architecture-preview-w4-20261119-pjgo3_zz/`。

| 文件 | 内容 |
| --- | --- |
| `backup/`、`backup/sha256.json` | 同检查点数据库、封印、环境/密钥及哈希清单 |
| `prepare.json`、`before.json`、`after.json` | 精确提交/PID、原 59 表 404 行逐旧列核对 |
| `environment-preflight.json` | 原缓存/队列配置与实际应收 Redis 只读访问 |
| `migration.log`、`after-migration.log`、`after-verification.log` | 仅 013/014/015、21 条迁移、部署检查通过 |
| `original-access.json`、`final-check.log` | 原 5 个身份权限及页面验收后数据、密码、旧密文复核 |
| `http-acceptance.json`、专用页面 HTML | 既有客户/finance-viewer 11 页登录后 GET 状态与耗时 |
| `deployment-result.json`、`final-result.json` | 备份校验、完整性、jobs=0、无项目 worker/scheduler、维护解除 |

进程环境与备份含原密钥，专用页面 HTML 可能含业务信息，均只保存在 Web root 外私有目录，不加入 Git。本轮没有真实付款、供应商调用、外部通知、原客户清退、归档或注销。

恢复时先暂停本预览写入，按同检查点恢复数据库、封印、原密钥/环境、私有文件及兼容代码。若检查点后已发生真实资金或供应商事实，须先核对事实再选择前向修复或恢复，不盲目回滚迁移或重放旧任务。
