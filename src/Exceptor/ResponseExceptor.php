<?php

declare(strict_types=1);

namespace DOF\HTTP\Exceptor;

use DOF\Util\Exceptor;

class ResponseExceptor extends Exceptor
{
    public $advices = [
        'INVALID_HTTP_REDIRECTION_CODE' => 'Allowed: 301,302,307,308',
    ];
}
