# 经典 ZJMF 接入与验收记录

日期：2026-11-17。范围为经典财务 API；V10 不在本轮范围。

## 已完成

- 多账号配置、加密 API 密钥、余额检查、目录读取、单商品及周期导入。
- 新购：本地和上游开通参数校验、实时库存与报价、客户钱包/易支付结算、事务 outbox、队列执行 P1–P5，以及显式开启后的 P6。
- 上游账单成本与币种核对；P4/P5 响应丢失后经 P2 恢复；付款成功但尚未 Active 时保持 Pending。
- 主机绑定、加密凭据、客户服务页、签名回调、防重放、异步认证重查、删除后任务 stale。
- 暂停/恢复前预同步；已达目标状态时只对账，操作员请求仍记录原因和审计。
- form 请求、加密 JWT 缓存、有界重认证、逐次脱敏 HTTP 日志和撤销失败告警。

本轮按“一提交一规格”原样迁出：

1. `verifies zjmf callback signature, blocks rand_str replay, and never clears suspend_reason`（`a0473f7`）
2. `responds to host sync before reconciling upstream`（`d04eaf2`）
3. `pre-syncs upstream status before suspend and reconciles idempotently if already suspended`（`fb9aba4`）

剩余 2 个退款规格未迁移；本轮后续的新购与操作入口收尾提交不迁移额外规格。

## 人工批准与协议证据

用户已批准：**按上游账单与 upstream_expected_cost 对账，客户钱包比例不影响上游成本；从经源码核实的商品配置读取密码规则，无法取得则拒绝新购。**

只读核实的参考工程为 `/www/wwwroot/zjmf-manger-decoded`。其 AGENTS 标明“3.7.7 自维护、3.5.8 解密基线”，因此这些证据不能代替目标供应商 3.7.6 的运行验收。

| 来源 | 确认的协议 |
|---|---|
| `app/home/controller/CartController.php::getProductConfig` | `data.products.password`、`host` 为 JSON 规则；新购无须先有 host |
| `app/common/logic/Shop.php::checkHostName/checkHostPassword1/checkHostPassword` | hostname 最短长度、密码 6–20 bytes、upper/lower/num/special 字段 |
| `CartController::getQty/getTotal` | 库存为 `data.product`；报价 products/currency 在顶层；有优惠时使用 sale_price + sale_setupfee_total |
| `CartController::addToShop/settle/clearCart` | add 不返回完整 cart_data，settle 接收显式配置；P2 返回 invoiceid 或 Active hostid |
| `app/home/controller/PayController.php::useCreditPage` | `data.total` 来自 invoice.subtotal；同时返回 currency.code |
| `PayController::applyCredit` | 1001 表示付款完成，后续开通可能异步；必须另确认 Active |
| `app/zjmf.php::pushHostInfo/createSign` | 只签 id/token/rand_str；host_id、状态和密码均不能直接信任 |
| `data/route/home.php` | stock_control 映射 getQty；use_credit_page 使用 GET |

参考源码两个密码校验方法并不一致：`checkHostPassword1` 按声明组合字符规则，而 `checkHostPassword` 对缺失类别标志使用最终 `&&`。本实现按声明规则逐项执行，可能比该旧方法更严格；不以其宽松分支降低本地要求。字符集按实现限定，特殊标点及目标供应商版本仍需联调确认。

## 操作方式

以下命令面向已有服务器权限的受信操作员；审计 `actor_type=system`，原因不能为空，不代替后台 RBAC。

```bash
php artisan upstream:classic-configure Supplier https://supplier.example supplier@example.com --reason='新增供应商'
# API 密钥由隐藏交互输入，不放到命令参数。按供应商需要选择 --raw-jwt。
php artisan upstream:classic-inspect 1
php artisan upstream:classic-inspect 1 --catalog
php artisan upstream:classic-import 1 17 monthly --currency=CNY --markup=20.00 --reason='导入月付商品'
php artisan upstream:classic-state 1 suspend --reason='人工确认暂停'
php artisan upstream:classic-state 1 unsuspend --reason='人工确认恢复'
```

`classic-configure` 按账号名更新连接；更新密钥/地址后应重启长驻 worker。`--credit-limit` 显式开启信用额度回退，默认关闭。商品导入按 `BillingCycle::priceKey()` 读取周期价格，不把上游 monthly 当作 msetupfee，也不更改已有实例绑定。

客户在 `/console` 选择商品和周期、填写开通参数后创建报价账单。经典商品表单默认不使用钱包，客户可先查看账单再发起易支付；checkout API 仍支持纯钱包或组合付款。价格来自服务端实时上游报价，无法获取规则或报价时拒绝创建账单。已有本地规则仍共同执行。

持续运行异步 worker 和现有一分钟调度：

```bash
php artisan queue:work --timeout=180 --tries=3
php artisan schedule:run
```

调度应由 cron 每分钟触发；现有任务采用 withoutOverlapping/onOneServer。生产使用共享 Redis 缓存，queue retry_after 必须大于 180 秒（默认 240）。单次 HTTP 默认 30 秒，header 3 秒，任务租约和账号购物车锁 210 秒。整条链可能累计超过 worker timeout；任务回收后通过 P2 恢复，已完成的每次 HTTP 日志立即由 Action 写入。

同步队列配置下，host sync 和详情同步改投 database 队列；该情况下须另外消费 database 队列。正式新购应配置异步队列。

## 一致性与恢复

- F03 / INV-M1–M4：金额仅经 Money；JSON 金额数字在解码前转字符串，报价货币不同即拒绝，无汇率转换。
- INV-I2/W2/W3/P2：先锁 users，钱包冻结/结算和唯一流水在同一事务；纯钱包或有效回调原子创建 Paid、实例和唯一任务；提交后派发。
- INV-L1/L3/L4：实例与任务按顺序加锁；上游 HTTP 在业务事务之外；驱动返回 DTO，Action 复核绑定/租约后写入。
- F09 / INV-U1：账号密钥归 upstream_apis；JWT 共享缓存存密文；购买密码和自定义字段进入加密快照/任务；日志脱敏并截断。
- INV-U2/U3：P4/P5 未知结果不盲目重下单；P6 撤销失败记录操作日志、critical 告警，并停止自动重试。
- INV-Q3/Q4：回调先验签再以实例作用域 Redis `SET NX EX 600` 防重放，立即排队响应；非 Redis 本地测试退回原子 add。不使用未签名状态写库。
- INV-S1/S3/S4/S5：状态转移记录审计；不自动换绑；唯一 upstream host 绑定；暂停/恢复前重查；空暂停原因不覆盖历史。

上游成本变动会使任务失败，客户已付账单保持 Paid，不自动退款或偷偷增加授权支出。操作员须依据 upstream_operations、invoice.upstream_expected_cost、upstream host/invoice 和任务租约核实，再使用既有人工任务重试入口；禁止手工清 token 后重新下单。P6 撤销失败须先处理上游资金状态再重试。自动退款尚未实现。

## 未完成和验收边界

- 未接入真实供应商、未消费真实资金、未部署 worker/cron。
- 完整选项/自定义字段目录同步、版本探测调度、Filament 上游管理及显式热切换暂未实现。
- 新购 API 接受上游 `options.configoption/customfield`，页面只提供默认配置。尚无本地/上游选项配对时 `active_config.options=[]`；原始选择另存 `upstream_configoption`，不伪造规范要求的配对行。
- 续费、升级、终止和自动到期生命周期编排未实现；相应驱动方法明确拒绝，不能据此宣布 Phase 2 全部完成。
- MySQL 行锁并发、Redis 集群、多 worker 极端竞态和目标供应商特殊密码字符尚未实测。
- P2 幂等恢复和 1001 的运行表现仍须目标供应商验收；仅模拟环境通过不代表任意经典分支兼容。

## 本轮验证

- 默认 Pest：115 passed / 759 assertions；PHPStan：0 errors；Pint：clean。
- Specs：2 个预期未实现失败，分别缺少 AffiliateCommission 与 RefundInvoiceAction；不是默认 Gate 回归。
- 三个迁出的规格正文与 `c25162c` 中的原文逐字核对；剩余两个正文原样保留。迁移、枚举、DTO、契约和业务不变量未改变。
- `python3 scripts/verify-classic-zjmf-sale.py --redis-server /www/server/redis/src/redis-server`：临时 SQLite/storage/APP_KEY、loopback 上游/易支付模拟器、独立 HTTP 服务与 database worker；真实注册/CSRF、付款尝试、重复签名回调、P1–P5 各一次、成本对账、加密凭据、脱敏日志、no-store 页面及队列清空通过。
- 同一脚本启动独立临时 Redis，验证签名回调、忽略未签名 Deleted、重复 nonce 返回 400，实际 key 的 TTL 大于 0 且不超过 600 秒；只结束脚本自己创建的进程。
