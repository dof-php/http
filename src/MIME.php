<?php

declare(strict_types=1);

namespace DOF\HTTP;

use Throwable;
use DOF\Util\JSON;
use DOF\Util\XML;
use DOF\HTTP\Exceptor\RequestExceptor;

class MIME
{
    // Alias to Content-Type
    const A2C = [
        'text' => 'text/plain',
        'html' => 'text/html',
        'view' => 'text/html',
        'json' => 'application/json',
        'xml'  => 'application/xml',
        'form' => 'application/x-www-form-urlencoded',
    ];

    // Content-Type to Alias
    const C2A = [
        'text/plain' => 'text',
        'text/html'  => 'html',
        'application/json' => 'json',
        'application/xml'  => 'xml',
        'application/x-www-form-urlencoded' => 'form',
    ];

    public static function mimes() : array
    {
        return \array_keys(MIME::C2A);
    }

    public static function aliases() : array
    {
        return \array_keys(MIME::A2C);
    }

    /**
     * Get alias by MIME
     *
     * @param string $mime: MIME
     * @param string|null $default: Default alias when given mime not exists
     */
    public static function alias(string $mime, string $default = null) : ?string
    {
        return MIME::C2A[$mime] ?? $default;
    }

    /**
     * Get MIME by alias
     *
     * @param string $alias: MIME alias
     * @param string|null $default: Default MIME when given alias not exists
     */
    public static function mime(string $alias, string $default = null) : ?string
    {
        return MIME::A2C[$alias] ?? $default;
    }

    public static function encode(array $data, string $mime, bool $alias = true) : string
    {
        if (! $alias) {
            $mime = MIME::alias($mime, 'json');
        }

        switch ($mime) {
            case 'xml':
                return XML::encode($data);
            case 'text':
            case 'html':
            case 'view':
            case 'json':
            default:
                return JSON::encode($data);
        }
    }

    public static function decode(string $input = null, string $alias = null)
    {
        if (\is_null($input) || \is_null($alias)) {
            return $input;
        }

        try {
            if ($alias === 'json') {
                $data = JSON::decode($input, true);
                return \is_array($data) ? $data : [];
            }
            if ($alias === 'xml') {
                $data = XML::decode($input, true);
                return \is_array($data) ? $data : [];
            }
            if ($alias === 'form') {
                $data = [];
                \parse_str($input, $data);
                return $data;
            }
        } catch (Throwable $th) {
            throw new RequestExceptor('INVALID_REQUEST_MIME_BODY', ['data' => $input, 'mime' => $alias], $th);
        }

        return $input;
    }
}
