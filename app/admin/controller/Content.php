<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\exception\AdminException;
use app\common\service\ContentResourceService;
use think\facade\Log;
use think\Response;
use Throwable;

/**
 * 五类可排序内容资源的通用管理控制器。
 */
class Content extends AdminController
{
    /**
     * @param string $resource 资源标识
     * @return Response|string 列表页
     */
    public function index(string $resource)
    {
        try {
            $page = $this->positiveId($this->request->get('page', 1));
            $listing = $this->service()->listing($resource, $page > 0 ? $page : 1);
            $items = [];
            foreach ($listing['items'] as $item) {
                $item['_display'] = [];
                foreach ($listing['definition']['columns'] as $column) {
                    $item['_display'][] = [
                        'label' => (string) $column['label'],
                        'value' => (string) ($item[$column['key']] ?? ''),
                    ];
                }
                $firstDisplayValue = (string) ($item['_display'][0]['value'] ?? '');
                $item['_title'] = $firstDisplayValue !== ''
                    ? $firstDisplayValue
                    : $listing['definition']['singular'];
                $items[] = $item;
            }

            return $this->render('content/index', [
                'pageTitle' => $listing['definition']['label'],
                'pageDescription' => '排序、启停和维护前台展示内容',
                'activeNav' => 'content_' . $resource,
                'resource' => $resource,
                'definition' => $listing['definition'],
                'items' => $items,
                'page' => $listing['page'],
                'totalPages' => $listing['totalPages'],
                'total' => $listing['total'],
                'previousPage' => max(1, $listing['page'] - 1),
                'nextPage' => min($listing['totalPages'], $listing['page'] + 1),
            ]);
        } catch (AdminException $exception) {
            return $this->redirectWithFlash('/admin', $exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            Log::error('读取内容资源列表异常：' . $exception->getMessage());
            return $this->redirectWithFlash(
                $this->fallbackPath($resource),
                '内容列表暂时无法加载，请检查服务器日志。',
                'danger'
            );
        }
    }

    /**
     * @param string $resource 资源标识
     * @return Response|string 新建页
     */
    public function create(string $resource)
    {
        return $this->handleForm($resource, 0);
    }

    /**
     * @param string $resource 资源标识
     * @param int $id 记录 ID
     * @return Response|string 编辑页
     */
    public function edit(string $resource, int $id)
    {
        $recordId = $this->positiveId($id);
        if ($recordId <= 0) {
            if ($this->isModalRequest()) {
                return $this->modalErrorResponse('记录 ID 无效。', 422);
            }

            return $this->redirectWithFlash($this->fallbackPath($resource), '记录 ID 无效。', 'danger');
        }

        return $this->handleForm($resource, $recordId);
    }

    /**
     * @param string $resource 资源标识
     * @param int $id 记录 ID
     * @return Response 删除结果（JSON 或跳转）
     */
    public function delete(string $resource, int $id): Response
    {
        try {
            $this->assertCsrf();
            $definition = $this->service()->definition($resource);
            $this->service()->delete($resource, $this->positiveId($id));
            $message = $definition['singular'] . '已删除。';
            if ($this->wantsJson()) {
                return $this->jsonSuccess($message, [
                    'redirect' => $this->indexPath($resource),
                    'csrf_token' => $this->csrf->token('admin'),
                ]);
            }

            return $this->redirectWithFlash($this->indexPath($resource), $message);
        } catch (AdminException $exception) {
            if ($this->wantsJson()) {
                return $this->jsonError($exception->getMessage());
            }

            return $this->redirectWithFlash($this->fallbackPath($resource), $exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            Log::error('删除内容资源异常：' . $exception->getMessage());
            if ($this->wantsJson()) {
                return $this->jsonError('删除失败，请检查服务器日志。', 500);
            }

            return $this->redirectWithFlash($this->fallbackPath($resource), '删除失败，请检查服务器日志。', 'danger');
        }
    }

    /**
     * 批量删除当前资源下的多条记录。
     *
     * @param string $resource 资源标识
     * @return Response 删除结果（JSON 或跳转）
     */
    public function batchDelete(string $resource): Response
    {
        try {
            $this->assertCsrf();
            $definition = $this->service()->definition($resource);
            $ids = $this->request->post('ids', []);
            if (!is_array($ids)) {
                $ids = [];
            }

            $deleted = $this->service()->batchDelete($resource, $ids);
            $message = sprintf('已删除 %d 条%s。', $deleted, $definition['singular']);
            if ($this->wantsJson()) {
                return $this->jsonSuccess($message, [
                    'redirect' => $this->indexPath($resource),
                    'csrf_token' => $this->csrf->token('admin'),
                ]);
            }

            return $this->redirectWithFlash($this->indexPath($resource), $message);
        } catch (AdminException $exception) {
            if ($this->wantsJson()) {
                return $this->jsonError($exception->getMessage());
            }

            return $this->redirectWithFlash($this->fallbackPath($resource), $exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            Log::error('批量删除内容资源异常：' . $exception->getMessage());
            if ($this->wantsJson()) {
                return $this->jsonError('批量删除失败，请检查服务器日志。', 500);
            }

            return $this->redirectWithFlash(
                $this->fallbackPath($resource),
                '批量删除失败，请检查服务器日志。',
                'danger'
            );
        }
    }

    /**
     * @param string $resource 资源标识
     * @param int $id 记录 ID
     * @return Response 状态切换结果（JSON 或跳转）
     */
    public function toggle(string $resource, int $id): Response
    {
        try {
            $this->assertCsrf();
            $definition = $this->service()->definition($resource);
            $status = $this->service()->toggle(
                $resource,
                $this->positiveId($id),
                $this->requestedStatus()
            );
            $message = $definition['singular'] . ($status === 1 ? '已启用。' : '已停用。');
            if ($this->wantsJson()) {
                return $this->jsonSuccess($message, [
                    'status' => $status,
                    'redirect' => $this->indexPath($resource),
                    'csrf_token' => $this->csrf->token('admin'),
                ]);
            }

            return $this->redirectWithFlash($this->indexPath($resource), $message);
        } catch (AdminException $exception) {
            if ($this->wantsJson()) {
                return $this->jsonError($exception->getMessage());
            }

            return $this->redirectWithFlash($this->fallbackPath($resource), $exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            Log::error('切换内容状态异常：' . $exception->getMessage());
            if ($this->wantsJson()) {
                return $this->jsonError('状态更新失败，请检查服务器日志。', 500);
            }

            return $this->redirectWithFlash($this->fallbackPath($resource), '状态更新失败，请检查服务器日志。', 'danger');
        }
    }

    /**
     * 读取状态开关提交的目标值；缺省时兼容旧版反转状态请求。
     *
     * @return int|null
     * @throws AdminException 状态值不是 0 或 1 时抛出
     */
    private function requestedStatus(): ?int
    {
        $input = $this->request->post();
        if (!is_array($input) || !array_key_exists('status', $input)) {
            return null;
        }

        $status = $input['status'];
        if ($status === 0 || $status === '0') {
            return 0;
        }
        if ($status === 1 || $status === '1') {
            return 1;
        }

        throw new AdminException('状态值必须为 0 或 1。');
    }

    /**
     * @param string $resource 资源标识
     * @param int $id 记录 ID，0 表示新建
     * @return Response|string 表单页或保存后的跳转
     */
    private function handleForm(string $resource, int $id)
    {
        $isModalRequest = $this->isModalRequest();
        try {
            $definition = $this->service()->definition($resource);
            $values = $this->service()->formData($resource, $id);
        } catch (AdminException $exception) {
            if ($isModalRequest) {
                return $this->modalErrorResponse($exception->getMessage(), 422);
            }

            return $this->redirectWithFlash($this->fallbackPath($resource), $exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            Log::error('读取内容资源表单异常：' . $exception->getMessage());
            $message = '内容表单暂时无法加载，请检查服务器日志。';
            if ($isModalRequest) {
                return $this->modalErrorResponse($message, 500);
            }

            return $this->redirectWithFlash($this->fallbackPath($resource), $message, 'danger');
        }

        $error = '';
        $statusCode = 422;
        if ($this->request->isPost()) {
            try {
                $this->assertCsrf();
                $this->service()->save($resource, $this->request->post(), $id);
                $message = $definition['singular'] . ($id > 0 ? '已更新。' : '已创建。');
                if ($isModalRequest || $this->wantsJson()) {
                    return $this->jsonSuccess($message, [
                        'redirect' => $this->indexPath($resource),
                        'csrf_token' => $this->csrf->token('admin'),
                    ]);
                }

                return $this->redirectWithFlash($this->indexPath($resource), $message);
            } catch (AdminException $exception) {
                $error = $exception->getMessage();
                $values = array_merge($values, $this->request->post());
                $values['status'] = filter_var(
                    $this->request->post('status', false),
                    FILTER_VALIDATE_BOOLEAN
                ) ? 1 : 0;
            } catch (Throwable $exception) {
                Log::error('保存内容资源异常：' . $exception->getMessage());
                $error = '保存失败，请检查服务器日志。';
                $statusCode = 500;
            }
        }

        $formFields = [];
        foreach ($definition['fields'] as $field) {
            $field['value'] = (string) ($values[$field['key']] ?? '');
            $formFields[] = $field;
        }

        $viewData = [
            'pageTitle' => ($id > 0 ? '编辑' : '新建') . $definition['singular'],
            'pageDescription' => $definition['label'],
            'activeNav' => 'content_' . $resource,
            'resource' => $resource,
            'definition' => $definition,
            'formFields' => $formFields,
            'values' => $values,
            'recordId' => $id,
            'error' => $error,
            'actionUrl' => $id > 0
                ? sprintf('/admin/content/%s/edit/%d', $resource, $id)
                : sprintf('/admin/content/%s/create', $resource),
        ];

        if ($isModalRequest) {
            $fragment = $this->render('content/form_modal', $viewData);
            if ($error !== '') {
                return json([
                    'success' => false,
                    'message' => $error,
                    'html' => $fragment,
                ], $statusCode);
            }

            return $fragment;
        }

        return $this->render('content/form', $viewData);
    }

    /**
     * 判断请求是否来自内容管理弹窗。
     *
     * 仅接受携带 modal 参数且显式声明为异步请求的访问，
     * 这样直接打开原有 URL 时仍能得到完整页面作为无脚本回退。
     *
     * @return bool 是否返回弹窗片段或 JSON
     */
    private function isModalRequest(): bool
    {
        $accept = strtolower((string) $this->request->header('accept', ''));

        return (string) $this->request->get('modal', '') === '1'
            && ($this->request->isAjax() || strpos($accept, 'application/json') !== false);
    }

    /**
     * 构造弹窗不可恢复错误的 JSON 响应，避免前端接收完整后台页面。
     *
     * @param string $message 面向管理员的安全错误信息
     * @param int $statusCode HTTP 状态码
     * @return Response JSON 错误响应
     */
    private function modalErrorResponse(string $message, int $statusCode): Response
    {
        return json(['success' => false, 'message' => $message], $statusCode);
    }

    /**
     * @return ContentResourceService 内容资源服务
     */
    private function service(): ContentResourceService
    {
        return $this->app->make(ContentResourceService::class);
    }

    /**
     * @param string $resource 资源标识
     * @return string 资源列表路径
     */
    private function indexPath(string $resource): string
    {
        return '/admin/content/' . rawurlencode($resource);
    }

    /**
     * @param string $resource 资源标识
     * @return string 异常时的安全回退路径
     */
    private function fallbackPath(string $resource): string
    {
        return preg_match('/^[a-z]+$/', $resource) ? $this->indexPath($resource) : '/admin';
    }
}
