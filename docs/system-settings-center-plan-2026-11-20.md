# 后台系统配置中心：源码复核与实施清单

日期：2026-11-20。对照提交：`e02f3014a7a1d2bbfd4220c420482d4fecb5028c`。

状态：用户已于 2026-11-20 批准分批实施；第一批完成，见[实施记录](system-settings-center-implementation-2026-11-20.md)。第 5 节待定业务参数不在批准范围内。

## 1. 结论与纠正

可以复用 `system_settings`、scoped `SettingService`、现有 Filament 面板和 Epay 历史凭据机制，不需要为配置中心新增表或迁移。但这不是单纯补表单：每个参数必须同时有受控写入、实际消费者和生效范围。

| Gemini 方案中的说法 | 当前源码与实施要求 |
| --- | --- |
| 只需升级 `ConfigureIdentityAction` 的管理员身份 | `ConfigureEpayAction`、`ConfigureSub2ApiAction` 也只有 CLI/system 审计，需要补 Web actor、锁内身份/Gate 校验及管理员审计。 |
| Sub2API 已有创建、编辑、启停能力 | 目前 Action 只创建连接。连接仍归 `upstream_apis`，不能搬到全局设置；编辑还要保护原订单的远程地址。 |
| 保存支付配置即新建 profile | 已有有效 current profile 时，只有商户凭据实际变化才产生新 profile；空密钥或无变化保存不制造新快照。完整旧凭据没有 profile 时仍需建立基线快照。历史 attempt 始终按原 profile 验签。 |
| 修改 `ConfigureWalletTopupAction` 的 1/10000 即可参数化 | 该处只是审计 JSON。真实限制在 `WalletTopupIntent` 和 `CreateWalletTopupInvoiceAction`；D01/D03 已批准的 CNY 1–10000 边界不能因零迁移被静默改变。 |
| 新增 `billing.unpaid_expire_minutes` | 已有消费者读取 `billing.invoice_expiry_minutes`，不得增加无人消费的平行键。 |
| `billing.renewal_notice_days` 只是提醒天数 | 同时控制自动生成续费账单的提前量；小时/日周期分别最多提前一小时/一天。 |
| 普通 `auth` 足以保护后台设置 | 还需分域 Gate、Filament 页面准入、面板准入与首页入口。当前没有 `settings.*` 权限，也没有自动继承全部新权限的万能超管。 |
| 设置独立告警邮箱/TG Chat ID 即可 | 当前告警面向具有 `finance.view` 的用户及其有效通知目标。任意独立目标会改变收件人授权语义，不是补一个配置键。 |

源码入口：

- [ConfigureIdentityAction](../app/Actions/ConfigureIdentityAction.php)、[ConfigureEpayAction](../app/Actions/ConfigureEpayAction.php)、[EpayProfiles](../app/Support/EpayProfiles.php)。
- [ConfigureSub2ApiAction](../app/Actions/ConfigureSub2ApiAction.php)、[DriverManager](../app/Services/DriverManager.php)、[ProvisionRemoteCardAction](../app/Actions/ProvisionRemoteCardAction.php)、[RecoverSub2ApiCardAction](../app/Actions/RecoverSub2ApiCardAction.php)。
- [WalletTopupIntent](../app/Support/WalletTopupIntent.php)、[CreateWalletTopupInvoiceAction](../app/Actions/CreateWalletTopupInvoiceAction.php)、[D01/D03 决议](remaining-capabilities-decisions-2026-11-18.md)。
- [AdminRoles](../app/Support/AdminRoles.php)、[User](../app/Models/User.php)、[AdminPanelProvider](../app/Providers/Filament/AdminPanelProvider.php)。

## 2. 页面与共同保存约定

在现有面板新增 `/admin/settings`，用 Tab 分组，按配置域独立保存；现有 `/admin/upstreams` 和充值设置路径继续可用，共用同一 Action。上游 Tab 提供现有连接管理入口，Sub2API 补在连接管理中，避免两套连接 CRUD。

首期六组：站点、支付、邮件/短信、实名/第三方登录、上游连接、既有计费/调度参数。维护模式和独立告警目标不先做无效开关。

共同要求：

1. 每组固定键名和类型，不做任意 key/value 编辑器，不接受 profile、配置锁、APP_KEY、APP_URL、数据库或 Redis 参数。每组提交原子保存，事务提交后逐键 `forget()`；失败不改变配置或缓存。
2. 普通保存备注可选，服务端生成稳定审计描述；记录 actor、修改键名和安全上下文，不记录密码、API key、token 或明文 secret。
3. Web 表单密钥只显示“已配置/未配置”，空输入保留原值及密文；首次启用缺少必要凭据时明确校验失败。空输入不得变成清空密钥。CLI 已有显式清空语义单独保留。
4. 密钥保护覆盖 HTML、Livewire 状态、校验失败的 old input、响应、异常和审计。`mail.username`、SMS/KYC 的 API 凭据当前也加密，应沿用该保护，不能只处理名字含 password/key 的字段。
5. Web 入口始终传入当前管理员，Action 在事务内核对其仍有效及 Gate；不能把缺少 actor 的 Web 请求降级为 system 操作。Epay 保留“配置元数据锁先于用户/财务锁”的顺序。
6. 配置保存不自动调用上游或发送测试消息；测试使用已保存配置，由按钮单独触发，并设用户维度限流。

建议新增固定权限/角色：`settings.view`，以及分别管理站点、支付、通知接口、身份接口、既有计费策略的 `settings.{site,payment,notification,identity,billing}.manage`。上游仍用 `upstream.view/configure`；充值开关仍用 `finance.wallet.configure`。只读权限不能调用保存或测试接口，跨组混入键名应整体拒绝。

同步修改 `AdminRoles`、Page 的 `canAccess()`、`User::canAccessPanel()` 和后台首页候选入口。普通运营/通知模板管理角色不自动获得密钥配置权。新角色通过已有授权 Action 明确授予指定管理员；不能因为现有账号曾使用“全部业务权限”预设就假定它自动拥有新增角色。

## 3. 第一批：外部接口配置入口

按以下小提交实施，各自通过定向回归后自动提交。

### A1：权限、页面和身份/通知配置

升级 `ConfigureIdentityAction` 的 Web actor 支持，保持 CLI 调用兼容。白名单、校验和保存能力共用；按提交键对应的配置域检查权限，不能用一个宽泛 Gate 允许任意身份/通知键写入。

沿用实际键名：

| 域 | 键 |
| --- | --- |
| 邮件 | `mail.transport/host/port/username/password/from_address/from_name` |
| 短信 | `sms.driver/binding_enabled/binding_template`、`sms.idcsmart_endpoint/idcsmart_api/idcsmart_key/idcsmart_sign/idcsmart_templates` |
| 实名 | `kyc.enabled/driver`、`kyc.idcsmartali_endpoint/idcsmartali_api/idcsmartali_key/idcsmartali_biz_code` |
| OAuth | `oauth.{github,google,qq,wechat}_enabled`、`oauth.{provider}_client_id/{provider}_client_secret` |
| Telegram | `telegram.bot_username/bot_token`、`oauth.telegram_enabled` |

不要用 `kyc.endpoint`、`sms.api` 等简写创建平行配置。邮件驱动仍是 `LogMailDriver`，其中已支持动态 SMTP；465 由底层自动使用 smtps，当前有效配置无需修复。首期沿用端口与既有安全协商行为；若增加显式加密模式，必须同时接通 Action 校验和运行时读取。

启用 SMTP、SMS、KYC、OAuth 时校验合并留存值后的完整配置，不能出现“开关已开但必要字段为空”。关闭某功能无需重新输入全部密钥。

### A2：易支付管理

继续使用 `payment.epay_api_url/epay_pid/epay_key` 和 `ConfigureEpayAction`。补 Web 权限、管理员审计、空 key 保留。保留配置锁、无历史 profile 的旧 attempt 归档、商户替换和迟到付款处理。

如一并交付支付宝/微信开关，使用固定新键 `payment.epay_alipay_enabled`、`payment.epay_wechat_enabled`，缺省均为 true，保持现有行为。UI 和新 attempt 创建都要消费；关闭仅阻止新的支付发起，历史 attempt 验签、回调、记账照旧。全钱包付款和免费订单不因外部渠道关闭被拦截。不能把渠道开关塞进历史凭据验证，使老款无法入账。

### A3：Sub2API 连接管理

补 actor/Gate、显式连接 ID 编辑、名称、密钥留空保留和启停；`config.mode=remote_api`、`protocol=balance-codes-v1` 固定。

首期已有连接的地址只读。换服务地址新建连接，再显式调整商品绑定；保留旧连接供历史订单与不确定请求核对。同一远程服务可更新名称或轮换管理员密钥。

原因：订单加密参数及 `remote_delivery` 已保存原地址，发码和交付都会核对。直接换 URL 虽不会把旧单静默发到新站，却会阻断已付款旧单和人工核对。普通编辑不得清空原请求意图、幂等键或已取得结果。

启停文案明确“暂停远程操作”：停用会阻断新 driver、待执行任务和人工远程核对，不能写成“仅停止新销售”。停用前已取得有效 driver、进入执行的操作仍按原绑定、账单及租约规则收口；停用不能撤销已取得 driver 的执行。历史已交付凭据与本地退款证据继续可读。

### A4：受控测试按钮

- 邮件：仅向操作者本人已验证邮箱发送固定测试内容；短信仅向本人已验证手机号且有明确模板映射时开放。页面加载或保存不发送，日志驱动明确显示“模拟”，不显示真实送达。
- Telegram：`getMe` 仅核对已保存 bot 凭据，返回安全身份字段；不把 token 或带 token 的原始 URL/错误响应回显，不自动发 TG 消息。
- 测试端点要求所属配置域 manage 权限、CSRF 和独立限流。返回单次调用结果，区分拒绝、可重试和结果不明；结果不明不自动重发。
- 现有通知 outbox 限制本人有效目标，不能拿来发送到任意表单地址。测试可复用驱动和身份邮件的单次结果语义，无需为探测再建通用通知框架。

## 4. 第二批：站点展示与已有策略

白名单可共用字段定义与类型校验，保存 Action 按站点/财务/调度权限拆分，避免一个大 Action 同时修改 Logo、支付和资金规则。

### B1：站点展示

拟新增或暴露：`site.name`、`site.logo_url`、`site.icp_number`、`site.copyright`、`site.support_url`、`site.announcement`、`site.seo_title`、`site.seo_description`、`site.llms_description`。

名称缺省回退 `config('app.name')`，其余空值回退现有展示。文本按长度验证并转义，公告首期为纯文本；Logo/客服链接只允许站内路径或 HTTP(S)，不开放任意 HTML/脚本。

同时接通公共布局、客户中心布局和 `llms.txt`；`site.seo_title/seo_description` 用于首页并作为没有专属元数据时的回退，不覆盖产品/文章 SEO。`sitemap.xml`、canonical、支付及 OAuth 回调仍从现有 `APP_URL` 构造，站点展示配置不改变安装域名。

### B2：现有计费及调度键

以下范围为表单校验建议，不改变现有金额规则；缺省采用当前行为。

| 键 | 默认 / 建议输入范围 | 实际生效范围 |
| --- | --- | --- |
| `billing.invoice_expiry_minutes` | 30 / 整数 1–10080 | 新建账单有效期，以及重新确认的续费恢复报价窗口；已有账单 `expires_at` 不重算。 |
| `billing.renewal_notice_days` | 7 / 整数 0–365 | 下一轮生命周期自动建续费账单及提醒，保留短周期上限、唯一键及锁内边界核对。 |
| `billing.renewal_grace_days` | 7 / 整数 1–365 | 新建续费根账单有效期；保留至少当前时间加 30 分钟或服务到期加宽限的现行计算。 |
| `affiliate.freeze_days` | 7 / 整数 0–365 | 新账单分佣快照；不重算旧账单及已有佣金的成熟时间。 |
| `lifecycle.urgent_batch` | 100 / 整数 1–100 | 生命周期紧急队列选择上限，统一维护入口该部分最多 50，与轮询共用单轮 100 项/5 秒预算。 |
| `lifecycle.rotation_batch` | 100 / 整数 1–100 | 生命周期轮询选择上限，统一维护入口该部分最多 50，与紧急队列共用单轮 100 项/5 秒预算。 |
| `lifecycle.catalog_batch` | 100 / 整数 1–100 | 商品目录派发上限；统一维护仍受当前 100 项/5 秒预算约束。 |

当前独立目录派发代码允许最多 200；首期表单建议仅开放 1–100，不混改独立/统一入口的调度算法。所有范围需要在实施前与现有测试及具体消费者核对，不能单改表单就声称调整了全局预算。

充值启停继续复用 `ConfigureWalletTopupAction` 和现有权限，不改为一般缓存读写：创建充值单与修改配置共用配置锁，配置锁先于用户锁；锁内优先返回既有请求，再从 DB 读取当前开关。开关改变不影响旧单付款和回调。

## 5. 单独确定后再实施的业务参数

以下不混入前两批，也不先保存无人消费的键：

1. **充值/外部提现金额上下限**：D01/D03 当前为 CNY 1–10000。建议将来只允许在此硬边界内收窄；若需扩大，单独批准。须同步建单锁内校验、前端输入、审计及历史幂等；不能只改常量引用或审计数字。普通佣金转钱包、外部佣金提现、钱包结清是三条不同流程。
2. **全局手机/实名下单准入**：明确与产品要求取 OR、仅约束新请求、既有请求先幂等重放；不能替代经典上游主账号试用资格，也不恢复已暂停的新试用。
3. **维护模式**：先定义只停售还是同时限制登录、客户操作；明确后台恢复、支付回调、健康检查及既有付款/履约的处理，避免一个全站 503 开关切断收款闭环。
4. **独立告警邮箱/TG 群**：先确定授权、验证、撤销及注销后的接收语义。首期保留当前 `finance.view` 收件人体系，可展示现有绑定状态和管理入口。
5. **提高调度硬上限、延长预算或改变周期**：不由普通配置页顺带实施；配置数值不代表 worker/timer 已启用。

资金规则变更的人类决定要求来源：[agent-guardrails.md §1.3](agent-guardrails.md#13-never-invent-a-schema-or-contract-change)：“Changing them requires a human decision”。本文未把参数提案当作该决定。

## 6. 验收与交付

每批使用隔离数据库和 mock/loopback 对端，覆盖新增行为；不得在预览业务库运行破坏性测试，也不因配置页开发主动向真实供应商、收件人或短信平台发请求。

- 未登录按现有 HTML 登录约定跳转；无权限 403；仅持有 settings 权限的管理员也能进入面板，既有业务角色准入保持原规则；跨域键注入及停用管理员均被拒绝。
- 秘密哨兵不进入 HTML、Livewire、old input、日志或审计；空密钥不覆盖原密文，首次启用缺凭据失败；CLI 原契约保留。
- Epay 新旧商户回调分别成功；已有有效 current profile 时空保存不新增 profile，完整旧凭据缺少 profile 时允许建立必要基线快照；关闭渠道后旧付款仍按原尝试核验，新 attempt 被拒绝。
- Sub2API 停用阻止后续远程调用；在途结果可正常留证收口；已有连接不可换 URL；历史交付及退款规则不变。
- 参数保存后新请求/worker 读到新值；失败事务不污染缓存；站点 HTML/Markdown 保持一致；专属 SEO、历史账单到期及分佣快照保持原值。
- 测试按钮单次发送、限流、权限及 simulated/uncertain 行为有定向覆盖，不自动重试不明结果。

应用修改完成后运行完整 Pest、PHPStan 和 Pint；使用项目 PHP `/www/server/php/84/bin/php`，完整 Pest 配置足够内存。通过后按小提交自动提交，必要运行变更沿用现有备份与验证授权。本提案预期不需要迁移；若实现中发现必须改 schema/契约，应先说明具体差异。

本清单最初为源码复核提案。批准后的实际改动、当轮门禁和运行验收单独记入实施记录，不用旧成绩代替新功能验收。
