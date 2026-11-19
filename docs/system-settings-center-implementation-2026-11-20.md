# 后台系统配置中心实施记录

日期：2026-11-20。授权与范围：[两批清单](system-settings-center-plan-2026-11-20.md)。没有新增迁移、表、枚举或 DTO。

## 第一批：外部接口

新增 Filament `/admin/settings`，分组保存易支付、邮件、短信、实名、GitHub/Google/QQ/微信及 Telegram 配置。通过安全投影呈现非密配置和密钥状态，不将明文凭据写入 HTML、Livewire、错误回填或审计。普通备注可选，留空使用稳定服务端描述。

新增固定角色：`settings-viewer`、`settings-payment`、`settings-notification`、`settings-identity`；仅持配置角色也能进入面板。原运营/通知模板权限不获得接口密钥配置权限，当前管理员需显式授予新角色。

`ConfigureIdentityAction`、`ConfigureEpayAction` 补事务内管理员有效性和分域权限核验，保留 CLI 契约。Web 空密钥保留原密文，启用接口时按合并留存值验证完整配置，提交后失效 scoped/shared 缓存。支付继续保留配置锁、基线 profile、旧 attempt 商户映射及迟到款验签。

支付宝/微信开关默认启用，接入新购、客户续费/恢复、充值及新支付尝试；现有请求优先幂等返回，自动提醒建单、全钱包/免费结算和历史回调不受新渠道开关阻断。前台统一显示可用渠道，暂停渠道的旧账单明确提示联系管理员。

原 `/admin/upstreams` 增加 Sub2API 创建、显式编辑及启停。现有 URL 由服务端禁止换站，新站使用新连接；名称和管理员密钥可以更新，空密钥保留。停用限制后续远程操作，已取得有效 driver 的执行按原绑定/租约收口；不改原远程意图、unknown 核对或历史交付/退款。

测试按钮单独限流，邮箱/短信只使用管理员本人已验证目标。短信测试采用显式 `system.settings_test` 模板；Telegram 仅 `getMe`。日志通道标记模拟，未知发送结果不自动重发。开发验收未发送真实通知，也未调用真实供应商。

验证：

- 修改前完整基线：1992 passed / 17063 assertions。
- 新增 70 项定向测试：70 passed / 570 assertions。
- 修改后完整 Pest：2062 passed / 17645 assertions。
- PHPStan：0 errors；全项目 Pint：PASS；diff whitespace：PASS。
- `python3 scripts/verify-system-settings.py`：独立 SQLite、独立 storage/APP_KEY、真实 HTTP 会话及 CSRF，10 组检查 PASS；真实通知/供应商变更均为 0。覆盖 419、403、只读角色、全部接口分组、空密钥原密文保留、失败不回填、Epay 表单、Sub2API 原地址保护、模拟测试及安全审计。临时站点和数据库自动销毁。

## 第二批

待实施站点展示和已有计费/调度参数。充值/提现金额范围、全局实名/手机准入、维护模式及独立告警收件人仍按清单第 5 节保留，不能由配置页顺带改动。
