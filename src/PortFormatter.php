<?php

declare(strict_types=1);

namespace DOF\HTTP;

use DOF\DMN;
use DOF\INI;
use DOF\Convention;
use DOF\Util\IS;
use DOF\Util\FS;
use DOF\Util\Str;
use DOF\Util\Arr;
use DOF\Util\Annotation;
use DOF\Util\Collection;
use DOF\Util\Singleton;
use DOF\DDD\ModelManager;
use DOF\DDD\EntityManager;
use DOF\HTTP\MIME;
use DOF\HTTP\I18N;
use DOF\HTTP\HTTPPortManager;
use DOF\HTTP\Pipe\Sorting;
use DOF\HTTP\Exceptor\PortFormatterExceptor;

class PortFormatter
{
    /**
     * Get HTTP Port data structure for documentation
     *
     * @return array
     */
    public static function formatDocs() : array
    {
        $docs = [];

        foreach (HTTPPortManager::getPorts() as $class => $methods) {
            foreach ($methods as $method => $options) {
                \extract($options);

                if (IS::confirm($doc['NODOC'] ?? false)) {
                    continue;
                }

                $version = $doc['VERSION'] ?? 'v0';
                $domain = DMN::name($file);

                $_docs = $docs[$version][$domain] ?? [
                    'title' => DMN::meta($domain, 'title', $domain),
                    'group' => [],
                    'list'  => [],
                ];

                $_doc = [
                    'title' => $doc['TITLE'] ?? null,
                    'annotations' => $doc,
                    'arguments' => $arguments ?? [],
                    'class' => $class,
                    'method' => $method,
                    'file' => $file,
                ];

                $groups = self::formatDocGroups($doc['GROUP'] ?? [], $domain);
                if ($groups) {
                    $_docs['group'] = self::dynamicAppendDocWithGroups($_docs['group'] ?? [], $_doc, $groups);
                } else {
                    $_docs['list'][] = $_doc;
                }

                $docs[$version][$domain] = $_docs;
            }
        }

        return $docs;
    }

    /**
     * Append $append into $data dynamically with $groups
     */
    public static function dynamicAppendDocWithGroups(array $data, $append, array $groups)
    {
        $keys = $groups;
        foreach ($groups as $name => $title) {
            $data[$name]['title'] = $title;
            if (false === \next($groups)) {
                $data[$name]['list'][] = $append;
                return $data;
            }

            $_data = $data[$name]['group'] ?? [];
            unset($keys[$name]);

            // Here $keys must be unempty coz we check \next($groups) before
            $data[$name]['group'] = self::dynamicAppendDocWithGroups($_data, $append, $keys);
            $data[$name]['list']  = \array_merge(($data[$name]['list'] ?? []), ($_data['list'] ?? []));

            return $data;
        }
    }

    public static function formatDocNamespace(string $namespace = null)
    {
        if (! $namespace) {
            return null;
        }

        $arr = Str::arr($namespace, '\\');
        unset($arr[0]);

        return \join('.', $arr);
    }

    public static function formatDocGroups(array $groups, string $domain)
    {
        if (! $groups) {
            return [];
        }

        $result = [];
        foreach ($groups as $group) {
            $title = INI::final($domain, 'domain', Convention::OPT_DOC_GROUP)[$group] ?? $group;

            $result[$group] = $title;
        }

        return $result;
    }

    public static function formatDocAppendixes(
        array $appendixes,
        string $domainKey,
        string $domainTitle,
        string $domainRoot
    ) : array {
        \array_walk($appendixes, function (&$item) use ($domainKey, $domainTitle, $domainRoot) {
            $item['domain'] = $domainTitle;
            $path = $item['path'] ?? null;
            if (! $path) {
                return;
            }

            $item['key']  = $domainKey;
            $item['path'] = FS::path($domainRoot, $path);
        });

        return $appendixes;
    }

    public static function formatDocWraperr(string $wraperr = null)
    {
        if (! $wraperr) {
            return;
        }

        return self::formatDocWrapper(Singleton::get($wraperr)->wraperr());
    }

    public static function formatDocWrapout(array $doc)
    {
        $wrapout = $doc['annotations']['WRAPOUT'] ?? null;
        if (! $wrapout) {
            return;
        }

        return self::formatDocWrapper(Singleton::get($wrapout)->wrapout(), [
            'info' => $doc['annotations']['INFOOK'] ?? null,
            'code' => $doc['annotations']['CODEOK'] ?? null,
        ]);
    }

    public static function formatDocWrapper(array $wrapper = null, array $extra = [])
    {
        if ((! $wrapper) || (! \is_array($wrapper))) {
            return null;
        }

        $res = [];
        foreach ($wrapper as $key => $val) {
            $_key = \is_int($key) ? $val : $key;
            $_val = \is_int($key) ? null : $val;
            if (\in_array($_key, [
                '__DATA__',
                '__INFO__',
                '__PAGINATOR__',
            ])) {
                $_val = $_key;
                $_key = $val;
            }

            $res[$_key] = $extra[$_key] ?? $_val;
        }

        $res = Str::buffer(function () use ($res) {
            \print_r($res);
        });

        $res = \str_replace('[', '', $res);
        $res = \str_replace(']', '', $res);
        $res = Str::arr($res, PHP_EOL);

        Arr::unset($res, [0, 1, (\count($res) -1)]);

        return \join(PHP_EOL, $res);
    }

    public static function formatDocSorting(array $doc)
    {
        $allow = $doc['annotations'][Annotation::EXTRA_KEY]['PIPEIN'][Sorting::class]['ALLOW'] ?? '';
        $allow = Str::arr($allow);

        $properties = HTTPPortManager::getProperties($doc['class'] ?? null);
        $sorting = [];
        foreach ($allow as $field) {
            if ($option = ($properties[$field] ?? null)) {
                $sorting[] = [$field, $option['doc']['TITLE'] ?? null, $option['doc']['COMPATIBLE'] ?? []];
                continue;
            }

            throw new PortFormatterExceptor('INVALID_SORTING_FIELD', \compact('field', 'allow'));
        }

        return $sorting;
    }

    public static function formatDocModel(string $model = null)
    {
        if (! $model) {
            return null;
        }

        $_model = new Collection([]);

        $_model->key = self::formatDocNamespace($model);
        $_model->namespace = $model;
        $annotations = EntityManager::get($model) ?? ModelManager::get($model);
        $_model->title = $annotations['meta']['doc']['TITLE'] ?? null;
        $_model->subtitle = $annotations['meta']['doc']['SUBTITLE'] ?? null;
        $properties = $annotations['properties'] ?? [];
        $_properties = $arguments = [];
        foreach ($properties as $property => $option) {
            $type = \ucfirst($option['doc']['TYPE'] ?? '');
            $typemodel = null;
            if (IS::ciin($type, ['entity', 'entitylist'])) {
                $typemodel = self::formatDocNamespace($option['doc'][Annotation::EXTRA_KEY]['TYPE']['ENTITY'] ?? null);
            } elseif (IS::ciin($type, ['model', 'modellist'])) {
                $typemodel = self::formatDocNamespace($option['doc'][Annotation::EXTRA_KEY]['TYPE']['MODEL'] ?? null);
            }
            $_properties[] = new Collection([
                'name' => $property,
                'title' => $option['doc']['TITLE'] ?? null,
                'type' => $type,
                'typemodel' => $typemodel,
                'notes' => $option['doc']['NOTES'] ?? null,
                'compatibles' => $option['doc']['COMPATIBLE'] ?? [],
            ]);

            if ($_arguments = ($option['doc']['ARGUMENT'] ?? [])) {
                $arguments[$property] = $_arguments;
            }
        }

        $_model->properties = $_properties;
        $_model->arguments = $arguments;

        return $_model;
    }

    public static function formatModelDocData(array $annotation, string $key)
    {
        $data = [];

        $data['model'] = $annotation['meta']['title']['TITLE'] ?? $key;
        $data['key'] = $key;
        foreach ($annotation['properties'] ?? [] as $name => $options) {
            $data['properties'][] = [
                'name' => $name,
                'type' => $options['doc']['TYPE']  ?? null,
                'title' => $options['doc']['TITLE'] ?? null,
                'notes' => $options['doc']['NOTES'] ?? null,
                'arguments' => $options['doc']['ARGUMENT'] ?? [],
            ];
        }

        return $data;
    }

    public static function formatWrapinDocData(array $annotation, string $key)
    {
        $data = [];

        $data['wrapin'] = $annotation['meta']['doc']['TITLE'] ?? $key;
        $data['key'] = $key;

        foreach ($annotation['properties'] ?? [] as $name => list('doc' => $options)) {
            $rules = $options;
            Arr::unset(
                $rules,
                [
                    Annotation::EXTRA_KEY,
                    'TITLE',
                    'TYPE',
                    'NOTES',
                    'DEFAULT',
                    'COMPATIBLE'
                ]
            );

            if ($wrapin = ($rules['WRAPIN'] ?? false)) {
                $rules['WRAPIN'] = self::formatDocNamespace($wrapin);
            }

            $data['params'][] = [
                'name' => $name,
                'type' => $options['TYPE'] ?? null,
                'title' => $options['TITLE'] ?? null,
                'notes' => $options['NOTES'] ?? null,
                'default' => $options['DEFAULT'] ?? null,
                'compatibles' => $options['COMPATIBLES'] ?? [],
                'validators'  => $rules,
            ];
        }

        return $data;
    }

    public static function formatArguments(array $doc)
    {
        $arguments = [];
        if ($argvs = ($doc['annotations']['ARGUMENT'] ?? [])) {
            foreach ($argvs as $name) {
                $option = $doc['arguments'][$name]['doc'] ?? [];
                $location = $option['LOCATION'] ?? null;
                $rules = Arr::remove($option, [
                    Annotation::EXTRA_KEY,
                    'TITLE',
                    'TYPE',
                    'NOTES',
                    'LOCATION',
                    'DEFAULT',
                    'COMPATIBLE',
                ]);

                $arguments[] = new Collection([
                    'name' => $name,
                    'title' => $option['TITLE'] ?? null,
                    'type' => \ucfirst(\strtolower($option['TYPE'] ?? '')),
                    'notes' => $option['NOTES'] ?? null,
                    'location' => self::formatRequestArgumentLocation($location),
                    'compatibles' => $option['COMPATIBLE'] ?? [],
                    'rules' => $rules,
                ]);
            }
        }

        return $arguments;
    }

    public static function formatRequestArgumentLocation(string $location = null, string $lang = 'zh-CN')
    {
        switch (\strtoupper($location ?? '')) {
            case 'ROUTE':
                return I18N::get('ROUTE_PARAMS', $lang);
            case 'QUERY':
                return I18N::get('URL_QUERY', $lang);
            case 'BODY':
                return I18N::get('REQUEST_BODY', $lang);
            case 'ALL':
            default:
                return I18N::get('ALL', $lang);
        }
    }

    public static function formatRequest(array $doc)
    {
        $response = new Collection([]);

        $headers = $doc['annotations']['HEADERIN'] ?? [];

        if (($doc['annotations']['AUTH'] ?? 0) > 0) {
            $headers['AUTHORIZATION'] = 'Bearer | Basic | http-hmac | ... {TOKEN}';
        }

        if ($mimein = ($doc['annotations']['MIMEIN'] ?? null)) {
            $headers['Content-Type'] = MIME::mime($mimein);
        }

        $response->headers = $headers;

        return $response;
    }

    public static function formatResponse(array $doc)
    {
        $response = new Collection([]);

        $headers = $statuses = [];

        $headers['TRACE-NO'] = '{TRACE_NO}';

        if ($mimeout = ($doc['annotations']['MIMEOUT'] ?? null)) {
            $headers['Content-Type'] = MIME::mime($mimeout);
        }

        if ($statusok = ($doc['annotations']['STATUSOK'] ?? null)) {
            $statuses[$statusok] = 'success';
        }

        $response->headers = $headers;
        $response->statuses = $statuses;

        return $response;
    }

    public static function buildPortCategories(array $categories)
    {
        $data = [];

        foreach ($categories as $_category => $category) {
            $children = [];
            $list = $category['list'] ?? [];
            if ($list) {
                foreach ($list as $port) {
                    if ($port['annotations']['NODUMP'] ?? false) {
                        continue;
                    }

                    foreach (($port['annotations']['VERB'] ?? []) as $verb) {
                        $children[] = [
                            'title' => $port['annotations']['TITLE'] ?? null,
                            'route' => $port['annotations']['ROUTE'] ?? null,
                            'verb' => $verb,
                        ];
                    }
                }
            }

            $data[] = [
                'title' => $category['title'] ?? $_category,
                'categories' => self::buildPortCategories($category['group'] ?? []),
                'apis' => $children,
            ];
        }

        return $data;
    }
}
