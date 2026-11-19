# 后台系统配置中心实施记录

日期：2026-11-20。授权与范围：[两批清单](system-settings-center-plan-2026-11-20.md)。没有新增迁移、表、枚举或 DTO。

## 第一批：外部接口

新增 Filament `/admin/settings`，分组保存易支付、邮件、短信、实名、GitHub/Google/QQ/微信及 Telegram 配置。通过安全投影呈现非密配置和密钥状态，不将明文凭据写入 HTML、Livewire、错误回填或审计。普通备注可选，留空使用稳定服务端描述。

第一批提交：`6179815`（`feat: add audited external interface settings center`）。

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

## 第二批：站点展示与既有策略

新增 `settings-site`、`settings-billing` 固定角色及站点、计费、自动化三个分组。站点九个固定键由 `SiteSettings` 注册，`SaveSiteSettingsAction` 保存；计费和调度七个既有键由 `PolicySettings` 注册，按域交给 `SaveBillingSettingsAction` / `SaveAutomationSettingsAction`。三者均在事务内重新核对操作者及权限，只审计变更键，提交后失效缓存。

商城、客户中心与登录注册页统一消费名称、Logo、客服、纯文本公告、备案和版权。首页 SEO 和 `llms.txt` 使用配置；产品/文章专属 SEO 及 canonical、sitemap、支付/OAuth 回调的安装域名来源不变。Logo/客服只允许 HTTP(S) 或单斜杠站内路径，所有 HTML 文本均转义；两种 Markdown 入口一致展示站点与公告。

计费/自动化直接连接清单 B2 中的真实消费者，输入范围与默认值按批准清单执行。已有账单到期时间、账单分佣快照、已产生佣金成熟时间不会因保存重算；调度参数仍受原统一维护 100 项/5 秒等硬预算约束。页面解释生效范围，保存不启动或停止 worker/timer。

交叉审查补齐两项实际输入问题：Web 备注统一限制 255 字，以匹配既有 MySQL 审计列；明确输入的备注 `0` 保留。注册表中秘密字段及易支付/Sub2API 密钥不经过 `TrimStrings`，避免有效密钥首尾空格被改写；全空白输入继续保留旧密文，普通文本仍正常裁剪。

验证：

- 新增站点/策略/展示与备注回归；定向的配置中心、Sub2API、策略测试：81 passed / 543 assertions。
- 完整 Pest：2164 passed / 18207 assertions；PHPStan：0 errors；全项目 Pint：PASS；diff whitespace：PASS。
- `python3 scripts/verify-system-settings.py`：独立 SQLite/storage/APP_KEY，真实 HTTP session/CSRF，13 组检查 PASS。增加站点实际消费、策略保存与范围、255 字备注上限、带首尾空格的 SMTP/OAuth/Epay/Sub2API 密钥逐字节保留。真实通知与供应商变更均为 0。

## 预览激活

预览地址 `http://127.0.0.1:59493`，后台入口 `/admin/settings`。只向既有全权预览管理员（用户 ID 3）显式授予五个配置管理角色，已核对账户有效、原 access-manager/finance-operator/upstream-operator 身份及新增 Gate/面板准入，产生五条系统授权审计。未升级前台账号或其他只读后台账号。

授权前使用 SQLite 在线 backup，并检查备份完整性；备份位于服务器私有目录 `/root/.local/state/laravel13idc/settings-center-backup-20261120-005732/acceptance.sqlite`。授权前后 `system_settings` 全行摘要一致，没有改动真实支付、邮件或其他业务配置。`/up`、`/admin/login`、`/login`、首页 HTTP 200；没有新增迁移或触发真实通知/供应商调用。

额外以原管理员建立短命验收会话，对预览 13 个配置 Tab 执行真实 HTTP GET，均返回配置页 HTTP 200；会话已销毁，没有保存配置或调用测试端点。本轮未做浏览器视觉验收。

两批范围已实现。充值/提现金额范围、全局实名/手机准入、维护模式及独立告警收件人继续按清单第 5 节独立待定。本次没有执行推送。

```text
IMPLEMENTED:   两批配置中心及既有消费者；无 Specs 用例迁移
NOT DONE:      清单第 5 节单独待定业务；真实外部通知/供应商验收未执行
DECISIONS:     按已批准清单限定白名单、历史快照和既有硬预算；零迁移
CONCERNS:      连通性按钮会按已保存接口执行，仅由管理员显式触发；本轮隔离验证
GATES:         pest 2164 passed / 18207 assertions | phpstan 0 errors | pint clean
SPEC CASES:    0
```
