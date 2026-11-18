# 通知持久 outbox 实现与验证

日期：2026-11-18；对应已批准 D05，结合 D02 注销与 D01/D03/D04 财务通知。旧 Job 构造参数、Driver 接口及旧严格断言保留。本文记录实现与隔离测试，不代表预览环境已迁移或真实通知已送达。

## 业务提交与收件人

`NotificationManager::queue()` 在业务事务内写 `notification_events` 和每个已验证绑定/账户邮箱 fallback 的 `notification_deliveries`。生产发生点均传稳定 `eventKey`；无 key 兼容调用只具有一次调用级恢复能力。数据库用 SHA256(scope/user + eventKey) 唯一键处理并发，已存在的键要求事件类型和白名单变量一致，不再展开新增绑定。

续费通知已从 afterCommit 回调移回建单事务；其他生产者在原业务提交内记录事件。afterCommit 只派发投递 ID。派发失败保留 pending，由 `notifications:dispatch-pending` 分批恢复；默认 sync 环境改用 database 队列，避免业务请求等待网络传输。新 Job 实际序列化只含投递 ID 和 Laravel 队列元数据；内存属性保留旧兼容接口。

`queueOperations()` 供收款异常使用，写无用户的 operations 事件，财务事务尾部不锁运营人员或展开地址。后续独立阶段按 ID 锁定当前 `finance.view` 用户，再锁事件并展开；没有收件人时事件保持可见待展开。发送前再次校验权限。新事件类型包括钱包入账、收款异常、应收清偿/冲正；提现使用通用状态文案。

## 发送边界

Worker 按 users → event → delivery 锁序检查原用户、绑定、邮箱、模板停用与运营权限，并认领 UUID/120 秒租约、消耗一次数据库尝试。随后短事务再次校验并提交 `send_started_at`；网络调用时无该 Action 持有的事务或行锁。结果按相同锁序校验 UUID/租约，日志和状态原子写入。

明确临时未接受才允许最多 3 次尝试，遵守原 backoff 和 provider retry_after；租约过期且没有发送意图才可自动恢复。发送意图之后发生进程崩溃、未知响应、超时、结果提交失败或租约过期均为 uncertain，补扫和重复队列不能重发。即使崩溃发生在真正 HTTP 之前，也遵守这一边界。`sent` 表示传输接受，不表示送达或阅读。

后台 `/admin/notification-outbox` 只选择安全状态字段，不读取/展示地址、变量、正文或密文。查看要求 `notification.view`；处理 uncertain 要求 `notification.manage`、确认、原因和证据。重试还要求明确未接受、原 worker 已停止且预算未耗尽，只重开原投递并审计。Guest POST 返回 401。没有增加远端送达查询或“批量重试未知消息”。

## 注销、换钥与旧消息

`CancelUserNotificationsAction` 由持有用户锁的注销事务调用，按 event/delivery ID 锁定，清空用户事件/投递密文并遮罩历史日志 target。pending、retry_wait、尚无发送意图的 running 取消为 skipped；已开始发送变为 uncertain，允许完成注销，迟到结果不能回写地址。operations 事件可保留其他收件人共用的安全变量。旧邮箱重新注册不继承原 user ID 的消息。

换钥覆盖 outbox 两表密文及外部提现三项密文。旧序列化 Job 可按原入口消费，发送前持久化一次旧消息投递；采用 queue UUID 或调用级 ULID，不能由正文猜测业务发生身份。旧 failed_jobs、历史队列和历史业务事件不自动回填或批量重放。

## 隔离证据

- SQLite：新增 outbox、原通知/履约/续费及换钥相关回归 **205 tests / 1721 assertions** 通过；旧日志精确文本、Job 字段/数量、fallback 事件键、通知异常触发业务回滚均未弱化。
- 私有离线 MySQL 8.0.35：`scripts/verify-notification-outbox.py` 使用 `--network none`、tmpfs、新库和专用 socket；容器结束后删除，保留 `/tmp` 证据。测试使用 Http fake，未向真人、Telegram 或收费短信接口发请求。
- 两个独立 PHP 进程并发记录同运营发生键仅生成一个事件；同投递两个 worker 仅认领 1 次、传输 1 次。
- 注销先持有用户锁：发送方等待后看到 skipped，0 次传输；发送意图已提交时注销：保留 uncertain、清空载荷，晚到成功结果不恢复 PII。
- 假供应商已接受后注入本地日志事务异常：原投递保留 running + intent；租约过期补扫为 uncertain，之后执行不发第二次请求。
- 真正 database queue worker 分别消费事件与续费两类新 ID 消息成功；序列化 12 个新 Job 均未包含目标地址、变量、正文或供应商 token。反序列化先初始化安全空默认值，再还原 ID/队列元数据，避免 PHP promoted constructor 字段缺省导致续费 Job 读取未初始化属性。

最终 MySQL 新增用例 **20 tests / 102 assertions** 及完整进程验证通过目录：`/tmp/laravel13idc-notification-outbox-9xuzzo9v/`，其中 `result.json`、`queue-worker.log`、`queue-renewal-worker.log` 和 `transport.jsonl` 可复核。所属 33 个 PHP 文件 lint 无错误，定向 PHPStan/Pint 通过；全项目门禁以父任务汇总为准。
