<?php

declare(strict_types=1);

namespace DOF\HTTP;

use Swoole\Http\Request as SwooleHttpRequest;
use Swoole\Http\Response as SwooleHttpResponse;

final class Swoole extends Kernel
{
    public function __construct(SwooleHttpRequest $request, SwooleHttpResponse $response)
    {
        parent::__construct('swoole');

        $this->request = new SwooleRequest($request, $this);
        $this->response = new SwooleResponse($response, $this);
    }

    public function shutdown()
    {
        $this->logging();
    }
}
