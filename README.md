# NOVA App Download

NOVA App Download 是一个基于 ThinkPHP 6.1 的应用下载落地页与管理后台。项目提供五步安装向导、响应式前台、本地安装包或网盘直达下载、可视化内容管理、访问统计、下载日志和安全图片上传，适合直接部署到宝塔 Linux 面板，可用于多端应用分发。

## 功能

- 五步 Web 安装向导，自动检测环境、初始化 9 张数据表并生成随机应用密钥
- Session 后台登录、失败限次、CSRF 防护和管理员改密
- 站点信息、主题配色、下载方式、导航、预览、功能、友链、信任品牌管理
- 本地安装包校验上传与安全 302 下载（默认支持 APK 等安装包）
- 网盘分享地址与访问密码中转，不解析第三方网盘
- PNG、JPG、WebP 和白名单 SVG 图片上传
- ip2region 归属地、可信代理、访问日志、下载日志、筛选和趋势统计
- 桌面与移动端响应式页面；没有内容的区块不会输出空结构
- APP 预览轮播支持自动切换；点击图片使用本地 PhotoSwipe 放大预览
- 数据库和运行日志保留期清理命令

## 环境要求

- PHP 7.4 或更高版本
- MySQL 5.7 或更高版本
- PHP 扩展：`pdo`、`pdo_mysql`、`openssl`、`curl`、`fileinfo`、`mbstring`、`json`、`dom`
- Nginx 或 Apache，网站根目录必须指向 `public`
- 项目根目录、`runtime`、`public/uploads` 在安装时可由 PHP 运行用户写入

本地安装包默认最大 300MB，栅格图片默认 5MB，SVG 默认 1MB，均可通过 `.env` 调整。默认部署下服务器层建议允许 310MB 请求，PHP 建议设置 `upload_max_filesize=310M` 和 `post_max_size=320M`；修改应用上限时必须同步调整 PHP 与 Web 服务器限制。

## 宝塔面板部署

本节面向首次部署人员，说明如何在宝塔 Linux 面板中完成安装、HTTPS 配置和日常维护。以下示例以项目目录 `/www/wwwroot/nova-download`、PHP 8.0 为例；实际部署时请将域名、目录和 PHP 版本替换为服务器的真实值。

### 1. 准备运行环境

在宝塔面板中安装以下软件：

- Nginx 或 Apache，推荐 Nginx；两者只需选择其一。
- PHP 7.4 或更高版本。
- MySQL 5.7 或更高版本。

在 PHP 的“安装扩展”页面确认已启用 `pdo`、`pdo_mysql`、`openssl`、`curl`、`fileinfo`、`mbstring`、`json` 和 `dom`。项目已内置 `ip2region` 数据文件，无需额外安装扩展。

为支持默认 300MB 本地安装包，请在对应 PHP 版本的 `php.ini` 中设置：

```ini
upload_max_filesize = 310M
post_max_size = 320M
max_execution_time = 120
max_input_time = 120
memory_limit = 256M
```

保存后重载 PHP-FPM。还必须在 Nginx 或 Apache 中配置不低于 310MB 的请求上限，否则请求会在进入应用前返回 413。

应用上传上限可在安装完成后通过 `.env` 调整：

```ini
[UPLOAD]
APK_MAX_MB = 300
IMAGE_MAX_MB = 5
SVG_MAX_MB = 1
```

修改 `APK_MAX_MB` 时，必须同步提高 PHP 的 `upload_max_filesize`、`post_max_size` 和 Web 服务器请求上限。为避免错误配置耗尽资源，应用会将本地安装包、栅格图片和 SVG 的最大值分别限制为 1024MB、50MB 和 10MB。

### 2. 创建站点和数据库

1. 在宝塔“网站”中创建站点，填写实际域名。
2. 将完整源码上传至 `/www/wwwroot/nova-download`。
3. 将网站运行目录设置为 `/public`，使最终网站根目录为 `/www/wwwroot/nova-download/public`。
4. 关闭目录列表，并为站点选择已准备的 PHP 版本。
5. 在宝塔“数据库”中创建独立数据库和账号，字符集选择 `utf8mb4`，妥善记录数据库名、用户名和随机密码。

不要将运行目录指向项目根目录，否则 `.env`、数据库脚本和源代码可能被 Web 直接访问。

### 3. 配置 Web 服务器

#### Nginx

推荐将 [Nginx 示例](deploy/nginx.conf.example) 的内容作为站点配置基础，替换其中的域名、项目路径和 PHP-FPM 套接字。宝塔 PHP 套接字通常位于 `/tmp/php-cgi-版本号.sock`，例如 PHP 8.0 使用 `/tmp/php-cgi-80.sock`。

若沿用宝塔生成的默认配置，至少应确认包含以下规则，并保留示例中针对上传目录的脚本禁用规则：

```nginx
root /www/wwwroot/nova-download/public;
client_max_body_size 310m;

location / {
    try_files $uri $uri/ /index.php?s=$uri&$args;
}
```

#### Apache

启用 `rewrite` 与 `headers` 模块，并以 [Apache 示例](deploy/apache-vhost.conf.example) 配置虚拟主机。`public/.htaccess` 负责入口重写，`public/uploads/.htaccess` 负责阻止上传目录中的脚本执行，因此对应目录必须允许 `FileInfo`、`AuthConfig`、`Limit` 和 `Options` 覆盖。

### 4. 设置目录权限

安装器需要在项目根目录创建 `.env` 和 `install.lock`，并写入 `runtime` 与 `public/uploads`。以下命令假定宝塔 PHP 运行用户为 `www`；如服务器实际用户不同，请替换为正确的用户和用户组：

```bash
chown -R www:www /www/wwwroot/nova-download
find /www/wwwroot/nova-download -type d -exec chmod 750 {} \;
find /www/wwwroot/nova-download -type f -exec chmod 640 {} \;
chmod 750 /www/wwwroot/nova-download/think
chmod -R u+rwX /www/wwwroot/nova-download/runtime
chmod -R u+rwX /www/wwwroot/nova-download/public/uploads
```

不要使用 `chmod -R 777`。安装完成后收紧敏感文件权限：

```bash
chmod 600 /www/wwwroot/nova-download/.env
chmod 640 /www/wwwroot/nova-download/install.lock
```

### 5. 完成安装向导

访问 `https://你的域名/install`，按顺序完成以下步骤：

1. 阅读安装说明。
2. 通过 PHP 扩展、目录权限和数据文件检测。
3. 填写数据库连接信息并执行连接测试。
4. 创建管理员账号；密码须为 10 至 72 字节，且至少包含三类字符。
5. 确认安装完成并进入后台。

安装器会原子写入 `.env`，随后生成 `install.lock`。安装后的站点再次访问 `/install` 会返回 403；不要删除 `install.lock`，也不要将 `.env` 提交到版本库或发送给无关人员。后台登录地址为 `/admin/login`。

### 6. 启用 HTTPS

在宝塔站点的“SSL”页签申请并部署证书，开启 HTTP 强制跳转 HTTPS。确认 HTTPS 访问正常后，在 `.env` 中设置：

```ini
[COOKIE]
SECURE = true
```

重载 PHP-FPM 后重新登录后台，确认 `PHPSESSID` Cookie 同时具有 `Secure`、`HttpOnly` 和 `SameSite=Lax` 属性。纯 HTTP 环境提前开启 `COOKIE.SECURE` 会导致浏览器不发送会话 Cookie。

### 7. 配置日志清理任务

先在宝塔终端中预演，核对清理数量与截止时间：

```bash
cd /www/wwwroot/nova-download
/www/server/php/80/bin/php think nova:logs:cleanup --days=90 --dry-run
```

确认无误后，在宝塔“计划任务”中新建每日执行一次的 Shell 脚本：

```bash
cd /www/wwwroot/nova-download || exit 1
/www/server/php/80/bin/php think nova:logs:cleanup --days=90 >> runtime/cleanup.log 2>&1
```

请按实际 PHP 版本调整命令中的 `80`。`--days` 仅接受 1 至 3650 的整数；命令会在事务中清理过期访问日志和下载日志，并删除 `runtime` 下修改时间早于截止时间的 `.log` 文件。`.env` 中的 `LOG.MAX_FILES` 控制 ThinkPHP 单个日志通道最多保留的文件数，默认值为 30。

### 8. 上线验收与故障排查

上线前逐项确认：

- `/` 能打开前台下载页，桌面端和移动端布局正常。
- `/admin/login` 可以登录，错误密码会触发限次保护。
- 后台的站点、下载和五类内容资源均可保存。
- 本地安装包上传后，`/download` 返回 302，目标位于 `/uploads/apk/`。
- 网盘下载模式可显示访问密码并跳转到原始分享链接，失败时显示受控错误页。
- 图片上传只接受 PNG、JPG、WebP 或 SVG；上传目录中的脚本请求返回 403。
- 安装完成后 `/install` 返回 403，服务器错误日志没有持续异常。

| 问题 | 排查方式 |
| --- | --- |
| 上传安装包返回 413 | 同时检查 Nginx 的 `client_max_body_size` 或 Apache 的 `LimitRequestBody`，以及 PHP 的 `upload_max_filesize` 和 `post_max_size`；修改后重载对应服务。 |
| 安装器提示目录不可写 | 确认项目根目录、`runtime`、`public/uploads` 的所有者是 PHP-FPM 运行用户；不要使用全局 `777` 绕过问题。 |
| 页面路由全部 404 | 确认网站根目录为 `public`，并检查 Nginx 的 `try_files` 或 Apache 的 `mod_rewrite` 是否生效。 |

## 首次配置

后台地址为 `/admin/login`。登录后建议按以下顺序配置：

1. 在“站点配置”填写应用名称、版本、介绍、图标、统计文案、备案号和可信代理。
2. 在“站点配置 → 主题配色”选择预设品牌色或自定义十六进制色值，统一前台与后台强调色。
3. 在“下载配置”选择本地安装包或网盘下载。本地模式先上传安装包；网盘模式填写分享地址和可选访问密码，访问者会先看到密码与原始跳转链接，系统不会解析第三方网盘。
4. 维护导航、预览页面、功能卡片、友情链接和信任品牌。
5. 打开前台和 `/download` 完成实际下载验证。

可信代理只填写真实反向代理的单个 IP 或 CIDR。普通部署应留空；错误信任公网网段会让客户端伪造访问 IP。

## 环境配置

安装器会生成 `.env`，示例见 `.env.example`。生产环境常用项：

```ini
APP_DEBUG = false

[COOKIE]
SECURE = true

[LOG]
MAX_FILES = 30

[UPLOAD]
APK_MAX_MB = 300
IMAGE_MAX_MB = 5
SVG_MAX_MB = 1
```

只有 HTTPS 已正常启用时才设置 `COOKIE.SECURE=true`。`SECURITY.APP_KEY` 用于加密网盘访问密码，不能随意修改或泄露。

上传上限只接受整数 MB。应用会把本地安装包限制在 1 至 1024MB、栅格图片限制在 1 至 50MB、SVG 限制在 1 至 10MB；非整数回退默认值，越界整数会收敛到安全边界。PHP 的 `upload_max_filesize`、`post_max_size` 与 Nginx/Apache 请求上限必须大于对应应用上限，否则请求会在进入应用前被拒绝。

网盘下载模式仅由浏览器跳转到已配置的 HTTP(S) 分享地址，不会向第三方网盘发起服务端请求。

## 日志维护

预演 90 天保留策略：

```bash
php think nova:logs:cleanup --days=90 --dry-run
```

确认后执行：

```bash
php think nova:logs:cleanup --days=90
```

命令会清理 `visit_log`、`download_click_log` 中早于截止时间的记录，以及 `runtime` 下同期的 `.log` 文件。建议通过宝塔计划任务每日执行一次。`--days` 支持 1 至 3650。

## 开发验证

安装依赖：

```bash
composer install
```

执行静态和关键路径检查：

```bash
composer validate --strict
composer test
php think route:list
```

HTTP 集成测试要求站点已运行、测试数据库已安装，并通过环境变量提供测试管理员密码：

```bash
NOVA_TEST_ADMIN_USERNAME='实际管理员用户名' NOVA_TEST_ADMIN_PASSWORD='仅用于测试的密码' composer test:http
```

管理员用户名未提供时默认为 `admin`。测试会通过真实后台登录和 CSRF 流程上传临时图片与安装包，验证本地 302 和日志，然后恢复下载配置并删除测试文件。不要对生产站点运行该测试。

## 安全与交付

生产部署要求、已知上游公告及适用边界参见 [SECURITY.md](SECURITY.md)。制作源码售卖包前按 [PACKAGING.md](PACKAGING.md) 清除 `.env`、安装锁、测试数据、本机缓存和验收截图。

项目使用 Apache-2.0 许可证，详见 [LICENSE.txt](LICENSE.txt)。随附第三方组件保留各自许可证。
