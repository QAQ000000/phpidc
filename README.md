# CloudCMS

Laravel 13 云产品销售 CMS。当前代码版本见 [VERSION](VERSION)，当前能力、隔离验收、预览部署和生产阻断统一见 [发布状态](docs/release-status.md)；[rc.1 说明](docs/release-0.1.0-rc.1.md)保留历史记录。

- [安装、部署、升级与恢复](docs/deployment.md)
- [工程章程](CLAUDE.md) · [业务不变量](docs/domain-invariants.md) · [Agent 工作规则](docs/agent-guardrails.md)
- [运营扩展](docs/operations-expansion.md) · [管理后台](docs/admin-operations.md) · [经典 ZJMF](docs/classic-zjmf-integration.md)

生产环境要求 PHP 8.3+、MySQL 8.0+、Redis、HTTPS。请先阅读部署手册；测试和验收脚本只允许在隔离环境运行。不要用手写安装锁或重建 APP_KEY 绕过首次安装。

钱包充值、注销匿名化、人工外付、应收清偿和通知事务 outbox 已有实现。它们的代码完成、隔离验证与实际运营启用是不同状态；当前预览没有启动业务 worker、scheduler 或真实告警，生产启用仍须完成部署与值班接收者验收。
