# C4 实例工作台实施合同

日期：2026-11-20，基线 `1f0d38c`。用户验收 C3 后明确指示按独立实例工作台、管理员机器操作、显式上游同步和既有失败任务重试范围推进。零数据库迁移、零新增前端构建链；必要预览部署、隔离实测及自动提交沿用既有授权。验收仅使用模拟对端，不操作真实客户机器或发送真实通知；推送等待单独指令。

## 页面与读取

新增不注册导航的 `/admin/instances/{instance}`，映射回既有上游服务导航，保持六类、31 个全局左侧入口，不增加内侧栏或分类 Tab。保留 `/admin/upstream-instances?instance=...` 简要详情与旧操作路由，新增工作台直达。基础元数据要求 `upstream.view`，可读取全部现有驱动的本地安全事实；经典列表与原全局搜索的驱动／权限边界保持，不顺带扩大检索范围。PVE 未交付和非经典远端控制不可因出现详情页而宣称可用。

主区纵向呈现概况、归属／商品、配置与计费／到期事实、明确操作、关联账单、履约任务和工单。基础查询只选安全列，不选择／解密 `encrypted_credentials`、`downstream_token`、订单秘密、任务 payload 或原始错误文本。配置只投影非秘密的系统配置与公开硬件事实，不展示任意 active_config/custom_field_values JSON。客户链接、账单、任务、工单分别先检查 `customers.view`、`finance.view`、`tasks.view`、`support.view` 后查询／渲染；明细各自有界分页。普通 GET 不解密、不请求上游、不加载机器菜单／监控，也不完整核验应收。新工作台的任务区独立要求 tasks.view；旧经典列表沿用 upstream.view 下的既有队列元数据与转义诊断契约，仅在提供 user_id 时收窄原客户范围，不改写旧列表授权规则。

## 管理员操作身份与权限

新增固定 `upstream-machine-operator`（`upstream.view`＋`upstream.machine-control`）和 `upstream-sync-operator`（`upstream.view`＋`upstream.sync`）；原 `upstream.lifecycle` 不自动拥有机器控制或同步。全部业务及上游运维预设包含新角色，预设不批量赋权。共享预览仅给管理员 #3 追加两角色，#2 和其他绑定保持。

新机器操作明确区分原 owner 与实际 actor，不登录客户或伪造审计身份。按用户 ID 升序锁原 owner 与管理员，再锁实例／任务；提交前校验当前管理员有效与专权、原归属／绑定／启用账户、Active、无终止申请及未决任务。已停用但未注销的 owner 允许管理员维护既有服务；已注销／匿名化主体禁止新远端请求。已提交结果沿原 owner/actor 留存并收口，中途停用／撤权不删除结果审计，返回菜单／VNC 等敏感响应前必须再次鉴权。

保留客户现有公开 API 与严格断言，抽取内部共用协议执行，不把 owner 传入客户执行器冒充管理员。继续共用 `customer-machine-control:{instance_id}` 互斥锁、原 `cloud_operation` 意图、`instance.machine.request.*`／`result.*` 审计与 `MachineOperation` 未决判断；请求 ULID 永久防重复。HTTP 在事务外，提交和结果各自原子记录实际管理员及 AdminAuditContext，不新增资金写入或机器命令自动重试。

菜单只经明确的带 CSRF POST 读取现有 cloud_controls header，八类命令仅显示上游广告能力。开机／VNC保持简洁；关机、重启和硬操作需中断确认；重装／救援需独立备份确认。OS 分类与版本、端口、格式化能力执行前按最新 header 再验，救援密码沿现有规则且不落日志／审计／old-input。新管理员备注可选、最长255字符，缺省生成稳定说明。未交付的 crack_pass、exitRescue 等不补猜测实现。

`requested/unknown` 保留原机器操作核对闭环、五分钟保护期及明确人工任务证据，不自动清除、不盲重放、不将受理写为完成。VNC 使用独立管理员签名路由，五分钟、绑定实际 actor ID／认证修订／实例绑定／连接摘要；继续 URL 片段传连接，GET 不接收凭据，复用本地 noVNC 及 no-store/no-referrer/CSP。保留原客户签名路由与行为，不提供控制面板代客登录或通用凭据读取。

## 同步与重试

显式同步只支持已验证的经典魔方绑定；带 CSRF、限流的 POST 通过 `DriverManager → CallUpstreamAction → syncInfo → ApplyInstanceSnapshotAction`，事务外拉取、锁内重验实际管理员、原 owner、完整绑定与账户指纹，原子应用上游事实和管理员审计。同步可更新上游报告的状态、配置、到期日及原加密交付信息，这是同步原事实，不是人工改价／改日期。终态不复活，原未决机器意图及人工核对记录保持；非经典仅本地读，不显示虚假“同步上游”。

失败任务重试复用原 `RetryFulfillmentTaskAction` 和三个旧 POST，不复制任务或 payload、不伪造付款、不放行未知远端意图。经典保持 `upstream.view + upstream.lifecycle`，本地未交付卡保持 `inventory.view + inventory.retry`；工作台仅对当前实例、已 Paid 账单的失败交付提供入口。Action 锁内重读并核对实际 actor、原 owner、账单／实例／任务归属与绑定，保持 users→invoice→instance→task 锁序及 afterCommit 派发；原生命周期和可信 CLI 路径保留既有范围。任务查看仍独立要求 tasks.view。

## 验收与后续

保留旧金额／身份／权限／隐私／幂等断言和全部 Specs，不修改旧迁移。验证本地 GET 与分域查询、无权限403、未知ID404、明细分页、秘密不渲染／不回填、实际管理员审计、能力菜单／危险确认、重装OS配对、救援密码、VNC身份和短签名、未决核对、网络期间换绑／撤权、同步不复活／不清未决、Paid失败任务重试及事务回滚。运行全量 Pest、PHPStan、Pint、JS/diff及隔离桌面／390px明暗真实HTTP/CSRF验收，归档合成截图、清理隔离环境；预览只部署代码／角色并验证健康，不实际请求上游。

改本地／上游到期日的商业规则、改续费价、服务过户、手工开通、未交付驱动、代客登录、人工实名、真实机器变更不属本批。新工作台只汇总并接通上述边界，不替代未实施能力。

IMPLEMENTED: C4工作台、实际管理员机器协议、显式事实同步、Paid失败原任务重试、专权角色、定向预览部署、工程和隔离浏览器验收；见[实施记录](admin-instance-workbench-c4-implementation-2026-11-20.md)。
NOT DONE: 人工改到期日／续费价／过户及真实机器操作。
DECISIONS: 本地只读工作台；显式管理员身份；既有协议／未决核对／同步事实与重试范围保持。
CONCERNS: 上游受理不等于完成；同步事实可更新到期日；未知结果必须人工核对。
GATES: Pest3159/27784、PHPStan0、Pint、JS/diff PASS；36布局／20弹窗／31权限、8表单动作＋独立分页、18HTTP及12预览检查通过。
SPEC CASES: 0（不修改）。
