<?php
declare(strict_types = 1);

namespace app\service;

interface StorageInterface
{
    public function upload($file, $directory, $fileName);
    public function delete($filePath);
    public function getUrl($filePath);
}
