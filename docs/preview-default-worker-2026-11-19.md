# 原预览默认队列常驻运行记录

日期：2026-11-19（Asia/Shanghai）。用户明确要求“启动常驻一下”，承接已付 CDN 订单因没有队列消费者而待开通的问题。本轮启用原预览的 `database/default` 常驻消费者；没有改动业务源码、schema、Specs 或测试。

## 启用范围与运行环境

- 专用服务：`laravel13idc-preview-worker.service`，安装于 `/etc/systemd/system/laravel13idc-preview-worker.service`，已执行 `enable --now`，开机状态为 `enabled`。
- 仅消费 `database` 连接的 `default` 队列。原预览 scheduler、maintenance、receivable-check、lifecycle、catalog 及其他独立消费者保持未启用，本轮不启用生产服务或共享服务。
- 使用 `/www/server/php/84/bin/php`，运行身份为 `root`，与原预览保持相同环境、SQLite 数据库、私有 storage 和 APP_KEY。原预览数据库和 storage 位于 `/root` 私有目录，没有为了套用生产 `www` 模板而扩大目录权限。
- 启动脚本、环境快照和启动前一致备份保留在 `/root/.local/state/laravel13idc/preview-worker-20261119-wtclgkbq/`：目录0700、私有文件0600、启动脚本0700。systemd unit 不含凭据；私有文件不进入 Git。
- worker timeout 为180秒，数据库队列 `retry_after=240` 秒，停止宽限210秒；`--max-time=3600`、`--memory=256`。systemd 设置 `Restart=always`、`RestartSec=5`，正常轮换及意外退出后由服务管理器重新启动。

这是当前 SQLite 预览的运行配置，不能作为生产 MySQL/Redis、HTTPS、监控或完整调度服务已启用的证据。源码部署或预览环境重建后，应核对目标数据库、storage、密钥和服务启动环境，再重启该专用服务。

## 首轮消费结果

- 首轮服务状态为 `running`，MainPID 为 `2141384`，`NRestarts=0`。
- 原有六条 `default` 任务已全部消费，`failed_jobs=0`。
- 通知投递 `#1`、`#2`、`#3` 均为 `sent`，`attempts=1`、`error_code=accepted`。这证明 SMTP 接受了这些邮件，不证明最终收件箱送达；用户此前报告的邮件测试正常保留为其独立反馈。
- 账单 `#4` 的原履约任务 `#2` 仍为 `success`、`attempts=1`。原队列副本被消费时由成功状态守卫返回；实例 `#4` 的 `/apply_credit` 记录仍为一次，没有再次开通或重复扣费。

## 守护与重启验证

通过 `systemctl kill --kill-who=main --signal=TERM laravel13idc-preview-worker.service` 向旧 MainPID `2141384` 发送 SIGTERM，原 worker 正常退出0；systemd 随后自动拉起新 MainPID `2141584`，`NRestarts=1`、`ActiveState=active`、`SubState=running`、`UnitFileState=enabled`。这是实际进程退出后的恢复证据，不仅是服务配置检查。同一私有目录的 `final-result.json` 确认新进程二进制、工作目录和应用环境与原预览一致，jobs/failed_jobs均为0、SQLite完整性ok/外键异常0、迁移仍24条，健康及前后台登录均HTTP200。用户、账单、付款尝试/回调、资金流水、设置、产品、上游账户及履约任务逐行保持。

## 日常操作与停止边界

查看状态和近期日志：

```sh
systemctl status laravel13idc-preview-worker.service
journalctl -u laravel13idc-preview-worker.service -n 50 --no-pager
```

停止、启动或在代码更新后重启：

```sh
systemctl stop laravel13idc-preview-worker.service
systemctl start laravel13idc-preview-worker.service
systemctl restart laravel13idc-preview-worker.service
```

临时停止服务不会取消其开机启用状态。确需取消开机启用并同时停止时，使用：

```sh
systemctl disable --now laravel13idc-preview-worker.service
```

停止或拆除当前预览时，必须同时停止该专用 worker；仅关闭前台 PHP 监听不会停止后台消费。停止后应核对本服务 PID、对应 PHP 进程和队列状态，不停止共享 MySQL、Redis、Nginx、Supervisor 或其他项目的服务。

IMPLEMENTED: 原预览 database/default 专用常驻消费者、开机启用、原六条队列消费及发送/履约幂等核对。
NOT DONE: scheduler、其他队列消费者、生产部署和外部监控未启用。
DECISIONS: 本次“启动常驻一下”授权仅落实当前预览默认队列及其正常任务处理，不新增测试购买或批量供应商操作。
CONCERNS: sent/accepted 为 SMTP 接受结果；停预览须同时停本专用服务，环境重建须重新核对其私有启动环境。
GATES: 首轮running/enabled、六条任务消费、failed_jobs0、三条通知sent、原成功履约attempts不变及SIGTERM退出后自动拉起PASS。本轮仅运行配置与文档更新，未重复执行完整门禁；1806项通过属于上一代码提交的既有证据。
SPEC CASES: 0（未改动）。
