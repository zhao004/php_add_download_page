<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\exception\AdminException;
use app\common\service\LogQueryService;
use think\facade\Log;
use think\Response;
use Throwable;

/**
 * 访问日志与下载日志列表。
 */
class Logs extends AdminController
{
    /**
     * @return string 访问日志页
     */
    public function visit(): string
    {
        return $this->logPage('visit');
    }

    /**
     * @return string 下载日志页
     */
    public function download(): string
    {
        return $this->logPage('download');
    }

    /**
     * 返回一条日志的详情数据，供后台详情弹窗按需加载。
     *
     * @param string $type visit/download
     * @param mixed $id 日志 ID
     * @return Response JSON 详情或错误结果
     */
    public function detail(string $type, $id): Response
    {
        $recordId = $this->positiveId($id);
        if ($recordId <= 0) {
            return $this->jsonError('日志记录 ID 无效。');
        }

        try {
            /** @var LogQueryService $service */
            $service = $this->app->make(LogQueryService::class);
            $item = $service->detail($type, $recordId);
            if ($item === null) {
                return $this->jsonError('日志记录不存在或已删除。', 404);
            }

            return $this->jsonSuccess('日志详情已加载。', [
                'type' => $type,
                'item' => $item,
            ]);
        } catch (AdminException $exception) {
            return $this->jsonError($exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('读取日志详情异常：' . $exception->getMessage());
            return $this->jsonError('日志详情暂时无法加载，请检查服务器日志。', 500);
        }
    }

    /**
     * 批量删除访问或下载日志。
     *
     * @param string $type visit/download
     * @return Response 删除结果（JSON 或跳转）
     */
    public function batchDelete(string $type): Response
    {
        $listPath = $this->listPath($type);
        $fallback = $listPath !== '' ? $listPath : '/admin';
        try {
            $this->assertCsrf();
            /** @var LogQueryService $service */
            $service = $this->app->make(LogQueryService::class);
            $ids = $this->request->post('ids', []);
            if (!is_array($ids)) {
                $ids = [];
            }

            $deleted = $service->batchDelete($type, $ids);
            $label = $type === 'visit' ? '访问日志' : '下载日志';
            $message = sprintf('已删除 %d 条%s。', $deleted, $label);
            if ($this->wantsJson()) {
                return $this->jsonSuccess($message, [
                    'redirect' => $fallback,
                    'csrf_token' => $this->csrf->token('admin'),
                ]);
            }

            return $this->redirectWithFlash($fallback, $message);
        } catch (AdminException $exception) {
            if ($this->wantsJson()) {
                return $this->jsonError($exception->getMessage());
            }

            return $this->redirectWithFlash($fallback, $exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            Log::error('批量删除日志异常：' . $exception->getMessage());
            if ($this->wantsJson()) {
                return $this->jsonError('批量删除失败，请检查服务器日志。', 500);
            }

            return $this->redirectWithFlash($fallback, '批量删除失败，请检查服务器日志。', 'danger');
        }
    }

    /**
     * @param string $type visit/download
     * @return string 日志页面
     */
    private function logPage(string $type): string
    {
        /** @var LogQueryService $service */
        $service = $this->app->make(LogQueryService::class);
        $error = '';
        $query = $this->request->get();
        $page = $this->positiveId($query['page'] ?? 1);

        try {
            $listing = $service->listing($type, $query, $page > 0 ? $page : 1);
        } catch (AdminException $exception) {
            $error = $exception->getMessage();
            $listing = $service->listing($type, [], 1);
        }

        $rows = [];
        foreach ($listing['items'] as $item) {
            $locationParts = [];
            foreach (['country', 'region', 'city', 'isp'] as $field) {
                $value = trim((string) ($item[$field] ?? ''));
                if ($value !== '' && $value !== '未知' && !in_array($value, $locationParts, true)) {
                    $locationParts[] = $value;
                }
            }
            $item['_location'] = $locationParts === [] ? '未知' : implode(' · ', $locationParts);
            $rows[] = $item;
        }

        $baseFilters = array_filter($listing['filters'], static function (string $value): bool {
            return $value !== '';
        });
        $previousQuery = http_build_query(array_merge($baseFilters, ['page' => $listing['previousPage']]));
        $nextQuery = http_build_query(array_merge($baseFilters, ['page' => $listing['nextPage']]));

        return $this->render('logs/' . $type, [
            'pageTitle' => $type === 'visit' ? '访问日志' : '下载日志',
            'pageDescription' => $type === 'visit'
                ? '查看前台访问来源、设备与 IP 归属地'
                : '查看下载入口命中、模式和处理结果',
            'activeNav' => $type === 'visit' ? 'visit_logs' : 'download_logs',
            'logType' => $type,
            'filters' => $listing['filters'],
            'items' => $rows,
            'total' => $listing['total'],
            'page' => $listing['page'],
            'totalPages' => $listing['totalPages'],
            'previousUrl' => '?' . $previousQuery,
            'nextUrl' => '?' . $nextQuery,
            'error' => $error,
        ]);
    }

    /**
     * @param string $type visit/download
     * @return string 列表路径；类型非法时返回空字符串
     */
    private function listPath(string $type): string
    {
        return in_array($type, ['visit', 'download'], true)
            ? '/admin/logs/' . $type
            : '';
    }
}
