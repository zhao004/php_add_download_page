<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\InstallException;

/**
 * 将安装 SQL 拆为可逐条执行的语句。
 *
 * 解析器识别字符串、标识符及行/块注释，避免把字段值中的分号误当作分隔符。
 */
class SqlStatementParser
{
    /**
     * @param string $sql SQL 文件内容
     * @return array<int, string> 可执行语句
     * @throws InstallException SQL 存在未闭合结构时抛出
     */
    public function parse(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
        $length = strlen($sql);
        $statements = [];
        $buffer = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($lineComment) {
                if ($character === "\n") {
                    $lineComment = false;
                    $buffer .= $character;
                }
                continue;
            }

            if ($blockComment) {
                if ($character === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }
                continue;
            }

            if ($quote !== null) {
                $buffer .= $character;
                if ($character === '\\' && $index + 1 < $length) {
                    $buffer .= $sql[++$index];
                    continue;
                }
                if ($character === $quote) {
                    if ($next === $quote && $quote !== '`') {
                        $buffer .= $sql[++$index];
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            if ($character === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2]))) {
                $lineComment = true;
                $index++;
                continue;
            }
            if ($character === '#') {
                $lineComment = true;
                continue;
            }
            if ($character === '/' && $next === '*') {
                $blockComment = true;
                $index++;
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                $buffer .= $character;
                continue;
            }
            if ($character === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $character;
        }

        if ($quote !== null || $blockComment) {
            throw new InstallException('安装 SQL 含有未闭合的字符串或注释。');
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }
}
