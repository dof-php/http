<?php

declare(strict_types=1);

namespace DOF\HTTP;

final class LegacyResponse extends Response
{
    public function send() : void
    {
        $body = $this->stringify($this->body);

        if ($this->sent) {
            return;
        }

        if (! \headers_sent()) {
            \header(join(': ', ['TRACE-NO', $this->uuid()]));

            foreach ($this->headers() as $key => $value) {
                \header("{$key}: {$value}");
            }

            \http_response_code($this->status);
        }

        echo $body;

        if (\function_exists('fastcgi_finish_request')) {
            \fastcgi_finish_request();
        }

        $this->kernel->stdout = [$this->getMimeAlias(), \mb_strlen($body)];

        $this->sent = true;
    }
}
