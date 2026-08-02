<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\InstallException;

/**
 * 通过同目录临时文件和原子重命名创建关键配置文件。
 */
class AtomicFileWriter
{
    /**
     * @param string $path 目标绝对路径
     * @param string $contents 文件内容
     * @throws InstallException 文件存在或写入失败时抛出
     */
    public function create(string $path, string $contents): void
    {
        if (file_exists($path)) {
            throw new InstallException(sprintf('文件 %s 已存在，为避免覆盖已停止安装。', basename($path)));
        }

        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new InstallException(sprintf('目录 %s 不可写。', $directory));
        }

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            throw new InstallException('无法生成安全的临时文件名。', 0, $exception);
        }

        $temporaryPath = $path . '.tmp.' . $suffix;
        $bytes = @file_put_contents($temporaryPath, $contents, LOCK_EX);
        if ($bytes === false || $bytes !== strlen($contents)) {
            @unlink($temporaryPath);
            throw new InstallException(sprintf('写入 %s 失败。', basename($path)));
        }

        @chmod($temporaryPath, 0640);
        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new InstallException(sprintf('生成 %s 失败。', basename($path)));
        }
    }

    /**
     * @param string $path 仅删除当前安装流程创建的文件
     * @return void
     */
    public function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
