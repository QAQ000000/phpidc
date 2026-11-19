# 架构清理第三批实施记录

基线 `7130b05`。用户已明确选择 [B3-01～03 推荐方案](architecture-third-batch-plan-2026-11-19.md)，并沿用验证后自动提交、必要时备份迁移预览的授权。

本批完成应收完整核验证明复用、停用账户任务边界和两条历史留存外键调整。未修改旧迁移、Specs、账本原值、历史金额或全局 User 关系。

1. `ReceivableVerificationCache` 加密保存完整有效扫描的摘要与总计，绑定原用户、revision、算法、APP_KEY 和持久失效标志。普通无 capture、无 flag-review 权限的准入可跨请求复用；原动作仍自行鉴权、验密和核对意图，并在资金锁内核对当前版本与标志。
2. 前台与后台完成结果均从原扫描开始计时，最多 600 秒，命中不续期。共享命中产生的新原请求也继承该起点；旧部署缺扫描起点的 ready 缓存视为未命中。缺失、损坏、过期、密钥/算法/版本变化重新完整核验；扫描中版本漂移不发布证明。
3. 清偿、冲正、纠正、明细等带 capture 请求继续完整扫描，人工复核和独立审计也不使用跨请求证明。同一原请求的完成结果保留既有复用规则。资金锁内竞争先回滚，再在锁外重新核验，不因新增前台 ready 缓存误转入等待。
4. 新任务认领先锁原 owner，再锁账单、实例、任务，核对三方原归属。软删或匿名化账户不新增认领、不增加 attempts、不调用驱动；原 pending 保留待核对。Provision 入站及服务 create/pay/control 意图准备再次核对原执行资格。
5. 已授权调用期间停用账户，其返回结果仍可使用局部 `withTrashed()` 按原身份收口；原付款、租约、绑定、终态和归属条件继续检查。三个交付分支补齐原 user_id 比较，防止把旧返回凭据落给变更归属的实例。闭户通知继续抑制。锁释放后已授权调用可能继续执行；经典采购协议内部没有逐 POST 新增账户检查。
6. 新增 `2026_11_19_000018_restrict_delivery_history_deletions`，仅将 `redemption_codes.product_id`、`instance_upstream_bindings.instance_id` 改为 ON DELETE RESTRICT。SQLite 在迁移事务外关闭 FK 检查，再原子重建并保留自增高水位、核对完整性；MySQL 每表原子 ALTER，按元数据支持部分完成后重跑。MySQL 目标约束使用 `_retained_foreign` 名，避免同一 ALTER 沿用旧名的 COPY 算法冲突。

B3-01 是完整核验结论的有限期复用。用户已接受：绕过合法 Action 且不提升 revision 的直接 SQL 篡改，在已有证明有效期间可能不被普通新请求立即发现；过期后完整核验和独立审计继续检查。合法证据写入提升 revision，旧证明立即失效；不承诺独立审计对每个用户固定五分钟内检出。未实现按根增量余额投影。

最终门禁：Pest **1773 passed / 14699 assertions**（130.70 秒）、PHPStan **0 errors**、Pint **PASS**；13 个改动 PHP 文件语法通过，Python 容量脚本编译通过。三个新增测试文件共 58 个用例，旧测试及 Specs 均未修改。基线为 1715 passed / 14374 assertions。

任务专项覆盖停用/匿名化零 claim、归属不一致、已认领未执行时停用、三交付分支调用期间停用和归属变化、旧邮箱新账户隔离、前置同步/核价后停用，以及付款/租约/绑定/终态变化拒绝结果。应收专项覆盖起点 TTL、不续期、旧格式升级、损坏证明、版本/密钥/算法/标志变化、真实佣金成熟及清偿/冲正、完整复核/审计、锁内冲突和批准的 SQL 篡改检测窗口。

独立文件 SQLite 与 network=none 的 MySQL 8.0.35 均完成真实 **23→24** 增量升级、重复执行和一表已完成后的重跑：60 张原表、25 条原行、密文、列、索引、其他外键及 SQLite 自增序列保持。available/reserved/sold 三种库存的父商品、仅有历史绑定的父实例，以及被案件引用的绑定，物理删除均被拒绝；唯一约束与完整性检查通过。证据保留在 `/tmp/laravel13idc-batch3-fk-2hqrv0e2/summary.json`，一次性容器已删除。

`python3 scripts/verify-receivable-capacity-mysql.py` 在离线 MySQL 8.0.35 / Redis 7、network=none、无宿主端口的合成环境完整通过。一个用户含 10000 笔应收根、60000 笔清偿、40000 笔冲正和 100000 条对应流水；会话默认 READ COMMITTED，完整核验明确使用 REPEATABLE READ。五次完整核验为 **53.04–60.39 秒**，每次 1304 SQL，分配内存峰值 48.5 MiB。20 个不同原请求的普通准入复用同一完整摘要，**P50 2.59 ms、P95 3.50 ms、最高 10.60 ms，合计 40 SQL，历史证据 SQL 为 0**。这是准入层及短用户锁测量，不含 HTTP、渲染和资源加载。另一个真实新增提现 Action 用时 31.36 ms，正确进入 pending；这段未单列历史 SQL 统计。实际分页数据层 20 次、每页 20 行，P95 179.62 ms，不含 HTTP/渲染。

真实佣金转钱包申请与批准后，钱包 18.00、佣金 99.00，重放仍只有一条 payout。独立写进程在读进程取得原资金用户锁前执行真实冲正，revision 1→2；读进程拒绝旧证明并重排原请求，没有创建该提现。完整重核正确发现 1.00 应收；真实登记外部已收清偿后 revision 2→3，再以原稳定请求完成核验与批准，最终钱包 **19.00**、佣金 **98.00**，仅两条唯一 payout。真实资金事务锁样本最高 55.81 ms。该结果是同宿主合成验收，不作为生产 SLA。HTTP/邮件/队列均替身，无真实出款、发送或供应商动作；一次性容器已清理。最终完整证据：`/tmp/laravel13idc-receivable-capacity-vjyg_nfo/result.json`。早期专项证据另在 `/tmp/laravel13idc-receivable-capacity-dsmlwxia/`。

原预览已核对 PID 1728214 的 PHP 8.4、项目 cwd、SQLite 路径及独立 storage；无本项目业务 worker/scheduler。短维护后以 SQLite backup API 建立一致数据库备份，并保存原环境、APP_KEY、封印、私有文件和缓存文件，备份位于 Web root 外的 `/root/.local/state/laravel13idc/architecture-batch3-preview-deploy-kywu0ej6/`，目录/文件权限 0700/0600，凭据与备份不提交。随后仅执行已批准的 `000018`，预览 **23→24 条迁移**。

迁移后逐表核对 **57 张持久表、734 条原行原列**完全保持，包括原密文和 jobs=1；原列定义、索引、其他外键和自增序列保持，两条目标删除规则为 RESTRICT。`quick_check`、`foreign_key_check`、全部备份哈希、原环境/密钥/封印以及 `app:deployment-check` 均通过。已恢复访问，`/up`、`/login`、`/admin/login` 均 HTTP 200；未启用业务消费者或真实告警。预览升级证据在该私有备份目录的 `verification.json`。

IMPLEMENTED: B3-01～03、对应契约、回归及容量脚本适配。
NOT DONE: 生产启用、真实外付/通知/供应商动作及按根增量投影不在本批范围。
DECISIONS: 采用用户批准的 600 秒扫描起点证明、两条 RESTRICT 外键和原身份任务边界。
CONCERNS: 无 revision 的直接 SQL 篡改存在已批准的证明窗口；完整扫描成本仍随历史增长。
GATES: pest 1773 passed / 14699 assertions | phpstan 0 errors | pint clean；离线完整容量/恢复与两库升级 PASS；原预览 24 条迁移、HTTP 200。
SPEC CASES: 0（未改动）。
