# 旧后台路由与菜单完整清单

日期：2026-11-20。对应[主分析](legacy-admin-ui-hierarchy-analysis-2026-11-20.md)。

## 口径

以下为当前磁盘编译路由及安装/导入用权限种子，不是运行库实时菜单，也不是全部已运行成功的业务页面。

- Vue 路由定义：229 条；含容器、错误页、旧页和独立窗口页。
- 带 children 的容器：6 个；根 Home 的直接子路由179条。
- 权限 SQL：1019条规则，其中151条 is_display=1。
- 这151条中，143条可沿显示父节点从8个根抵达；8条缺显示父节点。分类、外链、接口规则也在种子中。
- 菜单按 pid 组织；路由按 children 组织；二者不是一一对应关系。

## 权限种子中的显示树

来源：`/www/wwwroot/zjmf-manger-decoded/downloads/database/auth_rule.sql`。外链仅标注类型，未访问。

```text
客户  /customer-list  [rule 1]
  客户管理  （分类）  [rule 1860]
    客户列表  /customer-list  [rule 13]
    实名认证  /customer-authentication  [rule 542]
    客户资源池  /customer-resources  [rule 1882]
  我的业绩  （分类）  [rule 1861]
    我的业绩  /sales-statistics  [rule 1454]
  运营管理  （分类）  [rule 1863]
    营销推送  /marketing-push  [rule 1864]
    推介计划  /customer-promotionplan  [rule 1463]
业务  /order-list  [rule 2]
  订单  （分类）  [rule 1866]
    产品订单  /order-list  [rule 15]
    续费订单  /renewal-order  [rule 17]
    流量包订单  /dcim-traffic-log  [rule 27]
  业务  （分类）  [rule 1867]
    业务列表  /customer-product  [rule 28]
    产品暂停请求  /customer-cancelreq  [rule 1507]
财务  /business-statement  [rule 3]
  财务记录  （分类）  [rule 1868]
    交易流水  /business-statement  [rule 405]
    账单管理  /bill-management  [rule 408]
    信用额管理  /credit-management  [rule 2129]
  审核管理  （分类）  [rule 1869]
    提现审核  /customer-withdrawal  [rule 1588]
  发票和合同  （分类）  [rule 1900]
    发票列表  /invoice-audit  [rule 1985]
    合同列表  /contracts_audit  [rule 2147]
工单  /support-ticket  [rule 4]
  工单  /support-ticket  [rule 335]
    工单列表  /support-ticket  [rule 338]
    工单统计  /support-statistics  [rule 2014]
功能  /timing-results  [rule 1897]
  插件  （外链）  [rule 2041]
    插件列表  （外链）  [rule 2042]
  统计  /annual-statistics  [rule 2107]
    年度收入统计  /annual-statistics  [rule 1698]
    新客户  /new-customer  [rule 1699]
    产品收入  /product-revenue  [rule 1700]
    收入排名  /revenue-ranking  [rule 1701]
  系统状态  /timing-results  [rule 2102]
    定时任务状态  /timing-results  [rule 2108]
    任务队列  /statistics-taskQueue  [rule 2103]
    数据库状态  /database-message  [rule 481]
设置  /set  [rule 6]
  基础设置  /set  [rule 7]
    客户设置  （分类）  [rule 1901]
      自定义客户字段  /customer-custom  [rule 512]
      推介设置  /promotion_plan  [rule 1680]
      客户等级  /customer-level  [rule 1943]
      客户分组与折扣  /customer-group  [rule 448]
      实名设置  /authentication-setting  [rule 454]
    财务设置  （分类）  [rule 1902]
      货币配置  /currency-settings  [rule 260]
      支付接口  /payment-interface  [rule 11]
      充值与财务  /general-settings/finance  [rule 1892]
      优惠码  /promo-code  [rule 203]
      发票设置  /voucher-setting  [rule 2010]
      合同设置  /contracts_setting  [rule 2148]
      信用额设置  /credit-setting  [rule 2143]
    工单设置  （分类）  [rule 1320]
      工单部门  /work-order-dept  [rule 308]
      工单状态  /work-order-status  [rule 317]
      工单传递  /work-order-rules  [rule 1996]
  系统设置  /general-settings/general  [rule 1909]
    基础设置  （分类）  [rule 1910]
      常规设置  /general-settings/general  [rule 462]
      第三方登录  /third-login  [rule 2073]
      定时任务  /automatic-tasks  [rule 1894]
      注册登录  /login-register  [rule 2069]
    人员管理  （分类）  [rule 1912]
      分组权限  /permissions-managment  [rule 588]
      销售设置  /sales-management  [rule 1443]
      员工管理  /admin-management  [rule 195]
    短信邮件设置  （分类）  [rule 1913]
      接口设置  /sms-template/sms  [rule 530]
      邮件模板  /email-list  [rule 531]
      短信模板  /sms-template-index  [rule 533]
      发送设置  /sms-send-settings  [rule 536]
    安全相关  （分类）  [rule 1914]
      二次验证  /twice-confirm  [rule 2074]
      验证码设置  /general-settings/captcha  [rule 1930]
      黑名单列表  /black-list  [rule 1726]
    系统相关  （分类）  [rule 1915]
      数据迁移  /data-migration  [rule 1599]
      关于  /about  [rule 1601]
      系统升级  /system-message  [rule 479]
      php信息  /php-message  [rule 480]
    日志  （分类）  [rule 1916]
      短信日志  /sms-log  [rule 1321]
      站内信日志  /station-letter-log  [rule 1590]
      API日志  /api-log  [rule 1849]
      定时任务日志  /automatic-task-log  [rule 1687]
      日志清理  /log-cleanup  [rule 1965]
      系统日志  /system-log  [rule 485]
      管理员登录日志  /system-admin-log  [rule 486]
      邮件日志  /email-log  [rule 488]
  商品设置  /product-server  [rule 5]
    商品配置  （分类）  [rule 1875]
      商品管理  /product-server  [rule 18]
      流量包管理  /dcim-traffic  [rule 19]
    自动化接口  （分类）  [rule 1876]
      通用接口  /server-settings  [rule 528]
      魔方DCIM  /dcim  [rule 1813]
      魔方云  /zjmfcloud  [rule 1920]
    设置  （分类）  [rule 1878]
      全局可配置项  /configurable-option  [rule 1879]
      商品订购设置  /order-product  [rule 2071]
  站务设置  /base-info  [rule 8]
    显示设置  /base-info  [rule 238]
      基础信息  /base-info  [rule 2064]
      主题模板  /theme-template  [rule 2065]
      官网自定义字段  /custom-template-fields  [rule 2066]
      导航管理  /menu_manage  [rule 2075]
      友情链接  /friendly_link  [rule 2149]
    文件下载  /service-support  [rule 420]
      文件分组  /service-support  [rule 2072]
    新闻中心  （分类）  [rule 1316]
      新闻列表  /news-list  [rule 518]
      新闻分类  /news-category  [rule 519]
    帮助中心  （分类）  [rule 2067]
      帮助列表  /help-list  [rule 1317]
      帮助分类  /help-category  [rule 1318]
资源与商店  /app-store/app-list  [rule 1895]
  国际资源  （外链）  [rule 1903]
  上下游  /zjmf-api  [rule 1899]
    上游资源  （分类）  [rule 2110]
      供应商管理  /zjmf-api  [rule 1688]
      商品管理  /commodity-list  [rule 2111]
      产品管理  /commodity-product  [rule 2112]
      任务队列  /commodity-taskQueue  [rule 2113]
      服务器列表  /munual-resource  [rule 1603]
      订单列表  /supplier-order-list  [rule 2127]
      续费订单  /supplier-renewal-order  [rule 2200]
    下游管理  （分类）  [rule 2098]
      任务队列  /task-queue  [rule 2101]
      API设置  /api-setup  [rule 2099]
  应用商店  /app-store/app-list  [rule 1898]
    我的应用  /app-store  [rule 1924]
反馈  （分类）  [rule 1904]
  需求  （外链）  [rule 1905]
  互助  （外链）  [rule 1906]
  BUG反馈  （外链）  [rule 1907]
```

## 未连接到显示父节点的种子

| rule ID | 父ID | 名称 | URL |
| --- | --- | --- | --- |
| 1340 | 404 | 账单列表页搜索 | `/bill-management` |
| 1362 | 1363 | 短信模板列表接口数据 | `/sms-template/sms` |
| 1489 | 21 | 商品设置 | `/zjmfcloud-product` |
| 1763 | 1762 | 应用列表 | `/application-list?appstatus=1` |
| 1764 | 1762 | 应用审核 | `/appcheck-list?appstatus=2` |
| 2068 | 251 | 基础信息 | `/menber-base-info` |
| 2070 | 251 | 主题模板 | `/menber-theme-template` |
| 2109 | 2100 | 供应商管理 | `/supplier-management` |

## Vue 路由定义完整清单

来源：`/www/wwwroot/zjmf-manger-decoded/public/admin/js/app~5a11b65b.94a330a1.js`，Webpack模块 `a18c` 的 `Cc` 数组。路径是 `#` 后的前端路由；不是后端 API。`name` 保留原拼写。空的父容器无 redirect 时，不视为可独立工作的业务详情。

| 序号 | 完整前端路径 | route name | 父容器 | 直接 children | redirect |
| ---: | --- | --- | --- | ---: | --- |
| 1 | `/login` | `login` | `根级` | 0 | `—` |
| 2 | `/forbidden` | `Forbidden` | `根级` | 0 | `—` |
| 3 | `/404` | `404` | `根级` | 0 | `—` |
| 4 | `/500` | `500` | `根级` | 0 | `—` |
| 5 | `/edit-configurable-option1` | `editConfigurableOption1` | `根级` | 0 | `—` |
| 6 | `/email-log-detail1` | `emailLogDetail1` | `根级` | 0 | `—` |
| 7 | `/balance-details1` | `balanceDetails1` | `根级` | 0 | `—` |
| 8 | `/email-preview` | `EmailPreview` | `根级` | 0 | `—` |
| 9 | `/senior-config` | `SeniorConfig` | `根级` | 0 | `—` |
| 10 | `/system-updata` | `systemUpdata` | `根级` | 0 | `—` |
| 11 | `/` | `home` | `根级` | 179 | `home-page` |
| 12 | `/home-page` | `homePage` | `/` | 0 | `—` |
| 13 | `/app-detail` | `appDetail` | `/` | 0 | `—` |
| 14 | `/app-store` | `appStoreNew` | `/` | 4 | `—` |
| 15 | `/app-store/app-inner` | `appInner` | `/app-store` | 0 | `—` |
| 16 | `/app-store/app-leaderboard` | `appLeaderboard` | `/app-store` | 0 | `—` |
| 17 | `/app-store/app-list` | `appList` | `/app-store` | 0 | `—` |
| 18 | `/app-store/my-app` | `myApp` | `/app-store` | 0 | `—` |
| 19 | `/install-progress` | `installProgress` | `/` | 0 | `—` |
| 20 | `/customer-list` | `customerList` | `/` | 0 | `—` |
| 21 | `/base-info` | `baseInfo` | `/` | 0 | `—` |
| 22 | `/theme-template` | `themeTemplate` | `/` | 0 | `—` |
| 23 | `/login-register` | `loginRegister` | `/` | 0 | `—` |
| 24 | `/order-product` | `orderProduct` | `/` | 0 | `—` |
| 25 | `/twice-confirm` | `twiceConfirm` | `/` | 0 | `—` |
| 26 | `/third-login` | `thirdLogin` | `/` | 0 | `—` |
| 27 | `/customer-view` | `customerView` | `/` | 19 | `—` |
| 28 | `/customer-view/abstract` | `abstract` | `/customer-view` | 0 | `—` |
| 29 | `/customer-view/station-letterlog` | `customerStationLetterList` | `/customer-view` | 0 | `—` |
| 30 | `/customer-view/developer` | `developer` | `/customer-view` | 0 | `—` |
| 31 | `/customer-view/abstractOld` | `abstractOld` | `/customer-view` | 0 | `—` |
| 32 | `/customer-view/bill` | `bill` | `/customer-view` | 0 | `—` |
| 33 | `/customer-view/transactions` | `transactions` | `/customer-view` | 0 | `—` |
| 34 | `/customer-view/credit` | `credit` | `/customer-view` | 0 | `—` |
| 35 | `/customer-view/tickets` | `tickets` | `/customer-view` | 0 | `—` |
| 36 | `/customer-view/log` | `log` | `/customer-view` | 0 | `—` |
| 37 | `/customer-view/noticelog` | `noticelog` | `/customer-view` | 0 | `—` |
| 38 | `/customer-view/annex` | `annex` | `/customer-view` | 0 | `—` |
| 39 | `/customer-view/smslog` | `smslog` | `/customer-view` | 0 | `—` |
| 40 | `/customer-view/emaillog` | `emaillog` | `/customer-view` | 0 | `—` |
| 41 | `/customer-view/person` | `person` | `/customer-view` | 0 | `—` |
| 42 | `/customer-view/product-innerpage` | `productInnerpage` | `/customer-view` | 0 | `—` |
| 43 | `/customer-view/product-list` | `productList` | `/customer-view` | 0 | `—` |
| 44 | `/customer-view/promotion_plan` | `promotion_plan` | `/customer-view` | 0 | `—` |
| 45 | `/customer-view/follow-status` | `FollowStatus` | `/customer-view` | 0 | `—` |
| 46 | `/customer-view/api-overview` | `CustomerApiOverview` | `/customer-view` | 0 | `—` |
| 47 | `/set` | `Set` | `/` | 0 | `customer-custom` |
| 48 | `/customer-add` | `customerAdd` | `/` | 0 | `—` |
| 49 | `/customer-group` | `customerGroup` | `/` | 0 | `—` |
| 50 | `/customer-custom` | `customerCustom` | `/` | 0 | `—` |
| 51 | `/customer-product` | `customerProduct` | `/` | 0 | `—` |
| 52 | `/customer-authentication` | `CustomerAuthentication` | `/` | 0 | `—` |
| 53 | `/add-records` | `AddRecords` | `/` | 0 | `—` |
| 54 | `/cancel-request` | `cancelRequest` | `/` | 0 | `—` |
| 55 | `/sales-management` | `salesManagement` | `/` | 0 | `—` |
| 56 | `/sales-statistics` | `SalesStatistics` | `/` | 0 | `—` |
| 57 | `/customer-promotionplan` | `CustomerPromotionplan` | `/` | 0 | `—` |
| 58 | `/customer-withdrawal` | `WithdrawalAudit` | `/` | 0 | `—` |
| 59 | `/customer-cancelreq` | `CustomerCancelReq` | `/` | 0 | `—` |
| 60 | `/customer-resources` | `CustomerResources` | `/` | 0 | `—` |
| 61 | `/customer-level` | `CustomerLevel` | `/` | 0 | `—` |
| 62 | `/order-list` | `orderList` | `/` | 0 | `—` |
| 63 | `/add-order` | `addOrder` | `/` | 0 | `—` |
| 64 | `/order-detail` | `orderDetail` | `/` | 0 | `—` |
| 65 | `/renewal-order` | `renewalOrder` | `/` | 0 | `—` |
| 66 | `/supplier-renewal-order` | `supplierRenewalOrder` | `/` | 0 | `—` |
| 67 | `/business-statement` | `businessStatement` | `/` | 0 | `—` |
| 68 | `/bill-management` | `billManagement` | `/` | 0 | `—` |
| 69 | `/bill-detail` | `billDetail` | `/` | 0 | `—` |
| 70 | `/preset-reply` | `presetReply` | `/` | 0 | `—` |
| 71 | `/addedit-pre-reply` | `addeditPreReply` | `/` | 0 | `—` |
| 72 | `/support-ticket` | `supportTicket` | `/` | 0 | `—` |
| 73 | `/add-support-ticket` | `addSupportTicket` | `/` | 0 | `—` |
| 74 | `/support-ticket-detail` | `supportTicketDetail` | `/` | 0 | `—` |
| 75 | `/support-statistics` | `SupportStatistics` | `/` | 0 | `—` |
| 76 | `/news-list` | `newsList` | `/` | 0 | `—` |
| 77 | `/add-news` | `addNews` | `/` | 0 | `—` |
| 78 | `/news-category` | `newsCategory` | `/` | 0 | `—` |
| 79 | `/help-list` | `helpList` | `/` | 0 | `—` |
| 80 | `/add-help` | `addHelp` | `/` | 0 | `—` |
| 81 | `/help-category` | `helpCategory` | `/` | 0 | `—` |
| 82 | `/custom-template-fields` | `customTemplateFields` | `/` | 0 | `—` |
| 83 | `/add-custom-template-fields` | `addCustomTemplateFields` | `/` | 0 | `—` |
| 84 | `/sms-template` | `smsTemplate` | `/` | 2 | `—` |
| 85 | `/sms-template/sms` | `sms` | `/sms-template` | 0 | `—` |
| 86 | `/sms-template/email` | `email` | `/sms-template` | 0 | `—` |
| 87 | `/sms-template-index` | `smsTemplateIndex` | `/` | 0 | `—` |
| 88 | `/sms-create-template` | `smsCreateTemplate` | `/` | 0 | `—` |
| 89 | `/sms-send-settings` | `smsSendSettings` | `/` | 0 | `—` |
| 90 | `/system-log` | `systemLog` | `/` | 0 | `—` |
| 91 | `/station-letter-log` | `StationLetterLog` | `/` | 0 | `—` |
| 92 | `/system-admin-log` | `systemAdminLog` | `/` | 0 | `—` |
| 93 | `/inform-log` | `informLog` | `/` | 0 | `—` |
| 94 | `/email-log` | `emailLog` | `/` | 0 | `—` |
| 95 | `/sms-log` | `smsLog` | `/` | 0 | `—` |
| 96 | `/api-log` | `ApiLog` | `/` | 0 | `—` |
| 97 | `/log-cleanup` | `LogCleanup` | `/` | 0 | `—` |
| 98 | `/automatic-task-log` | `automaticTaskLog` | `/` | 0 | `—` |
| 99 | `/system-message` | `systemMessage` | `/` | 0 | `—` |
| 100 | `/php-message` | `phpMessage` | `/` | 0 | `—` |
| 101 | `/database-message` | `databaseMessage` | `/` | 0 | `—` |
| 102 | `/data-migration` | `DataMigration` | `/` | 0 | `—` |
| 103 | `/about` | `about` | `/` | 0 | `—` |
| 104 | `/general-settings` | `generalSettings` | `/` | 13 | `—` |
| 105 | `/general-settings/general` | `general` | `/general-settings` | 0 | `—` |
| 106 | `/general-settings/local` | `local` | `/general-settings` | 0 | `—` |
| 107 | `/general-settings/support` | `support` | `/general-settings` | 0 | `—` |
| 108 | `/general-settings/promote` | `promote` | `/general-settings` | 0 | `—` |
| 109 | `/general-settings/safe` | `safe` | `/general-settings` | 0 | `—` |
| 110 | `/general-settings/other` | `other` | `/general-settings` | 0 | `—` |
| 111 | `/general-settings/invoice` | `invoice` | `/general-settings` | 0 | `—` |
| 112 | `/general-settings/login-setting` | `loginSetting` | `/general-settings` | 0 | `—` |
| 113 | `/general-settings/captcha` | `captcha` | `/general-settings` | 0 | `—` |
| 114 | `/general-settings/finance` | `finance` | `/general-settings` | 0 | `—` |
| 115 | `/general-settings/order` | `OrderGoods` | `/general-settings` | 0 | `—` |
| 116 | `/general-settings/class` | `class` | `/general-settings` | 0 | `—` |
| 117 | `/general-settings/source-api` | `sourceApi` | `/general-settings` | 0 | `—` |
| 118 | `/second` | `second` | `/` | 0 | `—` |
| 119 | `/voucher-setting` | `InvoiceSetting` | `/` | 0 | `—` |
| 120 | `/invoice-audit` | `InvoiceAudit` | `/` | 0 | `—` |
| 121 | `/admin-management` | `adminManagement` | `/` | 0 | `—` |
| 122 | `/admin-edit` | `adminEdit` | `/` | 0 | `—` |
| 123 | `/black-list` | `blackList` | `/` | 0 | `—` |
| 124 | `/email-list` | `emailList` | `/` | 0 | `—` |
| 125 | `/email-edit` | `emailEdit` | `/` | 0 | `—` |
| 126 | `/currency-settings` | `currencySettings` | `/` | 0 | `—` |
| 127 | `/permissions-managment` | `permissionsManagment` | `/` | 0 | `—` |
| 128 | `/permissions-edit` | `permissionsEdit` | `/` | 0 | `—` |
| 129 | `/automatic-tasks` | `automaticTasks` | `/` | 0 | `—` |
| 130 | `/timing-results` | `timingResults` | `/` | 0 | `—` |
| 131 | `/promotion_plan` | `PromotionPlan` | `/` | 0 | `—` |
| 132 | `/module-plugin` | `modulePlugin` | `/` | 0 | `—` |
| 133 | `/authentication-setting` | `authenticationSetting` | `/` | 0 | `—` |
| 134 | `/payment-interface` | `paymentInterface` | `/` | 0 | `—` |
| 135 | `/promo-code` | `promoCode` | `/` | 0 | `—` |
| 136 | `/promo-code-add` | `promoCodeAdd` | `/` | 0 | `—` |
| 137 | `/work-order-dept` | `workOrderDept` | `/` | 0 | `—` |
| 138 | `/new-work-order-dept` | `newWorkOrderDept` | `/` | 0 | `—` |
| 139 | `/add-custom-fields` | `addCustomFields` | `/` | 0 | `—` |
| 140 | `/work-order-status` | `workOrderStatus` | `/` | 0 | `—` |
| 141 | `/work-order-rules` | `WorkOrderRules` | `/` | 0 | `—` |
| 142 | `/product-server` | `productServer` | `/` | 0 | `—` |
| 143 | `/add-product-group` | `addProductGroup` | `/` | 0 | `—` |
| 144 | `/edit-product` | `editProduct` | `/` | 0 | `—` |
| 145 | `/configurable-option` | `configurableOption` | `/` | 0 | `—` |
| 146 | `/edit-configurable-option-group` | `editConfigurableOptionGroup` | `/` | 0 | `—` |
| 147 | `/server-settings` | `serverSettings` | `/` | 0 | `—` |
| 148 | `/add-server` | `addServer` | `/` | 0 | `—` |
| 149 | `/group-list` | `groupList` | `/` | 0 | `—` |
| 150 | `/add-group` | `addGroup` | `/` | 0 | `—` |
| 151 | `/add-interface` | `addInterface` | `/` | 0 | `—` |
| 152 | `/dcim` | `dcim` | `/` | 0 | `—` |
| 153 | `/dcim-view` | `dcimView` | `/` | 0 | `—` |
| 154 | `/dcim-traffic` | `dcimTraffic` | `/` | 0 | `—` |
| 155 | `/dcim-traffic-log` | `dcimLog` | `/` | 0 | `—` |
| 156 | `/dcim-product` | `productManagement` | `/` | 0 | `—` |
| 157 | `/zjmfcloud` | `zjmfcloud` | `/` | 0 | `—` |
| 158 | `/zjmfcloud-product` | `zjmfcloudProduct` | `/` | 0 | `—` |
| 159 | `/munual-resource` | `munualResource` | `/` | 0 | `—` |
| 160 | `/upStream-edit` | `upStreamEdit` | `/` | 0 | `—` |
| 161 | `/addOrEdit-resource` | `addOrEditResource` | `/` | 0 | `—` |
| 162 | `/task-queue` | `TaskQueue` | `/` | 0 | `—` |
| 163 | `/configure-edit` | `ConfigureEdit` | `/` | 0 | `—` |
| 164 | `/api-setup` | `ApiSetUp` | `/` | 0 | `—` |
| 165 | `/statistics-taskQueue` | `statisticstaskQueue` | `/` | 0 | `—` |
| 166 | `/commodity-list` | `commodityList` | `/` | 0 | `—` |
| 167 | `/commodity-product` | `commodityProduct` | `/` | 0 | `—` |
| 168 | `/commodity-taskQueue` | `commodityTaskQueue` | `/` | 0 | `—` |
| 169 | `/add-supplier` | `addSupplier` | `/` | 0 | `—` |
| 170 | `/supplier-order-list` | `supplierOrderList` | `/` | 0 | `—` |
| 171 | `/zjmf-api` | `ZjmfApi` | `/` | 0 | `—` |
| 172 | `/official-setting` | `OfficialSetting` | `/` | 0 | `—` |
| 173 | `/menu_manage` | `MenuManage` | `/` | 0 | `—` |
| 174 | `/create_menu` | `CreateMenu` | `/` | 0 | `—` |
| 175 | `/create_menu_www` | `CreateMenuWww` | `/` | 0 | `—` |
| 176 | `/friendly_link` | `Friendlylink` | `/` | 0 | `—` |
| 177 | `/marketing-push` | `MarketingPush` | `/` | 0 | `—` |
| 178 | `/message-write` | `MessageWrite` | `/` | 0 | `—` |
| 179 | `/annual-statistics` | `AnnualStatistics` | `/` | 0 | `—` |
| 180 | `/service-support` | `ServiceSupport` | `/` | 0 | `—` |
| 181 | `/file` | `File` | `/` | 0 | `—` |
| 182 | `/new-customer` | `NewCustomer` | `/` | 0 | `—` |
| 183 | `/product-revenue` | `ProductRevenue` | `/` | 0 | `—` |
| 184 | `/revenue-ranking` | `RevenueRanking` | `/` | 0 | `—` |
| 185 | `/edit-person` | `EditPerson` | `/` | 0 | `—` |
| 186 | `/customer-developer` | `customerDeveloperList` | `/` | 0 | `—` |
| 187 | `/application-list` | `applicationList` | `/` | 0 | `—` |
| 188 | `/application-detail` | `applicationDetail` | `/` | 0 | `—` |
| 189 | `/appcheck-list` | `appCheckList` | `/` | 0 | `—` |
| 190 | `/comment-list` | `commentList` | `/` | 0 | `—` |
| 191 | `/hot-app` | `hotApp` | `/` | 0 | `—` |
| 192 | `/highly-recommended` | `highlyRecommended` | `/` | 0 | `—` |
| 193 | `/dcim-authorization` | `DcimAuthorization` | `/` | 0 | `—` |
| 194 | `/dcim-authorization-disable` | `DcimAuthorizationDisable` | `/` | 0 | `—` |
| 195 | `/dcim-authorization-error` | `DcimAuthorizationErrorLog` | `/` | 0 | `—` |
| 196 | `/dcim-debug-log` | `DcimDebugErrorLog` | `/` | 0 | `—` |
| 197 | `/dcim-authorization-update` | `DcimAuthorizationUpdate` | `/` | 0 | `—` |
| 198 | `/resource-pool` | `ResourceRool` | `/` | 0 | `—` |
| 199 | `/credit-management` | `CreditManagement` | `/` | 0 | `—` |
| 200 | `/credit-setting` | `CreditSrtting` | `/` | 0 | `—` |
| 201 | `/notify_list` | `notifyList` | `/` | 0 | `—` |
| 202 | `/Instruction_query` | `InstructionQuery` | `/` | 0 | `—` |
| 203 | `/auto_reply_setting` | `AutoReplySetting` | `/` | 0 | `—` |
| 204 | `/functional-module` | `FunctionalModule` | `/` | 0 | `—` |
| 205 | `/add_functional_module` | `AddFunctionalModule` | `/` | 0 | `—` |
| 206 | `/keyword-manage` | `KeyWords` | `/` | 0 | `—` |
| 207 | `/special-reply` | `SpecialReply` | `/` | 0 | `—` |
| 208 | `/add_keywords` | `AddKeywords` | `/` | 0 | `—` |
| 209 | `/cate-management` | `cateManagement` | `/` | 0 | `—` |
| 210 | `/knowledge-base` | `knowledgeBase` | `/` | 0 | `—` |
| 211 | `/plug-management` | `PlugManagement` | `/` | 1 | `—` |
| 212 | `/plug-management/plug-export` | `plugExport` | `/plug-management` | 0 | `—` |
| 213 | `/contracts_audit` | `contractsAudit` | `/` | 0 | `—` |
| 214 | `/contracts_setting` | `contractsSetting` | `/` | 0 | `—` |
| 215 | `/add_contract` | `addContract` | `/` | 0 | `—` |
| 216 | `/order-management-list` | `orderManagementList` | `/` | 0 | `—` |
| 217 | `/refund-detail` | `refundDetail` | `/` | 0 | `—` |
| 218 | `/aftersale-detail` | `aftersaleDetail` | `/` | 0 | `—` |
| 219 | `/business-management` | `businessManagement` | `/` | 0 | `—` |
| 220 | `/resourcePool-taskQueue` | `resourcePoolTaskQueue` | `/` | 0 | `—` |
| 221 | `/resourcePool-workOrder` | `resourcePoolWorkOrder` | `/` | 0 | `—` |
| 222 | `/resourcePool-set` | `resourcePoolSet` | `/` | 0 | `—` |
| 223 | `/statistical-information` | `statisticalInformation` | `/` | 0 | `—` |
| 224 | `/commodity-management` | `commodityManagement` | `/` | 0 | `—` |
| 225 | `/ssistant-audit` | `ssistantAudit` | `/` | 0 | `—` |
| 226 | `/assist-apply` | `assistApply` | `/` | 0 | `—` |
| 227 | `/assist-detail` | `assistDetail` | `/` | 0 | `—` |
| 228 | `/resource-pool-shop` | `resourcePoolShop` | `/` | 0 | `—` |
| 229 | `/resourcePool-logs` | `journalManagement` | `/` | 0 | `—` |

本文仅列源码声明，未根据 chunk 名自动判断功能已实现，也未做真实登录／写操作验收。
