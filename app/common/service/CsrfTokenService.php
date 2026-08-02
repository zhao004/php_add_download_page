<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\AdminException;
use think\facade\Session;

/**
 * 为不同业务域生成相互隔离的 CSRF 令牌。
 */
class CsrfTokenService
{
    /**
     * @param string $scope 令牌作用域
     * @return string 当前会话令牌
     */
    public function token(string $scope): string
    {
        $key = $this->sessionKey($scope);
        $token = (string) Session::get($key, '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set($key, $token);
        }

        return $token;
    }

    /**
     * @param string $scope 令牌作用域
     * @param string $actual 请求携带的令牌
     * @return void
     * @throws AdminException 令牌缺失或不匹配时抛出
     */
    public function assertValid(string $scope, string $actual): void
    {
        $expected = (string) Session::get($this->sessionKey($scope), '');
        if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
            throw new AdminException('页面已过期，请刷新后重试。');
        }
    }

    /**
     * @param string $scope 令牌作用域
     * @return string Session 键名
     */
    private function sessionKey(string $scope): string
    {
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $scope)) {
            throw new \InvalidArgumentException('CSRF 作用域格式无效。');
        }

        return 'csrf_' . $scope;
    }
}
