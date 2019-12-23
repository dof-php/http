<?php

declare(strict_types=1);

namespace DOF\HTTP;

use Throwable;
use DOF\DOF;
use DOF\DMN;
use DOF\INI;
use DOF\ENV;
use DOF\I18N;
use DOF\Convention;
use DOF\ErrManager;
use DOF\Traits\Tracker;
use DOF\Util\IS;
use DOF\Util\Arr;
use DOF\Util\Str;
use DOF\Util\Num;
use DOF\Util\JSON;
use DOF\Util\Format;
use DOF\Util\Wrapper;
use DOF\Util\TypeHint;
use DOF\Util\TypeCast;
use DOF\Util\Exceptor;
use DOF\HTTP\Exceptor\ResponseExceptor;

abstract class Response
{
    use Tracker;
    
    protected $kernel;

    /** @var bool: Reponse was sent or not */
    protected $sent = false;

    /** @var bool: Response error status, from code pint of view */
    protected $error = false;

    /** @var int: HTTP response status code */
    protected $status = 200;

    /** @var string: Content-Type header of HTTP response */
    protected $mime = 'text/html';

    /** @var string: Charset of HTTP response content */
    protected $charset = 'UTF-8';

    /** @var array: Headers of HTTP response */
    protected $headers = [];

    /** @var mixed|stringable: HTTP response body content */
    protected $body;

    /** @var array: Response body data structure elements candidates (KV) */
    protected $context = [
        // 'wrapout' => [],
        // 'wraperr' => [],
        // 'logging' => [],
    ];

    public function __construct(Kernel $kernel)
    {
        $this->kernel = $kernel;
    }

    public function __destruct()
    {
        if (! $this->sent) {
            $this->send();
        }
    }

    public function redirect(string $url, int $code = 302)
    {
        $this->setStatus($code);

        if (! $this->isRedirection()) {
            // TODO: reset headers
            throw new ResponseExceptor('INVALID_HTTP_REDIRECTION_CODE', \compact('code'));
        }

        $this->removeHeader('cache-control');
        $this->header('location', $url);

        $this->setBody(\sprintf('<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8" />
        <meta http-equiv="refresh" content="0;url=%1$s" />
        <title>Redirecting to %1$s</title>
    </head>
    <body>
        Redirecting to <a href="%1$s">%1$s</a>.
    </body>
</html>', \htmlspecialchars($url, ENT_QUOTES, 'UTF-8')));

        $this->send();

        return $this;
    }

    // Abort response directly without logging anything
    // For scenarios like client make primary mistakes and no need to log into server
    // For example, ROUTE_NOT_FOUND errors might be huge and meanless, it's unnecessary to log
    final public function abort(int $status, ...$params)
    {
        $exceptor = new ResponseExceptor(...$params);
        $exceptor
            ->setProxy(true)
            ->tag(Exceptor::TAG_CLIENT)
            ->tag('stderr', 'abort')
            ->setChain(\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
 
        $this->stderr($exceptor, $status);

        return $this;
    }

    // Everything like abort() except fail() will log into server
    final public function fail(int $status, ...$params)
    {
        $exceptor = new ResponseExceptor(...$params);
        $exceptor
            ->setProxy(true)
            ->tag(Exceptor::TAG_CLIENT)
            ->tag('logging', 'fail')
            ->setChain(\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
 
        $this->stderr($exceptor, $status);

        return $this;
    }

    final public function err(array $err, ...$params)
    {
        $code = $err[0] ?? -1;
        $info = $err[1] ?? Convention::DEFAULT_ERR;

        $exceptor = new ResponseExceptor(...$params);
        $exceptor
            ->setProxy(true)
            ->tag(Exceptor::TAG_CLIENT)
            ->tag('logging', 'err')
            ->setNo($code)
            ->setInfo($info)
            ->setChain(\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));

        $this->stderr($exceptor, $this->code2status($code, 500));

        return $this;
    }

    // Server side exception with logging
    final public function exceptor(...$params)
    {
        $exceptor = new ResponseExceptor(...$params);
        $exceptor
            ->setProxy(true)
            ->tag('logging', 'exception')
            ->setChain(\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));

        $this->stderr($exceptor, 500);

        return $this;
    }

    private function stderr(ResponseExceptor $exceptor, int $status = 500)
    {
        $domain  = $this->kernel->route->class;
        $logging = $exceptor->getTag('logging');

        // Previous exceptor check (only last)
        if (($previous = $exceptor->getPrevious()) && ($previous instanceof Exceptor)) {
            $exceptor = $previous;
        }

        // Route errs customization check
        if ($exceptor->hasTag(Exceptor::TAG_CLIENT)) {
            $code = $exceptor->getNo();
            $lang = $this->kernel->language();
            $name = $exceptor->getName();
            if ($_err = ErrManager::get($code)) {
                $info = $name;
                $name = $_err[2] ?? $name;
                $status = $this->code2status($code, $status, true);
                $logging = 'err';
            } else {
                $info = $exceptor->getInfo();
            }

            $data = ['info' => $info, 'more' => $exceptor->getContext()];
            if (($errs = $this->kernel->route->getErrs()) && ($err = ($errs[$code] ?? null))) {
                $status = $this->code2status(($err['status'] ?? null), $status);
                $name = $err['info'] ?? $name;
                $code = $err['code'] ?? $code;
                $logging = 'err';
            }

            // ignore client side error coz it might be huge
            $logging = ($status >= 500) ? $logging : null;
            $name = I18N::active($lang, $domain) ? I18N::get($domain, $name, $lang) : $name;
        } else {
            $data = Format::throwable($exceptor);
            $code = $data['code'] ?? -1;
            $name = $data['name'] ?? Convention::DEFAULT_ERR;
        }

        $body = [$code, $name, [$this->uuid()]];
        if (ENV::systemGet('HTTP_DEBUG', false)) {
            $body[2][] = $data;
        }

        if ($domain) {
            $wrapper = $this->kernel->port->annotation('WRAPERR', INI::final($domain, 'domain', Convention::OPT_HTTP_WRAPERR));
        } else {
            $wrapper = INI::systemGet('domain', Convention::OPT_HTTP_WRAPERR);
        }

        $body = $this->wraperr($body, $wrapper);

        $this->setMime($this->mimeout());
        $this->setBody($body);
        $this->setStatus($status);

        $this->kernel->stderr = [$status, $exceptor->getTag('stderr', $logging)];
        if ($logging) {
            $this->logger()->log($logging, $this->kernel->sapi(), [$this->kernel->stdin, $data]);
        }

        $this->send();
    }

    final public function throw(Throwable $th, int $status = 500)
    {
        $this->stderr(new ResponseExceptor($th), $status);

        return $this;
    }

    public function code2status($code, int $status, bool $auto = false) : int
    {
        if (TypeHint::uint($code)) {
            if ($auto && ($err = ErrManager::get($code)) && ($class = ($err[0] ?? null)) && (! IS::empty($no = DMN::meta($class, 'no')))) {
                $_status = Str::first(Str::shift(\strval($code), $no), 3);
            } else {
                $_status = Str::first(\strval($code), 3);
            }

            if (Num::between($_status, 100, 999)) {
                return \intval($_status);
            }
        }

        return $status;
    }

    abstract public function send() : void;

    /**
     * Get current HTTP response headers
     */
    public function headers() : array
    {
        if ($this->mime) {
            $this->headers['content-type'] = "{$this->mime}; charset={$this->charset}";
        } elseif ($this->alias && ($mime = MIME::mime($alias))) {
            $this->headers['content-type'] = "{$mime}; charset={$this->charset}";
        }

        return $this->headers;
    }

    public function mimeout() : string
    {
        $suffix = $this->kernel->route->suffix;
        if ($suffix && ($mime = MIME::mime($suffix))) {
            return $mime;
        }

        $mimeout = $this->kernel->port->annotation('MIMEOUT', Convention::DEFAULT_MIMEOUT);

        return MIME::mime($mimeout);
    }

    public function wrapout($result, $wrapper = null)
    {
        if (\is_string($wrapper)) {
            $wrapper = Wrapper::out($wrapper);
        }
        if ((! $wrapper) || (! \is_array($wrapper))) {
            return $result;
        }

        $data = [];

        foreach ($wrapper as $key => $default) {
            if ($key === '__DATA__') {
                $data[$default] = \is_object($result) ? Format::scalar($result) : $result;
                continue;
            }
            if ($key === '__PAGINATOR__') {
                $data[$default] = $this->getContext('wrapout', 'paginator');
                continue;
            }
            if ($key === '__INFO__') {
                $data[$default] = $this->getContext('wrapout', 'info', 'ok');
                continue;
            }
            if ($key === '__CODE__') {
                $data[$default] = $this->getContext('wrapout', 'code', 0);
                continue;
            }

            $_key = \is_int($key) ? $default : $key;
            $val  = $this->getContext('wrapout', $_key);
            $_val = \is_null($val) ? (\is_int($key) ? null : $default) : $val;

            $data[$_key] = $_val;
        }

        return $data;
    }

    public function wraperr($result, $wrapper = null)
    {
        if (\is_string($wrapper)) {
            $wrapper = Wrapper::err($wrapper);
        }
        if ((! $wrapper) || (! \is_array($wrapper))) {
            return $result;
        }

        return Format::wrap($result, $wrapper, function ($key) {
            return $this->getContext('wraperr', $key);
        });
    }

    public function stringify($value) : string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_array($value)) {
            return MIME::encode($value, $this->mime, false);
        }
        if (IS::empty($value)) {
            return '';
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return $this->exceptor('UNSTRINGIFIABLE_VALUE', ['type' => \gettype($value)]);
    }

    public function isRedirection() : bool
    {
        return \in_array($this->status, [301, 302, 307, 308]);
    }

    public function getContext(string $type = null, string $key = null, $default = null)
    {
        if (! $type) {
            return $this->context;
        }

        return Arr::get(\join('.', [$type, $key]), $this->context, $default);
    }

    public function setContext(array $context)
    {
        $this->context = $context;

        return $this;
    }

    public function addContext(string $type, string $key, $value)
    {
        $this->context[$type][$key] = $value;

        return $this;
    }

    public function appendContext(string $type, string $key, $value)
    {
        $this->context[$type][$key][] = $value;

        return $this;
    }

    /**
     * Getter for status
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }
    
    /**
     * Setter for status
     *
     * @param int $status
     * @return Response
     */
    public function setStatus(int $status)
    {
        $this->status = $status;
    
        return $this;
    }

    /**
     * Getter for body
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }
    
    /**
     * Setter for body
     *
     * @param mixed $body
     * @return Response
     */
    public function setBody($body)
    {
        $this->body = $body;
    
        return $this;
    }

    /**
     * Getter for error
     *
     * @return bool
     */
    public function getError(): bool
    {
        return $this->error;
    }
    
    /**
     * Setter for error
     *
     * @param bool $error
     * @return Response
     */
    public function setError(bool $error)
    {
        $this->error = $error;
    
        return $this;
    }

    public function hasHeader(string $key) : bool
    {
        return \array_key_exists(\strtolower($key), $this->headers);
    }

    public function removeHeader(string $key)
    {
        unset($this->headers[\strtolower($key)]);

        return $this;
    }

    /**
     * Set one HTTP header item
     */
    public function header(string $key, string $value)
    {
        $this->headers[\strtolower($key)] = $value;

        return $this;
    }

    public function setMimeAlias(string $alias)
    {
        $this->mime = MIME::mime($alias, 'text/html');

        return $this;
    }

    public function getMimeAlias() : string
    {
        return MIME::alias($this->mime, 'html');
    }

    /**
     * Getter for mime
     *
     * @return string
     */
    public function getMime()
    {
        return $this->mime;
    }
    
    /**
     * Setter for mime
     *
     * @param string $mime
     * @return Response
     */
    public function setMime(string $mime)
    {
        $this->mime = $mime;
    
        return $this;
    }

    /**
     * Getter for charset
     *
     * @return string
     */
    public function getCharset()
    {
        return $this->charset;
    }
    
    /**
     * Setter for charset
     *
     * @param string $charset
     * @return Response
     */
    public function setCharset(string $charset)
    {
        $this->charset = $charset;
    
        return $this;
    }

    /**
     * Getter for headers
     *
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * Setter for headers
     *
     * @param array $headers
     * @return Response
     */
    public function setHeaders(array $headers)
    {
        foreach ($headers as $key => $val) {
            $this->header(TypeCast::string($key), TypeCast::string($val));
        }

        return $this;
    }
}
