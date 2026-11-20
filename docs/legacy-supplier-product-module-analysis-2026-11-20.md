# 旧后台复刻补充：供应商、商品导入、分组与通用接口

日期：2026-11-20。当前代码基线：`b950dee`。状态：**源码分析与方案补充，尚未实施**。

用户补充的目标：恢复旧后台方便的新增供应商、导入商品、商品分组编辑流程，以及可扩展的通用接口体验。本文件补充 [复刻总方案](legacy-admin-replica-plan-2026-11-20.md)；这些内容是运营工作流与扩展能力，不能只列作 R4 的表单换肤。

## 1. 复核结论

当前存在三个不同问题：

1. **工作流缺口**：供应商、目录和售卖商品挤在同页，连接操作埋在大弹窗；导入主要依赖单个 PID，缺目录多选和分组选择。
2. **管理功能缺口**：商品分类有存储字段，但没有对应的商品分组管理 Action；CMS 栏目不是商品分类。旧版一级分组、商品分组、商品三级树也不能由当前单层分类直接替代。
3. **扩展入口缺口**：已有能力接口与驱动，但后台按魔方、Sub2API 分开写表单，履约解析依赖固定枚举和分支；缺少让模块声明名称、字段、能力并进入统一界面的注册层。

需要一起恢复旧版的页面组织和实际可用操作；通用接口还需要独立的模块接入设计。

## 2. 旧供应商类型与通用接口是两套机制

| 旧能力 | 真实实现 | 对本项目的含义 |
| --- | --- | --- |
| 添加供应商“智简魔方／手动” | `addSupplier` 编译组件直接列出 `zjmf_api`、`manual`；后端仍按类型分支，另留 WHMCS 分支 | 表单布局便于扩展，但这个选择器本身不是动态插件目录 |
| 手动供应商 | 保存名称、联系方式、备注及人工资源归属 | 不等于已有一个能自动开通的“万能接口”；不应塞进 Sub2API 驱动冒充手动类型 |
| 通用接口模块选择 | `AddInterface` 从 `servers_add` 返回的 `data.modules` 生成选项 | 模块列表由服务端提供，新增模块不必逐页添加下拉项 |
| 模块发现及名称 | 扫模块目录，读取 `{module}_MetaData` | 名称、文档等由模块声明 |
| 商品模块配置 | `{module}_ConfigOptions` 声明字段类型、选项、默认值和说明 | 通用表单宿主根据声明渲染，供应商特有参数不挤入公共页面 |
| 连通性及履约 | `{module}_TestLink`、`{module}_CreateAccount` 等约定入口 | 通用的是宿主协议，每种外部服务仍有实际通信适配代码 |

源码依据：

- [添加供应商 UI](/www/wwwroot/zjmf-manger-decoded/public/admin/js/addSupplier~f71cff67.cbc03931.js:1)、[供应商保存](/www/wwwroot/zjmf-manger-decoded/app/admin/controller/ZjmfFinanceApiController.php:30)。
- [通用接口 UI](/www/wwwroot/zjmf-manger-decoded/public/admin/js/AddInterface~31ecd969.e3e29fab.js:1)、[模块列表接口](/www/wwwroot/zjmf-manger-decoded/app/admin/controller/ConfigServersController.php:82)。
- [模块字段](/www/wwwroot/zjmf-manger-decoded/app/common/logic/Provision.php:62)、[模块发现](/www/wwwroot/zjmf-manger-decoded/app/common/logic/Provision.php:706)、[操作分派](/www/wwwroot/zjmf-manger-decoded/app/common/logic/Provision.php:759)。
- [实际模块示例 bthosts](/www/wwwroot/zjmf-manger-decoded/public/plugins/servers/bthosts/bthosts.php:6)。磁盘中另有 `nokvm`、`wlkanglepro`。

当前旧工程未检出 Sub2API 模块，不能把用户对机制的描述当成该模块已在本盘安装验收的证据。其通用模块方式可以承载这类适配。

## 3. 恢复新增供应商的完整工作流

### 页面结构

独立供应商列表 → 新增／编辑 → 供应商详情。

- 列表直接显示名称、接口类型、地址／联系方式、启停、已关联商品与服务入口。
- 新增页按旧版分为“基本信息”和“自动开通／接口设置”。公共字段只填写一次。
- 接口类型切换时，仅显示该类型需要的配置。类型选项、字段与帮助文字由统一模块注册信息提供。
- 保存成功回列表定位新记录；从详情编辑则回原供应商上下文。
- 供应商详情保留概览、商品、已售服务及任务等实际可用对象菜单；导入按钮就在商品区域。

### 三个维度分清

- **供应商**是谁提供服务：名称、联系方式、备注。
- **接口连接**怎样访问某个服务端：模块、地址、凭据、状态。
- **售卖商品与分组**怎样组织销售：展示、分类、排序、定价及履约绑定。

同一售卖分组可以包含不同供应商商品。Sub2API 属于接口模块；不能因为当前有它的独立表单，就把它永久固定成另一种商品管理体系。旧手动供应商也不应被迫填写无意义的接口地址和密钥。

当前 `UpstreamApi` 同时承担名称和连接，不具备完整的独立手动供应商模型。恢复手动供应商及一供应商多连接时，需要明确新增存储关系，不能本轮直接宣布零迁移。

## 4. 导入应恢复“选目录、选分组、一次完成”

旧版供应商工作区的导入弹窗约 500px，集中提供：本地分组、供应商、按上游分组组织的可搜索商品多选、利润百分比、客户中心导航分类。从供应商详情进入时固定供应商；成功后原地刷新商品树。全局商品页复用同一导入表单，才需要选择供应商。

源码：[商品及导入组件](/www/wwwroot/zjmf-manger-decoded/public/admin/js/ConfigureEdit~commodityList~31ecd969.b9829884.js:1)、[导入初始数据](/www/wwwroot/zjmf-manger-decoded/app/admin/controller/ZjmfFinanceApiController.php:1561)、[批量导入](/www/wwwroot/zjmf-manger-decoded/app/admin/controller/ZjmfFinanceApiController.php:1601)。

新版应接通以下闭环：

1. 选择供应商后，显式读取目录；目录可搜索，按上游组呈现，标记已导入商品。
2. 多选目标商品，一次选择本地分组、币种和加价。币种仍受当前 CNY 售卖边界约束。
3. 提交后逐项显示导入成功／已关联／失败；成功商品能直接进入编辑页，失败项保留选择以便处理。
4. 每个商品继续复用完整快照校验与原子发布：事务外获取上游快照，事务内保存基础信息和完整配置。单项失败不暴露缺配置的新商品，也不撤销其他已完成项。
5. 重复提交不生成同连接同 PID 的重复商品；已关联商品与“重新同步”明确区分。
6. 返回时保留供应商、筛选与目录位置，避免每导入一个商品都回到总列表重新找连接。

不复制旧 `inputProduct` 把远端同步包在数据库事务内的实现。当前 [ImportClassicProductAction](/www/wwwroot/Laravel13idc/app/Actions/ImportClassicProductAction.php:19) 的完整导入保护保留；需要增加本地分组参数与批量入口，而非另写一套弱化导入。

Sub2API 现有协议是固定面额余额码发行，尚无售卖目录协议。对该连接显示“新建面额商品”，复用现有发行路径；将来模块提供目录能力后再开放同一导入入口。统一操作体验不要求凭空提供不存在的远端目录。

## 5. 商品分组是售卖管理的一部分

旧常规商品以 `ProductServer` 为依据，结构为：**一级分组 → 商品分组 → 商品**。可展开、拖拽排序、商品跨组、商品组跨一级组；分组行直接编辑或在本组新增商品，新建后进入完整编辑页。

源码：[ProductServer](/www/wwwroot/zjmf-manger-decoded/public/admin/js/ProductServer~31ecd969.6ab821f9.js:1)、[分组编辑页](/www/wwwroot/zjmf-manger-decoded/public/admin/js/AddProductGroup~f71cff67.6cedca3a.js:1)、[排序及跨组](/www/wwwroot/zjmf-manger-decoded/app/admin/controller/ProductController.php:116)、[商品分组保存](/www/wwwroot/zjmf-manger-decoded/app/admin/controller/ProductController.php:315)。

现项目 [categories 与 products 表](/www/wwwroot/Laravel13idc/database/migrations/2026_11_16_000001_create_cloud_cms_core_tables.php:84) 已有名称、slug、显隐和排序；`products.category_id` 已表达商品归组。可以先补齐受控管理入口，再恢复旧两级分组层级。

最小实用管理范围：分组新增／编辑／显隐／排序，商品排序和移动；分组内新增商品自动预选分组；不同驱动商品统一显示，以实际能力进入各自编辑流程。组内仍有商品时给出处理入口，不能删除后形成悬空归属。

完整旧三级树需要新增一级分组或分类父关系。旧客户导航分类、模板与其他扩展字段不能假装已有消费者；应分别列出是否需要。

商品公开路径包含分类 slug，见 [Product::publicPath](/www/wwwroot/Laravel13idc/app/Models/Product.php:97)。改分组别名或商品移组会改变旧商城链接，需要与发布管理一并明确旧地址的保留／301 方案。既有 CMS slug 历史表只管理文章和栏目，不能直接当作商品地址历史能力。

## 6. 模块注册驱动统一后台

推荐用小型模块注册层连接现有业务底座。每个已安装模块集中声明：

| 声明内容 | 通用后台据此提供 |
| --- | --- |
| 稳定标识、名称、说明、帮助文档 | 类型选项和模块信息 |
| 连接字段及服务端校验 | 地址、账号、密钥等相关表单；秘密字段不回显、留空保留 |
| 商品字段及校验 | 模块自己的商品配置区域，例如 Sub2API 面额 |
| 目录／连通性／本地 SKU／生命周期／机器能力 | 对应的真实按钮和表单，不支持的动作不出现 |
| 适配器工厂和现有配置 Action | 由 DriverManager 与受审计 Action 执行，通用页面不加入供应商协议分支 |

先把现有魔方与 Sub2API 接进这一注册层，复用当前 `FulfillmentDriver` 及能力接口，不为后台再写第二套履约引擎。注册层是页面和驱动之间缺失的连接部分，不是另一套队列、资金或订单系统。

目标验收：加入一个已部署模块后，供应商／接口类型列表、相关表单和支持动作由其声明出现；不需要修改通用供应商 Blade、控制器或逐页复制表单。外部协议适配仍由模块实现，不建立任意 HTTP 请求模板平台。

### 当前固定驱动标识的真实限制

当前 [DriverType](/www/wwwroot/Laravel13idc/app/Enums/DriverType.php:12) 是固定枚举；[DriverManager](/www/wwwroot/Laravel13idc/app/Services/DriverManager.php:99) 通过分支创建驱动，模型、履约记录和快照也使用这些标识。商品公开准入还有明确驱动条件，见 [Product::scopePublicCatalog](/www/wwwroot/Laravel13idc/app/Models/Product.php:73)。因此，仅增加一个“模块下拉框”不能声称支持任意新驱动。

第一步集中声明已有驱动即可减少页面硬编码；要达到新增第三方模块无需修改核心驱动枚举，还需要独立的**模块标识／注册解析／历史兼容合同**，覆盖商品、实例、任务、建单快照、公开准入和历史发卡。必须继续解析原 `zjmf_api`、`sub2api` 等记录，不批量改写历史订单。这个改动不混在外壳 CSS 中实施，也不预先承诺零迁移。

## 7. 对复刻批次的调整建议

R1 外壳完成后，供应商与商品流程应提前做为另一条样板线，再推广到其余页面。原 R2／R3 的客户、实例、财务和工单范围仍保留。

| 小批 | 范围 | 实际验收重点 |
| --- | --- | --- |
| P1 供应商与接口入口 | 独立列表、新增编辑、详情；内置模块统一声明；已有 Action 接入 | 基本信息与接口字段位置接近旧版；创建后能继续管理该供应商；既有连接与凭据语义不变 |
| P2 商品导入与售卖分组 | 目录搜索多选、指定分组、批量结果、售卖商品树、编辑与排序移动 | 从供应商新增到商品归组完整走通；每项完整导入；旧商城链接处理明确 |
| P3 通用模块扩展 | 完整模块标识及注册合同、手动供应商存储与未交付关系、必要的层级存储 | 以新增测试模块证明无需改通用页面；老驱动及历史任务继续按原协议工作 |

P1 可以先统一当前真实连接的界面。P2 涉及新写入口、分类层级及链接规则；P3 涉及新的标识／存储合同，实施前分别形成具体决议。手动供应商按钮应随其真实存储能力接入，不能先保存成一个假魔方连接。完整两级分组层级可在 P2 具体合同中纳入，不能留到验收才发现欠缺。

Sub2API 原地址不可原地替换、历史面额与端点快照继续有效；无远端目录的驱动仍能在同一售卖商品中心创建它真正支持的商品。

## 8. 本轮验证范围

只读核对旧前端编译资源、旧控制器／Provision／模块示例，以及当前页面、Actions、模型和迁移；未运行旧应用、查询业务库、连接真实供应商、导入商品或发送通知。本轮修改仅为本文及总方案的链接／拆批说明；文档链接与 diff 检查不代表功能验收。
