<?php

declare(strict_types=1);

namespace app\common\service;

/**
 * 生成 ThinkPHP 可读取的 INI 格式环境配置。
 */
class EnvFileService
{
    /**
     * @param array{hostname:string,hostport:int,database:string,username:string,password:string,charset:string,prefix:string,create_database:bool} $database 数据库配置
     * @param string $applicationKey 随机应用密钥
     * @return string 完整 .env 内容
     */
    public function build(array $database, string $applicationKey): string
    {
        $lines = [
            'APP_DEBUG = false',
            '',
            '[APP]',
            'DEFAULT_TIMEZONE = Asia/Shanghai',
            '',
            '[DATABASE]',
            'TYPE = mysql',
            'HOSTNAME = ' . $this->encode((string) $database['hostname']),
            'DATABASE = ' . $this->encode((string) $database['database']),
            'USERNAME = ' . $this->encode((string) $database['username']),
            'PASSWORD = ' . $this->encode((string) $database['password']),
            'HOSTPORT = ' . $database['hostport'],
            'CHARSET = ' . $database['charset'],
            'PREFIX = ' . $this->encode((string) $database['prefix']),
            '',
            '[SECURITY]',
            'APP_KEY = ' . $this->encode($applicationKey),
            '',
            '[COOKIE]',
            'SECURE = false',
            '',
            '[LOG]',
            'MAX_FILES = 30',
            '',
            '[UPLOAD]',
            'APK_MAX_MB = ' . UploadLimitService::DEFAULT_APK_MAX_MB,
            'IMAGE_MAX_MB = ' . UploadLimitService::DEFAULT_IMAGE_MAX_MB,
            'SVG_MAX_MB = ' . UploadLimitService::DEFAULT_SVG_MAX_MB,
            '',
            '[LANG]',
            'DEFAULT_LANG = zh-cn',
            '',
        ];

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param string $value 原始值
     * @return string 双引号包裹的 INI 安全值
     */
    private function encode(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
