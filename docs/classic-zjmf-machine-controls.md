# 经典 ZJMF 机器管理与 VNC 直连

本轮复用既有隔离验收库及服务 4253，不新购、不付款，不修改正式业务数据库。预览 `http://127.0.0.1:59493` 和 Playwright `classic-cloud-live` 会话继续运行。

## 已核实协议

证据为参考成品的 `app/home/controller/ProvisionController.php`、`app/common/logic/Host.php`、`app/common/logic/DcimCloud.php`、`public/themes/clientarea/default/servicedetail.tpl` 及实时认证 `/host/header`：

- `data.module_button.control/console` 中只接受 `type=default`、匹配本地允许命令的按钮，不执行任意自定义命令或上游脚本。
- 电源、重装、救援、VNC 复用现有 ProvisionCommand 枚举。200/success 只表示受理，不修改本地业务状态、系统名称、凭据或账单来假装完成。
- `cloud_os_group[]{id,name}` 为系统分类，`cloud_os[]{id,name,group}` 为版本。重装提交当前 header 中的版本 id，执行前重新核对分类/版本配对。不能用本地商品选项 ID 或镜像名称中的数字代替。
- 仅在 `reinstall_random_port`、`reinstall_format_data_disk` 允许时接受对应选项；格式化数据盘默认不选。重装和救援均需单独备份确认。
- **救援拼写差异**：冻结枚举 RESCUE_SYSTEM 的领域值仍为 `rescueSystem`，经典线协议为 `rescue_system`，仅驱动转换，不修改枚举、契约或 DTO。参数为 `system=1`（Windows）或 `2`（Linux）、`temp_pass`。密码规则来自 `host_data.password_rule.rule`，并与商品 provisioning_rules 密码约束取交集，规则不明则拒绝。
- 4253 还广告 `type=custom,func=exitRescue`，不在冻结命令中。本轮没有新增或猜测该命令；界面提示退出救援需管理员在上游处理。重置密码不在本次交付范围。

## 执行、审计与边界

复用 PowerControllable，由 DriverManager 解析实例自身绑定。客户只能操作本人 Active 服务；停用账户、终止申请、已有 pending/running/failed 履约任务均阻止新命令。

Action 先按 users → instances 锁顺序提交请求意图及原因审计，再通过 CallUpstreamAction 在事务外调用纯驱动。驱动在 header 返回后再次核对绑定、当前任务和终止申请，Action 在结果应用时再次核对绑定与状态（INV-L1/L3/L4、INV-U1）。审计不保存完整请求、密码或连接地址。

请求 ULID 经实例行锁和审计去重，浏览器重复提交不能重复发送。连接失败记 unknown，进程中止保留 requested；两者阻止自动重试及后续机器命令。后续 [FIX-006](backend-fix006-machine-reconciliation.md) 已增加后台人工核对：JSON意图保留提交时间、非秘密参数及加密绑定，只有带上游任务证据的已提交人工核对审计才能允许新请求；原 requested/unknown 与原ULID去重仍保留。没有新增表字段、冻结状态或重放原命令。热切换归档旧请求，迟到结果不得覆盖新绑定。供应商当前电源不证明原任务完成，缺少证据时继续保持未决。

瞬时绑定复验不能提供两个独立系统的分布式原子性。极窄窗口内远端执行与本地热切换仍需通过审计人工核对，不能持数据库锁等待 HTTP。

## 本站域名 + B64，浏览器直连

实测 `POST /provision/default id=4253,is_api=1,func=vnc` 返回 `status=200,data{url,pass,zjmfcloud_out_vnc:false}`。url 是 HTTPS `/dcim/novnc?url=<B64 WSS>&password=...&host_token=...`。只提取 WSS 与 VNC 密码，丢弃不必要的 host_token，不加载上游页面。

本站生成相对签名链接 `/console/instances/{instance}/vnc?expires=...&fingerprint=...&binding=...&signature=...#<B64 JSON>`，域名使用当前本站。连接信息在浏览器片段中，不进入本站页面 GET 查询串。**B64 是编码，不是加密**，完整链接仍须按短时控制台秘密处理。

- 入口要求本人登录、Active、启用账户、当前绑定匹配和五分钟有效签名。签名绑定 B64 内容摘要，浏览器校验后立即清除片段。
- 本站加载 noVNC RFB，浏览器 WebSocket 直接连接远端 WSS。无本站 WebSocket 转发、iframe 或远端 HTTP 代理，保留远端 TLS 证书验证。
- 连接不落库、不闪存 session old-input；VNC 响应整个 data 及救援 temp_pass 在上游日志中脱敏。页面 no-store/no-referrer。
- 可手动断开，发送 Ctrl+Alt+Del 另需确认。不会自动发送按键、粘贴机器密码或修改远端分辨率。
- 仅支持此次核实的 HTTPS 包装 + WSS，其他直出页面、HTTP/WS 或未验证格式明确拒绝。

noVNC **v1.6.0** 的 core/vendor 源文件与 LICENSE/AUTHORS 随站点提供，无运行时第三方 CDN。官方归档 `https://github.com/novnc/noVNC/archive/refs/tags/v1.6.0.tar.gz`，SHA-256 `5066103959ef4e9b10f37e5a148627360dd8414e4cf8a7db92bdbd022e728aaa`，保留依赖许可。

## 真实浏览器验收（2026-11-18）

- 从本人服务实际点击机器管理，显示五类电源、重装、救援与 VNC。
- 4253 返回 **5 类系统、13 个版本**。选择 Ubuntu 后只显示 24.04、22.04、20.04.1；未提交重装。此机端口及格式化标志为 false，对应选项隐藏。
- 实际填写原因、确认并点击 VNC。新页面位于本站，显示“已直连远端控制台”；观察到 **1 条非本站 WSS**、5 个 RFB 消息、**1024×768 canvas**，无页面异常和第三方 HTTP 请求，地址片段已清除。
- 已查看实际截图，画面为 Ubuntu 20.04.4 LTS 登录提示；未输入登录信息。上游模板标签为 Ubuntu-20.04.1-x64，不能据此把客体系统补丁版本当成模板标签。
- 没有发送按键、Ctrl+Alt+Del、重装、救援或电源变更。本轮 2 次 VNC 请求分别为协议核实和按钮验收，不是失败重放。
- 钱包 **0.99 CNY**，账单 **1**、流水 **2**、履约任务 **1**，均未变；无 status/vnc 之外的新增 provision 命令。
- 机器管理读取复用现有 panel widgets，也读取监控，保留上一轮约 26 秒整页延迟。CLI 五秒导航等待先超时，独立 snapshot 确认原点击已成功，未重复点击。
- 保留 PHP 预览 PID **1448041**、两张浏览器标签和隔离库，不关闭项目、不启动自动生命周期 worker/cron。

`ClassicMachineControlTest` 隔离验证权限、确认、OS 配对、选项能力、密码规则、重复提交、未决结果、绑定变化、签名页面与脱敏。真实重装/救援/电源变更未执行，隔离 HTTP 测试不能替代破坏性联调。

收尾 Gate：Pest **525 passed / 4103 assertions**（新增 28 个测试实例），PHPStan **0 errors**，Pint **clean**；Specs 剩余 **0**，原有断言与 SpecIntegrityTest 未修改。JS/PHP 语法及 diff whitespace 检查通过，真实控制台凭据未出现在仓库源码、测试或文档中。最后 `/up`、`/login` 均 HTTP 200，保留的 PID 1448041 存活，VNC 标签仍显示已连接。

后续性能修复：服务详情新增不加载图表的独立电源查询，4253 实测 3.26 秒，详见[电源查询性能验收](classic-zjmf-power-status-performance.md)。机器管理也已独立读取 header，实际点击约 3.9 秒，详见[机器管理性能验收](classic-zjmf-machine-controls-performance.md)；完整监控仍保留聚合读取。
