<?php

declare(strict_types=1);

namespace DOF\HTTP;

use DOF\ENV;
use DOF\INI;
use DOF\Util\IS;
use DOF\Util\Str;
use DOF\Util\Arr;

final class LegacyRequest extends Request
{
    public function getHeaders() : array
    {
        return $this->getOrSet('headers', function () {
            return \array_change_key_case(getallheaders(), CASE_UPPER);
        });
    }

    public function getInputRaw() : string
    {
        return $this->getOrSet('input_raw', function () {
            return \trim((string) \file_get_contents('php://input'));
        });
    }

    public function getAll() : array
    {
        return $this->getOrSet('all', function () {
            $input = $this->getInput();
            if (\is_array($input)) {
                return \array_merge($_REQUEST, $input);
            }

            return $_REQUEST;
        });
    }

    public function getPost() : array
    {
        return $this->getOrSet('post', function () {
            return $_POST;
        });
    }

    public function getGet() : array
    {
        return $this->getOrSet('get', function () {
            return $_GET;
        });
    }

    public function getHost() : ?string
    {
        return $this->getOrSet('host', function () {
            return $_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? null);
        });
    }

    public function getMimeLong() : ?string
    {
        $mime = $_SERVER['HTTP_CONTENT_TYPE'] ?? null;

        return $mime ? \trim(\strtolower($mime)) : null;
    }

    public function getClientIp(bool $forward = false) : ?string
    {
        $key = $forward ? 'client_ips_forward' : 'client_ip';

        return $this->getOrSet($key, function () use ($forward) {
            return http_client_ip($forward);
        });
    }

    public function getClientOS()
    {
        return $this->getOrSet('client_os', function () {
            return http_client_os();
        });
    }

    public function getClientName()
    {
        return $this->getOrSet('client_name', function () {
            return http_client_name();
        });
        // $_SERVER['REMOTE_PORT'] ?? null,
    }

    public function getClientPort()
    {
        return $this->getOrSet('client_port', function () {
            return $_SERVER['REMOTE_PORT'] ?? null;
        });
    }

    public function getClientUA() : ?string
    {
        return $this->getOrSet('client_user_agent', function () {
            return $this->getHeader('USER_AGENT');
        });
    }

    public function getMethod() : ?string
    {
        return $this->getOrSet('method', function () {
            return $_SERVER['REQUEST_METHOD'] ?? null;
        });
    }

    public function getQueryString() : string
    {
        return $this->getOrSet('query_string', function () {
            $res = $_SERVER['QUERY_STRING'] ?? null;
            if (! \is_null($res)) {
                return $res;
            }

            $uri = $this->getRequestUri();
            $res = (string) parse_url("http://dof{$uri}", PHP_URL_QUERY);

            return $res;
        });
    }

    public function getScheme() : ?string
    {
        return $_SERVER['REQUEST_SCHEME'] ?? null;
    }

    public function getRequestUri() : string
    {
        return $this->getOrSet('uri_request', function () {
            return \urldecode($_SERVER['REQUEST_URI'] ?? '/');
        });
    }

    public function getTimeFloat() : ?float
    {
        return $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
    }

    public function getTime() : ?int
    {
        return $_SERVER['REQUEST_TIME'] ?? null;
    }

    public function getContext(bool $basic = false) : array
    {
        $context = $_basic = [
            $this->getVerb(),
            $this->getUri(),
            $this->getMime(),
        ];

        if ($basic) {
            return $_basic;
        }

        $logHeaders = ENV::systemGet('HTTP_REQUEST_LOG_HEADERS', false);
        $logParams = ENV::systemGet('HTTP_REQUEST_LOG_PARAMS', false);
        $logClient = ENV::systemGet('HTTP_REQUEST_LOG_CLIENT', false);
        $logServer = ENV::systemGet('HTTP_REQUEST_LOG_SERVER', false);
        $domain = $this->kernel->route->class;
        if ($domain) {
            $logParams = ENV::final($domain, 'HTTP_REQUEST_LOG_PARAMS', false);
            $logHeaders = ENV::final($domain, 'HTTP_REQUEST_LOG_HEADERS', false);
        }

        $log = [];
        if ($logParams) {
            $params = $this->getAll();
            $maskKeys = INI::final($domain, 'HTTP_REQUEST_PARAMS_MASK_KEYS', ['password', 'secret']);

            foreach ($params as $key => &$val) {
                if (IS::ciin($key, $maskKeys)) {
                    $val = '*';
                }
            }

            $log[0] = $params;
        }
        if ($logClient) {
            $log[1] = [
                $this->getClientName(),
                $this->getClientOS(),
                $this->getClientIP(),
                $this->getClientPort(),
            ];
        }
        if ($logServer) {
            $log[2] = [
                $_SERVER['USER'] ?? null,
                $_SERVER['SERVER_NAME'] ?? null,
                $_SERVER['SERVER_ADDR'] ?? null,
                $_SERVER['SERVER_PORT'] ?? null,
                \getmypid(),
                // $_SERVER['REQUEST_SCHEME'] ?? null,
                $_SERVER['SERVER_SOFTWARE'] ?? null,
                $_SERVER['GATEWAY_INTERFACE'] ?? null,
            ];
        }
        if ($logHeaders) {
            if (\is_array($logHeaders)) {
                $headers = $this->getHeaders();
                foreach ($logHeaders as $header) {
                    if (! \is_string($header)) {
                        continue;
                    }
                    if ($_header = ($headers[$header] ?? false)) {
                        $log[3][$header] = $_header;
                    }
                }
            } else {
                $log[3] = $this->getHeaders();
            }
        }
        if ($log) {
            $context[] = $log;
        }

        return $context;
    }
}
