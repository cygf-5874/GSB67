<?php

declare(strict_types=1);

namespace UrlRouter;

/**
 * 路由表。
 *
 * ```php
 * $router = new Router();
 * $router->add('GET', '/users/{id}', 'user');
 * $router->match('GET', '/users/42');   // ['name' => 'user', 'params' => ['id' => '42']]
 * $router->urlFor('user', ['id' => 42]); // '/users/42'
 * ```
 *
 * 语义细节（尾斜杠策略、优先级、冲突检测、百分号编码、查询串……）见 README「对外契约」一节。
 */
final class Router
{
    /** 路径命中但方法不命中时 `match()` 的返回值。 */
    public const METHOD_NOT_ALLOWED = 'MethodNotAllowed';

    private string $trailingSlash;

    /** @var Route[] 按注册顺序。 */
    private array $routes = [];

    private Matcher $matcher;

    public function __construct(string $trailingSlash = 'strict')
    {
        $this->trailingSlash = $trailingSlash;
        $this->matcher = new Matcher();
    }

    /** 注册一条路由。 */
    public function add(string $method, string $pattern, string $name): void
    {
        $this->routes[] = new Route($method, $pattern, $name);
    }

    /**
     * 匹配请求。
     *
     * @return array{name: string, params: array<string, string>}|string|null
     *   命中返回 `['name' => ..., 'params' => [...]]`；
     *   路径命中但方法不命中返回 `self::METHOD_NOT_ALLOWED`；路径完全没命中返回 null。
     */
    public function match(string $method, string $path)
    {
        $method = strtoupper($method);
        $segments = Matcher::split($path);
        if ($segments === null) {
            return null;
        }

        $pathMatched = false;
        foreach ($this->routes as $route) {
            $params = $this->matcher->matchSegments($route, $segments);
            if ($params === null) {
                continue;
            }
            $pathMatched = true;
            if ($route->method() === $method) {
                return ['name' => $route->name(), 'params' => $params];
            }
        }

        return $pathMatched ? self::METHOD_NOT_ALLOWED : null;
    }

    /**
     * 反向生成路径。
     *
     * @param array<string, string|int> $params
     */
    public function urlFor(string $name, array $params = []): string
    {
        foreach ($this->routes as $route) {
            if ($route->name() === $name) {
                return $this->matcher->buildPath($route, $params);
            }
        }

        throw new RouteError("unknown route: {$name}");
    }

    /** 已注册的路由数量。 */
    public function count(): int
    {
        return count($this->routes);
    }
}
