# 管理后台交付契约

2026-11-19 输入契约更新：网页角色管理已支持多选一次保存、中文角色名和常用组合；日常操作备注可选，由服务器生成非空审计说明。退款保留服务的豁免仍需实际原因和独立同意。下文单角色流程保留为兼容接口及原交付记录，当前规则见 [操作精简](usability-simplification-2026-11-19.md)。

日期：2026-11-17。本轮基线：Pest 351 passed / 2806 assertions，PHPStan 0 errors，Pint clean，Specs 0。

状态：代码与隔离验收已完成；未部署业务数据库或授权真实账号。

## 范围与既有能力

现有 Filament `/admin` 已有库存概览、加密导卡、已付缺货重试、CMS 与经典 ZJMF 页面。本轮补齐财务、集中任务监控、固定角色授撤和审计查询，继续使用已批准的五张 Spatie 权限表；不新增字段、表、枚举、DTO 或冻结接口。

## 页面、权限与操作

| 页面 | 权限 | 数据与行为 |
|---|---|---|
| 财务账单 `/admin/finance` | finance.view | 账单号、用户 ID、状态、金额、币种，筛选和分页 |
| 账单核查 `/admin/finance/invoice?invoice_id=` | finance.view | 已存金额快照、服务状态、支付尝试与回调元数据 |
| 全额退款 | finance.view + finance.refund + invoice-scoped refund-invoice Gate | 必填原因和确认；钱包退款或已完成外部退款的登记；保留服务必须独立勾选 |
| 财务流水 `/admin/finance/ledger` | finance.view | 只读 append-only 流水、唯一引用键、退款佣金应收投影、未知账单/异常回调；不提供直接改账或补发回调 |
| 任务监控 `/admin/tasks` | tasks.view | 全状态任务、次数、租约及失败队列元数据；不读取 payload、凭据、exception |
| 任务重试 | tasks.view + 既有 inventory.view/retry 或 upstream.view/lifecycle | 复用 RetryFulfillmentTaskAction；必须 failed、当前绑定有效、未退款并显式确认 |
| 账户角色 `/admin/access` | access.view / access.manage | 查看角色、按用户 ID 授予或撤销固定业务角色，非空原因与确认，事务内审计 |
| 操作审计 `/admin/audit` | audit.view | 时间、actor、动作、对象、原因、IP、请求 ID及白名单前后快照；不直接展示任意 JSON |

固定新增角色：finance-viewer（finance.view）、finance-operator（finance.view/refund）、task-viewer（tasks.view）、audit-viewer（audit.view）、access-manager（access.view/manage）。access-manager 仅允许受信服务器命令初始化/撤销，网页不能授予它。网页可管理上述业务角色及既有库存/CMS/上游固定角色，不能编辑任意权限、角色定义或直接权限。既有权限不隐式扩大；没有万能管理员 Gate 或默认账号。

`admin:role <用户ID> <固定角色> --reason=... [--revoke]` 为服务器初始化入口，记 system 审计；Web 记真实 actor。撤销一个角色保留其他角色和直接权限。网页不允许自授权/自撤权；实际权限管理账号的恢复留给服务器命令。新角色 Action 锁定实际 actor 和目标用户，核实账号与当前权限后写入角色关联和审计，不冒充旧 CLI 专用 Action。

## 金融与恢复边界

- 退款只调用 RefundInvoiceAction；沿用 Money、先锁用户、唯一 refund 引用、佣金撤销与应收登记（INV-W2/W3、INV-L1/L2、C10/C11）。不新增钱包调账入口，因为 INV-L2 尚未授权独立调账 Action。
- 服务终止必须已确认；保留服务是有权人员的单独明确豁免（INV-R1）。外部退款登记不是调用支付平台打款；须另勾选“外部退款已经完成”。已售卡密不回收（INV-R3）。
- 补货后 failed 任务仍需人工处理（INV-T3）。运行中/成功/stale 不重置；远程 Sub2API 不明发码不能通过通用重试绕过持久意图核对。经典重试保留原意图；UI 受理不等于上游交付成功。
- 页面只查询安全元数据；不把卡密、请求 payload、支付签名、上游凭据、失败 exception 或任意审计 JSON 放入 HTML/Livewire 属性。流水/审计无编辑删除入口（F01）。

桌面分页表格；窄屏任务/操作表单单栏，列表可局部横向滚动。所有操作使用 session、CSRF、服务器权限与 Action 再授权；表单显示空态、失败、重复提交与状态变化提示；后台 private/no-store。真实资金、业务库迁移、真实角色授予和上游变更不在本轮验收授权内。

## 验收要求

新增隔离测试验证普通客户/只读/操作员/权限管理员边界、撤权、伪造角色、审计原子性、金额快照与退款重复/模式冲突、保留服务与外部登记独立确认、敏感字段不查询/不序列化、任务状态限制和运行中租约保护。复用库存闭环验收，并在临时 SQLite/APP_KEY/storage/public 的 HTTP 服务验收真实 session/CSRF 和浏览器桌面/窄屏。SQLite 不证明 MySQL 并发行锁正确性。

## 实现与验证结果

- 新增 `tests/Feature/AdminOperationsTest.php`：35 个场景、330 条断言。覆盖不同角色的页面/POST/Livewire 边界，撤权立即拒绝；独立保留服务/外部退款确认；仅用已存总额退款，重复点击不重复入账、变更退款模式拒绝；资金或角色审计插入失败时事务完整回滚；原任务重试只重置 failed，不能重置运行中租约，不能绕过远程发码对账，经典上游意图保留不变。没有修改旧测试断言或 Specs。
- 财务查询仅投影金额和支付元数据，不读取支付 payload 或实例凭据；账单详情只以锁定的整数 ID 保留 Livewire 刷新上下文。任务页不查询 payload/error_message/exception，只显示安全队列元数据，并链接现有库存/上游核查页面。审计 JSON 经白名单投影后展示；流水只展示佣金扣回/应收等指定 meta 字段。
- Web 导卡、任务重试和退款补充 IP 与服务器生成的请求 ID；同一次退款造成的实例和账单审计共用请求 ID。重试审计补充任务原/新状态及尝试次数。旧审计不会伪造回填，CLI 仍记 system。
- `python scripts/verify-admin-operations.py`：使用独立 SQLite、APP_KEY、storage、public 与数据库队列，真实 HTTP 验证 CSRF 419、越权 403、确认不足 422、网页授撤角色、会话内撤权、付款缺货→补货后仍 failed→显式重试→独立 worker 单次交付→明确豁免退款→重复点击保持单条退款。核实已售卡仍 sold、服务 Cancelled、钱包只按账单总额退回、审计有实际 actor/IP/request ID；未知回调可查，原文和队列异常不输出。
- `python scripts/verify-inventory-admin.py`：既有库存隔离闭环再次通过。
- Playwright：真实 Filament 登录；1440px 财务核查，390px 任务、退款与角色页面。移动端完成独立勾选的退款及角色授予，并直接核查临时数据库中的操作者、退款唯一键和退款后 stale 任务。三页 documentWidth=390，与 viewport 一致。临时浏览器、PHP 服务与数据已清理。
- 最终 Gate：**Pest 386 passed / 3136 assertions；PHPStan 0 errors；Pint clean**。21 个相关 PHP 文件 `php -l` 通过，验收脚本 Python AST 通过。Specs 剩余 0，`--group=spec` 报 No tests found，不能把它当作另一批通过的测试。

## 上线交接与限制

本轮没有新迁移。已批准的 Spatie 迁移仍须由有权运维按部署流程执行，Filament assets 也需在真实 public 路径发布；隔离验收不等于线上启用。业务站点仍未生成 installed.lock，没有创建真实管理员或默认密码，没有触及真实支付或上游资源。

在已完成部署、核实目标用户 ID 的环境，用以下命令初始化或撤销角色；将 `USER_ID` 替换为真实存在且已核实的 ID，原因写实际工单依据：

```bash
php artisan admin:role USER_ID access-manager --reason="初始化指定权限管理人员"
php artisan admin:role USER_ID finance-viewer --reason="财务核查只读授权"
php artisan admin:role USER_ID finance-operator --reason="全额退款操作授权"
php artisan admin:role USER_ID task-viewer --reason="全状态任务监控授权"
php artisan admin:role USER_ID audit-viewer --reason="操作审计核查授权"
php artisan admin:role USER_ID finance-operator --revoke --reason="撤销退款权限"
```

task-viewer 单独只有查看权限；恢复本地卡任务还需 inventory-operator，恢复经典任务还需 upstream-lifecycle。access-manager 没有隐式财务权限；审计查看同样需独立授权。授撤固定角色保留其他角色和直接权限；页面不能移除直接权限或管理非固定角色。

外部退款入口只登记**已完成**的外部支付，不能自动打款；退款佣金应收尚无收款/清偿入口。钱包调账因 INV-L2 的有限写入者规则不开放，后续如需实施须先人工澄清规则。远程发码/经典上游的未知结果继续按既有命令和流程人工核查，此页不增加盲目重放途径。本轮没有验证真实支付退款、真实供应商变更或 MySQL 并发行锁压力。


## C3 后续边界（2026-11-20）

上述钱包调账未开放描述为原批次交付边界；用户随后批准的 [C3合同](admin-financial-fallback-c3-contract-2026-11-20.md) 定向新增可用钱包加扣款、普通线下收款与原账单工作台。它不扩大原退款／应收／网关核对权限，不重写历史支付与资金，不将一般调账视为应收清偿。原接口和本页历史验收记录保留。

当前入口及权限见 [C3 实施记录](admin-financial-fallback-c3-implementation-2026-11-20.md)。客户详情的「钱包加扣款」需客户查看、财务查看及钱包调整专权，仅改变可用余额；账单工作台的「登记已收到的线下款」需财务查看及普通线下收款专权，仅支持准确的原外部应付金额，已有网关支付尝试的账单继续原核对流程。凭证详情独立 POST 并记审计，默认页面仅显示遮盖编号。

## C4 实例工作台（2026-11-20）

入口 `/admin/instances/{instance}`；从经典列表、客户服务和任务记录可进入。普通 GET 仅看本地安全字段，账单／任务／工单／客户分别要求对应查看权限。机器菜单及电源状态需要明确 POST 与 `upstream.machine-control`；同步上游事实需要 `upstream.sync`。两专权分别由固定机器操作／事实同步角色授予，旧生命周期权限不自动增加。

开机、VNC 直接提交；关机／重启／硬操作须确认业务中断，重装／救援须确认备份。操作记录实际管理员及原客户，返回“受理”后仍需核对远端结果。未知／未决请求不得自动重发或通过同步清除，继续原机器命令核对闭环。VNC 临时链接五分钟有效，权限、账户、绑定或认证修订变化即失效。

事实同步仅支持有效经典绑定，事务外读取、锁内重验后应用上游状态／到期等事实，不等于人工改日期。新工作台重试仅针对已付款失败原任务，需财务／任务／上游查看和原运维权限；旧三个重试路由保持。非经典驱动只读；改价、改日期、过户、手工开通另行设计。详见 [C4 实施记录](admin-instance-workbench-c4-implementation-2026-11-20.md)。
