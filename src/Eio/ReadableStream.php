<?php

namespace React\Filesystem\Eio;

use Evenement\EventEmitter;
use React\Filesystem\EioAdapter;
use React\Filesystem\Stream\GenericStreamInterface;
use React\Filesystem\Stream\GenericStreamTrait;
use React\Filesystem\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

class ReadableStream extends EventEmitter implements GenericStreamInterface, ReadableStreamInterface
{
    use GenericStreamTrait;

    protected $path;
    protected $size;
    protected $filesystem;
    protected $fileDescriptor;
    protected $cursor;
    protected $chunkSize = 8192;
    protected $pause = true;

    public function __construct($path, $fileDescriptor, EioAdapter $filesystem)
    {
        $this->path = $path;
        $this->filesystem = $filesystem;
        $this->fileDescriptor = $fileDescriptor;

        $this->resume();
    }

    public function resume()
    {
        $this->pause = false;

        if ($this->size === null) {
            $this->filesystem->stat($this->path)->then(function ($info) {
                $this->size = $info['size'];
                $this->cursor = 0;

                $this->readChunk();
            });
            return;
        }

        $this->readChunk();
    }

    public function pause()
    {
        $this->pause = true;
    }

    public function pipe(WritableStreamInterface $dest, array $options = [])
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }

    public function close()
    {
        $this->filesystem->close($this->fileDescriptor)->then(function () {
            $this->emit('close', [
                $this,
            ]);
        });
    }

    public function isReadable()
    {

    }

    protected function readChunk()
    {
        if ($this->pause) {
            return;
        }

        $this->filesystem->read($this->fileDescriptor, $this->chunkSize, $this->cursor)->then(function ($data) {
            // If chunk size can be set make sure to copy it before running this operation so
            // that used can't change it mid operation and cause funkyness.
            $this->cursor += $this->chunkSize;
            $this->emit('data', [
                $data,
                $this,
            ]);

            if ($this->cursor < $this->size) {
                $this->readChunk();
            } else {
                $this->emit('end', [
                    $this,
                ]);
            }
        });
    }
}
