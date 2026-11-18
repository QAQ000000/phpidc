# 第二批：钱包结清与一次性交付归档

日期：2026-11-18。对应方案 W2 / N01–N02，复用第一批已完成的三列钱包容量规则。本批实现客户退出所需业务闭环；付款容量异常的补记、旧上游资源结案及映射恢复仍属于 W3。

## 钱包与外付

- 本人凭当前密码、明确确认及稳定 ULID 申请 CNY 0.01–10000.00 钱包结清，手续费由商家承担；同一原用户最多一笔未结束申请。批准时重新核验余额和准入条件。
- 账单冻结与结清冻结分列。批准写 hold，真实外部付款凭证确认写 paid，执行前取消或确证未付款写 release；每次记录唯一账本引用和完整钱包快照。结果不明保留冻结及原执行标识，不能重开出款。
- 佣金外付与钱包结清在同一事务占用共享外付凭证。数据库全局唯一 `(payout_method,external_reference)`，并强制两个业务 FK 恰有一个非空；原 paid 凭证永不释放。历史已付登记不改账本，冲突必须人工核对；未回填窗口仍核对原已付事实。
- 新增独立 approve / execute / confirm / reconcile / details 权限。普通列表不查询/展示明文受款资料；独立受限入口必须给理由并写访问审计。新密文同时进入换钥；验证失败不把密码、账号或证据闪存回会话。

## 匿名身份来源

`reconciliation_cases` 与追加式 `reconciliation_entries` 保留原 callback 或受限 `refund:{invoice}` CREDIT_IN 来源。原用户 ID、原贷项和稳定事实指纹决定清退权，复用邮箱不建立任何继承关系。合法匿名钱包退款继续支持，不伪造 callback、不新增拒绝规则；普通活跃买家的退款不建立匿名案件。

匿名申请要求专门财务权限及加密受款权利核验依据。L 为原已确认贷项、P 为具有合法 hold/paid 流水和共享凭证的已付金额、H 为有效 reserved/processing/unknown 冻结；批准时重验 `P+H≤L` 以及新金额 `≤min(credit,L−P−H)`。只有 `P=L且H=0` 才追加真实结案证据。修改 case.status 本身不能解除阻断，坏流水/凭证使既有结案重新不成立。

旧 attention 回填前仍保留阻断，回填只建立来源投影；未入账的容量异常继续 open。已经可用的钱包可以结清以腾出容量，原异常案件不会造成清退自锁，但未入账款不能作为可用余额。零金额合法退款不制造清退义务；零迟到回调保留 ACK、原 callback/账本及可核验的零本金结论，不伪造出款。

JSON 指纹递归规范排序，避免 MySQL JSON 键重排使合法退款失败。后续 W3 可以引用同一来源进行原回调补记，不删原告警、不重发 HTTP 绕过去重。

## 一次性交付归档

`OneTimeDeliverable` 显式能力覆盖本地卡密、本地 Sub2API 和远程固定码的完整历史交付。本人以当前密码、确认和稳定请求键归档，核验原 Paid 账单、付款账本、成功 provision、sold 卡/当前凭据、远程原请求与加密结果，并排除持续费用和未决结果。

归档只保存非秘密事实快照及指纹，不改变 Active、sold、原交付或兑换权。注销每次重新核验当前证据，只豁免该服务的未终止项。注销前提示保存交付资料；所有其他资金、任务和业务阻断继续生效。

历史能力解析没有外部 I/O，不依赖上游账号仍启用或密钥仍可用，也不放宽现有 fulfillment 解析。指纹不包含卡密、密文或 code_hash；真实换钥后仍有效。

## Schema 与入口

| 新迁移 | 内容 |
| --- | --- |
| `2026_11_18_000009` | wallet_clearances、external_payout_receipts、reconciliation_cases、reconciliation_entries |
| `2026_11_18_000010` | instance_entitlement_archives |

客户入口：`/console/wallet/clearances`、`/console/instances/{instance}/archive`，从钱包和服务列表/详情均可到达；后台 `/admin/wallet-clearances` 已显式注册到 Filament，避免只有页面类却没有路由的 404。

历史回填命令：`app:backfill-payment-attention-cases`、`payments:backfill-payout-receipts --json`（外付支持 `--dry-run`）。它们登记真实历史证据，均不付款、不交付、不改历史账本。执行前先按部署边界备份，冲突非零退出后核对。

## 验收

完整 Pest **1220 passed / 9972 assertions**，PHPStan **0 errors**，Pint **passed**。旧测试、旧迁移及 Specs 没有修改。日志位于 `/tmp/laravel13idc-batch2-final-{pest,phpstan,pint}.log`。

独立交叉复核实际发现并修复 JSON 键序、零退款及零回调问题，三个原复现探针均通过。专项覆盖未知付款/坏 hold/paid/receipt、部分清退、真实匿名退款和迟到款来源、通知失败整笔回滚、越权/明文访问和真实换钥、归档后注销及同邮箱新用户隔离。

`python3 scripts/verify-wallet-receipts-mysql.py --preview-pid 1448041` 使用无网络/无宿主端口的一次性 MySQL 8.0.35，并复制只读预览库，私有证据为 `/tmp/laravel13idc-wallet-receipts-40r1syku/`：

- 两个独立 PHP 进程、四个独立参与者，跨佣金/钱包通过历史检查后同时 INSERT 同一凭证：只有钱包成功，registry 与 paid ledger 均为 1；败方仍 processing、原 10.00 冻结保留。裁决来自数据库唯一约束。
- 两个业务 FK 全空/全非空均被真实 MySQL CHECK 3819 拒绝；合法匿名退款经过 MySQL JSON 实际重排后仍可验证原来源指纹。
- MySQL 原 53 表/13 合成旧行和原预览副本 53 表/394 旧行逐列哈希均保留，14→16 迁移；完整性及 deployment-check 通过。原预览库、封印和 `.env` 哈希不变，容器已删除。

实际预览迁移与只读入口验收在提交后另记，不能用副本 SQLite 或页面 200 冒充真实付款、出款或供应商执行。

IMPLEMENTED: W2 钱包结清、匿名已入账来源清退、共享外付凭证、一次性交付归档及注销接入。
NOT DONE: W3 异常补记/旧资源结案/映射恢复、W4 性能与运行保障，按原方案继续分批实施。
DECISIONS: 遵守已授权 N01–N02/N05；保留合法匿名退款和零金额原业务语义；未入钱包款不直接外退。
CONCERNS: 未核实出款必须保持冻结；来源回填不代表实际结案；本批不执行真实外部财务或供应商动作。
GATES: pest 1220 passed / 9972 assertions | phpstan 0 errors | pint passed | MySQL concurrency/CHECK/JSON and incremental upgrade PASS | independent review PASS。
SPEC CASES: 0，未修改。
