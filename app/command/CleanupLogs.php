<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\LogCleanupService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use Throwable;

/**
 * 清理超过保留期的访问、下载与运行日志。
 */
class CleanupLogs extends Command
{
    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('nova:logs:cleanup')
            ->addOption(
                'days',
                null,
                Option::VALUE_REQUIRED,
                '日志保留天数，范围 1-3650',
                (string) LogCleanupService::DEFAULT_RETENTION_DAYS
            )
            ->addOption('dry-run', null, Option::VALUE_NONE, '只统计，不删除数据或文件')
            ->setDescription('清理超过保留期的访问日志、下载日志和 runtime 日志');
    }

    /**
     * @param Input $input 命令行输入
     * @param Output $output 命令行输出
     * @return int 进程退出码
     */
    protected function execute(Input $input, Output $output)
    {
        try {
            $days = LogCleanupService::parseRetentionDays($input->getOption('days'));
            $dryRun = (bool) $input->getOption('dry-run');
            $result = (new LogCleanupService($this->app))->cleanup($days, $dryRun);
        } catch (Throwable $exception) {
            $output->error('日志清理失败：' . mb_substr($exception->getMessage(), 0, 200));
            return 1;
        }

        $output->writeln($dryRun ? '<comment>预演完成，未删除任何内容。</comment>' : '<info>日志清理完成。</info>');
        $output->writeln(sprintf('保留范围：%d 天（清理早于 %s 的记录）', $days, $result['cutoff']));
        $output->writeln(sprintf('访问日志：%d 条', $result['visit_logs']));
        $output->writeln(sprintf('下载日志：%d 条', $result['download_logs']));
        $output->writeln(sprintf('运行日志：%d 个文件', $result['runtime_logs']));

        return 0;
    }
}
