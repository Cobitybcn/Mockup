<?php
declare(strict_types=1);

/**
 * Minimal ZIP writer with no extension dependency (ext-zip is absent in this
 * runtime). Entries are stored uncompressed: the payloads are JPEG/PNG images
 * that are already compressed, so deflating them would cost CPU for ~0% gain.
 *
 * Writes straight to a file handle, one entry at a time, so a package of large
 * images never has to sit in memory as a whole.
 */
final class ZipPackage
{
    private const LOCAL_HEADER = "\x50\x4b\x03\x04";
    private const CENTRAL_HEADER = "\x50\x4b\x01\x02";
    private const END_OF_CENTRAL_DIRECTORY = "\x50\x4b\x05\x06";
    /** Bit 11: file names are UTF-8. */
    private const FLAG_UTF8 = 0x0800;

    /** @var resource */
    private $handle;
    /** @var list<array{name:string,crc:int,size:int,offset:int}> */
    private array $entries = [];
    private int $offset = 0;
    private int $timestamp;

    public function __construct(string $path, ?int $timestamp = null)
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo crear el paquete comprimido.');
        }
        $this->handle = $handle;
        $this->timestamp = $timestamp ?? time();
    }

    public function addFileFromPath(string $name, string $sourcePath): void
    {
        $contents = @file_get_contents($sourcePath);
        if ($contents === false) {
            throw new RuntimeException('No se pudo leer un archivo del paquete: ' . basename($sourcePath));
        }
        $this->addFromString($name, $contents);
    }

    public function addFromString(string $name, string $contents): void
    {
        $name = str_replace('\\', '/', trim($name, '/'));
        $crc = crc32($contents);
        $size = strlen($contents);

        $this->entries[] = ['name' => $name, 'crc' => $crc, 'size' => $size, 'offset' => $this->offset];
        $header = self::LOCAL_HEADER
            . pack('v', 20)                 // version needed to extract
            . pack('v', self::FLAG_UTF8)
            . pack('v', 0)                  // stored, no compression
            . pack('v', $this->dosTime())
            . pack('v', $this->dosDate())
            . pack('V', $crc)
            . pack('V', $size)              // compressed size == size (stored)
            . pack('V', $size)
            . pack('v', strlen($name))
            . pack('v', 0)                  // no extra field
            . $name;
        $this->write($header);
        $this->write($contents);
    }

    /** Writes the central directory and closes the file. */
    public function finish(): void
    {
        $centralDirectoryOffset = $this->offset;
        foreach ($this->entries as $entry) {
            $record = self::CENTRAL_HEADER
                . pack('v', 20)             // version made by
                . pack('v', 20)             // version needed
                . pack('v', self::FLAG_UTF8)
                . pack('v', 0)
                . pack('v', $this->dosTime())
                . pack('v', $this->dosDate())
                . pack('V', $entry['crc'])
                . pack('V', $entry['size'])
                . pack('V', $entry['size'])
                . pack('v', strlen($entry['name']))
                . pack('v', 0)              // extra
                . pack('v', 0)              // comment
                . pack('v', 0)              // disk number
                . pack('v', 0)              // internal attributes
                . pack('V', 0)              // external attributes
                . pack('V', $entry['offset'])
                . $entry['name'];
            $this->write($record);
        }
        $centralDirectorySize = $this->offset - $centralDirectoryOffset;
        $end = self::END_OF_CENTRAL_DIRECTORY
            . pack('v', 0)                  // this disk
            . pack('v', 0)                  // disk with central directory
            . pack('v', count($this->entries))
            . pack('v', count($this->entries))
            . pack('V', $centralDirectorySize)
            . pack('V', $centralDirectoryOffset)
            . pack('v', 0);                 // no comment
        $this->write($end);
        fclose($this->handle);
    }

    private function write(string $bytes): void
    {
        $written = fwrite($this->handle, $bytes);
        if ($written === false) {
            throw new RuntimeException('No se pudo escribir el paquete comprimido.');
        }
        $this->offset += $written;
    }

    private function dosTime(): int
    {
        $parts = getdate($this->timestamp);
        return ($parts['hours'] << 11) | ($parts['minutes'] << 5) | ((int)($parts['seconds'] / 2));
    }

    private function dosDate(): int
    {
        $parts = getdate($this->timestamp);
        return (max(0, $parts['year'] - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday'];
    }
}
