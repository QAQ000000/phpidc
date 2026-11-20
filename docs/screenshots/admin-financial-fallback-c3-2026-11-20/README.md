# C3 财务人工兜底截图与验收证据

全部截图来自隔离 SQLite 与模拟账户；无真实客户、真实资金或部署凭据。桌面视口 1440×1000，手机视口 390×844，全页截图高度随内容变化。

实际建单使用 `CreateComboInvoiceAction`：10.00 元新购单，钱包 3.00 元、网关 7.00 元。真实表单登记 7.00 元银行转账后，原钱包冻结消费一次，生成一份收款凭证、一份共享来源、一条付款流水、一条结算流水、一个待执行任务和一个已付通知事件。未伪造网关尝试或回调，未运行供应商任务。

钱包真实表单先加 5.00 元再扣 2.00 元，可用余额从 20.00 变为 23.00 元；冻结 4.00 元及结清冻结 2.00 元保持。已核对重复、冲突、超额扣款、权限、真实 CSRF，以及凭证失败不回填与关闭清空。

| 截图 | 尺寸 |
| --- | --- |
| [unpaid-invoice-1440-light.png](unpaid-invoice-1440-light.png) | 1440×2545 |
| [wallet-customer-1440-dark.png](wallet-customer-1440-dark.png) | 1440×2343 |
| [wallet-dialog-1440-light.png](wallet-dialog-1440-light.png) | 1440×1000 |
| [offline-dialog-390-dark.png](offline-dialog-390-dark.png) | 390×844 |
| [wallet-validation-390-dark.png](wallet-validation-390-dark.png) | 390×844 |
| [offline-validation-390-dark.png](offline-validation-390-dark.png) | 390×844 |
| [paid-invoice-1440-light.png](paid-invoice-1440-light.png) | 1440×2764 |
| [paid-invoice-390-dark.png](paid-invoice-390-dark.png) | 390×844 |
| [receipt-details-1440-light.png](receipt-details-1440-light.png) | 1440×1000 |
| [receipt-details-390-dark.png](receipt-details-390-dark.png) | 390×844 |

[完整验收](acceptance.json) · [PNG SHA-256](SHA256SUMS)

浏览器共 24 项布局、12 项弹窗、24 项权限边界和 29 项最终交互检查；page error 为 0。普通 GET 覆盖 60 张业务表且哈希无变化。真实表单成功后的独立数据库核验确认资金、收款、任务和通知事件恰好一次。

`root_checks` 单独记录主代理的全量门禁、隔离 MySQL 与预览部署结果；专项与全量存在重叠，不将数量相加。默认头像仍有既有 `ui-avatars.com` 请求，供应商与支付请求为零，本机 SMTP 收信数为零；本批不证明外部通知送达或供应商交付。

隔离 HTTP／SMTP、命名浏览器、临时数据库／存储与口令元数据均已清理；共享预览 `/up` 返回 200。
