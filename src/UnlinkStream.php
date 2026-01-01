<?php

namespace claserre9;

use Slim\Psr7\Stream;

/**
 * A Stream wrapper that unlinks (deletes) the file when the stream is closed.
 */
class UnlinkStream extends Stream
{
    /**
     * @var string|null The path to the file to be deleted.
     */
    private ?string $path;

    /**
     * @param resource $resource The stream resource.
     * @param string|null $path The path to the file.
     */
    public function __construct($resource, ?string $path = null)
    {
        parent::__construct($resource);
        $this->path = $path;
    }

    /**
     * Closes the stream and deletes the associated file.
     *
     * @return void
     */
    public function close(): void
    {
        parent::close();
        if ($this->path !== null && is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
