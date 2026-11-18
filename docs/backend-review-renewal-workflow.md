# 自动续费付款选择与终止闭环

日期：2026-11-18。设计依据为用户已明确采用的 `backend-review-fix-decisions.md` A，以及既有 INV-W2/W3/W4、INV-L1/L2、INV-K1 和付款回调决策表。

## 已实现

定时器调用 `CreateComboInvoiceAction::automaticRenewal()`，新账单明确带 `line_items.automatic_renewal.selection_pending`。提醒建单不授权使用客户余额；金额、报价、优惠券和续费边界在建单时固定。账单页提供一次钱包/微信/支付宝确认，使用既有 `/console/instances/{id}/operations` 入口；未确认的账单不能直接创建支付 attempt。

首次确认由原资金写入者 `CreateComboInvoiceAction` 处理。按用户→续费账单族→attempt→实例的顺序锁定，检查本人、当前链尖、币种、未到期 Unpaid、绑定、认证配置及到期边界，要求无付款 attempt/回调、冻结/结算/解冻流水和未决履约。总价与优惠不变，只确认一次付款分配；非零钱包冻结一次，全钱包 Paid、结算流水与续费任务在同一事务中写入。保存请求 ID 和选择，相同请求重放原结果，不同选择拒绝。其他请求定位到已确认账单时也不能再次调整。所有供应商 HTTP 位于事务外。

历史未标记账单不推断为自动账单，不能因当前请求把原分配改掉；不同钱包/网关或新增优惠券请求返回字段错误，明确使用原账单处理。已确认单沿用原付款/过期/恢复规则；过期恢复单仍按 C23 独立重新报价确认。修改账号、绑定或存在旧付款证据的待确认单要求人工核对，不删除其账单或历史。

服务终止请求先锁未付账单和 attempt，检查是否有未付升级或未决任务；只有未付续费可伴随终止取消。`ExpireUnpaidInvoicesAction::cancelRenewalForTermination()` 验证原冻结流水、没有结算/解冻且冻结余额充足，写唯一解冻流水并将账单设为 Cancelled、created attempts 设 expired。取消与终止任务、实例取消标记和审计在同一事务提交；其他冲突整笔回滚。优惠券消耗不恢复。重复终止返回原任务；取消后的真实迟到付款按原 P2 只入钱包，不续费，不再次解冻。

## 验证

新增 `ReviewRenewalWorkflowTest` 20 项，覆盖定时建单后的全钱包/组合/支付宝选择、幂等和变更意图拒绝、既有付款三种状态、到期/取消/账号停用/凭据与币种变化、固定优惠、旧单不可变、实际 GET 表单及 POST 路由、终止解冻、重复请求/回调、缺失冻结证据和未决升级/任务回滚。

- 隔离 SQLite：新专项加既有 `RenewalRecoveryTest` 和 `ClassicExpansionTest` **95 passed / 1106 assertions**。
- 独立 MySQL 8.0.35：新专项加既有 `RenewalRecoveryTest` **54 passed / 708 assertions**；未修改旧键序断言。
- 可重复执行 `python3 scripts/verify-review-renewal-mysql.py`，仅使用随机名称 Docker `--network none`、tmpfs、私有 socket，并在结束时移除自己的容器。
- 三组真实多进程竞争均先持有所属用户行锁，并查询 InnoDB 确认两个 worker 实际进入 `LOCK WAIT` 后释放：重复确认只冻结一次；确认与终止竞争取消原单且无重复占款；付款回调与终止竞争只能产生 Paid+续费或 Cancelled+迟到入钱包其中一条合法路径。

证据：`/tmp/laravel13idc-review-renewal-mysql-klrhijg1/pest.log`、`results.json`；SQLite 日志 `/tmp/laravel13idc-review-fixes-57vtwn92/renewal.log`。

没有迁移现有数据库、执行真实续费、真实收款/退款或修改历史账本。完整门禁与十项汇总由 `backend-review-fixes-2026-11-18.md` 记录。
