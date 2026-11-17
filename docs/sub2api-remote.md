# Sub2API 远程余额兑换码

状态：已实现并完成获准的单次真实 1 单位发码与兑换联调；未部署业务数据库。2026-11-17，用户批准按参考成品模式实施，允许专用测试账号生成并兑换 1 单位额度。不是给已有客户直接改余额；不保存管理员密钥到本文件。

## 协议依据与边界

参考 `/www/wwwroot/zjmf-manger-decoded-副本/public/plugins/servers/sub2api/sub2api.php` 与 README；交叉核对上游源码 `Wei-Shaw/sub2api@9c2cca9e627291bc719bada3d57de23b625a4180`：`backend/internal/handler/admin/redeem_handler.go`、`handler/redeem_handler.go`、`server/routes/{admin,user,auth}.go`、`service/idempotency.go`。

- 管理鉴权：`x-api-key`，JSON，严格 TLS，不跟随跳转；30s 请求超时，8s 连接超时（INV-Q1）。
- 只读：`GET /api/v1/admin/redeem-codes/stats`、`GET /api/v1/admin/redeem-codes/{id}`。
- 发码：`POST /api/v1/admin/redeem-codes/generate`，`Idempotency-Key`；`{"count":1,"type":"balance","value":整数面额}`；成功 `code=0`，data 必须恰好一条 `{id,code,type,value,status}`，类型 balance、面额一致、unused。
- 客户兑换：客户通过 `/api/v1/auth/login` 获得自己的 JWT，再向 `/api/v1/redeem` 提交 `{"code":"客户兑换码"}`；管理密钥不能作为客户 JWT。
- 源码默认幂等 TTL 为 24h，coordinator 未启用时可能不提供幂等，因此本地不能依赖无限重放。
- 2026-11-17 已通过目标站点只读 stats：HTTP 200、code=0，data 包含 active_codes/by_type/expired_codes/total_codes/total_value_distributed/used_codes。真实生成与兑换结果见下文。

## 实现选择

不增加表、枚举、DTO 或契约。连接密钥进入 `upstream_apis.encrypted_secret`（F09），显式 `config.mode=remote_api` 与 `config.protocol=balance-codes-v1` 才启用；未配置连接仍拒绝。`LocalPriced` 保持本地售价权威；首轮按固定面额商品交付一张码，面额位于 `products.provisioning_rules.sub2api_face_value`（正整数字符串），独立于售价、优惠和钱包抵扣比例。JSON schema 仅作新增键，不修改既有规范键。

下单在锁定用户后的事务内验证并加密保存面额、目标地址与账号快照。远程卡驱动复用现有 FulfillmentDriver/LocalPriced。DriverManager 解析后，按 Sub2ApiRemoteDriver 对象类型分派专用 Action；这是本轮的判断：既有 LocalInventory 的 allocate/release 会表达不合适的写入语义，UpstreamAccount 则会进入经典 ZJMF 的回调流程，因此不为复用分支而伪装能力，也不新增冻结的契约接口。

发送前在 `fulfillment_tasks.payload.remote_delivery` 提交请求意图、目标、面额、随机幂等键和 requested 状态。HTTP 不持有数据库锁（INV-L3）；Driver 只返回 DTO/记录缓冲，所有模型写入仍在 Action（F10）。响应中的兑换码与任何管理员凭据不进入 upstream_operations 明文；成功结果加密保留，再按当前付款状态、绑定与租约决定是否交付。

成功交付原子写入 `redemption_codes` 的加密 code/HMAC/sold 记录和实例加密凭据、上游 code ID、Active 状态。复用卡密退款损失规则与 owner-only/no-store 领取页，不把远程生成的码放回可售库存（INV-C1、INV-R3）。

requested/uncertain 任务绝不再次发码；进程崩溃、超时、5xx、错面额/数量、无效响应一律转人工核对。CLI 显式重试也不会清空请求意图。上游明确拒绝也保留意图，通用重试不会重新发码；本轮没有提供清空意图并重新发码的命令。应核对已有结果后恢复，或按既有财务授权处置订单。提供按已确认上游 ID 的只读核对恢复入口，必须填写原因并确认归属；匹配面额不能证明归属。旧 worker 丢失租约、退款或换绑后的响应只加密留证并报警，不交付。

本轮不接入 `create-and-redeem` 对已有用户直接充值、不替客户保存 Sub2API 登录密码/JWT、不自动撤销已兑换余额、不迁移业务库、不创建生产商品。既有库存后台只处理本地池；远程结果不混入其可售库存。


## 运维入口

所有写入命令要求非空原因。管理员 Key 使用隐藏提示输入，不放在命令参数或产品字段：

```bash
php artisan sub2api:connect '主充值站' 'https://sub2api.example.com' --reason='接入已核实的余额兑换码协议'
php artisan sub2api:create-product <连接ID> '100 单位余额兑换码' '90.00' '100' --currency=CNY --reason='上架固定面额商品'
php artisan sub2api:recover <失败任务ID> <确认属于原请求的上游码ID> --confirmed --reason='已在上游核对原请求及兑换码归属'
```

`sub2api:recover` 只 GET 已有码，核对类型、面额、ID、状态与原端点，记录人工确认后恢复同一个任务；不调用 generate。允许恢复已由用户兑换的原码，不把它重新放入可售库存。后台库存角色不因此获得远程操作权限。首轮不支持客户任意输入面额，可创建多个固定面额商品。

## 真实验收记录

2026-11-17，经用户明确批准，仅执行一次真实额度变动。运行标识 `cms-sub2api-a34ac61287bee633`：

| 检查 | 结果 |
|---|---|
| 上游地址 | https://api.zhuzn.top |
| 专用普通测试用户 | ID 67；新建余额为 0，限制公共组访问 |
| 实际生成 | 上游兑换码 ID 13；balance 类型；面额 1；仅一条 generate 请求 |
| 正常客户兑换 | 登录获得客户 JWT，通过 `/api/v1/redeem` 兑换；余额 0 → 1 |
| 重复兑换 | 被拒绝；余额仍为 1；原码状态 used |
| CMS 本地链路 | 独立临时 SQLite/storage/APP_KEY；钱包结算 Action → database queue → 独立 worker；Paid、单任务、单张 sold 卡，钱包 2.00 → 1.00 |
| 保密 | Key、兑换码不进入源码或文档；临时 DB 中加密，审计/操作日志无明文；客户 JWT/密码只在联调进程内存中使用 |

专用上游测试账号和已兑换记录保留，未删除财务审计痕迹、未增加第二单位额度、未调用模型消耗余额。脱敏证据为 `/tmp/laravel13idc-sub2api-live-20261117-01/evidence.json`；成功后清理临时密钥与数据库，仅保留该证据。正式业务库的上游连接和产品没有被写入。

可重复使用的验收脚本 `scripts/verify-sub2api-live.py` **会产生真实 1 单位额度**，每次运行都必须另获授权，并明确指定 `--allow-live-unit` 和新的私有 state-dir。失败时保留原任务与状态目录，不得换目录盲目重跑。此轮不会再次执行该写入脚本。

## 验证与限制

远程单元/功能场景覆盖纯钱包和组合支付、商品改价后的面额快照、非法面额拦截、超时/5xx/无效及错数量/面额响应、进程中断意图保留、人工重试不重发、退款/换绑/丢租约/改端点后的旧响应隔离、按已有 ID 恢复、已售远程卡的退款损失、隐藏 Key 命令及加密保存。既有测试未删改，Specs 仍为 0。

最终 Gate（2026-11-17）：`./vendor/bin/pest` 206 passed / 1578 assertions；`./vendor/bin/phpstan analyse` 0 errors；`./vendor/bin/pint --test` clean。本轮新增远程销售测试 17 个，沿用既有 189 个测试；`git diff --check` 通过。

未验证 MySQL 多 worker 并发压力与上游跨 24h 幂等过期行为；本实现以持久化意图不重放来避免依赖该 TTL。没有实现直接给既有用户充值、可变面额输入、远程管理 Web 页面、上游退款或重置请求意图。APP_KEY 不可随意轮换；发码后已知/未知结果都必须按原记录核对，不能以新建任务绕过。
