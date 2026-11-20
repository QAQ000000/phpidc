# C4 实例工作台实施记录

日期：2026-11-20，基线 `1f0d38c`。对应 [C4 合同](admin-instance-workbench-c4-contract-2026-11-20.md)。本批实现独立实例工作台、管理员机器操作、显式上游事实同步和已付款失败原任务重试。零迁移、零新增前端构建链，保留六类、31 个全局左侧入口。

## 工作台与本地查询

新增 `/admin/instances/{instance}`，从经典列表、客户服务记录及履约任务进入。既有经典列表、简要详情、三个旧重试路径和全局搜索范围保持。当前四种履约驱动可读本地安全元数据；未实现的 Proxmox 控制及非经典远端控制仍明确不可用。

`AdminInstanceQueries` 选择安全列，不选择或解密实例交付凭据、下游令牌、订单密文、任务 payload 或原始错误。配置仅从固定 JSON 路径读取数字选择值，再映射公开商品配置标签；不渲染任意配置或自定义字段。故意损坏交付密文不影响详情 GET。

客户、账单、任务和工单分别先检查 `customers.view`、`finance.view`、`tasks.view`、`support.view` 再查询；三个明细独立分页并保留其他页码。新工作台任务区不沿用旧列表的队列查看授权。GET 不请求上游、不读取机器菜单、监控或全量应收，也不产生业务审计写入。

## 管理员机器操作

`ExecuteCustomerPowerCommandAction` 保留客户 `execute` API，增加实际管理员 `executeAsAdmin`，内部共用既有协议。`AdminMachineAccess` 按用户 ID 升序锁实际管理员与原 owner，再锁实例；提交前重新核对有效管理员及专权、归属、Active、有效账户／绑定、终止申请及已有任务。已停用但未注销客户的既有服务允许管理员维护，原客户访问不会恢复。

共用原 120 秒互斥锁、永久 ULID 请求去重、`cloud_operation` 意图和 `instance.machine.request.*`／`result.*` 审计。请求与结果各自原子落库，网络在事务外。审计操作人为实际管理员，附原客户和 Web 上下文；中途撤权仍按原身份记录已提交结果，返回菜单／状态／VNC 前再鲜活鉴权。无资金写入、自动重试或伪装客户登录。

机器菜单通过明确 POST 只读当前 header 能力，八类现有命令仅显示上游提供的项。STATUS 另走显式 POST，返回 `available/state/checked_at`，不拉 header、监控图或上游原文。开机和 VNC 简洁直接；关机／重启／硬操作需中断确认，重装／救援需备份确认。执行前再校验 OS 分类／版本、端口、格式化及密码规则，备注可选 255 字；救援密码不持久化、不回填。

浏览器验收发现独立 POST 页面没有执行 Filament 面板初始化，导致 HTML 可见但 Alpine 弹窗不可用。已在成功读取菜单后显式初始化当前 admin 面板，再渲染既有外壳，恢复脚本、公共 Token、侧栏与弹窗；新增 HTTP 资产断言和真实浏览器交互覆盖。没有改变公共站点的 Livewire 资产注入配置。

`requested/unknown` 保持原未决核对与五分钟保护期，不盲重发、不通过同步清除。GET 仅按状态提示，是否已人工释放由原 POST 流程核验，避免旧 `unknown` 字段让已核对服务永久失去菜单入口。

## VNC、同步与重试

管理员 VNC 使用独立五分钟签名，绑定 actor、认证修订、执行所用实例／账户摘要和连接摘要。Controller 保存执行前摘要，返回后再次比较，防止把旧连接签成新绑定。端点重新验证 Active、经典有效绑定和当前管理员；连接只放 URL fragment，GET／审计不接收明文连接。保留本地 noVNC、no-store、no-referrer、CSP 和拒绝嵌入，客户原路由不变。

`SyncAdminInstanceAction` 仅支持有效经典绑定：事务外 `syncInfo`，事务内按用户→实例→账户→任务锁序重新核对实际管理员、原 owner、绑定、状态及账户指纹，调用原 `ApplyInstanceSnapshotAction` 并原子写管理员 `instance.admin_sync`。上游真实到期日、状态和原加密交付事实可以更新，原价格及未决机器意图保留，终态不复活。上游不可用返回原同步弹窗、安全保留备注，不暴露原始远端错误或假报成功。

工作台 retry 需 `upstream.view + tasks.view + finance.view` 及原对应运维专权，仅处理当前实例 Paid 账单的 FAILED 原任务。`RetryFulfillmentTaskAction` 锁内重读实际管理员、原 owner、账单／实例／任务身份与绑定，原任务重排、afterCommit 派发，不复制 payload 或创建新付费任务。经典未知订单／续费／升级意图仍由原 worker 协议核对，非经典远端发卡重试不放开。三个旧路径和可信 CLI 范围保留。

同步／重试 dialog 使用固定表单标识和当前目标 ID；失败只保留匹配表单的可选 255 字备注，确认框不回勾。机器表单失败不进入 old-input。

## 权限与预览

| 入口 | 权限 |
| --- | --- |
| 工作台 GET | `upstream.view`，关联区块再按域检查 |
| 菜单／命令／电源 POST、短签名 VNC GET | `upstream.view + upstream.machine-control` |
| 同步 POST | `upstream.view + upstream.sync` |
| 工作台原任务 retry POST | 上游／任务／财务查看，加原经典生命周期或本地库存重试权限 |

新增固定 `upstream-machine-operator`、`upstream-sync-operator`，分别只含上游查看和对应专权；既有生命周期／查看角色不自动获权。上游运维和全部业务预设包含新角色，不批量修改既有账户。

共享预览先做 SQLite 一致性备份，只给管理员 #3 增两角色；#2 和其他绑定不变，两条系统角色审计已登记。仍为 26 条迁移，55 张非权限业务表哈希一致，用户／钱包／账单／实例／流水保持。四个真实实例工作台仅只读访问；匿名 HTML／JSON 分别 302／401，#2 的四项新 POST 均 403。12 项健康与权限检查通过，jobs／failed_jobs 为零。

核对项目目录、PHP84、预览数据库与密钥身份后，在空队列时精确刷新默认和目录消费者，两者 active/running；59493 `/up=200`。未在共享预览读取真实机器菜单、同步真实供应商或执行真实机器／资金／通知操作。

## 验证记录

| 检查 | 结果与范围 |
| --- | --- |
| 全量 Pest | **3,159 passed / 27,784 assertions**，207.99 秒；原金额／身份／隐私／权限／幂等断言及全部 Specs 保留 |
| PHPStan／Pint | **0 errors／PASS** |
| 最终 HTTP／VNC／旧后台认证定向 | **77 passed / 642 assertions**；包含独立 POST 面板资产、VNC、旧路由认证 |
| 新工作台专项 | **48 passed / 336 assertions**；四驱动本地投影、分域 SQL、坏密文、分页和旧入口 |
| 管理员机器与客户回归 | **167 passed / 1,356 assertions**；实际 actor、原 owner、协议、幂等、未知结果、STATUS、网络期间撤权与换绑 |
| 同步／重试及既有流程回归 | **287 passed / 2,453 assertions**，两个无重复文件的定向集合；各自与全量重叠，不作总数相加 |
| JavaScript／差异检查 | 复用脚本 `machine-controls.js` Node 语法检查、`git diff --check` PASS |

隔离浏览器使用随机密钥、独立 SQLite/storage、合成账号和只监听 loopback 的模拟供应商；服务端额外禁止访问非模拟 HTTP 地址。完成 **36 项布局、20 项弹窗、31 项角色边界**，矩阵 `pageErrors=0`。机器菜单八类表单完整，OS 切换清空旧版本、仅放行当前组，危险确认不预选；手机弹窗视口内可滚动。

真实表单完成重装、无效救援密码拒绝且不反射、有效救援受理、unknown 原意图拦截、显式同步、安全备注回弹、经典及本地两条 Paid 失败原任务重新入队；另验证两个明细独立翻页并保留另一页码。同步／重试同 URL 跳转及分页的浏览器等待曾提前读取旧 DOM，已按实际导航／目标状态等待后核对，未为测试改写业务规则。

另有 **18 项真实 HTTP/CSRF 检查**：四个缺 CSRF 请求419、VNC秘密仅在fragment、HTML／安全头、签名篡改与其他管理员拒绝、已注销owner无远端请求、已停用owner允许维护、STATUS归一化、同步保持原unknown意图、实际管理员审计。GET 前后 **60 张业务表哈希保持**。操作后除实例／原任务／队列／审计／上游交换五表外 **55 表保持**；两个原任务均 pending／attempts0，资金流水、支付尝试、回调和通知事件均为零，原续费价保持，救援密码没有写入审计／操作记录／实例意图。

归档 **12 张合成数据截图**、PNG尺寸／完整性／SHA-256及结构化记录于 [截图索引](screenshots/admin-instance-workbench-c4-2026-11-20/README.md)和 [acceptance.json](screenshots/admin-instance-workbench-c4-2026-11-20/acceptance.json)。已抽看桌面机器菜单、手机重装弹窗及手机实例工作台。独立 Playwright 会话、HTTP／供应商进程和端口、SQLite/storage以及含随机口令／cookies的元数据已精确清理，共享预览保持。未发送邮件／短信或消费模拟交付任务。

模拟 VNC 证明签名、权限、HTML 与秘密传递边界，不证明真实 WSS/TLS/RFB 握手；真实上游机器操作需另有明确目标和范围。人工改日期／续费价／过户、手工开通、代客登录、未实现驱动不在本批。

IMPLEMENTED: 工作台、管理员机器协议、显式事实同步、Paid失败原任务重试、权限角色及预览定向部署、工程／隔离浏览器验收、12张截图与证据归档、临时环境清理。
NOT DONE: 人工改到期日／续费价／过户、手工开通、代客登录和真实机器操作。
DECISIONS: 按C4合同；本地只读GET，实际管理员审计，旧客户端／任务／未决核对合同保持。
CONCERNS: 上游受理不等于完成；同步事实可能更新到期日；模拟VNC不等于真实RFB连通。
GATES: Pest3159/27784、PHPStan0、Pint、Node及diff PASS；36布局／20弹窗／31权限、8表单动作＋独立分页、18HTTP、GET60表／操作后55表保持、12预览健康与权限、12PNG完整性及SHA-256通过。
SPEC CASES: 0（不修改）。
