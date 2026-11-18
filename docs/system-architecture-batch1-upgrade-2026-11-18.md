# 第一批：隔离升级与金额边界验收

日期：2026-11-18。代码基线：`542334858809881523309b83a9812d9636a348bb` 加本批工作区变更。本文对应修复方案 W0，并记录 W1/N05 的离线 MySQL 验收；不代表原预览或业务环境已升级。

## 范围与执行边界

原预览实际使用 SQLite，不能把它描述成 MySQL 业务库。本轮分别验证：

1. 原 SQLite 预览通过只读连接制作一致性备份，在私有副本应用 `000004`–`000008`。
2. 离线 MySQL 8.0.35 创建截至 `000003` 的旧 schema 和合成历史，演练同一组增量迁移、DDL 中断及检查点恢复。

`000004`–`000007` 是已经实现的 D01–D05 schema；本批 `2026_11_18_000008_add_wallet_clearance_frozen_to_users.php` 只增加 `users.wallet_clearance_frozen DECIMAL(12,2) UNSIGNED DEFAULT 0`，作为 N05 总容量计算和后续 N01 的共享基础。它不代表钱包清退申请、审批或真实出款已经实现。

原预览不运行 Artisan、fixture、Pest 或迁移，不停服务，不改变密钥、封印、原客户/管理员权限、支付与供应商设置。所有新装、授权、封印、付款回调模拟和本地交付都发生在私有测试环境；没有真实付款、通知或供应商请求。

## 可重跑脚本

新增 [verify-batch1-upgrade.py](../scripts/verify-batch1-upgrade.py)：

```sh
python3 scripts/verify-batch1-upgrade.py
# 同时验证指定原预览的只读备份；先确认该 PID 仍属于本项目。
python3 scripts/verify-batch1-upgrade.py --preview-pid 1448041
```

脚本固定使用 `/www/server/php/84/bin/php`，要求本地已有 `mysql:8.0.35` 镜像，不自动拉取镜像。MySQL 容器使用 `--network none`、私有 Unix socket、临时数据目录，不发布 TCP 端口；升级库和新装库各使用只能访问本库的临时账户。凭据和应用密钥运行时生成，不接受外部数据库连接参数，不携带部署凭据。

PHP 的数据库、storage、public、配置/路由/事件/服务/包缓存与视图编译目录全部指向私有目录；HTTP 客户端阻断意外外呼，邮件使用 fake，队列只写私有 jobs 表。脚本不启动通用通知/上游 worker；本地卡密 Action 只处理本轮明确创建的任务。脚本不使用 `migrate:fresh`，不改旧迁移。

证据目录在仓库外，根目录 `0700`，文件 `0600`。容器在成功或失败后清理，数据库检查点及含真实历史的 SQLite 副本仅留在私有证据目录，不进入 Git。

## 旧数据保留与升级结果

| 检查 | 离线 MySQL 合成旧库 | 原 SQLite 的私有副本 |
| --- | --- | --- |
| 升级前迁移 | 9 条，截至 `000003` | 9 条，截至 `000003` |
| 旧业务表和历史行 | 49 表、14 行 | 49 表、385 行 |
| 旧列及原有行内容 | 逐表按旧列投影、排序并计算 SHA-256；升级前后一致 | 同左；在新增测试账号之前完成校验 |
| 升级后迁移 | 14 条，包括本批 `000008` | 14 条，包括本批 `000008` |
| 新增表 | 应收清偿/冲正、通知事件/投递，共 4 表；无额外删表 | 同左 |
| 新增冻结列 | 全部旧用户默认 `0.00`，原余额和冻结不变 | 同左 |
| 关键唯一索引及完整性 | topup、通知接收者、应收凭证索引；全部表 `CHECK TABLE` | 对应索引、`quick_check`、外键检查 |
| 原密钥及历史密文 | 原封印有效，合成旧卡密可解密 | 原封印有效，现有实例凭据可解密；原库没有卡池密文可抽样 |
| 旧推广页 | 迁移前因缺应收表 500，迁移后 200 | 同左；认证上下文内经 HTTP kernel 渲染，无真实网络调用 |

这里只比较旧行的旧列，并单独验证新列默认值；没有把新增列导致整行不同误报为历史资金改写。迁移记录单独核对，不混入历史业务行哈希。

旧推广页的 200 只证明入口恢复。另在 MySQL 和 SQLite 副本新增隔离本地商品及普通测试用户，验证纯钱包建单 → Paid → 唯一任务/持久事件 → 本地卡密 sold/交付成功；同请求重放返回原单，历史账单冻结 `2.00` 保持不动。另验证零金额商品同时产生 Paid、任务和事件，未调用支付通道。没有仅凭 HTTP 200 宣称付款链路通过。

## MySQL 部分 DDL 失败与恢复

演练不修改迁移文件。先应用 `000004`，再在 `000005` 的第一个 `users` ALTER 已执行后，通过查询事件注入明确失败：

- `users.affiliate_frozen_balance` 已实际存在；`affiliate_withdrawals.payout_method` 尚不存在。
- `migrations` 没有 `000005` 成功记录，证明一条迁移不是整个 DDL 事务。
- 直接重跑被 `Duplicate column` 拒绝，没有伪造迁移成功记录。
- 仅将本脚本创建的 MySQL 测试库恢复至升级前一致性 SQL 检查点，保留相同测试密钥/封印，再按顺序执行 `000004`–`000008`，旧行保留及业务链重新通过。

这是已经演练的“恢复检查点后重演”路径，不是通用自动补 DDL 工具。实际环境遇到部分迁移失败时，应先暂停写入、核对真实 schema 与迁移记录，再选经审查的前向补全或同检查点恢复；不可复制脚本中仅针对临时库的删库步骤。没有将 SQLite 或 `migrate:fresh` 当作旧 MySQL 升级证据。

新装另用独立空 MySQL 库，通过原生安装 Action 完成全部 14 条迁移、独立权限管理员、密钥封印及部署检查。

## N05：真实 DECIMAL 边界及并发

以下均使用 MySQL `DECIMAL(12,2) UNSIGNED`，元数据另存证。回调先由 Epay 适配器校验本地合成签名，再调用真实回调 Action；没有连接支付网关。

| 场景 | 实际结果 |
| --- | --- |
| `credit=9999999999.99`、冻结为 0，普通账单 10.00 实收 10.01，重复两次 | 两次均 ACK；1 callback、1 容量审计、1 持久 attention；账单保持 Unpaid，余额不变，没有账本或履约任务 |
| `credit=9999999998.99`、账单冻结 1.00，支付消耗冻结后超付 0.50 | 按完整最终状态成功：`credit=9999999999.49`、冻结 0；Paid、任务和事件完整 |
| 历史 `credit=MAX`、账单冻结 1.00，解冻将使目标列溢出 | 原单保持 Unpaid、原余额/冻结不变，重复扫描只有一次待核对审计；后续普通到期单仍成功解冻并 Expired |
| 同用户只剩 0.01 容量，两个独立 PHP 进程同时处理不同迟到款 | 两个独立事务经同步屏障竞争；恰好一笔贷项和账本成功、另一笔保存容量 attention，最终余额恰好 MAX，没有 SQL 1264 |

并发证据包含各进程 PID、原生用户行锁等待耗时及回调结果；没有用顺序调用冒充并发。

## 原预览遗留就绪问题

复制原数据库与原封印后，升级已通过，但 `app:deployment-check` 返回：**初始权限管理员不可用，请从可信 CLI 核查。** 只读核对发现原封印没有 `admin_id`，原权限表没有 `access.manage`。该遗留状态保留为检查失败，不能将原预览就绪标为 PASS。

完成 385 行保留验证之后，仅在 SQLite 副本创建独立后台测试账号，通过原生 `AssignAdminRoleAction` 赋 `access-manager`，并使用原 `InstallationState::seal` 和同一 APP_KEY 更新副本封印；随后副本部署检查通过。副本普通测试账号与后台权限分开，原账号没有增权，原封印没有修改。原环境的合法管理员/安装记录核实和升级仍是后续明确的环境操作。

## 私有证据及限制

最终整轮证据：`/tmp/laravel13idc-batch1-upgrade-tlms855l/`。

| 文件 | 内容 |
| --- | --- |
| `result.json`、`cleanup.json` | 迁移文件哈希、MySQL 版本/隔离、原预览前后哈希/迁移/PID/登录页只读检查、容器清理 |
| `mysql-old-checkpoint.sql`、`mysql-*-ddl-*.log`、`mysql-blind-retry-rejected.log`、`mysql-forward-replay.log` | 私有合成检查点、DDL 注入、部分状态、盲重跑失败和恢复升级 |
| `mysql-upgrade/before.json`、`after.json`、`mysql-wallet-columns.json` | 逐表旧行清单、升级后清单与实际 DECIMAL 定义 |
| `mysql-business-chain.log`、`mysql-capacity*.log`、`mysql-fresh-install.log` | 本地交付链、金额边界/两进程并发、新装 |
| `preview-copy/before.json`、`after.json`、`original-seal-deployment-check.json` | 原预览副本的行保留和原封印检查失败 |
| `preview-copy-readiness-with-test-admin.log`、`preview-copy-business-chain.log`、`preview-page-*.log` | 副本独立管理员、部署检查、隔离本地交付和推广页 |

这是隔离功能、数据保留与失败恢复验收，没有证明生产 MySQL 容量、生产 Redis、TLS/FPM、`www` 实际写权限或 systemd 消费者健康。原预览保留旧 schema 和运行进程；登录页 200 仅证明服务仍存活。升级窗口仍须按方案停本项目写入进程、保存同检查点备份、执行迁移后以最终运行用户检查，并核对实际消费者；新账务/枚举落库后不可只回退 Git 或盲目回滚迁移。

IMPLEMENTED: W0 可重跑隔离升级、部分 DDL 恢复、历史保留、新装与本地付款交付链；W1/N05 的 MySQL 金额和并发验收。
NOT DONE: 原预览/业务库升级、原封印/权限修复、生产部署及真实收付/通知/供应商执行；N01 完整钱包结清。
DECISIONS: `000008` 仅为已批准 N05/N01 的冻结列基础；原 SQLite 与合成旧 MySQL 分别报告；不改旧迁移。
CONCERNS: 原预览缺合法初始权限管理员记录，不能宣称部署就绪；部分 MySQL DDL 失败不能盲重跑。
GATES: 本脚本隔离整轮及 Python/PHP 语法检查；完整 Pest/PHPStan/Pint 由主代理另行归档。
SPEC CASES: 0，未修改。
