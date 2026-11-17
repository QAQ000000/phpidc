# 商城、CMS 与账户流程：C15 裁决与实施记录

日期：2026-11-17。状态：**用户已明确批准 C15，已按草案顺序实施。接口/DTO 已定向调整，未新增表字段或修改业务不变量；CMS 与账户本地实现、隔离验收及待办见末节。**

后续交付见 `identity-provider-integration.md`：用户已批准 idcsmartali 轮询差异，已补齐个人实名发起/查询、idcsmart 国内短信适配与 Telegram legacy Widget/显式通知订阅。真实供应商暂不可用，用户要求跳过，默认保持关闭；下文“无适配器/未实现”属于本轮之前的交付记录。

需求：商品展示、新闻/帮助、草稿/预约发布、SEO、Markdown、站点地图，以及找回密码、邮箱验证、第三方登录、实名认证/手机号绑定。

## 已核实的现状

基线：Pest 244 passed / 1929 assertions，PHPStan 0 errors，Pint clean；Specs 剩余 0。保留工作区既有库存、Sub2API、经典 ZJMF 扩展改动，不改用户提供的 `docs/zjmf-上游API对接总册.md`。

| 范围 | 当前事实 | 本轮补齐方向 |
|---|---|---|
| 公开商城 | 商品分类、商品详情、隐藏过滤、购买配置入口已存在 | 后台维护展示内容，保持商品/分类可见性与购买入口一致 |
| 新闻/帮助 | 分类、文章、草稿、定时可见性已存在；内容维护目前是 CLI | Filament 编辑、草稿/预约/发布状态展示、权限与操作者审计 |
| SEO/Markdown/站点地图 | canonical、Open Graph、文章 SEO、清洗输出、`.md`/Accept 协商、sitemap、llms 已存在 | 管理界面接入现有字段，验证预约时间前后各公开出口的一致性 |
| 账户 | 仅邮箱密码注册、登录、退出 | 找回密码、验证邮箱、账户安全页、第三方绑定/登录、手机验证 |
| 邮件 | `LogMailDriver` 支持 SettingService 中的 SMTP 配置 | 使用同一运行时邮件通道发送账户邮件；未配置时明确不可用 |
| 短信 | `LogSmsDriver` 只记录模板名并返回 true，不会投递验证码 | 先核实生产短信适配协议；不能把 log 的成功当成手机号所有权证明 |
| 实名 | 表、模型和接口存在，无驱动与客户流程 | 先处理 C15，再核实真实供应商协议；不得伪造认证成功 |

公开发布继续使用 `CmsArticle::scopePublished()` 的 `is_published && published_at <= now()` 与分类可见性条件，无需为了预约发布新增调度器或状态列。商品表没有独立 SEO 列，沿用已有名称/描述生成元信息；本方案不申请新增字段。

## 停止依据与冲突证据

`docs/agent-guardrails.md` §1.3：

> The migration, the enums, the DTOs and the contracts are the agreed interface. Changing them requires a human decision.

§4 同时要求：需要未核实响应结构的上游调用时停止，明确举例 legacy KYC callback。

现有三处不能同时遵守：

1. `CLAUDE.md` §2/F10 要求 Driver 返回 DTO、不得修改模型，所有模型修改归 Action。
2. `docs/external-drivers-spec.md` §3 却要求 Driver 的回调处理器是 `kyc_verifications` 和 `users.kyc_verified_at` 的唯一写入者。
3. `KycDriverInterface::handleCallback(Request): KycVerification` 返回数据库模型；`queryStatus(KycVerification): string` 又不能表达经过协议核验的外部记录标识与脱敏摘要。

`docs/external-drivers-spec.md` §3 的 Verification Note 还要求先核实经典 ZJMF 的实名端点和回调签名。只读参考 `/www/wwwroot/zjmf-manger-decoded/public/plugins/certification/` 后，确认其插件接口为 personal/company/getStatus，idcsmartali 逻辑调用供应商 certapi 的 initialize/certify/query 链，使用 api/key 请求头，不能据此推定本项目所需的 ZJMF API key 认证和签名回调格式。该历史实现还使用明文 HTTP 传送身份信息，不能直接照搬为生产传输。未向这些外部服务发送请求。

## C15：纯实名驱动结果与 Action 单一写入者

已批准并应用以下**定向**契约变更，不修改数据库、不修改业务不变量、不更改原规格：

```php
// 新增 readonly DTO，字段表达已核实的协议结果，不表达本地已写库。
KycStatusResult(
    string $externalId,
    string $status,        // 仅 pending / verified / failed
    array $maskedSummary, // 仅允许脱敏姓名、证件末四位等已批准字段
)

KycDriverInterface::initiate(User $user, array $payload): KycInitiateResult; // 不变
KycDriverInterface::queryStatus(KycVerification $verification): KycStatusResult;
KycDriverInterface::handleCallback(Request $request): KycStatusResult;
```

定向修订 `external-drivers-spec.md` §3 的写入归属为：

- Driver 负责认证请求、验证回调签名及解析结果，返回 DTO，不写模型。
- 发起 Action 先锁用户、记录本地 pending 意图后提交，再在事务外请求供应商；结果由 Action 复核当前申请后持久化。请求结果未知时保留待核对状态，不盲目重复创建收费认证。
- 应用状态的 Action 先锁用户，再锁匹配的认证记录，同事务更新认证状态与 `users.kyc_verified_at`。查询和回调均通过该入口；浏览器返回页不构成通过依据。
- 同一用户/驱动仅一个在途申请，由用户行锁保护；回调须唯一匹配驱动与 external_id，歧义拒绝。旧申请、重复回调和乱序状态不能覆盖新申请结果；已终态的同次申请不接受相反终态，除非核实协议后另行定义撤销语义。
- 当前有效申请确认 verified 时置认证时间，确认 failed 时清除标志；历史记录保留。已有 verified 的用户不静默重复发起认证。
- 明文姓名/证件号仅在必要的请求内存中传递，不写数据库、日志、审计、会话、缓存或异常回显；保存前使用固定允许字段生成脱敏摘要。

批准 C15 **不等于** 核实供应商协议。尚未核实的端点、认证、回调签名、重放/关联机制、状态含义与错误格式不得猜测；若供应商实际只支持查询，应先报告与回调契约的差异，而不是实现一个永远调用不到的假回调。

## 不需新增表字段的实施选择

以下为推荐实现方向，不构成新的冻结接口修改：

- **CMS 管理**：复用现有权限表，独立 cms.view/cms.edit/cms.publish 权限；库存/上游角色不自动获得。创建和编辑使用 Action、填写原因、记录真实操作者；按记录 ID 更新，避免把改 slug 误实现成新增文章。无发布权限的编辑不能通过通用保存入口改变发布状态/时间或改写已公开正文。Markdown/HTML 预览使用同一清洗渲染器；预览不进入 sitemap/llms。
- **密码找回**：复用 `password_reset_tokens` 和 Laravel broker 的哈希、过期与节流机制；存在/不存在邮箱返回一致提示。重置成功更换 remember_token；处理旧会话失效，令牌一次性消费；通过 SettingService 邮件适配器发送，不静默回退成已投递。
- **邮箱验证**：限时签名链接绑定当前登录用户及当前邮箱；拒绝过期、换邮箱后的旧链接与跨用户验证。只有验证成功的 Action 可设置标志和已验证 email 通知绑定。本轮不自动改变既有商品的购买门槛。
- **第三方登录**：复用 Socialite 与 `user_oauth_accounts`；只接受显式启用并配置完整的提供商。OAuth state/回调绑定会话及登录或绑定目的。已绑定 subject 登录其原账户；不凭相同邮箱自动合并。未绑定 subject 先完成本地账户注册/验证，或登录已有账户后明确绑定；不生成虚假邮箱，不把供应商昵称当身份依据，不保存无用途的 access/refresh token。Telegram/微信等协议须按实际提供商核实，不共用一个猜测的 OAuth 回调处理器。
- **手机号绑定**：复用 `users.phone` 唯一约束与 `user_notification_bindings(channel=sms, verified_at)`，不添加 phone_verified_at。验证码绑定用户、号码、用途与有效期，限制尝试和发送；验证码核验通过后在 Action 事务内更新号码与验证绑定，替换号码时撤销旧绑定。当前 require_phone 只检查 phone 非空；历史号码不能自动标记已验证，是否升级购买门槛需单独确认影响。
- **配置/未启用状态**：凭据通过 SettingService 读取并加密保存；页面明确展示未配置。日志短信不用于真实绑定，未验证的实名驱动保持禁用。测试替身只能用于隔离测试，不能作为生产认证后门。

## 验收与交付边界

裁决后先完成不依赖实名协议的 CMS 后台、密码/邮箱与账户页面，按核实程度接入第三方与短信适配；实名请求/查询/回调在协议确认后实现。缺少真实服务配置时可完成隔离协议测试与页面验收，不能报告真实投递、第三方授权或实名联调已通过。

测试覆盖权限隔离、预约边界与隐藏内容泄露、HTML 清洗、密码令牌过期/重复消费、邮箱链接错人/过期、第三方绑定冲突与 state、验证码重放/过期/错用途、实名旧申请与重复回调。原测试不改，Specs 数量维持 0；收尾跑完整 Pest、PHPStan、Pint，浏览器使用隔离数据库和测试替身。

本草案不授权向真实用户发送邮件/短信、创建收费认证，或使用此前 Sub2API 密钥处理身份功能。实际投递与实名联调需选定服务及专用测试配置。

## 本轮交付与配置

`/admin/content` 已提供商品名称/描述/上下架、新闻/帮助栏目、文章 Markdown/HTML、SEO、草稿/发布/撤回/预约和内部预览。按记录 ID 修改 slug，新建入口不能覆盖已有同 slug 文章；栏目和任何公开/预约文章的修改需要发布权限，审计记录真实 actor。公开路由、sitemap、llms 沿用同一可见性条件，不新增发布调度器。商品 SEO 继续使用已有名称/描述，没有专属 SEO 列。

可信服务器授权命令：`php artisan cms:role USER_ID cms-viewer|cms-editor|cms-publisher --reason="..."`，附加 `--revoke` 撤销角色；不向 Web 暴露提权接口。

`/forgot-password`、`/reset-password/{token}` 使用 broker 哈希令牌、默认 60 分钟有效期和每用户 60 秒节流。用户行锁串行创建/消费令牌，重置更换 remember_token 并清理数据库会话；web AuthenticateSession 校验登录时的密码摘要，兼容文件/Redis 会话失效。邮件在事务外发送，链接来源固定为可信 app.url。找回密码对未知邮箱、正常发送、节流和投递失败使用相同公开提示；失败显式记录 `identity.password_reset_delivery_failed` 审计，不改变密码或声明验证成功。已登录邮箱验证的投递失败则明确报错并记录失败，SMTP 异常正文与令牌不进审计。

`/console/account` 提供邮箱验证状态、发送签名链接、确认邮箱及通知绑定，以及第三方绑定/解绑。GitHub、Google、QQ、微信开放平台采用已安装的 Socialite Provider 与会话 state；本地授权目的限时 10 分钟。绑定须验证本地邮箱和当前密码；不凭供应商邮箱自动合并、不生成虚假邮箱用户、不存 access/refresh token。未绑定者先注册/登录并验证邮箱，再显式绑定。QQ/微信使用应用范围 openid，更换 Client ID 不承诺迁移旧绑定。

手机流程已实现用户/号码/密码版本/时效绑定、发送节流、5 次错误限制、一次性消费、唯一号码及验证通知绑定事务。默认 LogSmsDriver 无法启用绑定，即使 binding_enabled=true 也不能绕过。**尚无真实短信适配器，生产发送/验证保持不可用；成功/失败路径以隔离短信替身验证。** 既有非空 phone 的购买门槛未变，历史号码不自动标记已验证。

实名已落实 C15 DTO/接口和 `ApplyKycStatusAction`：用户先锁、唯一关联、最新申请隔离、重复/矛盾终态处理、脱敏白名单，以及认证记录/用户标志原子写入。**生产实名发起、供应商驱动、查询与回调路由尚未实现**，页面明确未启用且不收身份证号；后续只接受经过协议验证的驱动结果。Telegram Widget 不同于通用 OAuth2，其签名/时效协议待单独核实，目前不启用。

配置使用网站运行时对应的 PHP 二进制，文件放在 web 根目录以外且仅运维可读：

```sh
php artisan identity:configure /private/path/identity.json --reason="配置账户邮件与登录"
```

JSON 示例，全部凭据均为占位值：

```json
{
  "mail.transport": "smtp",
  "mail.host": "smtp.example.test",
  "mail.port": 587,
  "mail.username": "accounts@example.test",
  "mail.password": "REPLACE_WITH_PRIVATE_SMTP_PASSWORD",
  "mail.from_address": "accounts@example.test",
  "oauth.github_enabled": true,
  "oauth.github_client_id": "REPLACE_WITH_CLIENT_ID",
  "oauth.github_client_secret": "REPLACE_WITH_PRIVATE_CLIENT_SECRET"
}
```

其他平台将 github 替换为 google/qq/wechat；回调固定为 app.url 加 `/auth/{provider}/callback`。配置 Action 拒绝未列出的键，密码、SMTP 用户名和 client_secret 加密入库，审计只记键名。账户邮件须配置 smtp、host 和合法 from_address 才显示可发送。配置变更后重启常驻 worker，不提交凭据文件。

## C15 初次交付验证结果与限制

以下 282 用例为 C15 初次交付记录；后续客户注册/登录、本人订单和服务门户的当前结果见 `docs/customer-account.md`（305 passed / 2378 assertions）。

- 新增 CmsAdministrationTest / CustomerIdentityTest 合计 38 个测试实例；完整 Gate：Pest 282 passed / 2200 assertions，PHPStan 0 errors，Pint clean。原测试和 Specs 未改，剩余规格 0。
- `python3 scripts/verify-cms-identity.py` 使用临时 SQLite、独立 APP_KEY/storage/public、文件会话和回环 SMTP，真实 HTTP 验证 CSRF 拒绝、CMS 草稿/发布/导出/RBAC、邮件投递与签名确认、密码重置及旧文件会话失效；`--preview` 可保留临时站点供浏览器检查。无业务数据库迁移或向真实用户投递。
- Socialite 测试使用实际 Provider 的 state、token 和 subject 解析，仅替换 Guzzle 传输；这不代表真实平台应用审核、域名配置或账号授权已通过。
- Playwright 已通过真实登录进入 CMS，填写并保存预约文章；确认其公开 URL 返回 404、sitemap 不收录。后台 Livewire/Alpine 正常加载；CMS 与账户安全页在 390px 宽度下文档宽度均为 390px，无横向溢出。隔离 public 缺少 favicon 导致一条 404，未出现应用脚本错误。
- 待办：真实 SMTP/四个平台账号联调、真实短信适配器、Telegram Widget、实名发起及认证查询/回调。多进程 MySQL 并发、真实 Redis 会话尚未验证；手机号发送/验证需要共享缓存的原子锁，不能使用进程独立 array cache。
