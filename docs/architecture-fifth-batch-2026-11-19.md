# 架构清理第五批：运行闭环验收记录

基线 `5049312`。按用户“可以开始第5批”指示和 [B5 方案](architecture-fifth-batch-plan-2026-11-19.md)，完成同一隔离环境中的真实运行组件验收；最终生产环境及真实外部通道验收仍待配置。没有改动业务 Action、冻结规则、schema、旧测试或 Specs。

## 已实现的可重复验收

新增入口 [verify-production-runtime.py](../scripts/verify-production-runtime.py)，配套专用 [环境模块](../scripts/production_runtime_environment.py)、[协议及 SMTP 接收端](../scripts/production_runtime_supplier.py)和 [业务夹具](../scripts/fixtures/production-runtime.php)。执行：

```sh
python3 scripts/verify-production-runtime.py
```

脚本使用本机已有 PHP 8.4、Nginx、OpenSSL、www 账户和缓存 Docker 镜像 `mysql:8.0.35`、`redis:7-alpine`，Python 环境为 3.10（本地 SMTP 接收端使用标准库 smtpd/asyncore，适用 Python 3.10/3.11）。运行编排需要 root，应用 Web/CLI/worker 实际以 www 运行；不安装或修改全局 systemd、站点或共享服务。

私有发布基目录复用仓库代码，独立 bootstrap/cache、public、storage、APP_KEY 和配置/路由/事件缓存，不读取仓库 `.env`、不改变原仓库目录权限。MySQL/Redis 均为本次专有 network=none 容器、无宿主发布端口，仅通过私有 Unix socket 连接；MySQL 使用仅访问专用空库的独立账户。Redis 实际配置为 AOF、`appendfsync=always`、`noeviction`。本地 HTTPS 使用专用 CA/IP SAN 证书并严格校验，未关闭证书验证。

安装由真实 HTTPS `/install` 向导执行，实际完成 24 条迁移、初始权限管理员及密钥封印；缺 CSRF 的安装请求拒绝为 419，安装后 GET 及有效 CSRF 的 POST 均为 404。发布资源、建立缓存后，`app:deployment-check --production` 通过。管理员与客户通过实际 HTTP 登录，前台会话使用 Redis/secure cookie；三个发布 JS/CSS 资源通过 HTTPS 读取。此过程没有手写封印、使用 User factory、绑定 synthetic MaintenanceBatchWork 或替换应用 Queue/Http 驱动。

## 交易、通知与恢复结果

| 检查 | 同环境实际结果 |
| --- | --- |
| 配置与本地报价 | 表单生成非空主机名/合规密码；三次报价均 9.60 CNY，未增加任何上游请求 |
| HTTP 建单 | 缺 CSRF 请求零建单；原 request_id 重放复用原账单；网关全额支付、钱包分配为 0.00 |
| 支付回调 | 无效签名拒绝且零回调/任务；三单各接收两次有效回调，最终仅三条回调 |
| 新购／续费／升级 | 金额分别 9.60／9.60／2.40；三张 Paid 账单、三个 success 任务，attempts 各为 1，同一原用户及实例 |
| 上游协议替身 | 一次下单、一次续费、一次升级结账、三次对应账单付款；重复操作未重复扣款 |
| 金融与密文 | 六条唯一流水，五列用户资金均 0.00；交付凭据/库存密文可读，原运行日志不含上游密钥、JWT、动态开通密码或交付密码 |
| Worker 停止恢复 | 实际停止默认常驻 worker，续费保持 Paid/pending，恢复后执行一次 |
| Redis 持久化恢复 | 有待处理队列时停止独立 Redis；巡检识别 Redis critical；重新启动后队列计数、原账单和流水保持，worker 恢复后无重复交付 |
| SMTP | 五条持久投递均 sent、attempts=1，本机 SMTP 实际收到五封、唯一 Message-ID；没有发送至外部邮箱 |

实例详情实际显示交付地址及凭据，响应保留 private/no-store。经典最终配置为 502、未来续费金额为 12.00；本地卡密只验证库存加密、HMAC 和重复导入，未宣称本批购买了本地卡密。

## 调度、巡检与进程

启动真实 `schedule:work` 跨越下一分钟，经原 `schedule:run` 派发维护任务，两个独立 maintenance 常驻 worker 执行原业务合同和领域 Action；没有替换维护实现。七类每分钟维护均产生实际完成心跳。目录的五分钟周期未人为改动，本轮用原 `maintenance:dispatch catalog` 明确触发目录验收，第八类心跳也完成。

生命周期与目录消费者启动前，分别观察到各自 Redis 队列中的一个待处理任务；启动对应常驻消费者后，六个业务队列的 ready/delayed/reserved 均归零，failed_jobs=0。应收长核验 worker 同时保持运行，本批没有构造大账户核验负载。实际 PHP 进程的 UID、二进制、参数和 cwd 保存在私有证据中。

`app:operations-check --json` 与独立 watchdog 检查均为 ok；将独立测试心跳副本设为陈旧时，真实 watchdog 返回 critical/exit 2。没有配置外部监控接收方或假称有人收到告警。

finally 按本次记录的 PGID/SID 检查实际非 zombie 子进程，确认 remaining_pids=[]、本次 HTTPS 端口关闭，两个本次容器删除且 inspect 不存在。另以忽略 SIGTERM 的真实 PHP fork 子进程验证 leader 先退出后的残余清理，未只凭父进程返回成功认定清理完成。

## 门禁、证据与原预览

完整 Pest **1801 passed /14895 assertions**（130.44 秒，PHP CLI 显式 `memory_limit=512M`）、PHPStan **0 errors**、Pint **PASS**；新增 PHP 夹具 lint、三个 Python 文件编译及 `git diff --check` 通过。原业务代码及测试未改，新增运行脚本的断言另由真实同环境验收证明。

运行日志：`/tmp/laravel13idc-architecture-batch5-runtime.log`；完整门禁：`/tmp/laravel13idc-architecture-batch5-{pest,phpstan,pint}.log`。最终运行证据为 `/tmp/laravel13idc-production-runtime-nn3a9ox1/`（同时在运行日志首行），包含 `result.json`（交易/队列/进程/组件状态）和 `cleanup.json`（实际退出/删除结果）。目录及凭据保持私有，不随 Git 提交。初始基础与残余清理定向证据分别在 `/tmp/laravel13idc-production-runtime-pu1bocqt/`、`/tmp/laravel13idc-production-runtime-l0issh7_/`。

原预览按 PID 1728214 的二进制、cwd 和运行环境识别，未用仓库 `.env` 选择目标。前后只读逐表哈希核对 **57 张持久表、734 条原行**及安装封印保持，`jobs=1` 未消费、迁移仍 **24 条**，SQLite quick_check/foreign_key_check 正常，`/up`、`/login`、`/admin/login` 均 200。证据：`/tmp/laravel13idc-batch5-preview-before.json` 与 `/tmp/laravel13idc-batch5-preview-result.json`。原预览未启动业务消费者/scheduler；本批无需迁移。

## 最终生产验收仍待的条件

只读核对发现当前预览未配置 Epay 的 URL/商户/密钥，也未配置实际邮件地址/主机。没有可用生产域名、真实商户回调、测试购买额度及外部监控接收方的验收记录，不能把上述结果登记为实际生产上线。

下一步应在最终环境填写支付/邮件配置和域名，明确实际测试商品、动作、支付/上游扣费上限及通知接收者，再验公网签名回调、真实上游开通/重复抑制、外部送达、实际 systemd/timer、监控责任人与异机恢复。原一次历史小额实购授权不延伸为本批的续费/升级授权。具体执行要求见 [部署手册](deployment.md#5-升级与回退)。其余审计业务依赖和安装封印生命周期改造仍是独立后续议题。

IMPLEMENTED: B5 同环境真实 HTTPS/FPM、MySQL/Redis、安装/认证/交易、常驻队列/调度、SMTP、持久化中断恢复、独立巡检/watchdog 及精确清理验收。
NOT DONE: 最终生产服务启用、公网实际收款回调、真实供应商扣款/交付、外部通知送达和值班/监控接收方及异机恢复；缺最终环境配置和限定实购范围。
DECISIONS: 本机协议与 SMTP 接收端配合实际运行组件，保留原业务实现/周期/队列；不改原预览运行模式。
CONCERNS: 本机协议验收不证明真实第三方行为，独立 Nginx/FPM/进程不代表全局 systemd 已启用，单实例 AOF 恢复不等于生产可靠性或异机灾备。
GATES: pest 1801 passed /14895 assertions | phpstan 0 errors | pint clean；隔离运行链路 PASS，原预览24迁移、数据保持、HTTP200；真实生产外部验收待完成。
SPEC CASES: 0（未改动）。
