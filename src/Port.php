<?php

declare(strict_types=1);

namespace DOF\HTTP;

use DOF\Util\Annotation;
use DOF\Util\Collection;

class Port extends Collection
{
    public function __construct()
    {
        parent::__construct([], $this);
    }

    public function doc(string $key, $value)
    {
        $doc = $this->get('doc');
        $doc[\strtoupper($key)] = $value;
        $this->set('doc', $doc);

        return $this;
    }

    public function annotation(string $key = null, $default = null, bool $extra = false)
    {
        $doc = parent::get('doc');
        if (\is_null($key)) {
            return $doc;
        }

        if (! $doc) {
            return $default;
        }

        $key = \strtoupper($key);

        if ($extra) {
            return $doc[Annotation::EXTRA_KEY][$key] ?? $default;
        }

        return $doc[$key] ?? $default;
    }
}
