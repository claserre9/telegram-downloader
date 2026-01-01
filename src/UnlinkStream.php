<?php

namespace claserre9;

use Slim\Psr7\Stream;

class UnlinkStream extends Stream
{
    private ?string $path;

    public function __construct($resource, ?string $path = null)
    {
        parent::__construct($resource);
        $this->path = $path;
    }

    public function close(): void
    {
        parent::close();
        if ($this->path !== null && is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
