<?php

declare(strict_types=1);

namespace DOF\HTTP\Preflight;

use DOF\Surrogate\Log;
use DOF\Env;

class DebugLogging
{
    public function preflight($request, $response)
    {
        $header = Env::get('HTTP_DEBUG_HEADER', 'DOF_HTTP_DEBUG');
        if (! $request->hasHeader($header)) {
            return true;
        }
        $key = $request->getHeader($header);
        if (! $key) {
            return true;
        }

        if ($this->debugable($key)) {
            Log::setDebug(true)->setDebugKey($key);
        }

        return true;
    }

    protected function debugable(string $key) : bool
    {
        $config = Env::get('HTTP_DEBUG_LOGGING', []);

        return (bool) ($config[$key] ?? false);
    }
}
