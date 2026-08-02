<?php

declare(strict_types=1);

use app\common\exception\AdminException;
use app\common\exception\DownloadException;
use app\common\service\CaptchaService;
use app\common\service\DownloadService;
use app\common\service\EnvFileService;
use app\common\service\ImageUploadService;
use app\common\service\LogCleanupService;
use app\common\service\ThemeColorService;
use app\common\service\UploadLimitService;
use think\App;
use think\file\UploadedFile;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * 不依赖测试框架的关键路径测试，适合售卖包在未安装数据库前直接执行。
 */
final class CriticalPathTest
{
    /** @var int */
    private $assertions = 0;

    /**
     * @return void
     */
    public function run(): void
    {
        $this->testExternalShareUrlBoundary();
        $this->testLocalApkBoundary();
        $this->testImageUploadAndSvgSanitizing();
        $this->testLogCleanupRuntimeFiles();
        $this->testUploadLimitConfiguration();
        $this->testGeneratedEnvironmentDefaults();
        $this->testCaptchaInputBoundary();
        $this->testAdminInterfaceContracts();
        $this->testThemeColorService();
        $this->testDeliveryArtifacts();

        fwrite(STDOUT, sprintf("关键路径测试通过，共 %d 项断言。\n", $this->assertions));
    }

    /**
     * 校验主题色规范化、派生与 CSS 输出。
     *
     * @return void
     */
    private function testThemeColorService(): void
    {
        $service = new ThemeColorService();

        $this->same('#4a9fd8', $service->normalize('4A9FD8'), '应补全 # 并转为小写');
        $this->same('#44aaff', $service->normalize('#4af'), '三位色值应展开为六位');
        $this->same(ThemeColorService::DEFAULT_COLOR, $service->normalize('not-a-color'), '非法色值应回退默认色');
        $this->isTrue($service->isValid('#0d9488'), '合法色值应通过校验');
        $this->isTrue(!$service->isValid('#gg0000'), '非法十六进制应拒绝');

        $palette = $service->palette('#4a9fd8');
        $this->same('#4a9fd8', $palette['accent'], '色板主色应与输入一致');
        $this->same('#e9f3fa', $palette['accent_soft'], '浅底色应与白混合保留品牌色相');
        $this->same('#ffffff', $palette['on_accent'], '深色主色上的前景应为白色');
        $this->isTrue(
            preg_match('/^\d{1,3}, \d{1,3}, \d{1,3}$/', $palette['accent_rgb']) === 1,
            '色板应提供 accent_rgb'
        );
        $this->isTrue(
            preg_match('/^#[0-9a-f]{6}$/', $palette['accent_hover']) === 1
            && preg_match('/^#[0-9a-f]{6}$/', $palette['accent_soft']) === 1
            && preg_match('/^#[0-9a-f]{6}$/', $palette['selection_ink']) === 1
            && preg_match('/^#[0-9a-f]{6}$/', $palette['on_accent']) === 1,
            '派生色应为合法十六进制'
        );

        $lightPalette = $service->palette('#fbbf24');
        $this->same('#0f172a', $lightPalette['on_accent'], '极浅主色上的前景应切换为深色以保证对比度');

        $css = $service->cssOverrides('#4f46e5');
        $this->isTrue(
            strpos($css, '--nova-accent:#4f46e5;') !== false
            && strpos($css, '--nova-accent-rgb:') !== false
            && strpos($css, '--nova-selection-ink:') !== false
            && strpos($css, '--nova-on-accent:') !== false
            && strpos($css, '.form-switch .form-check-input:focus') !== false,
            '主题 CSS 应覆盖品牌变量、前景色与开关焦点色'
        );

        $form = $service->formData('#059669');
        $this->same('#059669', $form['theme_color'], '表单数据应返回规范化主色');
        $this->isTrue(count($form['theme_presets']) >= 6, '应提供多组预设主题色');
        $selected = array_filter($form['theme_presets'], static function (array $preset): bool {
            return !empty($preset['selected']);
        });
        $this->isTrue(count($selected) === 1, '匹配预设时应仅有一个选中项');
    }

    /**
     * @return void
     */
    private function testExternalShareUrlBoundary(): void
    {
        $serviceReflector = new ReflectionClass(DownloadService::class);
        /** @var DownloadService $service */
        $service = $serviceReflector->newInstanceWithoutConstructor();
        $method = $serviceReflector->getMethod('externalShareTarget');
        $method->setAccessible(true);

        $this->same(
            'https://example.invalid/share?id=42',
            $method->invoke($service, 'https://example.invalid/share?id=42'),
            '网盘下载应允许普通 HTTPS 分享地址'
        );
        $this->throwsDownloadException(static function () use ($method, $service): void {
            $method->invoke($service, 'https://example.invalid/share"data-x="unsafe');
        }, '网盘地址中的原始引号必须被拒绝');
        $this->throwsDownloadException(static function () use ($method, $service): void {
            $method->invoke($service, 'https://user:password@example.invalid/share');
        }, '网盘地址不能包含用户信息');
        $this->throwsDownloadException(static function () use ($method, $service): void {
            $method->invoke($service, 'javascript:alert(1)');
        }, '网盘地址必须使用 HTTP(S) 协议');
    }

    /**
     * @return void
     */
    private function testLocalApkBoundary(): void
    {
        $rootPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $uploadDirectory = $rootPath . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'apk';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('无法创建 APK 测试目录。');
        }
        $fixture = $uploadDirectory . DIRECTORY_SEPARATOR . 'critical-path-fixture.apk';
        if (file_put_contents($fixture, "PK\x03\x04test") === false) {
            throw new RuntimeException('无法创建 APK 测试文件。');
        }

        try {
            $serviceReflector = new ReflectionClass(DownloadService::class);
            /** @var DownloadService $service */
            $service = $serviceReflector->newInstanceWithoutConstructor();
            $appProperty = $serviceReflector->getProperty('app');
            $appProperty->setAccessible(true);
            $appProperty->setValue($service, new App($rootPath));
            $method = $serviceReflector->getMethod('localTarget');
            $method->setAccessible(true);

            $this->same(
                '/uploads/apk/critical-path-fixture.apk',
                $method->invoke($service, '/uploads/apk/critical-path-fixture.apk'),
                '目录内 APK 应允许跳转'
            );
            $this->throwsDownloadException(static function () use ($method, $service): void {
                $method->invoke($service, '/uploads/apk/../config.php');
            }, '路径穿越必须被拒绝');
            $this->throwsDownloadException(static function () use ($method, $service): void {
                $method->invoke($service, '/uploads/apk/missing.apk');
            }, '缺失文件必须被拒绝');
        } finally {
            @unlink($fixture);
        }
    }

    /**
     * @return void
     */
    private function testImageUploadAndSvgSanitizing(): void
    {
        $rootPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $service = new ImageUploadService(new App($rootPath));
        $temporaryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'nova-image-' . bin2hex(random_bytes(8)) . '.png';
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        if (!is_string($png) || file_put_contents($temporaryPath, $png) === false) {
            throw new RuntimeException('无法创建图片上传测试文件。');
        }

        $storedPath = '';
        $storedFaviconPath = '';
        try {
            $uploadedFile = new UploadedFile(
                $temporaryPath,
                'fixture.png',
                'image/png',
                UPLOAD_ERR_OK,
                true
            );
            $storedPath = $service->store($uploadedFile, 'icons');
            $this->isTrue(
                (bool) preg_match('#^/uploads/images/icons/\d{6}/[a-f0-9]{32}\.png$#', $storedPath),
                '图片应保存到随机分类路径'
            );
            $storedFile = $rootPath . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
            $this->isTrue(is_file($storedFile), '上传后的图片文件应存在');

            // 信任品牌使用 brands 分类，需与 icons 一样可写。
            $brandTemporaryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'nova-brand-' . bin2hex(random_bytes(8)) . '.png';
            if (file_put_contents($brandTemporaryPath, $png) === false) {
                throw new RuntimeException('无法创建信任品牌图片测试文件。');
            }
            $brandUploaded = new UploadedFile(
                $brandTemporaryPath,
                'brand.png',
                'image/png',
                UPLOAD_ERR_OK,
                true
            );
            $brandPath = $service->store($brandUploaded, 'brands');
            $this->isTrue(
                (bool) preg_match('#^/uploads/images/brands/\d{6}/[a-f0-9]{32}\.png$#', $brandPath),
                '信任品牌图标应保存到 brands 分类路径'
            );
            $brandStoredFile = $rootPath . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $brandPath);
            $this->isTrue(is_file($brandStoredFile), '信任品牌上传后的图片文件应存在');

            $faviconTemporaryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'nova-favicon-' . bin2hex(random_bytes(8)) . '.ico';
            $faviconBitmap = pack('V', 40)
                . pack('V', 16)
                . pack('V', 32)
                . pack('v', 1)
                . pack('v', 32)
                . pack('V', 0)
                . pack('V', 1024)
                . pack('V', 0)
                . pack('V', 0)
                . pack('V', 0)
                . pack('V', 0)
                . str_repeat("\x00", 1088);
            $faviconContents = pack('vvv', 0, 1, 1)
                . pack('CCCCvvVV', 16, 16, 0, 0, 1, 32, strlen($faviconBitmap), 22)
                . $faviconBitmap;
            if (file_put_contents($faviconTemporaryPath, $faviconContents) === false) {
                throw new RuntimeException('无法创建 ICO 测试文件。');
            }
            $faviconUploaded = new UploadedFile(
                $faviconTemporaryPath,
                'favicon.ico',
                'application/octet-stream',
                UPLOAD_ERR_OK,
                true
            );
            $storedFaviconPath = $service->store($faviconUploaded, 'favicons');
            $this->isTrue(
                (bool) preg_match('#^/uploads/images/favicons/\d{6}/[a-f0-9]{32}\.ico$#', $storedFaviconPath),
                '网站图标应保存到 favicons 分类路径'
            );
            $faviconStoredFile = $rootPath . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $storedFaviconPath);
            $this->isTrue(is_file($faviconStoredFile), '上传后的 ICO 文件应存在');

            $invalidFaviconPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'nova-invalid-favicon-' . bin2hex(random_bytes(8)) . '.ico';
            if (file_put_contents($invalidFaviconPath, 'invalid favicon') === false) {
                throw new RuntimeException('无法创建损坏 ICO 测试文件。');
            }
            $invalidFavicon = new UploadedFile(
                $invalidFaviconPath,
                'invalid.ico',
                'application/octet-stream',
                UPLOAD_ERR_OK,
                true
            );
            $this->throwsException(AdminException::class, static function () use ($service, $invalidFavicon): void {
                $service->store($invalidFavicon, 'favicons');
            }, '损坏 ICO 文件必须被拒绝');

            $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)">
  <script>alert(1)</script>
  <rect id="safe" width="10" height="10" fill="url(http://attacker.example/a)" />
</svg>
SVG;
            $method = (new ReflectionClass($service))->getMethod('sanitizeSvg');
            $method->setAccessible(true);
            $cleanSvg = (string) $method->invoke($service, $svg);
            $this->isTrue(strpos($cleanSvg, '<rect') !== false, 'SVG 应保留安全图元');
            $this->isTrue(stripos($cleanSvg, '<script') === false, 'SVG 应移除脚本节点');
            $this->isTrue(stripos($cleanSvg, 'onload') === false, 'SVG 应移除事件属性');
            $this->isTrue(stripos($cleanSvg, 'http://attacker.example') === false, 'SVG 应移除外链资源');

            // BOM + text/plain 场景：设计软件导出的 SVG 常见组合。
            $bomSvgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'nova-bom-' . bin2hex(random_bytes(8)) . '.svg';
            $bomSvg = "\xEF\xBB\xBF<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 10 10\">"
                . '<circle cx="5" cy="5" r="4" fill="#111"/></svg>';
            if (file_put_contents($bomSvgPath, $bomSvg) === false) {
                throw new RuntimeException('无法创建 BOM SVG 测试文件。');
            }
            $bomUploaded = new UploadedFile(
                $bomSvgPath,
                'logo.svg',
                'text/plain',
                UPLOAD_ERR_OK,
                true
            );
            $bomStoredPath = $service->store($bomUploaded, 'brands');
            $this->isTrue(
                (bool) preg_match('#^/uploads/images/brands/\d{6}/[a-f0-9]{32}\.svg$#', $bomStoredPath),
                '带 BOM 的 SVG 品牌图标应可上传'
            );
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
            if (isset($brandTemporaryPath) && is_file($brandTemporaryPath)) {
                @unlink($brandTemporaryPath);
            }
            if (isset($faviconTemporaryPath) && is_file($faviconTemporaryPath)) {
                @unlink($faviconTemporaryPath);
            }
            if (isset($invalidFaviconPath) && is_file($invalidFaviconPath)) {
                @unlink($invalidFaviconPath);
            }
            if (isset($bomSvgPath) && is_file($bomSvgPath)) {
                @unlink($bomSvgPath);
            }
            foreach ([$storedPath, $brandPath ?? '', $storedFaviconPath, $bomStoredPath ?? ''] as $relative) {
                if ($relative === '') {
                    continue;
                }
                $absolute = $rootPath . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (is_file($absolute)) {
                    @unlink($absolute);
                }
            }
        }
    }

    /**
     * @return void
     */
    private function testLogCleanupRuntimeFiles(): void
    {
        $rootPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'nova-log-cleanup-' . bin2hex(random_bytes(8));
        $nestedDirectory = $temporaryDirectory . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'log';
        if (!mkdir($nestedDirectory, 0775, true) && !is_dir($nestedDirectory)) {
            throw new RuntimeException('无法创建日志清理测试目录。');
        }

        $oldLog = $nestedDirectory . DIRECTORY_SEPARATOR . 'old.log';
        $recentLog = $nestedDirectory . DIRECTORY_SEPARATOR . 'recent.log';
        $oldText = $nestedDirectory . DIRECTORY_SEPARATOR . 'old.txt';
        foreach ([$oldLog, $recentLog, $oldText] as $path) {
            if (file_put_contents($path, 'fixture') === false) {
                throw new RuntimeException('无法创建日志清理测试文件。');
            }
        }
        $oldTimestamp = time() - (100 * 86400);
        if (!touch($oldLog, $oldTimestamp) || !touch($oldText, $oldTimestamp)) {
            throw new RuntimeException('无法设置日志清理测试时间。');
        }

        try {
            $service = new LogCleanupService(new App($rootPath), $temporaryDirectory);
            $cutoffTimestamp = time() - (90 * 86400);
            $this->same(1, $service->cleanupRuntimeLogs($cutoffTimestamp, true), '预演应统计过期日志');
            $this->isTrue(is_file($oldLog), '预演不能删除过期日志');
            $this->same(1, $service->cleanupRuntimeLogs($cutoffTimestamp, false), '执行应删除过期日志');
            $this->isTrue(!is_file($oldLog), '过期日志应被删除');
            $this->isTrue(is_file($recentLog), '保留期内日志不能被删除');
            $this->isTrue(is_file($oldText), '非日志文件不能被删除');
            $this->same(90, LogCleanupService::parseRetentionDays('90'), '应解析合法保留天数');
            $this->throwsException(InvalidArgumentException::class, static function (): void {
                LogCleanupService::parseRetentionDays('0');
            }, '零天保留期必须被拒绝');
            $this->throwsException(InvalidArgumentException::class, static function (): void {
                LogCleanupService::parseRetentionDays('90.5');
            }, '非整数保留期必须被拒绝');
        } finally {
            foreach ([$oldLog, $recentLog, $oldText] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($nestedDirectory);
            @rmdir(dirname($nestedDirectory));
            @rmdir($temporaryDirectory);
        }
    }

    /**
     * @return void
     */
    private function testGeneratedEnvironmentDefaults(): void
    {
        $contents = (new EnvFileService())->build([
            'hostname' => '127.0.0.1',
            'hostport' => 3306,
            'database' => 'nova_test',
            'username' => 'nova_user',
            'password' => 'temporary-password',
            'charset' => 'utf8mb4',
            'prefix' => 'nova_',
            'create_database' => false,
        ], str_repeat('a', 64));
        $normalizedContents = str_replace("\r\n", "\n", $contents);

        $this->isTrue(strpos($normalizedContents, "[COOKIE]\nSECURE = false") !== false, '安装配置应默认关闭安全 Cookie');
        $this->isTrue(strpos($normalizedContents, "[LOG]\nMAX_FILES = 30") !== false, '安装配置应包含日志文件上限');
        $this->isTrue(
            strpos($normalizedContents, "[UPLOAD]\nAPK_MAX_MB = 300\nIMAGE_MAX_MB = 5\nSVG_MAX_MB = 1") !== false,
            '安装配置应包含三类上传上限'
        );
    }

    /**
     * @return void
     */
    private function testUploadLimitConfiguration(): void
    {
        $custom = new UploadLimitService([
            'apk_max_mb' => '512',
            'image_max_mb' => 12,
            'svg_max_mb' => '3',
        ]);
        $this->same(512, $custom->apkMaxMb(), '应解析字符串形式的 APK 上限');
        $this->same(12 * 1048576, $custom->imageMaxBytes(), '应把图片 MB 上限换算为字节');
        $this->same(3, $custom->svgMaxMb(), '应解析 SVG 上限');

        $bounded = new UploadLimitService([
            'apk_max_mb' => 0,
            'image_max_mb' => 999,
            'svg_max_mb' => -8,
        ]);
        $this->same(1, $bounded->apkMaxMb(), '零值 APK 上限应钳制为 1MB');
        $this->same(UploadLimitService::MAXIMUM_IMAGE_MAX_MB, $bounded->imageMaxMb(), '图片上限不能超过安全边界');
        $this->same(1, $bounded->svgMaxMb(), '负数 SVG 上限应钳制为 1MB');

        $invalid = new UploadLimitService(['apk_max_mb' => 'not-a-number']);
        $this->same(UploadLimitService::DEFAULT_APK_MAX_MB, $invalid->apkMaxMb(), '非法配置应回退默认值');
        $this->throwsException(InvalidArgumentException::class, static function (): void {
            UploadLimitService::normalizeMegabytes(1, 0, 1);
        }, '无效默认边界必须被拒绝');
    }

    /**
     * @return void
     */
    private function testCaptchaInputBoundary(): void
    {
        $this->same(4, CaptchaService::answerLength(), '验证码应为 4 位');
        $this->same('A2B3', CaptchaService::normalizeAnswer(' a2b3 '), '验证码应忽略首尾空白和大小写');
        $this->same('A2B3', CaptchaService::normalizeAnswer('a 2 b 3'), '验证码应忽略中间空白并统一为大写');
        $this->same('A2B3', CaptchaService::normalizeAnswer('A2b3'), '验证码英文不区分大小写');
        $this->same('', CaptchaService::normalizeAnswer(" \t\r\n"), '空验证码应保持为空');
    }

    /**
     * @return void
     */
    private function testAdminInterfaceContracts(): void
    {
        $rootPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $downloadTemplate = $this->readFixture($rootPath . 'app/admin/view/site/download.html');
        $siteTemplate = $this->readFixture($rootPath . 'app/admin/view/site/index.html');
        $layoutTemplate = $this->readFixture($rootPath . 'app/admin/view/layout/saas.html');
        $loginTemplate = $this->readFixture($rootPath . 'app/admin/view/auth/login.html');
        $installLayout = $this->readFixture($rootPath . 'app/install/view/layout/base.html');
        $contentIndexTemplate = $this->readFixture($rootPath . 'app/admin/view/content/index.html');
        $contentModalTemplate = $this->readFixture($rootPath . 'app/admin/view/content/form_modal.html');
        $contentFormTemplate = $this->readFixture($rootPath . 'app/admin/view/content/form_body.html');
        $contentController = $this->readFixture($rootPath . 'app/admin/controller/Content.php');
        $contentResourceService = $this->readFixture($rootPath . 'app/common/service/ContentResourceService.php');
        $adminScript = $this->readFixture($rootPath . 'public/static/admin/admin.js');
        $adminStyles = $this->readFixture($rootPath . 'public/static/admin/admin.css');
        $imageUploadService = $this->readFixture($rootPath . 'app/common/service/ImageUploadService.php');
        $dashboardScript = $this->readFixture($rootPath . 'public/static/admin/dashboard.js');
        $designTokens = $this->readFixture($rootPath . 'public/static/ui/tokens.css');
        $loginScript = $this->readFixture($rootPath . 'public/static/admin/login.js');

        $this->isTrue(strpos($designTokens, '--nova-accent-rgb:') !== false, '设计令牌应提供品牌色 RGB 变量');
        $this->isTrue(strpos($designTokens, '--nova-selection-ink:') !== false, '设计令牌应提供选中态文字色');
        $this->isTrue(strpos($designTokens, '--nova-on-accent:') !== false, '设计令牌应提供强调色前景色');
        $this->isTrue(
            strpos($designTokens, '--nova-accent-soft-strong:') !== false
                && strpos($designTokens, '--nova-accent-border:') !== false
                && strpos($designTokens, '--nova-accent-glow:') !== false,
            '设计令牌应提供可随主题切换的强调派生变量'
        );
        $indexStyles = $this->readFixture($rootPath . 'public/static/index/index.css');
        $this->isTrue(
            strpos($indexStyles, '--hero-muted') !== false
                && strpos($indexStyles, 'rgba(var(--nova-accent-rgb)') !== false
                && strpos($indexStyles, 'var(--accent-soft)') !== false,
            '下载页样式应使用主题色氛围与派生令牌，而非仅硬编码灰阶'
        );
        $this->isTrue(
            strpos($adminStyles, '.form-check-input:checked') !== false
                && strpos($adminStyles, 'background-color: var(--nova-accent)') !== false,
            '后台表单选中态应使用品牌强调色覆盖 Bootstrap 默认蓝'
        );
        $this->isTrue(strpos($downloadTemplate, '测试下载') !== false, '下载配置页应提供测试下载入口');
        $this->isTrue(strpos($downloadTemplate, 'data-max-bytes="{$uploadLimits.apk_max_bytes}"') !== false, 'APK 表单应读取共享上限');
        $this->isTrue(
            strpos($siteTemplate, 'data-config-tabs') !== false
                && strpos($siteTemplate, 'config-workspace') !== false
                && strpos($siteTemplate, 'site-config-workspace') !== false
                && strpos($siteTemplate, 'site-tab-brand') !== false
                && strpos($siteTemplate, 'site-tab-theme') !== false
                && strpos($siteTemplate, 'data-theme-color-picker') !== false
                && strpos($siteTemplate, 'name="theme_color"') !== false
                && strpos($siteTemplate, 'site-tab-logs') !== false
                && strpos($siteTemplate, 'config-card') !== false
                && strpos($siteTemplate, 'config-card-icon') !== false
                && strpos($siteTemplate, 'config-tabs-shell') !== false
                && strpos($siteTemplate, 'config-asset-tile') !== false
                && strpos($adminStyles, 'config-page-header') !== false
                && strpos($adminStyles, 'theme-color-layout') !== false
                && strpos($adminStyles, 'config-tabs-shell') !== false
                && strpos($adminStyles, 'site-config-panel') !== false
                && strpos($adminStyles, 'safe-area-inset-bottom') !== false
                && strpos($adminScript, 'scrollTabIntoView') !== false
                && strpos($adminScript, 'updateTabsEdgeFade') !== false,
            '站点配置页应提供分组卡片、图标选项卡与移动端适配布局'
        );
        $heroTabPos = strpos($siteTemplate, 'id="site-tab-hero-btn"');
        $themeTabPos = strpos($siteTemplate, 'id="site-tab-theme-btn"');
        $this->isTrue(
            $heroTabPos !== false
                && $themeTabPos !== false
                && $heroTabPos < $themeTabPos,
            '站点配置选项卡顺序应为品牌 → 首屏 → 主题'
        );
        $this->isTrue(
            strpos($siteTemplate, 'name="favicon"') !== false
                && strpos($siteTemplate, 'data-target="favicon"') !== false
                && strpos($siteTemplate, 'data-category="favicons"') !== false
                && strpos($siteTemplate, '.ico,.png,.svg') !== false
                && strpos($imageUploadService, "'favicons'") !== false
                && strpos($imageUploadService, 'validateIco') !== false,
            '站点配置应提供独立网站图标上传，并校验 ICO 文件内容'
        );
        $this->isTrue(
            strpos($adminScript, 'initializeThemeColorPickers') !== false
                && strpos($adminStyles, '.theme-preset-swatch') !== false
                && strpos($dashboardScript, '--nova-accent') !== false,
            '主题配色应提供选择器初始化，图表应读取设计令牌颜色'
        );
        $indexTemplate = $this->readFixture($rootPath . 'app/index/view/index/index.html');
        $this->isTrue(
            strpos($indexTemplate, 'themeStyle') !== false
                && strpos($layoutTemplate, 'themeStyle') !== false
                && strpos($loginTemplate, 'themeStyle') !== false,
            '前台与后台布局应注入可配置主题样式'
        );
        $downloadErrorTemplate = $this->readFixture($rootPath . 'app/index/view/index/download_error.html');
        $downloadErrorStyles = $this->readFixture($rootPath . 'public/static/index/download-error.css');
        $downloadOtherTemplate = $this->readFixture($rootPath . 'app/index/view/index/download_other.html');
        $downloadOtherStyles = $this->readFixture($rootPath . 'public/static/index/download-other.css');
        $downloadOtherScript = $this->readFixture($rootPath . 'public/static/index/download-other.js');
        $downloadService = $this->readFixture($rootPath . 'app/common/service/DownloadService.php');
        $this->isTrue(
            strpos($downloadErrorTemplate, 'download-error-card') !== false
                && strpos($downloadErrorTemplate, 'themeStyle') !== false
                && strpos($downloadErrorTemplate, 'download-error-btn-primary') !== false
                && strpos($downloadErrorTemplate, 'href="/download"') !== false,
            '下载失败页应提供品牌化卡片、主题注入与重试入口'
        );
        $this->isTrue(
            strpos($downloadErrorStyles, 'var(--nova-accent') !== false
                && strpos($downloadErrorStyles, 'var(--nova-accent-rgb)') !== false
                && strpos($downloadErrorStyles, 'download-error-orb') !== false,
            '下载失败页样式应使用主题强调色令牌'
        );
        $this->isTrue(
            strpos($downloadService, 'errorPageData') !== false
                && strpos($downloadService, 'errorTitle') !== false
                && strpos($downloadService, 'themeColor') !== false,
            '下载服务应为失败页组装品牌与主题上下文'
        );
        $this->isTrue(
            strpos($downloadService, 'externalShareTarget') !== false
                && strpos($downloadService, 'externalShareResponse') !== false
                && strpos($downloadService, "'other'") !== false,
            '下载服务应将网盘下载作为浏览器中转页处理，而非服务端解析'
        );
        $this->isTrue(
            strpos($downloadService, 'LanzouService') === false
                && !is_file($rootPath . 'app/common/service/LanzouService.php'),
            '应移除蓝奏云解析服务与依赖'
        );
        $this->isTrue(
            strpos($downloadOtherTemplate, 'data-download-other') !== false
                && strpos($downloadOtherTemplate, 'data-copy-share-password') !== false
                && strpos($downloadOtherTemplate, 'href="{$shareUrl}"') !== false
                && strpos($downloadOtherTemplate, 'rel="noopener noreferrer"') !== false
                && strpos($downloadOtherTemplate, 'referrerpolicy="no-referrer"') !== false
                && strpos($downloadOtherTemplate, '网盘下载') !== false,
            '网盘下载中转页应展示密码、保留安全新窗口链接并支持复制'
        );
        $this->isTrue(
            strpos($downloadOtherStyles, '.download-other-open-link') !== false
                && strpos($downloadOtherScript, 'navigator.clipboard') !== false
                && strpos($downloadOtherScript, "document.execCommand('copy')") !== false,
            '网盘下载中转页应提供响应式样式与剪贴板降级方案'
        );
        $installSql = $this->readFixture($rootPath . 'database/install.sql');
        $siteConfigService = $this->readFixture($rootPath . 'app/common/service/SiteConfigService.php');
        $this->isTrue(
            strpos($installSql, '`theme_color`') !== false
                && strpos($siteConfigService, 'theme_color') !== false
                && strpos($siteConfigService, 'ensureThemeColorColumn') !== false,
            '数据库与配置服务应持久化并兼容升级 theme_color'
        );
        $this->isTrue(
            strpos($installSql, '`favicon`') !== false
                && strpos($siteConfigService, 'ensureFaviconColumn') !== false
                && strpos($siteConfigService, 'isAllowedFaviconPath') !== false
                && strpos($siteConfigService, 'DEFAULT_FAVICON_PATH') !== false,
            '数据库与配置服务应持久化并兼容升级网站图标'
        );
        $this->isTrue(
            strpos($installSql, '`other_url`') !== false
                && strpos($installSql, '`other_pwd`') !== false
                && strpos($siteConfigService, 'ensureOtherDownloadColumns') !== false
                && strpos($siteConfigService, 'other_password_configured') !== false
                && strpos($siteConfigService, "unset(\$config['lanzou_pwd'], \$config['other_pwd']") !== false,
            '数据库与配置服务应持久化网盘下载地址和加密密码，并禁止公开密码字段'
        );
        $this->isTrue(
            strpos($downloadTemplate, 'config-workspace') !== false
                && strpos($downloadTemplate, 'data-download-config') !== false
                && strpos($downloadTemplate, 'data-download-mode-select') !== false
                && strpos($downloadTemplate, 'name="download_mode"') !== false
                && strpos($downloadTemplate, 'data-download-panel="local"') !== false
                && strpos($downloadTemplate, 'data-download-panel="lanzou"') === false
                && strpos($downloadTemplate, 'data-download-panel="other"') !== false
                && strpos($downloadTemplate, 'value="lanzou"') === false
                && strpos($downloadTemplate, 'value="other"') !== false
                && strpos($downloadTemplate, '网盘下载') !== false
                && strpos($downloadTemplate, 'name="other_url"') !== false
                && strpos($downloadTemplate, 'name="other_pwd"') !== false
                && strpos($downloadTemplate, 'type="text"') !== false
                && strpos($downloadTemplate, 'value="{$values.other_pwd') !== false
                && strpos($downloadTemplate, 'name="clear_other_pwd"') === false
                && strpos($downloadTemplate, 'form="downloadConfigForm"') !== false
                && strpos($downloadTemplate, 'apk-status-card') !== false
                && strpos($adminScript, 'initializeDownloadConfigPage') !== false
                && strpos($adminScript, "lanzou: '服务端解析") === false
                && strpos($adminScript, "modeSelect.value === 'other'") !== false,
            '下载配置页应为单页：仅本地 APK 与网盘下载，不再提供蓝奏解析'
        );
        $this->isTrue(
            strpos($adminStyles, '.config-tabs') !== false
                && strpos($adminScript, 'initializeConfigTabs') !== false,
            '配置选项卡应提供统一样式与状态记忆初始化'
        );
        $this->isTrue(strpos($layoutTemplate, 'data-sidebar-toggle') !== false, '后台应提供桌面侧栏折叠控件');
        // 侧栏应按功能流排序：配置先于落地页内容，信任品牌先于友情链接。
        $sitePos = strpos($layoutTemplate, 'href="/admin/site"');
        $downloadPos = strpos($layoutTemplate, 'href="/admin/download"');
        $navPos = strpos($layoutTemplate, 'href="/admin/content/nav"');
        $trustPos = strpos($layoutTemplate, 'href="/admin/content/trust"');
        $friendPos = strpos($layoutTemplate, 'href="/admin/content/friend"');
        $this->isTrue(
            strpos($layoutTemplate, '>配置</') !== false
                && strpos($layoutTemplate, '>落地页</') !== false
                && $sitePos !== false
                && $downloadPos !== false
                && $navPos !== false
                && $trustPos !== false
                && $friendPos !== false
                && $sitePos < $downloadPos
                && $downloadPos < $navPos
                && $trustPos < $friendPos,
            '侧栏应按 配置→落地页 分组，且信任品牌位于友情链接之前'
        );
        $this->isTrue(strpos($layoutTemplate, 'topbar-user-menu') !== false, '后台顶栏应提供用户菜单');
        $this->isTrue(
            strpos($layoutTemplate, 'data-admin-page-title') !== false
                && strpos($layoutTemplate, 'data-admin-page-actions') !== false
                && strpos($layoutTemplate, 'topbar-breadcrumb') === false
                && strpos($layoutTemplate, 'page-heading') === false,
            '后台主标题应位于顶栏，且不再展示面包屑与内容区大标题'
        );
        $this->isTrue(
            strpos($adminScript, 'data-admin-page-title') !== false
                && strpos($adminScript, 'data-admin-page-actions') !== false
                && strpos($adminScript, 'data-admin-breadcrumb') === false,
            '异步导航应同步替换顶栏标题与操作区'
        );
        $this->isTrue(
            strpos($layoutTemplate, 'sidebar-account') === false
                && strpos($layoutTemplate, 'topbar-user-menu') !== false,
            '侧栏底部不应再保留账号入口，账号操作统一放在顶栏用户菜单'
        );
        $this->isTrue(
            strpos($adminStyles, 'sidebar-transitions-ready') !== false
                && strpos($adminScript, 'enableTransitions') !== false,
            '侧栏过渡应仅在用户折叠时启用，避免页面切换抖动'
        );
        $this->isTrue(
            strpos($adminStyles, 'html.admin-layout') !== false
                && strpos($adminStyles, 'position: relative !important') !== false
                && strpos($layoutTemplate, 'class="admin-layout"') !== false
                && !preg_match('/\.admin-main\s*\{[^}]*margin-left:\s*var\(--nova-sidebar-width\)/s', $adminStyles),
            '桌面侧栏应采用流式布局而非 fixed+margin，避免整页滚动条导致整栏抖动'
        );
        $this->isTrue(
            strpos($adminStyles, 'sidebar-nav-interactive') !== false
                && strpos($adminStyles, '.sidebar-link.active') !== false
                && !preg_match('/\.sidebar-link\.active\s*\{[^}]*font-weight\s*:/s', $adminStyles),
            '侧栏导航选中态不得改变字重，避免切页时按钮宽度抖动'
        );
        $this->isTrue(
            strpos($layoutTemplate, 'data-admin-nav-link') !== false
                && strpos($layoutTemplate, 'data-admin-content') !== false
                && strpos($layoutTemplate, 'data-admin-navigation-progress') !== false
                && strpos($layoutTemplate, 'aria-current="page"') !== false,
            '后台布局应提供稳定的异步导航、内容替换和加载状态标记'
        );
        $this->isTrue(
            strpos($adminScript, 'navigateAdminPage') !== false
                && strpos($adminScript, 'DOMParser') !== false
                && strpos($adminScript, 'history.pushState') !== false
                && strpos($adminScript, "addEventListener('popstate'") !== false
                && strpos($adminScript, 'window.location.assign') !== false,
            '桌面侧栏应支持无刷新导航、历史恢复与异常整页回退'
        );
        $this->isTrue(
            strpos($adminScript, 'data-admin-dirty-form') !== false
                && strpos($adminScript, 'beforeunload') !== false
                && strpos($adminScript, 'confirmDiscardChanges') !== false
                && strpos($siteTemplate, 'data-admin-dirty-form') !== false
                && strpos($downloadTemplate, 'data-admin-dirty-form') !== false,
            '后台配置表单应在离开前保护未保存修改'
        );
        $siteController = $this->readFixture($rootPath . 'app/admin/controller/Site.php');
        $adminControllerSource = $this->readFixture($rootPath . 'app/admin/controller/AdminController.php');
        $this->isTrue(
            strpos($siteTemplate, 'data-admin-ajax-form') !== false
                && strpos($downloadTemplate, 'data-admin-ajax-form') !== false
                && strpos($adminScript, 'data-admin-ajax-form') !== false
                && strpos($adminScript, 'submitAdminAjaxForm') !== false
                && strpos($adminScript, 'softReloadAdminPage') !== false
                && strpos($adminControllerSource, 'function wantsJson') !== false
                && strpos($siteController, 'wantsJson()') !== false
                && strpos($siteController, 'jsonSuccess') !== false,
            '站点与下载配置应支持 AJAX 静默保存并返回 JSON'
        );
        $this->isTrue(
            strpos($indexTemplate, 'data-site-favicon') !== false
                && strpos($downloadErrorTemplate, 'data-site-favicon') !== false
                && strpos($layoutTemplate, 'data-site-favicon') !== false
                && strpos($loginTemplate, 'data-site-favicon') !== false
                && strpos($installLayout, 'data-site-favicon') !== false
                && strpos($downloadService, 'faviconUrl') !== false
                && strpos($adminControllerSource, 'resolveFaviconUrl') !== false
                && strpos($siteController, "'favicon_url'") !== false
                && strpos($adminScript, 'applySiteFavicon') !== false,
            '网站图标应覆盖前台、下载失败页、后台与登录页，并支持异步同步'
        );
        $contentTemplate = $this->readFixture($rootPath . 'app/admin/view/content/index.html');
        $visitLogTemplate = $this->readFixture($rootPath . 'app/admin/view/logs/visit.html');
        $downloadLogTemplate = $this->readFixture($rootPath . 'app/admin/view/logs/download.html');
        $contentController = $this->readFixture($rootPath . 'app/admin/controller/Content.php');
        $logsController = $this->readFixture($rootPath . 'app/admin/controller/Logs.php');
        $this->isTrue(
            strpos($contentTemplate, 'data-admin-ajax-form') !== false
                && strpos($contentTemplate, 'data-admin-ajax-reload') !== false
                && strpos($visitLogTemplate, 'data-admin-ajax-reload') !== false
                && strpos($downloadLogTemplate, 'data-admin-ajax-reload') !== false
                && strpos($contentController, 'wantsJson()') !== false
                && strpos($logsController, 'wantsJson()') !== false
                && strpos($adminScript, 'softReloadAdminPage') !== false
                && strpos($adminScript, "contentModal.hide();\n                    window.location.reload()") === false,
            '表格增删改与日志批量删除应静默提交并软刷新列表'
        );
        $this->isTrue(
            strpos($adminStyles, 'admin-navigation-progress') !== false
                && strpos($dashboardScript, 'NovaAdminDashboard') !== false
                && strpos($dashboardScript, 'const mount') !== false
                && strpos($dashboardScript, 'const unmount') !== false
                && strpos($dashboardScript, 'novaTrendCrosshair') !== false
                && strpos($dashboardScript, 'createAreaGradient') !== false
                && strpos($adminStyles, 'chart-shell') !== false
                && strpos($adminStyles, 'trend-legend') !== false,
            '异步切换应提供顶栏进度条，并美化趋势图（区域渐变、准线、图例）'
        );
        $this->isTrue(strpos($layoutTemplate, 'id="contentFormModal"') !== false, '后台布局应包含内容表单弹窗容器');
        $this->isTrue(
            strpos($layoutTemplate, 'id="adminToastContainer"') !== false
                && strpos($layoutTemplate, 'data-toast-max="3"') !== false
                && strpos($layoutTemplate, 'volist name="flashes"') !== false
                && strpos($layoutTemplate, 'admin-toast-icon') !== false
                && strpos($layoutTemplate, 'admin-toast-progress') !== false
                && strpos($layoutTemplate, 'data-toast-type') !== false
                && strpos($adminScript, 'enforceToastLimit') !== false
                && strpos($adminScript, 'showAdminToast') !== false
                && strpos($adminScript, 'createToastElement') !== false
                && strpos($adminScript, 'admin-toast--') !== false
                && strpos($adminStyles, 'admin-toast-progress') !== false
                && strpos($adminStyles, 'admin-toast--success') !== false,
            '后台 Toast 应限制最多 3 条，并提供图标、进度条与语义样式'
        );
        $flashService = $this->readFixture($rootPath . 'app/common/service/AdminFlashService.php');
        $this->isTrue(
            strpos($flashService, 'MAX_ITEMS = 3') !== false
                && strpos($flashService, 'function push') !== false
                && strpos($flashService, 'function pull') !== false,
            'Flash 服务应按队列保留最新 3 条提示'
        );
        $this->isTrue(
            strpos($layoutTemplate, '{$siteName}') !== false
                && strpos($layoutTemplate, 'admin-brand-name') !== false
                && strpos($layoutTemplate, 'NOVA<span>ADMIN</span>') === false,
            '侧栏顶部应展示软件名称而非固定 NOVA 文案'
        );
        $adminController = $this->readFixture($rootPath . 'app/admin/controller/AdminController.php');
        $this->isTrue(
            strpos($adminController, 'resolveSiteName') !== false
                && strpos($adminController, 'SiteConfigService') !== false,
            '后台基类应从站点配置读取软件名称'
        );
        $siteConfigService = $this->readFixture($rootPath . 'app/common/service/SiteConfigService.php');
        $this->isTrue(
            strpos($siteConfigService, "LIKE 'theme_color'") !== false
                && strpos($siteConfigService, "LIKE ?', ['theme_color']") === false
                && strpos($siteConfigService, 'getSchemaInfo') !== false
                && strpos($siteConfigService, 'ensureThemeColorColumn(true)') !== false,
            'theme_color 列迁移不得使用 SHOW COLUMNS 预处理占位符，并应在保存前强制刷新 schema'
        );
        $this->isTrue(
            substr_count($contentIndexTemplate, 'data-content-modal-url') >= 3,
            '内容列表的新建、编辑和空状态入口都应使用弹窗'
        );
        $this->isTrue(
            strpos($contentIndexTemplate, 'data-batch-table') !== false
                && strpos($contentIndexTemplate, 'data-batch-item') !== false
                && strpos($contentIndexTemplate, 'batch-delete') !== false
                && strpos($contentController, 'function batchDelete') !== false
                && strpos($adminScript, 'initializeBatchTables') !== false,
            '内容列表应支持批量选择与批量删除'
        );
        $this->isTrue(
            strpos($contentIndexTemplate, 'data-content-status-form') !== false
                && strpos($contentIndexTemplate, 'name="status"') !== false
                && strpos($contentIndexTemplate, 'data-content-status-value') !== false
                && strpos($contentIndexTemplate, 'data-content-status-switch') !== false
                && strpos($contentIndexTemplate, 'role="switch"') !== false
                && strpos($contentIndexTemplate, 'bi-pause-circle') === false
                && strpos($contentIndexTemplate, 'bi-play-circle') === false
                && strpos($adminStyles, '.content-status-switch') !== false
                && strpos($adminScript, 'initializeContentStatusSwitches') !== false
                && strpos($adminScript, 'switchInput.disabled = true') !== false
                && strpos($adminScript, "switchInput.checked = currentStatus === '1';") !== false,
            '内容列表状态列应使用可回滚的即时保存开关，并移除启停图标操作'
        );
        $this->isTrue(
            strpos($contentController, '$this->requestedStatus()') !== false
                && strpos($contentController, 'private function requestedStatus(): ?int') !== false
                && strpos($contentResourceService, 'public function toggle(string $resource, int $id, ?int $targetStatus = null): int') !== false
                && strpos($contentResourceService, '$targetStatus !== 0 && $targetStatus !== 1') !== false
                && strpos($contentResourceService, '$status = $targetStatus ??') !== false,
            '内容状态开关应严格提交目标状态，并兼容旧版反转请求'
        );
        $visitLogTemplate = $this->readFixture($rootPath . 'app/admin/view/logs/visit.html');
        $downloadLogTemplate = $this->readFixture($rootPath . 'app/admin/view/logs/download.html');
        $this->isTrue(
            strpos($contentIndexTemplate, 'class="table-responsive admin-table-scroll"') !== false
                && strpos($contentIndexTemplate, 'role="region"') !== false
                && strpos($contentIndexTemplate, 'tabindex="0"') !== false
                && strpos($contentIndexTemplate, 'aria-label="内容列表，可横向滚动查看"') !== false
                && strpos($visitLogTemplate, 'class="table-responsive admin-table-scroll"') !== false
                && strpos($visitLogTemplate, 'aria-label="访问日志列表，可横向滚动查看"') !== false
                && strpos($downloadLogTemplate, 'class="table-responsive admin-table-scroll"') !== false
                && strpos($downloadLogTemplate, 'aria-label="下载日志列表，可横向滚动查看"') !== false,
            '后台列表应提供可聚焦的横向滚动表格容器'
        );
        $this->isTrue(
            preg_match('/\.admin-table-scroll\s*\{[^}]*overflow-x:\s*auto;[^}]*overscroll-behavior-x:\s*contain;/s', $adminStyles) === 1
                && strpos($adminStyles, '.admin-table-scroll:focus-visible') !== false
                && preg_match('/\.admin-table\s+th,\s*\.admin-table\s+td\s*\{[^}]*white-space:\s*nowrap;/s', $adminStyles) === 1
                && strpos($adminStyles, '.admin-table td::before') === false,
            '后台表格在窄屏应保留单行列结构并在容器内横向滚动'
        );
        $logsController = $this->readFixture($rootPath . 'app/admin/controller/Logs.php');
        $logQueryService = $this->readFixture($rootPath . 'app/common/service/LogQueryService.php');
        $adminRoutes = $this->readFixture($rootPath . 'app/admin/route/app.php');
        $this->isTrue(
            strpos($visitLogTemplate, 'data-batch-table') !== false
                && strpos($visitLogTemplate, '/admin/logs/visit/batch-delete') !== false
                && strpos($downloadLogTemplate, 'data-batch-table') !== false
                && strpos($downloadLogTemplate, '/admin/logs/download/batch-delete') !== false
                && strpos($logsController, 'function batchDelete') !== false
                && strpos($logQueryService, 'function batchDelete') !== false
                && strpos($adminRoutes, "logs/:type/batch-delete") !== false,
            '访问日志与下载日志应支持批量删除'
        );
        $this->isTrue(
            strpos($visitLogTemplate, 'data-log-detail-trigger') !== false
                && strpos($visitLogTemplate, 'data-log-type="visit"') !== false
                && strpos($downloadLogTemplate, 'data-log-detail-trigger') !== false
                && strpos($downloadLogTemplate, 'data-log-type="download"') !== false
                && strpos($layoutTemplate, 'id="logDetailModal"') !== false
                && strpos($layoutTemplate, 'data-log-detail-body') !== false
                && strpos($logsController, 'function detail') !== false
                && strpos($logsController, '$this->positiveId($id)') !== false
                && strpos($logsController, "jsonError('日志记录不存在或已删除。', 404)") !== false
                && strpos($logQueryService, 'function detail') !== false
                && strpos($logQueryService, 'VISIT_DETAIL_FIELDS') !== false
                && strpos($logQueryService, 'DOWNLOAD_DETAIL_FIELDS') !== false
                && strpos($logQueryService, 'field($this->detailFields($type))') !== false
                && strpos($adminRoutes, "logs/:type/detail/:id") !== false
                && strpos($adminScript, 'loadLogDetail') !== false
                && strpos($adminScript, 'resolveLogDetailRequest') !== false
                && strpos($adminScript, 'requestController.signal') !== false
                && strpos($adminScript, 'closeLogDetailModal();') !== false
                && strpos($adminScript, 'data-log-detail-trigger') !== false
                && strpos($adminScript, 'value.textContent = formatLogDetailValue') !== false
                && strpos($adminStyles, '.log-detail-grid') !== false,
            '访问日志与下载日志应支持按需加载并安全展示详情'
        );
        $this->isTrue(
            strpos($contentModalTemplate, 'data-content-form-fragment') !== false
                && strpos($contentFormTemplate, 'data-content-form') !== false,
            '内容表单片段应保留前端异步提交标记'
        );
        $this->isTrue(
            strpos($contentController, 'private function isModalRequest(): bool') !== false
                && strpos($contentController, 'modalErrorResponse') !== false,
            '内容控制器应区分弹窗请求并返回可恢复错误'
        );
        $this->isTrue(strpos($adminScript, '5 * 1024 * 1024') === false, '图片上传脚本不能保留固定 5MB 上限');
        $this->isTrue(strpos($adminScript, 'dataset.svgMaxBytes') !== false, '图片上传脚本应区分 SVG 上限');
        $this->isTrue(
            strpos($adminScript, 'new AbortController()') !== false
                && strpos($adminScript, 'contentModalUrl') !== false,
            '内容弹窗应隔离过期加载请求并提交到弹窗端点'
        );
        $this->isTrue(
            strpos($adminScript, 'uploadSelectedFile') !== false
                && strpos($adminScript, 'resolveImageTargetInput') !== false
                && strpos($adminScript, 'fileInput.click()') !== false,
            '图片上传应由上传按钮打开文件选择并自动上传'
        );
        $this->isTrue(
            strpos($contentFormTemplate, 'asset-file-button') === false
                && strpos($contentFormTemplate, 'data-image-submit') !== false
                && strpos($siteTemplate, 'asset-file-button') === false,
            '图片上传控件应移除图标选择按钮，仅保留上传文字按钮'
        );
        $this->isTrue(strpos($installLayout, 'brand-panel') === false, '安装向导不能保留左侧品牌卡片');
        $this->isTrue(strpos($loginTemplate, 'login-brand-panel') === false, '登录页不能保留左侧品牌卡片');
        $this->isTrue(strpos($loginTemplate, 'name="captcha"') !== false, '登录表单应包含验证码字段');
        $this->isTrue(strpos($loginTemplate, 'maxlength="4"') !== false, '登录验证码输入框应为 4 位');
        $this->isTrue(strpos($loginTemplate, 'pattern="[A-Za-z0-9]{4}"') !== false, '登录验证码应允许英文大小写字母与数字');
        $this->isTrue(strpos($loginTemplate, 'data-captcha-image') !== false, '登录表单应包含可点击验证码图片');
        $this->isTrue(strpos($loginTemplate, 'data-captcha-refresh') === false, '登录表单不应再保留验证码刷新按钮');
        $this->isTrue(strpos($loginTemplate, 'data-password-toggle') !== false, '登录密码框应提供显示/隐藏切换按钮');
        $this->isTrue(strpos($loginScript, "querySelector('[data-captcha-image]')") !== false, '登录脚本应绑定验证码图片刷新');
        $this->isTrue(strpos($loginScript, 'data-captcha-refresh') === false, '登录脚本不应再依赖验证码刷新按钮');
        $this->isTrue(strpos($loginScript, 'togglePasswordVisibility') !== false || strpos($loginScript, 'data-password-toggle') !== false, '登录脚本应处理密码显隐切换');
        $this->isTrue(strpos($loginScript, 'A-Za-z0-9') !== false, '登录脚本应过滤验证码为字母数字');
        $this->isTrue(strpos($loginTemplate, 'data-remember-login') !== false, '登录表单应包含记住凭据选项');
        $this->isTrue(strpos($loginScript, 'PasswordCredential') !== false, '登录页应接入浏览器凭据管理器');
        $this->isTrue(
            strpos($loginTemplate, 'id="adminToastContainer"') !== false
                && strpos($loginTemplate, 'admin-toast') !== false
                && strpos($loginTemplate, 'alert alert-') === false
                && strpos($loginTemplate, 'login-ambient') !== false
                && strpos($loginTemplate, 'login-card-title') !== false
                && strpos($loginTemplate, 'bootstrap.bundle.min.js') !== false
                && strpos($loginScript, 'showLoginToast') !== false
                && strpos($loginScript, 'application/json') !== false
                && strpos($loginScript, 'setSubmitting') !== false
                && strpos($adminStyles, 'login-ambient') !== false
                && strpos($adminStyles, 'login-card-title') !== false,
            '登录页应使用 Toast 提示、异步提交与优化后的视觉布局'
        );
        $passwordTemplate = $this->readFixture($rootPath . 'app/admin/view/account/password.html');
        $this->isTrue(
            strpos($siteTemplate, 'alert alert-danger') === false
                && strpos($downloadTemplate, 'alert alert-danger') === false
                && strpos($passwordTemplate, 'alert alert-danger') === false
                && strpos($contentFormTemplate, 'alert alert-danger') === false
                && strpos($contentModalTemplate, 'alert alert-danger') === false
                && strpos($visitLogTemplate, 'alert alert-danger') === false
                && strpos($downloadLogTemplate, 'alert alert-danger') === false
                && strpos($adminControllerSource, "flashService()->push(\$pageError, 'danger')") !== false
                && strpos($adminScript, "showAdminToast(safeMessage, 'danger')") !== false,
            '后台页面提示应统一使用 Toast，不再渲染内联 alert'
        );
    }

    /**
     * @return void
     */
    private function testDeliveryArtifacts(): void
    {
        $rootPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $requiredFiles = [
            'vendor/autoload.php',
            'database/install.sql',
            'data/ip2region.xdb',
            '.env.example',
            'public/.htaccess',
            'public/uploads/.htaccess',
            'public/static/admin/login.js',
            'app/index/view/index/download_other.html',
            'public/static/index/download-other.css',
            'public/static/index/download-other.js',
            'public/static/vendor/photoswipe/photoswipe.css',
            'public/static/vendor/photoswipe/photoswipe.esm.min.js',
            'public/static/vendor/photoswipe/photoswipe-lightbox.esm.min.js',
            'public/static/vendor/photoswipe/LICENSE',
            'app/common/service/CaptchaService.php',
            'deploy/nginx.conf.example',
            'deploy/apache-vhost.conf.example',
            'README.md',
            'PACKAGING.md',
            'SECURITY.md',
        ];

        foreach ($requiredFiles as $relativePath) {
            $absolutePath = $rootPath . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $this->isTrue(is_file($absolutePath) && filesize($absolutePath) > 0, '售卖包缺少文件：' . $relativePath);
        }

        $installSql = $this->readFixture($rootPath . 'database/install.sql');
        $tableCount = preg_match_all('/^CREATE TABLE `__PREFIX__[a-z_]+`/m', $installSql);
        $this->same(9, $tableCount, '安装 SQL 应包含完整的 9 张数据表');
        $this->isTrue(
            !is_file($rootPath . 'app/controller/Index.php'),
            '售卖包不能保留 ThinkPHP 默认示例控制器'
        );
    }

    /**
     * @param string $path 测试契约文件路径
     * @return string 文件内容
     */
    private function readFixture(string $path): string
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('无法读取测试契约文件：' . $path);
        }

        return $contents;
    }

    /**
     * @param mixed $expected 期望值
     * @param mixed $actual 实际值
     * @param string $message 失败说明
     * @return void
     */
    private function same($expected, $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf(
                "%s；期望 %s，实际 %s。",
                $message,
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    /**
     * @param bool $condition 断言条件
     * @param string $message 失败说明
     * @return void
     */
    private function isTrue(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new RuntimeException($message . '。');
        }
    }

    /**
     * @param callable $callback 应抛出异常的操作
     * @param string $message 失败说明
     * @return void
     */
    private function throwsDownloadException(callable $callback, string $message): void
    {
        $this->assertions++;
        try {
            $callback();
        } catch (DownloadException $exception) {
            return;
        } catch (ReflectionException $exception) {
            if ($exception->getPrevious() instanceof DownloadException) {
                return;
            }
            throw $exception;
        }

        throw new RuntimeException($message . '。');
    }

    /**
     * @param class-string<Throwable> $expectedClass 期望异常类型
     * @param callable $callback 应抛出异常的操作
     * @param string $message 失败说明
     * @return void
     */
    private function throwsException(string $expectedClass, callable $callback, string $message): void
    {
        $this->assertions++;
        try {
            $callback();
        } catch (Throwable $exception) {
            if ($exception instanceof $expectedClass) {
                return;
            }
            throw new RuntimeException($message . '，实际异常：' . get_class($exception) . '。', 0, $exception);
        }

        throw new RuntimeException($message . '。');
    }
}

try {
    (new CriticalPathTest())->run();
} catch (Throwable $exception) {
    fwrite(STDERR, '关键路径测试失败：' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
