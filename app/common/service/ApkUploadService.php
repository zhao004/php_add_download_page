<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\AdminException;
use think\App;
use think\facade\Db;
use think\file\UploadedFile;
use Throwable;

/**
 * 校验并替换本地 APK，所有文件操作限制在 uploads/apk 内。
 */
class ApkUploadService
{
    private const ALLOWED_MIME_TYPES = [
        'application/vnd.android.package-archive',
        'application/zip',
        'application/x-zip',
        'application/x-zip-compressed',
        'application/octet-stream',
    ];

    /** @var App */
    private $app;

    /** @var UploadLimitService */
    private $uploadLimits;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->uploadLimits = UploadLimitService::fromApplication($app);
    }

    /**
     * @param UploadedFile|null $file 上传文件
     * @return string 新 APK 的公开相对路径
     * @throws AdminException 文件无效或保存失败时抛出
     */
    public function replace(?UploadedFile $file): string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new AdminException('请选择上传成功的 APK 文件。');
        }
        if (strtolower($file->getOriginalExtension()) !== 'apk') {
            throw new AdminException('本地安装包仅允许 .apk 扩展名。');
        }

        $size = (int) $file->getSize();
        if ($size <= 0 || $size > $this->uploadLimits->apkMaxBytes()) {
            throw new AdminException(sprintf(
                'APK 文件大小必须在 1 字节到 %dMB 之间。',
                $this->uploadLimits->apkMaxMb()
            ));
        }

        $mimeType = strtolower($file->getMime());
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new AdminException('APK 文件 MIME 类型无效。');
        }

        $handle = @fopen($file->getPathname(), 'rb');
        $signature = $handle === false ? false : fread($handle, 4);
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($signature !== "PK\x03\x04") {
            throw new AdminException('APK 文件结构无效，未检测到 ZIP 文件签名。');
        }

        $uploadDirectory = $this->app->getRootPath() . 'public' . DIRECTORY_SEPARATOR
            . 'uploads' . DIRECTORY_SEPARATOR . 'apk';
        $filename = bin2hex(random_bytes(16)) . '.apk';
        $movedFile = null;

        try {
            $movedFile = $file->move($uploadDirectory, $filename);
            $relativePath = '/uploads/apk/' . $filename;
            $config = Db::name('site_config')->where('id', 1)->find();
            if (!is_array($config)) {
                throw new AdminException('站点配置不存在，无法保存 APK 路径。');
            }

            Db::name('site_config')->where('id', 1)->update([
                'local_apk_path' => $relativePath,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $oldPath = (string) ($config['local_apk_path'] ?? '');
            if ($oldPath !== '' && $oldPath !== $relativePath) {
                $this->removeOldApk($oldPath, $uploadDirectory);
            }

            return $relativePath;
        } catch (Throwable $exception) {
            if ($movedFile !== null && is_file((string) $movedFile)) {
                @unlink((string) $movedFile);
            }
            if ($exception instanceof AdminException) {
                throw $exception;
            }
            throw new AdminException('APK 保存失败：' . mb_substr($exception->getMessage(), 0, 160), 0, $exception);
        }
    }

    /**
     * @param string $relativePath 数据库中的旧相对路径
     * @param string $uploadDirectory 允许删除的目录
     * @return void
     */
    private function removeOldApk(string $relativePath, string $uploadDirectory): void
    {
        if (!preg_match('#^/uploads/apk/[a-f0-9]{32}\.apk$#', $relativePath)) {
            return;
        }

        $base = realpath($uploadDirectory);
        $candidate = realpath($this->app->getRootPath() . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($base === false || $candidate === false) {
            return;
        }
        if (strpos($candidate, $base . DIRECTORY_SEPARATOR) === 0 && is_file($candidate)) {
            @unlink($candidate);
        }
    }
}
