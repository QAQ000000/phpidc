# 实名、短信与 Telegram 接入记录

日期：2026-11-17。基线 Pest 305 passed / 2378 assertions，PHPStan 0 errors，Pint clean。保留所有既有未提交改动，不修改原测试、冻结接口、DTO、业务不变量或数据库结构。用户已批准实名轮询模式；随后明确要求跳过暂不可用的真实供应商，保持短信/实名关闭。

## 源码与已确认差异

只读参考用户指定的 `/www/wwwroot/zjmf-manger-decoded-副本/public/plugins/`：

- `sms/idcsmart/IdcsmartPlugin.php::sendCnSms/APIHttpRequestCURL`：POST `smsapi.php?action=send`，`api`/`key` 请求头，form-urlencoded 的 `to`、签名加替换后的 `content`、`template_id`、嵌套 `vars`；仅业务 status=200 表示平台受理。模板正文使用 `@var(name)`，签名规整为 `【签名】`。供应商受理不等同手机已收到，更不等同号码已验证。
- `certification/idcsmartali/logic/Idcsmartali.php`：POST `certapi.php?action=initialize`，字段 outer_order_no/biz_code/cert_type/cert_name/cert_no/return_url，status=200 与 certify_id；`certify` 传 certify_id 取 url；`query` 传 certify_id，status=200 在旧插件映射为通过。非200含义不够明确，不能照搬为认证失败。
- 实名只支持主动查询及浏览器回跳，未提供服务端签名回调。用户已明确批准只做轮询、非200保留待核对，沿用 C15 契约，不开放虚构的回调路由。
- 两套插件源码使用 `http://api1.idcsmart.com/`。本轮未传凭据的 HTTPS 连接测试均在 TLS 握手失败；不把明文 HTTP 或关闭证书验证作为替代。真实启用仍需供应商可用的 HTTPS 端点及账户配置。

## 实施边界

短信适配器默认关闭，仅通过 SettingService 读取全局凭据。模板 ID、审核正文及签名由运维配置，未知模板拒绝发送，不把模板键当正文。复用既有验证码事务、缓存锁、时效与一次性消费，真实供应商适配和日志通道明确分开。

Telegram 采用官方 legacy Widget 的独立签名流程，不套 OAuth2 access token，不调用已安装 Provider 的时效判断。已确认新官方登录首页改为 OIDC，需要额外 Client ID/Secret；本次给出的 bot token 只能用于 Bot API 与 legacy Widget，不能冒充 OIDC 配置。Widget 需要 BotFather 配置站点域名；登录使用限时站内意图、签名校验与单次 proof，绑定须验证本地邮箱和当前密码，不自动合并。通知订阅由用户明确开启，解绑同时撤销该 Telegram 的通知绑定。

凭据不得进入仓库、审计、异常或测试 fixture。本轮 Telegram 私密配置保存在 web 根目录以外的权限0600文件，应用尚未安装时不绕过 SettingService 的 pre-install 旁路、不写业务数据库。

官方协议参考：

- https://core.telegram.org/widgets/login-legacy
- https://core.telegram.org/bots/telegram-login
- https://core.telegram.org/bots/api#getme

本轮不向真实号码发送验证码、不创建收费实名申请、不向 Telegram 用户或群发送测试消息。真实账号、域名和投递均需专用配置才能验收。

## 个人实名流程

先锁用户并保存 pending 申请及脱敏摘要，然后在事务外请求 initialize；其返回外部 ID 必须先由 Action 落库，客户随后通过显式 POST 请求 certify 获取认证链接，再显式 POST query 查询。只有已认证的 query 业务 status=200 通过；其他业务状态保持 pending，HTTP/TLS/结构错误返回脱敏提示，不使用供应商原文。浏览器 return_url 仅提示客户查询。

仅支持插件已明确给出的个人身份证类型 IDENTITY_CARD；不在首轮实现企业认证或其他证件。发起须本地已验证邮箱、当前密码和明确同意。姓名/证件仅在请求内存中使用；数据库仅保存掩码姓名和证件末四位。初始化不明或进程中断留下 external_id=null 的 pending 记录，禁止自动再次初始化；需运维与供应商核对，不能靠超时删除意图。认证 URL 不持久化，页面无第三方二维码生成请求。

新配置均为 system_settings 的新增键，不改 schema/冻结接口。运行时使用 SettingService，测试仅临时数据库。用户提供的 SMS/KYC api/key 已分别保存在站点外0600配置文件，未读取旧项目数据库或复用旧项目运行时凭据。

## 配置与启用条件

私密文件在 `/root/.config/laravel13idc/`（目录0700、文件0600）：`telegram.private.json`、`idcsmart-sms.private.json`、`idcsmartali-kyc.private.json`。没有提交这些文件或将凭据复制到仓库；Telegram 与 SMS/KYC 开关均为 false。当前 workspace 没有 `storage/installed.lock`，没有绕过安装流程向业务库配置凭据。

后续由运维安装站点并取得供应商可用 HTTPS 地址、审核模板和场景码后，补全私密文件，再使用对应运行时 PHP 执行已有命令：

```sh
php artisan identity:configure /private/path/completed-identity.json --reason="配置已核实的身份服务"
```

支持分次配置，凭据加密写入 `system_settings`，审计仅记录键名。示例均为占位值，地址不代表已可用的供应商：

```json
{
  "sms.driver": "idcsmart",
  "sms.idcsmart_endpoint": "https://verified-supplier.example.test/smsapi.php",
  "sms.idcsmart_api": "REPLACE_WITH_PRIVATE_APPLICATION_ID",
  "sms.idcsmart_key": "REPLACE_WITH_PRIVATE_APPLICATION_KEY",
  "sms.idcsmart_sign": "已审核签名",
  "sms.binding_enabled": false,
  "sms.binding_template": "binding",
  "sms.idcsmart_templates": {
    "binding": {"id": "REPLACE_WITH_APPROVED_TEMPLATE_ID", "content": "验证码@var(code)，@var(minutes)分钟内有效"}
  },
  "kyc.driver": "idcsmartali",
  "kyc.enabled": false,
  "kyc.idcsmartali_endpoint": "https://verified-supplier.example.test/certapi.php",
  "kyc.idcsmartali_api": "REPLACE_WITH_PRIVATE_APPLICATION_ID",
  "kyc.idcsmartali_key": "REPLACE_WITH_PRIVATE_APPLICATION_KEY",
  "kyc.idcsmartali_biz_code": "FACE",
  "telegram.bot_username": "sakuraidccc_bot",
  "telegram.bot_token": "REPLACE_WITH_PRIVATE_BOT_TOKEN",
  "oauth.telegram_enabled": false
}
```

短信只接入参考源码的国内路径，账户输入 `+86`，传供应商时去掉 `+86`。国际号码不调用这个接口。正文/签名必须与审核模板一致，验证码有效期为10分钟，不能照搬旧插件5分钟正文。未知模板、未提供的变量、平台拒绝、重定向、非JSON或连接错误均不产生可用验证码；不自动重发不确定的收费短信。两套驱动均支持参考源码明确处理过的 UTF-8 BOM；短信受理和实名查询仅将业务状态200视为成功。

Telegram 已通过真实只读 `getMe` 核实 bot id/用户名；没有调用 sendMessage、setWebhook 或更改机器人配置。启用 legacy Widget 前，仍需在 BotFather `/setdomain` 绑定实际站点域名，使用正确的 APP_URL；真实账号授权和消息送达尚未联调。本地防重放需跨进程共享的持久缓存，不能使用 array cache；Telegram 通知须客户主动订阅并允许机器人消息，解绑自动撤销订阅。

## 验收与剩余限制

- 最终 Gate：**Pest 351 passed / 2806 assertions，PHPStan 0 errors，Pint clean**。本轮新增46个测试实例（短信/Telegram 24个、实名22个），Specs 剩余0。
- 新增 `IdentityProviderIntegrationTest`、`IdcsmartKycTest` 覆盖短信 form/凭据/模板、失败不留可用验证码、原始值 Telegram 签名、时效与重放、绑定冲突、密码改变、显式订阅与解绑撤销，以及实名先记录意图、外部编号先持久化、查询唯一置认证、未知结果不再初始化、隐私、越权与驱动纯度。
- `python3 scripts/verify-cms-identity.py --providers` 使用临时 SQLite、独立 storage/public/APP_KEY、回环 SMTP 和本地 HTTPS 服务。临时 CA 仅供该 PHP 进程信任，保留证书/主机名校验；真实 HTTP 验证 CSRF、短信 OTP、Telegram 签名与订阅、实名 initialize/certify/query（先pending再verified）、浏览器回跳无效及数据库无明文姓名/证件。没有生产测试后门。
- HTTPS 替身最初未声明 Content-Length，cURL 将连接结束判为传输失败。修正替身响应长度后全链路通过，未放宽适配器的错误处理；验收按独立流程清理隔离缓存以避开既有共享节流桶，未修改生产节流策略。
- Playwright 已检查账户页已验证/未验证状态、手机号掩码与实名表单。390px 文档宽度为390px；输入错误密码提交实名被拒绝，姓名/证件/密码输入值均为空，不回填敏感字段。没有用浏览器操作真实平台账号。
- 真实服务按用户要求跳过：SMS/KYC HTTPS 握手失败，api/key 尚未做供应商鉴权；短信签名/审核模板、实名已开通场景仍待补充。只有参考源码和隔离协议测试，不代表真实供应商成功样本、收费规则、状态语义或投递已验证。
- 实名只做个人大陆身份证；企业、其他证件、签名回调、自动查询任务与管理员恢复工具不在本次实现内。`external_id=null` 的不确定 pending 申请保留给人工核对；已有外部编号可以继续获取链接/查询，不自动重新初始化。未新增客户钱包扣款、实名收费账单或退款；供应商可能收费，真实收费须按后续业务决策验收。
- MySQL 多进程竞争、Redis 缓存/会话、真实 SMTP/其他 OAuth 平台不以此临时环境验收代替。原测试与 Specs 未修改；未运行业务数据库迁移、未提交 Git。
