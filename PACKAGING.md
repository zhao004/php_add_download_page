# 售卖包交付清单

本文供发布人员使用，目标是生成不含测试凭据、用户数据和本机产物的可安装源码包。

## GitHub 自动发布

仓库通过 GitHub Actions 在推送合法版本标签后自动校验、打包并创建 Release。标签必须符合以 `v` 开头的语义化版本，例如 `v1.2.3` 或 `v1.2.3-rc.1`；带预发布后缀的标签会创建预发布 Release。

发布正式版本时，在已经完成代码审查和本地验证的提交上执行：

```bash
git tag -a v1.2.3 -m "发布 v1.2.3"
git push origin v1.2.3
```

工作流会安装锁定的生产依赖，并依次执行 `composer validate --strict`、`composer test` 和 `php think route:list`。校验通过后，生成 `nova-app-download-v1.2.3.zip`，压缩包解压后直接得到项目根目录文件，并作为 GitHub Release 附件和 14 天构建产物保存。打包脚本的本地临时输出位于被 Git 忽略的 `.release/`。

已完成 `composer install --no-dev` 的环境也可以执行以下命令生成同样的本地验证包：

```bash
composer run-script package:release -- --version=v1.2.3
```

本地打包命令需要启用 PHP 的 `ZipArchive` 扩展；缺少扩展、版本标签非法、必需交付文件缺失或 ZIP 出现敏感文件时会直接失败。

需要补发时，在 GitHub Actions 的“发布源码包”工作流中手动运行，并填写一个已经推送到远端的合法版本标签。若同名 Release 已存在，工作流仅替换同名 ZIP 附件，不覆盖原有 Release 说明。

`public/static/` 是源码交付的一部分，不能只保留在本机或继续被 Git 忽略；首次启用自动发布前，应将其中的样式、脚本、字体、图片和第三方许可证一并纳入版本提交。

## 必须包含

- `app/`、`config/`、`database/`、`data/`、`public/`、`route/`、`view/`
- `deploy/` 和 `README.md`
- `tests/`
- `vendor/`，用于无 Composer 环境直接安装
- `public/static/` 下的应用样式、脚本、字体、图片及第三方许可证
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
- `.idea/`、`.vscode/`、`.git/`、`.github/`、`scripts/`
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
