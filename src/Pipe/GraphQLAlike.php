<?php

declare(strict_types=1);

namespace DOF\HTTP\Pipe;

use Throwable;
use DOF\Util\DSL\IFRSN;
use DOF\DDD\ASM;

class GraphQLAlike
{
    final public function pipein($request, $response, $route, $port)
    {
        $fields = $this->findFields($request, $response, true);
        if ($fields === $response) {
            return $response;
        }

        $route->setPipein(static::class, $fields);
    }

    final public function pipeout($result, $route, $port, $request, $response)
    {
        if ($route->hasPipein(static::class)) {
            $fields = $route->getPipein(static::class);
        } else {
            $fields = $this->findFields($request, $response, false);
            if ($fields === $response) {
                return $response;
            }
        }

        // If no fields but used this pipeout
        // Just return empty data as NULL
        if (! $fields) {
            return null;
        }

        return ASM::assemble($result, $fields, $port->annotation('ASSEMBLER'));
    }

    private function findFields($request, $response, bool $require)
    {
        $fields = $request->all($key = $this->getFieldsKey());
        if ((! $fields) && $require) {
            return $response->fail(400, 'MISSING_GRAPHQLALIKE_FIELDS', ['require' => $key]);
        }

        try {
            $_fields = IFRSN::parse(\sprintf('graphql(%s)', $fields));
            if (! $_fields) {
                return $response->fail(400, 'INVALID_GRAPHQLALIKE_FIELD', \compact('fields'));
            }

            return $_fields['graphql'] ?? null;
        } catch (Throwable $th) {
            return $response->fail(400, 'INVALID_GRAPHQLALIKE_FIELDS', \compact('fields'), $th);
        }
    }

    protected function getFieldsKey() : string
    {
        return '__fields';
    }
}
