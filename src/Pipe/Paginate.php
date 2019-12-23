<?php

declare(strict_types=1);

namespace DOF\HTTP\Pipe;

use DOF\INI;
use DOF\Util\Format;
use DOF\Util\DSL\IFRSN;

class Paginate
{
    const MAX_PAGE_SIZE = 50;

    public function pipein($request, $response, $route, $port)
    {
        $size = $this->getPaginateDefaultSize($port);
        $page = 1;
        $paginate = $request->all('__paginate');

        if ($paginate) {
            $paginate = \sprintf('paginate(%s)', $paginate);
            $paginate = IFRSN::parse($paginate);
            $paginate = $paginate['paginate']['fields'] ?? null;
            if ($paginate) {
                $size = $paginate['size'] ?? $this->getPaginateDefaultSize($port);
                $page = $paginate['page'] ?? 1;
            }
        }

        if ($paginateSize = $request->all('__paginate_size', null)) {
            $size = $paginateSize;
        }
        if ($paginatePage = $request->all('__paginate_page', null)) {
            $page = $paginatePage;
        }

        $route->setPipein(static::class, Format::collect([
            'size' => $this->validateSize($size, $port),
            'page' => $this->validatePage($page, $port),
        ]));

        return true;
    }

    protected function validateSize($size, $port) : int
    {
        $size = \intval($size);
        $size = $size < 0 ? $this->getPaginateDefaultSize($port) : $size;
        $size = $size > $this->getPaginateMaxSize($port) ? $this->getPaginateMaxSize($port) : $size;

        return $size;
    }

    protected function validatePage($page) : int
    {
        $page = \intval($page);
        $page = $page < 1 ? 1 : $page;

        return $page;
    }

    protected function getPaginateMaxSize($port) : int
    {
        if ($max = $port->annotation('MaxPageSize')) {
            return \intval($max);
        }

        if ($max = INI::final($port->class, 'MAX_PAGINATE_SIZE')) {
            return \intval($max);
        }

        return self::MAX_PAGE_SIZE;
    }

    protected function getPaginateDefaultSize($port) : int
    {
        return 10;
    }

    protected function getPaginateParameterName($port) : string
    {
        return '__paginate';
    }
}
