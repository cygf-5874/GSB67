<?php

declare(strict_types=1);

namespace UrlRouter;

/**
 * 路由配置错误：模式非法、参数名重复、路由名重复、形状冲突、反向生成缺参等。
 *
 * 属于对外契约的一部分：所有「配置期」错误都必须以本类型抛出。
 */
final class RouteError extends \RuntimeException
{
}
