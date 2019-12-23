<?php

declare(strict_types=1);

namespace DOF\HTTP\Pipe;

use DOF\INI;

/**
 * Http cache content negotiation
 * TODO
 */
class HttpCaching
{
    public function pipein($request, $response, $route, $port)
    {
        $class  = $route->get('class');
        $enable = INI::final($class, 'http.cache.negotiation', false);
        if (! $enable) {
            return true;
        }

        $method = $route->get('method');
        $verb = $request->getMethod();
        $_key = \md5(\join('@', $class, $method));

        if ($etag = \str_replace('"', '', $request->getHeader('If-None-Match'))) {
            $key = 'response:etag_'.$_key.$etag;
            $val = 1;    // TODO
            if (! \is_null($val)) {
                return $response->setBody($val)->setStatus(304)->send();
            }
        }

        if ($lastModified = $request->getHeader('If-Modified-Since')) {
            $key = 'response:last_modified_'.$_key.$lastModified;
            $val = 1;    // TODO
            if (! \is_null($val)) {
                return $response->setBody($val)->setStatus(304)->send();
            }
        }

        return true;
    }
}
