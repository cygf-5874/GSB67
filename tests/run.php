<?php

declare(strict_types=1);

/**
 * urlrouter 的既有用例（自建断言 runner）。
 *
 * 用法：php tests/run.php
 *
 * 这些用例只覆盖静态路径、单段捕获 `{id}`、方法匹配与最简单的反向生成，
 * 不涉及可选段、带约束的通配、尾斜杠策略、优先级、冲突检测与百分号编码。
 */

require __DIR__ . '/../src/RouteError.php';
require __DIR__ . '/../src/Matcher.php';
require __DIR__ . '/../src/Route.php';
require __DIR__ . '/../src/Router.php';

use UrlRouter\Router;
use UrlRouter\RouteError;

final class TestFailure extends \Exception
{
    public string $expected;
    public string $actual;

    public function __construct(string $message, string $expected = '-', string $actual = '-')
    {
        parent::__construct($message);
        $this->expected = $expected;
        $this->actual = $actual;
    }
}

/** 把任意值压成单行，便于 FAIL 行打印。 */
function fmt($v): string
{
    if (is_array($v) || is_object($v)) {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if ($v === null) {
        return 'null';
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }

    return var_export($v, true);
}

function assert_same($expected, $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new TestFailure(
            $what,
            fmt($expected),
            fmt($actual)
        );
    }
}

function assert_true(bool $cond, string $what): void
{
    if (!$cond) {
        throw new TestFailure($what, 'true', 'false');
    }
}

function assert_route_error(callable $fn, string $what): void
{
    try {
        $fn();
    } catch (RouteError $exc) {
        return;
    } catch (\Throwable $exc) {
        throw new TestFailure($what, 'RouteError', get_class($exc));
    }

    throw new TestFailure($what, 'RouteError', '无异常');
}

$TESTS = [];

function t(string $name, callable $fn): void
{
    global $TESTS;
    $TESTS[] = [$name, $fn];
}

// ---------------------------------------------------------------------------
// 用例
// ---------------------------------------------------------------------------

t('静态路径精确命中', function (): void {
    $r = new Router();
    $r->add('GET', '/health', 'health');
    assert_same(['name' => 'health', 'params' => []], $r->match('GET', '/health'), '命中结果');
    assert_same(1, $r->count(), '路由数量');
});

t('静态路径大小写敏感', function (): void {
    $r = new Router();
    $r->add('GET', '/health', 'health');
    assert_same(null, $r->match('GET', '/Health'), '大写路径不应命中');
    assert_same(null, $r->match('GET', '/HEALTH'), '全大写路径不应命中');
});

t('未注册路径返回 null', function (): void {
    $r = new Router();
    $r->add('GET', '/health', 'health');
    assert_same(null, $r->match('GET', '/missing'), '未知路径');
    assert_same(null, $r->match('GET', '/health/extra'), '多出的段不算命中');
    assert_same(null, $r->match('GET', '/'), '根路径');
});

t('路径命中但方法不命中返回 METHOD_NOT_ALLOWED', function (): void {
    $r = new Router();
    $r->add('GET', '/health', 'health');
    assert_same(Router::METHOD_NOT_ALLOWED, $r->match('POST', '/health'), '方法不匹配');
    assert_same(null, $r->match('POST', '/other'), '路径也不匹配');
});

t('方法名大小写不敏感', function (): void {
    $r = new Router();
    $r->add('get', '/x', 'x');
    assert_same(['name' => 'x', 'params' => []], $r->match('GET', '/x'), '注册小写 / 请求大写');
    assert_same(['name' => 'x', 'params' => []], $r->match('get', '/x'), '两者都小写');
});

t('同一路径可注册多个方法', function (): void {
    $r = new Router();
    $r->add('GET', '/x', 'get-x');
    $r->add('POST', '/x', 'post-x');
    assert_same(['name' => 'get-x', 'params' => []], $r->match('GET', '/x'), 'GET');
    assert_same(['name' => 'post-x', 'params' => []], $r->match('POST', '/x'), 'POST');
    assert_same(Router::METHOD_NOT_ALLOWED, $r->match('DELETE', '/x'), 'DELETE');
});

t('单段捕获 {id}', function (): void {
    $r = new Router();
    $r->add('GET', '/users/{id}', 'user');
    assert_same(['name' => 'user', 'params' => ['id' => '42']], $r->match('GET', '/users/42'), '捕获数字');
    assert_same(['name' => 'user', 'params' => ['id' => 'abc']], $r->match('GET', '/users/abc'), '捕获文本');
});

t('捕获段要求非空', function (): void {
    $r = new Router();
    $r->add('GET', '/users/{id}', 'user');
    assert_same(null, $r->match('GET', '/users/'), '空段不算命中');
    assert_same(null, $r->match('GET', '/users'), '缺少段不算命中');
});

t('捕获段只吃一段', function (): void {
    $r = new Router();
    $r->add('GET', '/users/{id}', 'user');
    assert_same(null, $r->match('GET', '/users/1/2'), '两段不能当一个捕获');
});

t('多段捕获', function (): void {
    $r = new Router();
    $r->add('GET', '/users/{uid}/posts/{pid}', 'post');
    assert_same(
        ['name' => 'post', 'params' => ['uid' => '7', 'pid' => '99']],
        $r->match('GET', '/users/7/posts/99'),
        '两处捕获'
    );
});

t('捕获参数名进入 params', function (): void {
    $r = new Router();
    $r->add('GET', '/u/{a}/{b}', 'ab');
    $got = $r->match('GET', '/u/1/2');
    assert_true(is_array($got), '应当命中');
    assert_same(['a', 'b'], array_keys($got['params']), '参数名与顺序');
    assert_same(['1', '2'], array_values($got['params']), '参数值');
});

t('urlFor 静态路径', function (): void {
    $r = new Router();
    $r->add('GET', '/about', 'about');
    assert_same('/about', $r->urlFor('about'), '静态反向生成');
});

t('urlFor 捕获路径', function (): void {
    $r = new Router();
    $r->add('GET', '/users/{id}', 'user');
    assert_same('/users/42', $r->urlFor('user', ['id' => 42]), '捕获反向生成（int 参数）');
});

t('urlFor 与 match 往返一致', function (): void {
    $r = new Router();
    $r->add('GET', '/users/{uid}/posts/{pid}', 'post');
    $path = $r->urlFor('post', ['uid' => '1', 'pid' => '2']);
    assert_same('/users/1/posts/2', $path, '生成路径');
    assert_same(
        ['name' => 'post', 'params' => ['uid' => '1', 'pid' => '2']],
        $r->match('GET', $path),
        '生成出来的路径能匹配回去'
    );
});

// ---------------------------------------------------------------------------
// runner
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
foreach ($TESTS as [$name, $fn]) {
    try {
        $fn();
    } catch (TestFailure $exc) {
        $failed++;
        echo "FAIL {$name}  期望={$exc->expected} 实际={$exc->actual}（{$exc->getMessage()}）\n";
        continue;
    } catch (\Throwable $exc) {
        $failed++;
        echo 'FAIL ' . $name . '  期望=正常返回 实际=' . get_class($exc) . ': ' . $exc->getMessage() . "\n";
        continue;
    }

    $passed++;
    echo "PASS {$name}\n";
}

$total = count($TESTS);
echo "结果：通过 {$passed}/{$total}\n";
exit($failed === 0 ? 0 : 1);
