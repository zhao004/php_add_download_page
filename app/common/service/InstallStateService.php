<?php

declare(strict_types=1);

namespace app\common\service;

use think\App;

/**
 * 统一管理安装锁路径，避免各应用自行拼接目录。
 */
class InstallStateService
{
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
     * @return string 安装锁绝对路径
     */
    public function lockPath(): string
    {
        return $this->app->getRootPath() . 'install.lock';
    }

    /**
     * @return bool 是否已经完成安装
     */
    public function isInstalled(): bool
    {
        return is_file($this->lockPath());
    }
}
