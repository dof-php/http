<?php

declare(strict_types=1);

namespace DOF\HTTP;

use Throwable;
use Swoole\Http\Response as SwooleHttpResponse;

final class SwooleResponse extends Response
{
    private $swoole;

    public function __construct(SwooleHttpResponse $swoole, Kernel $kernel)
    {
        $this->swoole = $swoole;

        parent::__construct($kernel);
    }

    public function send() : void
    {
        if ((! $this->swoole) || $this->sent) {
            return;
        }

        foreach ($this->headers() as $key => $value) {
            $this->swoole->header($key, $value);
        }

        $this->swoole->status($this->status);
        $this->swoole->end($this->stringify($this->body));
        $this->sent = true;
        $this->swoole = null;
        $this->kernel->shutdown();
    }
}
