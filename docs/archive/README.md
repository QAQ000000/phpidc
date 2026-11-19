# 历史实施与验收

本目录保留已完成阶段的原实施与验收事实。文中的 Gate 数量、迁移数、进程、未启用能力和测试限制均对应原检查点；当前状态见 [发布状态](../release-status.md)，日常查阅见 [文档入口](../README.md)。归档不改变已批准的业务规则，也不授予删除账本或审计的权限。

| 阶段 | 实施记录 | 数据库与预览验收 |
| --- | --- | --- |
| W1：身份邮件与钱包容量 | [实施记录](system-architecture-batch1-2026-11-18.md) | [隔离升级](system-architecture-batch1-upgrade-2026-11-18.md)、[原预览升级](system-architecture-preview-deployment-2026-11-18.md) |
| W2：钱包结清与一次性交付归档 | [实施记录](system-architecture-batch2-2026-11-18.md) | [原预览升级](system-architecture-preview-w2-deployment-2026-11-18.md) |
| W3：原收款、旧资源与映射恢复 | [实施记录](system-architecture-batch3-2026-11-18.md) | [原预览升级](system-architecture-preview-w3-deployment-2026-11-19.md) |
| B1：配置、验密、脱敏与重复请求清理 | [实施与验收](architecture-first-batch-2026-11-19.md) | 同左；该批无 schema 变更 |
| B2-01：业务事实与审计读取分离 | [实施与升级](architecture-second-batch-2026-11-19.md) | 同左；该批预览为 23 条迁移 |

各记录的原路径仍提供兼容入口及原章节锚点。活跃的章程、不变量、协议、设计决定、模块手册、当前发布状态和部署手册继续位于 `docs/` 原位置。
