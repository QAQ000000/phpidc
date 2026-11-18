# 支付商户切换与零佣金补偿修复

本次仅修复审查中已复现的两个问题：商户配置变更后旧支付回调验签失败，以及按分取整为零的佣金被定时任务反复扫描。未调整支付协议、DTO、状态枚举或表结构。

## 支付配置按尝试保存版本

旧实现每次回调都读取最新 `payment.epay_*`，即使支付单已经由旧商户发起。新实现使用现有 `system_settings` 保存不可变凭据版本，支付尝试通过现有 JSON 字段引用：

| 位置 | 内容 |
| --- | --- |
| `payment.epay_profile_<ULID>` | `is_encrypted=true`，加密 JSON `{version:1,api_url,pid,key}` |
| `payment.epay_profile_id` | 当前版本 ULID，不含密钥 |
| `payment.epay_configuration_lock` | 配置选择的数据库互斥行 |
| `payment_attempts.request_payload.epay_profile_id` | 该次支付使用的版本 ULID，不含密钥 |

原有 `payment.epay_api_url`、`payment.epay_pid`、`payment.epay_key` 继续由配置 Action 同事务写入，密钥仍加密。重复保存完全相同的配置复用版本，真实变更才创建新版本。配置审计仅保存版本标识和脱敏密钥。

`ConfigureEpayAction`、发起支付与回调版本选择先取得同一配置互斥行，配置读取直接访问数据库，避免旧的进程设置缓存把不同版本拼在一起。首次切换会在同一事务中，把未带版本的历史 Epay 尝试绑定到切换前的完整配置；`created`、`failed`、`expired`、`paid` 均包括在内，并保留其他请求诊断字段。配置写入、历史绑定、版本创建和审计一起提交或回滚。历史尝试存在而旧配置不完整时，拒绝推断旧商户，要求先恢复完整旧配置。

`InitiatePaymentAction` 在配置锁内选择版本，继续按既有用户、账单顺序加锁和校验，再持久化带版本的尝试。事务完成后才调用捕获的不可变网关对象；期间更换商户也不会改变该次 HTTP 使用的 URL、商户号或签名。既有支付失败保留尝试、显式重试使用新单号的规则不变。自动续费待确认分配的账单也会在持锁校验时拒绝直接发起支付。

回调控制器通过 `DriverManager::paymentForCallback` 按 `(gateway,out_trade_no)` 精确查找版本，随后把该凭据传给原有纯协议适配器。适配器仍只验签并返回 DTO，不查询或修改财务模型。请求传入的版本号、商户号或猜测的单号后缀均不能选择历史凭据。无精确尝试的完整账单号回退和未知订单只使用当前商户；当前商户签名正确的未知订单仍记录诊断并返回原有 ACK。已有历史尝试绑定后，晚到款继续由原有回调 Action 决定：过期账单退入余额，不重新开通。

新旧版本都存于 `is_encrypted=true` 设置行，因此既有 `ReencryptSecretsAction` 的全量加密设置轮换已覆盖它们。尝试、审计和设置缓存均不写入明文商户密钥。

限度：仅能把无版本历史尝试关联到本次切换前仍可读取的配置，无法重建此前已经丢失的商户历史。只有账单号而没有尝试映射的旧支付，不能凭入参任意选择历史密钥。首次切换对无版本尝试分块读取，但绑定和配置切换仍在一个事务中完成；历史量很大时应计入配置维护耗时。

## 零佣金留下持久完成证据

例如 `0.01 × 10.00%` 按既有 Money 规则得到 `0.00`。此前 Action 返回空而未写佣金，恢复扫描每次都认为该账单没有处理。

现在 Action 在既有用户和账单锁中，写入一次 `audit_logs.action=affiliate.zero_accrual`，主题为该 Invoice，内容为金额、佣金率、计算结果和推荐人。它不创建零佣金、流水或通知，不修改钱包和不可变账单快照。并发重复作业受同一组用户、账单锁保护，只写一条完成证据。

`affiliate:process` 使用相关 `NOT EXISTS` 排除这些账单，复用已有审计 `action` 和 `(subject_type,subject_id)` 索引。非零佣金仍走原有佣金唯一键和冻结期逻辑，半分边界仍按原有规则取整。该完成记录需作为持久业务证据保留；删除它会恢复扫描资格，但不会产生零额财务流水。

## 验证

所有测试使用临时 storage、独立 APP_KEY、内存 SQLite 或临时离线 MySQL，网络请求均替换为假响应。未使用业务数据库、真实支付商户或供应商。

- SQLite 定向回归：120 tests / 943 assertions 通过；包含新修复用例以及原有 PaymentSafety、CardOperations、SettlementCurrency、SecretRotation、OperationsExpansion、CustomerSales。
- 对应应用文件 PHPStan 通过；限定改动文件 Pint 通过。
- 新用例覆盖更换商户与仅更换密钥、旧尝试全部状态的晚到款、重复回调、错误商户和网关、猜测后缀、伪造版本、当前商户未知订单 ACK、事务外 HTTP、同值保存复用、审计失败原子回滚、APP_KEY 轮换、零佣金二次扫描排除、重复作业与非零边界。
- 独立 MySQL 8.0.35（Docker `--network none`、tmpfs 数据、私有 socket）定向回归：23 tests / 179 assertions 通过，容器已清理。
- MySQL 三进程配置竞态：配置事务持锁期间，同时发起的新支付与旧尝试回调均阻塞，释放后分别使用新配置发起和旧配置验签；新支付 HTTP 的事务层数为 0，旧过期账单的回调为 `processed_late_credit`、余额 `10.00`。
- MySQL 双进程零佣金竞态：第二个作业在首个作业持有用户锁时阻塞，完成后仅一条零佣金审计记录，佣金表为零条。

并发验证纠正了初稿使用 `INSERT IGNORE` 后再 `FOR UPDATE` 的锁升级问题：MySQL 多个首次竞争者可能同时持有重复键共享锁，升级时死锁。最终版本使用 upsert 先取得独占重复键锁。临时可复查脚本：`/tmp/laravel13idc-review-payment-affiliate-mysql.py`，结果：`/tmp/laravel13idc-review-payment-affiliate-mysql-results.json`。这些测试使用假支付 HTTP，不代表真实商户联调已验收。
