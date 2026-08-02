<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\AdminException;
use think\facade\Db;
use Throwable;

/**
 * 读取与更新站点单行配置，并隔离敏感字段。
 */
class SiteConfigService
{
    public const DEFAULT_FAVICON_PATH = '/favicon.ico';

    private const FAVICON_EXTENSIONS = ['ico', 'png', 'svg'];
    private const EXTERNAL_DOWNLOAD_URL_MAX_LENGTH = 2048;
    private const DOWNLOAD_PASSWORD_MAX_LENGTH = 128;

    /** @var SecretService */
    private $secretService;

    /** @var IpNetworkService */
    private $ipNetworkService;

    /** @var ThemeColorService */
    private $themeColorService;

    /** 当前进程是否已确认 theme_color 列存在。 */
    private static $themeColumnReady = false;

    /** 当前进程是否已确认 favicon 列存在。 */
    private static $faviconColumnReady = false;

    /** 当前进程是否已确认其他网盘下载列存在。 */
    private static $otherDownloadColumnsReady = false;

    /**
     * @param SecretService $secretService 敏感配置加密服务
     * @param IpNetworkService $ipNetworkService IP 网段服务
     * @param ThemeColorService $themeColorService 主题色服务
     */
    public function __construct(
        SecretService $secretService,
        IpNetworkService $ipNetworkService,
        ThemeColorService $themeColorService
    ) {
        $this->secretService = $secretService;
        $this->ipNetworkService = $ipNetworkService;
        $this->themeColorService = $themeColorService;
    }

    /**
     * @return array<string, mixed> 完整服务端配置
     * @throws AdminException 配置行缺失时抛出
     */
    public function get(): array
    {
        $this->ensureThemeColorColumn();
        $this->ensureFaviconColumn();
        $this->ensureOtherDownloadColumns();
        $config = Db::name('site_config')->where('id', 1)->find();
        if (!is_array($config)) {
            throw new AdminException('站点配置不存在，请检查数据库安装状态。');
        }

        $config['theme_color'] = $this->themeColorService->normalize(
            (string) ($config['theme_color'] ?? ThemeColorService::DEFAULT_COLOR)
        );
        $config['favicon_url'] = $this->buildFaviconUrl($config);

        return $config;
    }

    /**
     * @return array<string, mixed> 可安全传给公开模板的配置
     */
    public function publicConfig(): array
    {
        $config = $this->get();
        unset($config['lanzou_pwd'], $config['other_pwd'], $config['trusted_proxy_ips']);

        return $config;
    }

    /**
     * 读取带缓存版本的浏览器图标地址；配置不可用时回退内置图标。
     *
     * @return string 可用于浏览器图标链接的站内地址
     */
    public function faviconUrl(): string
    {
        try {
            return (string) ($this->get()['favicon_url'] ?? self::DEFAULT_FAVICON_PATH);
        } catch (Throwable $exception) {
            return self::DEFAULT_FAVICON_PATH;
        }
    }

    /**
     * 读取当前主题主色；配置不可用时回退默认色。
     *
     * @return string 规范化 #rrggbb
     */
    public function themeColor(): string
    {
        try {
            return $this->themeColorService->normalize(
                (string) ($this->get()['theme_color'] ?? ThemeColorService::DEFAULT_COLOR)
            );
        } catch (Throwable $exception) {
            return ThemeColorService::DEFAULT_COLOR;
        }
    }

    /**
     * 生成主题覆盖 style 标签；失败时返回空字符串。
     *
     * @return string
     */
    public function themeStyleTag(): string
    {
        try {
            return $this->themeColorService->styleTag($this->themeColor());
        } catch (Throwable $exception) {
            return $this->themeColorService->styleTag(ThemeColorService::DEFAULT_COLOR);
        }
    }

    /**
     * @param array<string, mixed> $input 站点表单
     * @return void
     * @throws AdminException 输入无效时抛出
     */
    public function saveSite(array $input): void
    {
        // 保存前必须确保新增配置列可用，失败应给出明确业务错误。
        $this->ensureThemeColorColumn(true);
        $this->ensureFaviconColumn(true);

        $dedupeSeconds = filter_var($input['log_dedupe_seconds'] ?? 30, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 3600],
        ]);
        if ($dedupeSeconds === false) {
            throw new AdminException('访问去重时间必须是 0 到 3600 之间的整数。');
        }

        $themeColorRaw = trim((string) ($input['theme_color'] ?? ThemeColorService::DEFAULT_COLOR));
        if (!$this->themeColorService->isValid($themeColorRaw)) {
            throw new AdminException('主题颜色必须是有效的十六进制色值，例如 #4a9fd8。');
        }

        $data = [
            'site_name' => $this->text($input, 'site_name', '软件名称', 64, true),
            'site_slogan' => $this->text($input, 'site_slogan', '站点副标题', 255),
            'app_icon' => $this->text($input, 'app_icon', '应用图标', 512),
            'favicon' => $this->text($input, 'favicon', '网站图标', 512),
            'version' => $this->text($input, 'version', '版本号', 32),
            'version_label' => $this->text($input, 'version_label', '版本说明', 64),
            'stats_users' => $this->text($input, 'stats_users', '用户数量', 32),
            'copyright' => $this->text($input, 'copyright', '版权文案', 255),
            'icp_beian' => $this->text($input, 'icp_beian', '备案号', 128),
            'hero_title' => $this->text($input, 'hero_title', '主标题', 255, true),
            'hero_desc' => $this->text($input, 'hero_desc', '主描述', 2000),
            'cta_text' => $this->text($input, 'cta_text', '下载按钮文案', 64, true),
            'theme_color' => $this->themeColorService->normalize($themeColorRaw),
            'trusted_proxy_ips' => $this->ipNetworkService->normalizeList(
                (string) ($input['trusted_proxy_ips'] ?? '')
            ),
            'log_dedupe_seconds' => (int) $dedupeSeconds,
            'record_bots' => filter_var($input['record_bots'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($data['app_icon'] !== '' && !$this->isAllowedUrl($data['app_icon'])) {
            throw new AdminException('应用图标必须是 http(s) 地址或站内绝对路径。');
        }
        if ($data['favicon'] !== '' && !$this->isAllowedFaviconPath($data['favicon'])) {
            throw new AdminException('网站图标必须是站内绝对路径，并使用 ico、png 或 svg 格式。');
        }

        Db::name('site_config')->where('id', 1)->update($data);
    }

    /**
     * @return array<string, mixed> 下载配置表单数据（后台内回显访问密码明文）
     */
    public function downloadForm(): array
    {
        $config = $this->get();
        $mode = $this->normalizeDownloadMode((string) ($config['download_mode'] ?? 'local'));

        // 历史蓝奏配置：若网盘地址为空则回填蓝奏地址，便于管理员迁移到「网盘下载」。
        $otherUrl = trim((string) ($config['other_url'] ?? ''));
        $passwordCipher = (string) ($config['other_pwd'] ?? '');
        if ($otherUrl === '' && trim((string) ($config['lanzou_url'] ?? '')) !== '') {
            $otherUrl = trim((string) $config['lanzou_url']);
        }
        if ($passwordCipher === '' && (string) ($config['lanzou_pwd'] ?? '') !== '') {
            $passwordCipher = (string) $config['lanzou_pwd'];
        }

        $otherPassword = '';
        if ($passwordCipher !== '') {
            $otherPassword = $this->secretService->decrypt($passwordCipher);
        }

        return [
            'download_mode' => $mode,
            'local_apk_path' => (string) $config['local_apk_path'],
            'other_url' => $otherUrl,
            'other_pwd' => $otherPassword,
            'other_password_configured' => $otherPassword !== '',
        ];
    }

    /**
     * 将下载方式归一为 local / other（历史 lanzou 视为 other）。
     *
     * @param string $mode 原始模式
     * @return string
     */
    public function normalizeDownloadMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === 'other' || $mode === 'lanzou') {
            return 'other';
        }

        return 'local';
    }

    /**
     * @param array<string, mixed> $input 下载配置表单
     * @return void
     * @throws AdminException 输入无效时抛出
     */
    public function saveDownload(array $input): void
    {
        $this->ensureOtherDownloadColumns(true);
        $current = $this->get();
        $mode = $this->normalizeDownloadMode((string) ($input['download_mode'] ?? ''));
        if (!in_array($mode, ['local', 'other'], true)) {
            throw new AdminException('下载方式只能选择本地 APK 或网盘下载。');
        }

        $otherUrl = trim((string) ($input['other_url'] ?? ''));
        if ($otherUrl !== '' && !$this->isExternalDownloadUrl($otherUrl)) {
            throw new AdminException('网盘分享地址必须是有效的 http(s) URL。');
        }
        if ($mode === 'other' && $otherUrl === '') {
            throw new AdminException('网盘下载模式必须填写分享地址。');
        }

        // 明文表单：有内容则加密保存，清空则视为无密码。
        $otherPassword = trim((string) ($input['other_pwd'] ?? ''));
        if (strlen($otherPassword) > self::DOWNLOAD_PASSWORD_MAX_LENGTH || preg_match('/[\r\n\x00]/', $otherPassword)) {
            throw new AdminException(sprintf(
                '网盘访问密码长度不能超过 %d 位，且不能包含换行或空字节。',
                self::DOWNLOAD_PASSWORD_MAX_LENGTH
            ));
        }
        $encryptedOtherPassword = $otherPassword === ''
            ? ''
            : $this->secretService->encrypt($otherPassword);

        Db::name('site_config')->where('id', 1)->update([
            'download_mode' => $mode,
            'other_url' => $otherUrl,
            'other_pwd' => $encryptedOtherPassword,
            // 清空历史蓝奏字段，避免旧模式残留。
            'lanzou_url' => '',
            'lanzou_pwd' => '',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 为已安装站点补齐其他网盘下载列（幂等）。
     *
     * @param bool $required 为 true 时迁移失败直接抛出业务异常（保存场景）
     * @return void
     * @throws AdminException 强制要求列就绪但迁移失败时抛出
     */
    private function ensureOtherDownloadColumns(bool $required = false): void
    {
        if (self::$otherDownloadColumnsReady) {
            return;
        }

        try {
            $prefix = (string) config('database.connections.mysql.prefix');
            $tableName = $prefix . 'site_config';
            $tableSql = str_replace('`', '``', $tableName);
            $urlColumn = Db::query("SHOW COLUMNS FROM `{$tableSql}` LIKE 'other_url'");
            if (!is_array($urlColumn) || $urlColumn === []) {
                Db::execute(
                    "ALTER TABLE `{$tableSql}` "
                    . "ADD COLUMN `other_url` varchar(" . self::EXTERNAL_DOWNLOAD_URL_MAX_LENGTH
                    . ") NOT NULL DEFAULT '' AFTER `lanzou_pwd`"
                );
            }

            $passwordColumn = Db::query("SHOW COLUMNS FROM `{$tableSql}` LIKE 'other_pwd'");
            if (!is_array($passwordColumn) || $passwordColumn === []) {
                Db::execute(
                    "ALTER TABLE `{$tableSql}` "
                    . "ADD COLUMN `other_pwd` varchar(255) NOT NULL DEFAULT '' AFTER `other_url`"
                );
            }

            $connection = Db::connect();
            if (method_exists($connection, 'getSchemaInfo')) {
                $connection->getSchemaInfo($tableName, true);
            }

            self::$otherDownloadColumnsReady = true;
        } catch (AdminException $exception) {
            self::$otherDownloadColumnsReady = false;
            if ($required) {
                throw $exception;
            }
        } catch (Throwable $exception) {
            self::$otherDownloadColumnsReady = false;
            if ($required) {
                throw new AdminException(
                    '无法初始化其他网盘下载配置列，请检查数据库权限后重试。详情：' . $exception->getMessage()
                );
            }
        }
    }

    /**
     * 为已安装站点补齐 theme_color 列（幂等）。
     *
     * 说明：MySQL 的 SHOW COLUMNS ... LIKE 不支持预处理占位符，
     * 使用 `?` 会直接语法错误；失败时若仍写入 theme_color 会触发
     * ThinkPHP strict 字段校验 “fields not exists:[theme_color]”。
     *
     * @param bool $required 为 true 时迁移失败直接抛出业务异常（保存场景）
     * @return void
     * @throws AdminException 强制要求列就绪但迁移失败时抛出
     */
    private function ensureThemeColorColumn(bool $required = false): void
    {
        if (self::$themeColumnReady) {
            return;
        }

        try {
            $prefix = (string) config('database.connections.mysql.prefix');
            $tableName = $prefix . 'site_config';
            $tableSql = str_replace('`', '``', $tableName);
            // 列名固定为标识符，禁止拼接用户输入；不可使用 PDO 占位符。
            $exists = Db::query("SHOW COLUMNS FROM `{$tableSql}` LIKE 'theme_color'");
            if (!is_array($exists) || $exists === []) {
                $defaultColor = ThemeColorService::DEFAULT_COLOR;
                if (!$this->themeColorService->isValid($defaultColor)) {
                    throw new AdminException('默认主题色配置无效，无法补齐数据列。');
                }
                Db::execute(
                    "ALTER TABLE `{$tableSql}` "
                    . "ADD COLUMN `theme_color` varchar(7) NOT NULL DEFAULT '{$defaultColor}' "
                    . 'AFTER `cta_text`'
                );
            }

            // ALTER 后强制刷新字段缓存，避免 strict 模式仍使用旧 schema。
            $connection = Db::connect();
            if (method_exists($connection, 'getSchemaInfo')) {
                $connection->getSchemaInfo($tableName, true);
            }

            self::$themeColumnReady = true;
        } catch (AdminException $exception) {
            self::$themeColumnReady = false;
            if ($required) {
                throw $exception;
            }
        } catch (Throwable $exception) {
            self::$themeColumnReady = false;
            if ($required) {
                throw new AdminException(
                    '无法初始化主题色配置列，请检查数据库权限后重试。详情：' . $exception->getMessage()
                );
            }
            // 非强制场景（页面渲染）不阻断，读取时回退默认色。
        }
    }

    /**
     * 为已安装站点补齐 favicon 列（幂等）。
     *
     * @param bool $required 为 true 时迁移失败直接抛出业务异常（保存场景）
     * @return void
     * @throws AdminException 强制要求列就绪但迁移失败时抛出
     */
    private function ensureFaviconColumn(bool $required = false): void
    {
        if (self::$faviconColumnReady) {
            return;
        }

        try {
            $prefix = (string) config('database.connections.mysql.prefix');
            $tableName = $prefix . 'site_config';
            $tableSql = str_replace('`', '``', $tableName);
            // 列名固定为标识符，禁止拼接用户输入；不可使用 PDO 占位符。
            $exists = Db::query("SHOW COLUMNS FROM `{$tableSql}` LIKE 'favicon'");
            if (!is_array($exists) || $exists === []) {
                Db::execute(
                    "ALTER TABLE `{$tableSql}` "
                    . "ADD COLUMN `favicon` varchar(512) NOT NULL DEFAULT '' AFTER `app_icon`"
                );
            }

            // ALTER 后强制刷新字段缓存，避免 strict 模式仍使用旧 schema。
            $connection = Db::connect();
            if (method_exists($connection, 'getSchemaInfo')) {
                $connection->getSchemaInfo($tableName, true);
            }

            self::$faviconColumnReady = true;
        } catch (AdminException $exception) {
            self::$faviconColumnReady = false;
            if ($required) {
                throw $exception;
            }
        } catch (Throwable $exception) {
            self::$faviconColumnReady = false;
            if ($required) {
                throw new AdminException(
                    '无法初始化网站图标配置列，请检查数据库权限后重试。详情：' . $exception->getMessage()
                );
            }
            // 非强制场景（页面渲染）不阻断，保留内置网站图标。
        }
    }

    /**
     * @param array<string, mixed> $input 表单
     * @param string $key 字段键
     * @param string $label 字段名
     * @param int $maxLength 最大字符数
     * @param bool $required 是否必填
     * @return string 规范化文本
     * @throws AdminException 文本不合规时抛出
     */
    private function text(array $input, string $key, string $label, int $maxLength, bool $required = false): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($required && $value === '') {
            throw new AdminException($label . '不能为空。');
        }
        if (mb_strlen($value) > $maxLength || preg_match('/[\x00]/', $value)) {
            throw new AdminException(sprintf('%s长度不能超过 %d 个字符。', $label, $maxLength));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config 站点配置
     * @return string 带缓存版本的浏览器图标地址
     */
    private function buildFaviconUrl(array $config): string
    {
        $favicon = trim((string) ($config['favicon'] ?? ''));
        if ($favicon === '' || !$this->isAllowedFaviconPath($favicon)) {
            return self::DEFAULT_FAVICON_PATH;
        }

        $updatedAt = trim((string) ($config['updated_at'] ?? ''));
        $timestamp = $updatedAt === '' ? false : strtotime($updatedAt);
        if ($timestamp === false) {
            return $favicon;
        }

        return $favicon . '?v=' . rawurlencode((string) $timestamp);
    }

    /**
     * @param string $path 网站图标站内路径
     * @return bool 是否为受支持的安全站内路径
     */
    private function isAllowedFaviconPath(string $path): bool
    {
        if ($path === '' || strpos($path, '/') !== 0 || strpos($path, '//') === 0
            || strpos($path, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return false;
        }

        $parts = parse_url($path);
        if (!is_array($parts) || !isset($parts['path'])
            || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'], $parts['port'], $parts['query'], $parts['fragment'])) {
            return false;
        }

        $pathOnly = (string) $parts['path'];
        if ($pathOnly === '' || strpos($pathOnly, '/') !== 0 || strpos($pathOnly, '//') === 0
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $pathOnly)) {
            return false;
        }

        return in_array(strtolower(pathinfo($pathOnly, PATHINFO_EXTENSION)), self::FAVICON_EXTENSIONS, true);
    }

    /**
     * @param string $url 地址
     * @return bool 是否为可渲染地址
     */
    private function isAllowedUrl(string $url): bool
    {
        return (strpos($url, '/') === 0 && strpos($url, '//') !== 0) || $this->isHttpUrl($url);
    }

    /**
     * @param string $url 地址
     * @return bool 是否为 http(s) URL
     */
    private function isHttpUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * @param string $url 其他网盘分享地址
     * @return bool 是否为可安全输出给浏览器的外部 http(s) 地址
     */
    private function isExternalDownloadUrl(string $url): bool
    {
        if (strlen($url) > self::EXTERNAL_DOWNLOAD_URL_MAX_LENGTH
            || preg_match('/[\x00-\x20\x7F]/', $url)
            || strpbrk($url, "\"'<>\\\\") !== false
            || !$this->isHttpUrl($url)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'])) {
            return false;
        }

        $host = (string) $parts['host'];
        return $host !== '' && strlen($host) <= 253;
    }
}
