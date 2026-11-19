# 安装、升级、恢复与密钥操作

本手册对应仓库 `VERSION` 的候选代码；实际发布状态以 [release-status.md](release-status.md) 为准。生产安装还须完成文末验收；隔离脚本成功不等于已上线。当前原预览已完成架构清理 B4，仍为 B3 的 `000018`、共 24 条迁移；B4 无 schema 变更，业务 worker/scheduler 与真实告警未启用，见 [最新实施记录](architecture-fourth-batch-2026-11-19.md)及 [B3 升级记录](architecture-third-batch-2026-11-19.md)。[W4 升级记录](system-architecture-preview-w4-deployment-2026-11-19.md)保留阶段证据。

## 1. 环境与发布目录

- PHP 8.3+，CLI/FPM 使用同一套版本及扩展；以 `composer check-platform-reqs --no-dev` 为准。运行时另检查 bcmath、curl、DOM、fileinfo、intl、mbstring、OpenSSL、PDO、XML 等。生产需要 pdo_mysql、phpredis；队列 CLI 需要 pcntl。
- **MySQL 8.0+**，InnoDB、utf8mb4、严格模式。MySQL 5.7 和 MariaDB 未获替代批准，安装器拒绝。SQLite 只用于 `local/testing` 隔离验收。
- Redis 用于生产缓存、会话、队列与调度锁。为本应用配置独立前缀、访问凭据，生产按可靠队列要求配置持久化、内存和淘汰策略；不要把有淘汰策略的缓存实例直接当唯一任务存储。
- Nginx root **只能是 `public/`**，启用 HTTPS。`deploy/nginx.conf.example` 是待替换域名、证书、FPM socket 的模板，不自动覆盖现有站点。
- Web/worker/scheduler 使用同一非 root 账户（模板为 `www`）。仅 `storage/`、`bootstrap/cache/` 可写；`.env` 用 0640、安装状态文件用 0600。令牌命令也应由该运行账户执行，避免 root 创建的 0600 文件令 Web 无法读取。
- `public/js/classic-config.js` 是应用源码，须随版本部署；Filament 资源由发布命令生成，不纳入源码。

开发/CI 在隔离数据库中运行三道 Gate；生产依赖固定使用 `composer.lock`：

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
php artisan package:discover --ansi
```

不得在业务环境运行 Pest、`migrate:fresh`、测试 fixture 或历史 live 验收脚本。所有 PHP 命令使用与 FPM 匹配的实际二进制。

## 2. 首次安装

1. 创建**专用空库**及仅访问该库的数据库账户；安装期间需要建表/索引权限。
2. 在服务器将 `.env.example` 复制为 `.env`，配置 APP_URL、DB、Redis 等 bootstrap 参数。设置 `APP_ENV=production`、`APP_DEBUG=false`、HTTPS APP_URL、`SESSION_SECURE_COOKIE=true`、Redis 的 queue/cache/session。供应商凭据以后通过已有配置命令/后台写入加密设置，不放 `.env`。
3. 确认 storage 的 `framework/views`、`framework/sessions`、`framework/cache/data`、`logs` 存在且运行账户可写。
4. 由运行账户执行 `php artisan key:generate`，将 `.env` 和密钥安全备份。**已有加密数据时禁止重跑 key:generate。** 执行 `php artisan filament:assets`，确保安装成功后第一次后台登录已有所需资源。
5. 执行 `php artisan config:clear`，首次安装完成前不要建立配置缓存。执行 `php artisan app:install-token`，将终端中一次显示的令牌填入 HTTPS `/install`；有效期一小时，新令牌替代旧令牌。不要把令牌写在 URL、工单或版本库中。
6. 填写管理员名称、邮箱、至少 12 位且含大小写/数字/符号的密码、确认密码及安装原因。数据库连接仍由服务器 `.env` 管理，Web 不接收/改写连接凭据。
7. 安装器串行执行：验证令牌 → 环境/连接检查 → 空库检查及本机进度记录 → 已批准迁移 → 一个事务内创建管理员、赋 `access-manager`、记录审计 → 原子发布带 APP_KEY 校验的 `storage/installed.lock`。最后删除令牌和进度文件。成功后 `/install` 的 GET/POST 均为 404。
8. 执行后续资源/缓存命令，登录 `/admin/login`。初始管理员仅有权限管理能力，没有默认退款/提现权限。可注册独立运营账号，再在权限页授予固定业务角色；Web 不允许自授权，也不能授予 `access-manager`。额外权限管理员只能从可信 CLI 使用 `admin:role` 命令初始化并提供原因。

首次安装前 `/install` 使用文件会话，不依赖数据库或 Redis；`/up` 是存活检查，不证明业务就绪。空 APP_KEY 仍须通过服务器命令生成后才能使用加密会话。

### 安装失败与重试

- 未安装成功不会生成安装锁或伪报成功。无令牌、错误/过期令牌在连接数据库前拒绝。错误响应/旧输入不回显令牌和密码。
- 进度文件绑定原数据库配置、APP_KEY、管理员邮箱。重试可生成新令牌，但必须保留这些条件和原进度文件。管理员事务已提交而锁文件写入失败时，重试核对本次安装审计，**不会重设密码或重复建号**。
- **MySQL DDL 不具备整个安装的事务原子性**。Laravel 只跳过已记账的完整迁移；单个迁移中途失败、留下部分表/字段时，不能承诺直接重试成功。保留进度与日志，人工确认仅为这次安装的空库后，恢复安装前空库/备份，或审查修复 DDL；禁止安装器自动删表或接管已有库。
- 多节点首次安装只允许一个入口，并共享同一个 storage 状态目录；`flock` 不是跨独立磁盘的分布式安装锁。迁移成功前不启用 worker/定时器。
- 非结构化锁仅保留本地开发/历史测试兼容；生产启动和所有环境的 `app:deployment-check` 均拒绝未密封锁。安装器不自动接管已有未密封业务站点；不要手写一个空锁绕过安装。

## 3. 资源、缓存和就绪检查

首次安装后/每次升级，在运行账户下执行：

```sh
php artisan filament:assets
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan app:deployment-check --production
```

检查命令只读业务数据：验证密钥封印、扩展/目录、生产配置、MySQL 版本、连接、Redis ping、队列 after_commit/retry_after、迁移状态、初始权限管理员、发布资源。它不证明守护进程存活、远程供应商可用、邮件送达或备份可恢复。

若仅在 local SQLite 跑 `app:deployment-check`，输出明确写 `local validation only`；附加 `--production` 必须失败，不能当成生产验收。

## 4. worker 与调度器

审查 `deploy/` 模板中的账户、工作目录、PHP 路径、Redis 连接及队列后，由服务器运维在完成迁移和运行前提后安装。当前预览没有启用业务 worker/scheduler 或真实告警；模板存在不表示服务在运行。

| 进程 | 模板/实例 | 队列、预算与职责 |
| --- | --- | --- |
| 常规履约/发送 | `cloudcms-worker` | Redis/default；timeout 180、停止余量 210 秒 |
| 生命周期与目录 | `cloudcms-lifecycle@lifecycle-urgent`、`@lifecycle`、`cloudcms-catalog` | 各只消费同名队列，供应商 I/O 与本地维护隔离 |
| 本地维护 | `cloudcms-maintenance@1`、`@2` | 仅 maintenance，两个 worker；Job timeout 25、停止余量 35 秒；共享每类批次 100 单位/5 秒软预算 |
| 应收长核验 | `cloudcms-receivable-check` | 仅 receivable-check，**一个 worker**；前台 2 秒，长任务 90 秒软预算、timeout 120、停止余量 150 秒 |
| 独立只读巡检 | `cloudcms-operations-check.service/.timer` | 每 60 秒直接运行只读检查；子进程硬限制 10 秒，systemd 外层 15 秒 |
| 独立监控检查进程 | `cloudcms-operations-watchdog.service/.timer` | 每 60 秒检查心跳，90 秒陈旧即异常；不依赖应用 scheduler、worker 或 outbox |
| 调度器 | `cloudcms-schedule.service/.timer` | 每分钟只派发本地维护任务；catalog/应收巡检保留各自五分钟周期 |

B4 当前通知统一派发 `SendNotificationDeliveryJob(deliveryId)`，队列只携带投递 ID；密文快照在原持久 outbox 中，由执行 Action 认领后读取。新代码上线前先更新所有发送 worker，再恢复派发，避免旧进程无法识别新 Job。旧 `SendNotificationJob`、`SendEventNotificationJob`、兼容 Action 与序列化过滤继续保留，已有队列不需转换或清空。当前通知 Job timeout=60、数据库租约=120 秒，框架最多 3 次尝试、原退避和 uncertain 不自动重发不变。

生命周期和目录的手工、计划派发分别由 `DispatchInstanceLifecycleAction`、`DispatchClassicCatalogAction` 管理；维护总入口只委派领域 Action。两入口共享原领域锁，竞争时返回零派发，不报返回类型错误；锁释放后可恢复。游标、队列、绑定指纹与错误处理保持原策略，具体配额见下文和 [B4 记录](architecture-fourth-batch-2026-11-19.md)。

生产 Redis `retry_after=240` 继续大于所有 Job timeout。maintenance 八种 kind 各有公平轮转、退避和心跳；通知展开/租约回收/派发/运营事实转通知共用一个批次预算，单用户的大量绑定也通过持久游标恢复。应收检查完成结果从原扫描开始最多保留 600 秒，并绑定原 user/action/稳定请求键/参数 HMAC/版本/算法/证据摘要；命中不续期，旧缺扫描起点 ready 重新核验。B3-01 允许普通无捕获根/无复核权限的准入跨请求复用加密完整证明；清偿/冲正/明细、人工复核和独立审计保持完整核验。合法 revision/算法/密钥/标志变化使旧证明失效；绕过 Action 且不提升版本的 SQL 篡改存在用户已批准的有效期检测窗口。缓存丢失只重新核验，不以缓存当账本。10000 根/100000 证据的完整扫描、复用、版本竞争与原请求恢复的测量范围见 [第三批记录](architecture-third-batch-2026-11-19.md)，不是生产 SLA。

独立监控将结构化结果写 journal 和 `/run/cloudcms-operations/check.json`，**还须把两个检查服务的故障、自身缺失及陈旧心跳接入实际外部监控接收者**。`finance.view` 活跃账号只表示有可选运营身份，不证明地址可达或有人值班。未配置真实责任人与送达出口时，不能宣称运营告警已上线。阈值、恢复和演练见 [维护与巡检说明](maintenance-and-operations-2026-11-18.md)。

- worker：Redis/default，job timeout=180，`REDIS_QUEUE_RETRY_AFTER=240`，停止宽限=210。HTTP timeout=30 < 180 < 240（INV-Q1）；不要改成相等。
- FIX-011新增队列必须随代码部署：两个独立实例 `cloudcms-lifecycle@lifecycle-urgent`、`cloudcms-lifecycle@lifecycle`，以及一个 `cloudcms-catalog`；分别只消费对应队列，default另设。所有worker/cache必须共享同一Redis；不要用sync连接运行派发器。
- 计划维护的 lifecycle 默认每分钟临近到期≤50、轮转≤50，共享总100单位/5秒预算；手工入口各≤100、去重合计≤200。catalog 计划每5分钟≤100，手工按配置≤200；两类入口保留独立游标。job timeout=180、共享实例锁210、retry_after=240，unique排队TTL3600。1000实例/临期≤100/平均任务≤3s的容量假设下，目标临期≤10分钟、全量≤75分钟，尚未生产验收。持续上游错误或积压不在该时效内，及时人工核对失败记录。
- 监测urgent最老任务等待>5分钟、rotation>30分钟、应同步实例last_synced_at>75分钟或长期为空，并核对worker健康/失败队列；这些为生命周期容量巡检门槛；独立巡检已实现，但当前预览尚未启用实际服务及真实监控出口。不得简单增加worker数量突破已评估的上游并发。
- scheduler：每分钟 `schedule:run`，模板TimeoutStartSec=55，供应商HTTP已移至独立worker。若选择 cron，运行账户同上，使用绝对 PHP/项目路径，**与 systemd timer 二选一**。业务调度已有 withoutOverlapping/onOneServer，所有节点必须共享 Redis 锁。
- 部署验收检查 `systemctl status`、`journalctl -u`、`list-timers`、`php artisan schedule:list`、失败任务和 fulfillment_tasks；不能只看 service 文件存在。
- 新代码发布后重启 FPM 和 worker 以清除长生命周期旧设置；`queue:restart` 要在同一 Redis/cache 配置下执行。首次启动前确认已安装和 migrations 完成。
- 不运行 `queue:retry all` 来代替核对上游成本/交付状态。付款后缺货仍是人工确认、补库存、显式重试；不自动退款或重新购买。

## 5. 升级与回退

1. 在隔离环境验收候选提交、完整 Pest/PHPStan/Pint、升级/并发/容量/恢复脚本；记录 Git commit、VERSION、composer.lock 校验值、迁移状态和部署文件清单。历史脚本只按其明确目标使用，不在业务库运行。
2. 明确目标库及所有写入节点；为支付回调设置维护期间的接收/重试策略。进入维护模式，先停本项目 timer 并等待 scheduler 退出，再按各服务停止宽限排空/停止精确归属本项目的 worker、长核验与其他写入者；不要停止共享 MySQL/Redis。已有上游、付款或出款 unknown 保留证据、人工核对。
3. 先备份，再部署完整的同一版本代码/依赖。保留 `.env`、APP_KEY、storage、私有下载和原安装锁。
4. 明确本次 schema 后在原库执行批准的增量迁移。D01–D05 是 `2026_11_18_000004`–`000007`；W1–W3 继续至 `000012`；W4 新增 `2026_11_19_000013_add_receivable_evidence_revision_to_users`、`000014_add_notification_recipient_cursors`、`000015_add_operational_event_outbox`，不要重写旧迁移。B2-01 的 `000016`–`000017` 在停止旧写入后登记历史来源及零佣金事实；B3 的 `000018` 仅将两条历史留存外键改为 RESTRICT，SQLite 需要独立迁移事务，保留原行和自增序列。按批次说明运行受限历史登记命令，再发布资源/缓存，由实际运行账户执行 `app:deployment-check --production`。**升级不重新运行安装向导**。
5. 核实角色、实际通知接收者、外部监控和值班责任；重启 FPM，先启动 worker 与独立巡检，再启用 scheduler。检查进程/监听/日志/心跳/队列，完成管理员与客户入口验收后开放流量。实际支付、出款、供应商变更及真实发送须分别明确测试商品、动作和额度。当前预览只读页面通过不能代替这些验收。
6. 失败时保持维护模式并优先向前修复。已有新账本方向或真实业务事实后，不能只回退 Git 或盲跑 migrate:rollback；旧枚举可能无法读取新账本。确需恢复时先列出检查点后的支付回调、出款、清偿和供应商动作，再恢复同检查点 DB/文件/密钥并逐项对账，不以旧备份抹掉已发生外部结果。

### 架构清理 B2-01 的来源切换

`2026_11_19_000016_add_payment_attention_source_snapshots` 增加支付关注来源快照，并在同一迁移内登记历史 audit-only 关注、检查零遗漏；`000017_add_zero_affiliate_accrual_completion` 增加零佣金完成时间并受限回填。**先停稳全部旧写入者，再迁移，迁移成功后才开放访问与启动新 worker。** 缺证明的旧案仍未结；矛盾或遗漏会令迁移失败，保持维护并核对，不能以删审计绕过。两条迁移允许 MySQL 已落 DDL 后重跑数据阶段；已有来源/完成时间不覆盖。来源补齐不改变旧案版本或结案处置序列，零佣金回填不执行正佣金、资金或通知。

正常审计仍必须保留。本批只移除支付关注、零佣金结转及相应注销/维护判断对审计的依赖；机器操作等其他业务仍依赖审计，不允许整表清理。具体范围见 [B2-01 决议](architecture-second-batch-plan-2026-11-19.md)。

## 6. 备份与恢复

- 备份范围：MySQL 一致性快照、`.env`/APP_KEY、`storage/installed.lock`、`storage/app/`（含私有下载）、对应代码/lock 文件/版本；若存在安装或换钥进度，也须保留。备份目录在 Web root 之外，用 0700/0600，另做异机加密备份。
- MySQL 可用受保护的 `--defaults-extra-file` 提供凭据，`mysqldump --single-transaction --routines --triggers --events --hex-blob` 创建快照；不要将密码放命令行，也不要在本仓库存 SQL dump。
- 恢复前停止入口、worker 和调度器；先在独立实例恢复验证。DB、私有文件与 APP_KEY 必须来自同一检查点。恢复后重建缓存和资源、运行就绪检查并验证历史卡密可解密、财务金额及幂等键完整。
- Redis 队列、会话与锁有独立恢复问题。不要恢复旧锁、对共享 Redis 执行 FLUSHDB，或执行 `queue:retry all`。`notification_events` / `notification_deliveries` 和 `operational_events` 已是持久 outbox，随业务数据库恢复；Redis 消息丢失后从持久待处理事实重新派发，通知接收者展开从原游标继续，不能重新生成业务事件键。
- 已提交发送意图、供应商请求、外付 intent 或 unknown/uncertain 必须保留。过期准备租约可按原规则回收；已经开始发送或出款的不自动重发。核对原供应商/银行结果，使用有权限、有原因和证据的原处置入口。已注销原身份的款项仍只属于原 user_id，不能交给复用邮箱的新用户。
- 同检查点恢复必须覆盖持久任务/事件/财务账本、外付证据、私有文件、APP_KEY、安装封印与换钥 journal。恢复后核验所有密文类别、唯一流水与事件、未知状态和原身份边界；重建 Redis 临时应收结果，锁内版本检查继续生效。
- 历史安装脚本演练过 SQLite 一致快照；`scripts/verify-architecture-recovery.py` 已验收一次性 MySQL 8.0.35/Redis 销毁重建后，从同一 DB 逻辑 dump、私有文件、APP_KEY 和封印检查点恢复，61 表/85 行/21 迁移一致，历史卡密/幂等/unknown/通知发送意图保护及部署检查通过。详情见 [发布状态](release-status.md)。该结果限单宿主离线合成逻辑恢复，生产备份、Redis 可靠性和异机恢复仍须实际验收。

## 7. APP_KEY 离线换钥

普通发布**不换钥**。确需换钥时：

1. 进入维护模式；停止所有应用节点上的 worker、调度器和其他写入者；备份 DB、storage 状态及旧密钥。
2. 保持 `.env` 和现有配置缓存使用**旧 APP_KEY**。生成新密钥放到 Web root 外的专用 0600 文件，安全备份它。不要先改环境密钥，也不要把密钥作为命令行参数。
3. 运行：

```sh
php artisan app:reencrypt-secrets --new-key-file=/secure/cloudcms-next.key --reason="审批记录编号与换钥原因" --workers-stopped
```

4. 命令验证维护状态并要求确认所有写入者已停，在一个事务内先锁用户再按既定顺序轮换以下密文，保留账本、金额、状态和价格：

   | 表/列 | 处理范围 |
   | --- | --- |
   | invoices.line_items、instances.active_config、fulfillment_tasks.payload | 递归处理 encrypted_params / encrypted_operation / encrypted_purchase / encrypted_result / encrypted_request / encrypted_binding |
   | instances.encrypted_credentials | 历史实例凭据 |
   | redemption_codes.code、code_hash | 卡密重新加密及按新 APP_KEY 计算 HMAC |
   | affiliate_withdrawals | encrypted_payout_details、encrypted_payout_evidence、operation_requests |
   | wallet_clearances | payout_details、payout_evidence、operation_requests |
   | reconciliation_entries.evidence | 付款/旧资源处置的追加证据 |
   | notification_events / notification_deliveries | 两表 encrypted_payload，保留投递身份与原消息快照 |
   | upstream_apis.encrypted_secret、system_settings | 上游 secret、is_encrypted 设置及提交 marker |

   仅失效本应用已知设置和上游 JWT 缓存，写入 system 审计；不清空共享 Redis。operational_events 仅保留白名单非秘密 ID/状态，权益归档与映射核准只保留非秘密快照，因此不伪称它们是待轮换密文。
5. 命令提交 DB 后原子更新密钥封印。此时旧密钥应当**无法再启动应用**；这是预期的停机窗口，不要尝试用 key:generate 解除。由可信文件操作更新 `.env` 的 APP_KEY，移除已知配置缓存 `bootstrap/cache/config.php`（如有自定义缓存路径则对应处理），再构建 config cache。重启全部 FPM/worker，验证 `app:deployment-check --production` 和历史凭据，然后退出维护。旧 Cookie/会话及签名链接会失效，客户需重登/重发链接。
6. 若提交前解密或 SQL 失败，整个 DB 事务回滚，但换钥进度保留。应用有未完成 journal 时拒绝正常启动；仅允许离线重跑 `app:reencrypt-secrets`。修复原因后使用同一对密钥重跑，不换另一把目标密钥。
7. 若 DB 已提交而写锁失败，保留旧配置，使用同一对密钥重跑；事务中的加密 marker 可判定已提交，仅补封印，不重复换钥/审计。若锁已更新但进度文件删除失败，则使用**新配置**启动命令恢复清理。文件和数据库之间没有跨介质原子提交，不自动删除 journal 或回退一半。

命令支持章程提到的 `--old-key` 作为兼容输入，但默认取当前配置，推荐省略以免泄漏到进程列表；其值必须等于当前 APP_KEY。维护模式不代替真正停止其他写入进程。

## 8. 交付验收

开发/CI（只用隔离数据）：

```sh
./vendor/bin/pest
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
./vendor/bin/pest --group=spec
python3 scripts/verify-installation-release.py
python3 scripts/verify-installation-release.py --redis
python3 scripts/verify-receivable-capacity-mysql.py
python3 scripts/verify-maintenance-fairness-mysql.py
python3 scripts/verify-architecture-recovery.py
```

生产上线必须另外确认：MySQL 8 新装与恢复、TLS/FPM、生产 Redis 持久化、实际 systemd/cron 执行、备份可恢复、真实邮件/OAuth送达，以及计划启用的付费供应商协议。短信/实名按用户要求维持关闭。当前能力与发布阻断见 [release-status.md](release-status.md)，旧 rc.1 矩阵只作历史证据。
