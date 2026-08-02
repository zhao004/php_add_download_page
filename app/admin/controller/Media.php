<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\exception\AdminException;
use app\common\service\ImageUploadService;
use think\facade\Log;
use think\Response;
use Throwable;

/**
 * 后台媒体上传接口。
 */
class Media extends AdminController
{
    /**
     * @return Response 图片上传 JSON 响应
     */
    public function image(): Response
    {
        try {
            $this->assertCsrf();
            /** @var ImageUploadService $service */
            $service = $this->app->make(ImageUploadService::class);
            $path = $service->store(
                $this->request->file('image'),
                trim((string) $this->request->post('category', ''))
            );

            return json(['success' => true, 'path' => $path]);
        } catch (AdminException $exception) {
            return json(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Log::error('上传图片异常：' . $exception->getMessage());
            return json(['success' => false, 'message' => '图片上传失败，请检查服务器日志。'], 500);
        }
    }
}
