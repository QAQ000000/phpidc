# 魔方财务（zjmf）上游 API 对接总册 · 老版站际对接

> **统一主题「老版站际对接」**：本文只描述「魔方财务站 ↔ 魔方财务站」的**老版相互对接**——`shd_zjmf_finance_api` 中 `type ∈ zjmf_api / manual / whmcs` 且 `is_resource=0` 的 `cart/*`、`host/*`、`api/product/*`、`/provision/default` 体系，以及 `is_resource=1` 的 `resource/*` 代理商 / 资源池体系；`whmcs` 线（`modules/addons/idcsmart_api/api.php`）与 `manual` 线（直连 IPMI/DCIM 凭证）作为老版形态的补充纳入。
> **分析基准**：本地 IonCube 反编译解码树 `C:/Users/hssqz/Downloads/zj/zjmf`（ZJMF 3.7.6 / ThinkCMF / ThinkPHP 5.x），全文 `文件:行号` 均相对该项目根、以本地实际读取为准。跨版本 / 部署事实（干净版、vhost 指向、外部参考树）本地树无对应文件可复核，一律标注「**未在代码中确认**」。
> **V10 不在范围内**：V10 分支契约正文一律不出现，统一收敛至文末「附录 Z：非站际对接范围（V10 分支，仅供存目）」。原文档以 V10 服务端实现为「真值」的字段字典已整体移出。
> **叙事修正（本次最核心）**：旧版断言「3.7.6 是纯消费方 / 无入站路由」不成立。本站同时具备**出站（本站=下游）**、**入站（本站=上游，被下游调用）**、**回传（本站=上游，主动推下游）**三种角色，见 §0.2。`home/CartController.php` 的 `cart/*`、`home/PayController.php` 的 `/apply_credit`、`LoginController::zjmfApiLogin()` 正是本站作为上游的服务端实现。
> **形态判定依据**：以 **`zjmf_finance_api.type` + `is_resource`** 为准，**不是**商品的 `products.api_type`（见 §0.3）。
> **体例**：每条结论带 `文件:行号`；查不到的一律写「未在代码中确认」，禁止推测性补全；明文密钥 / 密码字面量不写入本文（jwtkey 只记硬编码位置 `app/app.php:26`）；表格优先，不写空章节，单元格一律给出完整内容、不使用任何简写式占位表述。
> **编写日期**：2026-11-16 ｜ 全文只读分析，未改动任何业务代码。

---

## 目录

| 章 | 标题 | 覆盖 |
|---|---|---|
| §0 | 范围、术语与三视角 | 术语表、出站/入站/回传三视角表、**两形态表**、纳入/排除范围 |
| §1 | 鉴权 | 老版登录、JWT 签发与续期、**四路鉴权对照**、**签名算法** |
| §2 | 传输与响应约定 | 编码、响应结构、`status` 码（**1001 三义**）、分页、超时 |
| §3 | 商品目录（出站 + 入站双向） | 出站 6 函数、老版 `cart/all`、入站 `api/product/*` 与 `home/CartController` |
| §4 | 配置与定价 | `cart/get_product_config` 全字段、`upstreamBates()`、**18/17 键周期**、**17 对价格字段**、换算 |
| §5 | 老版下单 / 结算 / 支付 / 余额 / 退款 | **P1→P6 时序、`/cart/clear` 首步探活、落单、`AutoCreate.php:29` 开通链、幂等、表写入** |
| §6 | 主机信息与老版运维 | `host/header`、`cart/hostinfo`、`/provision/default`、**`resource/*` 21 个 path 代理商线** |
| §7 | 账户余额 | `cart/credit`、`cart/summary` 逐字段、健康检查 |
| §8 | 下游回传 | 三条登记、`pushHostInfo`、验签、重放、工单跨站 |
| §9 | 字段字典 | 商品/API 记录、周期与价格字段、`pay_type`、站际专用键 |
| §10 | 老版状态机 | 数字 status 映射、订单/账单状态、主机状态白名单 |
| §11 | 典型时序 | 出站 / 入站 / 回传 / 老版运维各一组 |
| §12 | 缺陷清单 | 仅站际相关，逐条带行号 |
| §13 | 快捷索引 | 按方向去重后的 path 清单 |
| — | 分册链接 / 附录：核心源码锚点 / 附录 Z | 存目与非站际对接范围说明 |

---

## 0. 范围、术语与三视角

### 0.1 统一术语（全文首处定义）

| 术语 | 定义 |
|---|---|
| **本站** | 当前这份 3.7.6 解码源码实例；既是某些链路的上游，也是另一些链路的下游 |
| **对端** | 与本站对接的另一台魔方财务站，不预设方向 |
| **上游** | 被本站调用、提供商品 / 主机 / 余额数据的一方（**按调用方向**定义，不指版本）；地址取自 `zjmf_finance_api.hostname`（`app/zjmf.php:193,209`） |
| **下游** | 调用本站、消费本站数据的一方；反向接收本站推送，地址/token 来自 `host.stream_info.downstream_url/downstream_token`（`app/zjmf.php:135-152`） |
| **出站视角** | 本站=下游，`zjmfCurl()` 调对端（`app/zjmf.php:185-224`） |
| **入站视角** | 本站=上游，`home/*`、`api/*` 被对端调用（`data/route/home.php`、`data/route/api.php`） |
| **回传视角** | 本站=上游，`pushHostInfo()` / `pushTicketReply()` 主动推下游 |
| **老版 zjmf_api 形态** | `zjmf_finance_api.type ∈ zjmf_api/manual/whmcs` 且 `is_resource=0`，`cart/*` 体系 |
| **资源 / 代理商形态** | `is_resource=1`（且 `is_using=1`、全局唯一），`resource/*` 体系 |
| **老版站际对接** | 本文统一主题词 |

**禁止**：用「上游」指 V10；用「形态①②③」；用「本站=纯消费方」「3.7.6 无入站路由」等旧断言。必须显式区分 **商品字段 `products.api_type`** 与 **API 记录字段 `zjmf_finance_api.type`**。

### 0.2 三视角表（全文接口方向判定基准）

| 视角 | 本站角色 | 入口 / 出口 | 代表接口 | 关键行号 |
|---|---|---|---|---|
| **出站** | 下游 | `app/zjmf.php::zjmfCurl()` → 对端 | `cart/all`、`cart/get_product_config`、`cart/credit`、`host/header`、`resource/*` | `app/zjmf.php:185-224` |
| **入站** | 上游 | `data/route/home.php`、`data/route/api.php` → 本站控制器 | `zjmf_api_login`、`resource_login_supplier`、`cart/*`、`api/product/*`、`api/host/sync` | `data/route/home.php:401-514`、`data/route/api.php:44-49` |
| **回传** | 上游 | 本站 `pushHostInfo()` → 下游 `api/host/sync` | `api/host/sync`、`api/ticket_reply/sync` | `app/zjmf.php:120-163`、`app/zjmf.php:90-119` |

> **这是本次收敛最核心的叙事修正**：旧文档断言「3.7.6 是纯消费方」，而 `home/CartController.php` 的 `cart/*`（`app/home/controller/CartController.php:31-3911` 方法表）、`home/PayController.php` 的 `/apply_credit`（`app/home/controller/PayController.php:259-344`）、`LoginController::zjmfApiLogin()`（`app/home/controller/LoginController.php:339-380`）正是本站作为**上游被下游调用**的服务端实现。`data/route/home.php:401-514`（组 3，`UserCheck`）与 `data/route/api.php:44-49` 是这些入站路由的注册处（§3.3、§5.3）。

### 0.3 形态判定与两形态表

形态**只看 `zjmf_finance_api` 这条 API 记录**的 `is_resource` 与 `type`，**与商品自己的 `products.api_type` 无关**——`api_type=resource`、`api_type=zjmf_api` 是商品侧字段，不能用来判定本次对接形态（`app/admin/controller/ProductController.php:1121` 的 `type` 分支即商品侧、勿混）。

收敛后的判定代码（历史版本分支已移出正文，见附录 Z）：

```php
// app/zjmf.php:38-53 getZjmfUpstreamProducts()
if ($api["is_resource"]) { // is_resource 真值判断，优先级最高
 $path = "resource/products"; // 资源 / 代理商形态 (app/zjmf.php:41-42)
} else {
 $path = "cart/all"; // 老版 zjmf_api 形态 (app/zjmf.php:45-46)
}
// zjmfCurl($id, $path, [], 30, "GET") (app/zjmf.php:48)；status==200 取 data，否则 [] (:49-52)
```

| 形态 | 判定（API 记录） | 登录入口 | 目录接口 | 能力范围 | 判定点 |
|---|---|---|---|---|---|
| **老版 zjmf_api 形态** | `type ∈ zjmf_api/manual/whmcs` 且 `is_resource=0` | `POST /zjmf_api_login`（`app/zjmf.php:200-203`） | `GET /cart/all`（`app/zjmf.php:46`） | 商品、下单/结算/余额、主机头信息、老版运维、工单跨站 | `app/zjmf.php:38-53` |
| **资源 / 代理商形态** | `is_resource=1`（优先级最高；`is_using=1` 全局唯一） | `POST /resource_login`（出站 path，`app/zjmf.php:194-196`） | `GET /resource/products`（`app/zjmf.php:42`） | 商品池、订单、续费、售后、巡检、评价；**无电源类接口** | `app/zjmf.php:41-42`、`app/admin/controller/AgentController.php:107` |

`is_resource` 优先级高于 `type` 的判定共 **4 处**（不要只写目录一处）：`app/zjmf.php:41-47`（目录）、`app/zjmf.php:194-203`（登录）、`app/zjmf.php:229-235`（`zjmfCurlHasFile`，无历史版本分支）、`app/admin/controller/ZjmfFinanceApiController.php:230-241`（连接测试，分支顺序 `is_resource → whmcs → 历史版本 → zjmf_api_login`）。另 `app/common/model/ProductModel.php:216,517` 以 `is_resource && is_using` 决定是否走 `getResourceRate()`。

**两种补充形态**（老版 `type` 维度内）：`type=manual` 不经上游财务站 API，本地持 `shd_upper_reaches*` 的 IPMI/DCIM 凭证直连被控端（§6.2）；`type=whmcs` 走 `whmcsCurlPost()` 调上游 WHMCS 财务站插件，用 `apiname/apikey` 而非 JWT（§6.2）。`zjmf_finance_api.type` 统一收敛为 `zjmf_api / manual / whmcs`（删 `v10`，补回 `whmcs`），`is_resource` 为单独维度（`public/install/thinkcmf.sql:5620` 默认值 `zjmf_api`、`:5839` `is_resource`、`:5844` `is_using`）。

### 0.4 纳入 / 排除范围

| 纳入（老版站际对接） | 排除（正文不出现契约细节，见附录 Z） |
|---|---|
| `cart/*`、`host/*`、`api/product/*`、`/provision/default` 体系；`resource/*` 代理商 / 资源池体系（21 个 path，§6.3） | V10 分支全链路（控制台/登录/商品/订单/支付）及其主控文件、购物车控制器、模块路由，统一收敛至附录 Z 存目 |
| 出站 / 入站 / 回传三视角全部接口；`whmcs` 线、`manual` 线、直连被控端 | 以历史版本服务端实现为真值的字段字典、状态枚举、能力矩阵 |
| 工单跨站、下游回传、登记/换绑、对账 | 外部部署参考树、跨版本（干净版）考证结论、任何以历史版本为「真值」的字段字典（本地树无对应文件可复核） |

---

## 1. 鉴权

### 1.1 前台 API 密钥与凭据存储

对接方**不使用客户登录密码**，而是用客户中心「API 密钥」页面生成的串。老版站际凭据链路（勘误收敛口径：「老版 = `clients.api_password` + DES-CBC」）：

| 形态 | 出站凭据来源 | 发送值 | 对端入站比对列 | 行号 |
|---|---|---|---|---|
| 老版 `zjmf_api_login` | `zjmf_finance_api.password`（密文） | `aesPasswordDecode($api["password"])` | `aesPasswordEncode($password) == clients.api_password` | `app/zjmf.php:200-203`；`app/home/controller/LoginController.php:365` |
| 资源 `resource_login` | `zjmf_finance_api.password`（密文） | `aesPasswordDecode($api["password"])` + `type="agent"` | `$password == clients.password`（**直接比登录密码列**） | `app/zjmf.php:194-196`；`app/home/controller/LoginController.php:413` |

`aesPasswordDecode()`（`app/common.php:1078-1085`）：`base64_decode` → `$key=md5("shundai")` → `IV=substr($key,0,8)` → `openssl_decrypt(..., "DES-CBC", ...)`，**DES-CBC、key、IV 均硬编码**；对称的 `aesPasswordEncode()` 在 `app/common.php:1071-1077`。入库侧 `app/admin/controller/ZjmfFinanceApiController.php:38,51` 用 `aesPasswordEncode(html_entity_decode(...))` 写 `password` 列。原文档「③ 资源线同一 `clients.api_password`」为误，已按上表更正。

**四路鉴权涉及的凭据表**（`public/install/thinkcmf.sql`）：

| 表/列 | 定义 | 说明 |
|---|---|---|
| `shd_zjmf_finance_api` | `:3529-3538`、`:5620`、`:5839-5844` | `hostname/username/password/type/is_resource/ticket_open/is_using` |
| `shd_api` | `:91-99` | `ApiCheck` 用：`id, username, password, ip(text), create_time, is_auto`；**无 `status`/`client_id`/`token` 列** |
| `shd_clients.api_password` | `:3525` | `VARCHAR(50) DEFAULT ''`，密钥密文 |
| `shd_clients.api_open` | `:5531` | `TINYINT(1) DEFAULT 1`（新装即开），业务态 0关/1开/2锁定 |

**开启链路**（三层开关）：

| 项 | 说明 | 位置 |
|---|---|---|
| 全局 | `configuration("allow_resource_api")`，`judgeApiIs()` 直接返回其真值 | `app/zjmf.php:10-13`；`app/openapi/controller/LoginController.php:33-35` |
| 全局·绑手机 | `allow_resource_api_phone` 开且 `clients.phonenumber` 空 → 拒 | `app/common.php:6303-6305` |
| 全局·实名 | `allow_resource_api_realname` 开且 `!checkCertify($uid)` → 拒 | `app/common.php:6306-6308`；`checkCertify()` `app/common.php:724` |
| 客户级 | `clients.api_open` 0/1/2 | `public/install/thinkcmf.sql:5531` |
| `judgeApi($uid)` 三层 | `configuration(...) && $api_open` 为真才继续（`api_open=2` 也为真值） | `app/common.php:6298-6312`（`:6302`） |
| 后台写入 | 读三值 / 写三值 / 写 `home_ip_check` | `app/admin/controller/ConfigGeneralController.php:1669`、`:1675-1678`、`:448-456` |

> ⚠️ **`api_open` 三个判定点口径不一致**，对接方以登录接口为准：
>
> | 位置 | 判定 | `api_open=2`（锁定）时 |
> |---|---|---|
> | `app/home/controller/LoginController.php:362` | `api_open != 1` → 拒 | 拒绝登录（正确） |
> | `app/common.php:6302`（`judgeApi`） | 真值 → 通过 | 通过 |
> | `app/home/controller/CartController.php:393-395` | `== 0` → 拒（**不是 `!=1`**，勘误 14） | 通过 |
>
> 即锁定态客户无法登录，但 `cart/summary` 等仍视作已开通（§7.2）。

**客户自助入口**（均落在 `data/route/home.php` 组 2 `:91-400` → `Check`，需强制登录）：

| 入口 | 路由行 | 行为 | 实现 |
|---|---|---|---|
| `get_api_pwd` | `:98` | `judgeApiIs` + `secondVerify` → 返回**裸字符串明文**（`aesPasswordDecode`） | `app/home/controller/UserController.php:227-240` |
| `modify_api_pwd` | `:99` | 空/含中文/不满足 `(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[\W_]).{8,32}` 均 400 | `app/home/controller/UserController.php:241-265`（正则 `:258`） |
| `auto_api_pwd` | `:100` | **无鉴权/二次验证、不落库**，仅返回 `randStrToPass(12,0)` | `app/home/controller/UserController.php:266-270` |
| 客户·重置/开关/概览 | `:269/:270/:271` | 重置按 `request()->uid` 覆盖随机 12 位 / 写 `api_open` / `judgeApi` 闸 | `app/home/controller/ZjmfFinanceApiController.php:18-23`、`:24-40`、`:41-77` |
| 后台·重置/切换锁定 | — | 按传入 `uid` 覆盖 / `api_open==2` 写 `lock_reason`/`api_lock_time` | `app/admin/controller/ZjmfFinanceApiController.php:332-337`、`:339-353` |

> `auto_api_pwd` 生成的 12 位串**不含特殊字符**，不满足 `modify_api_pwd` 强度正则，前端需加工后才能提交（`app/home/controller/UserController.php:266-270`、`:258`）。

### 1.2 两个登录入口（本站出站 + 本站入站）

#### ① `POST {host}/zjmf_api_login`（老版站际主线）

```http
POST https://upstream.example.com/zjmf_api_login
Content-Type: application/x-www-form-urlencoded

username=user@example.com&password=<API密钥明文>
```

| 参数 | 必填 | 说明 | 行号 |
|---|---|---|---|
| `username` | 是 | `require|length:4,20`；含 `@` 查 `clients.email`，否则查 `clients.phonenumber` | `app/home/controller/LoginController.php:345-346`、`:381-392` |
| `password` | 是 | API 密钥；服务端 `aesPasswordEncode($password) == clients.api_password` | `app/home/controller/LoginController.php:365`（旧文档标 `:358` 已更正） |

成功 `jsons({"jwt":…, "status":200, "msg":"鉴权成功"})`（`jsons()` 恒注入 `is_aff`，`app/common.php:2395-2402`）；失败 `{"status":400, "msg":"鉴权失败"}` / `"供应商api功能已关闭"` / `"无法访问"`。

**本站作为上游被下游调用时的入站实现**（`LoginController::zjmfApiLogin()`，`app/home/controller/LoginController.php:339-380`，路由 `data/route/home.php:420` → 组 3 `UserCheck`）：

| # | 条件 | 返回 | 行号 |
|---|---|---|---|
| 1 | `!judgeApiIs()`（`allow_resource_api` 关） | `400 无法访问` | `:341-343` |
| 2 | 非 POST | `400 ERROR MESSAGE` | `:344`、`:379` |
| 3 | 校验失败 | `400 鉴权失败` | `:348-350` |
| 4 | 客户不存在 | `400 鉴权失败` | `:354-356` |
| 5 | `login_get(get_client_ip(0,true))` 风控 | 透传 400 | `:357-361` |
| 6 | `api_open != 1`（含 2 锁定） | `400 供应商api功能已关闭` | `:362-364` |
| 7 | 密钥不匹配 | `400 鉴权失败` | `:365`、`:377` |
| 8 | 成功 | `{"jwt":createJwt($userinfo),"status":200,"msg":"鉴权成功"}` | `:375` |

成功副作用（入站侧应答契约）：写 `clients.lastlogin`/`lastloginip`（`:366-367`）；`$userinfo={id, username, is_api:1}`（`:368-370`）；`active_logs` ×2（`:371-372`）；`hook("client_api_login", {uid,name,ip})`（`:373`）。旧文档「前置校验 `:340-379`」更正为 `:339-380`。`login_get()` 只**读**计数不写（`app/home/model/ClientsModel.php:496-520`），API 登录自身不累加，但会被前台用户登录触发的封禁**连带拦截**；阈值 10 次/60s、20 次/1800s，封禁 600s / 2592000s。

#### ③ `POST {host}/resource_login`（资源/代理商，出站 path）

```http
POST https://upstream.example.com/resource_login
username=<资源商账号>&password=<密码>&type=agent
```

调用方固定带 `type=agent`（**`app/zjmf.php:194-196`**，旧文档标 `:196-201` 已更正）。完整响应体**未在代码中确认**：本地只判 `status==200` 并取 `$res["jwt"] ?? $res["data"]["jwt"]`（`app/zjmf.php:355`）。

| 维度 | `zjmf_api_login` | `resource_login` | 行号 |
|---|---|---|---|
| 触发 | `is_resource=0` | `is_resource==1`（优先级最高） | `app/zjmf.php:194` |
| 路径 | `/zjmf_api_login` | `/resource_login` | `app/zjmf.php:201` / `:195` |
| 参数 | `{username, password}` | `{username, password, type:"agent"}` | `app/zjmf.php:202` / `:196` |
| 入站凭据列 | `clients.api_password`（密文比对） | `clients.password`（明文相等） | `app/home/controller/LoginController.php:365` / `:413` |
| 前置开关 | `judgeApiIs()` + `api_open!=1` 双闸 | **无**任何开关判断 | `app/home/controller/LoginController.php:341,362` / `:393-424` |
| JWT | `createJwt($userinfo)` 默认 7200s | 同 | `app/common.php:47` |
| 副作用 | 更新 `lastlogin/lastloginip`、双份 `active_logs`、hook `client_api_login` | 更新 `clients.password`（`= $data["token"] ?: $password`）、`lastlogin/lastloginip`；**无日志/hook** | `app/home/controller/LoginController.php:366-373` / `:414-415` |

> ⚠️ **命名差异（勘误 7）**：本树**入站路由名是 `resource_login_supplier`**（`data/route/home.php:421` → `home/login/resourceLogin`，组 3 `UserCheck`），全树 `data/route/*.php` **无** `/resource_login` 路由；`/resource_login` 只作为**出站 path** 出现在 curl 字符串中，共 6 处：`app/zjmf.php:195`（`zjmfCurl`）、`:230`（`zjmfCurlHasFile`）、`app/admin/controller/AgentController.php:70`、`app/common.php:5538`（工单投递）、`app/admin/controller/PublicController.php:952`、`app/admin/controller/ZjmfFinanceApiController.php:231`。**是否有 rewrite/别名弥合两者，未在代码中确认**。
> `resourceLogin()` 入站实现 `app/home/controller/LoginController.php:393-424`（旧文档标 `:393-421` 已更正）；比对 `$password == $result["password"]`（`:413`），失败 `400 账号或密码错误`（`:421`）。成功时 `:414` 把 `$data["token"] ?: $password` **回写 `clients.password`**，属不可依赖的遗留行为。原文档「3.7.7 状态 / 新版已关停」跨树结论本地树无对应文件，已删除。

#### 附：客户 OpenAPI 入口 `POST /v1/login_api`（非站际，存目）

路由 `data/route/openapi.php:18` → `openapi/Login/loginAPI`（`app/openapi/controller/LoginController.php:28-66`），`Route::group("v1")` → `UserCheck`（`data/route/openapi.php:12-45`）。与 `zjmf_api_login` 的 7 项差异：入参名 `account`+`password`（`:39-41`）；直读 `allow_resource_api`（`:33-35`）、额外校验 `clients.status != 1`（`:52-54`）；凭据 `md5($param["password"]) != md5($api_password)`（`:58-60`）；JWT `createJwt($userinfo,7200)` 且 `userinfo` 仅 `{id,username}` **无 `is_api`**（`:62-64`）；响应 `json({jwt,status:200,msg:"login successful"})` **无 `is_aff`、全英文**（`:65`）；无 `lastlogin`/日志/hook。原文档「后续头一律取空格后第 1 段」与本地矛盾，见 §1.3 分域说明。

### 1.3 JWT 签发、校验与续期

| 项 | 规则 | 位置 |
|---|---|---|
| 出站请求头 | `Authorization: Bearer {jwt}` | `app/zjmf.php:208`（旧文档 `:210` 已更正，`:210` 是 `commonCurl` 调用） |
| 签发 | `createJwt($userinfo, $expire=7200)`；payload `{userinfo, iss, aud, ip, iat, nbf, exp}`，`Firebase\JWT\JWT::encode` 默认 **HS256** | `app/common.php:47-56` |
| 签名密钥 | `config("jwtkey")`，**硬编码于 `app/app.php:26`**（明文不写入本文） | `app/app.php:26` |
| 服务端白名单缓存 | `Cache::set("client_user_login_token_".$jwt, uid, $expire)`，TTL 同 7200s | `app/common.php:53-54`（旧文档 `:49-51` 已更正） |
| 调用方缓存 | `Cache::set("zjmf_finance_jwt_{id}", $jwt, 0)` —— `0`=永久（TP5 File 驱动判据 `vendor/topthink/framework/library/think/cache/driver/File.php:138`） | `app/zjmf.php:348-365`（`:356`） |
| 失效信号 | 业务 `status=405`（唯一重登触发点，只识别业务层 405） | `app/zjmf.php:211` |
| 重登策略 | 405 → `zjmfApiLogin(...,true)` 强制重登 → **刷新 header**（`:216`）→ 重放一次；仍 405 → `400 "API账号密码错误"` | `app/zjmf.php:211-222`（旧文档 `刷新 header :214` / 区间 `212-220` 已更正为 `:216` / `211-222`） |

稳态下 JWT 自身 7200s 过期后必然产生一次「405 → 重登 → 重放」额外往返（`app/zjmf.php:356`）。

**405 全部触发条件**（`Check::checkToken()`，`app/http/middleware/Check.php:96-130`）：

| 判据 | 返回 | 行号 |
|---|---|---|
| 无 `authorization` 头 | `405 请登陆后再试` | `:103-105` |
| 取到的段为空或字面量 `"null"` | 405 | `:107-109` |
| 按 `.` 分割 ≠ 3 段 | 405 | `:110-112` |
| 白名单缓存 `client_user_login_token_{jwt}` 不存在 | 405 | `:113-116` |
| `client_user_update_pass_{uid}` 存在且 `nbf < pass`（改密） | `405 密码已修改,请重新登陆` | `:118-121` |
| `home_ip_check=1` 且 IP ≠ 签发 IP | `405 登录失效,请重新登录` | `:122-125` |

`Check::handle()` 成功注入 `$request->uid/contactid/uname/is_api`（`app/http/middleware/Check.php:54-57`）；`verifyJwt()`（`:161-183`）用 `JWT::decode($jwt,$key,["HS256"])`，`userinfo` 非空构造 `status:1001`（`:168-169`），否则 `1002`（`:171`）。异常分支 `SignatureInvalidException`（`:174-176`）/ `ExpiredException`（`:177-179`）直接 `echo json_encode(...); exit;`，输出**裸 JSON**（无 `is_aff`），旧文档 `1002` 行号 `167-170/:174-177` 已更正为 `:171 / :174-176 / :177-179`。

> ⚠️ **解析坑（分域说明，勘误：禁止「一律取第 1 段」）**：
> - `app/http/middleware/Check.php:106,136`、`app/home/controller/UserController.php:1326` 均为 `list($authorization) = explode(" ", $header["authorization"])`，`list($x)` 取 **索引 0** → `Authorization: Bearer <jwt>` 被截成 `"Bearer"` → `:110` 按 `.` 分割不足 3 段 → 405。`zjmfCurl`（`app/zjmf.php:208`）发的正是 `Bearer` 前缀，放大稳态 405 重登。
> - `app/http/middleware/ApiCheck.php:26-31` 用 `count($token)>1 ? $token[1] : $token[0]`，**有前缀时取第 1 段**——四路鉴权中仅 `ApiCheck` 走标准 Bearer/Basic 解析。
> - `app/openapi/documents/json/Basice.php:10` 的示例前缀是 `JWT <jwt>`（注明「JWT 后面有个空格」）。干净版 `[1]`、跨树统计、外部参考路径均**未在代码中确认**，不予采信。
> - **对接建议**：按 `Bearer {jwt}` 发；遇 405 第一步改成**裸 jwt** 复测。

> ⚠️ **`UserCheck` 组不拦截未登录**：`data/route/home.php` 三个组——`:14-84`（组 1 `UserCheck`）、`:91-400`（组 2 `Check`，强制登录）、`:401-514`（组 3 **`UserCheck`**）。`UserCheck::handle()` 对 `parent::checkToken()` 结果**不判状态**（405 数组也 truthy 仍 `$next`），且不注入 `is_api`（`app/http/middleware/UserCheck.php:52-61`）。落在组 3 的 `zjmf_api_login`(:420)、`resource_login_supplier`(:421)、`cart/all`(:423)、`cart/get_product_config`(:424)、`cart/summary`(:425)、`cart/hostinfo`(:427)、`cart/credit`(:428)、`cart/stock_control`(:429)、`cart/add_to_shop`(:439) **不带 token 也能调**，但 `request()->uid=0` 返回空/零值。**对接建议**：务必带 token，且校验 `data` 而非只看 `status`。`app/middleware.php:10` 全局仅挂 `AppCheck`（CORS / 安装检查，不鉴权，`app/http/middleware/AppCheck.php:12-47`）。

### 1.4 四路鉴权对照（新增）

| 维度 | `Check` | `UserCheck` | `ApiCheck` | `AdminCheck` |
|---|---|---|---|---|
| 定义 | `app/http/middleware/Check.php:12` | `app/http/middleware/UserCheck.php:12`（`extends Check`） | `app/http/middleware/ApiCheck.php:12` | `app/http/middleware/AdminCheck.php:12` |
| 凭据 | JWT（`Authorization`） | JWT（复用 `parent::checkToken()`） | Basic base64(`username:password`) | session `ADMIN_ID` |
| 核心判据 | `checkToken()`（`Check.php:96-130`）+ 白名单缓存 | **不判状态、恒放行** | `shd_api` + `md5` + IP 白名单（`ApiCheck.php:41,47-53`） | `cmf_get_current_admin_id()`（`AdminCheck.php:34-37`） |
| 失败返回 | `405`（未登录）/ `401`（子账户 `:79,89`）/ `503`（维护 `:36`） | 不拦截，未登录仍 `$next` | `400` 系列（`:22,35,43,50`） | `405 您还没有登录`（`:36`） |
| 注入 `$request` | `uid/contactid/uname/is_api`（`:54-57`） | 仅 `uid/uname`（`UserCheck.php:54-55`） | 无 | 无 |
| 挂载 | `data/route/home.php:91-400`、`data/route/openapi.php:46-152` | `data/route/home.php:14-84`、`:401-514`、`data/route/openapi.php:12-45` | `data/route/api.php:13-17` | **未在代码中确认**（全项目仅命中类定义） |
| 典型接口 | `cart/settle`（`data/route/home.php:166`）、客户密钥 CRUD（`:98-100,269-271`） | `zjmf_api_login`(:420)、`cart/*`(:423-449)、`/v1/login_api` | `api/host`、`api/host/free`、`api/host_server` | 后台（推测，未确认） |

`ApiCheck::handle()` 逐行（`app/http/middleware/ApiCheck.php:17-55`）：取 `authorization`（`:20`）→ `explode(" ",$token)` Basic 变体（`:26-31`）→ `base64_decode` 按首个 `:` 切 `username/password`（`:32-40`）→ `\think\Db::name("api")->where("username",…)->where("password", md5($password))->find()`（`:41`，即 `shd_api`，密码以 `md5(明文)` 比对）→ IP 白名单 `$ip=explode(",",$api["ip"])` 必须 `in_array(get_client_ip())`（`:47-53`）→ 放行（`:54`）。挂载于 `data/route/api.php:13-17` 的 `Route::group("api")` 内 3 条路由；**`api/host/sync`（`data/route/api.php:18`）与 `api/ticket_reply/sync`（`:19`）不在该 group 内**，靠签名自验签（§1.5、§8.3）。

### 1.5 签名算法 `createSign()` / `validateSign()`

`createSign($params, $token)`（`app/zjmf.php:164-176`）：

| 步 | 动作 | 行号 |
|---|---|---|
| 1 | `$rand_str = randStr(6)`（`randStr()` 定义 `app/common.php:609`） | `:166` |
| 2 | `$params["token"]=$token`、`$params["rand_str"]=$rand_str` | `:167-168` |
| 3 | `ksort($params, SORT_STRING)` → `json_encode` → `strtoupper(md5($str))` | `:169-172` |
| 4 | 返回 `{"signature":$sign, "rand_str":$rand_str}`（**`token` 不发送**） | `:173-175` |

`validateSign($params, $sign)`（`app/zjmf.php:177-184`）：**只重组 3 个字段** `$data=["id"=>…, "token"=>…, "rand_str"=>…]`（`:179`）→ `ksort(SORT_STRING)` → `json_encode` → `strtoupper(md5($str)) === $sign`（**严格 `===`**，`:180-183`）。

| 侧 | 位置 | 上下文 |
|---|---|---|
| 出站生成 | `app/zjmf.php:112`（`pushTicketReply`）、`:144`（`pushHostInfo`） | `createSign(["id"=>…], $stream_info["downstream_token"])`，`array_merge($post_data,$sign)` 并入 POST 体（`:115,151`） |
| 入站校验 | `app/api/controller/HostController.php:154,159`（`syncInfo` 定义 `:133`）、`:283,288`（`syncTicketReply` 定义 `:263`） | 失败均 `400 签名验证失败`；两支验签恒等（见 §8.3） |

> **覆盖字段不对称**：生成侧覆盖 `$params` 全部键 + `token` + `rand_str`；校验侧**仅** `id/token/rand_str`。两处能对齐的前提是调用方只传 `["id"=>…]`（`app/zjmf.php:112,144` 均如此）；若生成时 `$params` 含额外键，签名必不匹配。`token` 取自 `host.stream_info.downstream_token`（`app/zjmf.php:95,135`）或入站侧 `stream_info["token"]`（`app/api/controller/HostController.php:144,274`）。
> **无防重放**（`app/zjmf.php:164-184` 全文无 Cache/DB/time 调用）：`rand_str` 仅作随机扰动，**无 nonce 存储、无去重、无时间戳、无时效校验** ⇒ 同一 `signature`+`rand_str`+`id`+`token` 可无限重放。接收端是否另挂限频中间件**未在代码中确认**。

---

## 2. 传输与响应约定

### 2.1 编码（`commonCurl()`，`app/zjmf.php:366-435`）

`commonCurl($url, $data=[], $timeout=30, $request="POST", $header=[])`（`:366`），`strtoupper($request)`（`:369`）。

| 方法 | 编码 | 位置 |
|---|---|---|
| GET | 空值补 `""`（`:373-377`）→ `http_build_query`（`:378`）→ `?` 拼 URL（`:380-383`）；设 `RETURNTRANSFER=1`、`HTTPGET=1` | `app/zjmf.php:370-383`（旧文档/分册A `370-382`、清单 `373-381` 已统一） |
| POST | `CURLOPT_POST=1`，数组用 `http_build_query($data)`（**表单编码，非 JSON**，`:402`），非数组原样（`:404`） | `app/zjmf.php:398-406`（旧文档 `404-421` 已更正） |
| PUT / DELETE | `CURLOPT_CUSTOMREQUEST=$request` + `POSTFIELDS`（同样 `http_build_query`） | `app/zjmf.php:407-415` |
| 带附件 | `zjmfCurlHasFile` → `curlHasFile`，`multipart/form-data` | `app/zjmf.php:225-255`、`:264`（旧文档 `:268` 已更正） |

通用：`CURLOPT_TIMEOUT=$timeout`（**`app/zjmf.php:387`，单位「秒」，默认 30**；旧文档标 `:388` / 30000ms 已更正）、`USERAGENT`「Mozilla/5.0 (compatible; MSIE 5.01; Windows NT 5.0)」（`:388`）、`FOLLOWLOCATION=1`（`:389`）、`HEADER=0`（`:390`）、`REFERER=request()->host()`（`:391`）、**`SSL_VERIFYPEER=false`/`SSL_VERIFYHOST=false` 恒定关闭**（`:392-393`）、`$header` 非空才设 `CURLOPT_HTTPHEADER`（`:416-418`）。

> 对接本系统当上游时**不要发 JSON body**，按 `application/x-www-form-urlencoded` 发；反之本站调对端也按表单。
> 另有独立封装 `postRequest()`/`getRequest()`（`app/common.php:831-880`）：`POSTFIELDS` **原样不编码**、默认超时 **10s**、**返回原始字符串不做 `json_decode`、不构造 `status` 信封**，且不在 `zjmfCurl` 调用链上，勿与 `commonCurl` 混用。

### 2.2 响应结构与 `status` 码

统一信封（HTTP 恒 200，业务状态在 body）：`{ "status": 200, "msg": "success", "data": { } }`。三种返回封装均为 `think\Response::create($data,"json",$code=200,…)`，差异仅在自动注入字段（`app/common.php:2382-2413`）：

| 函数 | 注入 | 行号 | 典型触发 |
|---|---|---|---|
| `jsonrule()` | `identify`/`action`/`is_sale`/`per_page_limit`（默认 50） | `:2382-2394`（注入 `:2389-2392`） | 后台 `admin/*`：`createApi`（`ZjmfFinanceApiController.php:65`） |
| `jsons()` | `is_aff = configuration("affiliate_enabled")` | `:2395-2402`（注入 `:2400`） | 前台 `home/*`：`cart/*`、`LoginController.php:375,419` |
| `json()` | 无 | `:2403-2409` | `api/product/*`（`ProductController.php:54`）、`clearCart`、`checkOrder` |
| `jsonseparate()` | 无 | `:2410-2413` | 未在本域确认调用点 |

| `status` | 含义 | 典型触发 |
|---|---|---|
| `200` | 业务成功 | 全树，如 `LoginController.php:375` |
| `400` | 通用业务失败 | `LoginController.php:342,349,355,363,377`、`app/zjmf.php:189,219-220,422,429`、`ApiCheck.php:22,35,43,50` |
| `405` | 未登录 / token 失效 / 改密 / IP 变化 | **仅** `Check::checkToken()`（`Check.php:104,108,111,115,120,124,129`）与 `ExpiredException`（`:178`）；是 `zjmfCurl` 唯一重登信号 |
| `406` | 表单/业务前置校验失败，与 400 无技术区分 | `app/home/controller/CartController.php:2673,2863,2945,2998,3006,3013`；`app/api/controller/HostController.php:294` |
| `401` | 子账户 `contacts` 权限不足 | `Check.php:79,89` |
| `503` | 维护模式 | `Check.php:36` |
| `1001` | **三义**（见下表，必须按接口 + msg 联合判断） | — |
| `1002` | JWT 无效 / `userinfo` 为空 | `Check.php:171,175` |

> **勘误 6：`status=1001` 是三义（非双义）**。分册A 旧「内部标记，不对外」必须改写，否则误导下游把开通成功判成失败。统一口径「**按接口 + msg 联合判断**」：
>
> | # | 语义 | 触发 | 行号 |
> |---|---|---|---|
> | ① | 老版「金额 0 / 已付清、开通完成」（**对外业务状态**） | `cart/settle` `total==0`、`/apply_credit` 全额付清、`/apply_credit_limit` 成功；下游把上游 `1001` 改判 `200` | `app/home/controller/CartController.php:2644`；`app/home/controller/PayController.php:323`、`:420`；`app/common/logic/Host.php:97`、`:151`、`:175` |
> | ② | 鉴权「Token 验证通过」（内部） | `Check::verifyJwt()` `userinfo` 非空 | `app/http/middleware/Check.php:169` |
> | ③ | 支付查询「支付失败」（`1000`=成功） | `OrderController::checkOrder()` | `app/home/controller/OrderController.php:146-148` |

> ⚠️ `400` 与 `406` 无技术区分（各控制器手写默认值），不要依赖 `406` 判断「参数错误」。`verifyJwt` 的签名无效/过期分支直接 `echo`+`exit`（`Check.php:174-179`），绕过全局异常处理，客户端拿到**裸 JSON**。`catch (Exception $e)`（`:180-181`）在 `namespace app\http\middleware` 下未加 `\` 前缀，其能否捕获通用 `\Exception` **未在代码中确认**。
> HTTP 层：只有 HTTP 200 才 `json_decode($content,true)` 原样返回（`app/zjmf.php:426-427`）；非 200 自造 `{status:400, msg, http_code, content}`（`:428-433`）；`curl_error` 非空 → `{status:400, msg:"CURL ERROR:".$error}`（`:420-423`）。

### 2.3 分页与搜索参数

| 参数 | 类型 | 说明 | 上限 |
|---|---|---|---|
| `page` | int | 页码，从 1 开始 | 全局默认 `app/app.php:26` `"page"=>1` |
| `limit` | int | 每页条数 | 全局默认 `"limit"=>10`；`jsonrule` 注入 `per_page_limit`（`app/common.php:2392`，缺省 50），**无统一分页包装、无硬上限** |
| `orderby` | string | 排序字段，非法值回落 `id` | — |
| `sort` | string | `asc`/`desc`，非法值回落 `DESC` | — |
| `keywords` | string | 模糊搜索 | — |

`data/route/home.php:11` 的 `page/limit` pattern（`\d+`、`max:50` 字面分支）因无 `:limit` 占位符**不生效**。`app/openapi/documents/json/Basice.php:10` 的 `pages` 段列 `page/limit/orderby/sort/keywords`，`result.code` **只列 200/400**。实现抽样 `app/home/controller/ZjmfFinanceApiController.php:98-101`：`page`/`limit`/`order="a.id"`/`sort="DESC"` 默认，`:173` `->page()->limit()`。多处 `$max_page = ceil($count / $page)`（把 `$page` 当 `limit`）是**错误**，请用 `count` 自行计算总页数。

### 2.4 超时

全链路默认 `timeout=30`（**秒**，`app/zjmf.php:185`、`:387`）；探活类 5–15s（`app/home/controller/CartController.php:1787` 的 `cart/ontrialmax` 用 5s、`app/admin/controller/ZjmfFinanceApiController.php:40` 建号探活）。`commonCurl` 只设 `CURLOPT_TIMEOUT`，**无 connect/read 分离**，无 5xx/超时自动重试（非 200 HTTP 直接转 `400`，`app/zjmf.php:426-433`）。

---

## 3. 商品目录（出站 + 入站双向）

### 3.1 出站六个函数总表（`app/zjmf.php`）

| # | 函数 | 定义 | path | 方法 | 入参 | 超时 | 返回处理 | 失败 | 调用方 |
|---|---|---|---|---|---|---|---|---|---|
| 1 | `getZjmfUpstreamProducts($id)` | `app/zjmf.php:38-53` | 三分支见 §3.2 | GET | `[]` | 30 | `status==200` 取整个 `data`，否则 `[]` | 静默空数组、不抛错、不带 msg | `app/admin/controller/ProductController.php:828`；`app/common/model/ProductModel.php:221,522` |
| 2 | `getZjmfUpstreamProductsInfo($id,$pids=[],$timeout=10)` | `app/zjmf.php:54-59` | `api/product/proinfo` | GET | `["pids"=>$pids]` | 10 | **原样返回** `$result`，不判 status | 交调用方 | `app/common/logic/Product.php:143,211` |
| 3 | `getZjmfUpstreamProductsDetail($id,$pids=[],$timeout=10)` | `app/zjmf.php:60-65` | `api/product/prodetail` | GET | `["pids"=>$pids]` | 10 | 原样返回 `$result` | 交调用方 | `app/common/logic/Product.php:166,251`；`app/admin/controller/ProductController.php:1450` |
| 4 | `getResourceRate($id,$pid)` | `app/zjmf.php:66-74` | `resource/rate` | GET | `["pid"=>$pid]` | 30 | `status==200` 取 `data`，否则 `[]` | 静默空数组 | `app/common/model/ProductModel.php:217,518` |
| 5 | `getZjmfUpstreamProductConfig($id,$pid)` | `app/zjmf.php:75-80` | `cart/get_product_config` | GET | `["pid"=>$pid]` | 30 | 原样返回 `$result` | 交调用方 | `app/admin/controller/ProductController.php:1015,2170,2567`；`app/admin/controller/ConfigOptionsController.php:157,779`；`app/admin/command/Cron.php:1257`；`app/common/model/ProductModel.php:237,538` |
| 6 | `getResourceProducts()` | **未在代码中确认**（全项目无 `function getResourceProducts`） | `resource/products` | — | — | — | 该串只是 `app/zjmf.php:42` 的 **path 常量** | — | — |

> **勘误 3**：`getResourceProducts()` **函数不存在**；`resource/products` 只是 `getZjmfUpstreamProducts()` 在 `is_resource` 分支的 path（`app/zjmf.php:38-47`）。
> **§6.3 冲突口径**：`resource/products`（`getZjmfUpstreamProducts()` 用，`app/zjmf.php:42`）与 `resource/agentproductsarray`（`AgentController::getProducts()` 用，`app/admin/controller/AgentController.php:109`）是**两个不同接口**，并列写明，不是冲突。
> **未在代码中确认**：`resource/products`、`resource/rate` 在本树 `data/route/` 全目录**无路由注册**（`data/route/home.php:421` 只有 `resource_login_supplier`），二者是本站作为客户端发往**对端上游站**的 path，本树不提供。

### 3.2 出站三分支与老版 `cart/all`

`getZjmfUpstreamProducts()` 逐行：`app/zjmf.php:40` 读 `zjmf_finance_api where id` → `:41-42` `if($api["is_resource"]) → resource/products` → `:43-44` 历史版本分支（已收敛，见附录 Z）→ `:45-46` `else → cart/all` → `:48` `zjmfCurl($id,$path,[],30,"GET")` → `:49-50` `status==200` 取 `data` → `:52` 否则 `[]`。优先级：`is_resource`（真值判断）最高；登录分流处是 `is_resource==1`（`app/zjmf.php:194`），取值域同为 0/1。

**老版 `cart/all` 请求与返回解析**：

| 项 | 值 | 依据 |
|---|---|---|
| 下游发起 | `GET {hostname}/cart/all`，无 query 参数 | `app/zjmf.php:46-48` |
| 上游实现（本站入站） | `app/home/controller/CartController.php:443-458` | 路由 `data/route/home.php:423` |
| 过滤 | `products` 表 `hidden=0 AND retired=0 AND type NOT IN (developer_app_product_type 键)` | `app/home/controller/CartController.php:448-449` |
| 分组 | `product_groups` 取 `id,name`（`hidden=0`），每组挂 `products` 的 `id,type,name,description` | `app/home/controller/CartController.php:450-454` |
| 币种 | `currencies.default=1` 的 `code` | `app/home/controller/CartController.php:455` |
| 返回体 | `jsons(["status"=>200,"msg"=>…,"data"=>["products"=>分组数组,"count"=>总数,"currency"=>code]])` | `app/home/controller/CartController.php:456-457` |
| 下游解析 | 取 `$list["products"]`、`["currency"]`（`count` 下游未用） | `app/admin/controller/ProductController.php:828-837`；`app/common/model/ProductModel.php:221-233` |
| 审计 | 每次写 `api_resource_log`（依赖 `request()->uid`），desc「客户User ID:X…调取cart/all接口获取产品数据」 | `app/home/controller/CartController.php:445-447`；`app/zjmf.php:479-484` |

```json
{ "status":200, "msg":"success",
 "data": { "products":[ { "id":3, "name":"云服务器",
 "products":[ { "id":12, "type":"dcimcloud", "name":"香港CN2", "description":"..." } ] } ],
 "count":47, "currency":"CNY" } }
```

> **勘误 5（30 天缓存核实）**：老版 `cart/all` **实时直查 DB、无缓存**（`app/home/controller/CartController.php:449-456`）。`2592000` 在代码中只用于封禁/cookie/统计窗口/授权周期（`app/home/model/ClientsModel.php:22`、`app/common.php:2435`、`app/common/logic/Shop.php:82`、`app/admin/controller/IndexController.php:608`），**不存在** `cache(...,2592000)` 形式的商品目录缓存；`idcsmart_cache`、`product:list` 在 `app/` 全树**未在代码中确认**。老版唯一带 TTL 的缓存是汇率 `shd_cron_currency_rate` **86400 秒**（`app/common/logic/Product.php:116-125`、`:122`；该时长标「未在代码中确认」需要复核）。其余 `shd_all_products_info`/`_detail_<pid>`/`_list` 均 `cache(键,值)` **不传 TTL**，由 `updateCache()`/`deleteXCache()` 主动失效重建（`app/common/logic/Product.php:62-115`、`:834-864`），与 JWT 缓存 `Cache::set(...,0)` 是两套机制。

**目录接口决策表**（老版三列）：

| 维度 | 出站 `GET /cart/all` | 入站 `GET api/product/proinfo` | 入站 `GET api/product/prodetail` |
|---|---|---|---|
| 方向 | 出站（本站=下游） | 入站（本站=上游） | 入站（本站=上游） |
| 返回 | `data.products[]` 分组树 | `data.info[]{id,name,location_version,stock_control,qty}` + `data.currency` | `data.detail{"<pid>":{...}}` |
| 商品 `type` | ✅ 有 | ❌ 无 | ❌ 无 |
| `count` | ✅ `data.count` | ❌ 无 | ❌ 无 |
| 币种键 | `data.currency` | `data.currency` | 随 `detail` |
| 缓存 | **实时、无 TTL** | `shd_all_products_info`（无 TTL，主动重建） | `shd_all_products_detail_<pid>`（无 TTL） |
| 实现 | `app/home/controller/CartController.php:443-458` | `app/api/controller/ProductController.php:14-55` | `app/api/controller/ProductController.php:56-84` |

### 3.3 入站：本站作为上游提供目录

**`app/api/controller/ProductController.php`（`data/route/api.php`）**：

| 接口 | 方法 / 定义 | 路由行 | 入参 | 返回 `data` | 中间件 |
|---|---|---|---|---|---|
| `GET api/product/proinfo` | `proInfo()` `:14-55` | `data/route/api.php:44` | `pids`（标量/数组，空=全量）`:16-25` | `info[]{id,name,location_version,stock_control,qty}`、`currency`（`currencies.default=1.code`）`:52-54` | 未挂分组 |
| `GET api/product/prodetail` | `proDetail()` `:56-84` | `data/route/api.php:45` | `pids`（空=全站 id）`:59-67` | `detail{"<pid>":{...}}` `:82-83` | 未挂分组 |
| `GET api/product/list` | `proList()` `:85-287` | `data/route/api.php:46` | `uid`（`request()->uid`）`:92` | `list[]`、`currency_code` `:285-286` | 未挂分组 |
| `GET api/product/:id` | `detail()` `:288-492` | `data/route/api.php:47` | 路由 `:id` | `product{...}`、`customfields` `:490-491` | `UserCheck` + `allowCrossDomain()` |
| `GET api/product/:id/resource` | `downloadResource()` `:698-704` | `data/route/api.php:48` | `:id` | 转 `Provision::downloadResource()`，`{module,url,version:"1.0.0"}`，`msg="模块文件不存在"`（`app/common/logic/Provision.php:1013-1026`） | 未挂分组 |
| `GET api/product/upgrade_product` | `getUpgradeProduct()` `:493-697` | `data/route/api.php:49` | `id`→`product_upgrade_products.upgrade_product_id`，`type IN (dcim,dcimcloud)` `:496-501` | `list[]` `:695-696` | 未挂分组 |

`Route::group("api")->middleware("ApiCheck")` 只包 3 个 host 路由（`data/route/api.php:13-17`），**上述 6 个商品路由均在组外**；仅 `api/product/:id` 显式挂 `UserCheck`。`proDetail()` 并发上限 `$logic->concurrent=500`，超限 `400 商品数量过多,请分批请求,最大请求数量为500个`（`app/common/logic/Product.php:17`、`app/api/controller/ProductController.php:69-72`）。`proList()/detail()/getUpgradeProduct()` 三处重复同一套 pricing→billingcycle 判定与 `bcadd/bcmul` 算价（`ProductController.php:96-283`、`:296-480`、`:507-694`），`recurring` 输出改写为 `recurring_prepayment`（`:271-276`、`:473-478`、`:682-687`）。

**`app/home/controller/CartController.php`（`data/route/home.php`，组 3 `UserCheck`）**：

| 接口 | 方法 / 定义 | 路由行 | 入参 | 返回 `data` |
|---|---|---|---|---|
| `GET cart/all` | `getProducts()` `:443-458` | `:423` | 无（仅 `request()->uid` 审计） | `products`(分组)、`count`、`currency` `:456` |
| `GET cart/get_product_config` | `getProductConfig()` `:459-504` | `:424` | `pid` `:462-463` | 见 §4.1 |
| `GET cart/summary` | `summary()` `:389-425` | `:425` | `uid`；需 `allow_resource_api=1` 且 `api_open!=0`，否则 `400 暂未开通API功能` `:392-395` | 见 §7.2 |
| `GET cart/stock_control` | `getQty()` `:336-343` | `:429` | `pid` | `product{qty,stock_control,hidden}` `:340-342` |
| `GET cart/ontrialmax` | `ontrialAndMax()` `:376-388` | `:426` | `pid` | `api_type=resource` 时整行；否则 `api_user_product.{ontrial,qty}` `:381-387` |
| `GET cart/credit` / `GET cart/hostinfo` | `:328-335` / `:344-375` | `:428` / `:427` | — | 见 §7.1 / §6.1 |
| `POST cart/productsgroups` | `postProductGroups()` `:68-95` | `:432` | 无 | 上游分组树（默认分组兜底）`:87-94` |
| `POST cart/createproducts` | `postCreateProducts()` `:96-327` | `:431` | 见 §5.8 | `status,msg,rate`（`rate` 在**顶层**非 `data`）`:326` |
| `POST cart/resource_product` | **方法未定义**（勘误 8，见 §5.8） | `:430` | — | — |

**路由分组与响应包装**：`data/route/api.php:13-17`（`ApiCheck`）/ `data/route/home.php:14-84`（组 1 `UserCheck`）/ `:91-400`（组 2 `Check`）/ `:401-514`（组 3 **`UserCheck`**，含 `cart/*` 全部老版 API `:423-450`、`:480`）。`jsons()`（`app/common.php:2395-2402`）注入顶层 `is_aff` 后输出，**所有 `cart/*` 用它**；`json()`（`:2403-2409`）不注入，**`api/product/*` 用它**（`app/api/controller/ProductController.php:54`）。下游 `commonCurl()` 对返回 `json_decode(...,true)`（`app/zjmf.php:426-427`），`is_aff` 被忽略，不影响解析。

> **勘误 16（`cart/stock_control` 两个行号）**：`app/home/controller/CartController.php:2812` 是本站**作为下游**向上游查库存的出站调用（`zjmfCurl("cart/stock_control",…)`，`:2811-2825`，`hidden=1`→本地下架 `:2818-2820`、`stock_control&&qty<=0`→`400 库存不足` `:2822-2823`）；`getQty()` `:336-343` 是本站**作为上游被调**的实现。旧文档混为一处，已分开。

### 3.4 下游拉取上游的返回解析

| 接口 | 下游怎么解析 | 依据 |
|---|---|---|
| `proinfo` | `$info=$res["data"]["info"][0]`；`$upstream_currency=$res["data"]["currency"]`；字段 `id,name,location_version,stock_control,qty` | `app/common/logic/Product.php:143-153`、`:91` |
| `proinfo`（定时） | `getZjmfUpstreamProductsInfo($id)` **不传 pids**（默认 `[]`=全量版本），按 `location_version` 比对本地 `upstream_version` | `app/common/logic/Product.php:211-231` |
| `prodetail` | `$res["data"]["detail"][$upstream_pid]`；空 → `400 同步失败,供应商…商品已删除` | `app/common/logic/Product.php:166-175`、`:251-253` |
| `resource/rate` | `$rate = $res["rate"] ? floatval($res["rate"]) : 1` | `app/common/model/ProductModel.php:216-219`、`:518` |
| `cart/get_product_config` | `$res["data"]["products"]`/`["flag"]`/`["product_pricings"]`/`["config_groups"]` | `app/admin/controller/ProductController.php:2569-2571`；`app/common/model/ProductModel.php:239-240`；`app/admin/command/Cron.php:1258` |
| 失败文案 | 非 200 →「【供应商名】无法链接,本地数据可能与上游数据不一致!」+ `active_log_final` | `app/common/logic/Product.php:144-148`、`:167-170` |

---

## 4. 配置与定价

### 4.1 `GET /cart/get_product_config?pid=` 完整返回结构（老版核心大接口）

实现 `app/home/controller/CartController.php:459-504`；路由 `data/route/home.php:424`；下游封装 `app/zjmf.php:75-80`。对接方拿「能不能买、怎么配、多少钱」全靠它。

**处理流程逐行**：

| 行 | 动作 |
|---|---|
| `:461-471` | `$pid=intval($params["pid"])`、`$uid=request()->uid`；`$flag=getSaleProductUser($pid,$uid)`→`data.flag`（可为 `false`）；查 `products` 整行，空则 `400 CART_PRO_CONF_NOTFOUND` |
| `:472-475` | `apiResourceLog($uid,$desc,$pid,$product["location_version"])` 写审计（`:473`）；**`unset($product["upstream_product_shopping_url"])`（唯一剔除字段，`:474`，勘误 18）**；`data.products`=**整行** |
| `:476-479` | `data.customfields`（`customfields` 表）；`data.product_pricings`（`pricing` LEFT JOIN `currencies`，取 `a.*,b.code`） |
| `:480-495` | `data.config_groups`：组 → `options[]` → `sub[]` → `pricings[]` 四层 |
| `:496-502` | `data.advanced`（`sub_id` 做 `json_decode`）；`data.config_links = array_column($config_links_data,"gid")`——**纯 gid 数组**、不是对象 |
| `:503` | `jsons(["status"=>200,"msg"=>SUCCESS MESSAGE,"data"=>$data])`（另注入 `is_aff`，`app/common.php:2395-2402`） |

**`data` 顶层键**：

| 键 | 类型 | 含义 | 来源表 | 依据 |
|---|---|---|---|---|
| `flag` | object/`false` | 客户等级折扣 `{type,bates}`，`type=1` 百分比 / `2` 固定额 | `getSaleProductUser()`（`user_product_bates` 系） | `CartController.php:465-466` |
| `products` | object | 商品整行；**周期/库存/限购/加价配置全在这里，没有独立节点** | `shd_products` | `CartController.php:475` |
| `customfields[]` | array | 自定义字段 `id,fieldname,description,fieldtype,fieldoptions,regexpr,required,showorder,sortorder,showdetail` | `shd_customfields` | `CartController.php:476-477` |
| `product_pricings[]` | array | 商品级各周期价 + 初装费（`a.*,b.code`） | `shd_pricing` JOIN `shd_currencies` | `CartController.php:478-479` |
| `config_groups[]` | array | 可配置项组树 `{id,name,description,options[]}` | `shd_product_config_groups/_links/_options/_options_sub`、`shd_pricing` | `CartController.php:480-495` |
| `advanced[]` | array | 配置联动规则 `{id,config_id,sub_id(map),relation,type,relation_id,upstream_id}` | `shd_product_config_options_links` | `CartController.php:496-500` |
| `config_links` | array\<int\> | 绑定的配置组 gid 列表（**纯数组**） | `shd_product_config_links` | `CartController.php:502` |

**`data.products` 里的周期 / 价格 / 库存 / 限购 / 加价字段**（字段定义见 `public/install/thinkcmf.sql`）：

| 字段（组） | 含义 | 位置 |
|---|---|---|
| `id,type,gid,name,description` | 商品 ID/类型/组/名/描述；`type` 字典见 `app/app.php:26` | `thinkcmf.sql:1556-1560` |
| `hidden,retired,order,is_featured,tax` | 上下架/排序/推荐/税（`cart/all` 用 `hidden=0 AND retired=0` 过滤） | `thinkcmf.sql:1561`、`:1610`、`:1614-1616` |
| **`pay_type`（JSON）** | `pay_type`(free/onetime/recurring/hour/day) + `pay_ontrial_status/cycle/cycle_type` + `clientscount_rule`；周期是否可售由它 + pricing `-1` 共同决定 | `thinkcmf.sql:1569`；`app/common/logic/Product.php:292-312` |
| `stock_control,qty,allow_qty` | 库存开关/库存量/限购（同步落地时强制 `stock_control=0,qty=0`） | `thinkcmf.sql:1564-1565`、`:1571`；`app/common/logic/Product.php:300` |
| `api_type,upstream_pid,zjmf_api_id` | 上游形态/上游商品 ID/上游 API 账号 ID（**商品字段，勿与 API 记录 `type` 混**） | `thinkcmf.sql:3526`、`:3547`、`:3546` |
| `upstream_version,location_version` | 上游版本/本地版本 | `thinkcmf.sql:3542-3543` |
| `upstream_price_type,upstream_price_value` | `percent`/`custom`，默认 `percent`/`120.00` | `thinkcmf.sql:3544-3545` |
| `upstream_stock_control,upstream_qty,upstream_auto_setup,upstream_ontrial_status` | 上游库存/开通方式/试用镜像 | `thinkcmf.sql:5870`、`:5559`、`:5895`、`:5897` |
| `rate,server_group,groupid` | 相对上游汇率/服务器组(=`zjmf_api_id`)/分组 | `thinkcmf.sql:3945`、`:4795`、`:3498` |
| `config_options_upgrade,down_configoption_refund,auto_setup,auto_terminate_days` | 升降级与开通配置 | `thinkcmf.sql:1602`、`:1572`、`:1600`、`:2237` |
| `upstream_product_shopping_url` | **被 `getProductConfig()` 唯一 `unset` 剔除**（`CartController.php:474`），不返回 | `thinkcmf.sql:5560` |

**`product_pricings[]` 与 `config_groups[].options[].sub[].pricings[]`**：两者字段集相同，`shd_pricing` 的 `a.*` 加 `currencies.code`（`CartController.php:478`、`:488`）；商品级过滤 `a.type="product" AND a.relid=pid AND b.default=1`，子选项级 `type="configoptions" AND relid=sub.id AND b.default=1`。`shd_pricing` 共 **38 列**（`public/install/thinkcmf.sql:1370-1408`）：`id,type,currency,relid` + **17 初装费** `osetupfee…tensetupfee`（`:1375-1391`）+ **17 周期价** `onetime…tenly`（`:1392-1408`），即「**17 对（价格 + 初装费）+ 4 元数据列**」；`-1` 表示该周期不售（代码体现为 `0 <= $pricing[周期]` 判定，如 `app/api/controller/ProductController.php:99,124,136`）。`config_groups[]` 每项 `{id,name,description,options[]}`（`:480,494`）；`options[]`←`product_config_options where gid=组id and hidden=0`（`:484`）；`sub[]`←`product_config_options_sub where config_id=option.id and hidden=0`（`:486`）；`sub[].pricings[]`（`:488-491`）。`advanced[]`←`product_config_options_links whereIn config_id (去重 option ids) order id asc`，`sub_id` 由 JSON 解码为 map（`:496-500`），落地反写见 `app/common/logic/Product.php:483-511`。

**下游消费方解析**：

| 消费方 | 取用字段 | 依据 |
|---|---|---|
| `getUpstreamPrice()` | `data.products`/`flag`/`product_pricings` → 逐 `price_type` 配对调 `upstreamBates()`；回传 `data{flag,product_pricing,config_groups}` | `app/admin/controller/ProductController.php:2567-2595` |
| `ProductModel::syncProductInfo()` | `data.products`（比对 `location_version`）、整包 `data` 交 `baseUpdateProduct()` | `app/common/model/ProductModel.php:237-249` |
| 定时任务 | `data.products.location_version` 变化才触发同步 | `app/admin/command/Cron.php:1257-1262` |
| `ConfigOptionsController` | 拉上游配置项做本地编辑 | `app/admin/controller/ConfigOptionsController.php:157`、`:779` |

### 4.2 库存与限购

| 接口 | 方向 / 参数 | 返回 | 用途 | 行号 |
|---|---|---|---|---|
| `GET /cart/stock_control` | 出站（本站=下游）`pid=upstream_pid`，30s | `data.product{hidden,stock_control,qty}` | `hidden=1`→本地下架、`stock_control&&qty<=0`→`400 库存不足`（勘误 16 出站侧） | `app/home/controller/CartController.php:2812`、`:2811-2825`、`:2818-2823` |
| `GET cart/stock_control` | 入站（本站=上游）`pid` | `data.product{qty,stock_control,hidden}` | `getQty()` 被调实现 | `app/home/controller/CartController.php:336-343` |
| `GET cart/ontrialmax` | 入站 `pid` | `api_type=resource` 整行 / 否则 `api_user_product.{ontrial,qty}` | 试用数量 / 最大购买量 | `app/home/controller/CartController.php:376-388` |
| `GET cart/ontrialmax` | 出站（本站=下游）`pid=upstream_pid`，**5s** | 试用/限购数据 | 探活类 | `app/home/controller/CartController.php:1787`、`:2782` |

### 4.3 上游加价 `upstreamBates()`

定义 `app/zjmf.php:436-478`：`upstreamBates($flag,$price,$setup,$end=false)` → `[$price,$setup,$bates]`。

| 分支 | 条件 | 计算 | 行 |
|---|---|---|---|
| A | `$price<=0`（只处理初装费），`type==1` | `$bates=flag.bates/100`；`$setup=$bates*$setup` | `app/zjmf.php:439-443` |
| A | `type!=1` 且 `!$end` | `$cp=$setup; $setup=$setup-$bates; if($setup<0){$setup=0;$bates=$cp;}` | `app/zjmf.php:445-454` |
| B | `$price>0` 且 `type==1` | `$bates=flag.bates/100`；`$price=$bates*$price`；`$setup=$bates*$setup` | `app/zjmf.php:458-461` |
| B | `type!=1` 且 `!$end` | `$price=$price-$bates`；若 `$price<0` → `$setup=$setup+$price`（负价冲减初装费）、`$setup<0` 归 0、`$price=0` | `app/zjmf.php:463-474` |
| — | `$end==true` | **不做加减**，只保留 `$bates` | `app/zjmf.php:446-447`、`:464-465` |
| 返回 | — | `[$price,$setup,$bates]`；`getUpstreamPrice()` `list($p,$s)=$new` **只取前两返回值**，第三个 `$bates` 丢弃 | `app/zjmf.php:477`；`app/admin/controller/ProductController.php:2579,2587` |

**计算顺序**：`getUpstreamPrice()` 先判上游也是 `zjmf_api` 且 `upstream_price_type=percent`，对每个 `price_type` 配对先 `* upstream_price_value/100`（`app/admin/controller/ProductController.php:2572-2577`、`:2583-2585`），**再**调 `upstreamBates()`（`:2578`、`:2586`）。涉及字段：`products.upstream_price_type`（percent/custom 互斥）、`upstream_price_value`（默认 120）、`flag.type/bates`、`config("price_type")` 17 组配对。

> **勘误 4（`upstreamBates()` 无除零风险）**：`upstreamBates()` **全程无 `bcdiv`、无 `round`/`number_format`/`bc*` 精度处理**，只有原生浮点 `/100`、`*`、`-`（`app/zjmf.php:442,460,466`），**除数恒为 100、无除零风险**；唯一风险是 `$price-$bates` 变负，代码已用 `<0` 归零（`:467-473`）。真正的 `bcdiv` 除零在**同一链路的其它位置**：
>
> | 位置 | 表达式 | 风险 |
> |---|---|---|
> | `app/common/logic/Product.php:160`、`:217` | `bcdiv($currency_arr[$local],$currency_arr[$upstream],20)` | 上游币种不在汇率数组时除数为 0 |
> | `app/common/model/ProductModel.php:229`；`app/home/controller/CartController.php:108` | `bcdiv($arr[$currency],$arr[$上游code],20)` | 上游币种不在汇率数组时除数为 0 |
> | `app/admin/controller/ProductController.php:835` | `bcdiv($arr[$currency],$arr[$upstream_currency],2)` | 上游币种不在汇率数组时除数为 0；精度 2 而非 20（勘误 17） |
> | `app/home/controller/CartController.php:410`、`:415` | `bcdiv($api_count,$api_count2,2)` | `$api_count2`（前天调用数）为 0 时**明确除零** |
> | `app/api/controller/ProductController.php:258` 等 | `bcdiv($flag["bates"],100,2)` | 除数固定 100，安全 |
>
> PHP 7.2/7.3 下 `bcdiv(x,0,2)` 为 Warning+`NULL`、PHP 8 抛 `DivisionByZeroError`；旧文档「`INF%`」**未在代码中确认**，须实例实测，不写成确定结论。

### 4.4 价格换算规则

| 场景 | 公式 | 位置 |
|---|---|---|
| 跨币种 | `rate = bcdiv(arr[本地code], arr[上游code], 20)`；同币种 `rate=1` | `app/common/logic/Product.php:154-164`、`:206-218`；`app/common/model/ProductModel.php:220-234` |
| 汇率精度不一致 | `Product.php:160/:217`、`ProductModel.php:229` 用 20 位；`app/admin/controller/ProductController.php:835` 用 **2 位**，均无除零保护（勘误 17） | `app/common/logic/Product.php:154-164`、`:206-218` |
| percent 加价 | `bcmul(product_price, upstream_price_value, 2)/100`（`zjmf_api && upstream_version>0 && percent`） | `app/common/logic/Cart.php:486-488`（旧文档 `CartController.php:181-204` 已更正） |
| percent 同步落地 | 逐 `price_type` 配对 `* upstream_price_value/100`，跨币种再 `* rate` | `app/common/logic/Product.php:336-361`、`:450-473` |
| `custom` 模式 | **不乘**，售价由后台手填；只做「销售价<成本价」检测，命中则 `stock_control=1,qty=0` 并写 `info_notice` | `app/common/logic/Product.php:620-637` |
| 汇率源 | `json => https://api.exchangerate-api.com/v4/latest/USD`、`xml => ECB`（`EUR=1`） | `app/app.php:26` 的 `getRateUrl`；`app/admin/controller/CurrencyController.php:14` |
| 抓取实现 | `getRate($method)`：`json` 取 `(array)json_decode($rawFeed)->rates`、`xml` 逐行解 `currency='`/`rate='` | `app/common.php:4760-4789` |
| 币种对象 | `getUserCurrency()` → `{id,code,prefix,suffix}`；`convert_currency()` `round(amount/base_rate*convertto_rate,2)` | `app/common.php:1037-1048`、`:1641-1649` |
| 汇率缓存 | `shd_cron_currency_rate`，TTL 86400s（**该时长未在代码中确认**） | `app/common/logic/Product.php:116-125` |

> `upstream_price_type` 是 `percent` 与 `custom` 的**互斥分支**，读价格前务必先看这个字段。币种输出键差异：`cart/all`/`proinfo` 用 `currency`（`app/home/controller/CartController.php:456`），`proList` 用 `currency_code`（`app/api/controller/ProductController.php:285-286`）。

### 4.5 计费周期 / 价格字段 / `pay_type` / 汇率币种字典

全部来自 `app/app.php:26`（单行配置数组）。

**勘误 2：`config("billing_cycle")` 是 18 键（含 `free`），17 是 `config("price_type")` 键数 / `shd_pricing` 价格字段对数。** 旧标题「17 个」改为「`billing_cycle` 18 键 / `price_type` 17 键」。

`config("billing_cycle")` **18 键**：`free, onetime, hour, day, ontrial, monthly, quarterly, semiannually, annually, biennially, triennially, fourly, fively, sixly, sevenly, eightly, ninely, tenly`（`app/app.php:26`）。`billing_cycle_unit`、`coupon_cycle` 键集相同（后者首键为 `""` 而非 `free`），同为 18 键。拼写怪异：`fourly/fively/sixly/sevenly/eightly/ninely/tenly` 均为硬造词；`semiannually` 为半年度标准拼法；是 **`fourly`** 不是 `fourthly`。

`config("price_type")` **17 键**，是 `[周期价字段名, 初装费字段名]` 二元数组，被 `baseUpdateProduct()`（`app/common/logic/Product.php:321,337-361,451-473`）、`postCreateProducts()`（`app/home/controller/CartController.php:172,182-204,263-282`）、`getUpstreamPrice()`（`app/admin/controller/ProductController.php:2573-2577`）循环使用：

| # | 周期键 | 价格字段 | 初装费字段 | `shd_pricing` 列 |
|---|---|---|---|---|
| 1 | `onetime` | `onetime` | `osetupfee` | `thinkcmf.sql:1375`/`:1392` |
| 2 | `hour` | `hour` | `hsetupfee` | `:1376`/`:1393` |
| 3 | `day` | `day` | `dsetupfee` | `:1377`/`:1394` |
| 4 | `ontrial` | `ontrial` | **`ontrialfee`**（非 `ontrialsetupfee`） | `:1378`/`:1395` |
| 5 | `monthly` | `monthly` | `msetupfee` | `:1379`/`:1396` |
| 6 | `quarterly` | `quarterly` | `qsetupfee` | `:1380`/`:1397` |
| 7 | `semiannually` | `semiannually` | `ssetupfee` | `:1381`/`:1398` |
| 8 | `annually` | `annually` | `asetupfee` | `:1382`/`:1399` |
| 9 | `biennially` | `biennially` | `bsetupfee` | `:1383`/`:1400` |
| 10 | `triennially` | `triennially` | `tsetupfee` | `:1384`/`:1401` |
| 11 | `fourly` | `fourly` | **`foursetupfee`**（无 `ly`） | `:1385`/`:1402` |
| 12 | `fively` | `fively` | `fivesetupfee` | `:1386`/`:1403` |
| 13 | `sixly` | `sixly` | `sixsetupfee` | `:1387`/`:1404` |
| 14 | `sevenly` | `sevenly` | `sevensetupfee` | `:1388`/`:1405` |
| 15 | `eightly` | `eightly` | `eightsetupfee` | `:1389`/`:1406` |
| 16 | `ninely` | `ninely` | `ninesetupfee` | `:1390`/`:1407` |
| 17 | `tenly` | `tenly` | `tensetupfee` | `:1391`/`:1408` |

> 命名例外（勘误：`fourly…tenly` 旧文档压成一行）：`fourly→foursetupfee`（无 `ly`）、`ontrial→ontrialfee`；`five/six/seven/eight/nine/tensetupfee` 为 6 个独立字段，勿合并。

**`pay_type` 取值差异**：

| 侧 | 取值 | 依据 |
|---|---|---|
| `config("pay_type")`（中文映射，**5 值**） | `free=>免费, onetime=>一次, recurring=>周期, hour=>小时, day=>天` | `app/app.php:26` |
| `config("product_paytype")` | **仅 3 值** `free/onetime/recurring` | `app/app.php:26` |
| `products.pay_type`（JSON） | `pay_type` + `pay_ontrial_status/cycle/cycle_type` + `clientscount_rule` | `app/common/logic/Product.php:292-312` |
| 老版同步回写 | `day`/`hour` **强制改写**为 `recurring` | `app/common/logic/Product.php:293-295`；`app/common.php:5660-5663` |
| `proList`/`detail`/`getUpgradeProduct` 输出 | `recurring` → **`recurring_prepayment`** | `app/api/controller/ProductController.php:271-276`、`:473-478`、`:682-687` |

**汇率与币种**：默认 `fee_type="CNY"`（`app/app.php:26`）；`shd_currencies{id,code,prefix,suffix,format,rate,default}`（`public/install/thinkcmf.sql:610-618`），`Currencies::getCurrencies()` 读表（`app/common/logic/Currencies.php:14-23`）；老版 `zjmf_api` 汇率 `getZjmfUpstreamProducts()` 取 `data.currency` → `getRate("json")` → `bcdiv(…,20)`，同币种 `=1`（`app/common/model/ProductModel.php:220-230`）；资源线 `getResourceRate()` 直接取 `data.rate`（`app/common/model/ProductModel.php:216-219`）。

---

## 5. 老版下单 / 结算 / 支付 / 余额 / 退款（重点）

> 本章给出老版站际下单 / 结算 / 支付 / 余额 / 退款的完整链路。老版站际链路唯一出口是 `zjmfCurl()`（`app/zjmf.php:185-224`）：查 `zjmf_finance_api`，空 → `400 "API账号或秘钥错误"`（`:187-192`）；登录带 `Authorization: Bearer {jwt}`（`:208`）；`405` → 强制重登一次并重放（`:211-222`）。

**身份对照**：下游（本站买上游）调 `app/common/logic/Host.php:63-195` 的 `/user_info`、`/cart/clear`、`/cart/add_to_shop`、`/cart/settle`、`/apply_credit`、`/apply_credit_limit`；上游（下游买本站）由 `app/home/controller/CartController.php` 提供 `cart/all|get_product_config|stock_control|add_to_shop|get_total|settle|clear|credit|summary|hostinfo|createproducts|productsgroups`，`app/home/controller/PayController.php` 提供 `/apply_credit`、`/apply_credit_limit`。

### 5.1 全链时序：加购 → 结算 → 向上游下单 → 本地落单 → 开通（逐行）

| # | 行号 | 动作 |
|---|---|---|
| 1 | `app/home/controller/CartController.php:2660-2674` | `addToShop()` 校验 `pid(require|number)`、`configoption/customfield/os(array)`、`qty`、`hostid`、`checkout(in:0,1)`；校验键写作 **`currecncyid`**（`:2667`，解码拼写，勘误 15）；失败 → `406`（`:2673`） |
| 2 | `CartController.php:2761`、`:2856` | `new Shop($uid)` → `$shop->addProduct($pid,$billingcycle,$serverid,$configoption,$customfield,$currencyid,$qty,$os,$host,$password,$hostid,$checkout)` |
| 3 | `app/common/logic/Shop.php:222-240` | `checkProductToArr()`（`:224`）→ 写 `cart_data["products"][]`（`:230`）→ `save()`（`:232`）→ 返回 `["status"=>"success","i"]`（`:240`） |
| 4 | `CartController.php:1660-1667` | `settle()` 取 `payment`/`checkout`/`use_credit`（`:1663/:1666`）、`clients.defaultgateway`（`:1665`） |
| 5 | `CartController.php:1848-1853` | `payment` 须命中 `gateway_list1()`，否则 `406 PAY_TYPE_ERROR` |
| 6 | `CartController.php:2400` | 构造 `invoices_data`：`uid,create_time,due_time,paid_time=0,subtotal,credit=0,tax,total,status="Unpaid",payment,notes,type="product"` |
| 7 | `CartController.php:2401` | 构造 `order_data`：`uid,ordernum=cmf_get_order_sn(),status="Pending",create_time,update_time=0,amount,payment` |
| 8 | `CartController.php:2410-2413` | `request()->is_api==1` 时按 `downstream_url`(http/https)+`downstream_token`(长 32)+`downstream_id`(数字) 判 `is_downstream` |
| 9 | `CartController.php:2417`/`:2427`/`:2473` | 事务内 `invoices`→`orders`→`host` 三次 `insertGetId` |
| 10 | `CartController.php:2496` | `invoice_items` 批量写（type `host`/`setup`/`discount`/`promo`，见 `:2271/:2273/:2316/:2374/:2379`），带 `invoice_id`、`rel_id=hostid` |
| 11 | `CartController.php:2478-2482` | 按 `products.auto_setup` 分 `create_after_order`(`order`)/`create_after_pay`(`payment`) |
| 12 | `CartController.php:2537-2551` | `is_downstream` 防重（§5.7）并把三元组写 `host.stream_info`（`:2550`） |
| 13 | `CartController.php:2630-2650` | `total==0`：`invoices.status=Paid`（`:2632`）→ `Invoices::processPaidInvoice()`（`:2634`）→ **`1001`** + `data.hostid=$all_host` + `data.url`（`:2644-2648`） |
| 14 | `CartController.php:2652-2658` | `total>0`：`200` + `data.invoiceid`；`is_downstream` 附 `data.hostid`（`:2654-2656`） |
| 15 | `CartController.php:2562-2568` | 异常 → `rollback` + `406` + 异常 msg |
| 16 | `CartController.php:2621-2628`/`:2636-2642` | `AutoCreate::push(["hid","ip"])` 或 `async_create` |
| 17 | `app/queue/job/AutoCreate.php:15-23` | `fire()`：`catch(Throwable)` → `self::later(10,$data)`（**失败 10 秒后重投**） |
| 18 | `AutoCreate.php:24-29` | `handle()` → `Host::createFinal($data["hid"],$data["ip"])`（**`:29` 开通链**） |
| 19 | `app/common/logic/Host.php:20-27` | `create()`：`shd_allow_auto_create_queue` 时 push `AutoCreate`（`:23`），否则直接 `createFinal`（`:26`） |
| 20 | `Host.php:36-42` | 互斥锁 `HOST_DEFAULT_ACTION_CREATE_{id}` TTL 300s，命中 → `406 "产品正在开通中"` |
| 21 | `Host.php:43-45` | `new Provision()` + `getParams($id)`（`app/common/logic/Provision.php:940`） |
| 22 | `Host.php:63-195` | `api_type=="zjmf_api"` → 向上游下单全链（§5.2 P1→P6） |
| 23 | `Host.php:196-210` | `api_type=="resource"` → `resourceHostCreate($id)`（`:199`），回写 `dcimid/domainstatus`（`:202-205`） |

### 5.2 下游实际调用的「上游 path」逐个（P1→P6，`Host.php:63-195`）

| # | path | 调用点 | 参数 | 返回与本地处理 |
|---|---|---|---|---|
| **P1** | `GET /user_info` | `app/common/logic/Host.php:64` | `[]`,30s,GET | `status==200` 才继续；取 `data.user.currency`（`:110` 作上游 `currencyid`） |
| **P2** | `POST /cart/clear` | `Host.php:82` | `downstream_url/token/id`（`:79-81`） | **首步探活/续付**：`200`+`hostid`→写 `dcimid`（`:84-87`）；`200`+`invoiceid`→直接续付 P6（`:88-104`）；`400`+`domainstatus=Active`→视为已开通（`:183-190`） |
| **P3** | `POST /cart/add_to_shop` | `Host.php:123` | `pid=upstream_pid`、`billingcycle`、`host`、`password=cmf_decrypt`、**`currencyid`**（与本站入站 `currecncyid` 拼写不一致，勘误 15）、`qty=1`、`configoption{上游option⇒值}`、`customfield{upstream_id⇒value}`（`:106-122`） | `200` 才进 P4 |
| **P4** | `POST /cart/settle` | `Host.php:136` | `downstream_*` + `cart_data`（含 `configoptions`，`:133-135`）；`is_resource` 加 `agent_client=1`（`:130-132`） | `200`→取 `data.hostid[0]`/`invoiceid`（`:138-143`）；`1001`→直接视为开通完成（`:175-180`） |
| **P5** | `POST /apply_credit` | `Host.php:96/:150/:167/:169` | `invoiceid`、`use_credit`(1 用/0 撤)、`enough`(0/1)、`downstream_*`（`:90-95`、`:143-148`） | `1001`→改判 `200` 并取 `data.hostid[0]` 写 `dcimid`（`:97-101`/`:151-155`）；`200`=未付清（`:102-104`） |
| **P6** | `POST /apply_credit_limit` | `Host.php:158` | `invoiceid`、`use_credit_limit=1`、`enough=0`、`downstream_*`（`:157`） | `1001`→成功（`:159-163`）；否则撤销（P5 `use_credit=0`） |
| P7 | `GET cart/stock_control` | `CartController.php:2812` | `pid=upstream_pid`,30s | `data.product{hidden,stock_control,qty}`；见 §4.2 |
| P8 | `GET cart/ontrialmax` | `CartController.php:1787`/`:2782` | `pid=upstream_pid`,**5s** | 试用/限购数据 |
| P9 | `GET /host/renew` + `/apply_credit` | `app/common/logic/Invoices.php:234`/`:239` | `hostid=dcimid`、`billingcycles` | 续费账单生成后 `use_credit=1` 支付 |
| P10 | `GET cart/get_product_config` | `app/zjmf.php:78` | `pid` | 商品配置/定价（§4.1） |
| P11 | `GET cart/hostinfo` / `host/header` | `app/zjmf.php:84` / `app/admin/controller/ZjmfFinanceApiController.php:1046` | `host_id`、`source=API` / `hostid` | 主机头（§6.1） |

> **顺序**：P1 → **P2（`/cart/clear` 首步探活，旧文档完全漏掉）** →（无未付单）P3 → P4 → P5 →（失败）P6 →（再失败）P5 撤销。`app/common.php:2323/:2348` 另有 `/apply_credit` 用于购买重装次数/流量包。
> **勘误 15**：本站入站 `add_to_shop` 校验键为 `currecncyid`（`CartController.php:2667`），出站 `Host.php:110` 用 `currencyid`，两处拼写不一致须注明。

### 5.3 本站作为上游「被下游下单」的对端实现（入站）

| 下游调用 | 本站实现 | 入参 / 返回 |
|---|---|---|
| `POST /cart/clear` | `app/home/controller/CartController.php:3277-3306` `clearCart()` | 清空 `cart_session`（`:3282`）；`is_api==1` 按 `downstream_token` 查 `host.stream_info`（`:3293`）→ 已付 `Paid` 回 `400 "该订单已开通,请勿重新开通"`+`hostid`+`domainstatus`（`:3298-3300`）；未付回 `200`+`invoiceid`+`hostid`（`:3301`）；否则 `200`（`:3305`） |
| `POST /cart/add_to_shop` | `CartController.php:2660-2935` | §5.1-A；`200`+`data.i` / 校验失败 `406`（`:2673`） |
| `POST /cart/settle` | `CartController.php:1660-2659` | §5.1-B；`1001`（金额 0/已付清）或 `200`（`invoiceid`，下游附 `hostid[]`）或 `406` |
| `POST /apply_credit` / `apply_credit_limit` | `app/home/controller/PayController.php:259-344` / `:345-435` | §5.4；成功 `1001`、撤销/部分 `200` |
| `POST /cart/get_total` | `CartController.php`（组 3） | `data.products{child[],duration,total,setupfee_total,sale_total,sale_price,…,bates}` |
| `GET cart/all` | `CartController.php:443-458` | 无参；`data.products[]`+`count`+`currency`（`:456`）；每次写审计（`:447`） |
| `GET cart/get_product_config` | `CartController.php:459-504` | `pid`；§4.1 全字段；审计 `:472` |
| `GET cart/stock_control` | `CartController.php:336-343` `getQty()` | `pid`；`data.product{qty,stock_control,hidden}` |
| `GET cart/credit`/`summary`/`hostinfo`/`ontrialmax` | `:328-335`/`:389-425`/`:344-375`/`:376-388` | 见 §7 / §6.1 |

**路由注册**（`data/route/home.php`）：`apply_credit` `:162`、`apply_credit_limit` `:163`、`cart/settle` `:166`（**组 2 `:91-400` `Check`，强制登录**）、`cart/clear` `:249`；`cart/all` `:423`、`summary` `:425`、`hostinfo` `:427`、`credit` `:428`、`stock_control` `:429`、`createproducts` `:431`、`productsgroups` `:432`、`get_total` `:438`、`add_to_shop` `:439`（**组 3 `:401-514` `UserCheck`，不强制登录**）。即 **结算/支付强制登录，加购/查询不强制**（勘误 13）。

### 5.4 本地落单、余额与支付

**`apply_credit` 使用 / 撤销**（`PayController.php:259-344`）：

| # | 行号 | 分支与返回 |
|---|---|---|
| 1 | `:263-265` | `invoiceid`、`use_credit`、`checkInvoice($uid,$invoiceid)` |
| 2 | `:268-272` | **`use_credit=0`（撤销）**：`invoices` 更新 `credit=0,total=subtotal` → `200`（仅改账单，不回滚已扣 `clients.credit`） |
| 3 | `:273-277` | `request()->is_api==1` 校验 `downstream_url/token(32)/id` 得 `is_downstream` |
| 4 | `:279-293` | 余额 ≤0 → `400`；封顶到 `subtotal`；`is_api==1` 且不足 → `400`（附「上游账单#完成支付后即可开通」，`:290`） |
| 5 | `:294-300` | 算 `paid_invoice_credit`、`paid_invoice_total=bcsub(subtotal,credit,2)` |
| 6 | `:301-335` | **全额付清**：`invoices` 置 `status/payment_status=Paid`（`:302`）→ 事务 `setDec clients.credit` + `credit_log()`（`:304-320`）→ `Invoices::processPaidInvoice()` 开通（`:321-322`）→ **`1001 "支付完成"`** + `data.hostid`（`invoice_items` 中 `type=host` 的 `rel_id`，`:325`）+ `data.url`（`:323-326`）→ 回写 `host.stream_info`（`:327-334`） |
| 7 | `:337-339` | `enough==1` 且未付清 → `400 "余额不足"` |
| 8 | `:340-341` | 部分使用 → `200 "使用余额成功"`，仅改 `invoices.total` |
| 9 | `:343` | `checkInvoice` 失败原样返回 |

`applyCreditLimit()`（`PayController.php:345-435`）为信用额支付，成功同样返回 `1001`（`:420-432`）。

**「撤销失败仅重试 1 次」真实代码**（`app/common/logic/Host.php:165-172`）：

```
:165 $credit_unuse = ["invoiceid"=>$invoiceid, "use_credit"=>0];
:167 $module_res = zjmfCurl(..., "/apply_credit", $credit_unuse); // 第 1 次撤销
:168 if ($module_res["status"] != 200) {
:169 $module_res = zjmfCurl(..., "/apply_credit", $credit_unuse); // 仅重试 1 次
:170 }
:171 $module_res["status"] = 400; // 重试结果不再判定
:172 $module_res["msg"] = $now_msg; // 原 msg 见 :166
```

即撤销最多 2 次请求（1 正常 + 1 重试），且**重试后不检查是否成功直接返回 400**，极端情况上游余额仍被占用 → 挂账，需 §5.6 对账兜底。旧文档结论正确但未给行号，本稿补齐为 `app/common/logic/Host.php:167-170`。

**`pay_type` 五值与支付路径分叉**（`app/app.php:26`）：

| 取值 | 含义 | 出现处 |
|---|---|---|
| `free` | 免费 | `app/common.php:959`、`app/common/logic/Cart.php:61-66`、`CartController.php:561,1990` |
| `onetime` | 一次性 | `app/common.php:1282`、`Cart.php:55-60`、`CartController.php:2082` |
| `recurring` | 周期 | `app/common.php:1286`、`Cart.php:67-85` |
| `hour` / `day` | 小时 / 天 | `app/common.php:5660-5663`（同步回写时强制改为 `recurring`） |

| 分叉 | 判定 | 行号 |
|---|---|---|
| 网关支付 | `payment ∈ gateway_list1()`，否则 `406`；网关来自 `shd_payment_gateways.visible=="on"`（`app/common/logic/PaymentGateways.php:14,15-28`） | `CartController.php:1663,1848-1853` |
| 余额支付 | `use_credit=1` → `applyCredit()`；对账值 `creditPay` → `invoices.credit>0` | `CartController.php:1666`；`PayController.php:259`；`ZjmfFinanceApiController.php:657-658` |
| 信用额支付 | `applyCreditLimit()`；对账值 `creditLimitPay` → `invoices.use_credit_limit=1` | `PayController.php:345`；`ZjmfFinanceApiController.php:655-656` |
| 对账 `payment` 三类 | `creditLimitPay`/`creditPay`/其他 `o.payment` | `app/admin/controller/ZjmfFinanceApiController.php:654-662` |

**`check_pay()`**：`app/common.php:928-931` `return (new OrderController())->orderPayHandle($data)`；实现 `app/home/controller/OrderController.php:29-134`，入参 `invoice_id,amount_in,trans_id,currency,paid_time,payment`（`:31-36`）。幂等① `invoices.payment_status=="Paid"` → `true`（`:59-62`）；② `check_account_id($trans_id)` 命中 `accounts.trans_id` → `true`（`:63-67`）；写流水 `accounts`（`:69,73`）、改账单 `invoices.payment_status=Paid`（`:77-78`）、余额抵扣 + `credit_log()`（`:83-94`）、开通 `Invoices::processPaidInvoice()`（`:116-118`）。

**写入表与字段**：

| 表 | 写入点 | 关键字段 |
|---|---|---|
| `shd_orders` | `CartController.php:2401,2427` | `uid,ordernum,status="Pending",create_time,update_time,amount,payment,invoiceid,promo_*` |
| `shd_invoices` | `CartController.php:2400,2417`；`PayController.php:302,340`；`OrderController.php:77,88,102` | `uid,subtotal,credit,total,status="Unpaid"→"Paid",payment,payment_status,type="product",paid_time,url` |
| `shd_invoice_items` | `CartController.php:2271,2273,2316,2374,2379,2496` | `uid,type(host/setup/discount/promo),description,amount,due_time,payment,invoice_id,rel_id`；`get_pay_host_id()` 取 `rel_id`（`app/common.php:939-942`） |
| `shd_credit` | `app/common.php:1636-1640` `credit_log()` | `uid,description,amount,relid,notes,balance,create_time,aff_refund`；调用点 `PayController.php:314,395`、`OrderController.php:56,94` |
| `shd_accounts` | `OrderController.php:69,73` | `uid,currency,gateway,create_time,pay_time,amount_in,fees,amount_out,rate,trans_id,invoice_id,refund,description` |
| `shd_clients` / `shd_host` | `PayController.php:309`、`OrderController.php:55,90,92`；`CartController.php:2473,2550`、`PayController.php:333` | `credit`（`setDec`/`setInc`）；`stream_info`(三元组)、`dcimid`、`domainstatus` |

### 5.5 老版退款 / 终止说明

> 老版站际**没有独立的退款 HTTP 契约**：终止走 `POST /host/cancel`（`id=dcimid, type="Immediate", reason="立即删除"`，`app/common/logic/Host.php:542-546`，资源线 `:547-553`），随后 `stream_info=""`（`Host.php:608-609`）并 `pushHostInfo($id)`；资金冲正依赖 §5.6 的对账链路（`app/admin/controller/ZjmfFinanceApiController.php:617-784`），本树未提供 `/refund` 类专用接口（未在代码中确认）。

### 5.6 对账与账号管理

**`app/admin/controller/ZjmfFinanceApiController.php`**（老版账号 CRUD / 对账）：

| 方法 | 行号 | 职责 / 上游 path |
|---|---|---|
| `createApi()` | `:18-66` | 建号：`type ∈ zjmf_api/manual/whmcs`（历史版本分支排除）；`password=aesPasswordEncode(...)`（`:38`）；`zjmf_api` 建号即 `GET /cart/all` 探活写 `product_num`（`:40-42`）；返回 `jsonrule`（`:65`） |
| `modifyApi()`/`detail()`/`deleteApi()`/`index()`/`refreshStatus()` | `:67`/`:145`/`:158`/`:183`/`:220-260` | 改/查/删/列表/刷新（`GET /cart/all` 在 `:249`） |
| `apiProducts()` | `:407` | 上游商品导入；`:1236` 拼 `upstream_product_shopping_url=/cart/goods.htm?id=` |
| `apiOrder()` | `:617-784` | **对账/订单查询**：`orders/invoices/clients` 联表；`payment` 三类分叉（`:654-662`） |
| `upstreamHost()` | `:1041-1051` | `GET cart/hostinfo`（`:1046`），`hostid` 可数组 |
| `downstreamSummary()` | `:1052-1078` | `manual`（`:1057-1064`）本地统计不调上游；老版走 `GET cart/summary`（`:1073`） |
| `apiLog()` | `:1079-1176` | 查 `api_resource_log`（`:1105`），`description` 内 `Invoice/User/Host/Order ID` 转后台链接 |
| `upstreamCredit()` | `:1275-1296` | **老版 → `GET cart/credit`（`:1290`）**；历史版本分支排除 |
| `getRenew()` | `:1297-1350` | 续费对账：`d.type="renew"`、`paid_time` 区间、`zjmf_api_id|upper_reaches_id>0`（`:1307`） |

**`app/admin/controller/AgentController.php`**（`resource` 订单线，详见 §6.3）：`getOrder()` `:325` → `resource/agentorders`（`:330`）、`getRenew()` `:404` → `resource/agentrenew`（`:409`）、`getBaseInfo()` `:641-663` → `resource/agentbaseinfo`（`:645`，得 `pids/total/month/grade/refunded`，本地 `getTotal()` 相减 `bcsub` 得利润 `:655`）+ `resource/agentcredit`（`:656`，得 `data.client`）。

**`apiResourceLog()` 与 `shd_api_resource_log`**：写函数 `app/zjmf.php:479-484`，字段 `uid,description,ip=get_client_ip6(),create_time,update_time=0,pid,version,port=get_remote_port(),source`（`:481`）；典型 `description`「客户User ID:X在…调取cart/all接口获取产品数据」（`CartController.php:446`、`:472`）；读取 `CartController.php:397,405,409,414,440`（`summary()` 的 `agent_count/api_count/ratio/form_api`）、`ZjmfFinanceApiController.php:1105-1173`、`app/admin/controller/LogRecordController.php:678-835`。**本表是调用审计表，不是资金台账**；`cart/summary` 统计与「对账」实际来源于 `orders/invoices/accounts/credit`（§5.4）与 `resource/agentbaseinfo|agentcredit`（§6.3）。

### 5.7 幂等与重试

| 机制 | 真实情况 | 行号 |
|---|---|---|
| 标准幂等键 | 老版 `settle`/`apply_credit` **均无** `request_id`/`Idempotency-Key`；参数仅 `downstream_url/token/id` + `cart_data` | `Host.php:126-136,90-95`；`CartController.php:1660-1667` |
| 防重（探活） | `POST /cart/clear` 带三元组；上游按 `downstream_token` 查 `host.stream_info`，已有未付单回 `invoiceid/hostid` 续付，已付回 `400` | `Host.php:82-104`；`CartController.php:3290-3303` |
| 防重（落单） | `settle` 内 `is_downstream` 再按 `downstream_token` 查重，命中直接 `1001` + 原 `hostid` | `CartController.php:2537-2551` |
| 本地互斥锁 | `HOST_DEFAULT_ACTION_CREATE_{id}` TTL 300s | `Host.php:36-42` |
| 405 重登重放 | 仅 `status=405` 强制重登并重放一次 | `app/zjmf.php:211-222` |
| 网关回调幂等 | 重复支付 / `trans_id` 去重 | `OrderController.php:59-67` |
| 开通重试 | `AutoCreate` 抛异常 → 10 秒后重投 | `app/queue/job/AutoCreate.php:20-22` |
| 撤销重试 | 失败仅重试 1 次且不复核结果 | `Host.php:167-170`（§5.4） |
| 超时 | 默认 `timeout=30`（`app/zjmf.php:185,387` 秒）；探活类 5–15s | — |
| 重复下单风险 | 超时后上游已建单而本地未收到 → 依赖 `downstream_token` 防重；**无 5xx/超时自动重试**（`commonCurl` 非 200 直接转 `400`，`app/zjmf.php:426-433`），上层业务手动重试会造成重复 | `Host.php:137-150` |

> **勘误 11**：旧文档以「历史版本购物车清空探活、回 `data.order_id`」为例（已删除，属排除范围）；老版是 `POST /cart/clear`（`Host.php:82`）回 `invoiceid/hostid`（`CartController.php:3301,3299`）。

### 5.8 代理商「代理上游商品到自己站」

| 接口 | 用途 | 请求体要点 | 行号 |
|---|---|---|---|
| `POST /cart/productsgroups` | 列出可代理的上游分组 | 无参；返回 `{id,name,second:[{id,name}]}`，空分组回退「系统默认」 | `CartController.php:68-95`（路由 `data/route/home.php:432`） |
| `POST /cart/createproducts` | **把上游商品整套复制到本地**（商品+定价+自定义字段+配置项+子项定价+联动） | `percent_value`、`currency{code}`、`product`（上游整结构，`id`→`upstream_pid`）、`product_groups_id`、`token`、`product_pricings[]`、`customfields[]`、`config_groups[]`、`advanced[]` | `CartController.php:96-327`（路由 `:431`） |
| `POST /cart/resource_product` | ⚠️ **死路由**（勘误 8） | — | `data/route/home.php:430` |

> **勘误 8（`cart/resource_product`）**：路由 `data/route/home.php:430` **存在**（`POST` → `home/Cart/postResourceProduct`），但 `postResourceProduct` **全仓无定义**（`CartController.php` 方法表无此方法）、**无任何 `zjmfCurl(...,"cart/resource_product",...)` 出站调用点**，全项目仅文档 2 处 + 路由 1 处。结论是「路由存在 + 方法缺失 + 无调用点」的**纯死路由**，调用必然「方法不存在」；不是「调用了但报错」。实际可用的是 `postCreateProducts`（`CartController.php:96-165`）。

`postCreateProducts()` 入参（`:98-132`）：`percent_value`、`currency{code}`、`product{...}`、`product_groups_id`、`token`、`product_pricings[]`、`customfields[]`、`config_groups[]`、`advanced[]`；`resource_id` 取 `zjmf_finance_api where is_resource=1 and is_using=1 order id desc`（`:133-134`）；汇率 `bcdiv($arr[本地code],$arr[供应商code],20)`（`:104-109`）。**落地强制字段**（`:153-161`）：`gid`、`api_type="zjmf_api"`、`zjmf_api_id/server_group=resource_id`、`upstream_version=location_version?:1`、`location_version=1`、**`upstream_pid=$pid`**、`upstream_price_type="percent"`、`upstream_price_value=percent_value`；回写 `product_shopping_url`/`product_group_url`（`:168-169`），删建 pricing/customfields/config_groups/advanced（`:170-320`）；返回 `jsons(["status"=>200,"msg"=>"代理成功","rate"=>$rate])`（`rate` 在**顶层**非 `data`，`:326`），异常回滚 `400 代理失败:…`（`:322-325`）。

### 5.9 商品落地：字段与关联（`baseUpdateProduct()`）

`app/common/logic/Product.php:286-524`，由 `syncProduct()`（`:181`）与 `cronSyncProduct()`（`:257/:259`）触发。`$basedata`（`:300`）：`type,password,auto_setup="payment",auto_terminate_days,config_options_upgrade,down_configoption_refund,retired,is_featured,groupid,api_type="zjmf_api",location_version=本地+1,upstream_version=上游location_version,zjmf_api_id,server_group=zjmf_finance_api_id,upstream_pid,hidden,pay_method,rate,upstream_stock_control,upstream_qty,stock_control=0,qty=0,upstream_auto_setup,upstream_ontrial_status,upstream_product_shopping_url`；首同步 `first_cron`（`:313-320`）额外写 `description,allow_qty,is_truename,is_bind_phone,cancel_control,pay_type`。**删后重建**（事务 `:322-323`、`:518-522`）：`shd_pricing` type=product（`:326-367`）、`shd_customfields`（`:368-387`）、配置组/项/子项及 pricing（`:389-482`）、`advanced` 联动（`:483-511`）。触发条件：`upstream_version != info.location_version` 或 `upstream_price_type` 变化（`:165`、`:190`）；仅库存变化只更新 `upstream_qty/upstream_stock_control`（`:187-189`）。

| 关系 | 字段 | 依据 |
|---|---|---|
| 本地商品 → 上游 API 账号 | `products.zjmf_api_id = zjmf_finance_api.id`；同步时 `server_group` 也写同一值 | `app/common/logic/Product.php:300`；`CartController.php:155-156` |
| 本地商品 → 上游商品 | `products.upstream_pid = 上游 products.id` | `Product.php:179-180,290`；`CartController.php:159` |
| 版本比对 | 上游 `location_version` ↔ 本地 `upstream_version` | `Product.php:165`；`app/admin/command/Cron.php:1258` |
| 配置项 → 上游 | 各表 `upstream_id` 存上游 id | `Product.php:378,408,417,429` |
| 切换上游 | `zjmf_api_id` 或 `upstream_pid` 变化时把 `upstream_version` 置 0 强制重拉 | `app/common/model/ProductModel.php:241-246` |

> 运行时表前缀 `shd_` 由 `app/api/controller/InstallController.php:353` 设定、`:390` 执行 `str_replace("shd_", $config["prefix"], $sql)`；代码内 `Db::name()` 均为无前缀逻辑名。

---

## 6. 主机信息与老版运维

> 原字段级分册 `zjmf-3.7.6-上游主机运维接口对接清单.md` 未收录于当前仓库，仅作历史存目；本章给总册口径 + `resource/*` 代理商线完整清单。CloudCMS 已实现的经典线操作与限制另见 [实例操作规范](zjmf-api-spec.md#4-instance-operations-provisiondefault--lifecycle) 和 [机器管理与 VNC](classic-zjmf-machine-controls.md)。

### 6.1 主机信息：`host/header` 与 `cart/hostinfo`

**`getZjmfUostreamHostInfo()` → `host/header`**（出站封装 `app/zjmf.php:81-89`）：签名 `($id,$hid)`，入参 `["host_id"=>$hid, "source"=>"API"]`（`:83`），`zjmfCurl($id,"host/header",$data,30,"GET")`（`:84`），`status==200` → `return $result["data"]["host_data"]`，否则 `[]`（`:84-88`）。鉴权随 `zjmfCurl` 依 `is_resource`/`type` 选登录（`app/zjmf.php:193-223`）。

> **勘误 11（主入口无调用点）**：`getZjmfUostreamHostInfo()` **全库仅定义处一处，无任何调用点** → 标「**未在代码中确认**」。`host/header` 实际由 **15+ 处直接 `zjmfCurl`/`resourceCurl` 调用**：`app/admin/controller/ClientsServicesController.php:118,132`；`app/common/logic/Host.php:654,673,1922`；`app/home/controller/HostController.php:562,680,735,836,1019,1067,2310`；`app/openapi/controller/HostController.php:1264,1304`；`app/home/controller/DcimController.php:81`；`app/openapi/controller/ProductController.php:286`；`app/admin/command/Cron.php:281`。

**接收端 `getHeader()`**（`app/home/controller/HostController.php:903-1369`，下一方法 `setSslConfig` 于 `:1370`）：

| 环节 | 内容 | 依据 |
|---|---|---|
| 入参 | `host_id`（`uid` 归属校验）、可选 `source=API`、`nat=true` | `HostController.php:910-917`、`:1016-1018` |
| `source=API` 记日志 | `apiResourceLog($uid,$desc)` | `HostController.php:906-908` |
| `host_data` 主字段 | `id,orderid,initiative_renew,productid,serverid,regdate,domain,payment,groupid,promoid,firstpaymentamount,amount,billingcycle,nextduedate,nextinvoicedate,dedicatedip,assignedips,domainstatus,username,password,suspendreason,pid,api_type,auto_terminate_end_cycle,auto_terminate_reason,bwusage,bwlimit,os,remark,dcimid,dcim_area,dcim_os,port,type,productname,payment_type,config_options_upgrade,zjmf_api_id,upstream_price_type,upstream_price_value,upper_reaches_id,config_option1,password_rule,groupname,ordernum,cancel_control,ocreate_time,order_amount` | `HostController.php:919` |
| 派生字段 | `status`(账单态)、`invoice_id`、`password_rule{}`、`domainstatus_desc=config("domainstatus")[值]`、`suspendreason_type/suspendreason`(按 `-` 拆)、`assignedips`→数组、`password=cmf_decrypt`、`*_desc`、`ip_num`、`bwusage` 取整、`promo_code`、`allow_upgrade_*`、`payment_zh` | `HostController.php:937-987`、`:944`、`:970-973` |
| 返回体 | `returndata.host_data` + `server_data`、`custom_field_data[]`、`module_button`、`module_client_area`、`module_chart`、`module_client_main_area`、`module_power_status`、`reinstall_random_port`、`reinstall_format_data_disk` | `HostController.php:988-1010` |
| 链式行为 | 本机 `api_type=="zjmf_api"` 时再向上游发一次 `/host/header`，本机无 `dedicatedip` 则用上游字段回填本机 `host` | `HostController.php:1013-1028` |

**`cart/hostinfo`**：发送端 `upstreamHost()` `app/admin/controller/ZjmfFinanceApiController.php:1041-1051`，`$post_data=["hostid"=>$param["hostid"]]` → `zjmfCurl($id,"cart/hostinfo",$post_data,30,"GET")`（`:1046`），200 透传 `data`，否则 `400 接口请求失败`。接收端 `app/home/controller/CartController.php::hostInfo()`（`:344-375`）：入参 `hostid`（**数组**，`is_array` + `whereIn`，`:348`）、可选 `all`（`:359`）；返回字段 `id,productid,domain,dedicatedip,assignedips,create_time,nextduedate,billingcycle,firstpaymentamount,amount,port,username,password,initiative_renew,domainstatus` + `billingcycle_zh`、`domainstatus_zh=config("public.domainstatus")`、`assignedips`→数组、`password` 解密（`:349-357`、`:352`）；返回体 `jsons(["status"=>200,"msg"=>…,"data"=>["hosts"=>[],"currency"=>…]])`（`:373-374`）。

**两者差异**（勘误：`domainstatus` 字典写法不同）：

| 维度 | `host/header` | `cart/hostinfo` |
|---|---|---|
| 语义 | 单台主机**详情页**全量 | 多台主机**列表**精简 |
| 入参 | `host_id` + `source=API` + 可选 `nat`（`HostController.php:910,1016`） | `hostid[]` + 可选 `all`（`CartController.php:348,359`） |
| 返回根 | `data.host_data` + `server_data`/`module_*`（`HostController.php:988-1010`） | `data.hosts[]` + `data.currency`（`CartController.php:373`） |
| 字段量 | 约 50 字段，含 `bwlimit/bwusage`、`cancel_control`、`module_button`、密码规则 | 18 字段，含 `*_zh` 中文映射 |
| 状态字典 | `config("domainstatus")`（`HostController.php:944`） | `config("public.domainstatus")`（`CartController.php:352`） |
| 附带动作 | `source=API` 触发 `apiResourceLog`（`HostController.php:906-908`） | 无 |

### 6.2 老版站际主机运维能力全集

**统一分发骨架（`api_type` 六分支，模板 `app/common/logic/Host.php:748-829`）**：

| 分支 | 走向 | 行号 |
|---|---|---|
| `zjmf_api` | 调上游财务站 `/provision/default`（JWT） | `Host.php:783-787` |
| `resource` | `resourceCurl($host["productid"],"/provision/default",…)` | `Host.php:792-798` |
| `whmcs` | `whmcsCurlPost($id,"on")` | `Host.php:799-800` |
| `manual` | `new UpperReaches()` 直连被控端 | `Host.php:788-791`、`:1392-1395`、`:1759-1762` |
| `server_group` 非空 | `Dcim`/`DcimCloud`/`Provision` 直连 | `Host.php:801-812`、`:1403-1432` |
| 皆非 | `["status"=>200]` 空操作 | `Host.php:813-815` |
| 前置覆盖 | 布尔判定为真时强制走历史版本路径（已收敛，见附录 Z） | `Host.php:324-325`、`:649-650`、`:1377-1378`、`:1860-1861` |

方法全集（`app/common/logic/Host.php`）：`create`:20、`createFinal`:28、`suspend`:285、`unsuspend`:396、`terminate`:511、`sync`:625、`on`:748、`off`:830、`reboot`:912、`hardOff`:994、`hardReboot`:1070、`vnc`:1146、`reinstall`:1224、`status`:1354、`changePackage`:1446、`crackPass`:1714、`panel`:1802、`renew`:1836、`createTicket`:1939、`replyTicket`:1964、`rescueSystem`:2008。

**调用上游财务站 `POST /provision/default`**（`zjmf_api` / `resource` 双发）：统一 `$post_data=["id"=>host.dcimid, "func"=><动作>, "is_api"=>1]` + 动作专属参数；返回判定统一 `status=="success" || ==200`（`Host.php:366,734,816,1786,1917,2076`），失败一律 `406` + `module_res.msg`。

| 能力 | 方法 | `func` / 附加参数 | 老版行号 | 资源线行号 |
|---|---|---|---|---|
| 暂停 | `suspend` | `suspend` + `reason`(默认「代理商暂停」) | `Host.php:327-332` | `:333-340` |
| 解除暂停 | `unsuspend` | `unsuspend` | `Host.php:437-440` | `:442-446` |
| 开机 | `on` | `on` | `Host.php:783-787` | `:792-798` |
| 关机 | `off` | `off` | `Host.php:866-868` | `:875-879` |
| 重启 | `reboot` | `reboot` | `Host.php:948-950` | `:957-961` |
| 强制关机 | `hardOff` | `hard_off` | `Host.php:1030-1032` | `:1035-1039` |
| 强制重启 | `hardReboot` | `hard_reboot` | `Host.php:1106-1108` | `:1111-1115` |
| VNC | `vnc` | `vnc` | `Host.php:1160-1162` | `:1169-1173` |
| 重装 | `reinstall` | `reinstall` + `os`(上游镜像 id)、`port`、`format_data_disk` | `Host.php:1284-1291` | `:1301-1309` |
| 状态 | `status` | `status` | `Host.php:1381-1384` | `:1397-1401` |
| 改密 | `crackPass` | `crack_pass` + `password` | `Host.php:1753-1758` | `:1763-1768` |
| 救援系统 | `rescueSystem` | `rescueSystem` + `system` | `Host.php:2037-2042` | `:2043-2050` |

**生命周期专用 path**：

| 能力 | path / 参数 | 行号 |
|---|---|---|
| 终止 | `/host/cancel` POST `id=dcimid,type="Immediate",reason="立即删除"` | `Host.php:542-546`；资源线 `:547-553` |
| 续费 | `/host/renew` POST `hostid=dcimid,billingcycles`；成功后 `/apply_credit`(`invoiceid,use_credit=1`)，`status==1001` 折算 200 | `Host.php:1863-1878`；资源线 `:1879-1898` |
| 拉取/同步 | `/host/header` GET `host_id=dcimid`；写 `dedicatedip,assignedips,domain,bwlimit,bwusage,username,password,port,os`，仅 `Pending→Active` 改状态 | `Host.php:652-669`；资源线 `:670-689`；续费后 `:1919-1928` |
| 升降级 | `/upgrade/upgrade_config_post`、`/upgrade/checkout_config_upgrade`、`/upgrade/upgrade_product_post`、`/upgrade/checkout_upgrade_product`、`/upgrade/upgradehost` + `/apply_credit` | `Host.php:1545-1547`、`:1568-1575`、`:1698` |
| 开通下单 | `/user_info`、`/cart/clear`、`/cart/add_to_shop`、`/cart/settle`、`/apply_credit`、`/apply_credit_limit`，post 带 `downstream_url/token/id` | `Host.php:64,78-82,96,123,136,150,158,167-169` |
| 产品/配置 | `cart/all`、`cart/get_product_config`、`api/product/proinfo`、`api/product/prodetail`、`resource/rate`、`resource/products` | `app/zjmf.php:46,56,62,68,78,42,44` |

**`whmcsCurlPost()` —— 第三种对接形态（勘误 19）**：按 `host.id` 查 `products.zjmf_api_id` 取上游账号，从 `customfieldsvalues`(`fieldname=hostid`) 取上游主机号 `$up_id`（`app/zjmf.php:485-495`、`:489`），目标 `hostname + "/modules/addons/idcsmart_api/api.php?action=/v1/hosts/".$up_id."/module/".$action`（`:490`）；鉴权 `["apiname"=>username, "apikey"=>aesPasswordDecode(password)]`，**明文 apiname/apikey、非 JWT**（`:491-493`）。形态属「**调用上游 WHMCS 财务站**」插件（`api_type=="whmcs"`，`Host.php:799-800,690-705`），**不是直连被控端**；`modules/addons/idcsmart_api/api.php` 文件本体在本解码产物中「**未在代码中确认**」。调用点（`action` → 行号）：`Host.php` `on`:800、`off`:882、`reboot`:964、`vnc`:1176、`repassword`:1770；`app/common/logic/Dcim.php` `on`:172、`off`:238、`reboot`:304、`traffic`:371、`bmc`:603、`vnc`(120s):665、`repassword`:897、`reinstall`(120s):1026、`status`:1577；`app/home/controller/HostController.php` `os`:1289。`Host::sync()` 的 whmcs 分支**不用**此函数，直接 `commonCurl` 到 `...?action=/v1/hosts/{up_id}`（无 `/module/`）拉全量（`Host.php:690-705`）。

**`manual` 直连被控端**：IPMI `commonCurl("http://public.api.idcsmart.com/v1/".$app,…)`，path `ipmi/status`、`ipmi/on`、`ipmi/off`、`ipmi/reboot`、`vnc`（`app/common/logic/UpperReaches.php:354-358,297,310,322,334,346`）；DCIM client `$re["dcim_client_url"]."/index.php?a=api&id=..."` + `func=on/off/reboot/novnc/refreshPower/reloadSystem/crackpwd/getReinstallList/cancelReinstall/getOs`（`UpperReaches.php:359-628`）；后台入口 `app/admin/controller/UpperReachesController.php:559-672`、`:678-904`。服务器组直连走 `Dcim`/`DcimCloud`/`Provision`（`nokvm` 走 `Provision`，`app/common/logic/Provision.php:886`），行号 `Host.php:801-812,1403-1432,1771-1782`。佐证：`app/api/controller/HostController.php:370` 的 `execProvision` 黑名单列出老版模块函数全集（`createaccount,suspendaccount,unsuspendaccount,terminateaccount,renew,changepackage,on,off,reboot,hardoff,hardreboot,reinstall,crackpassword,rescuesystem,vnc,sync,status,…`）。

> **勘误 12**：`Host::createTicket()`/`replyTicket()` 对 `api_type=="zjmf_api"` 是**空分支**（`Host.php:1947-1948`、`:1978-1979`）；老版站际工单**不走** `Provision` 模块函数，而走 `app/common.php:5522-5625` 的 `/ticket/create`、`/ticket/reply`、`/api/ticket_reply/sync`（§8.5）。
> **未在代码中确认**：`resourceCurl` 定义应在 `app/res/common.php`（`app/admin/controller/ProductController.php:31` 判 `file_exists`），`app/res` 在本解码产物中不存在；所有调用以 `function_exists("resourceCurl")` 守卫（`Host.php:334,442,671`、`app/admin/controller/ClientsServicesController.php:131`）。
> **勘误 20（文件规模）**：`app/zjmf.php` `wc -l` 计 **496**，含末行 `?>` 共 **497**（末行无换行）。

### 6.3 代理商 / 资源线 `resource/*`（21 个 path）

> 本节为 `is_resource=1` 形态的**完整接口清单**。全树 `resource/*` 上游 path 去重共 21 个，分布在 `app/zjmf.php`（2 个）与 `app/admin/controller/AgentController.php`（19 个，`resource/agentproductsarray` 被 4 处复用）。本地树是**消费方**，上游服务端实现**未在代码中确认**；「未在代码中确认」分级：① 本地消费端未读取该键（可反推）；② 上游服务端实现不在本地树（真缺口）。

**调用约定**（决定 HTTP 方法与失败语义）：

| 项 | 规则 | 证据 |
|---|---|---|
| 统一出口 | `zjmfCurl($api_id,$path,$data,$timeout=30,$request="POST")`，第 5 参不传即 **POST** | `app/zjmf.php:185` |
| URL 拼接 | `rtrim($api["hostname"],"/")."/".$path`；带前导 `/` 的 path 会产生 `//`（`AgentController.php:86` 的 `/resource/agentinfo`） | `app/zjmf.php:193,209` |
| 鉴权 | 每次先 `/resource_login` 取 jwt（`type=agent`），`Authorization: Bearer {jwt}`；405 强制重登一次并重放 | `app/zjmf.php:194-208,210-222` |
| 带附件 | `zjmfCurlHasFile` → `curlHasFile`，固定 POST，`multipart/form-data` | `app/zjmf.php:225-255,258,264` |
| GET / POST 编码 | `http_build_query` 拼 query / 表单编码（**非 JSON**） | `app/zjmf.php:370-383,398-405` |
| HTTP 层失败 | 非 HTTP 200 → `{status:400, msg:"请求失败,HTTP状态码:x", http_code, content}` | `app/zjmf.php:426-433` |
| 账号目标 | 所有 `AgentController` 方法取 `zjmf_finance_api` 中 `is_resource=1 AND is_using=1` 且 `order("id","desc")->find()` 的**唯一在用**记录 | `app/admin/controller/AgentController.php:107`（同类重复于 `:319,327,406,466,474,492,512,536,578,586,593,601,635,643,666,752,854,886,952,986,1024`） |

**`app/zjmf.php` 的 2 个 path**：

| 方法 | 完整 path | 入参 | 返回结构（本地可见） | 调用它的本地方法 | 失败处理 |
|---|---|---|---|---|---|
| GET | `resource/products` | 无（`app/zjmf.php:48`） | `data.products[]`、`data.currency`（`app/zjmf.php:50`；消费端 `ProductController.php:830,837`、`ProductModel.php:223`） | `getZjmfUpstreamProducts()` `app/zjmf.php:38-53` | `status!=200` 返回 `[]`（`app/zjmf.php:49-52`）；消费端 `$list["products"] ?? []` 兜底 |
| GET | `resource/rate` | `pid`（`app/zjmf.php:69`） | `data.rate`（`app/zjmf.php:71`；消费端 `$res["rate"]`，`ProductModel.php:218,519`） | `getResourceRate()` `app/zjmf.php:66-74` | `status!=200` 返回 `[]`（`app/zjmf.php:70-73`）；消费端 `$res["rate"] ? floatval(...) : 1` |

**`app/admin/controller/AgentController.php` 的 19 个不同 path**：

| 方法 | 完整 path | 入参字段 | 返回结构（本地可见） | 本地方法 | 失败处理 |
|---|---|---|---|---|---|
| POST | `/resource/agentinfo` `:86` | `{hostname, admin_url}`（`:85`） | **未在代码中确认**（`$res` 未被读取，见 §12 D1） | `postLinkToResource()` `:63-104` | `:87` 判的是本地 `$result`，不判 `$res` |
| GET | `resource/agentproductsarray` `:109,754,856,888` | 无 | 顶层 `currency{prefix,suffix}`（`:111,281`）；`data[]` 每项 `pid/username/percent/current_rate`（`:112-115`） | `getProducts()` `:105-316`、`getConsumption()` `:749-829`、`getIncome()` `:851-882`、`getHostLists()` `:883-912` | 失败原样 `jsonrule($res)`（`:314,828,881,911`） |
| GET | `resource/agentorderssearch` `:322` | 前端 `param` 原样 | `data`（`:323` 取 `$res["data"]` 重包 200） | `getOrderSearchPage()` `:317-324` | 不判 `status`，恒返回 200（`:323`） |
| GET | `resource/agentorders` `:330` | 前端 `param` | `data.rows[]`（`:335`）：`hosts[].hostid`（`:336`）、`invoice_type`（`:338,372,374`）、`amount`（`:375,387`）、`current_rate`（`:375,388`）、`agent_grade`（`:375`）、`prefix/suffix`（`:391`） | `getOrder()` `:325-400` | `status!=200` 时 `jsonrule($res)`（`:399`） |
| GET | `resource/agentrenew` `:409` | 前端 `param` | `data.rows[]`（`:413`）：`hostid`（`:414`）、`amount`（`:452`）、`current_rate`（`:455`）、`prefix/suffix`（`:458`） | `getRenew()` `:404-463` | `jsonrule($res)`（`:462`） |
| GET | `resource/afterSaleDetail` `:469` | 前端 `param` | **未在代码中确认**（`:470` 原样透传） | `getAfterSaleDetail()` `:464-471` | `jsonrule($res)`（`:470`） |
| GET | `resource/refundDetail` `:477` | 前端 `param` | `data.refund{hostid, vhostid, amount, subtotal, agent_amount}`（`:479-485`） | `getRefundDetail()` `:472-489` | `jsonrule($res)`（`:488`） |
| GET | `resource/agenthosts` `:495` | 前端 `param` | `data.rows[]`（`:498`），每行 `id`（上游 host id，`:499`） | `getHost()` `:490-508` | `jsonrule($res)`（`:507`） |
| GET | `resource/resourceinspectionlists` `:514` | 前端 `param` | **未在代码中确认**（`:515` 原样透传） | `getInspectionLists()` `:509-516` | `jsonrule($res)`（`:515`） |
| POST | `resource/resourceinspection` `:560`(HasFile)/`:562` | `{police, agency, ip, orderid, email, phone}`（`:539`）；文件 `police_card`(`:548`)、`law`(`:557`) | 仅判 `status==200`（`:564`） | `postResourceInspection()` `:534-574` | 上传失败 `status=400 "文件上传失败"`（`:545,554`）；上游失败 `400`+`$res["msg"]`（`:573`） |
| GET | `resource/resourceinspectionip` `:580` | 前端 `param` | **未在代码中确认**（`:581` 原样透传） | `getInspectionIp()` `:575-582` | `jsonrule($res)`（`:581`） |
| GET | `resource/resourceinspectiondetail` `:588` | 前端 `param` | **未在代码中确认**（`:589` 原样透传） | `getInspectionDetail()` `:583-590` | `jsonrule($res)`（`:589`） |
| POST | `resource/resourcerefundfromagent` `:596` | 前端 `param` 原样 | **未在代码中确认**（`:597` 原样透传） | `postRefund()` `:591-598` | `jsonrule($res)`（`:597`） |
| POST | `resource/resourceaftersalefromagent` `:618`(HasFile)/`:620` | 前端 `param`，`agent_img[]` 抽为文件字段（`:605-616`） | 仅判 `status==200`（`:622`） | `postAfterSale()` `:599-632` | 上传失败 `400`（`:610`）；上游失败 `400`+`$res["msg"]`（`:630`） |
| POST | `resource/resourceunaftersalefromagent` `:638` | 前端 `param` | **未在代码中确认**（`:639` 原样透传） | `postUnAfterSale()` `:633-640` | `jsonrule($res)`（`:639`） |
| GET | `resource/agentbaseinfo` `:645` | 无 | `data{pids[], total, month, grade, refunded}`（`:647-655`） | `getBaseInfo()` `:641-663` | `jsonrule($res)`（`:662`） |
| GET | `resource/agentcredit` `:656` | 无 | `data.client`（`:658`） | `getBaseInfo()` `:656-659` | 失败被静默忽略（`:657-659` 无 else 分支） |
| GET | `resource/agentlogs` `:669` | 前端 `param` | **未在代码中确认**（`:670` 原样透传） | `getAgentLogs()` `:664-671` | `jsonrule($res)`（`:670`） |
| POST | `resource/evaluation` `:1041`(HasFile)/`:1043` | 前端 `param`，`img[]` 抽为文件字段（`:1028-1039`） | 仅判 `status==200`（`:1045`） | `postEvaluation()` `:1022-1055` | 上传失败 `400`（`:1033`）；上游失败 `400`+`$res["msg"]`（`:1053`） |

**同链路但非 `resource/` 前缀的 3 个 path**（补全，避免清单被误读为完整闭环）：

| 方法 | path | 入参 | 用途 / 返回 | 本地方法 | 证据 |
|---|---|---|---|---|---|
| POST | `/resource_login` | `{username, password(解密), type:"agent"}` | 取 jwt | `postLinkToResource()` | `app/admin/controller/AgentController.php:69-73` |
| GET | `/cart/all` | 无 | 取 `data.count` 写本地 `product_num` | `postLinkToResource()` | `app/admin/controller/AgentController.php:81-84` |
| GET（拼接，非 curl） | `/resource/shop/login?from=...&token=...&time=...` | `from`、`token`(12 位,缓存 300s)、`time` | 仅返回给前端做跳转 URL，**不发起上游调用** | `getToken()` | `app/admin/controller/AgentController.php:1056-1066`；token 校验 `app/admin/controller/PublicController.php:945-959` |

**`resource_login` 完整逻辑**：凭据 `username` + `aesPasswordDecode($api["password"])` + 固定 `type:"agent"`（密码列在 `zjmf_finance_api.password`，`app/zjmf.php:196`、`AgentController.php:71`）；jwt 走 `zjmfApiLogin($id,$login_url,$login_data)` 取 `$res["jwt"] ?? $res["data"]["jwt"]`、缓存 `zjmf_finance_jwt_{id}`、`Cache::set(...,0)` 永不过期（`app/zjmf.php:348-365`）；405 强制重登一次（`app/zjmf.php:210-222`）；**不判断 `judgeApiIs()`**（`app/zjmf.php:194-203`），与客户密钥通道隔离；6 处登录 URL 见 §1.2。工单投递差异：`ticketDeliver` 中 `is_resource==1` 额外要求 `ticket_open` 非空，否则 `return false`（`app/common.php:5532-5543`）；老版下单交叉点：`is_resource==1` 时给 `/cart/settle` 注入 `agent_client=1`（`app/common/logic/Host.php:129-136`）。

**`is_resource` 优先级 4 处判定**：见 §0.3（目录 `app/zjmf.php:41-47`、登录 `app/zjmf.php:194-203`、带附件 `app/zjmf.php:229-235`、连接测试 `app/admin/controller/ZjmfFinanceApiController.php:230-241`）。补充：`app/zjmf.php:25-26` 的 `getAddressByApiType()` 对 `$api_type=="resource"` 是**空分支**（后台按 API 类型取服务器组下拉不覆盖资源形态）。

**四张表字段用途**（人工资源，属老版/`manual` 形态，**不是** `is_resource=1` 资源池）：

| 表（`shd_` 前缀） | 字段 | 用途 | 证据 |
|---|---|---|---|
| `shd_upper_reaches` | `id, name, phone, bz, create_time` | **手工录入的上游供应商档案**（名称/联系方式/备注），非 API 账号 | `public/install/thinkcmf.sql:3419-3427`；`app/admin/controller/UpperReachesController.php:63-64` |
| `shd_upper_reaches_res` | `id, ip, pid, pz, create_time, ipmi, root, pwd, total(成本), hid(云主机id), paid_time, update_time, in_ip`；ALTER 增 `ipmi_version, power_status, control_mode, dcim_client_url, dcim_client_id, username, password, mark` | **手动资源池单条物理机**：`pid` 指向上游档案、`hid` 关联本地 host、`control_mode` 决定电源按钮 | `thinkcmf.sql:3437-3453`；ALTER `:4033-4035,4050-4051,4124-4125,5906`；`UpperReachesController.php:306-311` |
| `shd_upper_reaches_ip` | `id, ip, resid` | 手动资源的**附加 IP 列表**，`resid`→`upper_reaches_res.id` | `thinkcmf.sql:3429-3435`；`UpperReachesController.php:313-317` |
| `shd_upper_manual_info` | `id, hid(唯一), regate(到期), amount, billingcycle, dedicatedip, assignedips, create_time` | **手动资源的上游账单快照**，与 `is_resource` 无关 | `thinkcmf.sql:5621-5633`；`app/admin/controller/ZjmfFinanceApiController.php:1253-1274` |

> `UpperReachesController.php:42,45,153,166,179,218,226,332` 的查询全部带 `where("is_resource",0)`；`is_resource=1` 的资源池账号存在 `shd_zjmf_finance_api`（`app/admin/controller/AgentController.php:31-43`），二者**不是同一条线**。该文件全 911 行**无任何 `resource/*` 上游 path**，电源操作全部针对本地手动资源（出站仅 `ipmi/*`、`vnc` 打向 `http://public.api.idcsmart.com/v1/`，`app/admin/controller/UpperReachesController.php:571,593,617,641,665,675`，与 DCIM client `:689,712,737,762,787`）。

**代理商线是否支持电源类操作：无**（本地源码证据链）：

| # | 证据 | 位置 |
|---|---|---|
| 1 | `AgentController.php` 全 1068 行的 21 个 `resource/*` path 中，无任何 `power/on/off/reboot/status/vnc/hard_*` 类接口 | `app/admin/controller/AgentController.php:86,109,322,330,409,469,477,495,514,560,562,580,588,596,618,620,638,645,656,669,754,856,888,1041,1043` |
| 2 | `zjmfCurl`/`commonCurl` 通用封装不含电源 path | `app/zjmf.php:185-224,366-435` |
| 3 | 「上游资源管理」电源方法全部以 `upper_reaches_res` 表为依据，且查询显式排除 `is_resource=1` | `app/admin/controller/UpperReachesController.php:559-672,678-805`；`where("is_resource",0)` 见 `:42,45,153,166,179,218,226,332` |
| 4 | 电源状态只写本地 `upper_reaches_res.power_status`，不经任何上游 API | `UpperReachesController.php:574,577,596,620,644,694,697,717,742,767`；字段 `thinkcmf.sql:4034,4935` |
| 5 | 电源请求打向固定公共服务或 DCIM 客户端地址，与 `zjmf_finance_api.hostname` 无关 | `UpperReachesController.php:675`、`:689,712,737,762,787` |

> 保留原文档 §0 形态表「资源/代理商 … **无电源类接口**」判定，本节补上本地源码证据链。

---

## 7. 账户余额

**接口总表**（老版账户 / 余额接口）：

| 接口 | 方法 | 方向 | 参数 | 返回要点 | 位置 |
|---|---|---|---|---|---|
| `GET /cart/credit` | GET | 入站（本站=上游）/ 出站探活 | 无 | `data.credit`、`data.currency{id,code,prefix,suffix}` | `app/home/controller/CartController.php:328-335` |
| `GET /cart/summary` | GET | 入站 | 无 | 见 §7.2 专表 | `app/home/controller/CartController.php:389-425` |
| `GET cart/hostinfo` | GET | 出站（后台查上游主机） | `hostid`（可数组） | `data.hosts[]` + `data.currency` | `app/admin/controller/ZjmfFinanceApiController.php:1041-1051`；`CartController.php:344-375` |
| `POST /apply_credit` / `/apply_credit_limit` | POST | 双向（支付动作） | 见 §5.4 | `1001` 全额付清 / `200` 部分 / `400` 不足 | `app/home/controller/PayController.php:259-344`、`:345-435` |

### 7.1 `GET /cart/credit`（`app/home/controller/CartController.php:328-335`）

| 字段 | 值 | 行号 |
|---|---|---|
| `data.credit` | `clients.credit`（`uid=request()->uid`，`decimal(12,2)`，JSON 中为**字符串**） | `:330-331` |
| `data.currency` | `getUserCurrency($uid)` → `currencies{id,code,prefix,suffix}`，无币种回落 `default=1` | `:332`；`app/common.php:1037-1048` |
| 返回封装 | `jsons(["status"=>200,"msg"=>SUCCESS,"data"=>$data])`（多 `is_aff` 顶层键） | `:334`；`app/common.php:2395-2402` |
| 错误分支 | **无任何业务错误分支**（无 uid / `api_open` / `allow_resource_api` 校验）；`uid=0` 时 `credit=null` | `:328-335` |

```json
{ "status":200, "msg":"请求成功",
 "data": { "credit":"1234.56",
 "currency": { "id":1, "code":"CNY", "prefix":"￥", "suffix":"" } },
 "is_aff":"..." }
```

### 7.2 `GET /cart/summary` 返回体逐字段（`app/home/controller/CartController.php:389-425`）

| 路径 | 类型 | 含义 | 行号 |
|---|---|---|---|
| `data.client.api_password` | string | ⚠️ **明文 API 密钥**：`aesPasswordDecode()` 后原样返回（缺陷，见 §12 #14） | `:392`、`:396` |
| `data.client.api_open` / `lock_reason` / `api_lock_time` / `api_create_time` | int/string | 0/1/2、锁定原因/时间、开通时间 | `:392` |
| `data.client.agent_count` | int | `api_resource_log` 中 `pid<>0` 的 distinct 计数 | `:397`、`:400` |
| `data.client.host_count` / `active_count` | int | 带 `downstream_url` 的主机数 / 且 `domainstatus=Active` | `:398-402` |
| `data.client.api_count` | int | 「今日」调用量（变量 `yesterday_start` 实为今日 0 点） | `:403-406` |
| `data.client.ratio` | string | `bcdiv(今日,昨日,2)*100 . "%"` | `:410-411` |
| `data.client.up` | int | 前日比值 ≤ 今日比值 → 1（第二处 `bcdiv` 在 `:415`） | `:412-419` |
| `data.form_api` | int[7] | 近 7 天每日调用量 | `:420`、`getEveryDayTotal():426` |
| `data.free_products[]` | object | `{id,name,ontrial,qty}`（`api_user_product`） | `:421` |

> **勘误 14**：唯一错误 `allow_resource_api==0 || api_open==0` → `400 "暂未开通API功能"`（`:393-395`）；判定是 **`==0` 而非 `!=1`**，故锁定态 `api_open=2` **通过**；与 §1.1 三处口径差异对齐。**带 token 才返回全量 `data.client.*`，不带 token → `uid=0` → 400**。
> ⚠️ 两个缺陷：① `api_password` 明文外泄（`:396`，明文不写入本文）；② `bcdiv($api_count,$api_count2,2)` 未防除零（`:410`、`:415`，分母 `$api_count2` 昨日计数 `:409` 为 0 时触发）。PHP 7.2/7.3 下 `bcdiv(...,0)` 为 Warning+`NULL`、PHP 8 抛 `DivisionByZeroError`，旧文档「`INF%`」**未在代码中确认**，须实例实测。

### 7.3 健康检查选哪个

`cart/summary` 会返回明文密钥且带 `bcdiv` 风险；`cart/credit` 最轻量、无副作用、无业务错误分支。做探活请用 `GET /cart/credit`（`app/home/controller/CartController.php:328-335`）。

---

## 8. 下游回传（登记 / 状态推送 / 工单跨站）

> 回传视角下**本站=上游**：主机状态变化或工单回复时，本站主动调下游的 `api/host/sync`、`api/ticket_reply/sync`。三条回传/模块入口路由（`data/route/api.php:18-20`）**不经 `ApiCheck`**，仅靠请求体 `signature` 自验签（§1.5）。

### 8.1 登记接口（实为三条，勘误 10）

| # | 入口 | 方向 | 参数 | 校验 | 行号 |
|---|---|---|---|---|---|
| 1 | `POST /host/setdownstream`（老版换绑） | 本站作下游 → 上游 | `id`=新 `dcimid`、`pid`=`upstream_pid`、`downstream_url`=`request()->domain().rootUrl()`、`downstream_token`=`md5(randStr(16).time().$hostid)`(32 位)、`downstream_id`=本机 `hostid` | **无签名**；`uid` 归属 + `productid==pid` 两个前置校验 + **4 项格式校验** | 发送 `app/admin/controller/ClientsServicesController.php:418-423`；接收 `app/home/controller/HostController.php:2220-2248` |
| 2 | 下游站下单带 `downstream_*`，由上游站 `settle` 写入 | 本站作上游 | `downstream_url/token/id` 三元组 | `url` 以 http(s):// 开头 && `strlen(token)==32` && `is_numeric(downstream_id)` | `app/home/controller/CartController.php:2410-2412`、`:2546-2550`；同式 `PayController.php:276`、`CartController.php:3292`、`app/openapi/controller/CartController.php:1210`、`app/openapi/controller/PayController.php:118` |
| 3 | 支付回调 / openapi 同构写入 | 本站作上游 | `downstream_url/token/id` 三元组 | `url` 以 http(s):// 开头 && `strlen(token)==32` && `is_numeric(downstream_id)` | `PayController.php:327-332,424-429`；`app/openapi/controller/CartController.php:1345-1347`；`app/openapi/controller/PayController.php:172-174,288-290` |

> **勘误 9（`host/setdownstream` 校验项数与键名）**：「无签名」属实，但**不止 4 项**——另有 `uid` 归属（`HostController.php:2222-2234`，否则 `406 产品ID错误`）、`productid==pid`（否则 `406 不是同一商品`）两个前置校验；**4 项格式校验实际在 `app/home/controller/HostController.php:2235`**（`downstream_url` 前缀、`strlen(downstream_token)==32`、`is_numeric(downstream_id)`），方法起点 `:2220`；旧文档标 `HostController.php:2232` 是「不是同一商品」的 msg 行，且本树有 4 个同名 `HostController.php`，须写明 `app/home/controller/`。写入 `stream_info` 三键 → `Db::name("host")->update`（`:2236-2246`），`200 关联成功` / `400 参数错误` / `406 产品ID错误|不是同一商品`。路由 `data/route/home.php:181` 是 `Route::controller("host","home/Host")`，`postSetDownStream` **由控制器自动映射**（无显式单行注册）。
> **老版键名是 `downstream_id`**（`app/zjmf.php:137`、`app/home/controller/HostController.php:2239`）；`downstream_host_id`/`downstream_client_id`/`downstream_system_type` 属历史版本键（见 `app/admin/controller/ClientsServicesController.php:408`），**老版不存在**。另有两处等价写入：`app/admin/controller/ClientsServicesController.php:922-927`、`app/home/controller/PayController.php:424-429`。
> 老版登记只走 `POST /host/setdownstream` 与下单式回调写入（§8.1）。

**三键落表位置（双向 token 同值）**：

| 键 | 表/字段 | 说明 |
|---|---|---|
| `downstream_url`/`downstream_token`/`downstream_id` | `shd_host.stream_info`(JSON) | **上游站持有**，供 `pushHostInfo`/`pushTicketReply` 定位与签名（`app/zjmf.php:127,135,143-144`） |
| `token` | `shd_host.stream_info`(JSON) | **下游站持有**，与上游 `downstream_token` **同值**，供 `syncInfo`/`syncTicketReply` 验签（`app/admin/controller/ClientsServicesController.php:428`、`app/api/controller/HostController.php:144,274`） |
| `upstream_pid`/`zjmf_api_id`/`dcimid` | `shd_products`/`shd_host` | 上游产品 id / 账号 id / 上游主机 id（`ClientsServicesController.php:418-419`、`app/common/logic/Host.php:328`） |

`is_downstream` 三种语义（老版）：① 下单参数布尔（上游站视角，`CartController.php:2410-2412,2537-2550`）；② 商品列表标记 `$v["upstream_product"]["is_downstream"]=1`（`app/common/logic/Shop.php:626`、`CartController.php:2057`）；③ 统计口径 `host.stream_info LIKE %downstream_url%`（`app/admin/controller/ZjmfFinanceApiController.php:270-271`、`CartController.php:398-399`）。

### 8.2 状态推送 `pushHostInfo()`（发送端，`app/zjmf.php:120-163`）

| 步骤 | 内容 | 依据 |
|---|---|---|
| 签名/字段 | `pushHostInfo($id,$other_field="",$type="")`；基础字段 `id,productid,domain,username,password,dedicatedip,assignedips,port,os,os_url,stream_info,nextduedate,domainstatus`（**无 `suspendreason`、无 `bwusage/bwlimit`**）；`$other_field` 逗号拼入 | `app/zjmf.php:120`、`:122-125` |
| 取数/回调 | `Db::name("host")->field($field)->find()`；`json_decode(stream_info)` → `downstream_url/token/id` | `app/zjmf.php:126-127`、`:135`、`:137` |
| 幂等 | `type=="create"` 且 `shd_zjmf_pushhost` 已有 `host_id` → 跳过（`empty($pushhost)`） | `app/zjmf.php:130-132`、`:135` |
| 改写 | `unset(stream_info)`、`unset(productid)`；`host_id=$host["id"]`(本机)、`id=downstream_id`(下游) | `app/zjmf.php:133-137` |
| 密码 | `password=cmf_decrypt($host["password"]) ?: ""`；`(new Dcim())->getPanelPass($id,$pid)` 非 false 追加 `ippassword` | `app/zjmf.php:138-142` |
| URL/签名/取舍 | `rtrim(downstream_url,"/")."/api/host/sync"`；`createSign(["id"=>$host["id"]],downstream_token)`；`nextduedate` 空则 unset；`type` 非空则加 | `app/zjmf.php:143-150` |
| 发送/落表/返回 | `commonCurl($url,$post_data,30)`；仅 `type=="create"` 写 `shd_zjmf_pushhost`；`return $res` | `app/zjmf.php:152-161` |

### 8.3 `shd_zjmf_pushhost` 失败重放与推送触发点

| 项 | 内容 | 依据 |
|---|---|---|
| 写入字段 | `host_id,url,status,post_data(json_encode($post_data)),time,num=1` | `app/zjmf.php:155`、`:157`、`:159` |
| status 判定 | `res.status==200 或 ==400` → `1`（视为已送达），否则 `0` | `app/zjmf.php:154-158` |
| 重放 | `Cron::hostInfo()` 取 `status=0 AND num<5`，用存量 `url`+`json_decode(post_data)` 重发；200→`1`否则`0`，`num+1` | `app/admin/command/Cron.php:264-273` |
| 调度/上限 | `fiveMinuteCronJob()` 每 5 分钟调 `hostInfo()`；`num<5` 即最多 5 轮 | `app/admin/command/Cron.php:52-55`、`:262`、`:264` |
| 同方法内 | 另处理 `Pending && api_type=resource && regdate<time()-300`：`resourceCurl("/host/header")` 拉到 `Active` 回填并 `pushHostInfo` | `app/admin/command/Cron.php:274-299`、`:281`、`:298` |

> 「返回 200 或 400 都视为已送达」属实（`app/zjmf.php:154`）；补：`commonCurl` 非 200 HTTP 时返回 `status=400`（`app/zjmf.php:429-433`），故「400=已送达」**包含网络/HTTP 失败**，是刻意的重放抑制。

**推送触发点全表**：

| 触发动作 | 调用 | 依据 |
|---|---|---|
| 开通成功/失败 | `pushHostInfo($id,"domainstatus","create")` | `app/common/logic/Host.php:271`、`:278` |
| 暂停（仅 `reason_type=="flow"`）/ 解除暂停 | `pushHostInfo($id,"domainstatus,suspendreason")` | `Host.php:384`、`:501` |
| 终止成功（随后 `stream_info=""`） | `pushHostInfo($id)` | `Host.php:608-609` |
| 同步/重装/升降级成功 | `pushHostInfo($id)` | `Host.php:739`、`:1340`、`:1704` |
| 后台「推送信息」按钮 | `case "pushHostInfo"`（按钮注册 `app/admin/controller/ClientsServicesController.php:320`） | `app/admin/controller/ProvisionController.php:225`；`ClientsServicesController.php:311-321` |
| Cron 开通回填/重放 | `pushHostInfo($v["id"])` | `app/admin/command/Cron.php:298` |
| DCIM 直连侧变更后（5 处） | `pushHostInfo($id)` | `app/common/logic/Dcim.php:1014,1075,1543,2459,3506` |
| `editDcimHost`/`freeDcimHost` 接收端改本机后 | `pushHostInfo` | `app/api/controller/HostController.php:68`、`:129` |
| `syncInfo()` 内**级联下推** | `type=create && Active` → `pushHostInfo($id,"domainstatus","create")`；否则 `pushHostInfo($id)` | `app/api/controller/HostController.php:210`、`:229-235`（`:231,233`） |

### 8.4 验签与接收端 `syncInfo()`（`app/api/controller/HostController.php:133-262`）

| 步骤 | 内容 | 依据 |
|---|---|---|
| 入参/签名空 | `id=(int)$params["id"]`(=下游 `downstream_id`)；`signature` 空 → `400 签名错误` | `:135-141` |
| **token 来源** | 查 `host.stream_info` → `$token=$stream_info["token"]`；空 → `400 该产品不能使用该接口`；`params.token=$token`（签名 token **不由请求携带**）；`password` 先 `html_entity_decode` | `:142-151`（取值 `:144`） |
| 验签两支 | ① 布尔判定函数为真 → `$validate=["id","token","rand_str"]` + `validateSign($validate,signature)`；② 否则 `validateSign($params,signature)`；失败 `400 签名验证失败` | `:152-163`（判定函数调用点 `:152`） |
| 两支等价 | `validateSign()` 内部**只取 `id/token/rand_str` 三键**重算，故两支恒等，`$params` 其余字段不参与签名 | `app/zjmf.php:177-184`（`:179`） |
| **逐字段写入** | `$update=[domain, username, password=cmf_encrypt, os, os_url?:"", dedicatedip, assignedips, port(int), **suspendreason**]` → `Db::name("host")->where("id",$id)->update($update)`；`nextduedate` 非空才写；`domainstatus` 白名单 6 值才写；`if(isset(suspendreason))` 再写一次（**冗余**） | `:164-174` |
| `type=="create"` | 本机已 `Active` → 直接 200；`Active` 且原 `Pending` → 欢迎短信/邮件、`active_log_final`、`RunMap::saveMap`、**再次 `pushHostInfo(...,"create")`**；`Pending` → 记「上游开通失败」；其余 `400 更新失败` | `:175-224`（`:210`） |
| 面板密码/级联/日志 | `isset(ippassword)`→`Dcim::savePanelPass()`；自身 `downstream_url` 非空 → 继续下推；`desc_arr` 中文对照 + `config("domainstatus")[值]` + `active_log` | `:225-235`、`:236-258` |
| 返回体 | `200 更新成功`；`400 签名错误/该产品不能使用该接口/签名验证失败/更新失败` | `:139,147,156,161,221,260` |

> 布尔判定函数 `getZjmfApiIdByHostId($id)` 的**定义属非站际对接范围**（见附录 Z），本文只标注调用点（`app/api/controller/HostController.php:152,281`）与「分支效果」，不展开实现。
> **「无条件覆盖 `suspendreason`」缺陷（勘误，真实代码）**：`:164` 构造 `$update` 时即写入 `"suspendreason"=>$params["suspendreason"]`，`:174` 无条件 `update($update)`；而 `pushHostInfo` 基础字段（`app/zjmf.php:122`）**不含 `suspendreason`**，仅 flow 类暂停/解除经 `$other_field` 追加（`Host.php:384,501`）⇒ **任何一次普通推送都把下游 `host.suspendreason` 写为 null/空**；`:171-173` 的 `isset` 保护因 `:164` 已写入完全失效。

**接收端 `syncTicketReply()`**（`app/api/controller/HostController.php:263-356`）：验签与 `syncInfo` 同构（`:265-292`）；内容空 `406 TICKET_REPLY_CONTENT_EMPTY`、`$tid=$params["tid"]` → `Db::name("ticket")->where("upstream_tid",$tid)->where("merged_ticket_id",0)->find()`，空 `406 ID_ERROR`（`:293-305`）；附件 `Upload::moveTo($v,config("ticket_attachments"))` 逐个转存（`:306-312`）；组装 `tid, admin_id, create_time, content=model("Ticket")->parse(...), admin, attachment=implode(","), is_receive=1, source=1`（`:313-322`）；事务更新 `ticket.last_reply_time/client_unread=1/status=2` + 插入 `ticket_reply`，提交后 `pushTicketReply($r)`（`:323-328`、`:336-354`）；返回 `200 TICKET_REPLY_SUCCESS` / `400 签名错误|签名验证失败` / `406 内容空|ID错误|TICKET_REPLY_FAILED`。

**路由与中间件**（`data/route/api.php`）：

| 路由 | 方法 | 指向 | 中间件 |
|---|---|---|---|
| `api/host/sync` | POST | `api/host/syncInfo` | **无**（group 外独立注册）`data/route/api.php:18` |
| `api/ticket_reply/sync` | POST | `api/host/syncTicketReply` | **无** `data/route/api.php:19` |
| `api/exec_module_func` | GET\|POST | `api/host/execProvision` | **无** `data/route/api.php:20` |
| `api/host`、`api/host/free`、`api/host_server` | POST | `editDcimHost`/`freeDcimHost`/`getDcimServerSetting` | `ApiCheck`（`Route::group("api",...)->middleware("ApiCheck")`）`data/route/api.php:13-17` |
| `api/product/proinfo`、`api/product/prodetail` | GET | `api/product/*` | 无 `data/route/api.php:44-45` |

`execProvision` 另有 `$ban` 黑名单（`app/api/controller/HostController.php:370`）与 `module_name` 正则白名单（`:363`）。

### 8.5 工单回传与工单跨站完整链路

**`pushTicketReply()`（`app/zjmf.php:90-119`，本站作上游 → 下游）**：

| 步骤 | 内容 | 依据 |
|---|---|---|
| 取数 | 联表 `ticket_reply a`+`ticket b`+`host c`+`products d`，字段 `a.id as rid,c.password,a.content,c.id,c.stream_info,b.tid,a.attachment` | `app/zjmf.php:92` |
| 前置/附件 | 需 `downstream_url`+`downstream_token`；遍历 `attachment`(逗号分隔) → `curlUpload(rtrim(url,"/")."/upload_image", config("ticket_attachments").$value, 文件名)`，`status==200 && savename` 才收集 | `app/zjmf.php:95,97-108,100-105` |
| 改写/目标 | `id=downstream_id`；`password=cmf_decrypt(...) ?: ""`；`content=html_entity_decode(...)`；`rtrim(url,"/")."/api/ticket_reply/sync"` | `app/zjmf.php:109-111`、`:114` |
| 签名/发送 | `createSign(["id"=>downstream_id],downstream_token)`；`commonCurl($url,$post_data,5)`（**超时 5s**） | `app/zjmf.php:112`、`:116` |
| 后置 | **不检查返回、无条件** `ticket_reply.is_deliver=1`；触发于后台回复 / `syncTicketReply` 落库后 | `app/zjmf.php:117`；`app/admin/controller/TicketController.php:303`、`app/api/controller/HostController.php:328` |

**方向 A：本站作下游 → 上游财务站提交/回复**（`app/common.php`）：

| 环节 | 内容 | 依据 |
|---|---|---|
| `ticketDeliver($id)` | 联表 `ticket_deliver`+`ticket_deliver_products`+`ticket_deliver_department`+`ticket`+`host`+`products`+`ticket_department_upstream`（`g.dptid=c.dptid AND f.zjmf_api_id=g.api_id`）取 `upstream_dptid` | `app/common.php:5522-5527`（`:5527`） |
| 登录/屏蔽/附件 | `is_resource==1`→`/resource_login`(需 `ticket_open`)，否则 `/zjmf_api_login`；`zjmfApiLogin` 取 JWT；`mask_keywords` 命中标题/内容则 `return false`；`curlUpload($url."/upload_image")` 收集 `savename` | `app/common.php:5532-5547`、`:5552-5562`、`:5563-5575`、`:5626-5635` |
| 提交/回写 | POST `/ticket/create`：`dptid,hostid=dcimid,title,content,attachment,priority,is_api=1` + Bearer；成功写 `ticket.upstream_tid=res.data.tid`、`is_deliver=1` | `app/common.php:5576-5580` |
| `ticketReplyDeliver($id)` | 联表条件 `ticket.upstream_tid<>""`（`:5589`）；固定 `/zjmf_api_login`（`:5596`）；附件逐个 `curlUpload` 收集 `savename`；POST `/ticket/reply`：`tid=upstream_tid,content,attachment,is_api=1`；成功写 `ticket_reply.is_deliver=1` | `app/common.php:5584-5623`（`:5605-5617`、`:5618`、`:5620-5623`） |
| 触发点 | 前台/开放接口提交与回复工单 | `app/home/controller/TicketController.php:217,470`；`app/openapi/controller/TicketController.php:159,352` |

**方向 B：本站作上游 → 下游站回传**：`pushTicketReply()`（§8.5）→ `/api/ticket_reply/sync` → `syncTicketReply()`（§8.4），接收端以 `ticket.upstream_tid = $params["tid"]` 定位本机工单（`app/api/controller/HostController.php:299`）。

**部门映射 `shd_ticket_department_upstream`**：字段 `api_id,upstream_dptid,dptid`（`app/admin/controller/TicketDepartmentController.php:76,138`）；新增 `:73-81`、编辑先 delete 再 insertAll `:134-145`、读取 `array_column(...,"upstream_dptid","api_id")` `:380-381`；自动转发还关联 `ticket_deliver`/`ticket_deliver_products`/`ticket_deliver_department`（`app/admin/command/Cron.php:834`、`app/home/controller/TicketController.php:261`）。`/ticket/department` 批量并发 `batch_curl`。`mask_keywords` 拦截、`upload_image`→`savename`、附件 `curlUpload`（`app/common.php:5522-5642`）。

**涉及表**：`shd_ticket`(`upstream_tid,is_deliver,dptid,host_id,merged_ticket_id,status,token`)、`shd_ticket_reply`(`tid,attachment,content,is_deliver,is_receive,source,admin_id`)、`shd_ticket_department_upstream`、`shd_ticket_deliver`/`_products`/`_department`、`shd_ticket_department`/`_admin`/`_status`、`shd_zjmf_finance_api`(`hostname,username,password,is_resource,ticket_open`)、`shd_host`(`stream_info,dcimid,productid`)、`shd_products`(`zjmf_api_id`)。

> **勘误 12（再次确认）**：`Host::createTicket()`/`replyTicket()` 对 `api_type=="zjmf_api"` 为**空实现**（`app/common/logic/Host.php:1947-1948`、`:1978-1979`）；老版站际工单**不走** `Provision` 模块函数，而走 `/ticket/create`、`/ticket/reply`、`/api/ticket_reply/sync` 三条专用 HTTP 接口。

### 8.6 `pushHostInfo` 推送字段 vs 接收端使用字段（不对称清单）

| 字段 | 推送端 | 接收端 `syncInfo` | 结论 |
|---|---|---|---|
| `domain,username,password,os,os_url,dedicatedip,assignedips,port,nextduedate,domainstatus` | `app/zjmf.php:122,145-150` | `app/api/controller/HostController.php:164-170` | 对齐 |
| `suspendreason` | **默认不推**，仅 `$other_field` 追加（`app/zjmf.php:123-125`；`Host.php:384,501`） | `:164` 无条件写、`:174` 无条件 update | **缺陷**（§8.4） |
| `ippassword` | `Dcim::getPanelPass()` 可选（`app/zjmf.php:139-142`） | `:225-228` `Dcim::savePanelPass()` | 对齐（可选） |
| `type` | 非空才加（`app/zjmf.php:148-150`） | `:175,230` 分支判定 | 对齐 |
| `host_id`（本机 id） | `app/zjmf.php:136` | **未使用** | 冗余 |
| `id`(=`downstream_id`) / `signature` / `rand_str` | `app/zjmf.php:137,144,151` | `:136` 定位、`:137-163` 验签 | 定位/签名用，不落库 |
| `productid` / `stream_info` | `unset`（`app/zjmf.php:133-134`） | — | 已剔除 |
| `bwusage` / `bwlimit` | **不推** | 不写（另走 `Host::sync()` 的 `/host/header`，`Host.php:659-660`） | 缺失 |
| `os_url` | 推（`app/zjmf.php:122`） | `:164` 写 `os_url ?: ""` | 但 `Host::sync()` 回填时**未更新 `os_url`**（`Host.php:656-664`）→ 常为空 |

---

## 9. 字段字典

### 9.1 商品与 API 记录（`products` / `zjmf_finance_api`）

> **显式区分**：**商品字段 `products.api_type`** 与 **API 记录字段 `zjmf_finance_api.type`**；形态判定**以后者 + `is_resource` 为准**（§0.3），勿把商品侧 `api_type` 取值误当形态。

| 字段 | 取值 | 含义 | 依据 |
|---|---|---|---|
| `products.api_type` | `zjmf_api` / `manual` / `normal` / `resource` | **商品侧**上游类型 | `public/install/thinkcmf.sql:3526` |
| `products.zjmf_api_id` | int | 指向 `zjmf_finance_api.id` | `thinkcmf.sql:3546` |
| `products.upstream_pid` | int | 上游商品 ID | `thinkcmf.sql:3547` |
| `products.upstream_version` | int | 上游版本号（= 上游 `location_version`） | `thinkcmf.sql:3542` |
| `products.location_version` | int | 本地版本号，本地改配置即 +1 | `thinkcmf.sql:3543` |
| `products.upstream_price_type` | `percent` / `custom` | 加价方式（互斥） | `thinkcmf.sql:3544` |
| `products.upstream_price_value` | decimal | `percent` 时百分比，默认 120 | `thinkcmf.sql:3545` |
| `products.upstream_price` / `upstream_cycle` | — | 历史版本价格/周期字段（表注释标注为历史版本字段），**属排除范围**，见附录 Z | `thinkcmf.sql:5902-5903` |
| **`zjmf_finance_api.type`** | **`zjmf_api` / `manual` / `whmcs`**（收敛：删 `v10`、补回 `whmcs`） | **API 记录侧**上游形态 | `thinkcmf.sql:5620`（默认 `zjmf_api`）；` |
| `zjmf_finance_api.is_resource` | 0/1 | 资源线（优先级最高，**单独维度**） | `thinkcmf.sql:5839` |
| `zjmf_finance_api.is_using` | 0/1 | 资源线全局唯一在用 | `thinkcmf.sql:5844` |
| `zjmf_finance_api.ticket_open` | 0/1 | 工单传递开关 | `thinkcmf.sql:5840` |

**关键表与建表位置**：`shd_products` `thinkcmf.sql:1555-1619`（上游同步 ALTER `:3526,3542-3547,3945,4795,5559,5560,5870,5895,5897`）；`shd_pricing` `:1370-1408`；`shd_product_groups` `:1517-1527`；`shd_server_groups` `:1757-1761`；`shd_zjmf_finance_api` `:3529-3536`；关联辅助 `shd_customfields` `:630`、`shd_product_config_links` `:1432`（`:3549/3550/3588/3589` 各表加 `upstream_id`）。

### 9.2 计费周期 / 价格字段 / `pay_type` 补遗

> **勘误 2 汇总**：`config("billing_cycle")` 是 **18 键**（含 `free`），17 是 `config("price_type")` 键数 / `shd_pricing` 价格字段对数；旧标题「17 个」已改为「`billing_cycle` 18 键 / `price_type` 17 键」。完整 18 键列表、17 对配对表见 §4.5。本节给补遗与例外。

| 项 | 值 | 依据 |
|---|---|---|
| `billing_cycle` / `billing_cycle_unit` / `coupon_cycle` | 三者键集相同、**同为 18 键**；`coupon_cycle` 首键为 `""` 而非 `free` | `app/app.php:26` |
| `shd_pricing` 列组成 | `id,type,currency,relid` 4 元数据列 + **17 初装费** `osetupfee…tensetupfee`（`thinkcmf.sql:1375-1391`）+ **17 周期价** `onetime…tenly`（`:1392-1408`）= **38 列** | `public/install/thinkcmf.sql:1370-1408` |
| 命名例外 | `ontrial→ontrialfee`（非 `ontrialsetupfee`）；`fourly→foursetupfee`（无 `ly`）；`fively/sixly/sevenly/eightly/ninely/tenly` 初装费为 `fivesetupfee/sixsetupfee/sevensetupfee/eightsetupfee/ninesetupfee/tensetupfee` 六个独立字段 | `app/app.php:26` 的 `price_type`；`thinkcmf.sql:1375-1391` |
| `-1` 语义 | 该周期不售（代码体现为 `0 <= $pricing[周期]` 判定） | `app/api/controller/ProductController.php:99,124,136` |
| `config("product_paytype")` | **仅 3 值** `free/onetime/recurring`（区别于 `config("pay_type")` 5 值） | `app/app.php:26` |
| 同步回写 | `day`/`hour` **强制改写**为 `recurring` | `app/common/logic/Product.php:293-295`；`app/common.php:5660-5663` |
| `proList`/`detail`/`getUpgradeProduct` 输出 | `recurring` → **`recurring_prepayment`** | `app/api/controller/ProductController.php:271-276,473-478,682-687` |

### 9.3 站际专用键与三键落表

| 键 / 字段 | 含义 | 依据 |
|---|---|---|
| `downstream_url` / `downstream_token` / `downstream_id` | 下游注册回调三元组，写入**上游站** `shd_host.stream_info`(JSON)；`downstream_token` 32 位 | `app/home/controller/CartController.php:2410-2412,2546-2550`；`app/admin/controller/ClientsServicesController.php:418-422` |
| `token`（下游站 `stream_info`） | 与上游 `downstream_token` **同值**，供验签 | `app/admin/controller/ClientsServicesController.php:428`；`app/api/controller/HostController.php:144,274` |
| `downstream_host_id` / `downstream_client_id` / `downstream_system_type` | **历史版本键，老版不存在**；老版一律用 `downstream_id` | `app/admin/controller/ClientsServicesController.php:408`；`app/zjmf.php:137` |
| `upstream_tid` / `tid` | 工单双向映射（`ticket.upstream_tid` ↔ 对端 `tid`） | `app/common.php:5589`；`app/api/controller/HostController.php:299` |
| `upstream_pid` / `zjmf_api_id` / `dcimid` | 上游产品 id / 账号 id / 上游主机 id | `CartController.php:159`；`Host.php:328` |
| `api_resource_log` 字段 | `uid,description,ip,create_time,update_time=0,pid,version,port,source` | `app/zjmf.php:479-482` |
| `zjmf_pushhost` 字段 | `host_id,url,status,post_data,time,num` | `app/zjmf.php:155,157,159` |
| `is_downstream` | 下单参数布尔 / 商品列表标记 / 统计口径三义（老版） | `CartController.php:2410-2412,2537-2550`；`app/common/logic/Shop.php:626`；`app/admin/controller/ZjmfFinanceApiController.php:270-271` |

---

## 10. 老版状态机

### 10.1 老版站际状态映射（无枚举订单状态）

> **老版上游不返回 `Unpaid/Paid/WaitUpload/…` 字符串状态**，只回数字 `status` + `hostid/invoiceid/domainstatus`；老版站际**无一一映射表**，`domainstatus` 仅在 `/cart/clear` 返回 `Active` 时直接落库（`app/common/logic/Host.php:185-188`）。

| 上游返回 | 本地动作 | 行号 |
|---|---|---|
| `1001`（已付清 / 金额 0） | 改写为 `200`，取 `data.hostid[0]` 写 `host.dcimid` | `app/common/logic/Host.php:97-101`、`:151-155`、`:175-180` |
| `200`（未付清 / 余额不足） | 改 `400`（续付分支）或继续 `apply_credit_limit` | `Host.php:102-104`、`:156-174` |
| `400` + `domainstatus=Active` | 视为已开通，写 `dcimid/domainstatus` | `Host.php:183-190` |
| `400` | 加前缀「上游：」+ 原 msg | `Host.php:193-195` |

### 10.2 订单 / 账单状态机与字典（本地）

| 字典 | 取值 | 行号 |
|---|---|---|
| `config("order_status")` 定义 A | `Pending/Active/Cancelled/Fraud/Suspended/Guarantee`（`name` 取自 `$app_lang`） | `app/app.php:26` |
| `config("public.order_status")` 定义 B | 同 6 值，硬编码中文名/色值 | `app/config/public.php:10` |
| `domainstatus` | `Pending/Active/Cancelled/Fraud/Deleted/Suspended` | `app/config/public.php:10` |
| `invoice_payment_status` | `Paid/Unpaid/Refunded/Cancelled/Draft/Overdue/Collections` | `app/config/public.php:10`、`app/app.php:26` |

> **两处 `order_status` 定义并列**（勘误：旧文档只提 `app/config/public.php:10`，实为 `app/app.php:26` + `app/config/public.php:10` 两处，6 值一致）。

状态推进：`orders.status=Pending`（`app/home/controller/CartController.php:2401`）→ `invoices.status=Unpaid`（`:2400`）→ 支付成功置 `Paid`（`app/home/controller/PayController.php:302`、`app/home/controller/OrderController.php:85-102`、`CartController.php:2632`）。

### 10.3 主机状态映射与白名单回写

| 上游/动作 | 本站 `domainstatus` | 依据 |
|---|---|---|
| 回传白名单 | `syncInfo` 仅接受 `["Pending","Active","Cancelled","Fraud","Deleted","Suspended"]` **同名透传**（无数值映射） | `app/api/controller/HostController.php:168-170` |
| `sync()` 拉取 | 仅 `本机 Pending && 上游 Active` 才改 | `app/common/logic/Host.php:665-667`、`:684-686`、`:698-700` |
| 开通成功 / 暂停成功 / 解除暂停 / 终止成功 | `Active` / `Suspended`+`suspendreason`+`suspend_time` / `Active`+`suspendreason=""` / `Deleted`+`termination_date`+清空 IP 账号 | `Host.php:262`、`:369`、`:482`、`:581-595` |
| 中文展示 | `config("domainstatus")[值]`（日志）、`config("public.domainstatus")[值]`（`domainstatus_zh`） | `app/api/controller/HostController.php:241,243`；`CartController.php:352` |

> ⚠️ 回写时本地只接受白名单 6 值，上游其他字符串状态**会被静默丢弃**，**不是一一映射**，对接方不要假设存在映射表。

### 10.4 状态字典不同源（易踩）

| 配置项 | 取值 | 来源 |
|---|---|---|
| 本地 `config("order_status")` | `Pending/Active/Cancelled/Fraud/Suspended/Guarantee`（WHMCS 风格） | `app/app.php:26`、`app/config/public.php:10` |
| 本地 `domainstatus` | `Pending/Active/Cancelled/Fraud/Deleted/Suspended` | `app/config/public.php:10` |
| 本地 `invoice_payment_status` | 7 值见 §10.2 | `app/config/app.php:26`、`app/config/public.php:10` |
| `sslDomainStatus` | 独立字典 | `app/home/controller/HostController.php:940-945` |

不对称点：`order_status` 含 `Guarantee` 而 `domainstatus` 无（`app/config/public.php:10`）；`sslDomainStatus` 为独立字典。

---

## 11. 典型时序（三视角各一组）

### 11.1 出站视角：首次对接（最小可用，本站=下游）

```
1. 上游客户中心开启「API 功能」→ 获取 API 密钥 （前置 allow_resource_api=1, api_open=1）
2. POST /zjmf_api_login {username, password=API密钥} → 拿 jwt（LoginController.php:339-380）
3. GET /cart/all → 商品分组 + count + currency（CartController.php:443-458）
4. GET /cart/get_product_config?pid=X → 配置项 + 17 对价格（CartController.php:459-504）
5. GET /cart/stock_control?pid=X → 校验库存（出站 CartController.php:2812）
6. POST /cart/add_to_shop {pid,billingcycle,configoption{…},currecncyid} → data.i（CartController.php:2660）
7. POST /cart/settle {payment,pos[],cart_data,downstream_*} → 1001/200 + invoiceid/hostid[]（CartController.php:1660-2659）
8. POST /apply_credit {invoiceid,use_credit:1} → status=1001 即开通完成（PayController.php:259-344）
9. AutoCreate.php:29 → Host::createFinal → 上游 P1→P6 下单链（app/queue/job/AutoCreate.php:29、Host.php:63-195）
10. GET /host/header?host_id=<dcimid> → 拉取 IP/账号/到期（HostController.php:903-1369）
```

> 首步探活补正：下单链真正首步是 `POST /cart/clear`（`Host.php:82`），上游按 `downstream_token` 查重（§5.2 P2、§5.7）。

### 11.2 入站视角：本站作为上游被下游下单

```
下游站 zjmfCurl → 本站：
 POST /zjmf_api_login（LoginController.php:339-380，组 3 UserCheck）→ 应答 jwt + is_aff
 POST /cart/clear （CartController.php:3277-3306）→ 已付回 400「该订单已开通」+hostid+domainstatus；未付回 200+invoiceid+hostid
 POST /cart/add_to_shop（CartController.php:2660-2935）→ 200+data.i / 406
 POST /cart/settle （CartController.php:1660-2659，组 2 Check 强制登录）→ total==0: 1001+hostid[]+url；total>0: 200+invoiceid；异常 406
 POST /apply_credit （PayController.php:259-344）→ 全额付清 1001「支付完成」+ hostid + url / 部分 200 / 不足 400
 → 结算完成写 host.stream_info = {downstream_url, downstream_token, downstream_id}（CartController.php:2546-2550）
 → 本机开通后 pushHostInfo($id,"domainstatus","create") 回推下游（app/zjmf.php:120-163）
```

### 11.3 回传视角：状态回调（本站=上游，你是下游）

```
上游主机变更 → pushHostInfo()（app/zjmf.php:120-163）
 → POST {downstream_url}/api/host/sync（data/route/api.php:18，无 ApiCheck）
 → 接收端按 stream_info.token 自补 token（app/api/controller/HostController.php:144）
 → validateSign 只重组 {id,token,rand_str} 三键验签（app/zjmf.php:177-184）
 → 按 id 定位本地 host，写 domain/username/password/os/dedicatedip/assignedips/port/nextduedate
 → domainstatus 白名单 6 值才写（HostController.php:168-170）
 → 返回 {status:200}；失败入 zjmf_pushhost，Cron num<5 重放（Cron.php:264-273）
```

### 11.4 老版日常运维

```
GET /host/header?host_id=X&source=API → 全量信息（含模块按钮、图表配置）（HostController.php:903-1369）
POST /provision/default {id:dcimid, func:"status"|"on"|"off"|"reboot", is_api:1} → 电源类（Host.php:748-829）
POST /host/cancel {id:dcimid, type:"Immediate"} → 终止（Host.php:542-546）
POST /host/renew + /apply_credit → 续费（Host.php:1863-1878）
```

老版电源类路由注册于 `data/route/home.php:217-236`（`Route::controller("host","home/Host")` 自动映射），无独立 `console/*` 前缀。

---

## 12. 已知缺陷与对接避坑（仅站际相关，逐条带行号）

> 收敛口径：仅保留老版站际相关缺陷，`1001` 由「双义」订正为**三义**，全部补齐行号；§12 D1–D7 为本轮新发现缺陷。

| # | 缺陷 | 影响 | 对接建议 | 行号 |
|---|---|---|---|---|
| 1 | `Authorization` 头解析取**第 0 段** | `Bearer {jwt}` 被截成 `"Bearer"` → 405 | 遇 405 先试裸 jwt；`ApiCheck` 域才取第 1 段 | `app/http/middleware/Check.php:106,136`；`app/zjmf.php:208` |
| 2 | 老版 `cart/all` **无 30 天缓存** | 旧「`/api/v1/product` 缓存 30 天」误植会误导 | 按实时接口对接；老版唯一 TTL 是汇率 86400s（未确认） | `app/home/controller/CartController.php:449-456`；`app/common/logic/Product.php:116-125` |
| 3 | `cart/resource_product` **死路由** | 路由存在、方法未定义、无调用点，调用必「方法不存在」 | 改用 `cart/createproducts` | `data/route/home.php:430`；`CartController.php:96-165` |
| 4 | `settle` / `apply_credit` 无幂等键 | 超时重试会重复下单 | 用 `POST /cart/clear` + `downstream_token` 防重；本地加锁 | `Host.php:82-104,126-136`；`CartController.php:2537-2551,3290-3303` |
| 5 | 签名只覆盖 `{id,token,rand_str}` 三字段、无 nonce/时效 | 业务字段可被篡改；同一签名可无限重放 | 接收方加 IP 白名单 + `rand_str` 去重 + 主动回查 | `app/zjmf.php:164-184` |
| 6 | `pushHostInfo` 不推 `suspendreason`，接收端却无条件覆盖 | 每次同步清空下游暂停原因；`:171-173` 的 isset 保护完全冗余 | 接收方跳过空值覆盖 | `app/zjmf.php:122-125`；`app/api/controller/HostController.php:164,171-174`；`Host.php:384,501` |
| 7 | 老版 `apply_credit` 撤销失败**仅重试 1 次且不复判结果** | 极端情况上游余额挂账 | 对账机制兜底 | `app/common/logic/Host.php:167-170` |
| 8 | `1001` 状态码**三义** | 误判成功/失败 | 按接口 + msg 联合判断（§2.2） | `Check.php:169`；`CartController.php:2644`；`PayController.php:323`；`OrderController.php:148` |
| 9 | `UserCheck` 组（`cart/*`、`zjmf_api_login`）不拦截未登录 | 不带 token 也返回 200，但 `data` 为空 | 必须带 token；校验 `data` 而非只看 `status` | `app/http/middleware/UserCheck.php:52-61`；`data/route/home.php:401-514` |
| 10 | `cart/summary` 返回**明文 API 密钥** | 密钥泄露面扩大 | 不在前端/日志透出该字段 | `app/home/controller/CartController.php:396` |
| 11 | `bcdiv()` 未防除零（`cart/summary` 的 `ratio`） | 昨日计数 0 时异常；`INF%` 未在代码中确认 | 容错解析 + 实例实测 | `app/home/controller/CartController.php:410,415` |
| 12 | `verifyJwt` 异常分支 `echo` + `exit` | 返回体不是标准信封（裸 JSON） | 兼容裸 JSON | `app/http/middleware/Check.php:174-179` |
| 13 | `GET /auto_api_pwd` 无鉴权、不落库、不含特殊字符 | 生成的密钥无法直接提交 | 前端二次加工 | `app/home/controller/UserController.php:264-268,258` |
| 14 | `zjmfCurlHasFile` 405 重登后**未刷新 header** | 带附件接口重试必失败 | 上游侧修复；对接方避免依赖 405 自动重试 | `app/zjmf.php:243-252`（对比 `zjmfCurl` `:216` 有刷新） |
| 15 | `curlHasFile` 错误返回用 `code` 键而非 `status` | 调用方读 `$res["status"]` 取空 | 兼容 `code` 键 | `app/zjmf.php:337,343` |
| 16 | 跨币种 `bcdiv` 精度不一致（20 位 vs 2 位）且无除零保护 | 汇率换算金额偏差 / 上游币种缺失时除零 | 统一精度 + 判空 | `app/common/logic/Product.php:160,217`；`ProductModel.php:229`；`ProductController.php:835` |
| 17 | `upstreamBates()` **无 bcdiv/除零风险**（旧文档反） | 误把汇率换算风险归到加价函数 | 除零在 §4.3 表所列位置排查 | `app/zjmf.php:436-478` |
| 18 | `getZjmfUostreamHostInfo()` 全库无调用点 | 误当 `host/header` 主入口 | 以 15+ 处直接 `zjmfCurl` 为准 | `app/zjmf.php:81-89` |
| 19 | `createTicket`/`replyTicket` 对 `zjmf_api` 空实现 | 工单跨站误用模块函数 | 走 `/ticket/create`、`/ticket/reply`、`/api/ticket_reply/sync` | `app/common/logic/Host.php:1947-1948,1978-1979` |
| 20 | `getProductConfig()` `unset` 掉 `upstream_product_shopping_url` | 下游拿不到该字段 | 单列取用 | `app/home/controller/CartController.php:474` |
| 21 | `add_to_shop` 校验键 `currecncyid` 与出站 `currencyid` 拼写不一致 | 参数名对齐易错 | 按调用方向分别处理 | `CartController.php:2667`；`Host.php:110` |
| 22 | `resource/agentinfo` 失败**不回滚**（勘误 13） | 上游报备失败被吞，连接状态误报「连接成功」 | 联调实测报备返回 | `app/admin/controller/AgentController.php:78,86-90` |
| D1 | `postLinkToResource` 判 `$result["status"]`（`:78` 已置 200 恒真），从不判 `$res` | 事务照常 commit | 上游侧修复 | `app/admin/controller/AgentController.php:78,86-90` |
| D2 | 资源池上游地址写死常量，不接受用户传的 hostname | 生产无法配置真实上游 | 上线前核对 | `app/admin/controller/AgentController.php:18,32,1064` |
| D3 | `getResourceInfo` 把明文密码回给前端 | 明文凭据外泄面 | 不在前端透出 | `app/admin/controller/AgentController.php:21-26` |
| D4 | `getProducts` 联表条件写 `b.zjfm_api_id`（少一个 `m`） | 按 id 过滤商品时漏匹配 `product_groups` 层 | 联调核对 | `app/admin/controller/AgentController.php:122` |
| D5 | `getTotal` 三处 `static $end/$v` 未 `use` 传参 | 时间过滤恒不生效、`"…#" . $v` 拼空 | 月度成本统计需复核 | `app/admin/controller/AgentController.php:694-700,703-709,712-717` |
| D6 | `getRefundDetail` `$percent=bcdiv($amount,$subtotal,20)` 从未使用，`subtotal=0` 会除零 | 死代码 + 潜在除零 | 清理 | `app/admin/controller/AgentController.php:482-486` |
| D7 | `zjmfCurlHasFile` 无历史版本登录分支 | 带附件接口若落在历史版本账号会误用 `/zjmf_api_login` | 标注「资源线/老版线适用」 | `app/zjmf.php:229-235` vs `:197-203` |

> **未确认项汇总**（本地树无法复核，联调实测）：① `/resource_login` 与 `resource_login_supplier` 是否有 rewrite/别名弥合；② `AdminCheck` 路由挂载位置；③ `verifyJwt` 的 `catch (Exception $e)`（`Check.php:180`）能否捕获通用 `\Exception`；④ `resourceCurl` 定义（`app/res` 本树不存在）；⑤ 回传限频/重放窗口策略（签名无 time/nonce）；⑥ `cart/credit`/`cart/summary` 上游余额运行时行为；⑦ `bcdiv(x,0)` 的实际返回值（是否 `INF%`）；⑧ `modules/addons/idcsmart_api/api.php` 插件本体；⑨ 8 个「原样透传」`resource/*` 接口的 `data.*` 与全部接口的上游服务端实现；⑩ 跨版本任何行为差异。

---

## 13. 快捷索引表

> 全表仅保留老版站际接口，方向标注见 §0.2。

### 13.1 鉴权

| 接口 | 方法 | 方向 | 用途 |
|---|---|---|---|
| `/zjmf_api_login` | POST | 出站 + 入站 | 老版 API 密钥登录（`LoginController.php:339-380`） |
| `/resource_login` | POST | 出站 | 资源/代理商登录（本树入站名为 `resource_login_supplier`） |
| `/v1/login_api` | POST | 入站（OpenAPI） | 客户 OpenAPI 密钥登录（非站际，存目） |
| `get_api_pwd` / `modify_api_pwd` / `auto_api_pwd` | GET/POST/GET | 入站 | 客户密钥自助（`data/route/home.php:98-100`） |

### 13.2 商品（双向）

| 接口 | 方法 | 方向 |
|---|---|---|
| `/cart/all` | GET | 出站（下游拉）+ 入站（上游提供） |
| `/cart/get_product_config` | GET | 双向 |
| `/cart/stock_control` | GET | 双向（出站 `:2812` / 入站 `:336-343`） |
| `/cart/ontrialmax` | GET | 双向（`5s` 出站） |
| `/api/product/proinfo` / `prodetail` | GET | 入站 |
| `/api/product/list` / `:id` / `:id/resource` / `upgrade_product` | GET | 入站 |
| `/cart/productsgroups` / `/cart/createproducts` | POST | 入站（代理商代理商品） |
| `resource/products` / `resource/rate` | GET | 出站（资源形态专属目录/定价，`app/zjmf.php:42,68`） |
| `resource/agentproductsarray` | GET | 出站（`AgentController.php:109`，与 `resource/products` 是不同接口） |

### 13.3 交易

| 接口 | 方法 | 方向 |
|---|---|---|
| `/user_info` | GET | 出站（P1） |
| `/cart/clear` | POST | 双向（首步探活 / 入站 `clearCart`） |
| `/cart/add_to_shop` | POST | 双向 |
| `/cart/get_total` | POST | 入站 |
| `/cart/settle` | POST | 双向 |
| `/apply_credit` / `/apply_credit_limit` | POST | 双向 |
| `/host/renew` + `/apply_credit` | GET + POST | 出站（续费对账） |
| `/upgrade/*` | POST | 出站（升降级） |
| `resource/agentorders` / `agentorderssearch` / `agentrenew` | GET | 出站（资源线对账） |

### 13.4 主机 / 运维

| 接口 | 方法 | 方向 |
|---|---|---|
| `/host/header` | GET | 出站 + 入站 |
| `/cart/hostinfo` | GET | 出站 + 入站 |
| `/provision/default` | POST | 出站（12 个 `func`，§6.2） |
| `/host/cancel` / `/host/renew` | POST | 出站（生命周期） |
| `/host/setdownstream` | POST | 出站（登记换绑） |
| `api/host` / `api/host/free` / `api/host_server` | POST | 入站（`ApiCheck`） |
| `api/exec_module_func` | GET\|POST | 入站（无中间件） |

本册已收录的主机接口与代理商线内容见 [§6 主机信息与老版运维](#6-主机信息与老版运维)；原字段级分册未收录，不以现有摘选冒充该分册全集。

### 13.5 回调 / 回传 / 工单

| 接口 | 方法 | 方向 |
|---|---|---|
| `/api/host/sync` | POST | 回传（上游 → 下游，`data/route/api.php:18`） |
| `/api/ticket_reply/sync` | POST | 回传 |
| `/ticket/create` / `/ticket/reply` / `/ticket/department` | POST/POST/GET | 出站（本站作下游 → 上游） |
| `/upload_image` | POST | 双向（附件换 `savename`） |
| `/host/setdownstream` | POST | 出站（登记） |

### 13.6 `resource/*` 21 个 path 速查

`resource/products`、`resource/rate`（`app/zjmf.php`）+ `/resource/agentinfo`、`resource/agentproductsarray`、`resource/agentorderssearch`、`resource/agentorders`、`resource/agentrenew`、`resource/afterSaleDetail`、`resource/refundDetail`、`resource/agenthosts`、`resource/resourceinspectionlists`、`resource/resourceinspection`、`resource/resourceinspectionip`、`resource/resourceinspectiondetail`、`resource/resourcerefundfromagent`、`resource/resourceaftersalefromagent`、`resource/resourceunaftersalefromagent`、`resource/agentbaseinfo`、`resource/agentcredit`、`resource/agentlogs`、`resource/evaluation`（`app/admin/controller/AgentController.php`）——逐接口入参/返回/失败处理见 §6.3。

---

## 分册

下列两个原分册未收录于当前仓库，名称仅作历史存目。现有入口可定位本册已保留的考证和 CloudCMS 的经典线实现范围，不表示原分册已交付，也不把资源/代理商线整体视为 CloudCMS 已实现能力。

| 原分册（未收录，仅存目） | 当前可用入口 | 已有内容范围 |
|---|---|---|
| `zjmf-3.7.6-上游主机运维接口对接清单.md` | [本册 §6 主机信息与老版运维](#6-主机信息与老版运维)、[CloudCMS 实例操作规范](zjmf-api-spec.md#4-instance-operations-provisiondefault--lifecycle)、[机器管理与 VNC](classic-zjmf-machine-controls.md) | 本册保留主机信息、站际运维和 `resource/*` 代理商线考证；CloudCMS 参考仅覆盖已接入的经典线操作及其验收边界 |
| `zjmf-上游API对接分册A-鉴权与账户.md` | [本册 §1 鉴权](#1-鉴权)、[CloudCMS 传输与认证规范](zjmf-api-spec.md#1-transport--auth) | 本册保留老版登录、凭据和多路鉴权考证；CloudCMS 规范说明已接入经典线的登录、JWT、余额读取及脱敏规则 |

---

## 附录：核心源码锚点

| 文件 | 作用 |
|---|---|
| `app/zjmf.php:185-224` | `zjmfCurl()` 上游调用唯一出口（登录分流 `:194-203`、405 重登 `:211-222`） |
| `app/zjmf.php:38-80` | 目录/详情/配置/汇率的 6 个出站函数 |
| `app/zjmf.php:90-163` | `pushTicketReply()`、`pushHostInfo()` 回传 |
| `app/zjmf.php:164-184` | `createSign()` / `validateSign()` 回调签名 |
| `app/zjmf.php:348-365` | `zjmfApiLogin()` jwt 获取与缓存 |
| `app/zjmf.php:366-435` | `commonCurl()` 传输层 |
| `app/zjmf.php:436-478` | `upstreamBates()` 上游加价 |
| `app/zjmf.php:479-495` | `apiResourceLog()`、`whmcsCurlPost()` |
| `app/common.php:47-56` | `createJwt()`（缓存写入 `:53-54`） |
| `app/common.php:1071-1085` | `aesPasswordEncode/Decode()` |
| `app/common.php:2382-2413` | `jsonrule/jsons/json/jsonseparate()` 响应封装 |
| `app/common.php:5522-5642` | 工单跨站 `/ticket/create`、`/ticket/reply`、`curlUpload` |
| `app/common.php:6298-6312` | `judgeApi()` |
| `app/http/middleware/Check.php:96-183` | jwt 校验（含 405 六种触发条件、`verifyJwt`） |
| `app/http/middleware/UserCheck.php:52-61` | UserCheck 组不拦截未登录 |
| `app/http/middleware/ApiCheck.php:17-55` | 四路鉴权之 `ApiCheck` |
| `app/home/controller/LoginController.php:339-424` | `zjmfApiLogin()` / `resourceLogin()` |
| `app/home/controller/CartController.php:96-3306` | `cart/*` 商品/配置/结算/落单全链路 |
| `app/home/controller/PayController.php:259-435` | `applyCredit()` / `applyCreditLimit()` |
| `app/home/controller/HostController.php:903-1369`、`:2220-2248` | `getHeader()` / `postSetDownStream()` |
| `app/api/controller/ProductController.php:14-704` | 入站 `api/product/*` |
| `app/api/controller/HostController.php:133-356` | `syncInfo()` / `syncTicketReply()` 回传接收端 |
| `app/common/logic/Host.php:20-210`、`:748-829` | 下游开通链 / 运维六分支分发 |
| `app/admin/controller/AgentController.php:63-1068` | `resource/*` 21 个 path 代理商线 |
| `app/admin/controller/ZjmfFinanceApiController.php:18-1350` | 老版账号 CRUD / 对账 |
| `app/queue/job/AutoCreate.php:15-29` | 开通失败 10s 重投 → `Host::createFinal` |
| `app/admin/command/Cron.php:264-299` | `zjmf_pushhost` 重放 / 资源线开通回填 |
| `data/route/home.php:91-514` | 本地 `cart/*`、`host/*` 入站路由（组 2 `Check`、组 3 `UserCheck`） |
| `data/route/api.php:13-49` | 回传接收、商品 API、`ApiCheck` 分组 |
| `data/route/openapi.php` | 客户 OpenAPI 路由 |
| `public/install/thinkcmf.sql` | `shd_*` 表结构（列定义见 §9） |

> `app/zjmf.php` `wc -l` 计 **496**，含末行 `?>` 共 **497**（勘误 20）；`app/app.php:26` 是单行大配置数组（`billing_cycle`/`price_type`/`pay_type`/`jwtkey`/`order_status`/`getRateUrl` 等），Read 会截断，行号统一记作 `:26`。

---

## 附录 Z：非站际对接范围（V10 分支，仅供存目）

- 本次改写范围仅「老版站际对接」；`console/v1/*`、`app/v10.php`、`V10CartController.php`、10.7.1 参考树等非站际对接分支不在范围内。
- 原总册中上述分支的全链路、字段字典、能力矩阵、状态枚举、时序与缺陷条目已全部移出正文，不再维护。
- 如需查阅相关契约，请回到本文件 2026-11-15 及更早的历史版本。
- 如需核对部署/跨版本事实，请回到对应环境实测；本地解码树无对应文件可复核。
