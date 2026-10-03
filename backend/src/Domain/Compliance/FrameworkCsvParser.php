<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Entity\Requirement;

final class FrameworkCsvParser
{
    public const MAX_BYTES = 1048576;
    public const MAX_ROWS = 500;
    private const HEADERS = ['reference', 'title', 'category', 'description', 'parentReference'];

    /** @param iterable<Requirement> $requirements */
    public function export(iterable $requirements): string
    {
        $stream = fopen('php://temp', 'w+');
        if (false === $stream) throw new \RuntimeException('CSV stream unavailable.');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, self::HEADERS, ',', '"', '');
            $count = 0;
            foreach ($requirements as $item) {
                if (++$count > self::MAX_ROWS) throw new \InvalidArgumentException('Maximum 500 exigences pour un fichier réimportable. Aucun export partiel créé.');
                $row = [$item->getReference(), $item->getTitle(), $item->getCategory(), $item->getDescription() ?? '', $item->getParentRequirement()?->getReference() ?? ''];
                fputcsv($stream, array_map(static fn (string $value): string => preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value, $row), ',', '"', '');
                if (ftell($stream) > self::MAX_BYTES) throw new \InvalidArgumentException('Le fichier dépasse 1 Mio. Aucun export partiel créé.');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if (false === $csv) throw new \RuntimeException('CSV stream unavailable.');
            $this->parse($csv);
            return $csv;
        } finally {
            fclose($stream);
        }
    }

    /** @return list<array{reference: string, title: string, category: string, description: string, parentReference: string}> */
    public function parse(string $csv): array
    {
        if ('' === trim($csv) || strlen($csv) > self::MAX_BYTES || !mb_check_encoding($csv, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $csv)) {
            throw new \InvalidArgumentException('CSV UTF-8 requis, non vide, sans caractères de contrôle et limité à 1 Mio.');
        }
        $stream = fopen('php://temp', 'w+');
        if (false === $stream) throw new \RuntimeException('CSV stream unavailable.');
        try {
            fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
            rewind($stream);
            $headers = fgetcsv($stream, null, ',', '"', '');
            if (self::HEADERS !== $headers) {
                throw new \InvalidArgumentException('En-tête attendu : reference,title,category,description,parentReference (séparateur virgule).');
            }
            $rows = []; $references = []; $line = 1;
            while (false !== ($values = fgetcsv($stream, null, ',', '"', ''))) {
                ++$line;
                if ([null] === $values) continue;
                if (count($rows) >= self::MAX_ROWS) throw new \InvalidArgumentException('Maximum 500 exigences par import.');
                if (5 !== count($values)) throw new \InvalidArgumentException('Enregistrement '.$line.' : cinq colonnes requises.');
                $values = array_map(trim(...), $values);
                foreach ([100, 255, 120, 10000, 100] as $index => $maximum) {
                    if (mb_strlen($values[$index]) > $maximum || ($index < 3 && '' === $values[$index])) {
                        throw new \InvalidArgumentException('Enregistrement '.$line.' : champ obligatoire vide ou trop long.');
                    }
                }
                // Prefix keys so numeric references retain their textual identity.
                $key = 'ref:'.$values[0];
                if (isset($references[$key])) throw new \InvalidArgumentException('Enregistrement '.$line.' : référence en doublon.');
                $references[$key] = count($rows);
                $rows[] = array_combine($headers, $values);
            }
            if ([] === $rows) throw new \InvalidArgumentException('Le CSV ne contient aucune exigence.');
            foreach ($rows as $row) {
                $seen = []; $current = $row;
                while ('' !== $current['parentReference']) {
                    $key = 'ref:'.$current['reference'];
                    if (isset($seen[$key])) throw new \InvalidArgumentException('La hiérarchie contient une boucle.');
                    $seen[$key] = true;
                    $parent = 'ref:'.$current['parentReference'];
                    if (!isset($references[$parent])) throw new \InvalidArgumentException('Une référence parente est absente du CSV.');
                    $current = $rows[$references[$parent]];
                }
            }
            return $rows;
        } finally {
            fclose($stream);
        }
    }
}
