<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$folder = safe_relative_directory((string) ($_GET['folder'] ?? ''));
if ($folder === null || $folder === '' || absolute_directory_path($folder) === null) {
    http_response_code(400);
    exit('Folder tidak valid.');
}
$folderRules = load_folder_visibility();
if (folder_is_unlisted($folder, $folderRules)) {
    http_response_code(404);
    exit('Folder tidak ditemukan.');
}

$documents = load_documents();
$matching = [];
$folderPrefix = $folder . '/';

foreach ($documents as $path => $doc) {
    if (is_string($path) && is_array($doc) && str_starts_with($path, $folderPrefix)
        && document_access_level($doc, $path, $folderRules) === 'public') {
        $abs = absolute_file_path($path);
        if ($abs !== null && is_file($abs)) {
            $matching[$path] = [
                'abs' => $abs,
                'name' => document_display_name($doc, $path),
                'subpath' => substr($path, strlen($folderPrefix)),
            ];
        }
    }
}

if (!$matching) {
    http_response_code(404);
    exit('Tidak ada berkas yang ditemukan dalam folder ini.');
}

// Simple pure-PHP Zip packager (zero extensions required, 100% portable)
class FolderZipPacker {
    private array $files = [];

    public function addFile(string $data, string $filename): void {
        $this->files[] = [
            'name' => str_replace('\\', '/', $filename),
            'data' => $data,
            'time' => time(),
        ];
    }

    public function stream(string $zipName): void {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . rawurlencode($zipName) . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        $ctrlDir = '';
        $dataBlocks = '';
        $offset = 0;

        foreach ($this->files as $file) {
            $name = $file['name'];
            $data = $file['data'];
            $uncLen = strlen($data);
            $crc = crc32($data);

            $gzData = function_exists('gzdeflate') ? gzdeflate($data) : false;
            if ($gzData === false || strlen($gzData) >= $uncLen) {
                $method = 0;
                $cData = $data;
                $cLen = $uncLen;
            } else {
                $method = 8;
                $cData = $gzData;
                $cLen = strlen($gzData);
            }

            $time = getdate($file['time']);
            $dosTime = (($time['year'] - 1980) << 25)
                     | ($time['mon'] << 21)
                     | ($time['mday'] << 16)
                     | ($time['hours'] << 11)
                     | ($time['minutes'] << 5)
                     | ($time['seconds'] >> 1);

            $header = "\x50\x4B\x03\x04"
                    . "\x14\x00\x00\x08"
                    . pack('v', $method)
                    . pack('V', $dosTime)
                    . pack('V', $crc)
                    . pack('V', $cLen)
                    . pack('V', $uncLen)
                    . pack('v', strlen($name))
                    . pack('v', 0)
                    . $name;

            $dataBlocks .= $header . $cData;

            $ctrlDir .= "\x50\x4B\x01\x02"
                     . "\x14\x00\x14\x00\x00\x08"
                     . pack('v', $method)
                     . pack('V', $dosTime)
                     . pack('V', $crc)
                     . pack('V', $cLen)
                     . pack('V', $uncLen)
                     . pack('v', strlen($name))
                     . pack('v', 0)
                     . pack('v', 0)
                     . pack('v', 0)
                     . pack('v', 0)
                     . pack('V', 0)
                     . pack('V', $offset)
                     . $name;

            $offset = strlen($dataBlocks);
        }

        $eocd = "\x50\x4B\x05\x06"
              . "\x00\x00\x00\x00"
              . pack('v', count($this->files))
              . pack('v', count($this->files))
              . pack('V', strlen($ctrlDir))
              . pack('V', strlen($dataBlocks))
              . pack('v', 0);

        echo $dataBlocks . $ctrlDir . $eocd;
        flush();
    }
}

$packer = new FolderZipPacker();
foreach ($matching as $item) {
    $content = file_get_contents($item['abs']);
    if ($content !== false) {
        // Use human display filename if at root of folder, otherwise keep subpath
        $inZipName = $item['subpath'] !== '' ? $item['subpath'] : $item['name'];
        $packer->addFile($content, $inZipName);
    }
}

$zipName = basename($folder) . '.zip';
$packer->stream($zipName);
exit;
