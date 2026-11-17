# 经典 ZJMF 接入与验收记录

日期：2026-11-17。范围：经典财务 API，V10 不在本轮范围。本记录替代早期“仅新购完成”的状态说明；历史规格的三个迁移提交仍保留在 Git 中，当前 Specs 为 0，本轮未修改任何原规格或断言。

## 已实现与入口

| 能力 | 实现与验收边界 |
|---|---|
| 多账号与商品导入 | `/admin/upstreams` 配置加密连接、查看目录、导入商品、同步配置；逐账号版本/库存探测 |
| 配置与实时报价 | 客户 `/console/products/{id}/configure`，选择周期、选项、数量、必填字段；浏览器联动与服务端复验；可信报价不接收客户金额 |
| 新购与成本对账 | 保留 P1–P6、钱包/易支付、事务 outbox、成本/币种核对、P2 新购恢复、加密交付与脱敏日志 |
| 回调同步 | 签名与实例作用域防重放，立即响应后异步认证重查；不信任回调中未签名的状态、IP、密码 |
| 暂停/恢复 | `/admin/upstream-instances`，权限、原因、确认、持久化任务；认证预同步，已达目标则不重复发命令 |
| 续费 | 客户服务页创建同周期账单；独立续费报价、真实到期边界去重；沿用钱包/易支付结算与唯一任务，认证到期推进后确认 |
| 升级 | 客户服务页选择目标配置，准备选项→读取升级页报价→创建差价账单→上游结算→成本核对→付款→认证配置确认 |
| 降级 | **仅降低未来续费价**，不退本期金额、不执行远程降配。商品后台显式配置 downgrade_bounds；未付款账单或未决任务阻止改价 |
| 终止 | 明确确认/原因后提交撤销申请，等待认证 Deleted；200 仅为受理，不能提前确认删除或据此退款 |
| 自动到期 | 提前出续费单并多渠道通知；到期预同步暂停；仅恢复有本系统已受理暂停证据且暂停原因相同的服务；不自动扣客户钱包 |
| 热切换 | 已有目标主机的产品/币种/周期/状态核验；旧资源处置、历史绑定、换 token、旧任务 stale、重新注册回调；不迁移磁盘数据 |
| 运营重试 | 后台仅允许有独立上游生命周期权限的人员显式重试原任务，保留全部上游意图；库存权限不授予上游操作能力 |

商品同步使用用户批准 C12 的 `CatalogSnapshotResult`：Driver 只返回快照，Action 事务中落库并返回真实 `SyncCatalogResult` 计数。上游选项 ID 和展示容器 ID 分开；按稳定 ID 更新，消失项隐藏，保留历史和本地来源记录。不修改已有实例绑定。

选项类型 1–19 映射选择/开关/数量，数量范围、步长、分段子项及联动规则均校验。自定义字段校验必填、枚举和无分隔符正则，密码不回显；购买参数与配对快照加密保存。无法支持的类型/规则拒绝同步或购买，不丢弃后默认购买。

## 已批准的业务决策

- 早期新购裁决：上游账单对账 `upstream_expected_cost`，不使用客户 `gateway_amount`；从商品配置读取首次密码规则，与本地规则求交集，无法读取则拒绝新购。
- C12–C14 已明确批准并定向应用，见 [裁决与源码证据](classic-zjmf-expansion-decisions.md)：纯配置快照、独立 `quoteRenewal`、`{instance_id}:renew:{due_boundary_utc}` 唯一键。
- 同一续费边界只有一张账单；日付/时付可以在同月产生不同边界。已取消/过期账单不会被重开或另加随机键重新出账。客户请求 ID 同时持久化，续费完成后重复提交仍返回原账单。
- 续费账单保留至到期加宽限期（`billing.renewal_grace_days`，默认 7 天，且至少 30 分钟），避免提前提醒时 30 分钟即失效；不变更迟到付款规则。终态账单重新出账策略未授权，仍需另行设计。
- 续费先认证同步，冻结到期边界、预计延长到的日期、周期、报价、绑定、地址和 token 指纹；若执行前上游边界已变化则停止对账，不重复购买一期。
- 锁价实例客户续费使用 recurring_amount；未锁价按服务器商品加价规则计算，上游成本独立保存。客户付款比例不改变上游成本授权。
- 升级不混入降价或数量减少；分段数量类型 15–19 的升级/降价暂拒绝，避免伪造已验证的计价逻辑。新购仍走上游实时报价。
- 降价保存独立 billing_adjustment，保留真实远程配置；后续实际升级同时核对远程方向与计费快照，不把先前计费降价当作已远程降配。

## 操作与部署

先在测试环境核实目标供应商协议，再配置真实连接。以下命令通过隐藏输入接收 API 密钥，原因不能为空：

```bash
php artisan upstream:classic-configure Supplier https://supplier.example supplier@example.com --reason='新增供应商'
php artisan upstream:classic-inspect 1 --catalog
php artisan upstream:classic-import 1 17 monthly --currency=CNY --markup=20.00 --reason='导入月付商品'
php artisan upstream:classic-sync
php artisan upstream:role 42 upstream-operator --reason='管理上游连接与商品'
php artisan upstream:role 43 upstream-lifecycle --reason='管理服务与任务'
php artisan upstream:role 44 upstream-migrator --reason='审核热切换'
```

角色分离：upstream-viewer 只读；operator 配置/导入；lifecycle 暂停/恢复/终止/重试；migrator 热切换。既有库存角色不自动获得上游权限。Web 操作审计保留真实 actor_id/reason，CLI 作为受信系统入口记录原因。尚无权限表的环境应先审查并执行此前已批准的权限表迁移；本轮未迁移业务数据库。

部署需发布 Filament 资源，并运行异步 worker 与每分钟 cron：

```bash
php artisan filament:assets
php artisan queue:work --timeout=180 --tries=3
php artisan schedule:run
```

正式环境使用共享 Redis 与异步队列；`retry_after` 默认 240 秒，必须大于 worker 的 180 秒；任务租约及账号购物车/升级配置锁 210 秒。每次 HTTP 默认 30 秒，header 3 秒。整条链可能超过 worker 超时，按已持久化阶段恢复，不能重放创建/付款。

目录每五分钟探测；生命周期和任务回收每分钟运行，均有 withoutOverlapping/onOneServer。到期提醒默认提前 7 天，时/日付不超过一个周期单位；不自动续费扣钱包。上游代理服务不会在本地宽限期后自动终止，等待上游报告 Deleted。未付款续费单不阻挡到期暂停。

通知：全部已验证绑定分别排队，未绑定邮件时回退账户邮箱，并在 notification_logs 的 `.fallback` 事件记录。单渠道失败不取消其他渠道。运行时配置来自 SettingService：`mail.transport=log|smtp`、`mail.host/port/username/password/from_address`、`telegram.bot_token`。默认邮件及 SMS 为 **日志运输**，sent 表示运输接收，不代表客户真实收到；短信供应商未接入，SMTP/Telegram 送达需部署验收。不要把通知密钥放进 Action 的 env()。

公开 CMS/销售页是纯 Blade；`livewire.inject_assets=false` 禁止自动注入，Filament 使用自身显式资源。新增独立 Livewire 页面需显式加载资源。该设置同时避免先访问后台后把 Livewire 脚本注入公共 CMS 响应的跨请求状态问题。

## 上游操作意图与人工恢复

新购沿用 P2 探针恢复。**续费/升级不能借用 P2**：先持久化 create_requested，取得账单 ID 后保存 invoice_created；GET use_credit_page 精确核对金额与币种；提交 payment_requested 后才调用 apply_credit。1001 后仍须确认到期日或配置；付款响应丢失时只读对账，绝不再次付款。

- 创建响应不明：保留 create_requested/create_uncertain，停止自动创建。即使点击后台“重试”，也不会重建上游账单。
- 成本/币种变化：任务失败告警，下游账单保持 Paid；不静默提高支出、不自动退款。
- 控制/撤销申请受理：等待目标状态，超出最大尝试次数后只允许显式人工重试，原请求不重发。
- 旧租约、退款、地址变化或热切换后的结果：保留证据，但不能修改当前绑定或再次付款。
- 人工恢复首先核对本地账单、任务 payload.operation、upstream_operations、上游账单和真实主机。不要删除任务意图、清 token 或改成新购买来绕过。由于参考协议没有确认续费/升级账单与主机归属的恢复查询，目前没有输入任意账单 ID 后自动付款的恢复入口。
- 热切换要求先处理未付账单和未决资金任务。terminate 处置须已认证旧资源 Deleted；keep_orphan 明确保留、审计并告警。目标绑定唯一索引冲突整笔回滚。目标配置映射标记待复核，升级/计费降价暂拒绝；续费保留当前商品的定价策略，不宣称自动迁移旧选项 ID。

## 证据与未验证边界

只读参考 `/www/wwwroot/zjmf-manger-decoded`，AGENTS 声明 3.7.7 自维护 / 3.5.8 解密基线。未启动参考项目，也没有使用 Laravel PHP 运行它。核心方法包括 CartController::getProductConfig、HostController::getRenewPage/postRenew/postCancel、UpgradeController::upgradeConfigPost/getUpgradeConfigPage/checkoutConfigUpgrade、PayController::useCreditPage/applyCredit。精确语义见裁决文档和 [协议](zjmf-api-spec.md)。

参考密码校验方法存在不一致，本实现按声明逐项执行；目标供应商版本、特殊字符和首次密码规则仍需真实验收。暂停原因未返回时不会覆盖旧值，也不会据此自动恢复。

本轮没有经典供应商测试凭据与真实费用/破坏性操作授权，**未运行真实新购、续费、升级、暂停、终止或迁移**。此前 Sub2API 的密钥与一单位授权不用于经典 ZJMF。SQLite 模拟验收不能证明 MySQL 多 worker 行锁竞态、Redis 集群、实际邮件送达或任意经典分支兼容；也没有部署生产 worker/cron。

验证入口：

```bash
./vendor/bin/pest
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
./vendor/bin/pest --group=spec
python3 scripts/verify-classic-zjmf-lifecycle.py --redis-server /www/server/redis/src/redis-server
# 加 --preview 在隔离数据上保留临时服务供浏览器审查，回车清理自己创建的进程。
```

专项 `ClassicExpansionTest` 验证配置同步与隐藏、必填/联动/数量校验、加密快照、短账期去重、钱包/组合付款、独立成本、未知结果不重放、租约/地址/token/退款隔离、升级实际确认、终止只受理、到期暂停/恢复、降价边界、热切换历史与唯一绑定、后台 RBAC 和通知失败隔离。

独立验收脚本在临时 SQLite/storage/APP_KEY/静态资源、loopback 易支付/上游和独立 database worker 下完成配置购买、重复签名回调、续费、升级、成本检查、队列清空、加密凭据/日志脱敏与真实 Redis 防重放 TTL；不使用业务数据库。

## 2026-11-17 收尾结果

- 默认 Pest：**244 passed / 1929 assertions**（本轮新增 38 个专项测试实例）；PHPStan：**0 errors**；Pint：**clean**。PHP 修改/新增源码 lint、JavaScript 语法与 diff whitespace 检查通过。原有测试正文、Specs 和 EXPECTED_SPEC_CASES 未修改。
- `pest --group=spec`：No tests found，EXPECTED_SPEC_CASES=0；它不是另一个带预期失败的完成证明。
- 独立 HTTP + worker + 临时 Redis 验收通过，完成配置新购、续费、升级各一次上游付款，重复请求/签名回调未多扣；实际防重放 TTL 检查通过。
- Playwright Chromium：客户选择 Large 后实时显示 12.00 CNY；后台商品/配置列表、独立上游导航、降价边界表单保存及审计反馈通过；服务任务页显示 provision/renew/upgrade success。390px 移动端页面无水平溢出。
- 通过浏览器确认后台 Livewire/Alpine 实际加载，纯 Blade CMS 不自动注入脚本；新增专项测试覆盖同一进程先访问后台再访问公共页。
- 以上使用临时账号、临时数据库/资源和 loopback 上游；不是目标供应商或实际客户的验收。测试服务和临时 Redis 在验收后关闭，不部署生产服务。
