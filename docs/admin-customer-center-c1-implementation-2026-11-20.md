# C1 客户查询中心与后台全局搜索实施记录

日期：2026-11-20。基线：`fb70879`。对应[批准合同](admin-customer-center-c1-contract-2026-11-20.md)。本批客户列表、客户聚合详情与顶部全局搜索已完成；全部读取本地业务事实，零数据库迁移、零新增依赖或前端构建链。

## 1. 客户入口与聚合详情

- `/admin/customers` 增加在「运营与内容」，保持单一全局左侧导航，合计六类、31 个入口；客户详情归属同一菜单高亮。
- 列表每页 20 条，支持精确 ID／完整邮箱、姓名关键词、实名与账户状态筛选。姓名的 `%`、`_`、`!` 按字面匹配，默认正常账户，已注销账户显式筛选。
- 列表与详情按权限展示可用余额、账单冻结、结清冻结三列，以及各驱动本地 `Active`／`Suspended` 有效服务数。没有财务或上游查看权限时，相应字段和聚合不查询、不渲染。
- `/admin/customers/{customerId}` 聚合安全资料、服务、账单、流水、工单、已有脱敏实名历史、通知元数据与账户自身审计，各明细每页 10 条、独立分页。非法或不存在的客户路径返回 404，不退回其他客户。
- 已注销账户保留匿名化后的身份与历史事实，不恢复原邮箱、手机号或身份资料，也不显示账户角色入口。实名只有已有脱敏摘要，通知不读取正文、目标或错误原文。
- 账户审计同时限定 `subject_type=User::class` 与客户 ID，并使用既有 `AdminAuditProjection` 白名单。明确标注这是账户自身审计，不能代替完整跨资源日志。

详情中的服务、账单、流水、工单、通知、角色与审计链接进入原后台页面。`UpstreamInstances` 补齐客户列表／选中详情筛选；`FinanceLedger` 同时约束流水及可核实账单归属的支付回调，未知归属回调不混入指定客户；`AdminAudit` 新增精确对象类型筛选，避免同数字 ID 的不同模型日志串户。原分页、权限与安全投影继续保留。

## 2. 权限与角色

新增固定 `customer-viewer` 角色，仅含 `customers.view`，同步角色标签、Web 可分配清单、面板准入及「日常运营」「财务只读」「全部业务权限」预设。预设更新不会自动改变已有用户的角色。

客户基本资料与脱敏实名历史要求 `customers.view`；钱包／账单／流水要求额外 `finance.view`，服务要求 `upstream.view`，工单、通知、审计和角色入口分别沿用原模块权限。没有新增万能超级管理员 Gate。

完成隔离验收后，通过原 `admin:role` 审计命令，仅为共享预览的已知后台测试账号 #2 和完整业务管理员 #3 增加 `customer-viewer`。核对只有两条角色新增、无角色删除，两条系统审计记录落库；用户记录及其他账号角色未变，包括 #4、#5 与普通客户。

## 3. 顶部全局搜索

`GET /admin/search` 在认证和业务授权后处理输入，各域先授权再查询，每域最多 5 条：

| 权限 | 查询内容 | 跳转 |
| --- | --- | --- |
| `customers.view` | 客户精确 ID／完整邮箱，含保留的匿名化记录 | 客户详情 |
| `finance.view` | 账单精确 ID／完整账单号 | 原账单详情 |
| `upstream.view` | 服务精确本地 ID／完整上游 ID | 经典服务进入原实例详情；其他驱动同时要求 `customers.view`，进入所属客户服务分区 |

同数字 ID 按客户、账单、服务分组；不同连接下相同上游 ID 分别展示连接与本地编号。不具备客户查看权限时，财务／服务搜索不关联查询客户姓名和邮箱。超出本地整数范围的数字仍可作为上游 ID 搜索，不截断成本地 ID。

客户端使用原生 `dialog`、250ms 防抖、取消请求及响应版本检查，清空输入不发搜索请求，旧响应不覆盖新结果。支持按钮、Ctrl／Cmd+K、上下方向键、Enter、Esc 和关闭后焦点返回。浏览器实测发现 Chromium 的 `type=search` 输入会先消耗 Esc，已显式处理 Esc 关闭对话框。搜索响应使用 `private, no-store`。

## 4. 自动化与运行验收

| 检查 | 结果与证据范围 |
| --- | --- |
| 全量 Pest | **2,654 passed / 23,694 assertions**；旧资金、权限、隐私严格断言及 Specs 保留 |
| PHPStan | **0 errors** |
| Pint | **PASS** |
| JavaScript 语法／差异检查 | `node --check public/js/admin-search.js`、`git diff --check` 均通过 |
| 浏览器布局矩阵 | **36 组通过**：9 个路径 × 桌面 1440×1000／手机 390×844 × 浅色／深色；含列表、精确筛选、通配字符字面筛选、注销、详情、多明细第二页与空态 |
| 浏览器角色边界 | **16 项通过**：客户只读、纯财务、纯上游、普通客户的三条客户路径及搜索分域；无权字段与操作不呈现 |
| 业务直达 | **8 条 HTTP 200**：返回列表、账户角色、经典服务、账单、流水、工单、通知、账户审计；目标筛选效果另有 Feature 验证 |
| 搜索真实交互 | **20 项通过**：双连接同号、数字分组、完整邮箱／账单跳转、非经典服务锚点、方向键／Enter／Esc／焦点、清空零请求、防抖、手机明暗布局等；旧响应乱序使用本地拦截响应模拟 |
| 页面错误／业务写入 | 浏览器无 page error；36 组矩阵及业务直达前后所有业务表哈希相同，排除会话／缓存表；敏感字段诱饵未进入 HTML |
| 共享预览 | `/up`、`/admin/login` 为 200；匿名客户页 302 到后台登录，匿名 JSON 搜索 401；原测试账号 #2 正常密码登录后，客户列表、详情、分域搜索均 200 |

浏览器使用独立 SQLite／storage 与合成数据，未执行真实资金、供应商或通知操作。Feature 检查新增客户页与搜索 GET 无业务 HTTP 请求与完整应收核验；浏览器网络记录仍观察到既有 Filament 头像资源 `ui-avatars.com`，不将其表述为“页面没有任何外部资源请求”。验收截图全部来自合成数据，不能视为供应商或真实财务验收。

搜索 Esc 修复后重新执行全部 20 项搜索交互及 JavaScript 语法检查；此前 PHP 全量门禁对应同一最终 PHP 实现。

验收完成后仅关闭本轮 Playwright 会话与经 PID／命令／工作目录核验的独立测试进程，临时数据库及含测试口令的元数据已删除，44981 端口关闭；原共享预览 59493 继续运行，健康检查为 200。

## 5. 截图与证据

截图目录：[admin-customer-center-c1-2026-11-20](screenshots/admin-customer-center-c1-2026-11-20/)。11 张原始 PNG 均通过图像完整性检查，复制前后 SHA256 一致；[SHA256SUMS](screenshots/admin-customer-center-c1-2026-11-20/SHA256SUMS) 与[浏览器验收明细](screenshots/admin-customer-center-c1-2026-11-20/browser-acceptance.json) 一并保存。

- [客户列表／桌面浅色](screenshots/admin-customer-center-c1-2026-11-20/customers-desktop-light.png)、[详情／桌面浅色](screenshots/admin-customer-center-c1-2026-11-20/customer-detail-desktop-light.png)。
- [筛选／桌面深色](screenshots/admin-customer-center-c1-2026-11-20/customer-filter-desktop-dark.png)、[注销详情／桌面深色](screenshots/admin-customer-center-c1-2026-11-20/closed-detail-desktop-dark.png)。
- [详情首屏／手机深色](screenshots/admin-customer-center-c1-2026-11-20/customer-summary-mobile-dark.png)、[完整详情／手机深色](screenshots/admin-customer-center-c1-2026-11-20/customer-detail-mobile-dark.png)、[注销列表／手机深色](screenshots/admin-customer-center-c1-2026-11-20/closed-list-mobile-dark.png)。
- [搜索／桌面浅色](screenshots/admin-customer-center-c1-2026-11-20/search-desktop-light.png)、[桌面深色](screenshots/admin-customer-center-c1-2026-11-20/search-desktop-dark.png)、[手机浅色](screenshots/admin-customer-center-c1-2026-11-20/search-mobile-light.png)、[手机深色](screenshots/admin-customer-center-c1-2026-11-20/search-mobile-dark.png)。

## 6. 保留边界

C1 只读客户查询与业务衔接，不含资料修改、账户封禁、重置密码、代客登录、手工实名、调账、手工标记已付、服务过户或运维动作。钱包未执行应收核验，不据此判断客户无欠款；各驱动只读元数据不表示新增生命周期支持。

IMPLEMENTED: 客户列表与聚合详情、服务端分域搜索、真实筛选直达、角色预设与定向预览赋权、六类31入口、验收与截图。
NOT DONE: C2–C4 写操作及资金状态机、真实外部业务验收。
DECISIONS: 按批准 C1 合同独立落地；现有权限按域生效，原路径与业务动作保留；零迁移、零构建链。
CONCERNS: 旧角色不会自动取得 customers.view；账户审计非跨资源完整审计；非经典服务仍无独立后台操作工作台。
GATES: Pest 2654/23694、PHPStan0、Pint、Node语法、差异检查、36布局／16角色／8直达／20搜索交互、11PNG完整性与SHA256通过。
SPEC CASES: 0（未变）。
