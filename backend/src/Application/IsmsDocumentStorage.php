<?php

declare(strict_types=1);

namespace App\Application;

use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class IsmsDocumentStorage
{
    public const MAX_SIZE = 10_485_760;
    private const MAX_DOCX_ENTRIES = 2_000;
    private const MAX_DOCX_UNCOMPRESSED_SIZE = 52_428_800;
    private const EXTENSIONS = ['doc', 'docx'];
    private const MIME_TYPES = ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream', 'application/x-ole-storage'];

    private ?S3Client $s3;

    public function __construct(private string $directory, private DocumentAntivirus $antivirus, private int $quotaBytes, private string $s3Endpoint, private string $s3Bucket, private string $s3Region, private string $s3AccessKey, private string $s3SecretKey)
    {
        $this->s3 = '' === trim($this->s3Bucket) ? null : new S3Client(['version' => 'latest', 'region' => $this->s3Region, 'endpoint' => '' === trim($this->s3Endpoint) ? null : $this->s3Endpoint, 'use_path_style_endpoint' => '' !== trim($this->s3Endpoint), 'credentials' => ['key' => $this->s3AccessKey, 'secret' => $this->s3SecretKey]]);
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
        $this->antivirus->scan($file->getPathname());
        if (!is_dir($this->directory) && !mkdir($concurrentDirectory = $this->directory, 0750, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException('Le stockage documentaire est indisponible.');
        }
        if ($this->usedBytes() + $size > $this->quotaBytes) {
            throw new \InvalidArgumentException('Le quota de stockage documentaire est atteint.');
        }
        $storageName = bin2hex(random_bytes(24)).'.'.$extension;
        $checksum = hash_file('sha256', $file->getPathname());
        if (false === $checksum) {
            throw new \RuntimeException('Impossible de calculer l’empreinte du document.');
        }
        if (null === $this->s3) {
            $file->move($this->directory, $storageName);
        } else {
            $arguments = ['Bucket' => $this->s3Bucket, 'Key' => $storageName, 'SourceFile' => $file->getPathname(), 'ContentType' => $mimeType];
            if ('' === trim($this->s3Endpoint)) {
                $arguments['ServerSideEncryption'] = 'AES256';
            }
            $this->s3->putObject($arguments);
        }

        return ['storageName' => $storageName, 'mimeType' => $mimeType, 'size' => $size, 'checksum' => $checksum];
    }

    private function usedBytes(): int
    {
        if (null !== $this->s3) {
            $bytes = 0;
            $token = null;
            do {
                $arguments = ['Bucket' => $this->s3Bucket];
                if (null !== $token) {
                    $arguments['ContinuationToken'] = $token;
                }
                $result = $this->s3->listObjectsV2($arguments);
                foreach ($result['Contents'] ?? [] as $object) {
                    $bytes += (int) ($object['Size'] ?? 0);
                }
                $token = true === ($result['IsTruncated'] ?? false) ? (string) ($result['NextContinuationToken'] ?? '') : null;
            } while (null !== $token && '' !== $token);

            return $bytes;
        }
        $bytes = 0;
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            $bytes += is_file($path) ? (int) filesize($path) : 0;
        }

        return $bytes;
    }

    public function path(string $storageName): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.basename($storageName);
    }

    public function exists(string $storageName): bool
    {
        if (null === $this->s3) {
            return is_file($this->path($storageName));
        }
        try {
            return $this->s3->doesObjectExistV2($this->s3Bucket, basename($storageName));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return resource|false */
    public function open(string $storageName)
    {
        if (null === $this->s3) {
            return fopen($this->path($storageName), 'rb');
        }
        try {
            return $this->s3->getObject(['Bucket' => $this->s3Bucket, 'Key' => basename($storageName)])['Body']->detach();
        } catch (\Throwable) {
            return false;
        }
    }

    public function delete(?string $storageName): void
    {
        if (null === $storageName) {
            return;
        }
        if (null !== $this->s3) {
            $this->s3->deleteObject(['Bucket' => $this->s3Bucket, 'Key' => basename($storageName)]);

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
