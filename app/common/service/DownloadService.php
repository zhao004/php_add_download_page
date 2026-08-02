<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\AdminException;
use app\common\exception\DownloadException;
use think\App;
use think\facade\Log;
use think\facade\View;
use think\Request;
use think\Response;
use Throwable;

/**
 * 处理唯一下载入口，统一完成配置读取、目标校验、日志更新和错误响应。
 * 支持本地 APK 直链与网盘下载中转（浏览器打开分享链接，不解析第三方网盘）。
 */
class DownloadService
{
    private const EXTERNAL_SHARE_URL_MAX_LENGTH = 2048;

    /** @var App */
    private $app;

    /** @var SiteConfigService */
    private $configService;

    /** @var SecretService */
    private $secretService;

    /** @var DownloadLogService */
    private $logService;

    public function __construct(
        App $app,
        SiteConfigService $configService,
        SecretService $secretService,
        DownloadLogService $logService
    ) {
        $this->app = $app;
        $this->configService = $configService;
        $this->secretService = $secretService;
        $this->logService = $logService;
    }

    /**
     * @param Request $request 当前下载请求
     * @return Response 302 跳转、网盘中转页或友好错误页
     */
    public function handle(Request $request): Response
    {
        $mode = 'local';
        $logId = 0;

        try {
            $config = $this->configService->get();
            $mode = $this->normalizeMode((string) ($config['download_mode'] ?? 'local'));
            $logId = $this->logService->start($request, $mode);

            if ($mode === 'local') {
                $target = $this->localTarget((string) ($config['local_apk_path'] ?? ''));
                $this->logService->finish($logId, 'success');
                return redirect($target, 302)->header([
                    'Cache-Control' => 'no-store, private',
                    'Referrer-Policy' => 'no-referrer',
                    'X-Robots-Tag' => 'noindex, nofollow',
                ]);
            }

            // 网盘下载：兼容历史蓝奏配置字段作为回退地址。
            $shareUrl = trim((string) ($config['other_url'] ?? ''));
            $passwordCipher = (string) ($config['other_pwd'] ?? '');
            if ($shareUrl === '') {
                $shareUrl = trim((string) ($config['lanzou_url'] ?? ''));
                $passwordCipher = (string) ($config['lanzou_pwd'] ?? '');
            }
            $target = $this->externalShareTarget($shareUrl);
            $password = $this->secretService->decrypt($passwordCipher);

            $this->logService->finish($logId, 'success');
            return $this->externalShareResponse($target, $password);
        } catch (DownloadException $exception) {
            $this->logService->finish($logId, $exception->getLogStatus(), $exception->getMessage());
            Log::warning('下载处理失败：' . mb_substr($exception->getMessage(), 0, 180));
            return $this->errorResponse($exception->getMessage(), $exception->getHttpStatus());
        } catch (AdminException $exception) {
            $message = '下载配置无法读取，请联系站点管理员。';
            $this->logService->finish($logId, 'fail_other', $message);
            Log::error('下载敏感配置处理失败：' . mb_substr($exception->getMessage(), 0, 180));
            return $this->errorResponse($message, 503);
        } catch (Throwable $exception) {
            $message = '下载服务暂时不可用，请稍后再试。';
            $this->logService->finish($logId, 'fail_other', $message);
            Log::error('下载处理出现未预期异常：' . mb_substr($exception->getMessage(), 0, 180));
            return $this->errorResponse($message, 500);
        }
    }

    /**
     * 将历史蓝奏模式归一为网盘下载，非法值回退本地。
     *
     * @param string $mode 配置中的下载方式
     * @return string local|other
     */
    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === 'lanzou') {
            return 'other';
        }
        if ($mode === 'other') {
            return 'other';
        }

        return 'local';
    }

    /**
     * 校验只由浏览器访问的网盘分享地址，防止损坏配置输出为危险协议。
     *
     * @param string $url 数据库中的网盘分享地址
     * @return string 可安全写入链接属性的 http(s) 地址
     * @throws DownloadException 地址无效时抛出
     */
    private function externalShareTarget(string $url): string
    {
        $url = trim($url);
        if ($url === ''
            || strlen($url) > self::EXTERNAL_SHARE_URL_MAX_LENGTH
            || preg_match('/[\x00-\x20\x7F]/', $url)
            || strpbrk($url, "\"'<>\\\\") !== false
            || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new DownloadException('网盘分享地址无效，请联系站点管理员。');
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || (string) $parts['host'] === '') {
            throw new DownloadException('网盘分享地址无效，请联系站点管理员。');
        }

        return $url;
    }

    /**
     * @param string $shareUrl 网盘原始分享地址
     * @param string $password 可为空的访问密码
     * @return Response 无缓存的密码提示与直达链接页面
     */
    private function externalShareResponse(string $shareUrl, string $password): Response
    {
        $view = $this->errorPageData('', 503);
        $view['shareUrl'] = $shareUrl;
        $view['sharePassword'] = $password;
        $view['hasSharePassword'] = $password !== '';
        $content = View::fetch('index/download_other', $view);

        return response($content, 200)->header([
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self'; connect-src 'none'; base-uri 'none'; form-action 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * @param string $relativePath 数据库中的公开相对路径
     * @return string 经过边界校验的公开路径
     * @throws DownloadException 文件缺失或路径越界时抛出
     */
    private function localTarget(string $relativePath): string
    {
        $relativePath = trim($relativePath);
        if (!preg_match('#^/uploads/apk/[A-Za-z0-9_-]{1,128}\.apk$#', $relativePath)) {
            throw new DownloadException(
                '安装包暂时不可用，请联系站点管理员。',
                'fail_missing_file',
                404
            );
        }

        $uploadDirectory = $this->app->getRootPath() . 'public' . DIRECTORY_SEPARATOR
            . 'uploads' . DIRECTORY_SEPARATOR . 'apk';
        $basePath = realpath($uploadDirectory);
        $candidatePath = realpath(
            $this->app->getRootPath() . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)
        );
        if ($basePath === false || $candidatePath === false || !is_file($candidatePath) || !is_readable($candidatePath)) {
            throw new DownloadException('安装包文件不存在，请联系站点管理员。', 'fail_missing_file', 404);
        }

        $basePrefix = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $comparisonBase = DIRECTORY_SEPARATOR === '\\' ? strtolower($basePrefix) : $basePrefix;
        $comparisonCandidate = DIRECTORY_SEPARATOR === '\\' ? strtolower($candidatePath) : $candidatePath;
        if (strpos($comparisonCandidate, $comparisonBase) !== 0
            || strtolower((string) pathinfo($candidatePath, PATHINFO_EXTENSION)) !== 'apk') {
            throw new DownloadException('安装包路径无效，请联系站点管理员。', 'fail_missing_file', 404);
        }

        return $relativePath;
    }

    /**
     * @param string $message 对外安全摘要
     * @param int $status HTTP 状态码
     * @return Response 禁止缓存和索引的错误页
     */
    private function errorResponse(string $message, int $status): Response
    {
        $safeStatus = in_array($status, [404, 500, 502, 503], true) ? $status : 500;
        $view = $this->errorPageData($message, $safeStatus);

        $content = View::fetch('index/download_error', $view);

        return response($content, $safeStatus)->header([
            'Cache-Control' => 'no-store, private',
            // 内联主题变量 + 同源样式/图片；图标使用内联 SVG，无需 font-src。
            'Content-Security-Policy' => "default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self'; base-uri 'none'; form-action 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * 组装下载失败页模板数据，尽量复用站点品牌与主题色。
     *
     * @param string $message 对外说明
     * @param int $status 安全 HTTP 状态码
     * @return array<string, mixed>
     */
    private function errorPageData(string $message, int $status): array
    {
        $siteName = 'NOVA';
        $siteSlogan = '';
        $appIcon = '';
        $themeStyle = '';
        $themeColor = ThemeColorService::DEFAULT_COLOR;
        $faviconUrl = SiteConfigService::DEFAULT_FAVICON_PATH;
        $versionMeta = '';

        try {
            $config = $this->configService->publicConfig();
            $themeStyle = $this->configService->themeStyleTag();
            $themeColor = $this->configService->themeColor();
            $faviconUrl = (string) ($config['favicon_url'] ?? $faviconUrl);

            $name = trim((string) ($config['site_name'] ?? ''));
            if ($name !== '') {
                $siteName = $name;
            }
            $siteSlogan = trim((string) ($config['site_slogan'] ?? ''));

            // CSP 仅允许同源图片，站外图标不输出，避免破图。
            $icon = trim((string) ($config['app_icon'] ?? ''));
            if ($icon !== '' && strpos($icon, '/') === 0 && strpos($icon, '//') !== 0) {
                $appIcon = $icon;
            }

            $version = trim((string) ($config['version'] ?? ''));
            $versionLabel = trim((string) ($config['version_label'] ?? ''));
            if ($version !== '' && $versionLabel !== '') {
                $versionMeta = $version . ' · ' . $versionLabel;
            } elseif ($version !== '') {
                $versionMeta = $version;
            } elseif ($versionLabel !== '') {
                $versionMeta = $versionLabel;
            }
        } catch (Throwable $exception) {
            // 配置不可用时保留默认品牌与默认主题色，保证错误页仍可渲染。
        }

        return [
            'message' => $message,
            'status' => $status,
            'themeStyle' => $themeStyle,
            'themeColor' => $themeColor,
            'faviconUrl' => $faviconUrl,
            'siteName' => $siteName,
            'siteNameInitial' => $this->resolveNameInitial($siteName),
            'siteSlogan' => $siteSlogan,
            'appIcon' => $appIcon,
            'versionMeta' => $versionMeta,
            'errorTitle' => $this->errorTitle($status),
            'errorHint' => $this->errorHint($status),
            'statusLabel' => $this->statusLabel($status),
        ];
    }

    /**
     * @param int $status HTTP 状态码
     * @return string 面向用户的标题
     */
    private function errorTitle(int $status): string
    {
        if ($status === 404) {
            return '安装包暂不可用';
        }
        if ($status === 502) {
            return '下载源暂时无法访问';
        }
        if ($status === 503) {
            return '下载服务未就绪';
        }

        return '下载暂不可用';
    }

    /**
     * @param int $status HTTP 状态码
     * @return string 补充操作提示
     */
    private function errorHint(int $status): string
    {
        if ($status === 404) {
            return '可先返回首页浏览介绍，或稍后重试；若持续失败请联系站点管理员上传安装包。';
        }
        if ($status === 502) {
            return '网盘链接可能失效或暂时不可用，请稍后点击「重试下载」，或返回首页稍后再试。';
        }
        if ($status === 503) {
            return '站点下载配置可能尚未完成，请联系管理员检查后台下载设置。';
        }

        return '这通常是临时问题。你可以返回首页，或稍后再试一次下载。';
    }

    /**
     * @param int $status HTTP 状态码
     * @return string 状态短标签
     */
    private function statusLabel(int $status): string
    {
        $labels = [
            404 => '未找到',
            500 => '服务异常',
            502 => '上游失败',
            503 => '暂不可用',
        ];

        return $labels[$status] ?? '异常';
    }

    /**
     * @param string $siteName 软件名称
     * @return string 单个展示字符
     */
    private function resolveNameInitial(string $siteName): string
    {
        $trimmed = trim($siteName);
        if ($trimmed === '') {
            return 'N';
        }

        if (function_exists('mb_substr')) {
            $initial = (string) mb_substr($trimmed, 0, 1, 'UTF-8');
            if (function_exists('mb_strtoupper') && preg_match('/^[A-Za-z]$/', $initial)) {
                return (string) mb_strtoupper($initial, 'UTF-8');
            }

            return $initial !== '' ? $initial : 'N';
        }

        $initial = substr($trimmed, 0, 1);
        return $initial !== '' ? strtoupper($initial) : 'N';
    }
}
