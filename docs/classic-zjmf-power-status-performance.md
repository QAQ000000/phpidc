# 独立机器电源状态查询

## 问题与修复

此前“查看机器状态与监控图表”依次读取 header、STATUS、cpu/disk/memory/flow 六个接口，真实整页约 26 秒。单进程 PHP 预览在此期间还会阻塞其他请求。

服务详情新增“查看机器电源状态”：浏览器通过带 CSRF 的认证 POST `/console/instances/{instance}/power-status` 原地异步更新，只执行一次现有 `ProvisionCommand::STATUS`，不读取 header、控制台 HTML、机器管理配置或历史图表。首次/失效鉴权仍可能额外登录；不将其算作业务状态请求。

立即显示加载提示，禁用重复点击；成功显示状态与本次完成时间，失败清除旧显示并明确提示不可用。没有自动轮询、自动重试或状态缓存；JS 关闭时仍可提交表单查看服务端页面。原有全图表入口及其行为保留。

## 契约与不变量

- 复用 PowerControllable、ProvisionCommand::STATUS 和现有 ProvisionResult，不修改冻结接口、DTO 字段、枚举、迁移或不变量。驱动在既有不定形 rawResponse 中附带规范化 machine_status_widget，Action 不解析供应商线协议，Controller 仅公开 available/state/checked_at。
- 查询前验证本人、Active、启用的上游账户、已绑定 host 和此前认证同步的 machine_status 能力；由当前实例绑定解析驱动。此次 STATUS 本身仍需上游鉴权，远端拒绝则返回 unavailable。
- 查询后重新验证用户归属、绑定、状态和账户启用状态，变化则丢弃结果。无数据库事务跨 HTTP（INV-L3），无模型/资金/任务变更（INV-L4）。业务 Active 与机器 on/off/unknown 分开。
- 只读查询不预读 header；有副作用的电源/重装/救援命令和 suspend/unsuspend 的实时验证及 INV-S5 不变，不能用缓存能力作为变更授权。
- 每次上游调用仍经 CallUpstreamAction 脱敏记录（INV-U1）。STATUS 超时标记 failed，而非有副作用命令的 ambiguous；不会创建 cloud_operation 意图或履约任务。
- HTTP 超时继续采用既有 30 秒上限，未擅改 INV-Q1。供应商慢或认证过期时仍可能等待；3.26 秒仅为此次样本，并非响应 SLA。

## 2026-11-18 实测

在持续运行的既有隔离库和 Playwright 会话，实际点击服务 3（上游 4253）的新按钮：

- HTTP 200，**3263 ms**，状态 **on / 开机**。
- 立即显示“正在查询机器电源状态，请稍候…”，aria-busy=true；响应后原地显示结果，按钮恢复，无整页导航。
- 浏览器只发送一次 `/power-status`；上游 STATUS 累计从 10 增至 11，VNC 仍 2 次，无新增其他机器命令。
- 钱包仍 **0.99 CNY**，账单 **1**、流水 **2**、履约任务 **1**。
- 不关闭或重启现有项目，不关闭原有 VNC 标签，不启动自动生命周期 worker/cron。

`PowerStatusReadTest` 新增 18 个测试实例，覆盖一次只读请求、服务页无远端 I/O、无 JS 回退、状态语义、无旧值缓存、远端错误/超时、归属/能力/状态检查、绑定变化及日志脱敏。原有 ClassicCloudTelemetryTest 继续保留完整图表断言。

## 仍存限制

独立电源查询交付时，历史图表/机器管理仍沿用原有 panel widgets 聚合读取。后续已将机器管理改为只读取 header，真实点击约 3.9 秒，详见[机器管理性能验收](classic-zjmf-machine-controls-performance.md)；完整监控仍保留聚合读取。当前 PHP 预览仍为单进程，供应商 I/O 期间仍会阻塞其他 HTTP 请求；这轮未中断用户测试以替换运行服务。正式部署应使用项目既有部署方案中的并发 PHP-FPM。独立查询消除了电源状态必须等待所有图表的依赖，并不意味着远端调用本身变为零延迟。

收尾验证：Pest **543 passed / 4280 assertions**，PHPStan **0 errors**，Pint **clean**，Specs 剩余 **0**；原有测试断言未修改。PHP/JS 语法及 diff whitespace 检查通过。预览 PID 1448041 继续存活，`/up`、`/login` 均 200，约 0.20 秒，未重启、关闭项目或浏览器。
