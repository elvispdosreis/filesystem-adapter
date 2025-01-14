<?php

namespace Cache\Adapter\Filesystem;

use Cache\Adapter\Common\AbstractCachePool;
use Cache\Adapter\Common\Exception\InvalidArgumentException;
use Cache\Adapter\Common\PhpCacheItem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemException;

class FilesystemCachePool extends AbstractCachePool
{
    private FilesystemOperator $filesystem;
    private string $folder;

    public function __construct(FilesystemOperator $filesystem, string $folder = 'cache')
    {
        $this->folder = rtrim($folder, '/');
        $this->filesystem = $filesystem;

        try {
            $this->filesystem->createDirectory($this->folder);
        } catch (FilesystemException $e) {
            // Handle initialization errors if necessary
        }
    }

    public function setFolder(string $folder)
    {
        $this->folder = rtrim($folder, '/');
    }

    protected function fetchObjectFromCache($key)
    {
        $empty = [false, null, [], null];
        $file = $this->getFilePath($key);

        try {
            $data = unserialize($this->filesystem->read($file));
            if ($data === false) {
                return $empty;
            }
        } catch (FilesystemException $e) {
            return $empty;
        }

        $expirationTimestamp = $data[2] ?: null;
        if ($expirationTimestamp !== null && time() > $expirationTimestamp) {
            foreach ($data[1] as $tag) {
                $this->removeListItem($this->getTagKey($tag), $key);
            }
            $this->forceClear($key);
            return $empty;
        }

        return [true, $data[0], $data[1], $expirationTimestamp];
    }

    protected function clearAllObjectsFromCache()
    {
        try {
            $this->filesystem->deleteDirectory($this->folder);
            $this->filesystem->createDirectory($this->folder);
        } catch (FilesystemException $e) {
            return false;
        }

        return true;
    }

    protected function clearOneObjectFromCache($key)
    {
        return $this->forceClear($key);
    }

    protected function storeItemInCache(PhpCacheItem $item, $ttl)
    {
        $data = serialize([
            $item->get(),
            $item->getTags(),
            $item->getExpirationTimestamp(),
        ]);

        $file = $this->getFilePath($item->getKey());

        try {
            $this->filesystem->write($file, $data);
        } catch (FilesystemException $e) {
            return false;
        }

        return true;
    }

    private function getFilePath($key)
    {
        if (!preg_match('|^[a-zA-Z0-9_\.! ]+$|', $key)) {
            throw new InvalidArgumentException(sprintf('Invalid key "%s". Valid filenames must match [a-zA-Z0-9_\.! ].', $key));
        }

        return sprintf('%s/%s', $this->folder, $key);
    }

    protected function getList($name)
    {
        $file = $this->getFilePath($name);

        try {
            if (!$this->filesystem->fileExists($file)) {
                $this->filesystem->write($file, serialize([]));
            }
            return unserialize($this->filesystem->read($file));
        } catch (FilesystemException $e) {
            return [];
        }
    }

    protected function removeList($name)
    {
        try {
            $this->filesystem->delete($this->getFilePath($name));
        } catch (FilesystemException $e) {
            // Ignore errors if file doesn't exist
        }
    }

    protected function appendListItem($name, $key)
    {
        $list = $this->getList($name);
        $list[] = $key;

        try {
            $this->filesystem->write($this->getFilePath($name), serialize($list));
        } catch (FilesystemException $e) {
            return false;
        }

        return true;
    }

    protected function removeListItem($name, $key)
    {
        $list = $this->getList($name);
        $list = array_filter($list, fn($item) => $item !== $key);

        try {
            $this->filesystem->write($this->getFilePath($name), serialize($list));
        } catch (FilesystemException $e) {
            return false;
        }

        return true;
    }

    private function forceClear($key)
    {
        try {
            $this->filesystem->delete($this->getFilePath($key));
        } catch (FilesystemException $e) {
            return true;
        }

        return true;
    }
}
