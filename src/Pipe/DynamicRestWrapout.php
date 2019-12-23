<?php

declare(strict_types=1);

namespace DOF\HTTP\Pipe;

use DOF\Util\Wrapper\ActionOnly;
use DOF\Util\Wrapper\Classic;
use DOF\Util\Wrapper\Http;

class DynamicRestWrapout
{
    /**
     * Select a wrapout according to the http verb
     *
     * !!! Warning: Using this pipein will not be documented
     */
    public function pipein($request, $response, $route, $port)
    {
        $verb = $request->getVerb();

        switch ($verb) {
            case 'PUT':
            case 'PATCH':
            case 'DELETE':
                $port->doc('WRAPOUT', ActionOnly::class);
                $port->doc('WRAPERR', ActionOnly::class);
                break;
            case 'GET':
            case 'POST':
            default:
                $port->doc('WRAPOUT', Http::class);
                $port->doc('WRAPERR', Http::class);
                break;
        }

        return true;
    }
}
