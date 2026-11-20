# C2 客户基础管理实施记录

日期：2026-11-20。基线：`2a5a5c8`。对应[批准合同](admin-customer-management-c2-contract-2026-11-20.md)。本批在 C1 客户查询中心上接入姓名／邮箱／手机号编辑、管理员发起标准密码重置、独立账户停用／恢复与详情锚点；新增一条用户状态迁移，零新增依赖或前端构建链。工程门禁与隔离浏览器验收通过，预览已完成备份迁移、定向赋权与运行健康核验，11 张截图及验收明细已归档。

## 1. 数据、权限与目标范围

迁移 `2026_11_20_000019_add_customer_access_state_to_users.php` 只增加 `users.disabled_at` 与 `users.auth_revision`，默认分别为 `null` 与 `0`。访问停用独立于注销／匿名化；认证版本用于撤销旧会话，恢复访问时不回退版本。不增加全局 User 查询过滤，历史业务仍能读取原所有者。

新增固定 `customer-operator` 角色，包含 `customers.view`、`customers.manage`、`customers.password-reset`、`customers.status.manage`；「日常运营」预设由客户只读角色换为客户管理角色，「财务只读」继续只读，「全部业务权限」纳入新角色。修改预设不会批量改变已有账户角色。

三个写入口同时要求客户查看与对应专权，由 `AdminCustomerAccess` 在事务内按用户 ID 升序锁定操作者和目标，再检查双方当前状态、权限与目标资格。只允许管理普通客户；本人、拥有后台角色或直接权限的账户、已注销／匿名化账户都不能通过客户管理入口编辑、重置或停用／恢复。停用的普通客户可编辑与恢复，不能发送密码重置链接。

| 入口 | 专权 | 可写内容 |
| --- | --- | --- |
| `POST /admin/customers/{customer}/profile` | `customers.manage` | 姓名、邮箱、手机号、可选备注 |
| `POST /admin/customers/{customer}/password-reset` | `customers.password-reset` | 可选备注；收件人与重置参数由系统确定 |
| `POST /admin/customers/{customer}/access` | `customers.status.manage` | 显式 `disabled` 布尔值、可选备注 |

资料编辑不接收余额、实名、角色、密码或访问状态字段。手机号可清空，非空沿用 E.164 与既有全身份唯一规则；邮箱归一化并沿用大小写兼容唯一检查及数据库唯一索引。所有备注可选，最长 255 字符。

## 2. 资料、验证证明与标准重置

姓名单独改变保留客户当前会话。邮箱／手机号实际变化时，提升认证版本并撤销既有会话和 remember 凭据；新联系方式不自动验证。

邮箱变化清除原邮箱验证状态、旧邮箱密码恢复 token 与 email／mail 验证绑定。手机号变化废弃旧 SMS 绑定与 OTP，并复用现有 phone-binding 缓存锁，在用户事务前取得互斥，避免已读取的旧验证码继续完成绑定。Telegram、OAuth、实名、历史通知与其他无关业务事实保持原归属。

管理员重置复用 `CustomerIdentityAction` 与原密码 broker：按目标 ID 确定当前登记邮箱，保存哈希 token，沿用 60 秒节流、60 分钟有效期及可信 `app.url`，事务外发送邮件。管理员不能输入客户新密码、token、链接或任意收件地址；完成重置继续走原身份流程，不自动登录、不验证邮箱，并提升认证版本。

反馈区分通道受理、近期节流、未获确认与未知结果；通道接受不表述为最终送达，未知结果不自动重发。审计保留实际管理员与请求上下文，只记录变更键、验证失效、会话撤销、访问状态、认证版本及发送状态，不记录联系方式明文差异、密码、token 或重置链接。

## 3. 访问停用与原业务收口

停用撤销会话、remember 凭据与恢复 token，并提升认证版本；同状态重复提交保持幂等。恢复只恢复访问许可，旧会话、旧恢复 token 与旧验证证明不会恢复。停用保留身份、余额、账单、服务、角色和历史归属。

`EnsureAccountAccess` 在普通 Web 与 Filament／Livewire 认证栈校验账户状态和会话版本；新登录登记当前版本，既有零版本会话兼容。密码、OAuth／Telegram 共有身份流程及面板准入拒绝停用账户；已注销账户访问后台保留原 403 契约，同时撤销会话。未安装、无会话请求与公开回调沿用原入口边界，不因访问检查引入安装前数据库依赖。

新的人为购买、充值、续费／升级、续费恢复／钱包分配和新支付尝试，在外部请求前及用户锁内校验访问许可；保留原已建请求的幂等读取和金融锁序。已经通过准入并提交的外部动作仍按原事实收口，不承诺取消全部在途请求。

停用不是停服：历史支付回调继续按原账单与所有者验签入账，已付款履约、可信自动续费出账、同步及到期生命周期继续现行合同。不新增自动退款、取消履约、转钱包、清空通知 outbox 或盲目重发外部请求。

## 4. 客户页面与查询边界

客户列表加入独立「已停用」状态；默认「正常」不包含已停用，原注销筛选保留。全局搜索客户描述增加已停用状态，已注销优先显示，搜索域与原有授权不扩大。

详情根据专权和目标资格显示「编辑资料」「发送重置链接」「停用／恢复访问」，全部复用 `x-admin.dialog`。固定 `_admin_form` 与客户 ID 双重匹配后，才恢复对应弹窗的非秘密标量输入；数组等非法输入不会造成页面渲染错误。重置弹窗只展示登记邮箱与影响说明，不提供密码、token 或任意收件人输入。

概要后使用纯 HTML 锚点连接资料、服务、账单、流水、工单、实名、通知与审计；只输出有权查看的区块，各明细分页带本区块 fragment。沿用六类、31 个全局左侧入口，不增加内层侧栏、分类 Tab、客户端路由或构建链。

资料投影仅增加 `disabled_at`，不向页面或搜索返回 `auth_revision`。C1 的分域查询、安全字段选择、钱包三列与全驱动有效服务计数继续保留；页面 GET 不发送重置邮件、不改变账户状态、不请求供应商。

## 5. 自动化与预览部署

下表登记最终工程门禁、专项与隔离浏览器结果；各专项存在覆盖重叠，不能相加作为全量数量。

| 检查 | 当前确认结果 |
| --- | --- |
| C2 页面专项及 C1 原客户中心回归 | **60 passed / 600 assertions**；覆盖权限、目标保护、表单恢复、非法类型、锚点、停用筛选及角色预设 |
| 认证定向回归 | **60 passed / 438 assertions**；最终认证实现亦纳入下列全量门禁 |
| 新业务准入与原履约回归 | **188 passed / 1,794 assertions**；使用隔离测试事实与模拟对端 |
| 全量 Pest | **2,751 passed / 24,629 assertions**；保留原资金、隐私、身份、幂等严格断言与 Specs |
| PHPStan／Pint | **0 errors／PASS** |
| JavaScript 语法／差异检查 | `node --check public/js/admin-search.js`、`git diff --check` 均 **PASS** |
| 浏览器页面布局 | **32 组通过**：8 个页面场景 × 桌面／手机两视口 × 浅色／深色 |
| 弹窗与原生锚点 | **12 项弹窗、8 项锚点通过**；含资料、重置、停用与验证失败回填 |
| 浏览器角色边界 | **15 项通过**，包括三个写入口对无对应权限者返回 403 |
| 真实 HTTP／CSRF 写操作 | **44 项通过**：资料、标准重置、停用／恢复及相关会话与凭据检查；使用隔离合成客户 |
| 页面错误与只读数据 | **pageErrors=0**；只读验收比较 58 张表，**0 变化** |
| 写操作事实范围 | 结束时仅 `users`、`audit_logs`、`user_notification_bindings` 变化；三列钱包、账单、服务、实名历史不变，旧恢复 token 已清空 |
| 本地邮件验收 | 最终连续两次重置请求只新增 **1 封**，重复节流新增 **0 封**；另有初始通道探测 **1 封**，合计 **2 封**，全部 loopback SMTP，无外发 |
| 共享预览健康 | **6 项通过**：`/up`、`/admin/login` 为 200，匿名客户页 302、匿名 JSON 搜索 401；只读管理员 #2 客户列表与详情均为 200，主区 POST 表单为 0 |
| 截图归档 | **11 张 PNG** 完整性检查通过，尺寸及 SHA256 记录于验收 JSON |

共享预览先在隔离副本验证迁移，再完成一致性 SQLite 备份与正式迁移；57 张既有业务表的原有数据保持，`migrations` 登记表按预期新增一条，现有用户新增列默认值为 `null`／`0`。通过原审计角色部署，仅为完整业务管理员 #3 增加 `customer-operator`；#2 保持客户只读，其他角色绑定保留。系统审计已登记，赋权前后用户、账单、实例与流水哈希不变。本批不向真实客户发重置邮件、不修改真实客户资料，公开文档不保存私有备份路径或测试口令。

浏览器使用隔离数据库与合成资料，未调用供应商、未使用外部 SMTP。网络仍观察到既有 `ui-avatars.com` 头像请求，因此不将本批写成“零外部请求”。本地 SMTP 证明表单、broker 与发送链路的行为，不代表真实客户邮箱最终送达。

验收后已关闭本批浏览器、隔离 HTTP／SMTP 端口，删除临时 SQLite、storage 与含口令的测试元数据；共享预览继续运行，`/up` 仍为 200。清理结果已登记于验收 JSON，未关闭共享服务。

## 6. 截图与保留范围

本批 11 张截图全部来自合成数据，归档于 [admin-customer-management-c2-2026-11-20](screenshots/admin-customer-management-c2-2026-11-20/)。[截图索引](screenshots/admin-customer-management-c2-2026-11-20/README.md)列出尺寸，[验收明细与 SHA256](screenshots/admin-customer-management-c2-2026-11-20/acceptance.json)保存工程门禁、浏览器检查、数据比较、预览部署与每张原始 PNG 的哈希。全部 PNG 已通过完整性检查。

- [客户列表／桌面浅色](screenshots/admin-customer-management-c2-2026-11-20/customers-1440-light.png)、[客户详情／桌面浅色](screenshots/admin-customer-management-c2-2026-11-20/customer-detail-1440-light.png)、[客户详情／手机深色](screenshots/admin-customer-management-c2-2026-11-20/customer-detail-390-dark.png)。
- [资料弹窗／桌面浅色](screenshots/admin-customer-management-c2-2026-11-20/profile-1440-light.png)、[失败回填／手机深色](screenshots/admin-customer-management-c2-2026-11-20/profile-recovery-390-dark.png)、[标准重置／手机深色](screenshots/admin-customer-management-c2-2026-11-20/password-reset-390-dark.png)。
- [停用弹窗／手机深色](screenshots/admin-customer-management-c2-2026-11-20/disable-390-dark.png)、[停用后详情／手机深色](screenshots/admin-customer-management-c2-2026-11-20/disabled-after-action-390-dark.png)、[停用详情／桌面深色](screenshots/admin-customer-management-c2-2026-11-20/disabled-detail-1440-dark.png)。
- [停用筛选／手机深色](screenshots/admin-customer-management-c2-2026-11-20/disabled-list-390-dark.png)、[受保护后台身份／桌面深色](screenshots/admin-customer-management-c2-2026-11-20/protected-detail-1440-dark.png)。

桌面／手机的浅色及深色均已纳入 32 组布局验收；截图是本批代表场景，不表示每个矩阵组合各有一张 PNG，也不代替真实供应商或外部邮件验收。

本批不包含 C3 调账、C4 实例／账单写操作、代客登录、人工实名、真实客户重置邮件外部验收或新增账户注销规则。既有资金、隐私、身份、幂等严格断言与 Specs 保留；只对本批新增状态／权限明确取代的旧预期做定向调整。

新表单仍可能显示 Laravel 默认英文验证错误，属于非阻断文案现状，本批不扩展通用翻译改造。联系方式变更后的重新验证、恢复后重新登录、未知发送结果不自动重发等影响已在表单及反馈说明。

IMPLEMENTED: 客户资料编辑、管理员标准重置、独立停用／恢复、会话版本撤销、认证与新业务准入、角色预设、详情锚点、原子审计、全量与隔离浏览器验收、预览备份迁移及定向赋权、健康检查与11张截图归档。
NOT DONE: C3／C4、代客登录、人工实名及真实重置邮件验收不属本批。
DECISIONS: 按批准 C2 合同新增两列；普通客户管理与后台身份管理隔离；历史已承诺业务按原事实收口。
CONCERNS: 联系方式变更后客户须重新登录并验证；恢复不复活旧会话；通道接受不代表送达；既有头像外部请求与英文验证提示保留。
GATES: Pest2751/24629、PHPStan0、Pint、Node语法及diff PASS；32布局／12弹窗／8锚点／15角色／44真实HTTP与CSRF检查、pageErrors0；预览6项健康、11PNG完整性与SHA256归档通过。
SPEC CASES: 0（不修改）。
