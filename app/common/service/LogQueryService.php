<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\AdminException;
use DateInterval;
use DateTimeImmutable;
use think\db\Query;
use think\facade\Db;

/**
 * 提供访问与下载日志的受限筛选和分页查询。
 */
class LogQueryService
{
    private const PER_PAGE = 25;

    /** 单次批量删除的最大条数，防止误操作与超大请求。 */
    private const BATCH_DELETE_LIMIT = 100;

    private const DEVICE_TYPES = ['mobile', 'desktop', 'tablet', 'bot', 'unknown'];
    private const DOWNLOAD_STATUSES = ['pending', 'success', 'fail_missing_file', 'fail_lanzou', 'fail_other'];
    // 保留 lanzou 以便筛选历史日志；新写入仅 local/other。
    private const DOWNLOAD_MODES = ['local', 'other', 'lanzou'];

    /**
     * 详情接口仅返回当前后台需要展示的访问日志字段，避免未来新增列被意外暴露。
     */
    private const VISIT_DETAIL_FIELDS = [
        'id',
        'created_at',
        'ip',
        'country',
        'region',
        'city',
        'isp',
        'region_raw',
        'device_type',
        'method',
        'path',
        'referer',
        'user_agent',
        'session_id',
    ];

    /**
     * 详情接口仅返回当前后台需要展示的下载日志字段，避免未来新增列被意外暴露。
     */
    private const DOWNLOAD_DETAIL_FIELDS = [
        'id',
        'created_at',
        'ip',
        'country',
        'region',
        'city',
        'isp',
        'region_raw',
        'device_type',
        'download_mode',
        'status',
        'fail_reason',
        'referer',
        'user_agent',
    ];

    /**
     * @param string $type visit/download
     * @param array<string, mixed> $input 查询参数
     * @param int $page 页码
     * @return array<string, mixed> 日志与分页信息
     * @throws AdminException 参数无效时抛出
     */
    public function listing(string $type, array $input, int $page): array
    {
        $filters = $this->filters($type, $input);
        $total = (int) $this->query($type, $filters)->count();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $totalPages));
        $items = $this->query($type, $filters)
            ->order('id', 'desc')
            ->page($page, self::PER_PAGE)
            ->select()
            ->toArray();

        return [
            'filters' => $filters,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'previousPage' => max(1, $page - 1),
            'nextPage' => min($totalPages, $page + 1),
        ];
    }

    /**
     * 读取单条日志的详情字段。
     *
     * @param string $type visit/download
     * @param int $id 日志 ID
     * @return array<string, mixed>|null 记录不存在时返回 null
     * @throws AdminException 类型或 ID 无效时抛出
     */
    public function detail(string $type, int $id): ?array
    {
        $this->typeLabel($type);
        if ($id <= 0) {
            throw new AdminException('日志记录 ID 无效。');
        }

        $item = Db::name($this->tableName($type))
            ->field($this->detailFields($type))
            ->where('id', $id)
            ->find();

        return is_array($item) && $item !== [] ? $item : null;
    }

    /**
     * 批量删除访问或下载日志。
     *
     * @param string $type visit/download
     * @param array<int|string> $ids 待删除 ID 列表
     * @return int 实际删除条数
     * @throws AdminException 类型无效、未选择有效记录、数量超限或全部不存在时抛出
     */
    public function batchDelete(string $type, array $ids): int
    {
        $label = $this->typeLabel($type);
        $normalizedIds = $this->normalizePositiveIds($ids);
        if ($normalizedIds === []) {
            throw new AdminException('请选择要删除的' . $label . '。');
        }
        if (count($normalizedIds) > self::BATCH_DELETE_LIMIT) {
            throw new AdminException(sprintf(
                '单次最多删除 %d 条%s。',
                self::BATCH_DELETE_LIMIT,
                $label
            ));
        }

        $deleted = Db::name($this->tableName($type))
            ->whereIn('id', $normalizedIds)
            ->delete();
        if ($deleted === 0) {
            throw new AdminException($label . '不存在或已删除。');
        }

        return (int) $deleted;
    }

    /**
     * @param string $type 日志类型
     * @return string 数据表名
     * @throws AdminException 类型无效时抛出
     */
    private function tableName(string $type): string
    {
        if ($type === 'visit') {
            return 'visit_log';
        }
        if ($type === 'download') {
            return 'download_click_log';
        }

        throw new AdminException('日志类型无效。');
    }

    /**
     * @param string $type 日志类型
     * @return string 面向管理员的类型文案
     * @throws AdminException 类型无效时抛出
     */
    private function typeLabel(string $type): string
    {
        if ($type === 'visit') {
            return '访问日志';
        }
        if ($type === 'download') {
            return '下载日志';
        }

        throw new AdminException('日志类型无效。');
    }

    /**
     * @param string $type 日志类型
     * @return list<string> 允许返回的详情字段
     * @throws AdminException 类型无效时抛出
     */
    private function detailFields(string $type): array
    {
        if ($type === 'visit') {
            return self::VISIT_DETAIL_FIELDS;
        }
        if ($type === 'download') {
            return self::DOWNLOAD_DETAIL_FIELDS;
        }

        throw new AdminException('日志类型无效。');
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
     * @param string $type 日志类型
     * @param array<string, mixed> $input 查询参数
     * @return array<string, string> 规范化筛选条件
     * @throws AdminException 筛选值无效时抛出
     */
    private function filters(string $type, array $input): array
    {
        // 通过 typeLabel 校验类型，避免重复维护白名单分支。
        $this->typeLabel($type);

        $filters = [
            'date_from' => $this->date((string) ($input['date_from'] ?? ''), '开始日期'),
            'date_to' => $this->date((string) ($input['date_to'] ?? ''), '结束日期'),
            'ip' => $this->limited((string) ($input['ip'] ?? ''), 45, 'IP'),
        ];
        if ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to']) {
            throw new AdminException('开始日期不能晚于结束日期。');
        }

        if ($type === 'visit') {
            $filters['city'] = $this->limited((string) ($input['city'] ?? ''), 64, '城市');
            $filters['isp'] = $this->limited((string) ($input['isp'] ?? ''), 128, '运营商');
            $device = trim((string) ($input['device_type'] ?? ''));
            $filters['device_type'] = in_array($device, self::DEVICE_TYPES, true) ? $device : '';
        } else {
            $status = trim((string) ($input['status'] ?? ''));
            $mode = trim((string) ($input['download_mode'] ?? ''));
            $filters['status'] = in_array($status, self::DOWNLOAD_STATUSES, true) ? $status : '';
            $filters['download_mode'] = in_array($mode, self::DOWNLOAD_MODES, true) ? $mode : '';
        }

        return $filters;
    }

    /**
     * @param string $type 日志类型
     * @param array<string, string> $filters 筛选条件
     * @return Query 查询对象
     */
    private function query(string $type, array $filters): Query
    {
        $query = Db::name($this->tableName($type));
        if ($filters['date_from'] !== '') {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if ($filters['date_to'] !== '') {
            $exclusiveEnd = (new DateTimeImmutable($filters['date_to']))->add(new DateInterval('P1D'));
            $query->where('created_at', '<', $exclusiveEnd->format('Y-m-d 00:00:00'));
        }
        if ($filters['ip'] !== '') {
            $query->whereLike('ip', '%' . $filters['ip'] . '%');
        }

        if ($type === 'visit') {
            foreach (['city', 'isp'] as $field) {
                if ($filters[$field] !== '') {
                    $query->whereLike($field, '%' . $filters[$field] . '%');
                }
            }
            if ($filters['device_type'] !== '') {
                $query->where('device_type', $filters['device_type']);
            }
        } else {
            if ($filters['status'] !== '') {
                $query->where('status', $filters['status']);
            }
            if ($filters['download_mode'] !== '') {
                $query->where('download_mode', $filters['download_mode']);
            }
        }

        return $query;
    }

    /**
     * @param string $value 日期字符串
     * @param string $label 字段名
     * @return string 有效日期或空字符串
     * @throws AdminException 日期格式无效时抛出
     */
    private function date(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new AdminException($label . '格式无效。');
        }

        return $date->format('Y-m-d');
    }

    /**
     * @param string $value 原始文本
     * @param int $maxLength 最大长度
     * @param string $label 字段名
     * @return string 规范化文本
     * @throws AdminException 长度超限时抛出
     */
    private function limited(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $maxLength || preg_match('/[\x00]/', $value)) {
            throw new AdminException($label . '筛选值无效。');
        }

        return $value;
    }
}
