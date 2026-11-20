# 后台库存、清算与运维页面实施记录（B2）

日期：2026-11-20。基线：`3b75f3e`。页面边界见 [B2 页面合同](admin-inventory-finance-operations-ui-contract-2026-11-20.md)。十二入口改造、专项回归、全量门禁及隔离浏览器验收已完成。

## 实施范围

本批翻新以下十二个全局导航入口，继续使用现有 Filament 外壳、`x-admin.card` / `filters` / `table` / `badge` / `pagination` / `dialog` 和 `admin-operations.css`。没有数据库迁移、新依赖或前端构建链；六个可折叠全局分类、三十个入口保持，内容区没有新增侧栏、主分类 Tab 或手机分类下拉。

| 入口 | 实施内容 |
| --- | --- |
| `/admin/inventory` | 本地卡池库存概览改为商品搜索、库存徽章、紧凑表格和分页；保留库存与交付入口 |
| `/admin/inventory/cards` | 商品 / 状态 / 批次筛选、卡密元数据表格；导入移入对话框，卡密始终为空并提示失败后重新粘贴 |
| `/admin/fulfillment` | 已付待交付列表展示库存候选、尝试次数及失败原因；原有失败任务重试进入对话框 |
| `/admin/finance/ledger` | 财务流水与支付回调分卡展示；保留金额、余额、幂等引用、应收投影和筛选作用范围说明 |
| `/admin/finance/receivables` | 应收列表、选中记录事实、清偿 / 冲正历史使用公共组件；登记、完整冲正和人工复核进入独立对话框 |
| `/admin/wallet-clearances` | 申请列表、注销身份来源案件和阶段操作分开；注销身份登记与阶段操作使用对话框 |
| `/admin/external-payouts` | 外部佣金提现状态筛选、申请列表、执行标识和阶段操作对话框，复用钱包结清的展示结构 |
| `/admin/reconciliations` | 案件表格、选中案件事实和追加处置历史；补记、重新打开及两种原资源处置使用独立对话框 |
| `/admin/tasks` | 全状态任务与失败队列元数据分卡展示；符合原权限、驱动和失败状态的重试进入对话框 |
| `/admin/access` | 账户查找、当前角色列表、可展开固定角色说明；角色批量调整进入对话框，保留角色预设和并发检查 |
| `/admin/audit` | 精确筛选、只读审计表格和可展开前后快照，继续使用既有白名单投影 |
| `/admin/notification-outbox` | 持久事件与渠道投递分卡；中文渠道 / 状态徽章，仅待核对投递提供人工核对对话框 |

本批范围是这十二个导航入口，不代表所有后台钻取页都重新设计。`machine-operation-review` 等附属页面保持原实现；既有财务、实例、商品配置和受审计敏感详情入口继续沿原路径访问，不因此扩展改版范围。

## 原业务与错误恢复

保留全部旧 GET / POST 路径、路由绑定、CSRF、业务字段、查询与分页参数。筛选条只呈现已有查询支持的条件；多列表分页继续携带当前筛选和记录参数。案件、应收的错误恢复可以定位原提交记录，显式 URL 中选择另一条记录时优先展示新选择，避免旧错误覆盖当前记录。

同页表单使用固定 `_admin_form` 标记，并同时匹配记录及操作。失败只重开对应对话框，恢复允许的金额、备注、选项、角色、非秘密记录字段及本次请求标识；同页其他记录、其他出款阶段及其他案件处置不混用旧输入。角色清空选择在失败后仍保持空选择，`expected_roles[]` 保留原并发比较依据；本人角色和服务器专属角色继续不能从 Web 修改。没有该标记的旧直接 POST 仍显示全页错误反馈。

财务操作继续由原 Controller / Action 校验权限、身份、金额、状态、版本、幂等、冻结和证据。应收保留完整核验预算、进度入口及未知状态提示；完整冲正只更正应收登记，不向外部发起退款。钱包结清、外部提现保留批准冻结、执行意图、实际付款凭证及未知结果核查；未知款不自动释放或重新执行。受款详情仍通过原受审计 POST 查看，不在列表 GET 解密。

任务重试只调度原失败任务，保留本地 / 经典上游权限及驱动条件；待执行和运行中任务不开放强制重置，未知远程意图不因 UI 改版获得重放许可。通知人工核对保留 `worker_stopped`、`not_accepted`、证据和确认要求；“已受理”仅表示传输接受，不表示用户已收到。案件保留来源、版本、原始原因、追加历史及原证据读取入口，结案仍取决于实际入账、清退或资源处置事实。

卡密、证据、收款资料、外部交易号、冲正引用、接收主体和责任接受凭证等继续遵守全局 `dontFlash`。错误后的相应表单提示重新输入，不为方便回填放开秘密闪存。卡密元数据不提供明文查看或导出；回调不展示原始 payload，失败队列不展示 payload / exception，通知不展示收件人和消息快照，审计不绕过既有投影。金额使用原定点字符串，不在模板重算。GET、打开和取消弹窗不调用供应商、支付或通知，也不新增业务资金动作；应收原有完整核验流程保持。

## B1 文案与校验收尾

工单会话副标题在没有关联实例时显示“未关联服务”，状态与列表统一为待回复 / 已回复 / 已关闭；通知投递日志的渠道及状态采用中文映射，并保留未知值回退。“已受理”的含义没有改为已送达。

下载资源保存 Action 为外部下载地址的必填、HTTPS URL 格式及长度校验补充中文提示。没有改变原下载方式、访问级别、文件保存或外链规则，也没有全局切换 Laravel 校验语言。

CMS 的 Web 备注校验和 `SaveCmsArticleAction`、`SaveCmsCategoryAction`、`UpdateProductPresentationAction` 三个保存 Action 统一限制 255 字符，匹配 `audit_logs.reason`。三个 Action 将原因规范化后使用同一值校验和持久化，避免校验时 `trim()`、写入时仍使用原始带空白字符串的差异。超长原因明确拒绝，不截断说明、不扩展数据库列、不部分写入文章 / 栏目 / 商品。B1 曾登记的后端 1000 / UI 与审计列 255 差异由本批定向收口。

## 已执行专项与最终门禁

| 检查 | 当前证据 |
| --- | --- |
| 开工基线 | 全量 Pest **2,531 passed / 22,387 assertions**；PHPStan 0 errors；Pint PASS |
| 新库存与任务 UI 专项 | **16 passed / 152 assertions**，覆盖卡密不回填、原重试条件、只读权限、表单恢复与分页 |
| 新清算 UI 专项 | **12 passed / 294 assertions**，覆盖清偿 / 冲正及出款对话框、秘密重输、记录 / 阶段隔离与原操作条件 |
| 新账户、案件与 outbox UI 专项 | **25 项**；与原角色选择、后台操作、通知 outbox、孤儿资源回归合跑 **119 passed / 953 assertions**，日志 `/tmp/phpidc-b2-access-own-final.log` |
| B1 细节及 CMS 收尾 | 新增 **12 项**；与原 CMS / B1 UI 回归合跑 **47 passed / 634 assertions** |
| 最终全量 Pest | **2,596 passed / 23,191 assertions**，180.12 秒；日志 `/tmp/phpidc-b2-pest-final.log` |
| 最终 PHPStan / Pint / 差异检查 | **0 errors / PASS / PASS**；日志 `/tmp/phpidc-b2-phpstan-final.log`、`/tmp/phpidc-b2-pint-final.log` |
| 展示微调后的定向回归 | 库存、权限案件与文案专项合跑 **53 passed / 510 assertions**；日志 `/tmp/phpidc-b2-targeted-final.log` |
| 浏览器桌面 / 手机、浅色 / 深色矩阵 | 十二入口 + 应收 / 案件选中详情，1440×1000 / 390×844 × 浅 / 深色，**56 组全部 200**；文档无水平溢出、无旧 box、无错误自动打开弹窗、无隐私诱饵泄露，脚本异常 **0** |
| 浏览器角色边界 | 上述十四页 × viewer / customer，**28 组通过**；viewer 200 且无 POST 表单，customer 403 |
| 原生弹窗及 HTTP / CSRF 回弹 | 卡密导入、角色调整、外部提现确认、通知核对四种无效提交均只恢复对应弹窗；应收登记打开 / 取消、角色 Escape / 取消焦点回到原触发按钮均通过 |
| 共享预览健康与匿名准入 | `/up`、`/admin/login`、`/css/admin-operations.css` 均 **200**；匿名 `/admin/inventory` **302** 至后台登录；只读检查 |

专项使用隔离测试数据和模拟对端；没有改写既有测试预期或 Specs，没有真实付款、出款、供应商变更或向业务收件人发送通知。本记录不将单元 / Feature 通过等同于浏览器、部署或真实外部验收。

浏览器使用独立 SQLite、storage、APP_KEY 与模拟账户。真实 session / CSRF 无效提交后核对数据库：客户仍为零后台角色、卡密仍六条、清偿仍两条，外部提现 #4 仍 `unknown / 20.00`，通知投递 #6 仍 `uncertain / 1` 次。角色预设由 Alpine 正确选择三个只读角色；回弹保留该选择。出款回弹保留实际金额与确认项，但不回填证据和交易号；通知回弹保留 `retry`、原因及三项勾选，但不回填证据。浏览器矩阵原始结果位于 `/tmp/phpidc-b2-browser-matrix.json`。验收结束关闭本次隔离站点与浏览器，不停止共享预览。

## 截图

截图均来自隔离模拟资料。桌面视口为 1440×1000，手机视口为 390×844；整页截图高度随页面内容变化。原始 PNG 原样归档，逐一用 Pillow 验证并核对复制前后 SHA-256 一致；页面及代表弹窗人工审阅通过。长表格允许容器内横滚，文档不横向溢出。

| 场景 | PNG 尺寸 | 截图 |
| --- | --- | --- |
| 库存概览 · 桌面浅色 | 1440×1000 | [inventory](screenshots/admin-inventory-finance-operations-2026-11-20/inventory-1440-light.png) |
| 卡密列表 · 手机深色 | 390×1264 | [inventory-cards](screenshots/admin-inventory-finance-operations-2026-11-20/inventory-cards-390-dark.png) |
| 财务流水 · 桌面浅色 | 1440×1466 | [ledger](screenshots/admin-inventory-finance-operations-2026-11-20/ledger-1440-light.png) |
| 外部提现 · 桌面深色 | 1440×1035 | [payouts](screenshots/admin-inventory-finance-operations-2026-11-20/payouts-1440-dark.png) |
| 履约任务 · 桌面浅色 | 1440×1336 | [tasks](screenshots/admin-inventory-finance-operations-2026-11-20/tasks-1440-light.png) |
| 操作审计 · 桌面深色 | 1440×1148 | [audit](screenshots/admin-inventory-finance-operations-2026-11-20/audit-1440-dark.png) |
| 通知 outbox · 手机深色 | 390×2026 | [outbox](screenshots/admin-inventory-finance-operations-2026-11-20/outbox-390-dark.png) |
| 账户角色 · 手机深色 | 390×1367 | [access](screenshots/admin-inventory-finance-operations-2026-11-20/access-390-dark.png) |
| 应收详情 · 手机深色 | 390×1520 | [receivable-detail](screenshots/admin-inventory-finance-operations-2026-11-20/receivable-detail-390-dark.png) |
| 案件详情 · 桌面浅色 | 1440×1042 | [case-detail](screenshots/admin-inventory-finance-operations-2026-11-20/case-detail-1440-light.png) |
| 角色错误回弹 · 手机深色 | 390×966 | [access-dialog](screenshots/admin-inventory-finance-operations-2026-11-20/access-dialog-390-dark.png) |
| 出款秘密重输 · 手机深色 | 390×844 | [payout-dialog](screenshots/admin-inventory-finance-operations-2026-11-20/payout-dialog-390-dark.png) |
| 通知核对回弹 · 手机深色 | 390×1642 | [outbox-dialog](screenshots/admin-inventory-finance-operations-2026-11-20/outbox-dialog-390-dark.png) |

## 保留限制

秘密字段和浏览器上传文件不恢复属于既有隐私与浏览器边界，不通过本批 UI 绕开。未知应收、未知出款和未知通知结果仍须按原流程核查；列表记录和操作受理不代表结清、付款、交付或最终送达。机器操作复核等附属钻取页未在本批翻新，原共享样式或反馈组件也不作无关重构。中文校验仅定向补充下载外链地址，其他既有 Laravel 默认校验仍可能显示英文；未在本批全局切换语言。

IMPLEMENTED: B2 十二个导航入口的公共组件、原生操作对话框与表单 / 记录 / 操作隔离恢复；B1 工单及通知文案、下载 URL 中文提示和 CMS 备注 255 字符与规范化一致性收尾。
NOT DONE: machine-operation-review 等附属钻取页未翻新；没有新增真实外部业务验收或全局校验中文化。
DECISIONS: 沿用六类三十入口与原业务权限 / 动作 / 金额 / 状态 / 幂等 / 核验边界；对话框只恢复允许输入，显式记录选择优先；零迁移、零依赖与构建链新增。
CONCERNS: 秘密字段必须重新输入；应收证据未知不能展示为无债，未知出款不释放，通知受理不表示送达；真实外部业务未在本批验收。
GATES: pest 2596 passed / 23191 assertions | phpstan 0 errors | pint PASS | diff PASS | post-polish targeted 53 passed / 510 assertions | browser layout 56 PASS | role boundary 28 PASS | HTTP / CSRF error recovery 4 forms PASS | 13 original PNGs verified。
SPEC CASES: 0（未变）。
