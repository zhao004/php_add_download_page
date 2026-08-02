<?php

declare(strict_types=1);

namespace app\common\service;

use think\App;

/**
 * 检测安装所需的 PHP 扩展和目录权限。
 */
class EnvironmentService
{
    private const MINIMUM_PHP_VERSION = '7.4.0';

    private const REQUIRED_EXTENSIONS = [
        'pdo',
        'pdo_mysql',
        'openssl',
        'curl',
        'fileinfo',
        'mbstring',
        'json',
        'dom',
    ];

    /** @var App */
    private $app;

    /**
     * @param App $app ThinkPHP 应用实例
     */
    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @return array<int, array{name:string,required:string,current:string,passed:bool,blocking:bool}> 检测明细
     */
    public function checks(): array
    {
        $checks = [[
            'name' => 'PHP 版本',
            'required' => '>=' . self::MINIMUM_PHP_VERSION,
            'current' => PHP_VERSION,
            'passed' => version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '>='),
            'blocking' => true,
        ]];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $checks[] = [
                'name' => '扩展 ' . $extension,
                'required' => '已启用',
                'current' => extension_loaded($extension) ? '已启用' : '未启用',
                'passed' => extension_loaded($extension),
                'blocking' => true,
            ];
        }

        foreach ($this->writablePaths() as $label => $path) {
            $checks[] = [
                'name' => $label,
                'required' => '可写',
                'current' => $path,
                'passed' => is_dir($path) && is_writable($path),
                'blocking' => true,
            ];
        }

        $xdbPath = $this->app->getRootPath() . 'data' . DIRECTORY_SEPARATOR . 'ip2region.xdb';
        $checks[] = [
            'name' => 'ip2region 数据库',
            'required' => '存在且可读（建议）',
            'current' => $xdbPath,
            'passed' => is_file($xdbPath) && is_readable($xdbPath),
            'blocking' => false,
        ];

        return $checks;
    }

    /**
     * @return bool 阻断项是否全部通过
     */
    public function canInstall(): bool
    {
        foreach ($this->checks() as $check) {
            if ($check['blocking'] && !$check['passed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string> 需要可写的目录
     */
    private function writablePaths(): array
    {
        return [
            '项目根目录' => rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR),
            '运行目录' => $this->app->getRootPath() . 'runtime',
            '上传目录' => $this->app->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'uploads',
        ];
    }
}
