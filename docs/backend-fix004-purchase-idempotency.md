# FIX-004：持久购买请求幂等验收

日期：2026-11-18（Asia/Shanghai）。依据：用户明确批准 [C22](backend-remediation-decisions.md#c22--fix-004用户作用域的持久购买幂等)，对应 [修复清单 FIX-004](backend-review-remediation-2026-11-18.md#fix-004新购缺少请求幂等重复提交重复扣款)。

## 实现及判断

- 新迁移仅增加可空 `invoices.purchase_request_id VARCHAR(26)` 和 `unique(user_id, purchase_request_id)`；历史/非新购账单保持 NULL。HTTP 与新购 Action 都要求有效 ULID，并统一为大写；缺失请求键明确拒绝，不在 POST 时临时补键。续费、升级现有幂等协议保持原样。
- `PurchaseRequest` 在配置解析、可信报价、自动生成密码之前生成 version=1 的规范请求：商品、周期、配置、自定义字段、主机名、密码、优惠券、钱包/网关选择。配置 ID 为正整数；选项值统一非负整数字符串；自定义字段必须字符串（null 等同空串）；映射排序；缺少/空映射等价；主机名/密码/优惠券空字符串等同 null，其他字符串精确比较；网关空串等同 null。不支持的形状拒绝，不纳入客户端金额、当前商品价格或生成的凭据。HTTP 既有输入中间件处理仍先于上述规范化。
- 请求身份加密保存在 `line_items.purchase.encrypted_request`，只在内存解密并比较 SHA-256；不持久化敏感输入的明文摘要。`ReencryptSecretsAction` 已覆盖新密文。身份用于查重，不成为报价或权限来源。
- HTTP 在当前商品规则检查前、Action 在当前目录/驱动/实时核价前查本人账单。最终创建仍在一个事务内先锁 users，再用锁定读取查相同请求（INV-W3、INV-L1、INV-K5），确实不存在才沿用可信价格、用券、冻结/结算、实例和任务写入。MySQL 的锁定读取避免使用旧一致性快照；不持锁调用上游。唯一索引是持久兜底，不吞唯一键异常。
- 同键同身份返回原单；同键改参数返回 request_id 错误。原单 CNY 边界仍检查。Paid 返回原服务、Unpaid 返回原待付单；Expired/Cancelled/Refunded 不重开、不重新冻结，不自动发起 payment attempt。重新购买须新键。
- 公开商品直购、客户总览、完整配置页共用请求键 partial，每份表单独立。校验重显只恢复匹配商品的旧键，密码/私密选项仍不回填；HTML 报价保留键，JSON 报价不替换表单。含购买表单的公开商品 HTML 设 private/no-store，防止共享缓存复用购买意图。
- C22 允许给旧测试及脚本补购买键；原用例名、资金/交付/权限断言不变，未修改 Specs 或其计数。INV-K5、章程及 guardrails 批准记录已同步。

## 默认 Gate

开工基线：**642 passed / 5242 assertions，PHPStan 0 errors，Pint clean**。

收尾：**671 passed / 5515 assertions，PHPStan 0 errors，Pint clean**。新增 `PurchaseIdempotencyTest` 共 **29 项 / 273 assertions**；剩余 Specs **0**。

新增测试覆盖：四种支付资金路径及用券只一次、响应丢失重试、各业务字段冲突、不同用户同键与同用户新键、终态重投、HTTP 与 Action 缺失/非法键、原单优先于当前商品规则、客户端金额无权定价、私密配置排序及类型归一、生成凭据不参与身份、密文损坏显式失败、真实换钥后查重、多个购买表单独立键、错误重显及 HTML/JSON 报价保留键。

日志：`/tmp/backend-fix004-before-{pest,stan,pint}.log`、`/tmp/backend-fix004-after-{pest,stan,pint}.log`。

## 独立 MySQL 8 与 HTTP

运行 `python3 scripts/verify-purchase-idempotency.py`：本地 MySQL **8.0.35** 镜像，不拉镜像、`--network none`、tmpfs 数据盘和私有 socket。隔离 schema/storage/cache/key；退出后仅销毁本次容器。

- MySQL 上 **57 项 / 497 assertions** 通过，覆盖购买幂等、组合账单、checkout 校验与换钥；这是相关测试子集，不声称全套 MySQL 通过。
- 四种支付各两个独立 PHP 进程同时购买，外加第三进程模拟丢响应后的重投：原账单、用券和资金结果相同。纯钱包 100→91，一单两条流水一任务；组合 3→0、冻结3、网关6，一单一条冻结流水无提前交付；纯网关余额100不变、网关9，无钱包流水或提前交付；零额保持余额0，一单零额结算流水一任务。各有一次1元优惠券使用、无自动支付请求。
- 同键不同 hostname 恰好一方成功、一方收到 request_id 错误；新键并发则合法创建两单，余额100→82、用券两次。
- 报价进程停在事务外，另一进程先按9元提交；解除报价屏障后即使拿到99元新报价，也返回原单，钱包/用券/任务均不重复。
- 数据库唯一约束与不同用户作用域、NULL 历史键均通过；在临时库对有数据的迁移 down/up 也保持历史账单原值。

另运行 `scripts/verify-classic-zjmf-lifecycle.py`：真实 HTTP/CSRF、完整配置购买、易支付替身与签名回调、独立 worker、回调同步、续费/升级均通过，全部只使用临时 SQLite、模拟供应商/支付服务。没有重跑真实上游购买。

日志：`/tmp/backend-fix004-mysql8.log`、`/tmp/backend-fix004-http-classic.log`。

## 已解释的失败与边界

1. 新测试初稿用未重新读取的 Eloquent 新建对象原始属性作比较，遗漏数据库默认值/SQLite 实际存储类型；改为先读取已持久化账单，再严格比较重试前后原始行，未降低断言。并发脚本初稿的第二请求键误写成27字符，被生产 ULID 校验拒绝；修正26字符夹具后全部通过，未改变生产校验。
2. 补充旧 `scripts/verify-local-card-sale.py` 在“jobs总数=1”处失败。临时诊断副本确认实际任务恰为 `ExecuteFulfillmentTaskJob`、`AccrueAffiliateCommissionJob`、`SendEventNotificationJob` 各一份；当前结算入口明确派发这三种任务，旧脚本落后于此前运营扩展。保留旧断言，仅按 C22 加请求键，不以修改旧脚本来制造通过。日志 `/tmp/backend-fix004-http-local.log`、`/tmp/backend-fix004-http-local-diagnostic.log`；不声称此旧脚本通过，后续定向修订需人工裁决。默认 Gate 与经典完整 HTTP 验收均通过。
3. 首次查重尚未命中时，竞争请求可能各自进行只读报价；购买幂等保证最终资金/账单/交付只一次，不承诺全局只调用一次报价。若其间一方上游报价失败，失败仍显式返回；客户复用原键重试即可取得已经提交的原单，不吞异常来假装成功。
4. 未迁移既有业务/隔离验收数据库，未处理历史重复订单。代码发布与新增迁移须协调；旧库没有新列时尚不能验收新版 checkout，不能用重新生成请求键绕过。历史订单没有购买键，不猜测其购买意图或回填。
5. 原预览 PID 1448041 未停止/重启；`http://127.0.0.1:59493/up`、`/login` 仍200。只读复核验收库：账单/流水/任务仍 **1/2/1**、客户钱包仍 **0.99 CNY**、冻结0、新列未应用。未更改真实上游机器或凭据，未操作 VNC 标签。
6. FIX-005 的过期续费恢复协议仍待独立设计/审批；本次没有把终态账单重新打开，也没有借新购请求键绕过原账期唯一键。
