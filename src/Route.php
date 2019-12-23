<?php

declare(strict_types=1);

namespace DOF\HTTP;

use DOF\Util\Collection;

class Route extends Collection
{
    private $errs;
    private $pipein;

    public function __construct()
    {
        parent::__construct([], $this);

        $this->pipein = new Collection([], $this);
    }

    public function arguments()
    {
        return new Collection($this->arguments);
    }

    public function setPipein(string $namespace, $data)
    {
        $this->pipein->set($namespace, $data);

        return $this;
    }

    public function getPipein(string $namespace)
    {
        return $this->pipein->get($namespace);
    }

    public function hasPipein(string $namespace) : bool
    {
        return $this->pipein->has($namespace);
    }

    public function err(
        array $err,
        int $status = null,
        string $info = null,
        int $code = null
    ) {
        $no = $err[0] ?? -1;

        if (! \is_null($status)) {
            $this->errs[$no]['status'] = $status;
        }
        if (! \is_null($info)) {
            $this->errs[$no]['info'] = $info;
        }
        if (! \is_null($code)) {
            $this->errs[$no]['code'] = $code;
        }

        return $this;
    }

    public function getErrs()
    {
        return $this->errs;
    }
}
