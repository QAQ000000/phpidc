# FIX-002：CNY 结算边界与验收

日期：2026-11-18（Asia/Shanghai）。对应 [修复清单](backend-review-remediation-2026-11-18.md) FIX-002 和已批准的 [C20](backend-remediation-decisions.md)。

## 实施状态与版本

实现及隔离验收完成；未修改生产历史账本、未做汇率转换、未增加字段或迁移。HEAD 基底为 `16b5e194de21ca8b942eb698a790fb7cfa2d1bd5`，本轮未提交，保留已有工作区改动。

十个实施/测试文件的排序后逐文件 SHA-256 清单摘要为 `3d3e4a7b61cfcfb4f05e6b8f2e3b432d74a72b334f4e0b33a422ac9071faa9f8`，这是工作区快照标识，不是 Git 提交。清单为 SettlementCurrency、CreateComboInvoiceAction、InitiatePaymentAction、EpayGateway、PaymentCallbackOutcome、HandlePaymentCallbackAction、ExpireUnpaidInvoicesAction、RefundInvoiceAction、SettlementCurrencyTest 和 verify-settlement-currency.py。

## 改动与依据

1. `SettlementCurrency` 集中定义当前 CNY 边界。新购、续费、升级在入口及事务内重读后校验，重复请求返回历史单前也校验；零额与纯网关订单同样适用。商品目录可保留原币种，不能据此扣减单币种钱包（C20、INV-M4/W5）。
2. 发起支付在创建 attempt 前拒绝外币；网关被独立调用时也拒绝并保留 outTradeNo，不发送 HTTP。验签驱动仍只做协议验证、不查账单（INV-L2/L3）。
3. 回调依据锁定账单的币种，先匹配支付渠道，再判断币种，之后才进入终态及金额分支。外币记为 `rejected_unsupported_currency`，保存验签载荷及关联、去重、提交后告警并正常 ACK，不改变资金、账单、支付 attempt、实例或任务。回调提交的 currency 参数不能覆盖账单币种（C20 回调 PC 分支）。
4. 非 CNY 历史账单到期时保留 Unpaid、冻结款及 attempt 并告警，批次继续处理 CNY；两种退款方式均拒绝且不变更钱包/佣金/实例。原推广计提及成熟已有 CNY 检查、转钱包固定 CNY，本项未重写其算法。
5. 金额仍全部经 Money，既有用户行锁、锁顺序、唯一流水及 afterCommit 保持；未修改旧测试断言、Specs 或 PHPStan ignore。

规范、章程、guardrails 与支付驱动说明同步记录此次定向批准。新枚举值可被后台现有 `cases()` 筛选和原始值展示读取，无需新增管理界面分支。

## SQLite 与独立 MySQL 验收

`tests/Feature/SettlementCurrencyTest.php`：**39 passed / 409 assertions**。覆盖 USD/EUR 各付款方式及零额新购、续费/升级和重复请求、陈旧模型的锁内检查、网关直接调用、外币五种状态与三种金额的回调及重放、HTTP 验签/伪造币种、错误渠道优先级、到期批次继续处理 CNY、两种退款和佣金保持不变。

`python3 scripts/verify-settlement-currency.py` 使用已有 `mysql:8.0.35` 镜像创建一次性容器：无网络、私有临时 socket、tmpfs 数据，不拉镜像、不连接现存数据库。上述 39 项在 MySQL 再次通过；另用两个独立 PHP 进程同步发起真实验签和回调 Action，**12 个并发场景通过**：

- USD 的 Unpaid/Paid/Expired/Cancelled/Refunded × 同交易重放或不同交易，共十组；钱包/冻结保持 17/4，流水/实例/任务均为零，仅留一条或两条回调证据。
- CNY Unpaid 同交易竞争：一次结算、一实例、一任务、两条流水，钱包/冻结为 17/0。
- CNY Unpaid 不同交易竞争：第二笔走原超额支付入钱包，仍一实例一任务、三条流水，钱包/冻结为 23/0。

unsupported-currency 的 afterCommit 告警也在隔离日志核实。容器仅清理脚本自行创建者；上述金额均为测试夹具，未发送真实支付请求。

收尾 Gate：**Pest 611 passed / 4940 assertions | PHPStan 0 errors | Pint clean | Specs 0**；`git diff --check` 通过。原有 CNY 财务断言全部保留。运行日志位于本机 `/tmp/backend-fix002-after-{pest,stan,pint}.log` 和 `/tmp/backend-fix002-mysql8.log`。

## 历史与运行边界

现有经典联调隔离库只读统计为一张 CNY Paid 账单，没有非 CNY 账单；钱包仍 0.99 CNY、冻结 0，账单/流水/任务仍为 1/2/1。这不能证明生产没有历史外币收款。默认项目无 installed.lock，本轮未连接默认业务库，也未对历史外币扣款、混合余额或待退冻结款做自动修正；实际收款须人工逐单对账。

本项未联调真实易支付外币通知，不声称供应商款项已退回。被正常 ACK 的外币通知已持久留证，后续须由运营处理告警，不能把 ACK 理解为到账或交付成功。预览 PID 1448041 仍在运行，`/up`、`/login` 均 HTTP 200，未重启或关闭项目。
