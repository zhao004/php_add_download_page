<?php

declare(strict_types=1);

namespace app\common\service;

/**
 * 主题品牌色校验、派生与 CSS 变量输出。
 *
 * 以单一主色生成强调色、悬停色、浅底色、描边色、选中文字色与
 * 按钮前景色，保证前台、后台与图表共享同一套可配置配色。
 *
 * 浅色系采用「主色与白混合」而非过度降饱和 HSL，避免 soft 色发灰；
 * 按钮前景色按相对亮度自动在深/浅之间切换，保证浅色主题的可读性。
 */
class ThemeColorService
{
    /** 默认品牌强调色（与 tokens.css 保持一致）。 */
    public const DEFAULT_COLOR = '#4a9fd8';

    /**
     * 可选预设主题色。
     *
     * 预设按「冷色稳重 → 暖色活力 → 中性」排列，覆盖下载落地页常见品牌气质。
     *
     * @var array<int, array{key:string,label:string,color:string}>
     */
    public const PRESETS = [
        ['key' => 'ocean', 'label' => '海洋蓝', 'color' => '#4a9fd8'],
        ['key' => 'sky', 'label' => '晴空蓝', 'color' => '#0284c7'],
        ['key' => 'indigo', 'label' => '靛蓝', 'color' => '#4f46e5'],
        ['key' => 'teal', 'label' => '青绿', 'color' => '#0d9488'],
        ['key' => 'emerald', 'label' => '翠绿', 'color' => '#059669'],
        ['key' => 'amber', 'label' => '琥珀', 'color' => '#d97706'],
        ['key' => 'rose', 'label' => '玫红', 'color' => '#e11d48'],
        ['key' => 'violet', 'label' => '紫罗兰', 'color' => '#7c3aed'],
        ['key' => 'slate', 'label' => '石墨灰', 'color' => '#475569'],
    ];

    /**
     * 规范化并校验十六进制颜色。
     *
     * @param string $color 原始颜色字符串
     * @return string 小写 #rrggbb；无效时返回默认色
     */
    public function normalize(string $color): string
    {
        $normalized = $this->tryNormalize($color);
        return $normalized !== null ? $normalized : self::DEFAULT_COLOR;
    }

    /**
     * 判断颜色是否为合法 #rrggbb / #rgb。
     *
     * @param string $color 原始颜色
     * @return bool
     */
    public function isValid(string $color): bool
    {
        return $this->tryNormalize($color) !== null;
    }

    /**
     * 基于主色派生完整品牌色板。
     *
     * @param string $color 主色
     * @return array{
     *     accent:string,
     *     accent_hover:string,
     *     accent_soft:string,
     *     accent_soft_strong:string,
     *     accent_border:string,
     *     selection_ink:string,
     *     on_accent:string,
     *     accent_rgb:string,
     *     accent_r:int,
     *     accent_g:int,
     *     accent_b:int
     * }
     */
    public function palette(string $color): array
    {
        $accent = $this->normalize($color);
        $rgb = $this->hexToRgb($accent);
        $hsl = $this->rgbToHsl($rgb[0], $rgb[1], $rgb[2]);

        // 悬停：略增饱和并压暗，保证可感知的交互反馈且不过度发黑。
        $hoverLightness = max(0.16, min(0.48, $hsl[2] - 0.09));
        if ($hsl[2] < 0.28) {
            // 极深主色改为略提亮，避免 hover 与主色几乎无差别。
            $hoverLightness = min(0.42, $hsl[2] + 0.08);
        }
        $hover = $this->hslToHex(
            $hsl[0],
            min(1.0, max(0.12, $hsl[1] * 1.06)),
            $hoverLightness
        );

        // 浅底 / 描边：与白混合，保留品牌色相，避免 HSL 降饱和后发灰。
        $soft = $this->mixWithWhite($rgb, 0.88);
        $softStrong = $this->mixWithWhite($rgb, 0.78);
        $border = $this->mixWithWhite($rgb, 0.68);

        // 选中文字：同源深色，亮度压到可读区间，浅色主色仍保持足够对比。
        $selectionLightness = max(0.16, min(0.36, $hsl[2] * 0.42));
        if ($hsl[2] > 0.72) {
            $selectionLightness = max(0.18, min(0.32, $hsl[2] - 0.48));
        }
        $selection = $this->hslToHex(
            $hsl[0],
            min(1.0, max(0.30, $hsl[1] * 1.05)),
            $selectionLightness
        );

        // 按钮/强调块上的前景色：按 WCAG 相对亮度自动切换深浅。
        $onAccent = $this->relativeLuminance($rgb[0], $rgb[1], $rgb[2]) > 0.55
            ? '#0f172a'
            : '#ffffff';

        return [
            'accent' => $accent,
            'accent_hover' => $hover,
            'accent_soft' => $soft,
            'accent_soft_strong' => $softStrong,
            'accent_border' => $border,
            'selection_ink' => $selection,
            'on_accent' => $onAccent,
            'accent_rgb' => sprintf('%d, %d, %d', $rgb[0], $rgb[1], $rgb[2]),
            'accent_r' => $rgb[0],
            'accent_g' => $rgb[1],
            'accent_b' => $rgb[2],
        ];
    }

    /**
     * 生成可嵌入页面的主题覆盖样式。
     *
     * @param string $color 主色
     * @return string 完整 style 标签内容（不含标签）
     */
    public function cssOverrides(string $color): string
    {
        $palette = $this->palette($color);
        $switchFill = rawurlencode($palette['accent']);

        $lines = [
            ':root{',
            sprintf('--nova-accent:%s;', $palette['accent']),
            sprintf('--nova-accent-hover:%s;', $palette['accent_hover']),
            sprintf('--nova-accent-soft:%s;', $palette['accent_soft']),
            sprintf('--nova-accent-soft-strong:%s;', $palette['accent_soft_strong']),
            sprintf('--nova-accent-border:%s;', $palette['accent_border']),
            sprintf('--nova-accent-rgb:%s;', $palette['accent_rgb']),
            sprintf('--nova-selection-ink:%s;', $palette['selection_ink']),
            sprintf('--nova-on-accent:%s;', $palette['on_accent']),
            sprintf('--nova-focus-ring:0 0 0 3px rgba(%s,0.22);', $palette['accent_rgb']),
            sprintf('--nova-control-focus-shadow:0 0 0 3px rgba(%s,0.18);', $palette['accent_rgb']),
            sprintf('--nova-accent-glow:0 10px 28px rgba(%s,0.28);', $palette['accent_rgb']),
            sprintf('--bs-primary:%s;', $palette['accent']),
            sprintf('--bs-primary-rgb:%s;', $palette['accent_rgb']),
            sprintf('--bs-focus-ring-color:rgba(%s,0.25);', $palette['accent_rgb']),
            '}',
            // 开关焦点圆点需写入 data-uri，随主题主色同步变化。
            sprintf(
                '.form-switch .form-check-input:focus{--bs-form-switch-bg:url("data:image/svg+xml,%%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'-4 -4 8 8\'%%3e%%3ccircle r=\'3\' fill=\'%s\'/%%3e%%3c/svg%%3e");}',
                $switchFill
            ),
        ];

        return implode('', $lines);
    }

    /**
     * 渲染供模板输出的 style 标签 HTML。
     *
     * @param string $color 主色
     * @return string 安全的 style 标签
     */
    public function styleTag(string $color): string
    {
        return '<style id="nova-theme-vars">' . $this->cssOverrides($color) . '</style>';
    }

    /**
     * 返回后台主题配置页所需的视图数据。
     *
     * @param string $currentColor 当前已保存颜色
     * @return array{theme_color:string,theme_presets:array<int,array{key:string,label:string,color:string,selected:bool}>,theme_palette:array<string,mixed>}
     */
    public function formData(string $currentColor): array
    {
        $normalized = $this->normalize($currentColor);
        $presets = [];
        foreach (self::PRESETS as $preset) {
            $presets[] = [
                'key' => $preset['key'],
                'label' => $preset['label'],
                'color' => $preset['color'],
                'selected' => strtolower($preset['color']) === $normalized,
            ];
        }

        return [
            'theme_color' => $normalized,
            'theme_presets' => $presets,
            'theme_palette' => $this->palette($normalized),
        ];
    }

    /**
     * @param string $color 原始颜色
     * @return string|null 规范化结果
     */
    private function tryNormalize(string $color): ?string
    {
        $value = strtolower(trim($color));
        if ($value === '') {
            return null;
        }
        if ($value[0] !== '#') {
            $value = '#' . $value;
        }
        if (preg_match('/^#([0-9a-f]{3})$/', $value, $matches)) {
            $short = $matches[1];
            return sprintf(
                '#%s%s%s%s%s%s',
                $short[0],
                $short[0],
                $short[1],
                $short[1],
                $short[2],
                $short[2]
            );
        }
        if (preg_match('/^#[0-9a-f]{6}$/', $value)) {
            return $value;
        }

        return null;
    }

    /**
     * @param string $hex #rrggbb
     * @return array{0:int,1:int,2:int}
     */
    private function hexToRgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    /**
     * 将主色与白色按比例混合，得到仍带品牌色相的浅色。
     *
     * @param array{0:int,1:int,2:int} $rgb 主色 RGB
     * @param float $whiteRatio 白色占比 0-1（越大越浅）
     * @return string #rrggbb
     */
    private function mixWithWhite(array $rgb, float $whiteRatio): string
    {
        $ratio = max(0.0, min(1.0, $whiteRatio));
        $mix = static function (int $channel) use ($ratio): int {
            return (int) round(255 * $ratio + $channel * (1.0 - $ratio));
        };

        return sprintf(
            '#%02x%02x%02x',
            $mix($rgb[0]),
            $mix($rgb[1]),
            $mix($rgb[2])
        );
    }

    /**
     * 计算 sRGB 相对亮度（WCAG 2.x）。
     *
     * @param int $r 红 0-255
     * @param int $g 绿 0-255
     * @param int $b 蓝 0-255
     * @return float 0-1
     */
    private function relativeLuminance(int $r, int $g, int $b): float
    {
        $channel = static function (int $value): float {
            $srgb = $value / 255;
            return $srgb <= 0.03928
                ? $srgb / 12.92
                : pow(($srgb + 0.055) / 1.055, 2.4);
        };

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /**
     * @param int $r 红
     * @param int $g 绿
     * @param int $b 蓝
     * @return array{0:float,1:float,2:float} H(0-360), S(0-1), L(0-1)
     */
    private function rgbToHsl(int $r, int $g, int $b): array
    {
        $rf = $r / 255;
        $gf = $g / 255;
        $bf = $b / 255;
        $max = max($rf, $gf, $bf);
        $min = min($rf, $gf, $bf);
        $lightness = ($max + $min) / 2;
        $delta = $max - $min;

        if ($delta < 0.00001) {
            return [0.0, 0.0, $lightness];
        }

        $saturation = $lightness > 0.5
            ? $delta / (2.0 - $max - $min)
            : $delta / ($max + $min);

        if ($max === $rf) {
            $hue = (($gf - $bf) / $delta) + ($gf < $bf ? 6.0 : 0.0);
        } elseif ($max === $gf) {
            $hue = (($bf - $rf) / $delta) + 2.0;
        } else {
            $hue = (($rf - $gf) / $delta) + 4.0;
        }

        return [$hue * 60.0, $saturation, $lightness];
    }

    /**
     * @param float $h 色相 0-360
     * @param float $s 饱和度 0-1
     * @param float $l 亮度 0-1
     * @return string #rrggbb
     */
    private function hslToHex(float $h, float $s, float $l): string
    {
        $h = fmod(($h + 360.0), 360.0);
        $s = max(0.0, min(1.0, $s));
        $l = max(0.0, min(1.0, $l));

        if ($s < 0.00001) {
            $value = (int) round($l * 255);
            return sprintf('#%02x%02x%02x', $value, $value, $value);
        }

        $chroma = (1 - abs(2 * $l - 1)) * $s;
        $huePrime = $h / 60.0;
        $x = $chroma * (1 - abs(fmod($huePrime, 2.0) - 1));
        $m = $l - $chroma / 2;

        if ($huePrime < 1) {
            $rf = $chroma;
            $gf = $x;
            $bf = 0.0;
        } elseif ($huePrime < 2) {
            $rf = $x;
            $gf = $chroma;
            $bf = 0.0;
        } elseif ($huePrime < 3) {
            $rf = 0.0;
            $gf = $chroma;
            $bf = $x;
        } elseif ($huePrime < 4) {
            $rf = 0.0;
            $gf = $x;
            $bf = $chroma;
        } elseif ($huePrime < 5) {
            $rf = $x;
            $gf = 0.0;
            $bf = $chroma;
        } else {
            $rf = $chroma;
            $gf = 0.0;
            $bf = $x;
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($rf + $m) * 255),
            (int) round(($gf + $m) * 255),
            (int) round(($bf + $m) * 255)
        );
    }
}
