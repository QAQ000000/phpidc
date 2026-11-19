# 架构审计第一批修复

> 历史实施与验收记录：本文保留原阶段事实和验收边界。当前能力、部署及未完成事项以 [发布状态](../release-status.md) 为准；返回 [文档入口](../README.md) 或 [历史索引](README.md)。

基线：`d759a90`。用户在终稿六项范围及验收边界明确后指示“可以，逐步开工”，沿用验证通过自动提交授权。

## 范围与验收

1. `SettingService` 采用请求/任务作用域，真实常驻 worker 的下一任务读取更新配置；同一任务内缓存、共享缓存密文及安装前旁路保持。Epay 历史商户快照专用读取保持。
2. 注销在应收扫描前验证最新账户密码，密码移出业务意图；事务内二次验密及已注销幂等返回保持。错误密码不扫描、不排队，扫描期间改密或停用不能注销。
3. 五个模型的加密载荷与实例回调令牌默认不进入数组/JSON；授权详情读取及回调功能保持。
4. 显式后台路由在绑定资源前统一认证，HTML 去后台登录、JSON 返回 401；Action 的业务 Gate 及三个任务重试路径/权限上下文保持。
5. 结账预校验不再开启事务或锁用户，产品参数验证保持；建单事务内的钱包锁、规则复核和并发幂等保持。
6. 自动续费单独提交：生命周期将同轮已同步实例的完整行、原绑定与账户指纹交给内部建单入口；在报价前及提交锁内复核，不增加通用跳过开关。人工续费及独立自动续费调用继续实时同步。

两处旧测试仅调整已批准契约：预校验不再要求事务/用户查询；后台匿名 HTML 预览改为后台登录跳转。保留场景、严格业务断言和 Specs。

不包含应收余额替代、审计业务状态迁移、外键、后台重写或生产消费者启用；无 schema 变更，无需迁移。

## 验证

修改前完整基线：Pest 1605 passed / 13415 assertions、PHPStan 0 errors、Pint PASS。日志：`/tmp/laravel13idc-architecture-batch1-baseline-{pest,phpstan,pint}.log`。

六项均已完成，分别提交，自动续费快照复用保持独立提交。实现与专项证据：

| 修复 | 实现与验证 |
| --- | --- |
| 配置缓存作用域 | `AppServiceProvider` 将 `SettingService` 从 singleton 改为 scoped。真实 Laravel 常驻 worker 连续处理两个隔离数据库队列任务，第二个任务读取更新后的配置；同任务缓存、共享缓存密文及安装前旁路通过。配置/安装/邮件故障组合回归 23 passed / 223 assertions。未修改 `EpayProfiles` 或 `DriverManager`。 |
| 注销前置验密 | 应收准入前查询最新账户并验密，业务意图只保留确认信息；错误密码不触发扫描、排队或注销审计。扫描期间密码、停用或匿名化变化由事务内锁定复核拒绝，已完成注销仍幂等返回。 |
| 默认序列化脱敏 | `AffiliateWithdrawal`、`Instance`、`ReconciliationEntry`、`NotificationEvent`、`NotificationDelivery` 的七个加密属性及实例 `downstream_token` 加入 `$hidden`。数组、JSON、嵌套集合不泄露，隐藏密文不被意外解密；显式授权详情读取及审计保持。注销与脱敏组合回归 148 passed / 1027 assertions。 |
| 后台统一认证 | 显式后台 GET/POST 路由在资源绑定前执行 `auth`；后台 HTML 访客跳转 `/admin/login`，JSON 返回 401。Filament 登录入口保持可访问，原控制器业务 Gate、限流以及三个重试 URL 的权限、跳转和幂等保持。 |
| 结账预校验去锁 | 移除预校验事务和用户查询/行锁，仅读取产品并校验开通参数；调用方已有事务不增加事务层级，无资金副作用。建单财务事务保持。后台与结账组合扩大回归 208 passed / 1879 assertions。 |
| 同轮续费同步复用 | 生命周期在应用快照的实例锁释放前捕获完整行，通过内部入口传递原绑定和账户指纹；报价前及建单锁内重复校验行、绑定、账户启用/配置与到期边界。上游同步及报价均在新增事务外，人工与独立自动续费继续实时同步。专项 16 项，续费/经典驱动/到期组合回归 106 passed / 1056 assertions；首次自动续费仅同步一次，后续轮次不会重复报价或建单，中途变化拒绝且零资金副作用。独立交叉审查通过。 |

最终合并代码完整门禁：

- Pest：**1644 passed / 13915 assertions**，114.00 秒；相对基线新增 39 项回归。上述专项组合有重叠，不累计计算。
- PHPStan：**0 errors**；Pint：**PASS**；21 个变更 PHP 文件语法检查及 `git diff --check` 通过。
- 未修改 Specs、旧迁移、财务不变量或历史 Epay 商户快照读取。

本机日志（临时证据，不纳入版本库）：

- `/tmp/laravel13idc-architecture-batch1-final-pest.log`
- `/tmp/laravel13idc-architecture-batch1-phpstan.log`
- `/tmp/laravel13idc-architecture-batch1-final-pint.log`
- `/tmp/laravel13idc-batch1-identity-hidden-tests.log`
- `/tmp/laravel13idc-auth-checkout-targeted.log`
- `/tmp/laravel13idc-auth-checkout-regression.log`
- `/tmp/laravel13idc-architecture-batch1-lifecycle.log`

## 预览与部署说明

原预览 `http://127.0.0.1:59493` 的 `/up`、`/login`、`/admin/login` 均返回 200；进程工作目录为本项目 `public`，运行时为 `/www/server/php/84/bin/php`。本批无数据库结构变更，无需迁移；未提交真实业务表单，未启用业务 worker/scheduler，未执行真实付款、注销、供应商变更或通知发送。自动认证与资金边界由隔离测试验证，不替代生产 MySQL 并发或真实外部通道验收。

旧代码已排队的 `account-close` 临时核验意图包含密码派生 HMAC，新意图不含密码，因此旧票据不能直接复用。部署时可等待旧结果默认 **600 秒 TTL** 过期，或用新请求 ID 重试；不增加保留密码的兼容分支。其他实际运行的常驻进程应在部署代码后按既有流程重启。

IMPLEMENTED: 六项架构清理及对应回归；未移动 Specs 用例。
NOT DONE: 应收余额替代、审计状态迁移、外键调整留至独立设计；本批不包含生产消费者或真实外部业务启用。
DECISIONS: 沿用用户批准的六项范围及验证后自动提交授权；仅纠正两处已批准旧测试契约；五模型脱敏包含同模型实例回调令牌。
CONCERNS: 旧注销核验票据存在上述 TTL 过渡；隔离测试不证明生产 MySQL 并发或外部通道已验收。
GATES: pest 1644 passed / 13915 assertions | phpstan 0 errors | pint clean
SPEC CASES: 0（未改动）
