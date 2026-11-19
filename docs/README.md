# 文档入口

当前实现、验收和部署状态以 [发布状态](release-status.md) 为准。设计获批、代码通过测试、预览已迁移和生产启用分别记录；历史报告中的数量、进程和“未完成”描述只代表原检查点。

## 按任务查阅

| 当前任务 | 入口 |
| --- | --- |
| 判断已实现能力、当前预览和剩余事项 | [发布状态](release-status.md) |
| 安装、升级、换钥、备份与恢复 | [部署手册](deployment.md) |
| 修改代码或核对业务契约 | [工程章程](../CLAUDE.md)、[业务不变量](domain-invariants.md)、[Agent 工作规则](agent-guardrails.md) |
| 接入支付、通知、身份或资源驱动 | [经典 ZJMF 协议](zjmf-api-spec.md)、[其他驱动协议](external-drivers-spec.md) |
| 运行维护任务、核对队列与告警 | [维护与巡检](maintenance-and-operations-2026-11-18.md) |
| 使用后台、权限和财务操作 | [管理后台](admin-operations.md)、[运营扩展](operations-expansion.md) |
| 查阅客户账户、登录与身份接入 | [客户账户](customer-account.md)、[身份接入](identity-provider-integration.md) |
| 商品、库存与卡密销售 | [库存管理](inventory-admin.md)、[本地卡密销售](local-card-sales.md)、[Sub2API](sub2api-remote.md) |
| 经典上游、机器与商品配置 | [经典 ZJMF 集成](classic-zjmf-integration.md)、[机器操作](classic-zjmf-machine-controls.md)、[老版源码考证总册](zjmf-上游API对接总册.md) |
| 前台内容、栏目与历史地址 | [CMS 与商城](cms-storefront.md) |
| 核对当前架构清理结果 | [第五批隔离运行验收与待办](architecture-fifth-batch-2026-11-19.md) |
| 追溯已完成阶段的实施和验收 | [历史记录索引](archive/README.md) |

代码变更前的必读范围和优先级仍遵循工程章程与 Agent 工作规则；本页只负责导航，不替代规范或更改业务约定。

## 现行决定与方案

这些文件仍承载已批准的业务范围、历史兼容或实施边界，保留在原路径。具体实现和当前部署先查发布状态，再按问题定位原决定。

- 基础契约：[C01–C09 澄清](contract-clarifications.md)、[后端修复决定](backend-remediation-decisions.md)、[复审 A/B/C 决定](backend-review-fix-decisions.md)。
- 模块扩展：[经典上游决定](classic-zjmf-expansion-decisions.md)、[运营扩展决定](operations-expansion-decisions.md)、[CMS/身份决定](cms-identity-decisions.md)、[退款决定](refund-decisions.md)。
- D01–D05：[能力决定](remaining-capabilities-decisions-2026-11-18.md)，以及 [钱包充值](wallet-topup-proposal-2026-11-18.md)、[匿名化与应收](account-anonymization-receivables-proposal-2026-11-18.md)、[外部佣金提现](external-affiliate-payout-proposal-2026-11-18.md)、[通知 outbox](notification-outbox-proposal-2026-11-18.md)。
- 全项目修复：[N01–N06 / W0–W5 方案](system-architecture-remediation-plan-2026-11-18.md)。
- 后续架构清理：[B2-01 方案](architecture-second-batch-plan-2026-11-19.md)、[B3-01～03 方案](architecture-third-batch-plan-2026-11-19.md)、[B4 流程与文档收敛方案](architecture-fourth-batch-plan-2026-11-19.md)、[B5 运行验收方案](architecture-fifth-batch-plan-2026-11-19.md)。

## 历史记录的使用

[archive/](archive/README.md) 收录已完成的 W1–W3、B1–B2 实施/验收记录，保留原文事实与边界。原路径提供兼容入口并保留原章节锚点；不删除历史依据，也不把历史检查点当作当前运行状态。当前发布、运维、协议、业务不变量及仍适用的决定不移入归档。
