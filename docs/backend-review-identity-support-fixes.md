# 账户查询与工单修复

日期：2026-11-18（Asia/Shanghai）。对应本轮复审中的邮箱扫描/锁范围、工单正文容量、关联服务全量加载三项问题。

## 行为

- `CustomerEmailLookup` 统一邮箱查询：生产 MySQL 沿用既有 `utf8mb4_unicode_ci` 邮箱唯一索引，使用等值条件；SQLite 保留原有 `LOWER(email)` 历史大小写/歧义语义。注册检查包含软删除身份，数据库唯一索引继续阻止并发重复注册。
- 找回与重置密码先读取唯一候选 ID，再按该主键加锁，并在锁定查询中复核当前邮箱与软删除状态。查找阶段不再对全表扫描加 `FOR UPDATE`；令牌节流、一次消费、remember token 轮换和旧会话撤销保持原逻辑。
- 工单正文保留 20,000 字符限制，并在写事务前校验 UTF-8 不超过 65,535 字节，覆盖新建、客户回复和客服回复。过长时返回 `body` 字段错误，不截断、不修改 TEXT 列。
- 工单页关联服务按本人范围搜索名称、IP、完整 ID；SQL 读取最多 51 条用于判断是否需要缩小范围，页面最多显示 50 个选项。历史与已删除的本人服务仍可搜索关联；提交时仍在事务中验证归属。搜索 `%`/`_`/`!` 按字面处理，工单分页保留搜索参数，校验失败时恢复原选中的本人服务且仍不超过 50 项。

## 验证

新增 `ReviewIdentitySupportFixesTest` 13 项，覆盖混合大小写及已注销邮箱、密码重置作用域/令牌消费、候选邮箱变动复验、发送节流、65,535/65,536 字节边界、20,000 个汉字、原字符上限、客户/客服拒绝及历史记录回滚、超过 50 个服务的搜索/归属/选中项恢复。

隔离 APP_KEY、storage、SQLite `:memory:`，运行新专项与既有 `CustomerPortalTest`、`CustomerIdentityTest`、`OperationsExpansionTest`：**115 passed / 730 assertions**。相关生产 PHP 文件 PHPStan **0 errors**；Pint 检查通过。没有修改旧测试或冻结规范、数据库结构，没有向业务数据库写入或发信。

独立离线 MySQL 8.0.35 验证由 `scripts/verify-review-identity-cms-mysql.py` 执行，使用 `--network none`、临时 socket、tmpfs 数据目录、新测试库和隔离 APP_KEY/storage；运行后已移除容器。新账户/工单专项与 CMS 别名专项合计 **29 passed / 315 assertions**。此外，用 1,002 个用户的实际查询验证：旧 `LOWER(email)` 查询 `type=ALL / rows=1002`，新查询 `type=const / key=users_email_unique / rows=1`。实际 Action 的候选 ID 查询不加锁，随后按邮箱和该主键 `FOR UPDATE`；另一连接更新无关账号耗时 **0.57 ms**，更新被锁账号命中 MySQL **1205**（约 1,001 ms）。这证明当前锁范围，不是生产耗时承诺。

同一隔离 MySQL 中，16,383 个四字节字符加 `abc` 的正文实测 `OCTET_LENGTH=65535 / CHAR_LENGTH=16386`，读回逐字节一致；65,536 字节输入返回 `body` 校验错误，工单回复数不变。工单客户/客服超长回复和 20,000 个汉字也由上述新专项覆盖。

脚本、SQL/EXPLAIN、测试输出及并发结果保存在 `/tmp/laravel13idc-review-identity-cms-kkd2gahn/`：`pest.log`、`setup.json`、`setup.php`。没有修改现有业务/预览数据库，既有 MySQL 容器未变动。未改变已明确延期的账户注销、真实身份供应商或通知事件 outbox 范围。
