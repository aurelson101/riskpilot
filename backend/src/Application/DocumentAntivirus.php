<?php

declare(strict_types=1);

namespace App\Application;

final readonly class DocumentAntivirus
{
    public function __construct(private string $host)
    {
    }

    public function scan(string $path): void
    {
        if ('' === trim($this->host)) {
            return;
        }
        $socket = @stream_socket_client(sprintf('tcp://%s:3310', $this->host), $errorCode, $errorMessage, 3.0);
        if (!is_resource($socket)) {
            throw new \RuntimeException('Le service antivirus est indisponible. Le fichier n’a pas été enregistré.');
        }
        $file = fopen($path, 'rb');
        if (!is_resource($file)) {
            fclose($socket);
            throw new \RuntimeException('Le fichier ne peut pas être analysé.');
        }
        fwrite($socket, "zINSTREAM\0");
        while (!feof($file)) {
            $chunk = fread($file, 8192);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            fwrite($socket, pack('N', strlen($chunk)).$chunk);
        }
        fwrite($socket, pack('N', 0));
        $result = stream_get_contents($socket);
        fclose($file);
        fclose($socket);
        if (!is_string($result) || !str_contains($result, 'OK')) {
            throw new \InvalidArgumentException('Le fichier a été refusé par l’analyse antivirus.');
        }
    }
}
