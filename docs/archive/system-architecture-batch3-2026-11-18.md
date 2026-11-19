# W3：原收款补记、旧资源结案及映射恢复

> 历史实施与验收记录：本文保留原阶段事实和验收边界。当前能力、部署及未完成事项以 [发布状态](../release-status.md) 为准；返回 [文档入口](../README.md) 或 [历史索引](README.md)。

本批实现 N03–N04，让已登记的异常存在实际结束路径。原收款、原绑定及历史账务保留，结案取决于唯一实际结果和追加证据。

## 原收款与匿名原身份

`HandlePaymentCallbackAction` 与专用 `ReconcilePaymentCallbackAction` 共用类型化的 `PostVerifiedPaymentReceiptAction`。补记引用原已验签 callback、原 attention 和当前账单状态，不删除回调、不重发 webhook。原单仍可付款时，Paid、钱包结算、唯一支付/超付账本、履约任务、案件证据和原 `invoice.paid` outbox 在同事务完成；已 Paid/Expired/Cancelled 则把完整原收款按迟到/重复收款入账，不复开原单。

已注销原身份只接受原钱包入账，转为 `awaiting_clearance`，不履约、不恢复账号，不归同邮箱新用户认领。复核额外发现历史已注销 Unpaid 账单无法过期：现可在严格原 freeze/未结算证据下过期，把原冻结按原 `unfreeze:{invoice}` 账本释放到原钱包，并登记独立 `wallet_release` 来源，供原 N01 清退。完整收款和原冻结释放分别核对义务，不制造第二笔信用入账。部分/未知/冻结中的清退均不能结案。

专用 `finance-reconciliation` 角色包含 `finance.view` 与 `finance.reconcile`。请求需要稳定 ULID、预期案件版本、原因、加密证据及确认。重试比较原意图；不同意图或过期版本拒绝。正常后续退款/任务完成不推翻原结算；证据确实不成立时可以追加 reopen，恢复证据后的再核验不能重复记本金。

## 旧资源处置

换绑 `keep_orphan` 同事务建立唯一原绑定案件，历史回填前的 orphan 仍阻断注销。新实际供应商交付和新换绑记录认证驱动使用的端点快照；历史 `source_snapshot=NULL` 保留，不用当前可变 URL 冒充历史端点。

`upstream-orphan-reviewer` 只能在原 host 经认证查询确认 Deleted，或已有接收主体、原资源、责任接受、时间及凭证的实际移交后结案。查询在事务外完成，锁内复核原身份、绑定、账号和凭据；当前账号端点漂移时拒绝，不向手填端点转发 secret。删除已受理不足以结案。原 binding/disposition 不改，案件证据追加、受限查看并审计。

## 映射与客户价格

`upstream-mapping-reviewer` 使用“检查→核准应用”入口，只认证读取 host、完整配置、目标目录与周期/CNY/成本；除登录外不发上游变更 POST。升级页的部分可升级选项不能替代完整配置，不按名称猜 ID。五分钟加密凭据绑定操作者/请求/实例/账号/目录，应用前重新认证并在锁内验证未决账单、任务与绑定漂移。

核准重建未来的本地/上游配置 ID，必要时调整实例商品，保留历史账单、原续费金额和到期日。目标基础价加完整选项无法匹配原售价时，保留 `price_requires_review`；升级/降价继续阻断。客户本人通过服务器精确价格预览、当前密码和明确确认建立另一个不可覆盖的记录，才调整未来续费价。远端配置和历史账单不变。

## 入口与升级

- 后台 `/admin/reconciliations` 已显式注册 Filament 页面，补记/旧资源/重新打开/证据查看均有独立 POST 权限检查。
- 后台 `/admin/upstream-instances/{id}/mapping-review`，客户 `/console/instances/{id}/mapping-price`，已有实例入口提供链接。
- 增量迁移 `000011`：`instance_mapping_reviews`；`000012`：原绑定端点及案件 FK/唯一约束。
- 回填：`app:backfill-payment-attention-cases`（含闭户原冻结释放）、`app:backfill-binding-orphan-cases`。只登记原来源，不推定历史已结案。

## 验收记录

本批完整测试 **1337 passed / 10997 assertions**（明确排除下一批尚未提交的维护/巡检专项），PHPStan **0 errors**，Pint **passed**，`git diff --check` 通过。旧测试、旧迁移与 Specs 没有修改。日志 `/tmp/laravel13idc-w3-final-{pest,phpstan,pint}.log`。

新增旅程覆盖：真实模拟热切换→原绑定案件→callback 注册任务成功→已完成移交结案→完整映射→客户本人确认未来价→恢复升级计算，历史账单逐字段不变；以及闭户收款补记→原冻结释放→两来源分别实际清退→旧身份余额归零。原资源专项另覆盖 Deleted 与 Accepted/其他主机的区别、历史端点缺证据/漂移、核验期间凭据改变、权限、幂等/版本、outbox 失败事务回滚、证据推翻后 reopen、回填窗口阻断和实际后台入口。

`python3 scripts/verify-reconciliation-mysql.py --preview-pid 1448041` 在无网络/无宿主端口的一次性 MySQL 8.0.35 上验收，私有证据 `/tmp/laravel13idc-reconciliation-x4b85_ns/`：

- 16→18 迁移，58 旧表/19 合成旧行逐原列哈希不变；历史端点仍为 NULL，完整性与 deployment-check 通过。
- 两独立 PHP 进程在 owner lock 前同时放行，对同 case/revision 竞争：一方补记、一方明确 stale revision；settle 4.00、payment 6.00、excess 5.00 各一笔，履约任务一条、Paid outbox 一条，案件 resolved/revision 3。原 callback 所有字段与原 attention 逐字不变。
- 原生 MySQL JSON 确实改变键序，映射指纹/读取仍通过；原绑定来源唯一约束真实拒绝第二案件（1062）。
- 原预览只读副本 58 表/397 行增量升级通过；原库/封印/.env/迁移清单哈希不变、原 PID 存活；一次性容器已删除。

实际原预览迁移单独记录，不以副本、专项或页面 200 代替实际出款/供应商执行。

IMPLEMENTED: N03 原收款专用补记、原身份冻结释放清退、旧资源处置结案；N04 完整映射核准与本人精确未来价格确认。
NOT DONE: W4 应收查询/资金锁、维护调度、持久运营事件与容量/恢复等运行保障，继续按原方案实施。
DECISIONS: 沿用已批准 N01–N06；历史端点不猜测，未入账资金不直接外退；结案依赖实际结果。
CONCERNS: 本批没有执行真实出款、真实通知或供应商变更；历史证据损坏须核对，不能以人工状态绕过。
GATES: pest 1337 passed / 10997 assertions | phpstan 0 errors | pint passed | offline MySQL concurrency/JSON/unique and incremental upgrade PASS | independent architecture review PASS。
SPEC CASES: 0，未修改旧严格断言、旧迁移或 Specs。
