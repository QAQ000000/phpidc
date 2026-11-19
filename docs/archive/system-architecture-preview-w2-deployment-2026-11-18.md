# W2 原预览实际升级与只读入口验收

> 历史实施与验收记录：本文保留原阶段事实和验收边界。当前能力、部署及未完成事项以 [发布状态](../release-status.md) 为准；返回 [文档入口](../README.md) 或 [历史索引](README.md)。

日期：2026-11-18（Asia/Shanghai）。执行代码提交：`0886726`。本记录是 W2 代码提交之后的原预览实际部署；离线 MySQL 并发、数据库约束及副本升级证据见 [第二批实施记录](system-architecture-batch2-2026-11-18.md)。

## 目标、备份与维护

- 原预览：`http://127.0.0.1:59493`，PID `1448041`，cwd `/www/wwwroot/Laravel13idc/public`，实际 PHP `/www/server/php/84/bin/php`。
- 从该进程的环境确认目标为专用 SQLite `classic-live-20261117-5bbd1a71/acceptance.sqlite` 及同目录 storage；未使用项目 `.env` 推测数据库目标。
- 核对本项目无运行中的队列消费者或 scheduler，jobs 为 0；没有启动任何消费者或调度。
- `artisan down` 后真实 `/login` 返回 503，再制作同检查点备份：一致性 SQLite backup、原安装封印、原运行环境与 APP_KEY、项目 `.env`、storage/app 私有文件及已存在的运行缓存。备份有 SHA-256 清单。
- 全部证据放在 Web root 外的 `0700` 私有目录，普通文件 `0600`；密钥、密码、浏览器状态没有写入 Git。

## 实际迁移与回填

清理本预览对应的配置、路由、事件与视图缓存，使用原运行环境执行明确路径：

```text
migrate --force
  --path=database/migrations/2026_11_18_000009_add_wallet_clearances_and_payment_cases.php
  --path=database/migrations/2026_11_18_000010_add_instance_entitlement_archives.php
```

两条迁移均成功，记录由 14 条增至 16 条。迁移和回填完成、创建只读验收账号之前，原 53 张业务表的 394 行按原列逐表比较 SHA-256 完全相同。历史实例凭据继续使用原 APP_KEY 解密，SQLite `quick_check=ok`、外键检查无错误，原 3 个账号的角色和有效权限未变。

| 历史登记命令 | 实际结果 |
| --- | --- |
| `app:backfill-payment-attention-cases` | callbacks=0，refunds=0 |
| `payments:backfill-payout-receipts --dry-run --json` | 无待登记项，无冲突 |
| `payments:backfill-payout-receipts --json` | scanned=0，registered=0，conflicts=[] |

回填未改变历史账本或资金。原环境 `app:deployment-check` 通过，原安装封印、APP_KEY 和项目 `.env` 保持不变；维护已解除，原 `/login` 返回 200。

## 只读页面与权限验收

复用原前台已授权凭据，真实填写原登录表单，没有重置密码。后台使用新增独立账号 ID `4`，仅授予固定 `finance-viewer`，有效权限严格为 `finance.view`。该角色原有权限与代码固定集合一致，授予前后没有改变旧账号的有效权限。此前恢复的 ID `3` 仍为 access-manager，没有添加财务权限。

| 身份和入口 | 实际结果 |
| --- | --- |
| 原客户 `/console/wallet/clearances` | 200，280 ms，`Cache-Control: no-store, private`；申请表正常展示，未提交 |
| 原客户 `/console/instances` | 200，249 ms，仍展示本人的 3 项历史服务 |
| 原客户 `/console/instances/2/archive` | 200，显示“暂不能归档”，没有归档提交表单 |
| 原客户 `/console/instances/3/archive` | 200，显示“暂不能归档”，没有归档提交表单 |
| 独立 finance-viewer 真实 Filament 登录 → `/admin/wallet-clearances` | 200，447 ms，页面已实际注册；无批准按钮、受款输入或查看收款资料操作 |

原历史 API KEY 和按月云服务目前不满足完整付款、一次性交付及无未决业务的归档条件，页面提供核对说明。本次没有为了演示而补造原交付证据、改变历史配置或放宽准入。

后台清退列表当前为空；此处证明真实入口和只读权限，含记录的脱敏、越权及受限资料审计由专项测试覆盖。上述耗时是单次本地功能核对，不是容量或 P95 结论。两个专用浏览器已关闭。

## 最终只读核对

最终直接对比备份和当前库：原 53 张业务表的 394 行全部保留；只追加了已授权只读验收账号对应的 `users`、`model_has_roles`、`audit_logs` 各 1 行。旧账号行、角色和有效权限完全不变。

新 wallet_clearances、external_payout_receipts、reconciliation_cases、reconciliation_entries、instance_entitlement_archives 均仍为空，jobs 为 0，原账单、账本、实例、卡密、支付、供应商及设置数据未变。最终 deployment-check、SQLite 完整性与外键检查通过；原 PID 存活、维护已解除、封印/密钥/`.env` 保持。

没有提交钱包结清、归档或注销，没有真实付款、退款、出款、通知或供应商调用。

私有证据目录：`/root/.local/state/laravel13idc/architecture-preview-w2-20261118-uw26hin5/`。

| 文件 | 用途 |
| --- | --- |
| `backup/`、`backup/sha256.json` | 同检查点备份及哈希 |
| `prepare.json`、`runtime-environment.json` | 精确版本/PID/原环境；后者含密钥，仅私有保存 |
| `before.json`、`after.json`、`migration.log`、`after.log` | 14→16 迁移与旧表逐列/行哈希保留 |
| `attention-backfill.json`、`receipt-dry-run.json`、`receipt-backfill.json` | 两类历史登记和零冲突 |
| `original-access.json`、`final-readonly.log` | 原 3 个身份权限保持，独立 viewer 权限核对 |
| `customer-http.log`、`archive-http.log`、`finance-http.log` | 真实登录后的新页面状态、说明和只读操作边界 |
| `finance-viewer.json`、`*-fill-*.private.log` | 独立随机凭据及私有登录记录，不可公开 |
| `deployment-result.json`、`final-result.json` | 维护解除、16 迁移、旧行保留、完整性及外部副作用为零 |

若需要恢复，应先暂停本预览写入，将数据库、封印、原密钥/环境、私有文件和兼容代码作为同一检查点恢复。产生新的真实资金或外部执行事实后，应先核对检查点之后的业务，再决定前向修复或恢复，不能盲目回滚迁移或重放任务。

IMPLEMENTED: 原预览 W2 两条增量迁移、历史登记命令和新增入口只读验收。
NOT DONE: 原客户实际清退/归档/注销、真实外部付款/通知/供应商调用、生产消费者/调度验收。
DECISIONS: 遵循既有自动提交及必要迁移授权；原客户和 access-manager 不增权，后台验收新增独立 finance-viewer。
CONCERNS: 历史服务证据不足保持不可归档；空后台列表不代替含记录脱敏测试；本地预览不等同生产验收。
GATES: 16 migrations | 394 old rows preserved | existing access unchanged | deployment-check PASS | integrity PASS | new entry HTTP PASS。
SPEC CASES: 0，未修改。
