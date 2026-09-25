# urlrouter

一个小巧的 **URL 路由库**：注册「方法 + 模式 + 名字」，把请求路径匹配到路由上，
并支持按名字反向生成路径。纯 PHP 标准库（`php-cli`），无第三方依赖（不用 Composer）。

```php
use UrlRouter\Router;

$router = new Router();                       // 尾斜杠策略，见契约第 5 条
$router->add('GET', '/users/{id}', 'user');
$router->add('GET', '/users/me', 'me');
$router->add('GET', '/a/{b?}/c', 'mid');

$router->match('GET', '/users/42');           // ['name' => 'user', 'params' => ['id' => '42']]
$router->match('GET', '/users/me');           // ['name' => 'me', 'params' => []]
$router->match('GET', '/users/42/');          // null（默认 strict）
$router->urlFor('user', ['id' => 42]);        // '/users/42'
```

## 目录

```
src/RouteError.php   路由配置错误（对外类型）
src/Route.php        一条路由：方法 + 模式 + 名字，及模式的段解析
src/Matcher.php      路径切分、逐段匹配、反向填参
src/Router.php       路由表与公开 API
tests/run.php        既有用例（14 个，自建 runner）
check/check.php      固定验收入口（**勿改**）
scripts/check.sh     固定验收入口的 shell 包装（**勿改**）
```

## 公开 API

| 成员 | 签名 | 说明 |
| --- | --- | --- |
| `Router::__construct` | `(string $trailingSlash = 'strict')` | 尾斜杠策略，取值见契约第 5 条 |
| `Router::add` | `(string $method, string $pattern, string $name): void` | 注册路由；配置非法抛 `RouteError` |
| `Router::match` | `(string $method, string $path): array\|string\|null` | 见契约第 12 条 |
| `Router::urlFor` | `(string $name, array $params = []): string` | 反向生成，见契约第 10 条 |
| `Router::count` | `(): int` | 已注册路由条数 |
| `Router::METHOD_NOT_ALLOWED` | `const string = 'MethodNotAllowed'` | 路径命中、方法不命中时的返回值 |

`match()` 命中时返回的数组**键顺序固定**为 `name` 在前、`params` 在后；
`params` 内的键按参数在模式中**首次出现的位置**排序（如 `/u/{a}/{b}` → `['a' => ..., 'b' => ...]`）。

## 对外契约（12 条）

下面 12 条是 `urlrouter` 的**对外契约**，是本题验收点的唯一出处。
**它们是契约，不是对「当前行为」的转述。** 语义细节以本节为准。

1. **方法匹配与静态路径**。`add($method, $pattern, $name)` 注册一条路由；
   方法名大小写不敏感（内部归一为大写），同一条路径可以为不同方法各注册一条。
   静态路径按**逐字节精确**比较；命中返回 `['name' => $name, 'params' => []]`。
   `$pattern` 必须以 `/` 开头，否则抛 `RouteError`。

2. **单段捕获 `{id}`**。一个 `{name}` 段匹配**恰好一段**非空路径（段内不含 `/`），
   捕获值以字符串放进 `params`。因此 `/users/{id}` 不匹配 `/users/`、`/users` 或 `/users/42/x`；
   模式中出现的每一个捕获参数名都必须出现在命中结果的 `params` 里。

3. **可选段 `{id?}`**。可出现在任意位置（含中间与末尾）；请求中缺少该段时，
   后续各段整体左移，即正则语义等价于 `(?:/...)?`。
   例如 `/a/{b?}/c` 匹配 `/a/c`（无 `b`）与 `/a/x/c`（`b = "x"`）；
   可选段缺省时**不**出现在 `params` 里。可选段只能是无约束捕获。

4. **带约束的捕获**。`{name:constraint}` 中 `constraint` 是一段**正则片段**（不含定界符），
   用来限定该段的取值，如 `{id:\d+}` 只吃数字。若约束本身能匹配 `/`（如 `{path:.*}`），
   则该捕获可以**跨段**匹配。约束片段里要出现字面 `}` 时必须写成 `\}`。

5. **尾斜杠策略**。由构造参数 `$trailingSlash` 决定，取值只能是 `strict` / `relaxed` / `redirect`
   （其它值抛 `RouteError`）：
   - `strict`：尾斜杠有意义，`/a/` 与 `/a` 是两条不同路径；
   - `relaxed`：忽略一个尾斜杠，`/a/` 与 `/a` 互相匹配；
   - `redirect`：先按 `strict` 找；若严格匹配不到（返回 `null`），把请求路径的尾斜杠翻转后再找一次，
     若这次命中了**同方法**的路由，`match()` 返回**目标路径字符串**（翻转后的那条路径）而不是匹配数组；
     否则按翻转后的结果给出 `null` 或 `METHOD_NOT_ALLOWED`。

6. **大小写敏感与段内混合**。路径比较**大小写敏感**；同一段里可以混排静态字符与捕获，
   如 `/file-{name}.txt`，其中捕获部分吃掉该段内相应位置的字面内容。

7. **优先级**。按段逐段比较，段分四档（数字越小越优先）：
   **静态段(0) < 带约束的捕获段(1) < 无约束捕获段(2) < 通配(3)**。
   含静态字符的混合段（如 `file-{name}`）算第 1 档，除非其中的捕获是通配（则算第 3 档）。
   比较规则：按段依次比较档次，先出现更小档次者优先；档次序列完全相同时，段数多者优先；
   仍然相同则**注册顺序在前的优先**。

8. **参数名**。捕获参数名必须出现在命中结果的 `params` 中；同一条模式里出现**重复参数名**
   （如 `/{id}/{id}`）在**注册时**抛 `RouteError`；同一路由名被重复注册也抛 `RouteError`。

9. **冲突检测**。注册时若已存在与该路由**方法相同、形状相同**（形状 = 逐段的静态文本相同，
   或同为同类捕获且约束相同，忽略参数名）的路由，抛 `RouteError`，不得静默覆盖。
   方法不同、或形状不同的路由不算冲突。

10. **反向生成 `urlFor($name, $params)`**。按名字生成路径，结果必须能匹配回**同一条**路由
    且参数相等（双向一致）。参数值按百分号编码写入（因此值里的 `/` 不会变成新的段）；
    缺必需参数、或名字不存在，抛 `RouteError`；可选段没有对应参数时该段整体省略。

11. **百分号编码**。匹配前把请求路径按 `/` 切段，再对**每一段**做百分号解码，然后才比对；
    因此 `%2F` 解码后是段内的字面 `/`，**不得**被当成路径分隔符 ——
    `/files/{name}` 能匹配 `/files/a%2Fb` 且 `name = "a/b"`，但不匹配 `/files/a/b`。

12. **查询串与失败区分**。请求路径里的 `?query` 部分不参与匹配（匹配前去掉）。
    `match()` 在路径没有命中任何路由时返回 `null`；在路径命中了路由、但没有一条方法匹配时，
    返回 `Router::METHOD_NOT_ALLOWED`。路径不是以 `/` 开头时返回 `null`。

## 验收

`check/` 是固定验收程序，**不要修改它**。它按五组共 12 个场景检查上面的契约：

| 组 | 场景 | 对应契约 |
| --- | --- | --- |
| `match` | `static` | 1、6 |
| `match` | `method` | 1、12 |
| `match` | `capture` | 2、6 |
| `match` | `edges` | 5、6、11、12 |
| `optional` | `middle` | 3 |
| `optional` | `tail` | 3 |
| `priority` | `static-over-capture` | 7 |
| `priority` | `constrained-over-plain` | 4、7 |
| `priority` | `registration-order` | 6、7 |
| `reverse` | `simple` | 10 |
| `reverse` | `advanced` | 3、4、10、11 |
| `errors` | `duplicate-and-conflict` | 8、9 |

共 12 个场景。每个场景独立判定，失败不会遮蔽其余场景；判据全部是纯函数结果比对
（数组 `===` 深比较），不依赖时间、随机源或文件系统。

## 怎么跑

```bash
php tests/run.php                  # 既有用例
php check/check.php                # 全部验收场景，全过才退 0
php check/check.php -list          # 列出全部场景
php check/check.php --only match   # 只跑一组（match / optional / priority / reverse / errors）
php check/check.php --only match,errors

bash scripts/check.sh              # 与 php check/check.php 等价
```

`check/check.php` 是**固定验收入口，不要改它**；失败不早退，一次把问题全暴露出来。

## 版本前提

- PHP 8.0 及以上，只用标准库（`php-cli`），不需要 Composer、联网、数据库或中间件。
- **不使用 `mbstring`**（本机 `php-cli` 未启用该扩展）。
- 判据完全确定：输入写死，不涉及时间、随机源或机器速度。
