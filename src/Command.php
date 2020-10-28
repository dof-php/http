<?php

declare(strict_types=1);

namespace DOF\HTTP;

use DOF\DOF;
use DOF\DMN;
use DOF\Convention;
use DOF\Util\IS;
use DOF\Util\FS;
use DOF\Util\Arr;
use DOF\Util\Str;
use DOF\Util\XML;
use DOF\Util\JSON;
use DOF\Util\Format;
use DOF\Util\Reflect;
use DOF\CLI\Color;
use DOF\CLI\Command as CLICommand;
use DOF\DDD\Command as DDDCommand;
use DOF\HTTP\I18N;
use DOF\HTTP\PortFormatter;
use DOF\HTTP\HTTPPortManager;
use DOF\HTTP\WrapInManager;

/**
 * @CMD(http)
 */
class Command
{
    /**
     * @CMD(crud)
     * @Alias(crud)
     * @Desc(Generate all CRUD operations related classes based on a resource/entity name)
     * @Option(domain){notes=Domain name of classes to be created}
     * @Option(entity){notes=Entity directory path}
     * @Option(storage){notes=ORM storage directory path}
     * @Option(repo){notes=Repository directory path}
     * @Option(port){notes=Port directory path}
     * @Option(noport){notes=Do not create port class&default=false}
     * @Option(service){notes=Service directory path}
     * @Option(asm){notes=Assembler directory path}
     * @Option(noasm){notes=Do not create assembler class&default=false}
     * @Option(withts){notes=Entity and ORM storage to be created need timestamps or not&default=true}
     * @Option(nodelete){notes=Do not create delete service&default=false}
     * @Option(noshow){notes=Do not create show service&default=false}
     * @Option(noupdate){notes=Do not create update service&default=false}
     * @Option(nolist){notes=Do not create list service&default=false}
     * @Option(noerr){notes=Do not create Err class of domain&default=false}
     */
    public function crud($console)
    {
        $cli = $console->di(CLICommand::class);

        $domain = $console->getOption('domain', null, true);
        if (! DMN::path($domain)) {
            $params = $console->getParams();
            $console->setParams([$domain]);
            $cli->addDomain($console);
            $console->setParams($params);
        }

        $ddd = $console->di(DDDCommand::class);

        $entity = $ddd->ers($console);

        if (! $console->hasOption('noport')) {
            $port = $console->hasOption('port') ? \join('/', [$console->getOption('port'), $_entity]) : $entity;
            $console->setOption('crud', true)->setOption('port', $port);
            $this->addPort($console);
        }

        $console->setOption('entity', $entity)->setOption('crud', 'create')->setOption('service', "CRUD/Create{$entity}");
        $ddd->addService($console);
        if (! $console->hasOption('nodelete')) {
            $console->setOption('crud', 'delete')->setOption('service', "CRUD/Delete{$entity}");
            $ddd->addService($console);
        }
        if (! $console->hasOption('noupdate')) {
            $console->setOption('crud', 'update')->setOption('service', "CRUD/Update{$entity}");
            $ddd->addService($console);
        }
        if (! $console->hasOption('noshow')) {
            $console->setOption('crud', 'show')->setOption('service', "CRUD/Show{$entity}");
            $ddd->addService($console);
        }
        if (! $console->hasOption('nolist')) {
            $console->setOption('crud', 'list')->setOption('service', "CRUD/List{$entity}");
            $ddd->addService($console);
        }
        if (! $console->hasOption('noasm')) {
            $asm = $console->hasOption('asm') ? \join('/', [$console->getOption('asm'), $entity]) : $entity;
            $console->setOption('asm', $asm);
            $ddd->addAssembler($console);
        }
        if (! $console->hasOption('noerr')) {
            $cli->addErr($console);
        }
    }

    /**
     * @CMD(port.add)
     * @Desc(Add a port class in a domain)
     * @Option(domain){notes=Domain name of port to be created}
     * @Option(port){notes=Name of port to be created}
     * @Option(force){notes=Whether force recreate port when given port name exists}
     * @Option(crud){notes=Whether add crud port methods into port&default=false}
     * @Option(version){notes=Port version&default=V1}
     * @Option(autonomy){notes=Whether create an autonomy port&default=false}
     */
    public function addPort($console)
    {
        $domain = $console->getOption('domain', null, true);
        if (! ($path = DMN::path($domain))) {
            $console->fail('DomainNotExists', \compact('domain'));
        }

        $name = \str_replace('\\', FS::DS, Format::u2c($console->getOption('port', null, true), CASE_UPPER));
        if (Str::end($name, '.php', true)) {
            $name = Str::shift($name, 4, true);
        }
        $version = \ucfirst($console->getOption('version', 'V1'));
        $pathof = DOF::pathof($class = FS::path($path, Convention::DIR_HTTP, Convention::DIR_PORT, $version, "{$name}.php"));
        if (\is_file($class) && (! $console->hasOption('force'))) {
            $console->error('PORT_ALREADY_EXISTS', ['port' => Reflect::getFileNamespace($class, true), 'file' => $pathof]);
        }

        if ($console->hasOption('autonomy')) {
            $tpl = 'port-autonomy';
        } elseif ($console->hasOption('crud')) {
            $tpl = 'port-crud';
        } else {
            $tpl = 'port-basic';
        }

        if (! \is_file($template = FS::path(\dirname(__DIR__), Convention::DIR_TEMPLATE, $tpl))) {
            $console->error('PortClassTemplateNotExist', \compact('template'));
        }

        $port = \file_get_contents($template);
        $port = \str_replace('__DOMAIN__', Format::namespace(DMN::name($domain), '.', true), $port);
        $port = \str_replace('__NAMESPACE__', Format::namespace($name, FS::DS, false, true), $port);
        $port = \str_replace('__VERSION_NS__', Format::namespace($version, FS::DS, true, true), $port);
        $port = \str_replace('__VERSION__', \strtolower($version), $port);
        $port = \str_replace('__NAME__', \basename($name), $port);
        $port = \str_replace('__NAME_UPPER__', \strtoupper(\basename($name)), $port);
        $port = \str_replace('__ROUTE__', \strtolower(\join('/', Str::arr(Format::namespace($name, FS::DS, true, false)))), $port);

        $lang = \I18N::lang($domain);
        $port = \str_replace('__SEARCH_KEYWORD__', I18N::get('SEARCH_KEYWORD', $lang), $port);

        if ($console->hasOption('crud')) {
            $entity = DMN::meta($domain, 'title', \basename($name));
            $port = \str_replace('__CRUD_CREATE__', I18N::get('CRUD_CREATE', $lang, ['entity' => $entity]), $port);
            $port = \str_replace('__CRUD_DELETE__', I18N::get('CRUD_DELETE', $lang, ['entity' => $entity]), $port);
            $port = \str_replace('__CRUD_UPDATE__', I18N::get('CRUD_UPDATE', $lang, ['entity' => $entity]), $port);
            $port = \str_replace('__CRUD_SHOW__', I18N::get('CRUD_SHOW', $lang, ['entity' => $entity]), $port);
            $port = \str_replace('__CRUD_LIST__', I18N::get('CRUD_LIST', $lang, ['entity' => $entity]), $port);
        }

        $console->task("Creating Port: {$pathof}", function () use ($class, $port) {
            FS::unlink($class);
            FS::save($class, $port);
        });
    }

    /**
     * @CMD(wrapin.add)
     * @Desc(Add a wrapin class in a domain)
     * @Option(domain){notes=Domain name of wrapin to be created}
     * @Option(wrapin){notes=Name of wrapin to be created}
     * @Option(force){notes=Whether force recreate wrapin when given wrapin name exists}
     */
    public function addWrapIn($console)
    {
        $domain = $console->getOption('domain', null, true);
        if (! ($path = DMN::path($domain))) {
            $console->fail('DomainNotExists', \compact('domain'));
        }

        $name = \str_replace('\\', FS::DS, Format::u2c($console->getOption('wrapin', null, true), CASE_UPPER));
        if (Str::end($name, '.php', true)) {
            $name = Str::shift($name, 4, true);
        }
        $pathof = DOF::pathof($class = FS::path($path, Convention::DIR_HTTP, Convention::DIR_WRAPIN, "{$name}.php"));
        if (\is_file($class) && (! $console->hasOption('force'))) {
            $console->fail('WrapinAlreadyExists', ['wrapin' => Reflect::getFileNamespace($class, true), 'file' => $pathof]);
        }
        if (! \is_file($template = FS::path(\dirname(__DIR__), Convention::DIR_TEMPLATE, 'wrapin'))) {
            $console->error('WrapinClassTemplateNotExist', \compact('template'));
        }

        $wrapin = \file_get_contents($template);
        $wrapin = \str_replace('__DOMAIN__', Format::namespace(DMN::name($domain), '.', true), $wrapin);
        $wrapin = \str_replace('__NAMESPACE__', Format::namespace($name, FS::DS, false, true), $wrapin);
        $wrapin = \str_replace('__NAME__', \basename($name), $wrapin);

        $console->task("Creating WrapIn: {$pathof}", function () use ($class, $wrapin) {
            FS::unlink($class);
            FS::save($class, $wrapin);
        });
    }

    /**
     * @CMD(pipe.add)
     * @Desc(Add a pipe class in a domain)
     * @Option(domain){notes=Domain name of pipe to be created}
     * @Option(pipe){notes=Name of pipe to be created}
     * @Option(force){notes=Whether force recreate pipe when given pipe name exists}
     */
    public function addPipe($console)
    {
        $domain = $console->getOption('domain', null, true);
        if (! ($path = DMN::path($domain))) {
            $console->fail('DomainNotExists', \compact('domain'));
        }

        $name = \str_replace('\\', FS::DS, Format::u2c($console->getOption('pipe', null, true), CASE_UPPER));
        if (Str::end($name, '.php', true)) {
            $name = Str::shift($name, 4, true);
        }
        $pathof = DOF::pathof($class = FS::path($path, Convention::DIR_HTTP, Convention::DIR_PIPE, "{$name}.php"));
        if (\is_file($class) && (! $console->hasOption('force'))) {
            $console->fail('PipeAlreadyExists', ['pipe' => Reflect::getFileNamespace($class, true), 'file' => $pathof]);
        }
        if (! \is_file($template = FS::path(\dirname(__DIR__), Convention::DIR_TEMPLATE, 'pipe'))) {
            $console->error('PipeClassTemplateNotExist', \compact('template'));
        }

        $pipe = \file_get_contents($template);
        $pipe = \str_replace('__DOMAIN__', Format::namespace(DMN::name($domain), '.', true), $pipe);
        $pipe = \str_replace('__NAMESPACE__', Format::namespace($name, FS::DS, false, true), $pipe);
        $pipe = \str_replace('__NAME__', \basename($name), $pipe);

        $console->task("Creating Pipe: {$pathof}", function () use ($class, $pipe) {
            FS::unlink($class);
            FS::save($class, $pipe);
        });
    }

    /**
     * @CMD(compile)
     * @Desc(Compile HTTP ports)
     * @Option(clear){notes=Clear HTTP ports compile cache}
     */
    public function compile($console)
    {
        if ($console->hasOption('clear')) {
            $console->task('Clearing HTTP ports compile cache', function () {
                HTTPPortManager::removeCompileFile();
            });
            return;
        }

        $console->task('Compiling HTTP Ports', function () {
            HTTPPortManager::compile(true);
        });
    }

    /**
     * @CMD(port.list)
     * @Desc(List http ports in domains)
     * @Option(domain){notes=List ports in given domains}
     * @Option(verb){notes=Filter ports with given http verbs}
     * @Option(version){notes=Filter ports with given versions}
     * @Option(logging){notes=Filter ports with logging-enable status}
     * @Option(count){notes=Get count of ports in given conditions}
     */
    public function listPort($console)
    {
        $domains = $console->getOption('domain', '');
        $domains = Str::arr($domains, ',');
        if ($domains) {
            foreach ($domains as &$domain) {
                $domain = \strtolower($domain);
                if (! DMN::path($domain)) {
                    $console->fail('DomainNotExists', \compact('domain'));
                }
            }
        }

        $verbs = Str::arr($console->getOption('verb', ''));
        \array_walk($verbs, function (&$verb) {
            $verb = \strtoupper($verb);
        });
        $versions = Str::arr($console->getOption('version', ''));
        $logging = $console->getOption('logging');

        $result = [];
        $routes = HTTPPortManager::getRoutes();
        $ports  = HTTPPortManager::getPorts();
        foreach ($routes as $group) {
            foreach ($group as $verb => list('class' => $class, 'method' => $method, 'definition' => $definition)) {
                if (! ($_domain = DMN::name($class))) {
                    $console->error('BadPortClassWithOutDomain', \compact('urlpath', 'verb', 'class'));
                }
                $title = $ports[$class][$method]['doc']['TITLE'] ?? null;
                if (! $title) {
                    $console->error('BadPortWithOutTitle', \compact('class', 'method', 'urlpath', 'verb'));
                }
                $_logging = $ports[$class][$method]['logging'] ?? null;
                if (! \is_null($logging)) {
                    if (IS::confirm($logging) && (! $_logging)) {
                        continue;
                    }
                    if ((! $logging) && $_logging) {
                        continue;
                    }
                }
                if ($domains && (! \in_array($_domain, $domains))) {
                    continue;
                }
                $version = $ports[$class][$method]['version'] ?? null;
                if (($versions === ['_']) && $version) {
                    continue;
                }
                if ($versions && (! IS::ciin($version, $versions))) {
                    continue;
                }

                $port = \join('@', [$class, $method]);
                $_verbs = $verb;
                if ($_port = ($result[$port] ?? null)) {
                    $_verbs = Arr::union(\explode('|', $_port[0] ?? ''), [$verb]);
                    $_verbs = \join('|', $_verbs);
                }

                $result[$port] = [$_verbs, $definition, $title];
            }
        }

        $output = '';
        $count  = 0;
        foreach ($result as $port => $item) {
            $_verbs = \explode('|', ($item[0] ?? ''));
            if ($verbs && (! \array_intersect($_verbs, $verbs))) {
                continue;
            }

            $item[] = $port;
            $output .= \join("\t\t", $item).PHP_EOL;
            $count++;
        }

        if ($console->hasOption('count')) {
            return $console->line($count);
        }

        $console->title(\join("\t\t", ['Verbs', 'URLPath', 'Title', 'Class@Method']));
        $console->line($output);
    }

    /**
     * @CMD(port.dump)
     * @Desc(Dump all ports data as a format)
     * @Option(format){notes=Dump format: JSON/XML/ARRAY&default=JSON}
     * @Option(save){notes=File path when has save option}
     */
    public function dumpPort($console)
    {
        $save = $console->getOption('save', null, false);

        $data = [];

        $docs = PortFormatter::formatDocs();
        foreach ($docs as $_version => $version) {
            $category = [];
            $category['title'] = $_version;
            $category['categories'] = PortFormatter::buildPortCategories($version);

            $data['categories'][] = $category;
        }

        $format = $console->getOption('format', 'json');
        if (! IS::ciin($format, ['json', 'xml', 'array'])) {
            $format = 'json';
        }

        switch (\strtolower($format)) {
            case 'array':
                $save ? Arr::save($data) : $console->line(Str::buffer($data));
                break;
            case 'xml':
                $save ? FS::save($save, XML::encode($data)) : $console->line(XML::encode($data, true));
                break;
            case 'json':
            default:
                $save ? FS::save($save, JSON::encode($data)) : $console->line($data);
                break;
        }

        $save && $console->ok();
    }

    /**
     * @CMD(service.start)
     * @Desc(Start/Restart HTTP service)
     */
    public function startWeb($console)
    {
        if (! \is_file($lock = DOF::path(Convention::FLAG_HTTP_HALT))) {
            return $console->ok('DOF HTTP web service is running.');
        }

        if (FS::unlink($lock) === false) {
            return $console->fail('Write permission denied.');
        }

        $console->ok('DOF HTTP web service restarted successfully.');
    }

    /**
     * @CMD(service.stop)
     * @Desc(Stop/Halt HTTP service)
     * @Option(force){notes=Whether force stop web application even if it's stopped already}
     * @Option(message){notes=The application shutdown message text displays to visitors}
     */
    public function stopWeb($console)
    {
        if (\is_file($lock = DOF::path(Convention::FLAG_HTTP_HALT))) {
            if ($console->hasOption('force')) {
                if (false === FS::unlink($lock)) {
                    return $console->error('Write permission denied (1).');
                }
            } else {
                return $console->ok('DOF HTTP service closed already.');
            }
        }

        if (false === \file_put_contents($lock, JSON::encode([
            Format::microtime('T Y-m-d H:i:s'),
            $console->getOption('message', 'Service Updating ...'),
        ]))) {
            return $console->error('Write permission denied (2).');
        }

        return $console->ok('DOF HTTP web service stoped.');
    }
}
