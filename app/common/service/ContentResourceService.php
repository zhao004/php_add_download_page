<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\AdminException;
use think\facade\Db;

/**
 * 管理导航、预览、功能、友链与信任品牌的共享 CRUD。
 */
class ContentResourceService
{
    private const PER_PAGE = 15;

    /** 单次批量删除的最大条数，防止误操作与超大请求。 */
    private const BATCH_DELETE_LIMIT = 100;

    private const DEFINITIONS = [
        'nav' => [
            'label' => '顶栏导航',
            'singular' => '导航项',
            'table' => 'nav_item',
            'icon' => 'bi-compass',
            'fields' => [
                ['key' => 'title', 'label' => '展示文案', 'type' => 'text', 'required' => true, 'max' => 64],
                ['key' => 'url', 'label' => '链接地址', 'type' => 'text', 'required' => true, 'max' => 512],
                ['key' => 'target', 'label' => '打开方式', 'type' => 'select', 'options' => ['_self' => '当前窗口', '_blank' => '新窗口']],
            ],
            'columns' => [['key' => 'title', 'label' => '文案'], ['key' => 'url', 'label' => '地址'], ['key' => 'target', 'label' => '打开方式']],
        ],
        'preview' => [
            'label' => '预览页面',
            'singular' => '预览页',
            'table' => 'preview_page',
            'icon' => 'bi-images',
            'fields' => [
                ['key' => 'title', 'label' => '页面标题', 'type' => 'text', 'required' => true, 'max' => 128],
                [
                    'key' => 'image',
                    'label' => '图片地址',
                    'type' => 'text',
                    'required' => true,
                    'max' => 512,
                    'upload' => 'previews',
                ],
            ],
            'columns' => [['key' => 'title', 'label' => '标题'], ['key' => 'image', 'label' => '图片']],
        ],
        'feature' => [
            'label' => '功能卡片',
            'singular' => '功能卡片',
            'table' => 'feature_item',
            'icon' => 'bi-grid',
            'fields' => [
                ['key' => 'title', 'label' => '标题', 'type' => 'text', 'required' => true, 'max' => 128],
                ['key' => 'description', 'label' => '描述', 'type' => 'textarea', 'required' => false, 'max' => 512],
                [
                    'key' => 'icon',
                    'label' => '图标类名或图片地址',
                    'type' => 'text',
                    'required' => false,
                    'max' => 512,
                    'upload' => 'features',
                ],
                ['key' => 'link_url', 'label' => '跳转地址', 'type' => 'text', 'required' => false, 'max' => 512],
            ],
            'columns' => [['key' => 'title', 'label' => '标题'], ['key' => 'description', 'label' => '描述'], ['key' => 'icon', 'label' => '图标']],
        ],
        'friend' => [
            'label' => '友情链接',
            'singular' => '友情链接',
            'table' => 'friend_link',
            'icon' => 'bi-link-45deg',
            'fields' => [
                ['key' => 'name', 'label' => '网站名称', 'type' => 'text', 'required' => true, 'max' => 128],
                ['key' => 'url', 'label' => '网站地址', 'type' => 'text', 'required' => true, 'max' => 512],
            ],
            'columns' => [['key' => 'name', 'label' => '名称'], ['key' => 'url', 'label' => '地址']],
        ],
        'trust' => [
            'label' => '信任品牌',
            'singular' => '信任品牌',
            'table' => 'trust_brand',
            'icon' => 'bi-patch-check',
            'fields' => [
                ['key' => 'name', 'label' => '品牌名称', 'type' => 'text', 'required' => true, 'max' => 128],
                [
                    'key' => 'logo',
                    'label' => '品牌图标 / Logo',
                    'type' => 'text',
                    'required' => false,
                    'max' => 512,
                    'upload' => 'brands',
                ],
                ['key' => 'url', 'label' => '品牌链接', 'type' => 'text', 'required' => false, 'max' => 512],
            ],
            'columns' => [['key' => 'name', 'label' => '名称'], ['key' => 'logo', 'label' => 'Logo'], ['key' => 'url', 'label' => '链接']],
        ],
    ];

    /**
     * @param string $resource 资源标识
     * @return array<string, mixed> 资源定义
     * @throws AdminException 资源不存在时抛出
     */
    public function definition(string $resource): array
    {
        if (!isset(self::DEFINITIONS[$resource])) {
            throw new AdminException('内容资源不存在。');
        }

        return self::DEFINITIONS[$resource];
    }

    /**
     * @param string $resource 资源标识
     * @param int $page 页码
     * @return array<string, mixed> 列表及分页信息
     */
    public function listing(string $resource, int $page): array
    {
        $definition = $this->definition($resource);
        $total = (int) Db::name($definition['table'])->count();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $totalPages));
        $items = Db::name($definition['table'])
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, self::PER_PAGE)
            ->select()
            ->toArray();

        return [
            'definition' => $definition,
            'items' => $items,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ];
    }

    /**
     * @param string $resource 资源标识
     * @param int $id 记录 ID，0 表示新建
     * @return array<string, mixed> 表单数据
     * @throws AdminException 记录不存在时抛出
     */
    public function formData(string $resource, int $id = 0): array
    {
        $definition = $this->definition($resource);
        if ($id <= 0) {
            return ['sort' => 0, 'status' => 1, 'target' => '_self'];
        }

        $item = Db::name($definition['table'])->where('id', $id)->find();
        if (!is_array($item)) {
            throw new AdminException($definition['singular'] . '不存在或已删除。');
        }

        return $item;
    }

    /**
     * @param string $resource 资源标识
     * @param array<string, mixed> $input 表单数据
     * @param int $id 记录 ID，0 表示新建
     * @return int 保存后的 ID
     * @throws AdminException 输入或记录无效时抛出
     */
    public function save(string $resource, array $input, int $id = 0): int
    {
        $definition = $this->definition($resource);
        $data = $this->validate($resource, $input);
        $now = date('Y-m-d H:i:s');
        $data['updated_at'] = $now;

        if ($id > 0) {
            if (!Db::name($definition['table'])->where('id', $id)->find()) {
                throw new AdminException($definition['singular'] . '不存在或已删除。');
            }
            Db::name($definition['table'])->where('id', $id)->update($data);
            return $id;
        }

        $data['created_at'] = $now;
        return (int) Db::name($definition['table'])->insertGetId($data);
    }

    /**
     * @param string $resource 资源标识
     * @param int $id 记录 ID
     * @return void
     * @throws AdminException 记录不存在时抛出
     */
    public function delete(string $resource, int $id): void
    {
        $definition = $this->definition($resource);
        if ($id <= 0 || Db::name($definition['table'])->where('id', $id)->delete() === 0) {
            throw new AdminException($definition['singular'] . '不存在或已删除。');
        }
    }

    /**
     * 批量删除内容资源。
     *
     * @param string $resource 资源标识
     * @param array<int|string> $ids 待删除 ID 列表
     * @return int 实际删除条数
     * @throws AdminException 未选择有效记录、数量超限或全部不存在时抛出
     */
    public function batchDelete(string $resource, array $ids): int
    {
        $definition = $this->definition($resource);
        $normalizedIds = $this->normalizePositiveIds($ids);
        if ($normalizedIds === []) {
            throw new AdminException('请选择要删除的' . $definition['singular'] . '。');
        }
        if (count($normalizedIds) > self::BATCH_DELETE_LIMIT) {
            throw new AdminException(sprintf(
                '单次最多删除 %d 条%s。',
                self::BATCH_DELETE_LIMIT,
                $definition['singular']
            ));
        }

        $deleted = Db::name($definition['table'])->whereIn('id', $normalizedIds)->delete();
        if ($deleted === 0) {
            throw new AdminException($definition['singular'] . '不存在或已删除。');
        }

        return (int) $deleted;
    }

    /**
     * 将原始 ID 列表规范为正整数去重数组。
     *
     * @param array<int|string> $ids 原始 ID
     * @return list<int> 合法正整数 ID
     */
    private function normalizePositiveIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (is_bool($id) || is_array($id) || is_object($id)) {
                continue;
            }

            $value = filter_var($id, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($value === false) {
                continue;
            }

            $normalized[(int) $value] = (int) $value;
        }

        return array_values($normalized);
    }

    /**
     * @param string $resource 资源标识
     * @param int $id 记录 ID
     * @param int|null $targetStatus 目标状态；为空时反转当前状态以兼容旧调用
     * @return int 更新后的状态
     * @throws AdminException 记录不存在或目标状态无效时抛出
     */
    public function toggle(string $resource, int $id, ?int $targetStatus = null): int
    {
        $definition = $this->definition($resource);
        if ($targetStatus !== null && $targetStatus !== 0 && $targetStatus !== 1) {
            throw new AdminException('状态值必须为 0 或 1。');
        }

        $item = Db::name($definition['table'])->where('id', $id)->find();
        if (!is_array($item)) {
            throw new AdminException($definition['singular'] . '不存在或已删除。');
        }

        $currentStatus = (int) $item['status'] === 1 ? 1 : 0;
        $status = $targetStatus ?? ($currentStatus === 1 ? 0 : 1);
        if ($status === $currentStatus) {
            return $status;
        }

        Db::name($definition['table'])->where('id', $id)->update([
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $status;
    }

    /**
     * @param string $resource 资源标识
     * @param array<string, mixed> $input 表单数据
     * @return array<string, mixed> 可入库数据
     * @throws AdminException 输入无效时抛出
     */
    private function validate(string $resource, array $input): array
    {
        $definition = $this->definition($resource);
        $data = [];
        foreach ($definition['fields'] as $field) {
            $key = $field['key'];
            $value = trim((string) ($input[$key] ?? ''));
            if (($field['required'] ?? false) && $value === '') {
                throw new AdminException($field['label'] . '不能为空。');
            }
            if (isset($field['max']) && mb_strlen($value) > (int) $field['max']) {
                throw new AdminException(sprintf('%s长度不能超过 %d 个字符。', $field['label'], $field['max']));
            }
            if (preg_match('/[\x00]/', $value)) {
                throw new AdminException($field['label'] . '包含无效字符。');
            }
            $data[$key] = $value;
        }

        $sort = filter_var($input['sort'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => -999999, 'max_range' => 999999],
        ]);
        if ($sort === false) {
            throw new AdminException('排序值必须是 -999999 到 999999 之间的整数。');
        }
        $data['sort'] = (int) $sort;
        $data['status'] = filter_var($input['status'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        if ($resource === 'nav') {
            if (!$this->isAllowedLink($data['url'])) {
                throw new AdminException('导航地址必须是站内路径、锚点或 http(s) URL。');
            }
            if (!in_array($data['target'], ['_self', '_blank'], true)) {
                throw new AdminException('打开方式无效。');
            }
        }
        if ($resource === 'preview' && !$this->isAllowedAsset($data['image'])) {
            throw new AdminException('预览图片必须是站内绝对路径或 http(s) URL。');
        }
        if ($resource === 'feature') {
            if ($data['icon'] !== ''
                && !preg_match('/^[A-Za-z0-9 _-]+$/', $data['icon'])
                && !$this->isAllowedAsset($data['icon'])) {
                throw new AdminException('功能图标必须是安全类名、站内绝对路径或 http(s) URL。');
            }
            if ($data['link_url'] !== '' && !$this->isAllowedLink($data['link_url'])) {
                throw new AdminException('功能卡片地址必须是站内路径、锚点或 http(s) URL。');
            }
            $data['link_url'] = $data['link_url'] === '' ? null : $data['link_url'];
        }
        if ($resource === 'friend' && !$this->isHttpUrl($data['url'])) {
            throw new AdminException('友情链接必须是有效的 http(s) URL。');
        }
        if ($resource === 'trust') {
            if ($data['logo'] !== '' && !$this->isAllowedAsset($data['logo'])) {
                throw new AdminException('品牌 Logo 必须是站内绝对路径或 http(s) URL。');
            }
            if ($data['url'] !== '' && !$this->isHttpUrl($data['url'])) {
                throw new AdminException('品牌链接必须是有效的 http(s) URL。');
            }
        }

        return $data;
    }

    /**
     * @param string $url 地址
     * @return bool 是否允许作为站内或外部链接
     */
    private function isAllowedLink(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if ($url[0] === '#' && preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/', $url)) {
            return true;
        }
        if ($url[0] === '/' && strpos($url, '//') !== 0) {
            return true;
        }

        return $this->isHttpUrl($url);
    }

    /**
     * @param string $url 地址
     * @return bool 是否允许作为图片资源
     */
    private function isAllowedAsset(string $url): bool
    {
        return ($url !== '' && $url[0] === '/' && strpos($url, '//') !== 0) || $this->isHttpUrl($url);
    }

    /**
     * @param string $url 地址
     * @return bool 是否为 http(s) URL
     */
    private function isHttpUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
