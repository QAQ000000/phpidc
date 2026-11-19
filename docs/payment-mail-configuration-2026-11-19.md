# 当前预览支付与邮件配置记录

日期：2026-11-19（Asia/Shanghai），基线 `21dd301`。用户暂不使用生产域名，并提供易支付及企业邮箱配置；本轮完成原预览配置与无业务副作用的连接验证。

## 配置与验证

- 通过原 `ConfigureEpayAction` 保存网关配置和历史商户快照，原 `ConfigureIdentityAction` 保存 SMTP 配置。易支付密钥、SMTP 用户名/密码加密存储；审计不含凭据。配置值和备份保留在 Web root 外私有目录，不写入源码、测试、`.env` 或 Git。
- SMTP 使用 465 implicit TLS。Python 与应用实际 Symfony SMTP 传输均完成证书/主机名校验及认证，SMTP AUTH 返回 235；只连接/认证/退出，未提交邮件。
- 本机易支付是真实收款应用，标准 POST `mapi.php` 的签名、`code=1` 与付款链接结构和当前驱动兼容；没有可用的标准 `/api.php?act=query` 只读鉴权入口。仅以 READ ONLY 事务查询指定 PID，将用户给定密钥与原密钥比较，输出匹配布尔，不读取其他商户、不创建订单或更改选码状态。
- 网关 PHP 8.0/www 进程实际读取当前预览 `/up` 返回 200，支持同机 loopback 回调；暂不需要生产域名。实际发起测试订单时应使用当前可达预览地址，不能让网关回调历史 `.invalid` 地址。GET/POST 通知均由原 Laravel 路由接受，本轮没有发送模拟成功回调或变更账单。

## 发件名称补齐

原邮件驱动未读取显示名称。新增可选 `mail.from_name` 配置，将名称传入 `Message::from(address, name)`；未配置时保持原行为。名称/正文保留 UTF-8，名称拒绝 CR/LF。465 已由 Laravel `MailManager` 自动选用 `smtps`，没有重复新增 SSL 字段或替换邮件驱动。

新增五项用例，验证 UTF-8 名称/正文、465 SSL、587 兼容、旧默认名称和非法名称零设置/审计写入；与既有邮件故障回归合计 **14 passed /193 assertions**。Specs 和旧测试未改，无 schema 变更。

## 备份与原预览

操作前核对原 PID、PHP 8.4、cwd、运行环境中的 SQLite 路径与独立 storage，用 SQLite backup API 建立一致快照并保存原安装锁/运行环境。备份、私密输入和连接结果位于 `/root/.local/state/laravel13idc/payment-mail-config-20261119-ci7w9pl6/`，目录0700、文件0600，不进入仓库。

配置后除明确写入的 settings/audit 外，**55 张表逐表哈希保持**，包括原用户、金额、账单、任务及 outbox；`jobs=1` 未消费，迁移仍24条。SQLite 完整性与外键检查通过，安装封印保持，`/up`、`/login`、`/admin/login` 均200。未启动原业务 worker/scheduler。

连接认证不代表邮件已送达或支付已收款。真实支付、上游实购、外部邮件投递和外部监控仍需其对应业务验收；本轮没有指定实购动作/金额或收件人发送指令，因此未执行这些动作。

IMPLEMENTED: 原预览易支付/SMTP配置、原凭据加密及商户匹配/SSL认证验证、可选UTF-8发件名称。
NOT DONE: 实际支付订单/收款回调、供应商扣款、外发邮件送达及生产服务启用。
DECISIONS: 复用原配置Action、历史商户快照与Laravel的465隐式TLS；商户仅只读比较，不用有副作用的付款接口验密。
CONCERNS: SMTP认证与商户参数匹配分别只证明连接凭据可用，不能替代收款或送达证明。
GATES: 完整 pest 1806 passed /14934 assertions（113.85秒，PHP CLI 512M）| phpstan 0 errors | pint clean；三改动PHP lint通过，379本地链接无断链，真实SMTP认证/商户只读匹配及原预览核对PASS。
SPEC CASES: 0（未改动）。
