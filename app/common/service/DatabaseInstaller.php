<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\InstallException;
use PDO;
use PDOException;
use Throwable;
use think\App;

/**
 * 负责数据库连通性检测、结构导入和初始数据写入。
 */
class DatabaseInstaller
{
    private const TABLE_NAMES = [
        'admin_user',
        'site_config',
        'preview_page',
        'nav_item',
        'feature_item',
        'friend_link',
        'trust_brand',
        'visit_log',
        'download_click_log',
    ];

    /** @var App */
    private $app;

    /** @var SqlStatementParser */
    private $sqlParser;

    /**
     * @param App $app ThinkPHP 应用实例
     * @param SqlStatementParser $sqlParser SQL 解析器
     */
    public function __construct(App $app, SqlStatementParser $sqlParser)
    {
        $this->app = $app;
        $this->sqlParser = $sqlParser;
    }

    /**
     * @param array{hostname:string,hostport:int,database:string,username:string,password:string,charset:string,prefix:string,create_database:bool} $database 数据库配置
     * @return string 检测结果说明
     * @throws InstallException 连接失败时抛出
     */
    public function testConnection(array $database): string
    {
        try {
            if ($database['create_database']) {
                $pdo = $this->connect($database, false);
                $databases = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);

                return in_array($database['database'], $databases, true)
                    ? '数据库服务器连接成功，目标数据库已存在。'
                    : '数据库服务器连接成功，安装时将尝试创建目标数据库。';
            }

            $pdo = $this->connect($database, true);
            $pdo->query('SELECT 1');

            return '数据库连接成功。';
        } catch (PDOException $exception) {
            throw new InstallException('数据库连接失败：' . $this->safeDatabaseMessage($exception), 0, $exception);
        }
    }

    /**
     * @param array{hostname:string,hostport:int,database:string,username:string,password:string,charset:string,prefix:string,create_database:bool} $database 数据库配置
     * @param array{username:string,password:string} $administrator 管理员凭据
     * @param callable $finalize 数据库成功后写入配置与锁文件
     * @return void
     * @throws InstallException 安装任一步骤失败时抛出
     */
    public function install(array $database, array $administrator, callable $finalize): void
    {
        $pdo = null;
        $schemaStarted = false;
        $tables = $this->tableNames($database['prefix']);

        try {
            $this->ensureDatabase($database);
            $pdo = $this->connect($database, true);
            $this->assertTablesAvailable($pdo, $tables);
            $schemaStarted = true;
            $this->importSchema($pdo, $database['prefix']);

            $pdo->beginTransaction();
            $this->seedConfiguration($pdo, $database['prefix']);
            $this->seedDemoContent($pdo, $database['prefix']);
            $this->createAdministrator($pdo, $database['prefix'], $administrator);
            $pdo->commit();

            $finalize();
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($pdo instanceof PDO && $schemaStarted) {
                $this->cleanupTables($pdo, $tables);
            }
            if ($exception instanceof InstallException) {
                throw $exception;
            }
            throw new InstallException('数据库安装失败：' . $this->safeThrowableMessage($exception), 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $database 数据库配置
     * @return void
     * @throws InstallException 创建数据库失败时抛出
     */
    private function ensureDatabase(array $database): void
    {
        if (!$database['create_database']) {
            return;
        }

        try {
            $pdo = $this->connect($database, false);
            $databaseName = $database['database'];
            $existing = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array($databaseName, $existing, true)) {
                $charset = $database['charset'];
                $pdo->exec(sprintf(
                    'CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s_unicode_ci',
                    $databaseName,
                    $charset,
                    $charset
                ));
            }
        } catch (PDOException $exception) {
            throw new InstallException('创建数据库失败：' . $this->safeDatabaseMessage($exception), 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $database 数据库配置
     * @param bool $withDatabase 是否连接具体数据库
     * @return PDO
     */
    private function connect(array $database, bool $withDatabase): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;%scharset=%s',
            $database['hostname'],
            $database['hostport'],
            $withDatabase ? 'dbname=' . $database['database'] . ';' : '',
            $database['charset']
        );

        return new PDO($dsn, $database['username'], $database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * @param PDO $pdo 数据库连接
     * @param array<int, string> $tables 计划创建的表
     * @return void
     * @throws InstallException 存在同名表时抛出，避免覆盖已有站点
     */
    private function assertTablesAvailable(PDO $pdo, array $tables): void
    {
        $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $conflicts = array_values(array_intersect($tables, $existing));
        if ($conflicts !== []) {
            throw new InstallException('目标数据库已存在同名数据表：' . implode('、', $conflicts));
        }
    }

    /**
     * @param PDO $pdo 数据库连接
     * @param string $prefix 安全表前缀
     * @return void
     * @throws InstallException SQL 文件缺失或为空时抛出
     */
    private function importSchema(PDO $pdo, string $prefix): void
    {
        $sqlPath = $this->app->getRootPath() . 'database' . DIRECTORY_SEPARATOR . 'install.sql';
        $contents = @file_get_contents($sqlPath);
        if ($contents === false || trim($contents) === '') {
            throw new InstallException('database/install.sql 缺失或为空。');
        }

        $contents = str_replace('__PREFIX__', $prefix, $contents);
        foreach ($this->sqlParser->parse($contents) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * @param PDO $pdo 数据库连接
     * @param string $prefix 表前缀
     * @return void
     */
    private function seedConfiguration(PDO $pdo, string $prefix): void
    {
        $statement = $pdo->prepare(sprintf(
            'INSERT INTO `%ssite_config` '
            . '(`id`, `site_name`, `site_slogan`, `version`, `version_label`, `download_mode`, '
            . '`stats_users`, `copyright`, `hero_title`, `hero_desc`, `cta_text`, `theme_color`, `trusted_proxy_ips`, `created_at`, `updated_at`) '
            . 'VALUES (1, :site_name, :site_slogan, :version, :version_label, :download_mode, '
            . ':stats_users, :copyright, :hero_title, :hero_desc, :cta_text, :theme_color, :trusted_proxy_ips, NOW(), NOW())',
            $prefix
        ));
        $statement->execute([
            'site_name' => 'NOVA',
            'site_slogan' => '一站式效率工具，智能同步跨端协作。',
            'version' => 'v1.0.0',
            'version_label' => '最新稳定版',
            'download_mode' => 'local',
            'stats_users' => '860K+',
            'copyright' => '© ' . date('Y') . ' NOVA',
            'hero_title' => 'NOVA',
            'hero_desc' => '一站式效率工具，智能同步跨端协作。点击下载，即刻上手。',
            'cta_text' => '立即下载',
            'theme_color' => ThemeColorService::DEFAULT_COLOR,
            'trusted_proxy_ips' => '',
        ]);
    }

    /**
     * @param PDO $pdo 数据库连接
     * @param string $prefix 表前缀
     * @return void
     */
    private function seedDemoContent(PDO $pdo, string $prefix): void
    {
        $navStatement = $pdo->prepare(sprintf(
            'INSERT INTO `%snav_item` (`title`, `url`, `target`, `sort`, `status`, `created_at`, `updated_at`) '
            . 'VALUES (:title, :url, :target, :sort, 1, NOW(), NOW())',
            $prefix
        ));
        foreach ([
            ['title' => '功能', 'url' => '#features', 'target' => '_self', 'sort' => 10],
            ['title' => '支持', 'url' => '#footer', 'target' => '_self', 'sort' => 20],
        ] as $navItem) {
            $navStatement->execute($navItem);
        }

        $previewStatement = $pdo->prepare(sprintf(
            'INSERT INTO `%spreview_page` (`title`, `image`, `sort`, `status`, `created_at`, `updated_at`) '
            . 'VALUES (:title, :image, 10, 1, NOW(), NOW())',
            $prefix
        ));
        $previewStatement->execute([
            'title' => '今日概览',
            'image' => '/static/index/app-preview.png',
        ]);

        $featureStatement = $pdo->prepare(sprintf(
            'INSERT INTO `%sfeature_item` (`title`, `description`, `icon`, `link_url`, `sort`, `status`, `created_at`, `updated_at`) '
            . 'VALUES (:title, :description, :icon, NULL, :sort, 1, NOW(), NOW())',
            $prefix
        ));
        $features = [
            ['极速同步', '项目内容在设备间快速同步，随时接续工作。', 'bi bi-lightning-charge'],
            ['隐私优先', '敏感数据按清晰边界处理，减少不必要暴露。', 'bi bi-shield-lock'],
            ['智能工作流', '把重复步骤组织成稳定、可复用的流程。', 'bi bi-diagram-3'],
            ['团队协作', '共享进度与上下文，让协作保持一致。', 'bi bi-people'],
            ['跨端连接', '手机与桌面协同工作，状态自然衔接。', 'bi bi-phone'],
            ['离线可用', '弱网环境仍可访问关键内容与任务。', 'bi bi-cloud-slash'],
            ['快速检索', '按关键词迅速定位项目、文档和记录。', 'bi bi-search'],
            ['安全备份', '重要内容可恢复，更新与迁移更从容。', 'bi bi-archive'],
        ];
        foreach ($features as $index => $feature) {
            $featureStatement->execute([
                'title' => $feature[0],
                'description' => $feature[1],
                'icon' => $feature[2],
                'sort' => ($index + 1) * 10,
            ]);
        }
    }

    /**
     * @param PDO $pdo 数据库连接
     * @param string $prefix 表前缀
     * @param array{username:string,password:string} $administrator 管理员凭据
     * @return void
     * @throws InstallException 密码哈希失败时抛出
     */
    private function createAdministrator(PDO $pdo, string $prefix, array $administrator): void
    {
        $passwordHash = password_hash($administrator['password'], PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new InstallException('管理员密码哈希失败。');
        }

        $statement = $pdo->prepare(sprintf(
            'INSERT INTO `%sadmin_user` (`username`, `password`, `created_at`, `updated_at`) '
            . 'VALUES (:username, :password, NOW(), NOW())',
            $prefix
        ));
        $statement->execute([
            'username' => $administrator['username'],
            'password' => $passwordHash,
        ]);
    }

    /**
     * @param string $prefix 表前缀
     * @return array<int, string> 完整表名
     */
    private function tableNames(string $prefix): array
    {
        return array_map(static function (string $table) use ($prefix): string {
            return $prefix . $table;
        }, self::TABLE_NAMES);
    }

    /**
     * @param PDO $pdo 数据库连接
     * @param array<int, string> $tables 仅限本次新建的表
     * @return void
     */
    private function cleanupTables(PDO $pdo, array $tables): void
    {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (array_reverse($tables) as $table) {
                $pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $cleanupException) {
            // 清理失败不能覆盖原始异常；管理员可按原始错误处理后重试。
        }
    }

    /**
     * @param PDOException $exception PDO 异常
     * @return string 不含密码的错误摘要
     */
    private function safeDatabaseMessage(PDOException $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 240);
    }

    /**
     * @param Throwable $exception 任意异常
     * @return string 限长错误摘要
     */
    private function safeThrowableMessage(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 240);
    }
}
