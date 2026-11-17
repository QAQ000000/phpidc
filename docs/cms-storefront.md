# CMS 与公开商城交付契约

2026-11-17 补充：已增加 `/admin/content` 管理、CMS 独立角色、按 ID 编辑、操作者审计和预约/预览入口；账户流程、C15 裁决、配置及当前验收见 [cms-identity-decisions.md](cms-identity-decisions.md)。以下阶段性状态为历史记录。

本轮范围：Blade 服务端渲染商城、新闻/帮助 CMS、SEO/GEO 输出，以及 Sub2API 本地卡池销售。沿用现有表、枚举、DTO、契约、安装门禁与支付流程，不迁移剩余两个退款规格。管理入口沿用受信服务器命令，暂不宣称 Filament/RBAC 已交付。

## 页面与状态

| 路由 | 内容与约束 |
|---|---|
| `/` | 公开首页，展示商品与最新文章；空库显示明确空状态 |
| `/store`、`/store/{category}` | 可售驱动的可见商品；隐藏分类与隐藏商品不出现；分页 |
| `/store/{category}/{product}` | 分类归属必须匹配；本地价格与经典上游参考价区分；登录后通过既有 checkout 创建账单 |
| `/news`、`/help`、`/{type}/{category}` | 新闻/帮助分类、标题搜索和分页；类型来自分类 |
| `/{type}/{category}/{article}` | 分类、类型、文章归属均匹配；只展示已发布且 published_at 非空并不晚于当前时间的文章 |
| 商品/文章详情 `.md` 或 `Accept: text/markdown` | 同样的可见性约束，Markdown MIME、nosniff、Vary: Accept，canonical 指向 HTML URL |
| `/sitemap.xml`、`/llms.txt` | 仅包含公开商品、分类和已发布文章；不包含草稿、预约文章、隐藏内容、账户、密钥与订单 |

Markdown 使用已安装的 CommonMark（禁用原始 HTML 与危险链接），HTML 使用已安装的 HTMLPurifier；HTML 页面统一经过净化。HTML 格式文章的 Markdown 输出使用净化后的 HTML 块（Markdown 支持内嵌 HTML）。SEO 使用标题、摘要、canonical 与 Open Graph，站点名从 SettingService 获取。商品 description 作为纯文本展示，不把上游说明直接输出为 HTML。

页面桌面端使用卡片列表与双栏详情，窄屏切为单栏；表单有 label，状态不单靠颜色表达。匿名访客可阅读，购买须先登录。公开页不查询上游库存/余额、不输出凭据；本地库存仅给数量，库存不足与付款后耗尽仍遵循既有交付恢复规则。

## 内容管理

`cms:category` 按 type+slug 创建/更新分类；`cms:article` 按 category_id+slug 创建/更新文章，从本地文件读取正文，默认草稿，显式 `--publish` 发布，`--publish-at` 支持预约。省略 `--publish` 再次保存会撤回文章。公开 slugs 限小写字母、数字和单短横线，避免与 `.md` 后缀冲突。所有写入由 Action 在事务内完成，要求非空 reason 并写 system 审计；不提供公开写接口。

`products:describe` 修改商品名称/纯文本说明及显式可见性，不修改驱动、价格或实例绑定。`cards:create-product --driver=sub2api` 创建一次性 Sub2API 充值卡；`cards:import` 导入现成卡密，复用加密库存、唯一交付与 owner-only/no-store 页面。SUB2API 不绑定 upstream_api_id 即明确采用 local_pool；带上游账号且明确启用 verified 协议的 remote_api 后续已接入，见 [远程发码与真实兑换验收](sub2api-remote.md)。用户仍须自行去对应 Sub2API 服务兑换已交付卡密。

PVE 需要新增资源表；Sub2API 远程接口的后续协议证据与交付范围见 `sub2api-remote.md`。它们不是本轮的默认实现，不新增字段或猜测接口。

## 操作示例

命令需由受信服务器操作员执行，文章和商品说明文件使用 UTF-8；下列 ID 需替换为命令实际输出的 ID。API 密钥与待售卡密文件仍应位于 Web 根目录之外。

```bash
php artisan cms:category help guides '使用指南' --reason='创建帮助分类'
php artisan cms:category news updates '平台动态' --reason='创建新闻分类'

# 首次默认保存为草稿；相同分类与 slug 再保存会更新原文章。
php artisan cms:article 1 recharge '充值卡使用指南' /secure/guide.md --reason='初稿'
php artisan cms:article 1 recharge '充值卡使用指南' /secure/guide.md --publish --seo-description='购买、领取与兑换说明' --reason='审核发布'
# 时间会转换到应用时区 Asia/Shanghai；允许显式 ISO 时区。
php artisan cms:article 1 scheduled '预约文章' /secure/guide.md --publish --publish-at='2026-11-18T10:00:00+08:00' --reason='预约发布'
# 撤回：对原分类和 slug 再次保存，省略 --publish。
php artisan cms:article 1 recharge '充值卡使用指南' /secure/guide.md --reason='撤回修订'

php artisan cards:create-product 'Sub2API 充值卡' '10.00' --driver=sub2api --reason='新增产品'
php artisan cards:import 1 /secure/sub2api-cards.txt --reason='导入现成充值卡'
php artisan products:describe 1 /secure/product.txt --reason='补充商品说明'
php artisan products:describe 1 /secure/product.txt --visibility=hidden --reason='暂时下架'
```

`cms:category --hidden` 隐藏分类；省略时保存为可见。`products:describe` 省略 visibility 时保持原可见性。CMS 不允许更改既有分类类型；同 slug 可分别存在于 news/help，同文章 slug 可存在于不同分类。预约公开由查询时间决定，不需要额外定时任务。

`site.name` 从 system_settings 经 SettingService 读取，缺省使用应用名称。未新增运行时 `.env` 项或写入真实站点设置。

## 验收与边界

新增测试覆盖安装门禁、可见性与分类归属、草稿/预约时间与时区、分页/搜索、HTML/Markdown 净化、canonical/Vary、sitemap XML/llms 隐私，以及 Sub2API 纯钱包/组合付款、重复通知/执行、唯一加密交付及 owner-only/no-store。

```bash
./vendor/bin/pest
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
./vendor/bin/pest --group=spec
python3 scripts/verify-cms-sub2api-sale.py
```

基线为 115 passed / 759 assertions；收尾默认 Pest 为 134 passed / 1009 assertions，PHPStan 0 errors，Pint clean。剩余 Specs 仍为两个退款相关预期失败，文件及计数未改。未修改迁移、枚举、DTO、契约或业务不变量。

隔离脚本使用临时 SQLite/storage/APP_KEY、loopback 模拟易支付和独立 database queue worker，验证真实注册/session/CSRF、付款尝试先提交、签名回调重放、只交付一张加密卡、非本人 403、CMS HTML/Markdown、合法 XML sitemap、公开 llms 和草稿 404。未使用业务数据库、真实资金或真实 Sub2API 服务。`--preview` 可在验收后保留临时服务，按 Enter 会关闭脚本自己创建的进程并清除临时数据。

浏览器验收使用独立 Playwright/系统 Chrome 会话检查 1440px 桌面和 390px 窄屏，不使用用户现有浏览器登录状态。预览与截图仅属于临时验收数据，未部署到真实站点。

- CMS 与商品管理当前为受信 CLI，不包含 Filament 编辑器、后台 RBAC、上传、版本历史或公开写 API。
- Sub2API 只销售已导入的卡密；卡密是否有效、兑换后额度如何变化，需在对应服务端验证，本轮不宣称已联调。
- INV-I2/W2/W3/P2/C1–C3/L4/R3 沿用现有报价、资金事务、唯一流水、outbox 和卡密预留；新增产品驱动只读，不绕过 Action。隐藏商品分类同时阻止 checkout，钱包保持不变。
- 完整上游选项、续费/升级/退款、PVE、Sub2API remote_api 和下载中心不在本轮范围。未验证 MySQL 并发、真实支付或远程产品资源分配。
- sitemap/llms 当前针对本项目的单站规模，尚未实现海量 sitemap 分片；搜索使用数据库标题匹配，不声称已有独立全文搜索服务。


## 后续交付说明（2026-11-17）

上文验收范围是本地卡池阶段的历史记录。库存运营后台已实现，见 `inventory-admin.md`；退款闭环已实现，见 `refund-decisions.md`。Sub2API remote_api 已按固定面额发码模式接入，并对专用测试账号完成一次真实 1 单位兑换及重复兑换拒绝验收，见 `sub2api-remote.md`。这些结果不表示业务库已迁移、真实商品已上线或直接用户充值模式已实现。
