<?php

declare(strict_types=1);

namespace DOF\HTTP\Pipe;

use DOF\Util\IS;
use DOF\Util\Str;
use DOF\Util\Arr;
use DOF\Util\Collection;
use DOF\Util\DSL\IFRSN;
use DOF\HTTP\HTTPPortManager;

class Sorting
{
    public function pipein($request, $response, $route, $port)
    {
        $sort = $request->all('__sort');
        $field = $order = null;
        if ($sort) {
            $sort = \sprintf('sort(%s)', $sort);
            $sort = IFRSN::parse($sort);
            $sort = $sort['sort']['fields'] ?? null;
            if ($sort) {
                $field = $sort['field'] ?? null;
                $order = $sort['order'] ?? null;
            }
        }
        if ($sortField = $request->all('__sort_field', null)) {
            $field = $sortField;
        }
        if ($sortOrder = $request->all('__sort_order', null)) {
            $order = $sortOrder;
        }

        if (! IS::ciin($order, ['asc', 'desc'])) {
            $order = null;
        }

        if ($field) {
            // annotation extra parameter named as `fields`
            $allow = $port->annotation('PIPEIN', null, true)[static::class]['ALLOW'] ?? '';
            $allow = Str::arr($allow);
            $properties = HTTPPortManager::getProperties($route->class);

            $abort = true;
            foreach ($allow as &$property) {
                if ($field === $property) {
                    $abort = false;
                    continue;
                }

                $compatibles = $properties[$property]['doc']['COMPATIBLE'] ?? [];
                if (\in_array($field, $compatibles)) {
                    $field = $property;
                    $abort = false;
                    continue;
                }

                $property = [$property => $compatibles];
            }

            if ($abort) {
                return $response->fail(400, 'INVALID_SORTING_FIELD', \compact('field', 'allow'));
            }
        }

        $route->setPipein(static::class, new Collection(\compact('field', 'order')));
    }
}
