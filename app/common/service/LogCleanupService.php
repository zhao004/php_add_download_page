<?php

declare(strict_types=1);

namespace app\common\service;

use DateInterval;
use DateTimeImmutable;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use think\App;
use think\facade\Db;

/**
 * 按统一保留期清理数据库访问记录和 ThinkPHP 运行日志。
 */
class LogCleanupService
{
    public const DEFAULT_RETENTION_DAYS = 90;
    public const MIN_RETENTION_DAYS = 1;
    public const MAX_RETENTION_DAYS = 3650;

    /** @var string */
    private $runtimePath;

    /**
     * @param App $app ThinkPHP 应用实例
     * @param string $runtimePath 测试时可注入隔离目录，生产环境留空
     */
    public function __construct(App $app, string $runtimePath = '')
    {
        $path = $runtimePath !== '' ? $runtimePath : $app->getRuntimePath();
        $this->runtimePath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * @param mixed $value 命令行传入的保留天数
     * @return int 校验后的保留天数
     * @throws InvalidArgumentException 天数不是指定范围内的正整数时抛出
     */
    public static function parseRetentionDays($value): int
    {
        if (is_int($value)) {
            $normalized = (string) $value;
        } elseif (is_string($value)) {
            $normalized = trim($value);
        } else {
            throw new InvalidArgumentException('保留天数必须是整数。');
        }

        if (!preg_match('/^[1-9]\d*$/D', $normalized)) {
            throw new InvalidArgumentException('保留天数必须是正整数。');
        }

        $days = (int) $normalized;
        if ($days < self::MIN_RETENTION_DAYS || $days > self::MAX_RETENTION_DAYS) {
            throw new InvalidArgumentException(sprintf(
                '保留天数必须在 %d 至 %d 天之间。',
                self::MIN_RETENTION_DAYS,
                self::MAX_RETENTION_DAYS
            ));
        }

        return $days;
    }

    /**
     * @param int $retentionDays 保留天数
     * @param bool $dryRun 是否只统计而不删除
     * @return array{cutoff:string,visit_logs:int,download_logs:int,runtime_logs:int} 清理统计
     * @throws InvalidArgumentException 保留天数越界时抛出
     * @throws RuntimeException 文件扫描或删除失败时抛出
     */
    public function cleanup(int $retentionDays, bool $dryRun): array
    {
        $retentionDays = self::parseRetentionDays($retentionDays);
        $cutoff = (new DateTimeImmutable('now'))->sub(new DateInterval('P' . $retentionDays . 'D'));
        $databaseCounts = $this->cleanupDatabaseLogs($cutoff, $dryRun);
        $runtimeCount = $this->cleanupRuntimeLogs($cutoff->getTimestamp(), $dryRun);

        return [
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'visit_logs' => $databaseCounts['visit_logs'],
            'download_logs' => $databaseCounts['download_logs'],
            'runtime_logs' => $runtimeCount,
        ];
    }

    /**
     * @param int $cutoffTimestamp 文件修改时间必须早于该时间戳
     * @param bool $dryRun 是否只统计而不删除
     * @return int 匹配或成功删除的日志文件数
     * @throws RuntimeException 目录扫描或文件删除失败时抛出
     */
    public function cleanupRuntimeLogs(int $cutoffTimestamp, bool $dryRun): int
    {
        if ($cutoffTimestamp < 1) {
            throw new InvalidArgumentException('日志截止时间无效。');
        }
        if (!is_dir($this->runtimePath)) {
            return 0;
        }

        try {
            $directory = new RecursiveDirectoryIterator($this->runtimePath, FilesystemIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::LEAVES_ONLY);
            $affected = 0;

            foreach ($files as $file) {
                // 符号链接不纳入清理，避免运维误配置把范围带出 runtime。
                if (!$file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'log') {
                    continue;
                }
                if ($file->getMTime() >= $cutoffTimestamp) {
                    continue;
                }

                if (!$dryRun && !@unlink($file->getPathname())) {
                    throw new RuntimeException('无法删除运行日志：' . $file->getPathname());
                }
                $affected++;
            }

            return $affected;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RuntimeException('扫描运行日志失败：' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param DateTimeImmutable $cutoff 数据库保留截止时间
     * @param bool $dryRun 是否只统计而不删除
     * @return array{visit_logs:int,download_logs:int} 数据库日志统计
     */
    private function cleanupDatabaseLogs(DateTimeImmutable $cutoff, bool $dryRun): array
    {
        $cutoffText = $cutoff->format('Y-m-d H:i:s');
        if ($dryRun) {
            return [
                'visit_logs' => (int) Db::name('visit_log')->where('created_at', '<', $cutoffText)->count(),
                'download_logs' => (int) Db::name('download_click_log')->where('created_at', '<', $cutoffText)->count(),
            ];
        }

        /** @var array{visit_logs:int,download_logs:int} $counts */
        $counts = Db::transaction(static function () use ($cutoffText): array {
            return [
                'visit_logs' => Db::name('visit_log')->where('created_at', '<', $cutoffText)->delete(),
                'download_logs' => Db::name('download_click_log')->where('created_at', '<', $cutoffText)->delete(),
            ];
        });

        return $counts;
    }
}
