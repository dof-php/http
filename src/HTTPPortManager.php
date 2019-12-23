<?php

declare(strict_types=1);

namespace DOF\HTTP;

use DOF\DMN;
use DOF\INI;
use DOF\Convention;
use DOF\Traits\Manager;
use DOF\DDD\Model;
use DOF\Util\IS;
use DOF\Util\FS;
use DOF\Util\Str;
use DOF\Util\Arr;
use DOF\Util\Num;
use DOF\Util\Format;
use DOF\Util\TypeCast;
use DOF\Util\TypeHint;
use DOF\Util\Reflect;
use DOF\Util\Annotation;
use DOF\HTTP\MIME;
use DOF\HTTP\HTTPPort;
use DOF\HTTP\Exceptor\HTTPPortManagerExceptor;

final class HTTPPortManager
{
    use Manager;

    /*
    protected static $data = [
        // Port definitions (One port can own multiple routes)
        'ports' => [],

        // Routes basic definitions (One route only belongs to one port)
        'routes' => [],

        // Port class properties
        'properties' => [],
    ];
    */

    /**
     * - Find definitions of current route by request uri and verb
     * - Find corresponding port if we found route
     * - Set uripath, suffix, params (etc) for route
     *
     * @param string $path: Request URL path
     * @param string $verb: HTTP verb
     * @return array|null
     */
    public static function find(string $path, string $verb) : array
    {
        $path = $path ? Format::route($path, '/') : null;
        if ((! $path) || (! $verb)) {
            return [null, null];
        }

        $route = self::$data['routes'][$path][$verb] ?? null;
        if ($route) {
            $route['urlpath'] = $path;

            return [$route, self::map($route)];
        }

        // Match resource suffix against MIME aliases
        $suffix = false;
        foreach (MIME::aliases() as $alias) {
            if (Str::end(".{$alias}", $path)) {
                $suffix = $alias;
                $path = Str::shift($path, ".{$alias}", true);
                $route = self::$data['routes'][$path][$verb] ?? null;
                if (! $route) {
                    break;
                }
                // If we found route but no corresponding port
                // Then just break the looping
                if (! ($port = self::map($route))) {
                    break;
                }
                if (\in_array($alias, ($port['SUFFIX'] ?? []))) {
                    $route['suffix'] = $alias;
                    $route['urlpath'] = $path;

                    return [$route, $port];
                }
            }
        }

        // Find route definition with route parameters
        $arr = $_arr = \array_reverse(\explode('/', $path));
        $cnt = \count($arr);
        $set = Arr::subsets($arr);
        foreach ($set as $replaces) {
            $arr = $_arr;
            $replaced = [];
            foreach ($replaces as $idx => $replace) {
                $replaced[] = $arr[$idx];
                $arr[$idx] = '?';
            }

            $try = \join('/', \array_reverse($arr));
            $route = self::$data['routes'][$try][$verb] ?? null;
            if (! $route) {
                continue;
            }
            if (! ($port = self::map($route))) {
                break;
            }
            if ($suffix) {
                if (! \in_array($suffix, ($port['doc']['SUFFIX'] ?? []))) {
                    break;
                }
                $route['suffix'] = $suffix;
            }

            $params = $route['parameters'] ?? [];
            if (\count($params) === \count($replaced)) {
                $params = \array_keys($params);
                $replaced = \array_reverse($replaced);
                $route['parameters'] = \array_combine($params, $replaced);
            }

            $route['urlpath'] = $path;

            return [$route, $port];
        }

        return [null, null];
    }

    public static function init()
    {
        foreach (INI::vendorGet() as $vendor => $item) {
            if ($ports = ($item['http-port'] ?? [])) {
                HTTPPortManager::addVendor($vendor, $ports);
            }
        }

        foreach (DMN::list() as $domain => $dir) {
            if (\is_dir($ports = FS::path($dir, Convention::DIR_HTTP, Convention::DIR_PORT))) {
                HTTPPortManager::addDomain($domain, $ports);
            }
        }
    }

    /**
      * Assemble routes definitions from class annotations
      *
      * @param array $ofClass: Annotations of class
      * @param array $ofProperties: Annotations of class properties
      * @param array $ofMethod: Annotations of methods
      * @return null
      */
    protected static function assemble(array $ofClass, array $ofProperties, array $ofMethods, string $type)
    {
        $port = $ofClass['namespace'] ?? null;
        if (! $port) {
            throw new HTTPPortManagerExceptor('CLASS_WITHOUT_NAMESPACE', \compact('ofClass'));
        }

        self::$data['properties'][$port] = $ofProperties;

        if (!\is_subclass_of($port, HTTPPort::class)) {
            throw new HTTPPortManagerExceptor('INVALID_HTTP_PORT', \compact('port'));
        }

        $handler = Convention::DEFAULT_HANDLER;
        if ($ofClass['doc']['AUTONOMY'] ?? false) {
            $ofHandler = $ofMethods[$handler] ?? null;
            if (! $ofHandler) {
                throw new HTTPPortManagerExceptor('AUTONOMY_PORT_HANDLER_MISSING', \compact('namespace', 'handler'));
            }

            self::append($ofClass, $ofHandler, $ofProperties);
            return;
        }

        foreach ($ofMethods as $method => $ofMethod) {
            self::append($ofClass, $ofMethod, $ofProperties);
        }
    }

    /**
     * Add one single route from port class annotations
     *
     * @param array $ofClass: the annotations of port class
     * @param array $ofMethod: the annotations of a port class method
     * @param array $ofProperties: the annotations of port class properties
     */
    private static function append(array $ofClass = [], array $ofMethod = [], array $ofProperties = [])
    {
        if (Str::start('_', $ofMethod['name'] ?? '')) {
            return;
        }
        if ($ofMethod['doc']['NOTROUTE'] ?? false) {
            return;
        }
        if ($ofClass['doc']['NOTROUTE'] ?? false) {
            return;
        }

        list($expression, $verbs, $route) = self::asmRoute($ofClass, $ofMethod);
        if ((! $expression) || (! $route) || (! $verbs)) {
            return;
        }

        foreach ($verbs as $verb) {
            $conflict = self::$data['routes'][$expression][$verb] ?? null;
            if ($conflict) {
                throw new HTTPPortManagerExceptor('DUPLICATED_HTTP_ROUTE', \compact('route', 'verb', 'conflict'));
            }

            self::$data['routes'][$expression][$verb] = $route;
        }

        self::asmPort($ofClass, $ofMethod, $ofProperties);
    }

    /**
     * Assemble port structure based on port class/method annotation and domain configs
     */
    private static function asmPort(array &$ofClass, array &$ofMethod, array &$ofProperties)
    {
        $class = $ofClass['namespace'] ?? null;
        $method = $ofMethod['name'] ?? null;
        if ((! $class) || (! $method)) {
            return;
        }
        if (! ($ofMethod['doc']['TITLE'] ?? null)) {
            throw new HTTPPortManagerExceptor('PORT_WITHOUT_TITLE', \compact('class', 'method'));
        }

        $domain = DMN::name($class, true);

        // multiple merge
        foreach ([
        ] as $annotation => $type) {
        }

        // multiple merge with domain local config and domain global config
        foreach ([
            'PIPEIN' => 'array',
            'PIPEOUT' => 'array',
        ] as $annotation => $type) {
            $_class = $ofClass['doc'][$annotation] ?? [];
            $_method = $ofMethod['doc'][$annotation] ?? [];

            $key = "http.port.{$annotation}";
            $local = INI::domainGet($domain, 'domain', $key, []);
            $global = INI::systemGet('domain', $key, []);
            if (! \is_array($local)) {
                throw new HTTPPortManagerExceptor('INVALID_PORT_ANNOTATION_LOCAL_TYPE', \compact('class', 'method', 'local', 'type', 'annotation'));
            }

            if (! \is_array($global)) {
                throw new HTTPPortManagerExceptor('INVALID_PORT_ANNOTATION_GLOBAL_TYPE', \compact('class', 'method', 'global', 'type', 'annotation'));
            }

            $ofMethod['doc'][$annotation] = Arr::union($_class, $_method, $local, $global);
        }

        // local multiple
        foreach ([
            // 'NOPIPEIN' => 'array',
            // 'NOPIPEOUT' => 'array',
        ] as $annotation => $type) {
        }

        // unique overwrite (default)
        foreach ([
            'VERSION' => 'string',
            'GROUP' => 'array',
            'SUFFIX' => 'array',
            'AUTH' => 'int',
            'STATUS' => 'string',
            'AUTHOR' => 'string',
            'MODEL' => 'namespace',
            'NODUMP' => 'string',
            'NODOC' => 'string',
            'ASSEMBLER' => 'namespace',
        ] as $annotation => $type) {
            $val = $ofMethod['doc'][$annotation] ?? null;
            if ($val === '_') {
                $ofMethod['doc'][$annotation] = null;
                continue;
            }

            $val = $ofMethod['doc'][$annotation] ?? ($ofClass['doc'][$annotation] ?? null);
            if (! \is_null($val)) {
                if (! TypeHint::typehint($type, $val)) {
                    throw new HTTPPortManagerExceptor('INVALID_PORT_ANNOTATION_TYPE', \compact('class', 'method', 'val', 'type', 'annotation'));
                }

                $val = TypeCast::typecast($type, $val);
            }

            $ofMethod['doc'][$annotation] = $val;
        }

        // unique overwrite method annotation nullable check, and domain local config and domain global config
        foreach ([
            'CODEOK' => 'int',
            'STATUSOK' => 'int',
            'INFOOK' => 'string',
            'WRAPOUT' => 'string',
            'WRAPERR' => 'string',
            'MIMEOUT' => 'string',
            'MIMEIN' => 'string',
        ] as $annotation => $type) {
            $val = $ofMethod['doc'][$annotation] ?? null;
            if ($val === '_') {
                $ofMethod['doc'][$annotation] = null;
                continue;
            }

            $key = "http.port.{$annotation}";
            $val = $ofMethod['doc'][$annotation] ?? (
                $ofClass['doc'][$annotation] ?? INI::final($domain, 'domain', $key)
            );
            if (! \is_null($val)) {
                if (! TypeHint::typehint($type, $val)) {
                    throw new HTTPPortManagerExceptor('INVALID_PORT_ANNOTATION_TYPE', \compact('class', 'method', 'val', 'type', 'annotation'));
                }

                $val = TypeCast::typecast($type, $val);
            }

            $ofMethod['doc'][$annotation] = $val;
        }

        foreach ($ofMethod['doc']['ARGUMENT'] ?? [] as $argument) {
            $property = $ofProperties[$argument] ?? null;
            if (! $property) {
                throw new HTTPPortManagerExceptor('PORT_ARGUMENT_UNDEFINED', \compact('class', 'argument'));
            }
            if (IS::empty($property['doc']['TITLE'] ?? null)) {
                throw new HTTPPortManagerExceptor('PORT_ARGUMENT_TITLE_MISSING', \compact('class', 'argument'));
            }
            if (IS::empty($type = ($property['doc']['TYPE'] ?? null))) {
                throw new HTTPPortManagerExceptor('PORT_ARGUMENT_TYPE_MISSING', \compact('class', 'argument'));
            }
            if (! TypeHint::support($type)) {
                throw new HTTPPortManagerExceptor('UNTYPEHINTABLE_VALUE', \compact('class', 'argument', 'type'));
            }

            // Append extra argument rule into property doc comment annotations
            $extra = $ofMethod['doc'][Annotation::EXTRA_KEY]['ARGUMENT'][$argument] ?? [];
            $need = false;
            foreach ($extra as $key => $val) {
                if (Str::start('_', $key)) {
                    continue;
                }

                $key = \strtoupper($key);
                if (Str::start('NEED', $key)) {
                    if ($need) {
                        throw new HTTPPortManagerExceptor('MULTIPLE_REQUIREMENT_RULES', \compact('key', 'class', 'method', 'argument'));
                    }

                    $need = true;
                    $item = Str::arr($key, ':');
                    $_key = $item[0] ?? null;
                    $_val = $item[1] ?? 1;
                    $property['doc'][$_key] = $_val;
                    $property['doc'][Annotation::EXTRA_KEY][$_key]['err'] = $val;
                } else {
                    $property['doc'][$key] = $val;
                }
            }
            if (false === $need) {
                $property['doc']['NEED'] = 1;
            }

            $ofMethod['arguments'][$argument] = $property;
        }

        self::$data['ports'][$class][$method] = $ofMethod;
    }

    /**
     * Assemble route structure based on port class/method annotation and domain configs
     */
    private static function asmRoute(array &$ofClass, array &$ofMethod) : array
    {
        // URL PATH
        $routeClass = $ofClass['doc']['ROUTE'] ?? null;
        $routeMethod = $ofMethod['doc']['ROUTE'] ?? null;
        if ((! $routeClass) && (! $routeMethod)) {
            return [null, null, null];
        }
        $extends = $ofMethod['doc'][Annotation::EXTRA_KEY]['ROUTE']['EXTENDS'] ?? true;
        if (! $extends) {
            $routeClass = null;
        }
        $version = $ofMethod['doc']['VERSION'] ?? ($ofClass['doc']['VERSION'] ?? null);
        $expression = Arr::str([$version, $routeClass, $routeMethod], '/');
        if (! $expression) {
            return [null, null, null];
        }

        $class = $ofClass['namespace'] ?? null;
        $method = $ofMethod['name'] ?? null;

        // VERBS
        $verbs = $ofMethod['doc']['VERB'] ?? $ofClass['doc']['VERB'];
        if (! $verbs) {
            throw new HTTPPortManagerExceptor('ROUTE_WITHOUT_VERB', \compact('class', 'method'));
        }

        $data = self::parseRoute($expression);
        $data['class'] = $class;
        $data['method'] = $method;

        $ofMethod['doc']['ROUTE'] = $data['definition'] ?? null;
        $ofMethod['doc']['VERB'] = $verbs;

        return [$expression, $verbs, $data];
    }

    /**
     * Parse route with route expression and route parameters
     *
     * @param string $route: Raw route from reqeust uri
     * @return array: A list with route expression and route parameters
     */
    public static function parseRoute(string &$expression) : array
    {
        $route = Format::route($expression, '/', false);
        $definition = $route ? \join('/', $route) : '/';
        $parameters = [];

        \array_walk($route, function (&$val, $key) use (&$parameters) {
            $matches = [];
            if (1 === \preg_match('#{([a-z]\w+)}#', $val, $matches)) {
                if ($param = ($matches[1] ?? false)) {
                    $parameters[$param] = null;
                    $val = '?';
                }
            }
        });
       
        $expression = $route ? \join('/', $route) : '/';

        return \compact('definition', 'parameters');
    }

    /**
     * Get port definitions by route definition
     *
     * @param array $route: $route item
     */
    public static function map(array $route) : ?array
    {
        $class  = $route['class']  ?? null;
        $method = $route['method'] ?? null;

        return self::$data['ports'][$class][$method] ?? null;
    }

    public static function getPorts() : array
    {
        return self::$data['ports'] ?? [];
    }

    public static function getRoutes() : array
    {
        return self::$data['routes'] ?? [];
    }

    public static function getProperties(string $port = null) : array
    {
        return \is_null($port) ? (self::$data['properties'] ?? []) : (self::$data['properties'][$port] ?? []);
    }

    public static function __annotationValueMODEL(string $model, string $port, &$multiple, &$strict)
    {
        if ($model === '_') {
            return '_';
        }

        $_model = Reflect::getAnnotationNamespace($model, $port);
        if ((! $_model) || (! \class_exists($_model))) {
            throw new HTTPPortManagerExceptor('CLASS_NOT_EXISTS', \compact('model', 'port'), 'PORT_MODEL');
        }
        if (! \is_subclass_of($_model, Model::class)) {
            throw new HTTPPortManagerExceptor('INVALID_DDD_MODEL', \compact('model', 'port'));
        }

        return $_model;
    }

    public static function __annotationValueCOMPATIBLE(string $compatible, string $port, &$multiple, &$strict)
    {
        $multiple = true;

        return Str::arr($compatible, ',');
    }

    public static function __annotationValueARGUMENT(string $argument, string $port, &$multiple, &$strict)
    {
        $multiple = true;

        if (! \property_exists($port, $argument)) {
            throw new HTTPPortManagerExceptor('PORT_ARGUMENT_UNDEFINED', \compact('argument'));
        }

        return $argument;
    }

    public static function __annotationValuePIPEOUT(string $pipeout, string $port, &$multiple, &$strict)
    {
        if ($pipeout === '_') {
            return '_';
        }

        $multiple = 'unique';

        $_pipeout = Reflect::getAnnotationNamespace($pipeout, $port);
        if ((! $_pipeout) || (! \class_exists($_pipeout))) {
            throw new HTTPPortManagerExceptor('PIPE_NOT_EXISTS', \compact('pipeout', 'port'));
        }
        if (! \method_exists($_pipeout, Convention::PIPEOUT_HANDLER)) {
            throw new HTTPPortManagerExceptor('PIPE_WITHOUT_HANDLER', \compact('pipeout', 'port'));
        }

        return $_pipeout;
    }

    public static function __annotationValueNOPIPEOUT(string $nopipeout, string $port, &$multiple, &$strict)
    {
        if ($nopipeout === '_') {
            return '_';
        }

        $multiple = 'unique';

        $_nopipeout = Reflect::getAnnotationNamespace($nopipeout, $port);
        if ((! $_nopipeout) || (! \class_exists($_nopipeout))) {
            throw new HTTPPortManagerExceptor('PIPE_NOT_EXISTS', \compact('nopipeout', 'port'));
        }
        if (! \method_exists($_nopipeout, Convention::PIPEOUT_HANDLER)) {
            throw new HTTPPortManagerExceptor('PIPE_WITHOUT_HANDLER', \compact('nopipeout', 'port'));
        }

        return $_nopipeout;
    }

    public static function __annotationValuePIPEIN(string $pipein, string $port, &$multiple, &$strict, array $ext)
    {
        if ($pipein === '_') {
            return '_';
        }

        $multiple = 'unique';

        $_pipein = Reflect::getAnnotationNamespace($pipein, $port);
        if ((! $_pipein) || (! \class_exists($_pipein))) {
            throw new HTTPPortManagerExceptor('PIPE_NOT_EXISTS', \compact('pipein', 'port'));
        }
        if (! \method_exists($_pipein, Convention::HANDLER_PIPEIN)) {
            throw new HTTPPortManagerExceptor('PIPE_WITHOUT_HANDLER', \compact('pipein', 'port'));
        }

        return $_pipein;
    }

    public static function __annotationValueNOPIPEIN(string $nopipein, string $port, &$multiple, &$strict)
    {
        if ($nopipein === '_') {
            return '_';
        }

        $multiple = 'unique';

        $_nopipein = Reflect::getAnnotationNamespace($nopipein, $port);
        if ((! $_nopipein) || (! \class_exists($_nopipein))) {
            throw new HTTPPortManagerExceptor('PIPE_NOT_EXISTS', \compact('nopipein', 'port'));
        }
        if (! \method_exists($_nopipein, Convention::HANDLER_PIPEIN)) {
            throw new HTTPPortManagerExceptor('PIPE_WITHOUT_HANDLER', \compact('nopipein', 'port'));
        }

        return $_nopipein;
    }

    public static function __annotationValueSTATUSOK(string $statusok, string $port, &$multiple, &$strict)
    {
        if ($statusok === '_') {
            return '_';
        }

        if (! Num::between($statusok, 100, 999)) {
            throw new HTTPPortManagerExceptor('INVALID_ANNOTATION', \compact('statusok', 'port'));
        }

        return \intval($statusok);
    }

    public static function __annotationValueCODEOK(string $codeok, string $port, &$multiple, &$strict)
    {
        if ($codeok === '_') {
            return '_';
        }

        if (! TypeHint::int($codeok)) {
            throw new HTTPPortManagerExceptor('INVALID_ANNOTATION', \compact('codeok', 'port'));
        }

        return \intval($codeok);
    }

    public static function __annotationValueWRAPERR(string $wraperr, string $port, &$multiple, &$strict)
    {
        if ($wraperr === '_') {
            return '_';
        }

        $_wraperr = Reflect::getAnnotationNamespace($wraperr, $port);
        if (! $_wraperr) {
            throw new HTTPPortManagerExceptor('WRAPPER_NOT_EXISTS', \compact('wraperr', 'port'));
        }
        if (! \method_exists($_wraperr, Convention::WRAPERR_HANDLER)) {
            throw new HTTPPortManagerExceptor('WRAPPER_WITHOUT_HANDLER', \compact('wraperr'));
        }
        $return = (new $_wraperr)->{Convention::WRAPERR_HANDLER}();
        if (! \is_array($return)) {
            throw new HTTPPortManagerExceptor('INVALID_WRAPPER_RETURN', \compact('wraperr', 'return'));
        }

        return $_wraperr;
    }

    public static function __annotationValueWRAPOUT(string $wrapout, string $port, &$multiple, &$strict)
    {
        if ($wrapout === '_') {
            return '_';
        }

        $_wrapout = Reflect::getAnnotationNamespace($wrapout, $port);
        if (! $_wrapout) {
            throw new HTTPPortManagerExceptor('WRAPPER_NOT_EXISTS', \compact('wrapout', 'port'));
        }
        if (! \method_exists($_wrapout, Convention::HANDLER_WRAPOUT)) {
            throw new HTTPPortManagerExceptor('WRAPPER_WITHOUT_HANDLER', \compact('wrapout'));
        }
        $return = (new $_wrapout)->{Convention::HANDLER_WRAPOUT}();
        if (! \is_array($return)) {
            throw new HTTPPortManagerExceptor('INVALID_WRAPPER_RETURN', \compact('wrapout', 'return'));
        }

        return $_wrapout;
    }

    public static function __annotationValueWRAPIN(string $wrapin, string $port, &$multiple, &$strict)
    {
        $_wrapin = Reflect::getAnnotationNamespace($wrapin, $port);

        if ((! $_wrapin) || (! \class_exists($_wrapin)) || (! WrapInManager::get($_wrapin))) {
            throw new HTTPPortManagerExceptor('WRAPIN_NOT_EXISTS', \compact('wrapin', 'port'));
        }

        return $_wrapin;
    }

    public static function __annotationValueVERB(string $verb, string $port, &$multiple, &$strict)
    {
        $multiple = true;

        $verbs = Str::arr(\strtoupper($verb), ',');
        foreach ($verbs as $_verb) {
            if (! \in_array($_verb, Convention::HTTP_VERBS_ALL)) {
                throw new HTTPPortManagerExceptor('INVALID_HTTP_VERB', \compact('verb', 'port'));
            }
        }

        return $verbs;
    }

    public static function __annotationValueMIMEIN(string $mimein, string $port, &$multiple, &$strict)
    {
        if ($mimein === '_') {
            return '_';
        }

        if (! MIME::mime($mimein)) {
            throw new HTTPPortManagerExceptor('INVALID_ANNOTATION_MIMEIN', \compact('mimein', 'port'));
        }

        return $mimein;
    }

    public static function __annotationValueMIMEOUT(string $mimeout, string $port, &$multiple, &$strict)
    {
        if ($mimeout === '_') {
            return '_';
        }

        if (! MIME::mime($mimeout)) {
            throw new HTTPPortManagerExceptor('INVALID_ANNOTATION_MIMEOUT', \compact('mimeout', 'port'));
        }

        return $mimeout;
    }

    public static function __annotationValueVERSION(string $version, string $port, &$multiple, &$strict)
    {
        if ($version === '_') {
            return '_';
        }

        return \strtolower($version);
    }

    public static function __annotationValueGROUP(string $group, string $port, &$multiple, &$strict)
    {
        if ($group === '_') {
            return '_';
        }

        return Str::arr($group, '/');
    }

    public static function __annotationValueSUFFIX(string $suffix, string $port, &$multiple, &$strict)
    {
        if ($suffix === '_') {
            return '_';
        }

        $multiple = true;

        return Str::arr(\strtolower($suffix), ',');
    }

    public static function __annotationValueREMARK(string $remark, string $port, &$multiple, &$strict)
    {
        $multiple = 'append';

        return $remark;
    }
}
