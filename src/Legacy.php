<?php

declare(strict_types=1);

namespace DOF\HTTP;

use Throwable;
use DOF\DOF;
use DOF\Convention;
use DOF\Exceptor\WritePermissionDenied;
use DOF\Util\Format;

final class Legacy extends Kernel
{
    public function __construct()
    {
        parent::__construct(PHP_SAPI);

        $this->request = $this->new(LegacyRequest::class, $this);
        $this->response = $this->new(LegacyResponse::class, $this);
    }

    public function handle(string $root)
    {
        try {
            DOF::init($root);

            // update upfiles right after kernel booted
            $this->upfiles = \count(get_included_files());
        } catch (WritePermissionDenied $th) {
            $this->unregister('shutdown', __CLASS__);

            return $this->response
                    ->setMimeAlias('json')
                    ->setStatus(500)
                    ->setBody(Format::throwable($th))
                    ->send();
        } catch (Throwable $th) {
            return $this->response->throw($th);
        }

        return parent::execute();
    }
}
