<?php

declare(strict_types=1);

namespace DOF\HTTP\Test;

use DOF\HTTP\Kernel;

class DummyKernel extends Kernel 
{
    public function __construct(string $sapi = PHP_SAPI)
    {
        parent::__construct($sapi);

        $this->unregister('before-shutdown');
        $this->unregister('shutdown');
    }
}
