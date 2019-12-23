<?php

declare(strict_types=1);

namespace DOF\HTTP\Preflight;

use DOF\ENV;
use DOF\Util\IS;

/**
 * CORS control at server side
 * 
 * See: <https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS>
 */
class CORS
{
    public function preflight($request, $response)
    {
        // NOTES: It's might not working if your web server has limited reqeust methods before php
        $headers = [
            'Access-Control-Allow-Origin'   => \join(',', $this->getAccessControlAllowOrigin()),
            'Access-Control-Expose-Headers' => \join(',', $this->getAccessControlExposeHeaders()),
            'Access-Control-Allow-Methods'  => \join(',', $this->getAccessControlAllowMethods()),
            'Access-Control-Allow-Headers'  => \join(',', $this->getAccessControlAllowHeaders()),
            'Access-Control-Max-Age' => $this->getAccessControlMaxAge(),
            'Access-Control-Allow-Credentials' => $this->getAccessControlAllowCredentials(),
        ];

        if ($request->getVerb() === 'OPTIONS') {
            return $response
                ->setMimeAlias('text')
                ->setBody(null)
                ->setStatus(204)
                ->setError(false)
                ->setHeaders($headers)
                ->send();
        }

        $response->setHeaders($headers);
    }

    public function getAccessControlAllowCredentials() : string
    {
        return IS::confirm(ENV::systemGet('CORS_ACCESS_CONTROL_ALLOW_CREDENTIALS', true)) ? 'true' : 'no';
    }

    private function getAccessControlAllowOrigin() : array
    {
        return ENV::systemGet('CORS_ACCESS_CONTROL_ALLOW_ORIGIN', ['*']);
    }

    private function getAccessControlExposeHeaders() : array
    {
        return ENV::systemGet('CORS_ACCESS_CONTROL_EXPOSE_HEADERS', []);
    }

    private function getAccessControlAllowHeaders() : array
    {
        return ENV::systemGet('CORS_ACCESS_CONTROL_ALLOW_HEADERS', [
            '*',
            'Access-Control-Allow-Origin',
            'AUTHORIZATION',
            'Content-Type',
            'Accept',
        ]);
    }

    private function getAccessControlAllowMethods() : array
    {
        return ENV::systemGet('CORS_ACCESS_CONTROL_ALLOW_METHODS', [
            '*',
            'OPTIONS',
            'GET',
            'HEAD',
            'POST',
            'PATCH',
            'PUT',
            'DELETE',
            // 'CONNECT',
            // 'TRACE',
        ]);
    }

    private function getAccessControlMaxAge() : int
    {
        return ENV::systemGet('CORS_ACCESS_CONTROL_MAX_AGE', 604800);
    }
}
