<?php

declare(strict_types=1);

namespace UrlRouter;

/**
 * 一条已注册的路由：方法 + 模式 + 名字。
 *
 * 模式按 `/` 切成若干「段」，每段目前是**静态文本**或**单个捕获** `{name}`。
 * 匹配算法在 Matcher 里，本类只负责持有解析结果。
 */
final class Route
{
    private string $method;
    private string $pattern;
    private string $name;

    /** @var array<int, array<string, string>> 每段：['type' => 'static'|'capture', ...] */
    private array $segments;

    public function __construct(string $method, string $pattern, string $name)
    {
        $this->method = strtoupper($method);
        $this->pattern = $pattern;
        $this->name = $name;
        $this->segments = self::parse($pattern);
    }

    /**
     * 把模式切成段。静态段保留原文，`{...}` 段记为捕获。
     *
     * @return array<int, array<string, string>>
     */
    private static function parse(string $pattern): array
    {
        $parts = Matcher::split($pattern);
        if ($parts === null) {
            throw new RouteError("pattern must start with '/': {$pattern}");
        }

        $segments = [];
        foreach ($parts as $part) {
            $len = strlen($part);
            if ($len >= 2 && $part[0] === '{' && $part[$len - 1] === '}') {
                $segments[] = ['type' => 'capture', 'name' => substr($part, 1, -1)];
                continue;
            }
            $segments[] = ['type' => 'static', 'text' => $part];
        }

        return $segments;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<int, array<string, string>> */
    public function segments(): array
    {
        return $this->segments;
    }
}
