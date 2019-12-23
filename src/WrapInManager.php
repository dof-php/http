<?php

declare(strict_types=1);

namespace DOF\HTTP;

use Closure;
use DOF\DMN;
use DOF\Convention;
use DOF\Traits\Manager;
use DOF\Util\FS;
use DOF\Util\Arr;
use DOF\Util\Reflect;
use DOF\Util\Annotation;
use DOF\Util\Validator;
use DOF\HTTP\Exceptor\WrapInManagerExceptor;

final class WrapInManager
{
    use Manager;

    public static function init()
    {
        foreach (DMN::list() as $domain => $dir) {
            if (\is_dir($wrapin = FS::path($dir, Convention::DIR_HTTP, Convention::DIR_WRAPPER, Convention::DIR_WRAPIN))) {
                WrapInManager::addDomain($domain, $wrapin);
            }
        }
    }

    protected static function assemble(array $ofClass, array $ofProperties, array $ofMethods, string $type)
    {
        $namespace = $ofClass['namespace'] ?? null;
        if (! $namespace) {
            return;
        }
        if ($exists = (self::$data[$namespace] ?? null)) {
            throw new WrapInManagerExceptor('DUPLICATE_WRAPIN', \compact('wrapin'));
        }
        if (! ($ofClass['doc']['TITLE'] ?? false)) {
            throw new WrapInManagerExceptor('MISSING_WRAPIN_TITLE', \compact('wrapin'));
        }

        self::$data[$namespace]['meta'] = $ofClass;

        foreach ($ofProperties as $property => $options) {
            if (! ($options['doc']['TITLE'] ?? null)) {
                throw new WrapInManagerExceptor('MISSING_WRAPIN_ATTR_TITLE', \compact('wrapin', 'property'));
            }
            if (! ($options['doc']['TYPE'] ?? null)) {
                throw new WrapInManagerExceptor('MISSING_WRAPIN_ATTR_TYPE', \compact('wrapin', 'property'));
            }
        }

        self::$data[$namespace]['properties'] = $ofProperties;
    }

    /**
     * Apply a wrapin class on given parameters
     *
     * @param string $wrapin: Wrapin class to apply
     * @param Closure $match: Value matching process
     * @return DOF\Util\Validator
     */
    public static function apply(string $wrapin, Closure $match)
    {
        if ((! \class_exists($wrapin)) || \is_null($annotation = self::get($wrapin))) {
            throw new WrapInManagerExceptor('WRAPIN_NOT_EXISTS', \compact('wrapin', 'annotation'));
        }
        
        return self::validate($annotation['properties'] ?? [], $wrapin, $match);
    }

    /**
     * Execute a wrapin check by given validator arguments and origin
     *
     * @param array $arguments: Validate rules list
     * @param string $origin: The origin class request a wrapin check
     * @param Closure $match: Value matching process
     * @return DOF\Util\Validator
     */
    public static function validate(array $arguments, string $origin, Closure $match)
    {
        $validator = new Validator;

        $data = $rules = $extra = $errs = [];
        foreach ($arguments as $argument => list('doc' => $annotations)) {
            $compatibles = $annotations['COMPATIBLE'] ?? [];
            $key = null;
            $val = $match(Arr::union([$argument], $compatibles), $key, $annotations);
            $ext = $annotations[Annotation::EXTRA_KEY] ?? null;

            unset(
                $annotations[Annotation::EXTRA_KEY],
                $annotations['TITLE'],
                $annotations['NOTES'],
                $annotations['COMPATIBLE'],
                $annotations['LOCATION']
            );

            foreach ($annotations as $annotation => $option) {
                if ($annotation === 'WRAPIN') {
                    $list = $ext['WRAPIN']['list'] ?? false;
                    if ($list) {
                        if (! IS::array($val, 'index')) {
                            $validator->throw('NONINDEX_ARRAY_WRAPPING_DATA', 'array', $argument, 'index', $val, \compact('origin', 'option', 'list'));
                            return;
                        }

                        foreach ($val as $idx => $item) {
                            if (! IS::array($item, 'assoc')) {
                                $validator->throw('NONASSOC_ARRAY_WRAPPING_DATA', 'array', $argument, 'assoc', $item, \compact('origin', 'option', 'idx'));
                                return;
                            }
                            $validator = WrapInManager::apply($option, function ($keys, &$key) use ($item) {
                                return Arr::match($keys, $item, null, $key);
                            });

                            $data[$argument][$idx] = $validator->getResult();
                        }
                        continue;
                    }
                    
                    if (! IS::array($val, 'assoc')) {
                        $validator->throw('NONASSOC_ARRAY_WRAPPING_DATA', 'array', $argument, 'assoc', $val, \compact('origin', 'option'));
                        return;
                    }

                    $validator = WrapInManager::apply($option, function ($keys, &$key) use ($val) {
                        return Arr::match($keys, $val, null, $key);
                    });

                    $data[$argument] = $validator->getResult();

                    continue;
                }

                $data[$argument] = $val;
                $rules[$argument][$annotation] = $option;
                // if ($err = ($ext[$annotation]['ERR'] ?? null)) {
                // $errs[$argument][$annotation] = $err;
                // }
                if ($_ext = ($ext[$annotation] ?? [])) {
                    $extra[$argument][$annotation] = $_ext;
                }
            }
        }

        $validator
            ->throwOnFail(true)
            ->abortOnFail(true)
            ->setData($data)
            ->setRules($rules)
            ->setExtra($extra)
            ->setErrs($errs)
            ->execute();

        return $validator;
    }

    public static function __annotationValueWRAPIN(string $wrapin, string $namespace, &$multiple, &$strict)
    {
        $_wrapin = Reflect::getAnnotationNamespace($wrapin, $namespace);
        if ((! $_wrapin) || (! \class_exists($_wrapin))) {
            throw new WrapInManagerExceptor('WRAPIN_NOT_EXISTS', \compact('wrapin', 'namespace'));
        }
        if ($_wrapin === $namespace) {
            throw new WrapInManagerExceptor('RECURSIVE_WRAPIN', \compact('wrapin', 'namespace'));
        }

        return $_wrapin;
    }

    public static function __annotationValueCOMPATIBLE(string $compatibles, string $wrapin, &$multiple, &$strict)
    {
        $multiple = 'flip';

        return Str::arr($compatibles, ',');
    }
}
