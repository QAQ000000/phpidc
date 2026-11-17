# CloudCMS

Laravel 13 云产品销售 CMS。当前代码版本见 [VERSION](VERSION)，候选能力、验证范围和未完成项见 [版本说明](docs/release-0.1.0-rc.1.md)。

- [安装、部署、升级与恢复](docs/deployment.md)
- [工程章程](CLAUDE.md) · [业务不变量](docs/domain-invariants.md) · [Agent 工作规则](docs/agent-guardrails.md)
- [运营扩展](docs/operations-expansion.md) · [管理后台](docs/admin-operations.md) · [经典 ZJMF](docs/classic-zjmf-integration.md)

生产环境要求 PHP 8.3+、MySQL 8.0+、Redis、HTTPS。请先阅读部署手册；测试和验收脚本只允许在隔离环境运行。不要用手写安装锁或重建 APP_KEY 绕过首次安装。
