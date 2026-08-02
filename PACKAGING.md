# 售卖包交付清单

本文供发布人员使用，目标是生成不含测试凭据、用户数据和本机产物的可安装源码包。

## 必须包含

- `app/`、`config/`、`database/`、`data/`、`public/`、`route/`、`view/`
- `deploy/` 和 `README.md`
- `tests/`
- `vendor/`，用于无 Composer 环境直接安装
- `runtime/.gitkeep`
- `public/uploads/.gitkeep` 和 `public/uploads/.htaccess`
- `.env.example`、`.gitignore`
- `composer.json`、`composer.lock`、`think`
- `README.md`、`SECURITY.md`、`PACKAGING.md`、`LICENSE.txt`
- 第三方组件随附的许可证文件

## 必须排除

- `.env` 和 `install.lock`
- `runtime/` 中除 `.gitkeep` 外的 Session、缓存、模板和日志
- `public/uploads/` 中除 `.gitkeep`、`.htaccess` 外的安装包和图片
- `_vendor_assets/`、`.playwright-mcp/`
- `.idea/`、`.vscode/`、`.git/`
- 根目录 `nova-*.png` 验收截图
- 数据库导出、服务器日志、临时压缩包和任何真实账号凭据

## 发布前验证

在一个全新的空数据库和解压目录中完成以下检查：

```bash
composer validate --strict
composer test
php think route:list
php think nova:logs:cleanup --days=90 --dry-run
```

还必须完成：

1. 确认 PHP 7.4 和目标 PHP 8.x 均无语法错误。
2. 从 `/install` 完成五步安装，确认 `.env` 与 `install.lock` 正确生成。
3. 验证后台登录、改密、站点配置和五类内容 CRUD。
4. 上传真实安装包测试文件并验证本地 302 与下载日志，再删除该文件。
5. 验证网盘下载中转页、错误页与非法分享地址拒绝路径。
6. 验证 PNG、JPG、WebP、SVG 上传及恶意 SVG 拒绝或清理。
7. 检查桌面与 390px 移动视口，不得出现溢出、遮挡或控制台错误。
8. 运行 `composer audit`，把所有公告及适用性评估同步到 `SECURITY.md`。

## 打包后复核

解压最终压缩包并再次确认：

- 根目录不存在 `.env` 或 `install.lock`，访问 `/install` 会进入安装向导。
- `runtime` 和 `public/uploads` 没有测试数据。
- `vendor/autoload.php` 存在，未运行 Composer 也能启动安装器。
- Web 根目录指向 `public` 后，项目根文件不能通过 HTTP 读取。
- 文档中的路径、PHP 版本和服务器模板仍与本次版本一致。

源码包可以收费分发，但不得删除或篡改 ThinkPHP、Bootstrap、Chart.js、Bootstrap Icons、PhotoSwipe、ip2region 等第三方项目的许可证和版权声明。
