<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\AdminException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use think\App;
use think\file\UploadedFile;
use Throwable;

/**
 * 校验并保存后台图片，SVG 会通过严格白名单重新构造。
 */
class ImageUploadService
{
    private const MAX_WIDTH = 10000;
    private const MAX_HEIGHT = 10000;
    private const MAX_PIXELS = 40000000;
    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
    private const ICO_HEADER_SIZE = 6;
    private const ICO_DIRECTORY_ENTRY_SIZE = 16;
    private const MAX_ICO_IMAGES = 64;
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    private const CATEGORIES = ['icons', 'favicons', 'previews', 'features', 'brands'];

    private const FAVICON_EXTENSIONS = ['ico', 'png', 'svg'];

    /** ICO 在不同系统和浏览器上传时可能返回的 MIME 类型。 */
    private const ICO_MIME_TYPES = [
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'image/ico',
        'application/x-ico',
        'application/vnd.microsoft.icon',
        'application/octet-stream',
    ];

    private const ICO_DIB_HEADER_SIZES = [40, 108, 124];

    private const RASTER_MIME_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /** SVG 常见 finfo 结果；部分导出文件会被识别为 text/plain。 */
    private const SVG_MIME_TYPES = [
        'image/svg+xml',
        'text/xml',
        'application/xml',
        'text/plain',
        'application/octet-stream',
    ];

    private const SVG_ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'path', 'rect', 'circle', 'ellipse',
        'line', 'polyline', 'polygon', 'text', 'tspan', 'title', 'desc',
        'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'pattern',
    ];

    private const SVG_ATTRIBUTES = [
        'id', 'viewBox', 'preserveAspectRatio', 'width', 'height', 'x', 'y', 'x1', 'y1',
        'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'transform',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
        'opacity', 'clip-path', 'mask', 'offset', 'stop-color', 'stop-opacity',
        'gradientUnits', 'gradientTransform', 'spreadMethod', 'patternUnits',
        'patternContentUnits', 'patternTransform', 'href', 'font-size', 'font-family',
        'font-weight', 'text-anchor', 'dominant-baseline', 'role', 'aria-label',
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
     * @param UploadedFile|null $file 上传图片
     * @param string $category 保存分类
     * @return string 可公开访问的站内绝对路径
     * @throws AdminException 文件无效或保存失败时抛出
     */
    public function store(?UploadedFile $file, string $category): string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new AdminException('请选择上传成功的图片文件。');
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new AdminException('图片用途无效。');
        }

        $extension = strtolower($file->getOriginalExtension());
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        if (!isset(self::RASTER_MIME_TYPES[$extension]) && $extension !== 'svg' && $extension !== 'ico') {
            throw new AdminException('图片仅允许 png、jpg、gif、webp 或 svg 格式。');
        }
        if ($category === 'favicons' && !in_array($extension, self::FAVICON_EXTENSIONS, true)) {
            throw new AdminException('网站图标仅允许 ico、png 或 svg 格式。');
        }
        if ($extension === 'ico' && $category !== 'favicons') {
            throw new AdminException('ICO 文件仅能作为网站图标上传。');
        }

        $size = (int) $file->getSize();
        $sizeLimit = $extension === 'svg'
            ? $this->uploadLimits->svgMaxBytes()
            : $this->uploadLimits->imageMaxBytes();
        if ($size <= 0 || $size > $sizeLimit) {
            $maximumMegabytes = $extension === 'svg'
                ? $this->uploadLimits->svgMaxMb()
                : $this->uploadLimits->imageMaxMb();
            throw new AdminException(sprintf(
                '%s文件大小必须在 1 字节到 %dMB 之间。',
                $extension === 'svg' ? 'SVG ' : '图片',
                $maximumMegabytes
            ));
        }

        $sanitizedSvg = null;
        if ($extension === 'svg') {
            $source = @file_get_contents($file->getPathname());
            if (!is_string($source) || $source === '') {
                throw new AdminException('无法读取 SVG 文件。');
            }
            // 设计软件导出的 SVG 常带 UTF-8 BOM，会导致 finfo 识别为 text/plain。
            $source = $this->stripUtf8Bom($source);
            $mime = strtolower((string) $file->getMime());
            if (!in_array($mime, self::SVG_MIME_TYPES, true) || !$this->looksLikeSvg($source)) {
                throw new AdminException('SVG 文件内容或 MIME 类型无效。');
            }
            $sanitizedSvg = $this->sanitizeSvg($source);
        } elseif ($extension === 'ico') {
            $this->validateIco($file);
        } else {
            $this->validateRaster($file, $extension);
        }

        $month = date('Ym');
        $directory = $this->app->getRootPath() . 'public' . DIRECTORY_SEPARATOR
            . 'uploads' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR
            . $category . DIRECTORY_SEPARATOR . $month;
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativePath = '/uploads/images/' . $category . '/' . $month . '/' . $filename;

        try {
            $this->ensureUploadDirectory($directory);
            if ($extension === 'svg') {
                $this->writeSvg($directory, $filename, (string) $sanitizedSvg);
            } else {
                $file->move($directory, $filename);
            }
        } catch (AdminException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AdminException('图片保存失败，请检查上传目录权限。', 0, $exception);
        }

        return $relativePath;
    }

    /**
     * @param UploadedFile $file 栅格图片
     * @param string $extension 已规范化扩展名
     * @return void
     * @throws AdminException 文件内容、尺寸或 MIME 不匹配时抛出
     */
    private function validateRaster(UploadedFile $file, string $extension): void
    {
        $expectedMime = self::RASTER_MIME_TYPES[$extension];
        $actualMime = strtolower($file->getMime());
        if ($actualMime !== $expectedMime) {
            throw new AdminException('图片扩展名与真实 MIME 类型不一致。');
        }

        $imageInfo = @getimagesize($file->getPathname());
        if (!is_array($imageInfo) || !isset($imageInfo[0], $imageInfo[1], $imageInfo['mime'])) {
            throw new AdminException('图片内容损坏或无法识别。');
        }
        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        if ($width <= 0 || $height <= 0 || $width > self::MAX_WIDTH || $height > self::MAX_HEIGHT
            || $width * $height > self::MAX_PIXELS) {
            throw new AdminException('图片尺寸不能超过 10000×10000，且总像素不能超过 4000 万。');
        }
        if (strtolower((string) $imageInfo['mime']) !== $expectedMime) {
            throw new AdminException('图片内容类型与扩展名不一致。');
        }
    }

    /**
     * 校验 ICO 目录及其内嵌 PNG/DIB 图像边界。
     *
     * @param UploadedFile $file ICO 图标文件
     * @return void
     * @throws AdminException ICO 格式或图像载荷不安全时抛出
     */
    private function validateIco(UploadedFile $file): void
    {
        $actualMime = strtolower((string) $file->getMime());
        if (!in_array($actualMime, self::ICO_MIME_TYPES, true)) {
            throw new AdminException('ICO 文件 MIME 类型无效。');
        }

        $contents = @file_get_contents($file->getPathname());
        if (!is_string($contents)) {
            throw new AdminException('无法读取 ICO 文件。');
        }

        $fileSize = strlen($contents);
        if ($fileSize < self::ICO_HEADER_SIZE + self::ICO_DIRECTORY_ENTRY_SIZE) {
            throw new AdminException('ICO 文件内容损坏或无法识别。');
        }

        $header = unpack('vreserved/vtype/vcount', substr($contents, 0, self::ICO_HEADER_SIZE));
        if (!is_array($header) || !isset($header['reserved'], $header['type'], $header['count'])) {
            throw new AdminException('ICO 文件头无效。');
        }

        $imageCount = (int) $header['count'];
        $directorySize = self::ICO_HEADER_SIZE + $imageCount * self::ICO_DIRECTORY_ENTRY_SIZE;
        if ((int) $header['reserved'] !== 0 || (int) $header['type'] !== 1
            || $imageCount <= 0 || $imageCount > self::MAX_ICO_IMAGES || $directorySize > $fileSize) {
            throw new AdminException('ICO 文件目录无效。');
        }

        for ($index = 0; $index < $imageCount; $index++) {
            $entryOffset = self::ICO_HEADER_SIZE + $index * self::ICO_DIRECTORY_ENTRY_SIZE;
            $entry = unpack(
                'Cwidth/Cheight/Ccolors/Creserved/vplanes/vbit_count/Vbytes/Voffset',
                substr($contents, $entryOffset, self::ICO_DIRECTORY_ENTRY_SIZE)
            );
            if (!is_array($entry) || !isset($entry['reserved'], $entry['bytes'], $entry['offset'])
                || (int) $entry['reserved'] !== 0) {
                throw new AdminException('ICO 图像目录项无效。');
            }

            $payloadSize = (int) $entry['bytes'];
            $payloadOffset = (int) $entry['offset'];
            if ($payloadSize <= 0 || $payloadSize > $fileSize || $payloadOffset < $directorySize
                || $payloadOffset > $fileSize - $payloadSize) {
                throw new AdminException('ICO 图像数据边界无效。');
            }

            $this->validateIcoPayload(substr($contents, $payloadOffset, $payloadSize));
        }
    }

    /**
     * @param string $payload ICO 内嵌图像数据
     * @return void
     * @throws AdminException 图像载荷无效时抛出
     */
    private function validateIcoPayload(string $payload): void
    {
        if (strncmp($payload, self::PNG_SIGNATURE, strlen(self::PNG_SIGNATURE)) === 0) {
            $imageInfo = @getimagesizefromstring($payload);
            if (!is_array($imageInfo) || !isset($imageInfo[0], $imageInfo[1], $imageInfo['mime'])
                || strtolower((string) $imageInfo['mime']) !== 'image/png') {
                throw new AdminException('ICO 内嵌 PNG 图像无效。');
            }
            $this->assertIcoDimensions((int) $imageInfo[0], (int) $imageInfo[1]);
            return;
        }

        if (strlen($payload) < 12) {
            throw new AdminException('ICO 内嵌图像无效。');
        }

        $dibHeader = unpack('Vsize/Vwidth/Vheight', substr($payload, 0, 12));
        if (!is_array($dibHeader) || !isset($dibHeader['size'], $dibHeader['width'], $dibHeader['height'])) {
            throw new AdminException('ICO 位图头无效。');
        }

        $headerSize = (int) $dibHeader['size'];
        if (!in_array($headerSize, self::ICO_DIB_HEADER_SIZES, true) || strlen($payload) < $headerSize) {
            throw new AdminException('ICO 位图头无效。');
        }

        $width = $this->toSigned32((int) $dibHeader['width']);
        $storedHeight = $this->toSigned32((int) $dibHeader['height']);
        if ($storedHeight === 0) {
            throw new AdminException('ICO 位图尺寸无效。');
        }

        $height = max(1, intdiv(abs($storedHeight), 2));
        $this->assertIcoDimensions($width, $height);

        $bitmapInfo = unpack('vplanes/vbit_count/Vcompression', substr($payload, 12, 8));
        if (!is_array($bitmapInfo) || !isset($bitmapInfo['planes'], $bitmapInfo['bit_count'], $bitmapInfo['compression'])
            || (int) $bitmapInfo['planes'] !== 1
            || !in_array((int) $bitmapInfo['bit_count'], [1, 4, 8, 16, 24, 32], true)
            || !in_array((int) $bitmapInfo['compression'], [0, 3], true)) {
            throw new AdminException('ICO 位图参数无效。');
        }

        $bitCount = (int) $bitmapInfo['bit_count'];
        $xorRowBytes = intdiv($width * $bitCount + 31, 32) * 4;
        $andRowBytes = intdiv($width + 31, 32) * 4;
        $minimumDataSize = $headerSize + ($xorRowBytes + $andRowBytes) * $height;
        if (strlen($payload) < $minimumDataSize) {
            throw new AdminException('ICO 位图数据不完整。');
        }
    }

    /**
     * @param int $width 图像宽度
     * @param int $height 图像高度
     * @return void
     * @throws AdminException 尺寸超出安全边界时抛出
     */
    private function assertIcoDimensions(int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0 || $width > self::MAX_WIDTH || $height > self::MAX_HEIGHT
            || $width * $height > self::MAX_PIXELS) {
            throw new AdminException('ICO 图像尺寸不能超过 10000×10000，且总像素不能超过 4000 万。');
        }
    }

    /**
     * @param int $value 无符号 32 位整数
     * @return int 有符号 32 位整数
     */
    private function toSigned32(int $value): int
    {
        return $value > 2147483647 ? $value - 4294967296 : $value;
    }

    /**
     * 通过重新构造文档保留安全 SVG 图元，未知元素与属性不会进入输出。
     *
     * @param string $source 原始 SVG
     * @return string 清理后的 SVG
     * @throws AdminException XML 无效或清理后无可见图元时抛出
     */
    private function sanitizeSvg(string $source): string
    {
        if (!class_exists(DOMDocument::class)) {
            throw new AdminException('服务器未启用 dom 扩展，无法安全处理 SVG。');
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY|<\?[^x]/i', $source)) {
            throw new AdminException('SVG 不能包含文档类型、实体或处理指令。');
        }

        $previousErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        if (!$loaded || !$document->documentElement instanceof DOMElement
            || $document->documentElement->localName !== 'svg') {
            throw new AdminException('SVG XML 结构无效。');
        }
        $namespace = (string) $document->documentElement->namespaceURI;
        if ($namespace !== '' && $namespace !== self::SVG_NAMESPACE) {
            throw new AdminException('SVG 命名空间无效。');
        }

        $cleanDocument = new DOMDocument('1.0', 'UTF-8');
        $cleanDocument->formatOutput = false;
        $root = $cleanDocument->createElementNS(self::SVG_NAMESPACE, 'svg');
        $cleanDocument->appendChild($root);
        $visibleElements = 0;
        $this->copySvgAttributes($document->documentElement, $root);
        foreach ($document->documentElement->childNodes as $child) {
            $this->copySvgNode($child, $root, $cleanDocument, $visibleElements);
        }
        if ($visibleElements === 0) {
            throw new AdminException(
                'SVG 清理后不包含可显示图形。若 Logo 为内嵌位图的 SVG，请改用 png、jpg、gif 或 webp。'
            );
        }

        $result = $cleanDocument->saveXML($cleanDocument->documentElement);
        if (!is_string($result) || strlen($result) > $this->uploadLimits->svgMaxBytes()) {
            throw new AdminException(sprintf(
                'SVG 清理结果无效或超过 %dMB。',
                $this->uploadLimits->svgMaxMb()
            ));
        }
        return $result;
    }

    /**
     * @param DOMNode $source 原节点
     * @param DOMElement $targetParent 清理文档父节点
     * @param DOMDocument $targetDocument 清理文档
     * @param int $visibleElements 可见图元计数
     * @return void
     */
    private function copySvgNode(
        DOMNode $source,
        DOMElement $targetParent,
        DOMDocument $targetDocument,
        int &$visibleElements
    ): void {
        if ($source instanceof DOMText) {
            if (in_array($targetParent->localName, ['text', 'tspan', 'title', 'desc'], true)) {
                $targetParent->appendChild($targetDocument->createTextNode($source->wholeText));
            }
            return;
        }
        if (!$source instanceof DOMElement
            || !in_array($source->localName, self::SVG_ELEMENTS, true)
            || ((string) $source->namespaceURI !== '' && (string) $source->namespaceURI !== self::SVG_NAMESPACE)) {
            return;
        }

        $target = $targetDocument->createElementNS(self::SVG_NAMESPACE, $source->localName);
        $this->copySvgAttributes($source, $target);
        $targetParent->appendChild($target);
        if (in_array($source->localName, [
            'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'use',
        ], true)) {
            $visibleElements++;
        }
        foreach ($source->childNodes as $child) {
            $this->copySvgNode($child, $target, $targetDocument, $visibleElements);
        }
    }

    /**
     * @param DOMElement $source 原元素
     * @param DOMElement $target 清理后的元素
     * @return void
     */
    private function copySvgAttributes(DOMElement $source, DOMElement $target): void
    {
        foreach ($source->attributes as $attribute) {
            $name = $attribute->localName;
            $value = trim($attribute->value);
            if (!in_array($name, self::SVG_ATTRIBUTES, true)
                || strlen($value) > 4096
                || preg_match('/[\x00-\x1F\x7F]/', $value)
                || !$this->isSafeSvgAttribute($name, $value)) {
                continue;
            }
            $target->setAttribute($name, $value);
        }
    }

    /**
     * @param string $name 属性名
     * @param string $value 属性值
     * @return bool 是否不含脚本、外链或危险 CSS
     */
    private function isSafeSvgAttribute(string $name, string $value): bool
    {
        $lower = strtolower($value);
        if (strpos($lower, 'javascript:') !== false || strpos($lower, 'data:') !== false
            || strpos($lower, 'expression(') !== false || strpos($lower, '@import') !== false) {
            return false;
        }
        if ($name === 'href') {
            return (bool) preg_match('/^#[A-Za-z_][A-Za-z0-9_.:-]{0,127}$/', $value);
        }
        if (in_array($name, ['fill', 'stroke', 'clip-path', 'mask'], true)
            && strpos($lower, 'url(') !== false) {
            return (bool) preg_match('/^url\(#[A-Za-z_][A-Za-z0-9_.:-]{0,127}\)$/', $value);
        }
        if ($name === 'id') {
            return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]{0,127}$/', $value);
        }

        return !preg_match('#(?:https?:)?//#i', $value);
    }

    /**
     * 去掉 UTF-8 BOM，避免影响 MIME 识别与 XML 解析。
     *
     * @param string $source 原始文本
     * @return string 去 BOM 后的文本
     */
    private function stripUtf8Bom(string $source): string
    {
        if (strncmp($source, "\xEF\xBB\xBF", 3) === 0) {
            return substr($source, 3);
        }

        return $source;
    }

    /**
     * 粗粒度判断内容是否像 SVG，防止 text/plain 伪装上传。
     *
     * @param string $source 已去 BOM 的文本
     * @return bool 是否包含 SVG 根标记
     */
    private function looksLikeSvg(string $source): bool
    {
        return (bool) preg_match('/<svg\b/i', $source);
    }

    /**
     * 确保分类上传目录存在且可写（icons/previews/features/brands 等）。
     *
     * @param string $directory 目标目录
     * @return void
     * @throws AdminException 创建失败时抛出
     */
    private function ensureUploadDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new AdminException('无法创建图片上传目录。');
        }
        if (!is_writable($directory)) {
            throw new AdminException('图片上传目录不可写，请检查服务器权限。');
        }
    }

    /**
     * @param string $directory 目标目录
     * @param string $filename 随机文件名
     * @param string $contents 清理后的 SVG
     * @return void
     * @throws AdminException 创建目录或原子写入失败时抛出
     */
    private function writeSvg(string $directory, string $filename, string $contents): void
    {
        $this->ensureUploadDirectory($directory);
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));
        $written = @file_put_contents($temporaryPath, $contents, LOCK_EX);
        if ($written !== strlen($contents) || !@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new AdminException('SVG 文件写入失败。');
        }
        @chmod($path, 0664);
    }
}
