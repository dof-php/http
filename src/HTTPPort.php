<?php

declare(strict_types=1);

namespace DOF\HTTP;

use Closure;

use DOF\Traits\Tracker;

class HTTPPort
{
    use Tracker;

    /**
     * @Annotation(0)
     */
    private $__ROUTE__;

    /**
     * @Annotation(0)
     */
    private $__PORT__;

    /**
     * @Annotation(0)
     */
    private $__REQUEST__;

    /**
     * @Annotation(0)
     */
    private $__RESPONSE__;

    /**
     * @Annotation(0)
     * @NotRoute(1)
     * @NoDoc(1)
     */
    final public function __construct(
        Route $route,
        Port $port,
        Request $request,
        Response $response
    ) {
        $this->__ROUTE__ = $route;
        $this->__PORT__ = $port;
        $this->__REQUEST__ = $request;
        $this->__RESPONSE__ = $response;

        // auto assign properties
        if ($route->arguments) {
            foreach ($route->arguments as $key => $value) {
                if (\property_exists($this, $key)) {
                    // !!! Access level for auto-asign properties MUST NOT be private
                    $this->{$key} = $value;
                }
            }
        }
    }

    /**
     * @Annotation(0)
     * @NotRoute(1)
     * @NoDoc(1)
     */
    final protected function response(Closure $callback = null)
    {
        if ($callback) {
            return $callback($this->__RESPONSE__);
        }

        return $this->__RESPONSE__;
    }

    /**
     * @Annotation(0)
     * @NotRoute(1)
     * @NoDoc(1)
     */
    final protected function request(Closure $callback = null)
    {
        if ($callback) {
            return $callback($this->__REQUEST__);
        }

        return $this->__REQUEST__;
    }

    /**
     * @Annotation(0)
     * @NotRoute(1)
     * @NoDoc(1)
     */
    final protected function port(Closure $callback = null)
    {
        if ($callback) {
            return $callback($this->__PORT__);
        }

        return $this->__PORT__;
    }

    /**
     * @Annotation(0)
     * @NotRoute(1)
     * @NoDoc(1)
     */
    final protected function route(Closure $callback = null)
    {
        if ($callback) {
            return $callback($this->__ROUTE__);
        }

        return $this->__ROUTE__;
    }
}
