<?php

declare(strict_types=1);

namespace DOF\HTTP;

use Swoole\Http\Request as SwooleHttpRequest;

final class SwooleRequest extends Request
{
    private $swoole;

    public function __construct(SwooleHttpRequest $swoole, Kernel $kernel)
    {
        $this->swoole = $swoole;

        parent::__construct($kernel);
    }

    public function getAll() : array
    {
        return $this->getOrSet('all', function () {
            $input = $this->getInput();
            if (\is_array($input)) {
                return \array_merge($this->getGet(), $this->getPost(), $input);
            }

            return \array_merge($this->getGet(), $this->getPost());
        });
    }

    public function getPost() : array
    {
        return $this->getOrSet('post', function () {
            return (array) $this->swoole->post;
        });
    }

    public function getGet() : array
    {
        return $this->getOrSet('get', function () {
            return (array) $this->swoole->get;
        });
    }

    public function getInputRaw() : string
    {
        return $this->getOrSet('input-raw', function () {
            return \trim((string) $this->swoole->rawContent());
        });
    }

    public function getMimeLong() : ?string
    {
        $mime = $this->swoole->header['content-type'] ?? null;     // lowercase already

        return $mime ? \trim($mime) : null;
    }

    public function getTime() : ?int
    {
        return (int) $this->kernel->uptime;

        // return $this->swoole->server['request_time'] ?? null;
    }

    public function getTimeFloat() : ?float
    {
        return $this->kernel->uptime;

        // request_time是在Worker设置的，在SWOOLE_PROCESS模式下存在dispatch过程，因此可能会与实际收包时间存在偏差。
        // 尤其是当请求量超过服务器处理能力时，request_time可能远滞后于实际收包时间。
        // return $this->swoole->server['request_time_float'] ?? null;
    }

    public function getRequestUri() : ?string
    {
        return $this->swoole->server['request_uri'] ?? null;
    }

    public function getMethod() : ?string
    {
        return $this->swoole->server['request_method'] ?? null;
    }
}
