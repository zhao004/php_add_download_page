<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 聚合前台落地页所需的公开配置与已启用内容。
 */
class LandingPageService
{
    /** @var SiteConfigService */
    private $configService;

    public function __construct(SiteConfigService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * @return array<string, mixed> 前台模板数据
     */
    public function data(): array
    {
        $config = $this->configService->publicConfig();
        $friendLinks = $this->enabled('friend_link');
        $previewPages = $this->enabled('preview_page');
        $featureItems = $this->enabled('feature_item');
        foreach ($previewPages as $index => &$previewPage) {
            $previewPage['_index'] = $index;
        }
        unset($previewPage);
        foreach ($featureItems as &$featureItem) {
            $icon = (string) ($featureItem['icon'] ?? '');
            $featureItem['_icon_is_image'] = $icon !== '' && (
                strpos($icon, '/') === 0
                || strpos($icon, 'http://') === 0
                || strpos($icon, 'https://') === 0
            );
        }
        unset($featureItem);
        $siteName = trim((string) ($config['site_name'] ?? ''));
        $config['display_title'] = trim((string) ($config['hero_title'] ?? '')) !== ''
            ? (string) $config['hero_title']
            : $siteName;
        $config['page_description'] = trim((string) ($config['site_slogan'] ?? '')) !== ''
            ? (string) $config['site_slogan']
            : (string) ($config['hero_desc'] ?? '');
        // 顶栏品牌无图标时的首字回退，兼容中英文。
        $config['site_name_initial'] = $this->resolveNameInitial($siteName);
        $config['theme_color'] = $this->configService->themeColor();

        return [
            'config' => $config,
            'themeStyle' => $this->configService->themeStyleTag(),
            'navItems' => $this->enabled('nav_item'),
            'previewPages' => $previewPages,
            'firstPreviewTitle' => $previewPages[0]['title'] ?? '',
            'featureItems' => $featureItems,
            'trustBrands' => $this->enabled('trust_brand'),
            'friendLinks' => $friendLinks,
            'showFooter' => $friendLinks !== []
                || trim((string) ($config['copyright'] ?? '')) !== ''
                || trim((string) ($config['icp_beian'] ?? '')) !== '',
        ];
    }

    /**
     * @param string $table 数据表逻辑名
     * @return array<int, array<string, mixed>> 已启用记录
     */
    private function enabled(string $table): array
    {
        return Db::name($table)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 提取站点名称首字，供顶栏品牌标记回退展示。
     *
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
            $initial = mb_substr($trimmed, 0, 1, 'UTF-8');
            if (function_exists('mb_strtoupper')) {
                return mb_strtoupper($initial, 'UTF-8');
            }

            return $initial;
        }

        return strtoupper(substr($trimmed, 0, 1));
    }
}
