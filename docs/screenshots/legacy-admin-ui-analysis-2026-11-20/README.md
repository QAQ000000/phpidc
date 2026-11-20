# 旧后台原始资源布局观察

日期：2026-11-20。对应[主分析](../../legacy-admin-ui-hierarchy-analysis-2026-11-20.md)。

使用旧项目原始编译 JS/CSS，在隔离 loopback 静态服务器中渲染。浏览器注入模拟语言、管理员标记和五域菜单；客户列表响应为空。外部来源请求阻断，不加载旧 PHP、不连接旧业务库或真实业务 API。模拟 token 仅供静态前端路由使用，不代表真实后台登录。

这些截图证明相应资源的布局与菜单/筛选交互，不代表旧系统的业务验收、完整菜单、真实数据或性能测量。

| 文件 | 内容 |
| --- | --- |
| [customer-list-1440.png](customer-list-1440.png) | 桌面客户空列表、顶部主域与200px上下文侧栏 |
| [customer-search-1440.png](customer-search-1440.png) | 桌面高级筛选展开 |
| [customer-list-390.png](customer-list-390.png) | 手机客户空列表与表内横向密度 |
| [customer-search-390.png](customer-search-390.png) | 手机高级筛选展开 |
| [mobile-navigation-390.png](mobile-navigation-390.png) | 手机左抽屉展开，五域菜单为模拟数据 |
| [observations.json](observations.json) | 视口/DOM实测与源文件摘要 |

未归档不完整模拟的客户详情容器，也不将其请求取消或模拟响应缺字段误报为真实站点缺陷。静态资源摘要与截图摘要分别记录于 observations.json、SHA256SUMS。
