<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\InstallException;
use Throwable;
use think\App;

/**
 * 编排数据库、环境配置和安装锁，保证锁文件始终最后生成。
 */
class InstallationService
{
    /** @var App */
    private $app;

    /** @var InstallStateService */
    private $installState;

    /** @var DatabaseInstaller */
    private $databaseInstaller;

    /** @var EnvFileService */
    private $envFile;

    /** @var AtomicFileWriter */
    private $fileWriter;

    /**
     * @param App $app ThinkPHP 应用实例
     * @param InstallStateService $installState 安装状态服务
     * @param DatabaseInstaller $databaseInstaller 数据库安装器
     * @param EnvFileService $envFile 环境文件生成器
     * @param AtomicFileWriter $fileWriter 原子文件写入器
     */
    public function __construct(
        App $app,
        InstallStateService $installState,
        DatabaseInstaller $databaseInstaller,
        EnvFileService $envFile,
        AtomicFileWriter $fileWriter
    ) {
        $this->app = $app;
        $this->installState = $installState;
        $this->databaseInstaller = $databaseInstaller;
        $this->envFile = $envFile;
        $this->fileWriter = $fileWriter;
    }

    /**
     * @param array{hostname:string,hostport:int,database:string,username:string,password:string,charset:string,prefix:string,create_database:bool} $database 数据库配置
     * @param array{username:string,password:string} $administrator 管理员凭据
     * @return void
     * @throws InstallException 安装失败或并发安装时抛出
     */
    public function install(array $database, array $administrator): void
    {
        if ($this->installState->isInstalled()) {
            throw new InstallException('系统已经安装，不能重复执行安装。');
        }

        $envPath = $this->app->getRootPath() . '.env';
        if (file_exists($envPath)) {
            throw new InstallException('.env 已存在。请确认它不是现有站点配置后再处理，安装器不会自动覆盖。');
        }

        $processLockPath = $this->app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'installing.lock';
        $processLock = @fopen($processLockPath, 'c+');
        if ($processLock === false || !flock($processLock, LOCK_EX | LOCK_NB)) {
            if (is_resource($processLock)) {
                fclose($processLock);
            }
            throw new InstallException('另一个安装请求正在执行，请稍后重试。');
        }

        try {
            if ($this->installState->isInstalled()) {
                throw new InstallException('系统已经安装，不能重复执行安装。');
            }

            $applicationKey = bin2hex(random_bytes(32));
            $envContents = $this->envFile->build($database, $applicationKey);
            $envCreated = false;

            $this->databaseInstaller->install(
                $database,
                $administrator,
                function () use ($envPath, $envContents, &$envCreated): void {
                    $this->fileWriter->create($envPath, $envContents);
                    $envCreated = true;

                    try {
                        $lock = json_encode([
                            'installed_at' => date(DATE_ATOM),
                            'product' => 'NOVA App Download',
                            'schema_version' => 1,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                        if ($lock === false) {
                            throw new InstallException('安装锁内容生成失败。');
                        }
                        $this->fileWriter->create($this->installState->lockPath(), $lock . PHP_EOL);
                    } catch (Throwable $exception) {
                        $this->fileWriter->remove($envPath);
                        $envCreated = false;
                        throw $exception;
                    }
                }
            );
        } catch (InstallException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InstallException('安装失败：' . mb_substr($exception->getMessage(), 0, 240), 0, $exception);
        } finally {
            flock($processLock, LOCK_UN);
            fclose($processLock);
            @unlink($processLockPath);
        }
    }
}
