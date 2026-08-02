<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Session;

/**
 * 管理后台登录验证码的生成、展示和一次性校验。
 */
class CaptchaService
{
    private const SESSION_KEY = 'admin_login_captcha';
    /** 验证码位数：4 位字母数字，校验时不区分大小写。 */
    private const ANSWER_LENGTH = 4;
    private const EXPIRE_SECONDS = 300;
    /** 排除易混淆字符 0/O、1/I/L，仅保留数字与大写字母。 */
    private const CHARACTER_SET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /**
     * 生成验证码 SVG 并把答案摘要写入当前会话。
     * 会话中始终保存大写规范化后的摘要，保证大小写无关校验。
     *
     * @return string SVG 图片内容
     */
    public function issue(): string
    {
        $answer = '';
        $characterCount = strlen(self::CHARACTER_SET);
        for ($index = 0; $index < self::ANSWER_LENGTH; $index++) {
            $answer .= self::CHARACTER_SET[random_int(0, $characterCount - 1)];
        }

        $normalized = self::normalizeAnswer($answer);
        Session::set(self::SESSION_KEY, [
            'hash' => hash('sha256', $normalized),
            'expires_at' => time() + self::EXPIRE_SECONDS,
        ]);

        // 展示时随机切换字母大小写，输入仍按不区分大小写校验。
        return $this->buildSvg($this->randomizeDisplayCase($normalized));
    }

    /**
     * 校验并立即消费验证码，防止同一验证码重复提交。
     *
     * @param string $answer 用户提交的答案
     * @return bool 验证码是否有效
     */
    public function verify(string $answer): bool
    {
        $state = Session::get(self::SESSION_KEY, []);
        Session::delete(self::SESSION_KEY);
        if (!is_array($state)) {
            return false;
        }

        $expiresAt = (int) ($state['expires_at'] ?? 0);
        $expectedHash = (string) ($state['hash'] ?? '');
        $normalized = self::normalizeAnswer($answer);
        if ($expiresAt <= time() || $expectedHash === '' || strlen($normalized) !== self::ANSWER_LENGTH) {
            return false;
        }

        return hash_equals($expectedHash, hash('sha256', $normalized));
    }

    /**
     * 统一验证码输入格式：仅保留英文大小写字母与数字，再转大写。
     * 空白与其它字符会被丢弃，校验不区分大小写。
     *
     * @param string $answer 原始输入
     * @return string 规范化后的答案
     */
    public static function normalizeAnswer(string $answer): string
    {
        $filtered = preg_replace('/[^A-Za-z0-9]/', '', $answer);
        if (!is_string($filtered) || $filtered === '') {
            return '';
        }

        return strtoupper($filtered);
    }

    /**
     * 返回验证码固定位数，供表单 maxlength 等前端约束复用。
     *
     * @return int 验证码位数
     */
    public static function answerLength(): int
    {
        return self::ANSWER_LENGTH;
    }

    /**
     * 将字母随机改为大小写，仅影响展示，不影响会话中的标准答案。
     *
     * @param string $answer 已规范化的大写答案
     * @return string 展示用字符串
     */
    private function randomizeDisplayCase(string $answer): string
    {
        $display = '';
        $length = strlen($answer);
        for ($index = 0; $index < $length; $index++) {
            $character = $answer[$index];
            if (ctype_alpha($character) && random_int(0, 1) === 1) {
                $display .= strtolower($character);
                continue;
            }
            $display .= $character;
        }

        return $display;
    }

    /**
     * 构造不依赖 GD 扩展的 SVG 验证码，图片内容完全由服务端生成。
     *
     * @param string $answer 展示用验证码文本（可含大小写）
     * @return string SVG 图片内容
     */
    private function buildSvg(string $answer): string
    {
        // 紧凑尺寸，避免登录表单中验证码区域过宽。
        $width = 120;
        $height = 42;
        $palette = ['#0f172a', '#1d6f9f', '#b45309', '#166534'];
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-label="登录验证码">',
            $width,
            $height,
            $width,
            $height
        );
        $svg .= sprintf('<rect width="%d" height="%d" rx="6" fill="#f8fafc"/>', $width, $height);

        for ($index = 0; $index < 4; $index++) {
            $x1 = random_int(4, $width - 4);
            $y1 = random_int(6, $height - 6);
            $x2 = random_int(4, $width - 4);
            $y2 = random_int(6, $height - 6);
            $svg .= sprintf(
                '<path d="M%d %d L%d %d" stroke="#cbd5e1" stroke-width="1"/>',
                $x1,
                $y1,
                $x2,
                $y2
            );
        }

        $characterCount = strlen($answer);
        for ($index = 0; $index < $characterCount; $index++) {
            $character = htmlspecialchars($answer[$index], ENT_QUOTES, 'UTF-8');
            // 4 位字符在 120px 宽度内居中排布。
            $x = 18 + ($index * 26);
            $rotation = random_int(-12, 12);
            $color = $palette[random_int(0, count($palette) - 1)];
            $svg .= sprintf(
                '<text x="%d" y="28" transform="rotate(%d %d 24)" fill="%s" font-family="Arial,sans-serif" font-size="18" font-weight="700">%s</text>',
                $x,
                $rotation,
                $x,
                $color,
                $character
            );
        }

        return $svg . '</svg>';
    }
}
