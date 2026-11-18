# 安装、升级、恢复与密钥操作

本手册对应仓库 `VERSION` 的本地候选版本。生产安装还须完成文末验收；隔离脚本成功不等于已上线。

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
- 非结构化锁仅保留本地开发/历史测试兼容；生产启动和所有环境的 `app:deployment-check` 均拒绝未密封锁。本项目此前未实际安装，无需自动接管旧业务站点；不要手写一个空锁绕过安装。

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

审查 `deploy/cloudcms-worker.service`、`cloudcms-lifecycle@.service`、`cloudcms-catalog.service`、`cloudcms-schedule.service`、`cloudcms-schedule.timer` 的账户、工作目录和 PHP 路径后，才由服务器运维安装到 systemd 并启动。模板未在本轮加载到业务服务器。

- worker：Redis/default，job timeout=180，`REDIS_QUEUE_RETRY_AFTER=240`，停止宽限=210。HTTP timeout=30 < 180 < 240（INV-Q1）；不要改成相等。
- FIX-011新增队列必须随代码部署：两个独立实例 `cloudcms-lifecycle@lifecycle-urgent`、`cloudcms-lifecycle@lifecycle`，以及一个 `cloudcms-catalog`；分别只消费对应队列，default另设。所有worker/cache必须共享同一Redis；不要用sync连接运行派发器。
- lifecycle默认每分钟最多100临近到期+100全量轮转，catalog默认每5分钟100商品；job timeout=180、共享实例锁210、retry_after=240，unique排队TTL3600。1000实例/临期≤100/平均任务≤3s的容量假设下，目标临期≤10分钟、全量≤75分钟，尚未生产验收。持续上游错误或积压不在该时效内，及时人工核对失败记录。
- 监测urgent最老任务等待>5分钟、rotation>30分钟、应同步实例last_synced_at>75分钟或长期为空，并核对worker健康/失败队列；这些为运维巡检门槛，本轮未接入自动监控告警。不得简单增加worker数量突破已评估的上游并发。
- scheduler：每分钟 `schedule:run`，模板TimeoutStartSec=55，供应商HTTP已移至独立worker。若选择 cron，运行账户同上，使用绝对 PHP/项目路径，**与 systemd timer 二选一**。业务调度已有 withoutOverlapping/onOneServer，所有节点必须共享 Redis 锁。
- 部署验收检查 `systemctl status`、`journalctl -u`、`list-timers`、`php artisan schedule:list`、失败任务和 fulfillment_tasks；不能只看 service 文件存在。
- 新代码发布后重启 FPM 和 worker 以清除长生命周期旧设置；`queue:restart` 要在同一 Redis/cache 配置下执行。首次启动前确认已安装和 migrations 完成。
- 不运行 `queue:retry all` 来代替核对上游成本/交付状态。付款后缺货仍是人工确认、补库存、显式重试；不自动退款或重新购买。

## 5. 升级与回退

1. 在隔离环境验收候选提交、三道 Gate及安装脚本；记录 Git commit、VERSION、composer.lock 校验值和部署文件清单。
2. 维护模式；先停 timer 并等待正在运行的 scheduler 完成，再按 210 秒宽限停止 worker，核实没有本项目执行中的任务。已有上游不确定请求保留证据、人工核对。
3. 先备份，再部署完整的同一版本代码/依赖。保留 `.env`、APP_KEY、storage、私有下载和原安装锁。
4. 在原库执行 `php artisan migrate --force`，然后重新发布资源、构建缓存、运行 `app:deployment-check --production`。**升级不重新运行安装向导**。
5. 重启 FPM、worker/调度器，完成管理员/客户登录和专用验收商品验证，再 `php artisan up`。实际付费验收须使用明确的测试商品、额度和账目。
6. 失败时保持维护模式。无 schema 变化可回退代码和依赖；有不兼容迁移时，不能只回退 Git，也不能盲跑 migrate:rollback。按备份恢复整套 DB/文件/密钥，或人工批准向前修复。

## 6. 备份与恢复

- 备份范围：MySQL 一致性快照、`.env`/APP_KEY、`storage/installed.lock`、`storage/app/`（含私有下载）、对应代码/lock 文件/版本；若存在安装或换钥进度，也须保留。备份目录在 Web root 之外，用 0700/0600，另做异机加密备份。
- MySQL 可用受保护的 `--defaults-extra-file` 提供凭据，`mysqldump --single-transaction --routines --triggers --events --hex-blob` 创建快照；不要将密码放命令行，也不要在本仓库存 SQL dump。
- 恢复前停止入口、worker 和调度器；先在独立实例恢复验证。DB、私有文件与 APP_KEY 必须来自同一检查点。恢复后重建缓存和资源、运行就绪检查并验证历史卡密可解密、财务金额及幂等键完整。
- Redis 中的队列、会话和分布式锁有独立恢复问题。不要恢复旧锁或把旧通知队列直接当 exactly-once 重放；不要对共享 Redis 执行 FLUSHDB。履约 outbox 可按既有调度回收；通知尚无持久事件 outbox，丢失/重复发送需人工核对。
- 本轮隔离脚本实际演练了 SQLite 一致快照、安装锁及同一密钥恢复、历史已交付卡密读取。**MySQL 8 物理/逻辑恢复、生产 Redis 可靠性与异机恢复尚未演练。**

## 7. APP_KEY 离线换钥

普通发布**不换钥**。确需换钥时：

1. 进入维护模式；停止所有应用节点上的 worker、调度器和其他写入者；备份 DB、storage 状态及旧密钥。
2. 保持 `.env` 和现有配置缓存使用**旧 APP_KEY**。生成新密钥放到 Web root 外的专用 0600 文件，安全备份它。不要先改环境密钥，也不要把密钥作为命令行参数。
3. 运行：

```sh
php artisan app:reencrypt-secrets --new-key-file=/secure/cloudcms-next.key --reason="审批记录编号与换钥原因" --workers-stopped
```

4. 命令验证维护状态并要求操作员明确确认停止 worker。在一个事务内锁定用户后按既定顺序处理：订单加密快照、实例凭据、卡密与 APP_KEY-HMAC 去重哈希、任务购买/操作/远程结果快照、上游凭据、加密设置。钱包/账本/价格不变，记录 system 审计。仅失效设置和上游 JWT 的已知密文缓存。
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
```

生产上线必须另外确认：MySQL 8 新装与恢复、TLS/FPM、生产 Redis 持久化、实际 systemd/cron 执行、备份可恢复、真实邮件/OAuth送达，以及计划启用的付费供应商协议。短信/实名按用户要求维持关闭。当前能力与未实现项见 `release-0.1.0-rc.1.md`。
