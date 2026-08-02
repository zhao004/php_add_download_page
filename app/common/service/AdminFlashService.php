<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Session;

/**
 * 后台 Flash / Toast 队列。
 *
 * 仅保留最新若干条，避免连续操作后提示刷屏。
 */
class AdminFlashService
{
    public const SESSION_KEY = 'admin_flash';

    /** 同时保留与展示的最大提示条数。 */
    public const MAX_ITEMS = 3;

    private const ALLOWED_TYPES = ['success', 'danger', 'warning', 'info'];

    /**
     * 追加一条提示，并裁剪为最新 {@see MAX_ITEMS} 条。
     *
     * @param string $message 提示文案
     * @param string $type success/danger/warning/info
     * @return void
     */
    public function push(string $message, string $type = 'success'): void
    {
        $normalized = $this->normalizeItem([
            'message' => $message,
            'type' => $type,
        ]);
        if ($normalized === null) {
            return;
        }

        $items = $this->readQueue();
        $items[] = $normalized;
        Session::set(self::SESSION_KEY, array_slice($items, -self::MAX_ITEMS));
    }

    /**
     * 读取并清空会话中的提示队列。
     *
     * @return list<array{message:string,type:string}> 最新提示列表（最多 MAX_ITEMS 条）
     */
    public function pull(): array
    {
        $items = $this->readQueue();
        Session::delete(self::SESSION_KEY);

        return $items;
    }

    /**
     * 读取当前队列但不清空，主要用于兼容检查。
     *
     * @return list<array{message:string,type:string}>
     */
    private function readQueue(): array
    {
        $raw = Session::get(self::SESSION_KEY);
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        // 兼容旧版单条结构：['message' => ..., 'type' => ...]
        if (array_key_exists('message', $raw)) {
            $item = $this->normalizeItem($raw);
            return $item === null ? [] : [$item];
        }

        $items = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $item = $this->normalizeItem($entry);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            return [];
        }

        return array_slice($items, -self::MAX_ITEMS);
    }

    /**
     * @param array<string, mixed> $item 原始提示
     * @return array{message:string,type:string}|null 规范化后的提示
     */
    private function normalizeItem(array $item): ?array
    {
        $message = trim((string) ($item['message'] ?? ''));
        if ($message === '' || preg_match('/[\x00]/', $message)) {
            return null;
        }
        // 控制展示长度，避免超长文案撑满屏幕。
        if (function_exists('mb_substr') && mb_strlen($message) > 240) {
            $message = mb_substr($message, 0, 240) . '…';
        } elseif (strlen($message) > 240) {
            $message = substr($message, 0, 240) . '...';
        }

        $type = strtolower(trim((string) ($item['type'] ?? 'success')));
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $type = 'info';
        }

        return [
            'message' => $message,
            'type' => $type,
        ];
    }
}
