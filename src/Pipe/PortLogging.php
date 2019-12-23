<?php

declare(strict_types=1);

namespace DOF\HTTP\Pipe;

use DOF\Container;
use DOF\Convention;
// use DOF\Util\Arr;
use DOF\Util\JSON;

/**
 * Business custom logging logics
 */
class PortLogging
{
    public function shutdown($port, $route, $request, $response)
    {
        $logging = $port->annotation('LOGGING');
        if (! $logging) {
            return;
        }

        $arguments = $port->arguments();
        if ($arguments['password'] ?? false) {
            $arguments['password'] = '*';
        }
        if ($arguments['secret'] ?? false) {
            $arguments['secret'] = '*';
        }
        $masks = $port->annotation('LOGMASKKEY');
        if ($masks) {
            foreach ($masks as $key) {
                if ($argvs[$key] ?? false) {
                    $argvs[$key] = '*';
                }
            }
        }

        \call_user_func_array([Container::di($logging), Convention::LOGGING_HANDLER], [[
            'at' => (int) $request->getTime(),
            'api' => 'http',
            'title' => (string) $port->annotation('TITLE'),
            'operator_id' => (int) $route->context('__logging_operator_id__'),
            'operator_ids_forward' => (string) $route->context('__logging_operator_ids_forward__'),
            'action_type' => (string) $route->verb,
            'action_value' => (string) $route->urlpath,
            'action_params' => JSON::encode($route->parameters),
            'arguments' => JSON::encode($arguments),
            'class'  => (string) $route->class,
            'method' => (string) $route->method,
            'client_ip' => (string) $request->getClientIP(),
            'client_ips_forward' => (string) $request->getClientIPForward(),
            'client_os'  => (string) $request->getClientOS(),
            'client_name' => (string) $request->getClientName(),
            'client_info' => (string) $request->getClientUA(),
            'client_port' => (int) $request->getClientPort(),
            'server_ip' => (string) $response->getServerIP(),
            'server_os' => (string) $response->getServerOS(),
            'server_name' => (string) $response->getServerName(),
            'server_info' => (string) $response->getServerInfo(),
            'server_port' => (int) $response->getServerPort(),
            'server_status' => (int) $response->getStatus(),
            'server_error'  => (int) $response->getError(),
        ]]);
    }
}
