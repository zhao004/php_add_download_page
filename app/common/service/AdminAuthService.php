<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\AdminException;
use think\facade\Db;
use think\facade\Session;

/**
 * 管理后台 Session 认证与登录限次策略。
 */
class AdminAuthService
{
    private const SESSION_ADMIN_ID = 'admin_id';
    private const SESSION_ADMIN_USERNAME = 'admin_username';
    private const SESSION_FAILURES = 'admin_login_failures';
    private const MAX_FAILURES = 5;
    private const FAILURE_WINDOW_SECONDS = 600;

    /**
     * @return bool 当前会话是否已登录
     */
    public function isAuthenticated(): bool
    {
        return (int) Session::get(self::SESSION_ADMIN_ID, 0) > 0;
    }

    /**
     * @return array{id:int,username:string}|null 当前管理员
     */
    public function currentAdmin(): ?array
    {
        $id = (int) Session::get(self::SESSION_ADMIN_ID, 0);
        if ($id <= 0) {
            return null;
        }

        return [
            'id' => $id,
            'username' => (string) Session::get(self::SESSION_ADMIN_USERNAME, ''),
        ];
    }

    /**
     * @param string $username 用户名
     * @param string $password 明文密码
     * @param string $ip 登录 IP
     * @return void
     * @throws AdminException 凭据错误或尝试过于频繁时抛出
     */
    public function login(string $username, string $password, string $ip): void
    {
        $username = trim($username);
        if ($username === '' || strlen($username) > 64 || $password === '' || strlen($password) > 72) {
            throw new AdminException('用户名或密码错误。');
        }

        $this->assertNotThrottled();
        $admin = Db::name('admin_user')->where('username', $username)->find();
        $storedHash = is_array($admin) ? (string) ($admin['password'] ?? '') : '';

        // 用户不存在时仍执行同成本哈希校验，降低用户名枚举的时间差。
        if ($storedHash === '') {
            $storedHash = (string) password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        }

        if (!is_array($admin) || !password_verify($password, $storedHash)) {
            $this->recordFailure();
            throw new AdminException('用户名或密码错误。');
        }

        if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            if ($newHash !== false) {
                Db::name('admin_user')->where('id', (int) $admin['id'])->update([
                    'password' => $newHash,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        Session::regenerate(true);
        Session::set(self::SESSION_ADMIN_ID, (int) $admin['id']);
        Session::set(self::SESSION_ADMIN_USERNAME, (string) $admin['username']);
        Session::delete(self::SESSION_FAILURES);

        Db::name('admin_user')->where('id', (int) $admin['id'])->update([
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return void
     */
    public function logout(): void
    {
        Session::delete(self::SESSION_ADMIN_ID);
        Session::delete(self::SESSION_ADMIN_USERNAME);
        Session::delete('csrf_admin');
        Session::regenerate(true);
    }

    /**
     * @param int $adminId 管理员 ID
     * @param string $currentPassword 当前密码
     * @param string $newPassword 新密码
     * @param string $confirmation 确认密码
     * @return void
     * @throws AdminException 密码校验失败时抛出
     */
    public function changePassword(
        int $adminId,
        string $currentPassword,
        string $newPassword,
        string $confirmation
    ): void {
        if ($adminId <= 0 || $currentPassword === '') {
            throw new AdminException('当前密码错误。');
        }
        if ($newPassword !== $confirmation) {
            throw new AdminException('两次输入的新密码不一致。');
        }
        if (strlen($newPassword) < 10 || strlen($newPassword) > 72 || preg_match('/[\r\n\x00]/', $newPassword)) {
            throw new AdminException('新密码长度必须为 10 到 72 位。');
        }

        $strengthGroups = 0;
        $strengthGroups += preg_match('/[a-z]/', $newPassword) ? 1 : 0;
        $strengthGroups += preg_match('/[A-Z]/', $newPassword) ? 1 : 0;
        $strengthGroups += preg_match('/\d/', $newPassword) ? 1 : 0;
        $strengthGroups += preg_match('/[^A-Za-z0-9]/', $newPassword) ? 1 : 0;
        if ($strengthGroups < 3) {
            throw new AdminException('新密码至少包含大小写字母、数字、特殊字符中的三类。');
        }

        $admin = Db::name('admin_user')->where('id', $adminId)->find();
        if (!is_array($admin) || !password_verify($currentPassword, (string) $admin['password'])) {
            throw new AdminException('当前密码错误。');
        }
        if (password_verify($newPassword, (string) $admin['password'])) {
            throw new AdminException('新密码不能与当前密码相同。');
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new AdminException('新密码哈希失败。');
        }

        Db::name('admin_user')->where('id', $adminId)->update([
            'password' => $passwordHash,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return void
     * @throws AdminException 达到失败上限时抛出
     */
    private function assertNotThrottled(): void
    {
        $state = Session::get(self::SESSION_FAILURES, []);
        if (!is_array($state)) {
            Session::delete(self::SESSION_FAILURES);
            return;
        }

        $startedAt = (int) ($state['started_at'] ?? 0);
        $attempts = (int) ($state['attempts'] ?? 0);
        $elapsed = time() - $startedAt;
        if ($startedAt <= 0 || $elapsed >= self::FAILURE_WINDOW_SECONDS) {
            Session::delete(self::SESSION_FAILURES);
            return;
        }

        if ($attempts >= self::MAX_FAILURES) {
            $remainingMinutes = max(1, (int) ceil((self::FAILURE_WINDOW_SECONDS - $elapsed) / 60));
            throw new AdminException(sprintf('登录尝试过于频繁，请在 %d 分钟后重试。', $remainingMinutes));
        }
    }

    /**
     * @return void
     */
    private function recordFailure(): void
    {
        $state = Session::get(self::SESSION_FAILURES, []);
        $startedAt = is_array($state) ? (int) ($state['started_at'] ?? 0) : 0;
        $attempts = is_array($state) ? (int) ($state['attempts'] ?? 0) : 0;

        if ($startedAt <= 0 || time() - $startedAt >= self::FAILURE_WINDOW_SECONDS) {
            $startedAt = time();
            $attempts = 0;
        }

        Session::set(self::SESSION_FAILURES, [
            'started_at' => $startedAt,
            'attempts' => $attempts + 1,
        ]);
    }
}
