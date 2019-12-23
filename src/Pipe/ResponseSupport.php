<?php

declare(strict_types=1);

namespace DOF\HTTP\Pipe;

use DOF\Util\IS;
use DOF\Util\Paginator;
// use DOF\DDD\Model;
use DOF\HTTP\Response;

/**
 * Recognize supported result types and set particular attributes to properties of current response
 */
class ResponseSupport
{
    public function pipeout($result, $route, $port, $request, $response)
    {
        if ($result instanceof Response) {
            return $result;
        }

        // Do not convert model object to data array here
        // coz it will disables some functionalities based on object when we using data assembling
        // if ($result instanceof Model) {
        //     return $result->__data__();
        // }

        if ($result instanceof Paginator) {
            $response->addContext('wrapout', 'paginator', $result->getMeta());

            // Should not return $result->getList() coz we may customize paginator later
            // Paginator is special, here we're not convert it into array unless it's empty
            return ($result->getCount() <= 0) ? [] : $result;
        }

        if (IS::duck($result, 'execute')) {
            return $result->execute();
        }

        return $result;
    }
}
