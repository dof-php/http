<?php

declare(strict_types=1);

namespace DOF\HTTP\Test;

use DOF\HTTP\Response;

class DummyResponse extends Response
{
    public function send() : void
    {
        // nothing to do
    }
}
