# 账户匿名化与退款应收清偿实施记录

日期：2026-11-18。依据用户批准的 D02、D04 与 `remaining-capabilities-decisions-2026-11-18.md`。本记录描述工作区实现和隔离验证，现有预览/业务库未在此专项迁移。

## 已实现合同

- 本人 GET/POST `/console/account/close`：认证、CSRF、用户限流、no-store、当前密码及不可逆确认；不接受目标用户覆盖，不提供后台强制清零。
- 提交持用户行锁重新检查四类余额、后台权限、未结账单/财务核查、服务/孤立资源、未决任务/主机操作、提现、待成熟或未计提佣金、剩余应收、KYC 和工单。任务使用 SQL `exists`，不读取全部历史任务；未计提查询排除 NULL 审计引用，避免 `NOT IN` 漏检。
- 同事务替换认证/联系资料、软删除、撤销 OAuth/通知绑定、数据库 session/reset token、取消和清理 outbox 收件快照、匿名化通知日志目标、追加无原 PII 的审计；审计失败整体回滚。提交后清理手机挑战缓存，身份 Action 持 active 用户锁拒绝旧证明。短信外部 I/O 返回后另行持锁复核，防止注销期间回填挑战。
- 原邮箱、手机、OAuth subject 可由新 ID 使用，新身份不继承原记录。历史财务、交付/凭据、工单、实名脱敏摘要和审计按原权限保留，注销文案明确范围；已开始的外部通知不宣称撤回。
- 财务回调由钱包模块按原软删 user_id 保留真实迟到款和人工核对事件，不复活身份或向同邮箱新账号转款。

应收专页 `/admin/finance/receivables` 需要 `finance.view`；登记和完整冲正还需要独立 `finance.receivable`（`finance-receivable` 角色），事务内重新检查操作者权限。POST 为 `/admin/finance/receivables/{clawback}/settlements` 和 `/admin/finance/receivable-settlements/{settlement}/reverse`。

- 只登记已经核实收到的外部 CNY 收款，正数且不超过当前剩余；校验原退款账单、推广归属、commission、原 C11 金额和币种证据。原流水总额不是债务本金，本金取原 `meta.receivable_amount`。
- 两张追加表及 `receivable_settle:{ULID}` / `receivable_reverse:{ULID}` 唯一流水共同保留凭证；同请求同意图返回原结果，改意图或旧版本拒绝。钱包、可用和冻结佣金不自动抵扣。
- 剩余统一按“本金 − 清偿 + 完整冲正”投影；证据异常维持提现阻断。客户、后台、注销和提现共用 `AffiliateReceivableBalance`，原始 clawback 不改写。
- 一张规范化外部凭证同时只允许一笔有效清偿，跨用户也受 UNIQUE 约束。错误登记完整冲正后，通过唯一 `corrects_settlement_id` 重新登记，链不分叉；更正同时按 ID 升序锁原/新 owner 及账单，并复查旧账本证据。
- 冲正恢复正应收立即阻断后续提现。原 ID 已注销，或存在冻结/执行中/未知外付时，同事务写 `affiliate.receivable_attention` 审计及 operations outbox；既有实际付款不倒改，旧身份不重新启用。

## 验证与证据

新建 `AccountAnonymizationTest.php`、`AffiliateReceivableSettlementTest.php`，不修改既有严格断言或 Specs。包含真实退款夹具、部分/全清/幂等/完整冲正、跨用户修订、越权和撤权、异常证据、故障注入回滚、余额不动、全部注销阻断、原身份释放、新 ID 隔离、database/file/cookie 旧会话拒绝、在途短信与匿名化竞争、专页 GET 和两类 HTTP POST。

- 最终专项及历史关联 SQLite：**168 passed / 1041 assertions**；日志 `/tmp/laravel13idc-receivable-closure-pl66j9yn/affected-sqlite.log`。独立 storage、APP_KEY、内存数据库及假 HTTP/队列。
- 所属生产文件 PHPStan **0 errors**，Pint **passed**；主代理另跑整库门禁。
- MySQL 8.0.35 真多进程：两清偿争抢剩余、跨用户同凭证、唯一完整冲正、不分叉修订、清偿与提现、注销与冲正、新充值、新工单、双注销全部通过。显式先取得用户锁验证两种顺序：新义务先提交则注销阻断；注销先提交则义务拒绝。
- MySQL 脚本 `scripts/verify-account-receivables-concurrency.py`；最终证据 `/tmp/laravel13idc-account-receivables-mysql-l012s_z5/evidence.json`。使用专用 `--network none`、私有 socket、tmpfs 数据和唯一容器，容器已移除。没有重用既有 MySQL 或调用真实外部服务。

升级需一起应用外付冻结字段 000005、应收表 000006、通知 outbox 000007，并完成既有迁移核对；不能只发布新路由而不升级依赖。预览资格只作提示，最终提交仍持锁重查。实际收款、凭证真实性、旧孤立资源/异常支付的人工核查与历史个人数据清理不由本功能自动推断。
