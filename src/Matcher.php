<?php

declare(strict_types=1);

namespace UrlRouter;

/**
 * 路径解析与匹配引擎。
 *
 * `split()` 把形如 `/a/b` 的字符串切成段（返回 null 表示不是合法路径）；
 * `matchSegments()` 逐段比对一条路由并收集捕获参数；
 * `buildPath()` 反向把参数填回模式，供 `Router::urlFor()` 使用。
 */
final class Matcher
{
    /**
     * 把 `/a/b/` 这样的字符串切成段：`['a', 'b', '']`；根路径 `/` 切成 `[]`。
     * 不以 `/` 开头（含空串）返回 null。
     *
     * @return array<int, string>|null
     */
    public static function split(string $s): ?array
    {
        if ($s === '' || $s[0] !== '/') {
            return null;
        }
        if ($s === '/') {
            return [];
        }

        return explode('/', substr($s, 1));
    }

    /**
     * 用一条路由去比对已经切好的请求段。
     *
     * @param array<int, string> $pathSegments
     * @return array<string, string>|null 命中返回捕获参数（可能为空数组）；不命中返回 null。
     */
    public function matchSegments(Route $route, array $pathSegments): ?array
    {
        $segments = $route->segments();
        if (count($segments) !== count($pathSegments)) {
            return null;
        }

        $params = [];
        foreach ($segments as $i => $segment) {
            $part = $pathSegments[$i];

            if ($segment['type'] === 'static') {
                if ($part !== $segment['text']) {
                    return null;
                }
                continue;
            }

            if ($part === '') {
                return null;
            }
            $params[$segment['name']] = $part;
        }

        return $params;
    }

    /**
     * 反向生成：把参数填回模式。
     *
     * @param array<string, string|int> $params
     */
    public function buildPath(Route $route, array $params): string
    {
        $out = [];
        foreach ($route->segments() as $segment) {
            if ($segment['type'] === 'static') {
                $out[] = $segment['text'];
                continue;
            }

            $name = $segment['name'];
            if (!array_key_exists($name, $params)) {
                throw new RouteError("missing parameter '{$name}' for route '{$route->name()}'");
            }
            $out[] = (string) $params[$name];
        }

        if ($out === []) {
            return '/';
        }

        return '/' . implode('/', $out);
    }
}
