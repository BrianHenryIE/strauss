<?php
/**
 * This trait implements all the methods of the FlysystemAdapterInterface and proxies them to another
 * filesystem adapter.
 */

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;

/**
 * implements FilesystemAdapter
 */
trait ProxyFlysystemAdapterTrait
{
    protected FilesystemAdapter $proxyFilesystemAdapter;
    
    public function setProxyFilesystemAdapter(FilesystemAdapter $proxyFilesystemAdapter): void
    {
        $this->proxyFilesystemAdapter = $proxyFilesystemAdapter;
    }
    
    public function fileExists(string $path): bool
    {
        return call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function read(string $path): string
    {
        return call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function lastModified(string $path): FileAttributes
    {
        return call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function fileSize(string $path): FileAttributes
    {
        return call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function mimeType(string $path): FileAttributes
    {
        return call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function visibility(string $path): FileAttributes
    {
        return call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function write(string $path, string $contents, Config $config): void
    {
        call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function setVisibility(string $path, string $visibility): void
    {
        call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function delete(string $path): void
    {
        call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function deleteDirectory(string $path): void
    {
        call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function createDirectory(string $path, Config $config): void
    {
        call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function move(string $source, string $destination, Config $config): void
    {
        call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }

    public function readStream(string $path)
    {
        return call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
    }
}
