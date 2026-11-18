# 经典 ZJMF 面板登录协议与实际验收

验收于 2026-11-17 至 2026-11-18，复用已批准 C19 的原服务：本地实例 1、上游主机 4291、商品 391「CDN体验」。没有重新购买或执行续费、升级、暂停、终止。

## 源码与真实协议

参考副本 `zjmf-manger-decoded/app/home/controller/ProvisionController.php` 的 custom content 入口要求当前用户认证并核对主机归属。`public/plugins/servers/wlkanglepro/templates/information.html` 与 `bthosts/templates/information.html` 表明模块登录协议并不统一，不能凭插件名称推测登录链接。

本次真实认证 `/host/header?host_id=4291&source=API` 返回 Active；`module_button.control` 只有重置密码命令，没有面板登录 URL。`module_client_area` 广告 `information`（主机详情）栏目。随后按源码调用：

```text
POST /zjmf_api/provision/custom/content
Content-Type: application/x-www-form-urlencoded
id=4291&key=information

status=200
data.html=<模块内容>
```

该内容有唯一登录表单：POST 到 `https://kz.foxhssq.top:443/vhost/index.php?c=session&a=login`，隐藏字段为 token、username、passwd；本服务 token 为空，用户名和密码非空。不把真实字段值或 HTML 写入本记录。

## 实现与边界

- 服务详情 GET 只检查本人、Active 状态、启用账户、实例绑定与 `HasCustomPanelWidgets` 能力；不读取面板密码。按钮使用带本地 CSRF 的 POST `/console/instances/{instance}/panel-login`，新窗口打开，每分钟限 10 次。
- `OpenInstancePanelAction` 经 `CallUpstreamAction` 调用 Driver；HTTP 在数据库事务之外。请求后重新核对本人、状态、driver、账户、主机和 token，绑定变化时丢弃结果。Driver 不写模型，复用已有接口和 widget 结构（F10、INV-L3/L4）。
- `ZjmfPanelProtocol` 仅从认证 Active 且广告 information 的响应中提取已核实的 HTTPS Kangle/EasyPanel POST 表单。仅接收指定路径、登录操作及 token/username/passwd 隐藏字段；未知、缺失密码、HTTP 或多个匹配表单均拒绝。原始 HTML、脚本和事件处理器不执行。
- 转交页使用转义字段、nonce 脚本、限定目标 origin 的 CSP、no-store、no-referrer 和禁止 iframe 的响应头。外站表单只含上游字段，不向上游发送本地 CSRF token。浏览器必须收到上游登录凭据才能完成该表单协议；不将其伪称为无密码的一次性令牌登录。
- 每个上游调用保留操作日志；`data.html` 整体脱敏，密码与 JWT 不进入日志（INV-U1）。登录参数不新增持久化或缓存，不新增资金、账单、任务或实例状态写入。

按钮显示代表本地状态与 Driver 支持此能力，不能保证任意上游插件都能登录。点击后才核实具体协议并提示不可用；目前只真实验收上述 Kangle/EasyPanel 形式。其他插件、JavaScript SSO、HTTP 登录、V10 不在支持声明内。

## 实际点击验收

使用私有隔离 SQLite、storage、APP_KEY 与 loopback 临时 HTTP 服务，通过 Playwright 驱动已安装的 Chrome，保持 HTTPS 证书验证开启。

1. 从本地登录页登录隔离测试用户，打开本人 `/console/instances/1`，看到「登录面板」按钮并保存元素截图。
2. 实际点击按钮，经过本地认证 POST 与自动表单转交，新窗口进入 `https://kz.foxhssq.top/vhost/index.php`。
3. 页面标题为 EasyPanel 虚拟主机控制面板，显示「面板首页」「域名绑定」「连接信息」「性能分析」「安全退出」，并显示本次测试主机用户名及正常状态；已保存截图。该页面为已认证面板首页，不是仅打开登录页。
4. 浏览器控制台仅见 favicon.ico 404，不影响登录。未点击域名配置、清缓存、修改密码等业务操作。
5. 独立只读查询确认上游余额仍为 **5.85 CNY**；本地流水仍两条，上游 settle/apply_credit 各一次，本轮新增计费操作为零。
6. 点击「安全退出」后已回到上游 loginForm 登录页；关闭本轮专用浏览器会话和 loopback 临时 HTTP 服务，保留原 Active 资源与私有审计证据。

验收证据保存在私有状态目录 `/root/.local/state/laravel13idc/classic-live-20261117-5bbd1a71`。其中原始 HTML、浏览器快照与恢复配置可能含凭据，不入仓库；分享截图已检查无密码或 API 密钥。

## 检查与未验证项

新增 `ClassicPanelLoginTest` 覆盖本人隔离、状态/绑定、按需获取、表单协议拒绝、敏感日志脱敏、响应策略、无资金副作用及请求途中换绑；没有修改旧测试断言、Specs、冻结契约、枚举或数据库结构。

收尾 Gate：Pest **475 passed / 3726 assertions**；PHPStan **0 errors**；Pint **clean**；Specs 仍为 **0**。

真实公网回调仍受 `.invalid` APP_URL 限制；CDN 域名和流量、真实续费/升级/控制/终止仍未验收。面板成功登录不替代这些业务验收。
