<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\InstallException;

/**
 * 集中校验安装表单，确保数据库标识符和管理员凭据可安全使用。
 */
class InstallInputValidator
{
    private const ALLOWED_CHARSETS = ['utf8mb4', 'utf8'];

    /**
     * @param array<string, mixed> $input 数据库表单
     * @return array{hostname:string,hostport:int,database:string,username:string,password:string,charset:string,prefix:string,create_database:bool}
     * @throws InstallException 输入无效时抛出
     */
    public function database(array $input): array
    {
        $hostname = trim((string) ($input['hostname'] ?? ''));
        $database = trim((string) ($input['database'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $charset = strtolower(trim((string) ($input['charset'] ?? 'utf8mb4')));
        $prefix = trim((string) ($input['prefix'] ?? ''));
        $portValue = filter_var($input['hostport'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        if ($hostname === '' || strlen($hostname) > 255 || preg_match('/[\x00-\x1F\x7F]/', $hostname)) {
            throw new InstallException('数据库主机不能为空，且不能包含控制字符。');
        }
        if ($portValue === false) {
            throw new InstallException('数据库端口必须是 1 到 65535 之间的整数。');
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)) {
            throw new InstallException('数据库名只能包含字母、数字和下划线，长度不超过 64 位。');
        }
        if ($username === '' || strlen($username) > 64 || preg_match('/[\x00-\x1F\x7F]/', $username)) {
            throw new InstallException('数据库用户名不能为空，且长度不能超过 64 位。');
        }
        if (strlen($password) > 255 || preg_match('/[\r\n\x00]/', $password)) {
            throw new InstallException('数据库密码长度不能超过 255 位，且不能包含换行或空字节。');
        }
        if (!in_array($charset, self::ALLOWED_CHARSETS, true)) {
            throw new InstallException('数据库字符集仅支持 utf8mb4 或 utf8。');
        }
        if (!preg_match('/^[A-Za-z0-9_]{0,32}$/', $prefix)) {
            throw new InstallException('数据表前缀只能包含字母、数字和下划线，长度不超过 32 位。');
        }

        return [
            'hostname' => $hostname,
            'hostport' => (int) $portValue,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => $charset,
            'prefix' => $prefix,
            'create_database' => filter_var(
                $input['create_database'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input 管理员表单
     * @return array{username:string,password:string}
     * @throws InstallException 输入无效时抛出
     */
    public function administrator(array $input): array
    {
        $username = trim((string) ($input['admin_username'] ?? ''));
        $password = (string) ($input['admin_password'] ?? '');
        $confirmation = (string) ($input['admin_password_confirmation'] ?? '');

        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $username)) {
            throw new InstallException('管理员用户名需以字母开头，只能包含字母、数字和下划线，长度为 4 到 32 位。');
        }
        if ($password !== $confirmation) {
            throw new InstallException('两次输入的管理员密码不一致。');
        }
        if (strlen($password) < 10 || strlen($password) > 72 || preg_match('/[\r\n\x00]/', $password)) {
            throw new InstallException('管理员密码长度必须为 10 到 72 位。');
        }

        $strengthGroups = 0;
        $strengthGroups += preg_match('/[a-z]/', $password) ? 1 : 0;
        $strengthGroups += preg_match('/[A-Z]/', $password) ? 1 : 0;
        $strengthGroups += preg_match('/\d/', $password) ? 1 : 0;
        $strengthGroups += preg_match('/[^A-Za-z0-9]/', $password) ? 1 : 0;
        if ($strengthGroups < 3) {
            throw new InstallException('管理员密码至少包含大小写字母、数字、特殊字符中的三类。');
        }

        return ['username' => $username, 'password' => $password];
    }
}
