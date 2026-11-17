# 经典 ZJMF 完整生命周期扩展裁决草案

日期：2026-11-17。状态：**用户已明确批准 C12–C14，按现行降级规则实施；定向调整已应用，本地实现已完成，验收结果与真实供应商边界见 classic-zjmf-integration.md。** 下文保留批准时的证据、裁决和实施范围，不构成任意变更契约或规则的许可。

用户本轮要求：商品导入、实时报价、新购、成本对账、回调同步、暂停/恢复、续费、升级/降级、终止、自动到期处理、热切换、完整配置选项界面。

## 批准前的事实与停止原因（历史）

已按顺序重读章程、不变量、工作规则和 Specs，并读取两份驱动规范。默认三道基线 Gate：Pest 206 passed / 1578 assertions，PHPStan 0 errors，Pint clean；Specs 0。工作区已有库存后台与 Sub2API 改动，全部保留；本轮不提交它们，不修改用户提供的 `docs/zjmf-上游API对接总册.md`。

| 能力 | 当前代码事实 | 本轮需要补齐 |
|---|---|---|
| 连接、目录、导入 | 已有 CLI，多账号密钥加密，逐商品逐周期导入 | 后台管理、完整配置同步、版本/库存探测 |
| 实时报价、新购、成本对账 | 已有服务端报价与 P1–P6、成本核对、P2 恢复 | 将本地配置模型和完整界面接入同一报价/购买快照 |
| 回调、暂停/恢复 | 已有签名/防重放/异步认证重查、预同步 | 后台入口、持久化操作任务、旧租约/换绑结果隔离 |
| 续费 | Driver 返回未启用失败 | 报价、账单、结算、队列付款、到期确认与重复请求防护 |
| 升级 | quoteUpgrade 抛出异常，尚无配置变更实现 | 可信升级报价、结算、配置交付确认、成本核对 |
| 降级 | 仅章程定义，没有实现 | 按现行规则降低未来 recurring_amount，不退款、不调用上游升级链 |
| 终止 | Driver 返回需显式流程的失败 | 撤销申请、持续同步、确认 Deleted 后完成 |
| 到期处理 | 尚无实例生命周期调度 | 提前续费通知/账单、到期预同步暂停、确认续费后的恢复 |
| 热切换 | 有历史表，无编排 | 显式确认、目标主机核验、旧资源处置、旧任务 stale、回调重新注册 |

遇到的约束来自 `docs/agent-guardrails.md` §1.3：“The migration, the enums, the DTOs and the contracts are the agreed interface. Changing them requires a human decision”，以及 §4：“Making a test pass requires changing a rule in docs/domain-invariants.md.”。目前停在设计与协议核对，不通过绕开能力接口、Driver 写库或弱化测试来继续。

## C12：让纯 Driver 返回商品配置快照，由 Action 负责同步计数

证据：`app/Contracts/SyncableCatalog.php::syncProductConfig()` 返回 `SyncCatalogResult`；后者只有 productsSynced/optionsUpserted/optionsHidden/latestVersion。它没有商品、选项、自定义字段及联动数据。F10/INV-L4 又要求 Driver 不写模型，因此返回真实“已落库计数”的契约归属错误。

建议批准以下定向接口调整：

```php
// 新增 readonly DTO，承载经过协议解析的快照，不宣称已写入本地数据库。
CatalogSnapshotResult(
    string $upstreamPid,
    int $version,
    array $product,
    array $prices,
    array $optionGroups,
    array $customFields,
    array $dependencies,
)

SyncableCatalog::syncProductConfig(Product $product): CatalogSnapshotResult;
```

保留现有 `SyncCatalogResult` 的所有字段，由同步 Action 在提交后返回真实落库计数；不添加一个可选 payload 让旧计数字段失效。`probeVersions()` 保持原签名。网络读取在事务外；Action 事务中复核账号/商品映射和快照版本后 upsert。来源为 upstream 的选项和字段按上游 ID 更新，消失项隐藏，不删除历史 ID，不覆盖 local 来源记录。同步不改已有实例绑定或订单快照。

已有表足够承载首轮映射：`product_option_groups/items`、`product_custom_fields`；联动与上游元数据使用既有 JSON 列的新增键。对无法表达/验证的上游选项类型明确拒绝，不悄悄丢弃配置。无需新迁移、状态枚举或交易类型；实现时若发现必须新增字段，仍再次停下报告。

## C13：补齐独立的续费报价能力

现有 LiveQuotable 只有 quotePurchase/quoteUpgrade；RenewableResource 只有 renew/suspend/unsuspend/terminate。不能拿新购价格当既有主机续费成本，不能在询价时调用会创建账单的 POST /host/renew。

建议在 `RenewableResource` 定向新增：

```php
public function quoteRenewal(Instance $instance, string $billingCycle): QuoteResult;
```

复用现有 QuoteResult，不新增金额字段；报价来自经核实的 `GET /host/renewpage`，按显式请求的周期读取价格并检查币种。实例锁价时客户续费金额使用已冻结的 recurring_amount；未锁价按明确的服务端加价规则报价；两种情况都独立记录上游成本，不能以客户钱包支付比例改变成本授权。无法确认所选周期的最终上游成本则拒绝出账。

不扩展 INV-L2 的资金写入者名单：续费/升级账单创建入口放在现有 CreateComboInvoiceAction，网关结算仍由 HandlePaymentCallbackAction。Paid 与唯一履约任务同事务、afterCommit 派发。续费/升级复用已有实例，不能再次走新购的实例创建分支。用户钱包与外部支付继续遵守相同资金不变量。

## C14：按真实续费边界生成唯一键

INV-K1 当前 renew 的 period_key 固定为 `Y-m`。BillingCycle 同时支持 hour/day；同一实例一天可有多个小时账期，一个月可有多次日付续费。月份键会把后续合法账期误判为重复。用“当前请求时间”作键又会使同一个账期重复出账。

建议定向调整 INV-K1 和章程对应描述：

```text
renew billing_period_key = {instance_id}:renew:{due_boundary_utc}
due_boundary_utc         = 2026-11-17T10:00:00Z
```

所有周期统一使用本次续费前、认证同步并锁定的 `next_due_at` 边界；同一边界只允许一张账单，客户重复点击与调度重入返回已有账单；已取消/过期的原账单不因此获得重复扣款授权，其后续重新支付/恢复策略必须显式设计，不能靠随机后缀绕开唯一键。若需支持其重新出账，先另行明确授权和幂等规则。

升级/降级的请求标识由服务端生成并随第一次请求持久化，重试复用原标识，不每次生成“新的当前时间”。唯一索引、INV-K2 的已付任务键和其余资金规则不变。

## 已核对的参考协议

只读参考 `/www/wwwroot/zjmf-manger-decoded`，未启动该项目、未加载业务数据库。其 AGENTS 声明为 3.7.7 自维护分支、3.5.8 解密基线，不能当作目标供应商所有版本的真实验收。

| 源码 | 核实结果与实现影响 |
|---|---|
| `CartController.php:560` getProductConfig | 返回 products/product_pricings/config_groups/options/sub/pricings/customfields/advanced/config_links；配置项 ID 与展示分组 ID 不能混用 |
| `HostController.php:2000` getRenewPage | GET 读取 host、cycle、currency；必须明确 billingcycles，不能默认取列表第一项 |
| `HostController.php:2286` postRenew；`common/logic/Renew.php` | POST 会生成/复用续费账单；不是纯报价操作，必须先保存操作意图 |
| `UpgradeController.php:230` upgradeConfigPost | 只验证并缓存配置，返回 status=200；没有返回升级金额，缓存键按账号用户和 hid 共享 |
| `UpgradeController.php:280` getUpgradeConfigPage | GET /upgrade/upgrade_config_page 读取上述缓存并调用 upgradeConfigCommon 取得报价；需按账号/主机串行执行准备/报价/结算，付款前重新验证目标配置与成本 |
| `UpgradeController.php:394` checkoutConfigUpgrade | 从缓存读取配置并调用会产生账单/变更的结算逻辑；不能把任意 200 当作成功交付 |
| `HostController.php:2731` postCancel | 成功仅 insert cancel_requests；type=Immediate 也只是撤销申请，不证明主机已删除 |
| `common/logic/Host.php:1890` | 经典续费发送 POST /host/renew，随后 apply_credit；不能照搬其缺失本地成本授权与持久化恢复的部分 |

新购的 P2 恢复仅适用于新购。续费/升级没有在这些证据中确认相同 downstream_id 探针；不得借用 P2 然后再次创建续费/升级账单。先持久化操作意图，确定上游账单 ID 后记录，再 GET use_credit_page 精确核对成本/币种；响应丢失且无法认证核实时停在人工对账，禁止盲目重放。1001 只代表支付完成，交付必须另查到期日或目标配置。

## 不申请改变的业务规则

- **降级**：遵守现行 CLAUDE §3.5，只降未来续费价、受 downgrade_bounds 限制、不退本期差额、不调用上游升级链、不伪造远程实际已降配。界面明确该语义。若用户要求 CPU/内存/磁盘实际降配，需要另行裁决该条章程及上游退款差额，不视为本草案的隐含授权。
- **到期**：只针对 RenewableResource 且 isRenewable() 的实例；不自动扣钱包续费。月付等在到期前 7 天建单通知；短周期的提醒窗口按现有 SettingService 配置限制到一个周期内，避免小时付提前多期建单。账单必须以真实边界去重。
- **暂停/恢复**：先认证预同步；续费后只自动恢复本系统因逾期暂停的主机，不解除风控/人工暂停。
- **终止**：客户或有独立权限的操作员明确确认及填写原因；申请成功后持续认证同步，只有 Deleted 才确认资源回收，不提前放行依赖终止的退款（INV-R1）。
- **上游到期**：逾期暂停后不自动调用终止，直到上游报告 Deleted（INV-S2）。
- **热切换**：本轮指绑定到已经存在且核验归属的目标主机；不承诺迁移磁盘数据。明确确认旧资源 terminate 或 keep_orphan；前者须先确认旧资源 Deleted，后者记录孤儿并告警。历史绑定、当前绑定和旧任务 stale 事务提交，不改共享 products；未决资金操作会阻止切换，先对账。
- **后台权限**：沿用已批准的权限表，新增上游查看/导入/配置、生命周期、热切换的独立权限；库存操作员不自动获得这些权限。财务/生命周期写操作均携带操作者与原因。
- **界面**：完整配置选择、数量/步长/范围、必填自定义字段、依赖联动、实时金额/币种、开通规则、升级前后差异；前后端使用同一映射，禁止客户端传入金额作为价格依据，密码字段加密且不回显。

## 批准时的实施与验收顺序

1. 应用获准的定向契约/规则调整，为快照、续费报价和真实账期唯一键补测试。
2. 商品/选项/自定义字段稳定同步、版本与库存探测、购买配置界面和报价；衔接既有新购/回调/成本对账。
3. 续费与升级：账单/outbox、持久化上游操作阶段、成本授权、结果认证核对、重复请求/超时/旧租约隔离。
4. 按现行规则降未来续费价；终止申请及删除确认、到期任务、通知、热切换。
5. Filament 管理与客户入口、权限隔离及真实浏览器验收；隔离 SQLite/Redis/模拟上游、独立 worker/调度验证，收尾三道 Gate。

本草案不授权真实供应商发单、扣费、续费、升级、暂停、终止或迁移业务数据库。尚无本轮目标经典供应商测试账号和费用/破坏性操作授权；不使用此前 Sub2API 的密钥或 1 单位授权。未完成目标供应商联调、多 worker MySQL 压力与实际资源变更前，报告中分别标明。

后续若发现新的字段/接口缺口或规则矛盾，继续按 guardrails 停下汇报。本草案的批准不构成任意扩展契约或修改测试的许可。

## 本轮落实

C12–C14 已定向应用，原测试和 Specs 未改。商品/配置、报价购买、续费/升级、未来价格降级、终止确认、到期控制、热切换、Filament 管理、客户界面、RBAC 与通知已接入。明确保留：分段选项升级/降价拒绝、终态续费单不隐式重开、未知上游创建结果人工对账、热切换目标映射待复核，不宣称这些受限路径自动闭环。当前 Gate 与隔离验收入口见接入记录。
