# 洛杉矶轻量弹性云：既有服务与监控验收

日期：2026-11-18。用户指定添加商品并绑定已开通的 4253，测试状态、IP/端口/登录信息及图表，不付款；随后要求测试后保留项目运行。

## 实际结果

| 项目 | 认证查询 / 验收结果 |
|---|---|
| 上游商品 | PID **14**「洛杉矶轻量弹性云」，monthly，CNY |
| 已开通服务 | host ID **4253**，当前账户所属，业务状态 **Active** |
| 本地商品 / 实例 | 隔离验收库 **3 / 3**；此前 CDN 4291、API KEY 4066 的实例记录未变 |
| 主 IP / 登录信息 | 主 IP、用户名、密码均有返回并已同步；端口 **22**，系统 **Ubuntu-20.04.1-x64**；密码加密保存，未在操作日志中发现其明文 |
| 网络可达性 | 从验收服务器连接主 IP 的 22 端口成功，收到 SSH banner；未尝试密码登录或执行系统命令 |
| 机器运行状态 | `/provision/default` 的只读 STATUS 返回 `data.status=on`，页面显示「机器状态：开机」 |
| CPU | 200，area，单位 %，1 条曲线 / 721 点 |
| 硬盘 IO | 200，line，单位 KB/s，读/写各 721 点 |
| 内存 | 200，bar，单位 MB，总量/已用各 721 点 |
| 网卡 | 200，line，单位 Kbps，进/出各 721 点 |
| 本地财务 | 账单仍 1、流水仍 2、履约任务仍 1；钱包仍 **0.99 CNY** |
| 上游余额 | **5.85 CNY → 5.85 CNY**；本轮没有新购或付款 |

4253 是已购服务 ID，不是商品 PID。本轮正确保留 products.upstream_pid=14，并另建 instances.upstream_instance_id=4253 的唯一绑定。导入通过既有商品 Action 和私有既有服务验收 Action，先核实认证主机的商品/币种/周期，再在事务内锁用户、创建绑定和原因审计，由 ApplyInstanceSnapshotAction 应用状态及加密凭据。未伪造 Paid 新购、改绑旧实例或接管上游回调；操作前已备份隔离库。

商品裸月付价格为 2.00 CNY，既有实例认证续费金额为 **16.00 CNY**，不能用裸价覆盖已有配置后的实例金额。本轮按已购主机金额保留 recurring_amount；既有配置 ID 配对未审核，沿用 mapping_requires_review 标记阻止升级/计费降价，后续操作需另验。

## 协议证据与实现

参考源码 `app/home/controller/ProvisionController.php::getChartData`、`app/common/logic/Host.php::status` 和客户端 `includes/chart.tpl` 与真实接口一致：

- header 广告 module_power_status=true 及 module_chart 的 cpu/disk/memory/flow。
- `POST /provision/default`，表单 `id=4253, is_api=1, func=status`；只使用已有 ProvisionCommand::STATUS，不开放任意命令。
- `GET /provision/chart/4253`，参数 type、start、end，后两者为毫秒时间戳。显式请求最近 24 小时，避免参考源码默认日期计算不一致。
- 响应 `status=200, data={unit,chart_type,label,list}`，list 为多条曲线，每点含 time/value。归一化时保留真实零和 null；错误、非数值、不明格式明确标为不可用，不制造健康零值。

复用现有 HasCustomPanelWidgets 和 InstanceInfoResult.panelWidgets；syncInfo 只同步能力描述与原有凭据，点击「查看机器状态与监控图表」才读取实时状态和四类图表。能力标记保存在已有 active_config.upstream_panel（JSON 增项），热切换时清除旧标记。没有更改冻结接口、DTO 字段、枚举、迁移或业务不变量。

客户 POST 路由 `/console/instances/{instance}/telemetry` 经过本人/Active/账户启用检查、CSRF、限流；HTTP 后再次核对状态与绑定，过期结果丢弃。Driver 纯 I/O，CallUpstreamAction 记录脱敏日志，实时监控不写实例或资金。图表以本地 SVG 呈现 area/line/bar，不执行供应商脚本；时间标签明确标为上游时间，不猜测其时区。图形坐标的浮点计算只处理非财务监控数据，金额仍用 Money。

## 浏览器与性能边界

真实 Chrome 已登录本人账户并打开 `/console/instances/3`，核对真实 IP、22 端口、用户名、密码、Ubuntu 信息与监控按钮均显示。实际点击后进入监控页，确认「开机」和四张 SVG 图表，7 条曲线均显示 721 个有效采样点，无不可用提示。原始响应、浏览器快照及截图保存在私有状态目录，服务详情快照含登录密码，不入仓库。

本次监控页实际响应约 **26 秒**。CLI 默认等待导航 5 秒先报告超时；随后独立快照确认同一次 POST 已成功完成，没有重复点击或重新执行控制命令。当前按顺序读取状态及四类图表，不能宣称响应足够快。最初协议探测末尾存在非零值，浏览器随后一次查询的末尾时间桶为零；页面忠实显示上游原值，不据此保证机器负载或采集质量。

另有两次正常异步同步的 header 查询超过现有 **3 秒**超时，日志显示 http_status=null、约 3000ms、failed；此前首次同步已成功提交 Active/凭据。显式客户面板读取改用标准 **30 秒**超时，异步 syncInfo 保留既有 3 秒约束。没有吞异常、伪造后续同步成功或改写约束；供应商延迟仍可能造成异步重试。

SSH 密码认证、VNC、开关机/重启/重装、续费、实际负载与公网回调均未验收。本轮只读 STATUS 不等于已执行电源控制测试。

## 保留运行环境

按用户要求，项目预览服务保留运行，未关闭。服务元数据及当前地址位于私有状态目录的 `project-preview-server.json`，原数据库、APP_KEY 和证据均保留；后续测试复用该环境，不重新采购。未启动自动扣款或到期任务 worker/cron，未部署正式业务数据库。

新增 ClassicCloudTelemetryTest 覆盖凭据同步/加密、本人隔离、停用限制、换绑丢弃、四类图形、显式失败与 null/零差异、业务状态与机器状态分离，以及无财务任务副作用。旧测试断言与 Specs 未修改。

最终 Gate：Pest **497 passed / 3918 assertions**；PHPStan **0 errors**；Pint **clean**；Specs **0**。PHPStan 覆盖新增 PHP 文件，diff whitespace 检查通过。预览地址为 `http://127.0.0.1:59493`，本轮结束前 `/up` 和 `/login` 均返回 200，进程仍运行。监控截图：私有状态目录 `output/playwright/cloud4253-telemetry.png`，已检查不含密码或 API 密钥。未提交或发布正式版本。
