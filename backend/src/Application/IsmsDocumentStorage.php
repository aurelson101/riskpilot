<?php

declare(strict_types=1);

namespace App\Application;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class IsmsDocumentStorage
{
    public const MAX_SIZE = 10_485_760;
    private const MAX_DOCX_ENTRIES = 2_000;
    private const MAX_DOCX_UNCOMPRESSED_SIZE = 52_428_800;
    private const EXTENSIONS = ['doc', 'docx'];
    private const MIME_TYPES = ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream', 'application/x-ole-storage'];

    public function __construct(private string $directory)
    {
    }

    /** @return array{storageName: string, mimeType: string, size: int, checksum: string} */
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
        $path = $this->path($storageName);
        $checksum = hash_file('sha256', $path);
        if (false === $checksum) {
            $this->delete($storageName);
            throw new \RuntimeException('Impossible de calculer l’empreinte du document.');
        }

        return ['storageName' => $storageName, 'mimeType' => $mimeType, 'size' => $size, 'checksum' => $checksum];
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
        $uncompressedSize = 0;
        if ($archive->numFiles > self::MAX_DOCX_ENTRIES) {
            $archive->close();
            throw new \InvalidArgumentException('Le fichier .docx contient trop d’éléments.');
        }
        for ($index = 0; $index < $archive->numFiles; ++$index) {
            $statistics = $archive->statIndex($index);
            $uncompressedSize += is_array($statistics) ? (int) $statistics['size'] : 0;
            if ($uncompressedSize > self::MAX_DOCX_UNCOMPRESSED_SIZE) {
                $archive->close();
                throw new \InvalidArgumentException('Le contenu décompressé du fichier .docx est trop volumineux.');
            }
        }
        $archive->close();
    }
}
