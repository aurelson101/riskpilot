<?php

declare(strict_types=1);

namespace App\Application;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class IsmsDocumentStorage
{
    public const MAX_SIZE = 10_485_760;
    private const EXTENSIONS = ['doc', 'docx'];
    private const MIME_TYPES = ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream', 'application/x-ole-storage'];

    public function __construct(private string $directory)
    {
    }

    /** @return array{storageName: string, mimeType: string, size: int} */
    public function store(UploadedFile $file): array
    {
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());
        $mimeType = (string) ($file->getMimeType() ?? $file->getClientMimeType());
        $size = (int) $file->getSize();
        if (!$file->isValid() || !in_array($extension, self::EXTENSIONS, true) || !in_array($mimeType, self::MIME_TYPES, true) || $size < 1 || $size > self::MAX_SIZE) {
            throw new \InvalidArgumentException('Seuls les fichiers Word .doc ou .docx de 10 Mo maximum sont acceptés.');
        }
        $this->assertWordFormat($file, $extension);
        if (!is_dir($this->directory) && !mkdir($concurrentDirectory = $this->directory, 0750, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException('Le stockage documentaire est indisponible.');
        }
        $storageName = bin2hex(random_bytes(24)).'.'.$extension;
        $file->move($this->directory, $storageName);

        return ['storageName' => $storageName, 'mimeType' => $mimeType, 'size' => $size];
    }

    public function path(string $storageName): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.basename($storageName);
    }

    public function delete(?string $storageName): void
    {
        if (null === $storageName) {
            return;
        }
        $path = $this->path($storageName);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function assertWordFormat(UploadedFile $file, string $extension): void
    {
        if ('doc' === $extension) {
            $handle = fopen($file->getPathname(), 'rb');
            $signature = false === $handle ? false : fread($handle, 8);
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" !== $signature) {
                throw new \InvalidArgumentException('Le fichier .doc n’est pas un document Word valide.');
            }

            return;
        }
        $archive = new \ZipArchive();
        $opened = true === $archive->open($file->getPathname());
        if (!$opened || false === $archive->locateName('[Content_Types].xml') || false === $archive->locateName('word/document.xml')) {
            if ($opened) {
                $archive->close();
            }
            throw new \InvalidArgumentException('Le fichier .docx n’est pas un document Word valide.');
        }
        $archive->close();
    }
}
