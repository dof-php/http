<?php

declare(strict_types=1);

namespace DOF\HTTP;

use Throwable;
use DOF\ENV;
use DOF\INI;
use DOF\DOF;
use DOF\Convention;
use DOF\KernelInitializer;
use DOF\Util\F;
use DOF\Util\IS;
use DOF\Util\FS;
use DOF\Util\Arr;
use DOF\Util\JSON;
use DOF\Util\Format;
use DOF\Util\Exceptor\ValidationFailure;
use DOF\HTTP\HTTPPortManager;
use DOF\HTTP\WrapInManager;
use DOF\HTTP\Exceptor\HTTPKernelExceptor;

class Kernel extends KernelInitializer
{
    /** @var DOF\HTTP\Request */
    protected $request;

    /** @var DOF\HTTP\Response */
    protected $response;

    /** @var DOF\HTTP\Route */
    public $route;

    /** @var DOF\HTTP\Port */
    public $port;

    public function __construct(string $sapi = PHP_SAPI)
    {
        parent::__construct(\join('_', ['http', $sapi]));

        $this->route = new Route;
        $this->port = new Port;
    }

    public function execute()
    {
        try {
            if ($this->preflight() === $this->response) {
                return;
            }
        } catch (Throwable $th) {
            $this->response->exceptor('PREFLIGHT_EXCEPTION', $th);
            return;
        }

        try {
            if ($this->routing() === $this->response) {
                return;
            }
        } catch (Throwable $th) {
            $this->response->exceptor('ROUTING_EXCEPTION', $th);
            return;
        }

        try {
            if ($this->pipingin() === $this->response) {
                return;
            }
        } catch (Throwable $th) {
            $this->response->exceptor('PIPINGIN_EXCEPTION', $th);
            return;
        }

        try {
            if ($this->validate() === $this->response) {
                return;
            }
        } catch (Throwable $th) {
            $this->response->exceptor('WRAPPINGIN_EXCEPTION', $th);
            return;
        }

        try {
            if (($parameters = $this->build()) === $this->response) {
                return;
            }
        } catch (Throwable $th) {
            $this->response->exceptor('BUILD_METHOD_PARAMETERS_FAILED', $th);
            return;
        }

        try {
            if (($result = $this->resulting($parameters)) === $this->response) {
                return;
            }
        } catch (Throwable $th) {
            $this->response->exceptor('RESULTING_RESPONSE_FAILED', $th);
            return;
        }

        try {
            $result = $this->pipingout($result);
        } catch (Throwable $th) {
            $this->response->exceptor('PIPINGOUT_EXCEPTION', $th);
            return;
        }

        try {
            $result = $this->packing($result);
        } catch (Throwable $th) {
            $this->response->exceptor('PACKING_RESULT_EXCPTION', $th);
            return;
        }

        try {
            $this->terminate();
        } catch (Throwable $th) {
            $this->logger()->log(
                'HTTP_KERNEL_TERMINATE_EXCEPTION',
                \join('@', [$this->route->class, $this->route->method]),
                Format::throwable($th)
            );
        }

        try {
            $this->response
                 ->setMime($this->response->mimeout())
                 ->setStatus($this->port->annotation('STATUSOK', 200))
                 ->setBody($result)
                 ->send();
        } catch (Throwable $th) {
            $this->response->exceptor('RESPONSE_SENDING_FAILED', $th);
        }
    }

    /**
     * Processing of listeners of web kernel terminated event
     *
     * SHOULD NOT throw exceptions/errors to break off normall http response
     */
    private function terminate()
    {
        // TODO
    }

    /**
     * Package response result with given wrapper (if exists)
     *
     * @param mixed $result: result data to response
     * @return $result: Packaged response result
     */
    private function packing($result = null)
    {
        if ($this->response->getError()) {
            return $this->response->wraperr($result, $this->port->annotation('WRAPERR'));
        }

        if ($codeok = $this->port->annotation('CODEOK')) {
            $this->response->addContext('wrapout', 'code', $codeok);
        }
        if ($infook = $this->port->annotation('INFOOK')) {
            $this->response->addContext('wrapout', 'info', $infook);
        }

        return $this->response->wrapout($result, $this->port->annotation('WRAPOUT'));
    }

    /**
     * Response result through port pipeouts defined in current route
     *
     * @param mixed $result
     * @return mixed Pipeouted response result
     */
    private function pipingout($result)
    {
        $pipeouts = $this->port->annotation('PIPEOUT');
        if (! $pipeouts) {
            return $result;
        }

        $nopipeouts = $this->port->annotation('NOPIPEOUT', []);

        $shouldPipeOutBeIgnored = function ($pipeout, $nopipeouts) : bool {
            foreach ($nopipeouts as $nopipeout) {
                if ($pipeout == $nopipeout) {
                    return true;
                }
            }

            return false;
        };

        foreach ($pipeouts as $pipeout) {
            if ($shouldPipeOutBeIgnored($pipeout, $nopipeouts)) {
                continue;
            }

            $result = \call_user_func_array(
                [$this->di($pipeout), Convention::HANDLER_PIPEOUT],
                [$result, $this->route, $this->port, $this->request, $this->response]
            );
            if ($result === $this->response) {
                return $this->response;
            }
        }

        return $result;
    }

    private function resulting(array $parameters)
    {
        return $this->new(
            $this->route->class,
            $this->route,
            $this->port,
            $this->request,
            $this->response
        )->{$this->route->method}(...$parameters);
    }

    /**
     * Build port method parameters from port method definition and route parameters
     */
    private function build() : array
    {
        return $this->completion($this->port->get('parameters', []), $this->route->get('parameters', []));
    }

    /**
     * Validate request body parameters against route/port definitions
     *
     * - either: wrapin check
     * - or: argument annotations check
     */
    private function validate()
    {
        $wrapin = $this->port->annotation('WRAPIN');
        $arguments = $this->port->arguments;

        $match = function ($keys, &$key, $annotations) {
            $location = $annotations['LOCATION'] ?? 'ALL';
            $location = \is_string($location) ? \strtoupper($location) : 'ALL';

            switch ($location) {
                case 'ROUTE':
                    // Check match in route parameters only
                    return Arr::match($keys, $this->route->parameters, null, $key);
                case 'QUERY':
                    // Check match in requested URL query string only
                    return Arr::match($keys, $this->request->get(), null, $key);
                case 'BODY':
                    // Check match in content-typed post/raw-request body
                    return Arr::match($keys, $this->request->body(), null, $key);
                case 'ALL':
                default:
                    // Finally we match in all kinds of parameters in current request
                    return Arr::match($keys, $this->request->all(), null, $key);
            }
        };

        // 1. Check wrapin setting on route annotation first
        // 2. Check arguments annotations from port method and port properties

        try {
            if ($wrapin) {
                $validator = WrapInManager::apply($wrapin, $match);
            } elseif ($arguments) {
                $validator = WrapInManager::validate($arguments, $this->route->class, $match);
            } else {
                $this->route->set('arguments', []);
                return;
            }

            $this->route->set('arguments', $validator->getResult());
        } catch (ValidationFailure $th) {
            return $this->response->fail(400, 'WRAPPIN_FAILED', $th);
        } catch (Throwable $th) {
            throw $th;
        }
    }

    /**
     * Request through port pipeins defined in current route
     */
    private function pipingin()
    {
        $pipeins = $this->port->annotation('PIPEIN');
        if ((! $pipeins) || (! \is_array($pipeins))) {
            return;
        }

        $nopipeins = $this->port->annotation('NOPIPEIN', []);

        $shouldPipeInBeIgnored = function ($pipein, $nopipeins) : bool {
            foreach ($nopipeins as $nopipein) {
                if ($pipein === $nopipein) {
                    return true;
                }
            }

            return false;
        };

        foreach ($pipeins as $pipein) {
            if ($shouldPipeInBeIgnored($pipein, $nopipeins)) {
                continue;
            }

            $result = \call_user_func_array([$this->di($pipein), Convention::HANDLER_PIPEIN], [
                $this->request,
                $this->response,
                $this->route,
                $this->port
            ]);

            if ($result === $this->response) {
                return $this->response;
            }
        }
    }

    /**
     * Routing logics
     *
     * 1. Find route definition by request information
     * 2. Validate request uri against route definition
     */
    private function routing()
    {
        $path = $this->request->getPath();
        $verb = $this->request->getVerb();

        list($route, $port) = HTTPPortManager::find($path, $verb);

        if (! $route) {
            return $this->response->abort(404, 'ROUTE_NOT_FOUND', \compact('verb', 'path'));
        }

        if (\is_file($lock = DOF::path(Convention::FLAG_HTTP_HALT))) {
            list($since, $message) = JSON::decode($lock, true, true);
            return $this->response->abort(503, $message, \compact('since'));
        }

        if (! $port) {
            throw new HTTPKernelExceptor('ROUTE_WITHOUT_PORT', \compact('verb', 'path', 'route'));
        }

        $this->route->setData($route);
        $this->port->setData($port);

        $mimein = $this->port->annotation('MIMEIN');
        $mime = $this->request->getMimeAlias();

        if ($mimein && ($mimein !== $mime)) {
            return $this->response->fail(400, 'INVALID_REQUEST_MIME', \compact('mimein', 'mime'));
        }
    }

    /**
     * Preflight stuffs before processing request
     */
    private function preflight()
    {
        $preflights = INI::systemGet('domain', Convention::OPT_HTTP_PREFLIGHT, []);
        if ((! $preflights) || (! \is_array($preflights))) {
            return;
        }

        $handler = Convention::HANDLER_PREFLIGHT;
        foreach ($preflights as $preflight) {
            if (! \class_exists($preflight)) {
                throw new HTTPKernelExceptor('PREFLIGHT_NOT_EXISTS', \compact('preflight'));
            }
            if (! \method_exists($preflight, $handler)) {
                throw new HTTPKernelExceptor('PREFLIGHT_HANDLER_NOT_EXISTS', \compact('preflight', 'handler'));
            }

            $result = \call_user_func_array([$this->di($preflight), $handler], [
                $this->request,
                $this->response
            ]);

            if ($result === $this->response) {
                return $this->response;
            }
        }
    }

    public function language()
    {
        if ($this->route->class) {
            return $this->language = $this->request->language(ENV::final($this->route->class, 'LANG_DEFAULT', 'en'));
        }

        return $this->language ?? 'en';
    }
}
