<?php

declare(strict_types=1);

namespace App\Application;

use Symfony\Component\HttpFoundation\Response;

/**
 * Export CSV unique pour toute l'application : UTF-8 avec BOM (Excel), fins de ligne CRLF, séparateur configurable
 * (point-virgule par défaut, lu directement par Excel en France), dates formatées et protection contre l'injection de formules.
 */
final class CsvExporter
{
    /**
     * @param list<list<int|float|string|bool|\DateTimeInterface|null>> $rows la première ligne contient les en-têtes
     */
    public function render(array $rows, string $delimiter = ';', string $dateFormat = 'd/m/Y'): string
    {
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new \RuntimeException('Unable to create export.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (mixed $value): string => $this->cell($value, $dateFormat), $row), $delimiter, '"', '', "\r\n");
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return false === $content ? '' : $content;
    }

    /** @param list<list<int|float|string|bool|\DateTimeInterface|null>> $rows */
    public function response(array $rows, string $filename, string $locale = 'fr', string $delimiter = ';', string $dateFormat = 'd/m/Y'): Response
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? 'export.csv';

        return new Response($this->render($rows, $delimiter, $dateFormat), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$safeName.'"',
            'Cache-Control' => 'private, no-store',
            'Content-Language' => 'en' === $locale ? 'en' : 'fr',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Les nombres et les dates sont écrits tels quels (un montant négatif reste un nombre) ; seul un texte pouvant être
     * interprété comme une formule par le tableur est neutralisé (OWASP « CSV injection »).
     */
    private function cell(mixed $value, string $dateFormat): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format($dateFormat);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $text = (string) $value;

        return 1 === preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $text) && !is_numeric(trim($text)) ? "'".$text : $text;
    }
}
