<?php

declare(strict_types=1);

namespace App\Application;

use Dompdf\Dompdf;
use Dompdf\Options;

final class PdfReportRenderer
{
    /** @param array<string, mixed> $data */
    public function render(string $title, string $subtitle, array $data): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml($this->document($title, $subtitle, $data), 'UTF-8');
        $pdf->render();
        $pdf->addInfo('Title', $title);
        $pdf->addInfo('Author', 'RiskPilot');
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(510, 810, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.35, 0.42, 0.5]);

        return $pdf->output();
    }

    /** @param array<string, mixed> $data */
    private function document(string $title, string $subtitle, array $data): string
    {
        return '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>'.
            '@page{margin:24mm 16mm 20mm}body{font-family:"DejaVu Sans",sans-serif;color:#17324d;font-size:10px;line-height:1.45}'.
            'header{border-bottom:3px solid #0284c7;margin-bottom:18px;padding-bottom:12px}h1{font-size:22px;margin:0 0 5px;color:#075985}'.
            '.subtitle{color:#52677a;font-size:10px}.meta{margin-top:8px;color:#66788a}h2{font-size:14px;color:#075985;margin:18px 0 7px;page-break-after:avoid}'.
            'table{width:100%;border-collapse:collapse;margin:0 0 10px;page-break-inside:auto}tr{page-break-inside:avoid}th,td{border:1px solid #cbd5e1;padding:6px;vertical-align:top}'.
            'th{width:31%;background:#eaf5fb;text-align:left;color:#164e63}.list{margin:0;padding-left:16px}.empty{color:#64748b;font-style:italic}'.
            'footer{position:fixed;bottom:-12mm;left:0;right:0;border-top:1px solid #d8e1e8;padding-top:4px;color:#64748b;font-size:8px}'.
            '</style></head><body><header><h1>'.$this->escape($title).'</h1><div class="subtitle">'.$this->escape($subtitle).'</div>'.
            '<div class="meta">Généré par RiskPilot le '.$this->escape((new \DateTimeImmutable())->format('d/m/Y H:i T')).'</div></header>'.
            $this->sections($data).'<footer>RiskPilot · Rapport gouverné et reproductible</footer></body></html>';
    }

    /** @param array<string, mixed> $data */
    private function sections(array $data): string
    {
        $html = '';
        foreach ($data as $key => $value) {
            $html .= '<h2>'.$this->escape($this->label((string) $key)).'</h2>'.$this->value($value);
        }

        return $html;
    }

    private function value(mixed $value): string
    {
        if (null === $value || '' === $value || [] === $value) {
            return '<p class="empty">Non renseigné</p>';
        }
        if (is_bool($value)) {
            return '<p>'.($value ? 'Oui' : 'Non').'</p>';
        }
        if (is_scalar($value)) {
            return '<p>'.$this->escape((string) $value).'</p>';
        }
        if (!is_array($value)) {
            return '<p>'.$this->escape((string) $value).'</p>';
        }
        if (array_is_list($value)) {
            $items = '';
            foreach ($value as $item) {
                $items .= '<li>'.$this->value($item).'</li>';
            }

            return '<ul class="list">'.$items.'</ul>';
        }
        $rows = '';
        foreach ($value as $key => $item) {
            $rows .= '<tr><th>'.$this->escape($this->label((string) $key)).'</th><td>'.$this->value($item).'</td></tr>';
        }

        return '<table>'.$rows.'</table>';
    }

    private function label(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace(['_', '-'], ' ', $value)) ?? $value;

        return ucfirst(trim($value));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
