<?php

declare(strict_types=1);

/**
 * 固定验收入口：urlrouter 的对外契约。**别改这个文件。**
 *
 * 用法：
 *   php check/check.php                 跑全部场景，全过才退 0
 *   php check/check.php -list           列出全部场景
 *   php check/check.php --only match    只跑一组
 *   php check/check.php --only match,errors
 *
 * 五组共 12 个场景（对应 README「对外契约」12 条）：
 *   match    4 —— 静态与方法、单段捕获、尾斜杠/段内混合/百分号/查询串等边界；
 *   optional 2 —— 可选段在中间与在末尾；
 *   priority 3 —— 静态优先、带约束优先、同档注册顺序；
 *   reverse  2 —— 反向生成与匹配双向一致；
 *   errors   1 —— 重复参数名 / 重复路由名 / 形状冲突。
 *
 * 只依赖 PHP 8 标准库；判据完全确定，不读时间、不用随机源、不碰文件系统。失败不早退。
 */

require __DIR__ . '/../src/RouteError.php';
require __DIR__ . '/../src/Matcher.php';
require __DIR__ . '/../src/Route.php';
require __DIR__ . '/../src/Router.php';

use UrlRouter\Router;
use UrlRouter\RouteError;

final class CheckFailure extends \Exception
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

// ---------------------------------------------------------------------------
// 工具
// ---------------------------------------------------------------------------

function sc(string $name, string $why, callable $fn): array
{
    return ['name' => $name, 'why' => $why, 'fn' => $fn];
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

function expect_same($want, $got, string $what): void
{
    if ($want !== $got) {
        throw new CheckFailure($what, fmt($want), fmt($got));
    }
}

function expect_true(bool $cond, string $what, string $expected = 'true'): void
{
    if (!$cond) {
        throw new CheckFailure($what, $expected, 'false');
    }
}

function expect_route_error(callable $fn, string $what): void
{
    try {
        $fn();
    } catch (RouteError $exc) {
        return;
    } catch (\Throwable $exc) {
        throw new CheckFailure($what, 'RouteError', get_class($exc) . ': ' . $exc->getMessage());
    }

    throw new CheckFailure($what, 'RouteError', '无异常');
}

function expect_no_error(callable $fn, string $what): void
{
    try {
        $fn();
    } catch (\Throwable $exc) {
        throw new CheckFailure($what, '正常返回', get_class($exc) . ': ' . $exc->getMessage());
    }
}

// ---------------------------------------------------------------------------
// [match] 4
// ---------------------------------------------------------------------------

$match = [
    sc('static', '静态路径精确匹配、大小写敏感', function (): void {
        $r = new Router();
        $r->add('GET', '/health', 'health');
        $r->add('GET', '/about', 'about');

        expect_same(['name' => 'health', 'params' => []], $r->match('GET', '/health'), '第一条静态路径');
        expect_same(['name' => 'about', 'params' => []], $r->match('GET', '/about'), '第二条静态路径');
        expect_same(null, $r->match('GET', '/Health'), '大小写敏感');
        expect_same(null, $r->match('GET', '/nope'), '未注册路径');
    }),

    sc('method', '方法匹配、方法与路径失败可区分', function (): void {
        $r = new Router();
        $r->add('GET', '/x', 'get-x');
        $r->add('POST', '/x', 'post-x');

        expect_same(['name' => 'get-x', 'params' => []], $r->match('GET', '/x'), 'GET 命中');
        expect_same(['name' => 'post-x', 'params' => []], $r->match('POST', '/x'), 'POST 命中');
        expect_same(['name' => 'get-x', 'params' => []], $r->match('get', '/x'), '方法名大小写不敏感');
        expect_same(Router::METHOD_NOT_ALLOWED, $r->match('DELETE', '/x'), '路径命中但方法不命中');
        expect_same(null, $r->match('DELETE', '/missing'), '路径不命中');
    }),

    sc('capture', '单段捕获与段级静态/捕获混合', function (): void {
        $r = new Router();
        $r->add('GET', '/users/{id}', 'user');
        $r->add('GET', '/users/{id}/posts', 'user-posts');

        expect_same(['name' => 'user', 'params' => ['id' => '42']], $r->match('GET', '/users/42'), '单段捕获');
        expect_same(
            ['name' => 'user-posts', 'params' => ['id' => '7']],
            $r->match('GET', '/users/7/posts'),
            '静态段与捕获段混合'
        );
        expect_same(null, $r->match('GET', '/users/42/x'), '多余段不命中');
        expect_same(null, $r->match('GET', '/users/'), '空捕获段不命中');
    }),

    sc('edges', '尾斜杠三策略 / 段内混合 / %2F / 查询串', function (): void {
        // 尾斜杠：strict
        $strict = new Router('strict');
        $strict->add('GET', '/a', 'a');
        expect_same(null, $strict->match('GET', '/a/'), 'strict：/a/ 不匹配 /a');
        expect_same(['name' => 'a', 'params' => []], $strict->match('GET', '/a'), 'strict：/a 命中');

        // 尾斜杠：relaxed
        $relaxed = new Router('relaxed');
        $relaxed->add('GET', '/a', 'a');
        expect_same(['name' => 'a', 'params' => []], $relaxed->match('GET', '/a/'), 'relaxed：/a/ 命中 /a');
        expect_same(['name' => 'a', 'params' => []], $relaxed->match('GET', '/a'), 'relaxed：/a 命中');

        // 尾斜杠：redirect —— 返回目标路径字符串
        $redirect = new Router('redirect');
        $redirect->add('GET', '/users/{id}', 'user');
        expect_same('/users/42', $redirect->match('GET', '/users/42/'), 'redirect：返回去斜杠后的目标路径');
        expect_same(
            ['name' => 'user', 'params' => ['id' => '42']],
            $redirect->match('GET', '/users/42'),
            'redirect：精确命中仍返回匹配数组'
        );

        $redirect2 = new Router('redirect');
        $redirect2->add('GET', '/a/', 'a-slash');
        expect_same('/a/', $redirect2->match('GET', '/a'), 'redirect：无斜杠补齐目标路径');

        // 非法尾斜杠策略
        expect_route_error(static function (): void {
            new Router('loose');
        }, '非法尾斜杠策略抛 RouteError');

        // 段内混合
        $mix = new Router();
        $mix->add('GET', '/file-{name}.txt', 'file');
        expect_same(
            ['name' => 'file', 'params' => ['name' => 'report']],
            $mix->match('GET', '/file-report.txt'),
            '段内静态字符与捕获混排'
        );
        expect_same(null, $mix->match('GET', '/file-.txt'), '混合段里的捕获不能为空');

        // 百分号编码
        $enc = new Router();
        $enc->add('GET', '/files/{name}', 'file-one');
        expect_same(
            ['name' => 'file-one', 'params' => ['name' => 'a/b']],
            $enc->match('GET', '/files/a%2Fb'),
            '%2F 段内解码'
        );
        expect_same(null, $enc->match('GET', '/files/a/b'), '%2F 不得当成路径分隔符');

        // 查询串
        $q = new Router();
        $q->add('GET', '/search', 'search');
        $q->add('POST', '/search', 'search-post');
        expect_same(['name' => 'search', 'params' => []], $q->match('GET', '/search?q=1&x=2'), '查询串不参与匹配');
        expect_same(
            Router::METHOD_NOT_ALLOWED,
            $q->match('DELETE', '/search?q=1'),
            '带查询串时仍区分方法不命中'
        );
    }),
];

// ---------------------------------------------------------------------------
// [optional] 2
// ---------------------------------------------------------------------------

$optional = [
    sc('middle', '可选段出现在中间，缺省时后续段左移', function (): void {
        $r = new Router();
        $r->add('GET', '/a/{b?}/c', 'mid');

        expect_same(['name' => 'mid', 'params' => ['b' => 'x']], $r->match('GET', '/a/x/c'), '可选段存在');
        expect_same(['name' => 'mid', 'params' => []], $r->match('GET', '/a/c'), '可选段缺省');
        expect_same(null, $r->match('GET', '/a/x/d'), '静态段不匹配');
        expect_same(null, $r->match('GET', '/a/x'), '缺尾段不命中');
    }),

    sc('tail', '可选段出现在末尾', function (): void {
        $r = new Router();
        $r->add('GET', '/files/{name?}', 'tail');

        expect_same(['name' => 'tail', 'params' => ['name' => 'a.txt']], $r->match('GET', '/files/a.txt'), '尾可选段存在');
        expect_same(['name' => 'tail', 'params' => []], $r->match('GET', '/files'), '尾可选段缺省');
        expect_same(null, $r->match('GET', '/files/a/b'), '可选段仍只吃一段');
    }),
];

// ---------------------------------------------------------------------------
// [priority] 3
// ---------------------------------------------------------------------------

$priority = [
    sc('static-over-capture', '静态段优先于捕获段，与注册顺序无关', function (): void {
        $r = new Router();
        $r->add('GET', '/users/{id}', 'by-id');
        $r->add('GET', '/users/me', 'me');
        expect_same(['name' => 'me', 'params' => []], $r->match('GET', '/users/me'), '静态在后注册仍胜出');
        expect_same(['name' => 'by-id', 'params' => ['id' => '42']], $r->match('GET', '/users/42'), '其余走捕获');

        $r2 = new Router();
        $r2->add('GET', '/users/me', 'me');
        $r2->add('GET', '/users/{id}', 'by-id');
        expect_same(['name' => 'me', 'params' => []], $r2->match('GET', '/users/me'), '静态在先注册仍胜出');
    }),

    sc('constrained-over-plain', '带约束捕获优先于无约束捕获；通配最低；约束内 \\} 转义', function (): void {
        $r = new Router();
        $r->add('GET', '/n/{id}', 'plain');
        $r->add('GET', '/n/{id:\d+}', 'digits');
        expect_same(['name' => 'digits', 'params' => ['id' => '42']], $r->match('GET', '/n/42'), '带约束优先');
        expect_same(['name' => 'plain', 'params' => ['id' => 'abc']], $r->match('GET', '/n/abc'), '约束不满足时回落');

        $w = new Router();
        $w->add('GET', '/files/{path:.*}', 'wild');
        $w->add('GET', '/files/{name}', 'one');
        expect_same(
            ['name' => 'wild', 'params' => ['path' => 'a/b/c']],
            $w->match('GET', '/files/a/b/c'),
            '通配可跨段'
        );
        expect_same(['name' => 'one', 'params' => ['name' => 'a']], $w->match('GET', '/files/a'), '无约束优先于通配');

        $e = new Router();
        $e->add('GET', '/tag/{t:\\}}', 'tag');
        expect_same(['name' => 'tag', 'params' => ['t' => '}']], $e->match('GET', '/tag/}'), '约束内 \\} 表示字面 }');
    }),

    sc('registration-order', '同一档次的形状不同路由按注册顺序', function (): void {
        $r = new Router();
        $r->add('GET', '/p/a-{x}', 'first');
        $r->add('GET', '/p/{x}-b', 'second');
        expect_same(['name' => 'first', 'params' => ['x' => 'b']], $r->match('GET', '/p/a-b'), '先注册者胜出');

        $r2 = new Router();
        $r2->add('GET', '/p/{x}-b', 'second');
        $r2->add('GET', '/p/a-{x}', 'first');
        expect_same(['name' => 'second', 'params' => ['x' => 'a']], $r2->match('GET', '/p/a-b'), '换序后后者胜出');
    }),
];

// ---------------------------------------------------------------------------
// [reverse] 2
// ---------------------------------------------------------------------------

$reverse = [
    sc('simple', '静态与单段捕获的反向生成与往返', function (): void {
        $r = new Router();
        $r->add('GET', '/users/{id}', 'user');
        $r->add('GET', '/about', 'about');

        expect_same('/about', $r->urlFor('about'), '静态反向生成');
        expect_same('/users/42', $r->urlFor('user', ['id' => '42']), '捕获反向生成');

        $path = $r->urlFor('user', ['id' => '42']);
        expect_same(['name' => 'user', 'params' => ['id' => '42']], $r->match('GET', $path), '生成路径能匹配回去');
    }),

    sc('advanced', '可选段/约束段的生成，参数内的 / 必须编码', function (): void {
        $r = new Router();
        $r->add('GET', '/a/{b?}/c', 'mid');
        $r->add('GET', '/blog/{slug:\d+}', 'post');
        $r->add('GET', '/files/{name}', 'file');

        expect_same('/a/c', $r->urlFor('mid'), '可选段缺省');
        expect_same('/a/x/c', $r->urlFor('mid', ['b' => 'x']), '可选段存在');
        expect_same('/blog/7', $r->urlFor('post', ['slug' => '7']), '约束段反填');

        $path = $r->urlFor('file', ['name' => 'a/b']);
        expect_same('/files/a%2Fb', $path, '参数含 / 必须编码为 %2F');
        expect_same(
            ['name' => 'file', 'params' => ['name' => 'a/b']],
            $r->match('GET', $path),
            '编码后的路径往返一致'
        );

        expect_route_error(static function () use ($r): void {
            $r->urlFor('post', []);
        }, '缺必需参数抛 RouteError');
        expect_route_error(static function () use ($r): void {
            $r->urlFor('missing');
        }, '未知路由名抛 RouteError');
    }),
];

// ---------------------------------------------------------------------------
// [errors] 1
// ---------------------------------------------------------------------------

$errors = [
    sc('duplicate-and-conflict', '重复参数名 / 重复路由名 / 形状冲突都在注册时抛错', function (): void {
        expect_route_error(static function (): void {
            (new Router())->add('GET', '/x/{id}/{id}', 'dup');
        }, '同一模式里重复参数名');

        $r = new Router();
        $r->add('GET', '/a', 'a');
        expect_route_error(static function () use ($r): void {
            $r->add('GET', '/b', 'a');
        }, '重复路由名');

        $r2 = new Router();
        $r2->add('GET', '/u/{id}', 'one');
        expect_route_error(static function () use ($r2): void {
            $r2->add('GET', '/u/{name}', 'two');
        }, '同方法同形状的冲突路由');

        $r3 = new Router();
        $r3->add('GET', '/u/{id}', 'g');
        expect_no_error(static function () use ($r3): void {
            $r3->add('POST', '/u/{id}', 'p');
        }, '方法不同不算冲突');
        expect_same(2, $r3->count(), '两条路由都在');

        $r4 = new Router();
        $r4->add('GET', '/s/{a}/t', 'x');
        expect_no_error(static function () use ($r4): void {
            $r4->add('GET', '/s/t/{b}', 'y');
        }, '形状不同不算冲突');
        expect_same(2, $r4->count(), '两条路由都在');
    }),
];

$GROUPS = [
    'match' => $match,
    'optional' => $optional,
    'priority' => $priority,
    'reverse' => $reverse,
    'errors' => $errors,
];

// ---------------------------------------------------------------------------
// runner
// ---------------------------------------------------------------------------

$flat = [];
foreach ($GROUPS as $group => $scenarios) {
    foreach ($scenarios as $scenario) {
        $flat[] = [$group, $scenario['name'], $scenario['why'], $scenario['fn']];
    }
}

$argv = $argv ?? [];
$doList = false;
$only = null;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '-list' || $arg === '--list') {
        $doList = true;
    } elseif ($arg === '--only' || $arg === '--group') {
        $i++;
        if ($i >= count($argv)) {
            fwrite(STDERR, "--only 需要一个组名（match/optional/priority/reverse/errors）\n");
            exit(2);
        }
        $only = [];
        foreach (explode(',', $argv[$i]) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $only[$part] = true;
            }
        }
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "用法: php check/check.php [-list] [--only <组名>]\n";
        exit(0);
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

if ($doList) {
    foreach ($flat as [$group, $name, $why, $_fn]) {
        printf("[%-8s] %-22s %s\n", $group, $name, $why);
    }
    exit(0);
}

$selected = [];
foreach ($flat as $entry) {
    if ($only === null || isset($only[$entry[0]])) {
        $selected[] = $entry;
    }
}

if ($selected === []) {
    fwrite(STDERR, "没有匹配的场景\n");
    exit(2);
}

$passed = 0;
$failed = 0;

foreach ($selected as [$group, $name, $_why, $fn]) {
    try {
        $fn();
    } catch (CheckFailure $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=%s 实际=%s（%s）\n", $group, $name, $exc->expected, $exc->actual, $exc->getMessage());
        continue;
    } catch (\Throwable $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=正常返回 实际=%s: %s\n", $group, $name, get_class($exc), $exc->getMessage());
        continue;
    }

    $passed++;
    printf("PASS %s/%s\n", $group, $name);
}

$total = count($selected);
printf("结果：通过 %d/%d\n", $passed, $total);
exit($failed === 0 ? 0 : 1);
