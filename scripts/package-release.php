<?php

declare(strict_types=1);

/**
 * 生成可直接解压部署的 NOVA 应用下载源码包。
 */
final class ReleasePackageBuilder
{
    private const ARCHIVE_PREFIX = 'nova-app-download-';

    /** @var string[] */
    private const PACKAGE_DIRECTORIES = [
        'app',
        'config',
        'data',
        'database',
        'deploy',
        'extend',
        'public',
        'route',
        'runtime',
        'tests',
        'vendor',
        'view',
    ];

    /** @var string[] */
    private const ROOT_FILES = [
        '.env.example',
        '.gitignore',
        'LICENSE.txt',
        'PACKAGING.md',
        'README.md',
        'SECURITY.md',
        'composer.json',
        'composer.lock',
        'think',
    ];

    /** @var string[] */
    private const REQUIRED_ARCHIVE_FILES = [
        '.env.example',
        'README.md',
        'PACKAGING.md',
        'SECURITY.md',
        'composer.json',
        'composer.lock',
        'think',
        'vendor/autoload.php',
        'database/install.sql',
        'data/ip2region.xdb',
        'runtime/.gitkeep',
        'public/.htaccess',
        'public/uploads/.gitkeep',
        'public/uploads/.htaccess',
        'public/static/admin/login.js',
        'public/static/index/download-other.css',
        'public/static/index/download-other.js',
        'public/static/vendor/photoswipe/photoswipe.css',
        'public/static/vendor/photoswipe/photoswipe.esm.min.js',
        'public/static/vendor/photoswipe/photoswipe-lightbox.esm.min.js',
        'public/static/vendor/photoswipe/LICENSE',
        'app/index/view/index/download_other.html',
        'app/common/service/CaptchaService.php',
        'deploy/nginx.conf.example',
        'deploy/apache-vhost.conf.example',
    ];

    /** @var string[] */
    private const FORBIDDEN_ROOT_FILES = [
        '.env',
        '.travis.yml',
        'install.lock',
    ];

    /** @var string[] */
    private const FORBIDDEN_PATH_SEGMENTS = [
        '.git',
        '.idea',
        '.playwright-mcp',
        '.vscode',
        '_vendor_assets',
    ];

    /** @var string */
    private $rootPath;

    /**
     * @param string $rootPath 项目根目录
     */
    private function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
    }

    /**
     * @param string[] $arguments 命令行参数
     * @return int 进程退出码
     * @throws \RuntimeException
     */
    public static function run(array $arguments): int
    {
        try {
            if (!class_exists(ZipArchive::class)) {
                throw new RuntimeException('当前 PHP 未启用 ZipArchive 扩展，无法生成 ZIP 发布包。');
            }

            $rootPath = realpath(dirname(__DIR__));
            if ($rootPath === false || !is_dir($rootPath)) {
                throw new RuntimeException('无法定位项目根目录。');
            }

            $builder = new self($rootPath);
            $version = $builder->parseVersion($arguments);
            $archivePath = $builder->build($version);

            fwrite(
                STDOUT,
                sprintf("已生成发布包：%s（%d 字节）。\n", $builder->relativeToRoot($archivePath), filesize($archivePath))
            );

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '发布包生成失败：' . $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * @param string[] $arguments 命令行参数
     * @return string 合法的版本标签
     * @throws \InvalidArgumentException
     */
    private function parseVersion(array $arguments): string
    {
        $parameters = array_values(array_slice($arguments, 1));
        $version = '';

        if (count($parameters) === 1 && strpos($parameters[0], '--version=') === 0) {
            $version = substr($parameters[0], strlen('--version='));
        } elseif (count($parameters) === 2 && $parameters[0] === '--version') {
            $version = $parameters[1];
        }

        if (!is_string($version) || !preg_match(
            '/^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/',
            $version
        )) {
            throw new InvalidArgumentException(
                '版本必须是以 v 开头的语义化标签，例如 v1.2.3 或 v1.2.3-rc.1。'
            );
        }

        return $version;
    }

    /**
     * @param string $version 合法版本标签
     * @return string 生成的 ZIP 绝对路径
     * @throws \RuntimeException
     */
    private function build(string $version): string
    {
        $this->assertSourceFiles();
        $files = $this->collectPackageFiles();
        $archivePath = $this->archivePath($version);
        $temporaryPath = $archivePath . '.tmp-' . bin2hex(random_bytes(8));

        try {
            $this->createArchive($temporaryPath, $files);
            $this->assertArchive($temporaryPath);
            $this->replaceArchive($temporaryPath, $archivePath);
        } catch (Throwable $exception) {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            throw $exception;
        }

        return $archivePath;
    }

    /**
     * @return void
     * @throws \RuntimeException
     */
    private function assertSourceFiles(): void
    {
        foreach (self::PACKAGE_DIRECTORIES as $directory) {
            $absolutePath = $this->rootPath . DIRECTORY_SEPARATOR . $directory;
            if (is_link($absolutePath) || !is_dir($absolutePath)) {
                throw new RuntimeException('发布包缺少目录或目录不是安全实体：' . $directory);
            }

            $this->assertPathWithinRoot((string) realpath($absolutePath));
        }

        foreach (array_merge(self::ROOT_FILES, self::REQUIRED_ARCHIVE_FILES) as $relativePath) {
            $this->assertRegularFile($relativePath);
        }
    }

    /**
     * @param string $relativePath 相对项目根目录的文件路径
     * @return void
     * @throws \RuntimeException
     */
    private function assertRegularFile(string $relativePath): void
    {
        $absolutePath = $this->rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_link($absolutePath) || !is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException('发布包缺少必需文件或文件不可读：' . $relativePath);
        }

        $realPath = realpath($absolutePath);
        if ($realPath === false) {
            throw new RuntimeException('无法解析必需文件：' . $relativePath);
        }

        $this->assertPathWithinRoot($realPath);
    }

    /**
     * @return array<string, string> ZIP 内路径与源文件绝对路径的映射
     * @throws \RuntimeException
     */
    private function collectPackageFiles(): array
    {
        $files = [];

        foreach (self::ROOT_FILES as $relativePath) {
            $absolutePath = $this->rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $files[$relativePath] = (string) realpath($absolutePath);
        }

        foreach (self::PACKAGE_DIRECTORIES as $directory) {
            $directoryPath = $this->rootPath . DIRECTORY_SEPARATOR . $directory;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directoryPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $relativePath = $this->relativeToRoot($file->getPathname());
                if (!$this->shouldInclude($relativePath)) {
                    continue;
                }

                if ($file->isLink()) {
                    throw new RuntimeException('发布包不允许包含符号链接：' . $relativePath);
                }
                if (!$file->isFile() || !$file->isReadable()) {
                    throw new RuntimeException('发布包包含不可读的非普通文件：' . $relativePath);
                }

                $realPath = $file->getRealPath();
                if ($realPath === false) {
                    throw new RuntimeException('无法解析发布文件：' . $relativePath);
                }

                $this->assertPathWithinRoot($realPath);
                if (isset($files[$relativePath]) && $files[$relativePath] !== $realPath) {
                    throw new RuntimeException('发布包出现重复路径：' . $relativePath);
                }

                $files[$relativePath] = $realPath;
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param string $relativePath 相对项目根目录的文件路径
     * @return bool 是否纳入发布包
     */
    private function shouldInclude(string $relativePath): bool
    {
        if (strpos($relativePath, 'runtime/') === 0) {
            return $relativePath === 'runtime/.gitkeep';
        }

        if (strpos($relativePath, 'public/uploads/') === 0) {
            return in_array($relativePath, [
                'public/uploads/.gitkeep',
                'public/uploads/.htaccess',
            ], true);
        }

        return true;
    }

    /**
     * @param string $temporaryPath 临时 ZIP 路径
     * @param array<string, string> $files ZIP 内路径与源文件绝对路径的映射
     * @return void
     * @throws \RuntimeException
     */
    private function createArchive(string $temporaryPath, array $files): void
    {
        $archive = new ZipArchive();
        $result = $archive->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new RuntimeException('无法创建临时 ZIP 文件，错误码：' . (string) $result);
        }

        try {
            foreach ($files as $relativePath => $absolutePath) {
                if (!$archive->addFile($absolutePath, $relativePath)) {
                    throw new RuntimeException('无法写入 ZIP 文件：' . $relativePath);
                }
            }
        } catch (Throwable $exception) {
            $archive->close();
            throw $exception;
        }

        if (!$archive->close()) {
            throw new RuntimeException('无法完成 ZIP 文件写入。');
        }
    }

    /**
     * @param string $archivePath ZIP 绝对路径
     * @return void
     * @throws \RuntimeException
     */
    private function assertArchive(string $archivePath): void
    {
        $archive = new ZipArchive();
        $result = $archive->open($archivePath);
        if ($result !== true) {
            throw new RuntimeException('无法重新打开生成的 ZIP 文件，错误码：' . (string) $result);
        }

        try {
            $entries = [];
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $name = $archive->getNameIndex($index);
                if (!is_string($name)) {
                    throw new RuntimeException('无法读取 ZIP 文件条目。');
                }

                $normalizedName = str_replace('\\', '/', $name);
                $this->assertArchiveEntryAllowed($normalizedName);
                if (isset($entries[$normalizedName])) {
                    throw new RuntimeException('ZIP 包含重复文件路径：' . $normalizedName);
                }

                $entryMetadata = $archive->statIndex($index);
                if (!is_array($entryMetadata)) {
                    throw new RuntimeException('无法读取 ZIP 文件元数据：' . $normalizedName);
                }

                $entries[$normalizedName] = $entryMetadata;
            }

            foreach (self::REQUIRED_ARCHIVE_FILES as $relativePath) {
                if (!isset($entries[$relativePath])) {
                    throw new RuntimeException('生成的 ZIP 缺少必需文件：' . $relativePath);
                }

                $size = $entries[$relativePath]['size'] ?? null;
                if (!in_array($relativePath, ['runtime/.gitkeep', 'public/uploads/.gitkeep'], true)
                    && (!is_int($size) || $size < 1)) {
                    throw new RuntimeException('生成的 ZIP 包含空的必需文件：' . $relativePath);
                }
            }
        } finally {
            $archive->close();
        }
    }

    /**
     * @param string $path ZIP 内文件路径
     * @return void
     * @throws \RuntimeException
     */
    private function assertArchiveEntryAllowed(string $path): void
    {
        if ($path === '' || $path[0] === '/' || strpos($path, "\0") !== false) {
            throw new RuntimeException('ZIP 包含非法路径。');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('ZIP 包含不安全路径：' . $path);
            }
            if (in_array($segment, self::FORBIDDEN_PATH_SEGMENTS, true)) {
                throw new RuntimeException('ZIP 包含禁止目录：' . $path);
            }
        }

        $topLevel = $segments[0];
        if (in_array($topLevel, self::FORBIDDEN_ROOT_FILES, true) || basename($path) === '.env') {
            throw new RuntimeException('ZIP 包含敏感文件：' . $path);
        }

        if ($topLevel === 'docs') {
            throw new RuntimeException('ZIP 包含未授权的根目录文档：' . $path);
        }

        if (in_array($topLevel, self::ROOT_FILES, true)) {
            if (count($segments) !== 1) {
                throw new RuntimeException('ZIP 根文件路径不合法：' . $path);
            }

            return;
        }

        if (!in_array($topLevel, self::PACKAGE_DIRECTORIES, true)) {
            throw new RuntimeException('ZIP 包含未授权的根路径：' . $path);
        }

        if (strpos($path, 'runtime/') === 0 && $path !== 'runtime/.gitkeep') {
            throw new RuntimeException('ZIP 包含运行时数据：' . $path);
        }

        if (strpos($path, 'public/uploads/') === 0 && !in_array(
            $path,
            ['public/uploads/.gitkeep', 'public/uploads/.htaccess'],
            true
        )) {
            throw new RuntimeException('ZIP 包含上传数据：' . $path);
        }
    }

    /**
     * @param string $temporaryPath 已校验的临时 ZIP 路径
     * @param string $archivePath 最终 ZIP 路径
     * @return void
     * @throws \RuntimeException
     */
    private function replaceArchive(string $temporaryPath, string $archivePath): void
    {
        $backupPath = '';
        if (is_file($archivePath)) {
            $backupPath = $archivePath . '.backup-' . bin2hex(random_bytes(8));
            if (!rename($archivePath, $backupPath)) {
                throw new RuntimeException('无法备份已有的发布包：' . $this->relativeToRoot($archivePath));
            }
        }

        if (!rename($temporaryPath, $archivePath)) {
            if ($backupPath !== '' && is_file($backupPath)) {
                @rename($backupPath, $archivePath);
            }
            throw new RuntimeException('无法移动已校验的发布包。');
        }

        if ($backupPath !== '' && is_file($backupPath) && !unlink($backupPath)) {
            throw new RuntimeException('无法清理旧发布包备份：' . $this->relativeToRoot($backupPath));
        }
    }

    /**
     * @param string $version 合法版本标签
     * @return string ZIP 绝对路径
     * @throws \RuntimeException
     */
    private function archivePath(string $version): string
    {
        $directory = $this->rootPath . DIRECTORY_SEPARATOR . '.release';
        if (is_link($directory)) {
            throw new RuntimeException('.release 目录不能是符号链接。');
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建 .release 目录。');
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false) {
            throw new RuntimeException('无法解析 .release 目录。');
        }

        $this->assertPathWithinRoot($realDirectory);

        return $realDirectory . DIRECTORY_SEPARATOR . self::ARCHIVE_PREFIX . $version . '.zip';
    }

    /**
     * @param string $absolutePath 绝对路径
     * @return string 相对项目根目录且使用正斜杠的路径
     * @throws \RuntimeException
     */
    private function relativeToRoot(string $absolutePath): string
    {
        $rootPrefix = rtrim($this->rootPath, '/\\') . DIRECTORY_SEPARATOR;
        if (strpos($absolutePath, $rootPrefix) !== 0) {
            throw new RuntimeException('路径位于项目根目录之外。');
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($rootPrefix)));
    }

    /**
     * @param string $path 已解析的绝对路径
     * @return void
     * @throws \RuntimeException
     */
    private function assertPathWithinRoot(string $path): void
    {
        $rootPrefix = rtrim($this->rootPath, '/\\') . DIRECTORY_SEPARATOR;
        if ($path !== $this->rootPath && strpos($path, $rootPrefix) !== 0) {
            throw new RuntimeException('发现超出项目根目录的文件。');
        }
    }
}

exit(ReleasePackageBuilder::run($argv));
