# 库存运营后台与缺货处理方案

状态：**已按人工批准方案实现，隔离环境验收通过；未部署业务库**。日期：2026-11-17。

## 必要前置与拟批准的迁移

章程要求 Filament v5 后台与 Shield/Spatie 权限体系。依赖已安装，但当前迁移中没有 Spatie 的权限表，User 也没有角色关系或 FilamentUser 入口授权。不能用“已经登录”或 APP_ENV=local 放行普通客户到后台，也不新增临时 is_admin 字段来替代既定权限体系。

已批准一份独立新增迁移，按已安装 `spatie/laravel-permission` 的官方迁移模板创建以下五表，使用 web guard，不启用 teams：

| 表 | 字段与约束 |
|---|---|
| permissions | id、name、guard_name、timestamps；name+guard_name 唯一 |
| roles | id、name、guard_name、timestamps；name+guard_name 唯一 |
| model_has_permissions | permission_id、model_type、model_id；组合主键，权限外键及模型索引 |
| model_has_roles | role_id、model_type、model_id；组合主键，角色外键及模型索引 |
| role_has_permissions | permission_id、role_id；组合主键及对应外键 |

不重写现有迁移，不增加 users 字段，不改资金、库存、实例、任务、枚举、DTO、契约或业务不变量。迁移先在临时数据库验证；本次批准不等于授权对业务数据库执行迁移或替真实用户授予权限。

授权初始化由受信服务器命令执行，显式指定用户 ID、角色和非空原因，事务内写审计。注册流程不授予后台权限，不按邮箱、用户 ID=1 或默认环境推断管理员。撤销权限也必须可审计。不会自动创建生产管理员或生成默认密码。

## 页面与权限契约

使用 Filament 的 `/admin` 面板及登录入口，现有 `/console` 保持客户中心。User 实现 FilamentUser 并按面板 ID 与显式权限判断入口，开发与生产使用同样的权限检查。

| 能力 | 库存查看员 | 库存操作员 |
|---|---|---|
| 库存概览、批次与状态查询 | 允许 | 允许 |
| 本地已付款未交付任务查询 | 允许 | 允许 |
| 导入卡密 | 拒绝 | 允许，必须填原因 |
| 明确确认后重试失败任务 | 拒绝 | 允许，必须填原因 |
| 查看/导出卡密明文、修改已售库存 | 拒绝 | 拒绝 |
| 退款、钱包调账、上游操作、授予角色 | 拒绝 | 拒绝 |

查看、导入、重试分别检查权限；按钮隐藏不能代替服务端授权。所有 Web 写操作记录真实 actor_id，而服务器 CLI 保留 system 审计语义。接口仍遵循入口→Action→Driver 的方向；资源和页面不直接更新库存或任务。

交付页面：

| 页面 | 内容与交互 |
|---|---|
| 库存概览 | 按商品统计 available/reserved/sold、已付待交付数量、缺货提示；支持商品筛选、搜索和分页 |
| 卡密库存 | 显示 ID、商品、批次、状态、关联实例、预留到期时间及售出时间；不查询或输出 code/code_hash |
| 导入卡密 | 选择本地卡池商品，一行一张卡密，填写导入原因；显示新增数和重复数，提交后清空明文 |
| 已付待交付任务 | 显示账单号、商品、任务状态、尝试次数、可用库存、下次重试时间与失败原因；查看或跳转到补货 |
| 重试确认 | 仅对当前绑定的 failed 任务开放；复核 Paid 状态、实例可交付状态与本地产品；填写原因并明确确认 |

界面区分缺货候选和其他交付异常，不通过错误字符串把所有任务失败都认定成缺货。商品范围限本地 redemption 与 Sub2API local_pool；查询使用 DriverType 枚举，带上游账号的 Sub2API 与经典 ZJMF 不进入库存操作范围。

桌面端使用统计卡片和分页表格；窄屏折叠次要列、保留状态与处理入口，导入/确认表单单栏显示。空库存、无待处理任务、无权限、已处理/状态变化和操作失败均有明确反馈，不只靠颜色表达。

## 付款后缺货的处理闭环

1. 付款成功后账单保持 Paid，不重复扣款或改回 Unpaid。未交付任务进入现有退避重试；仍在 pending/running 的任务不由后台强行重置租约。
2. 运营人员在后台看见缺货商品与相关待交付任务，导入新卡密。导入继续使用加密存储及 product_id+HMAC 唯一去重，不修改已售或已绑定记录。
3. pending 任务按现有调度恢复；达到最大次数的 failed 任务依 INV-T3 保持终止，**不在补货后自动复活**。有权操作员确认原因后单独重试，复用既有任务和幂等键。
4. 重试提交后才派发队列；旧 worker、重复点击、已退款账单、终态实例或换绑任务都不能导致重复发卡。补货与恢复均保留审计。
5. worker 交付成功后显示成功状态，客户通过本人/no-store 页面领取。售出卡密不再进入可用库存。

本轮改善人工处置入口和可见性，不擅自增加自动退款、自动购买上游库存或无限重试。取消订单退款继续使用已交付的退款业务规则；库存操作员不自动获得财务权限。

## 验收计划与当前基线

- 匿名/普通客户/库存查看员/库存操作员的页面及伪造写请求权限边界。
- 导入去重、库存加密、批次审计不含明文，页面和 Livewire 响应不泄漏卡密。
- 已付款缺货→退避/失败→后台补货→显式重试→同一任务交付一张卡，全程没有第二次扣款。
- 重复点击、已退款订单、终态实例、权限撤销和任务状态变化的拒绝分支。
- 三道 Gate、隔离 HTTP/队列验收，以及桌面和窄屏浏览器检查。

开工基线：Pest 175 passed / 1312 assertions；PHPStan 0 errors；Pint clean。剩余 Specs 0，本轮没有修改其文件或计数。业务数据库未修改。


## 实现判断与运维入口

- `/admin/inventory`、`/admin/inventory/cards`、`/admin/fulfillment` 使用 Filament 页面；过滤和分页是普通 GET，导入和重试是带 CSRF 的 POST。卡密不绑定 Livewire 属性，不在校验失败后写入 session old input，也不自动裁剪卡密两端空格；按原字节计算 HMAC。
- 权限名为 `inventory.view`、`inventory.import`、`inventory.retry`，guard 为 `web`。Spatie 执行实际权限检查，Shield 配置同名 custom permissions，关闭隐式 panel_user/super_admin 授权。本轮不注册 Shield 角色管理资源：库存操作员没有授予权限的入口。
- 卡密页只 SELECT 元数据列；任务页不查询 payload 或实例凭据。运行中的任务不开放手动重试；failed 任务在事务内按 `invoices → instances → fulfillment_tasks` 加锁，重新检查付款、当前绑定、未交付状态和本地卡池范围，提交后才派发队列（INV-L1、INV-T3、F02）。本操作不触碰钱包余额。
- 导入和重试的 Web 审计记录实际 actor_id，CLI 保留 system 语义；操作原因必须填写。角色授予/撤销也单独审计。没有默认管理员账号。
- 手机端任务改为逐条卡片，保留完整失败原因、补货链接与重试确认。后台统一 private/no-store；客户领取页沿用原有归属检查与 no-store。

部署需要由有权运维人员在备份和迁移审核后执行新增迁移、`php artisan filament:assets`，并给**明确存在的用户 ID**授权。此次交付没有执行这些生产操作。示例（将 `<用户ID>` 替换为经核实的目标）：

```bash
php artisan inventory:role <用户ID> inventory-viewer --reason="库存只读验收"
php artisan inventory:role <用户ID> inventory-operator --reason="授权处理本地卡池缺货"
php artisan inventory:role <用户ID> inventory-operator --revoke --reason="撤销库存操作权限"
```

撤销某个角色不会移除该用户的其他角色或直接权限。将操作员降为查看员时，先撤销 operator，再按需授予 viewer。角色命令不会创建用户，不会修改邮箱或密码。

## 验收结果及边界

- Pest 新增 14 个场景；总计 **189 passed / 1426 assertions**。PHPStan **0 errors**；Pint **clean**。
- `python scripts/verify-inventory-admin.py`：临时 SQLite、独立 storage/APP_KEY、临时发布的 Filament 静态资源和 loopback HTTP 服务，验证真实登录/session/CSRF、权限边界与撤销、已付缺货、导入后保持 failed、显式恢复、重复提交拒绝、单个数据库作业与独立 worker 交付、卡密加密及钱包仅扣一次。所有临时资源结束后清理。可用 `--preview` 在交互终端保留页面供浏览器验收。
- Playwright：Filament 登录、1440px 库存概览、390px 任务卡片及补货提交通过；提交后 textarea 清空，HTML 不含卡密。移动端 documentWidth=390，与 viewport 一致。
- 本轮迁移在隔离 SQLite 验证；**未执行 MySQL 并发压力验收，未迁移业务数据库、未授予真实用户权限、未部署生产静态资源或队列**。SQLite 验收不能证明 MySQL 行锁竞争行为。
- 本地库存为空只是缺货候选；其他错误仍需人工核查。自动退款、上游补货采购、卡密明文导出与财务权限管理不属于本轮。既有 pending 任务继续退避调度，failed 不因导入库存自动恢复。未调用真实上游 API 或支付渠道。
