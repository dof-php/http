<?php

declare(strict_types=1);

namespace DOF\HTTP;

use Closure;
use DOF\ENV;
use DOF\Traits\Tracker;
use DOF\Util\IS;
use DOF\Util\Arr;

class Request
{
    use Tracker;
    
    protected $kernel;
    private $data = [];

    public function __construct(Kernel $kernel)
    {
        $this->kernel = $kernel;
        $this->kernel->stdin = [$this->getVerb(), $this->getPath()];
    }

    public function language(string $default = null)
    {
        $lang = $this->all(ENV::final($this->kernel->route->class, 'LANG_HTTP_PARAM', '__language'));
        if (\is_string($lang)) {
            return $lang;
        }

        $lang = $this->getHeader(ENV::final($this->kernel->route->class, 'LANG_HTTP_HEADER', 'LANGUAGE'));

        return \is_string($lang) ? $lang : $default;
    }

    public function all(string $key = null, $default = null)
    {
        if (IS::empty($key)) {
            return $this->getAll();
        }

        return Arr::get($key, $this->getAll(), $default);
    }

    public function input(string $key = null, $default = null)
    {
        $input = $this->getInput();

        return $key ? (\is_array($input) ? Arr::get($key, $input, $default) : null) : $input;
    }

    public function post(string $key = null, $default = null)
    {
        return $key ? Arr::get($key, $this->getPost(), $default) : $this->getPost();
    }

    public function get(string $key = null, $default = null)
    {
        return $key ? Arr::get($key, $this->getGet(), $default) : $this->getGet();
    }

    /**
     * Match a list of field and return if found the first one
     *
     * @param array $keys: Fields list to match aginst
     * @param string $_key: The first matched field name
     * @return mixed|null
     */
    public function match(array $keys = [], $default = null, string &$key = null)
    {
        return Arr::match($keys, $this->getAll(), $default, $key);
    }

    public function only(...$keys) : array
    {
        $params = $keys;
        $cnt = \count($keys);
        if ($cnt === 1 && \is_array($_keys = ($keys[0] ?? false))) {
            $params = $_keys;
        }
        if (! $params) {
            return [];
        }

        $all = $this->getAll();
        $res = [];
        foreach ($params as $key) {
            if (! \is_string($key)) {
                continue;
            }
            if (\array_key_exists($key, $all)) {
                $res[$key] = $all[$key];
            }
        }

        return $res;
    }

    public function isNotGet() : bool
    {
        return $this->getVerb() !== 'GET';
    }

    public function isGet() : bool
    {
        return $this->getVerb() === 'GET';
    }

    public function isNotPost() : bool
    {
        return $this->getVerb() !== 'POST';
    }

    public function isPost() : bool
    {
        return $this->getVerb() === 'POST';
    }

    public function isMimeAlias(string $alias) : bool
    {
        $mime = $this->getMime();
        if (! $mime) {
            return false;
        }

        $_mime = (MIME::A2C[$alias] ?? null);
        if (! $_mime) {
            return false;
        }

        if ($this->getMimeShort() === $_mime) {
            return true;
        }

        return false;
    }

    public function hasHeader(string $keyupper) : bool
    {
        return isset($this->getHeaders()[\strtoupper($keyupper)]);
    }

    public function getHeader(string $keyupper) : ?string
    {
        $keyupper = \strtoupper($keyupper);
        $headers  = $this->getHeaders();

        return $headers[$keyupper] ?? ($headers[\str_replace('-', '_', $keyupper)] ?? null);
    }

    public function getMime() : ?string
    {
        $mime = $this->getMimeShort();

        return ($this->isNotGet() && (! $mime)) ? 'form' : $mime;
    }

    public function getMimeShort() : ?string
    {
        if (! ($mime = $this->getMimeLong())) {
            return null;
        }
        $mime  = \explode(';', $mime);
        $short = $mime[0] ?? false;

        return ($short === false) ? null : \trim($short);
    }

    public function getDomain() : ?string
    {
        return $this->getOrSet('domain', function () {
            $host = $this->getHost();
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                return $host;
            }
            $arr = \explode('.', $host);
            $cnt = \count($arr);
            if ($cnt === 1) {
                return $host;
            }

            $domain = '';
            for ($i = $cnt - 1; $i >= 0; --$i) {
                if ($i == ($cnt - 1)) {
                    $domain = $arr[$i];
                    continue;
                }
                if ($i == ($cnt - 2)) {
                    $domain = $arr[$i].'.'.$domain;
                    continue;
                }

                break;
            }

            return $domain;
        });
    }

    public function getUrl() : string
    {
        return $this->getOrSet('url', function () {
            return \sprintf('%s://%s%s', $this->getScheme(), $this->getHost(), $this->getRequestUri());
        });
    }

    public function getUri() : string
    {
        return $this->getOrSet('uri', function () {
            $uri = $this->getUriRaw();
            $uri = \join('/', Str::arr($uri, '/'));

            return $uri ?: '/';
        });
    }

    public function getUriRaw() : string
    {
        return $this->getOrSet('uri_raw', function () {
            $uri = $this->getRequestUri();
            $uri = (string) parse_url("http://dof{$uri}", PHP_URL_PATH);

            return $uri;
        });
    }

    public function getPath() : ?string
    {
        return $this->getUriRaw();
    }

    public function getVerb() : ?string
    {
        return \strtoupper($this->getMethod() ?? '');
    }

    public function getInput()
    {
        return $this->getOrSet('input', function () {
            return MIME::decode($this->getInputRaw(), $this->getMimeAlias());
        });
    }

    public function getMimeAlias() : ?string
    {
        $mime = $this->getMime();

        return $mime ? MIME::alias($mime) : null;
    }

    protected function getOrSet(string $key, Closure $callback)
    {
        $value = $this->data[$key] ?? null;

        if (\is_null($value)) {
            $value = $callback();
            $this->data[$key] = $value;
        }

        return $value;
    }
}
