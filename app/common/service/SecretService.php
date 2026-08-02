<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\AdminException;

/**
 * 使用安装时生成的应用密钥加密服务端敏感配置。
 */
class SecretService
{
    private const CIPHER = 'aes-256-gcm';
    private const FORMAT_PREFIX = 'v1:';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    /**
     * @param string $plaintext 明文
     * @return string 带版本前缀的密文
     * @throws AdminException 密钥或加密过程无效时抛出
     */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );
        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new AdminException('敏感配置加密失败。');
        }

        return self::FORMAT_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * @param string $encrypted 密文；空值直接返回
     * @return string 明文
     * @throws AdminException 密文损坏或密钥不匹配时抛出
     */
    public function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }
        if (strpos($encrypted, self::FORMAT_PREFIX) !== 0) {
            throw new AdminException('敏感配置格式无效，请在后台重新保存。');
        }

        $payload = base64_decode(substr($encrypted, strlen(self::FORMAT_PREFIX)), true);
        if ($payload === false || strlen($payload) <= self::IV_LENGTH + self::TAG_LENGTH) {
            throw new AdminException('敏感配置内容损坏，请在后台重新保存。');
        }

        $iv = substr($payload, 0, self::IV_LENGTH);
        $tag = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plaintext === false) {
            throw new AdminException('敏感配置无法解密，请在后台重新保存。');
        }

        return $plaintext;
    }

    /**
     * @return string 32 字节二进制密钥
     * @throws AdminException 应用密钥缺失时抛出
     */
    private function key(): string
    {
        $configured = (string) env('security.app_key', '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $configured)) {
            throw new AdminException('应用密钥缺失或格式无效，请重新执行安装。');
        }

        $key = hex2bin($configured);
        if ($key === false) {
            throw new AdminException('应用密钥无法解析。');
        }

        return $key;
    }
}
