<?php

declare(strict_types=1);

namespace App\Application;

use Dompdf\Dompdf;
use Dompdf\Options;

final class PdfReportRenderer
{
    /** @param array<string, mixed> $data */
    public function renderAnnualReport(string $title, array $data): string
    {
        return $this->renderDocument($title, $this->annualDocument($title, $data));
    }

    /** @param array<string, mixed> $data */
    public function renderDecisionReport(string $title, array $data): string
    {
        return $this->renderDocument($title, $this->decisionDocument($title, $data));
    }

    private function renderDocument(string $title, string $html): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $pdf->addInfo('Title', $title);
        $pdf->addInfo('Author', 'RiskPilot');
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(510, 810, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.35, 0.42, 0.5]);

        return $pdf->output();
    }

    /** @param array<string, mixed> $data */
    private function annualDocument(string $title, array $data): string
    {
        $year = (int) ($data['year'] ?? 0);
        $version = (int) ($data['version'] ?? 1);
        $organization = (string) ($data['organization'] ?? 'Organisation non renseignée');
        $period = (array) ($data['period'] ?? []);
        $totals = (array) ($data['totals'] ?? []);
        $maturity = (array) ($data['maturity'] ?? []);
        $assessments = (array) ($maturity['assessments'] ?? []);
        $weaknesses = (array) ($maturity['weaknesses'] ?? []);
        $generatedBy = (array) ($data['generatedBy'] ?? []);
        $average = $maturity['average'] ?? null;

        $months = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        $monthRows = '';
        $maxMonth = max(1, ...array_map(static fn (array $item): int => (int) ($item['count'] ?? 0), (array) ($data['byMonth'] ?? [])));
        foreach ((array) ($data['byMonth'] ?? []) as $item) {
            $month = (int) ($item['month'] ?? 0);
            $count = (int) ($item['count'] ?? 0);
            $monthRows .= $this->metricRow($months[$month - 1] ?? (string) $month, $count, $maxMonth);
        }

        $maturityRows = '';
        foreach ($assessments as $domain => $assessment) {
            $assessment = (array) $assessment;
            $assessed = true === ($assessment['assessed'] ?? false);
            $score = $assessed ? (float) ($assessment['score'] ?? 0) : null;
            $maturityRows .= '<tr><td><strong>'.$this->escape($this->domainLabel((string) $domain)).'</strong></td>'.
                '<td class="score">'.(null === $score ? 'Non évalué' : $this->escape(number_format($score, 1, ',', '').' / 5')).'</td>'.
                '<td>'.(null === $score ? '<span class="empty">À compléter</span>' : '<div class="meter"><span style="width:'.($score * 20).'%"></span></div>').'</td>'.
                '<td>'.$this->escape((string) ($assessment['rationale'] ?? '') ?: 'Non renseignée').'</td></tr>';
        }

        $activityRows = '';
        foreach ((array) ($data['activities'] ?? []) as $activity) {
            $activity = (array) $activity;
            $activityRows .= '<tr><td>'.$this->escape($this->date((string) ($activity['occurredAt'] ?? ''))).'</td><td>'.$this->escape($this->domainLabel((string) ($activity['domain'] ?? ''))).'</td><td>'.$this->escape($this->actionLabel((string) ($activity['action'] ?? ''))).'</td><td>'.$this->escape((string) ($activity['entityType'] ?? '')).' #'.$this->escape((string) ($activity['entityId'] ?? '')).'</td><td>'.$this->escape((string) ($activity['actor'] ?? '')).'</td><td>'.(true === ($activity['sealed'] ?? false) ? 'Scellée' : 'Non scellée').'</td></tr>';
        }

        $css = '@page{margin:20mm 14mm 18mm}body{font-family:"DejaVu Sans",sans-serif;color:#17324d;font-size:9px;line-height:1.4}header{border-bottom:3px solid #0284c7;padding-bottom:11px;margin-bottom:14px}h1{font-size:21px;color:#075985;margin:0 0 4px}h2{font-size:14px;color:#075985;margin:18px 0 7px;page-break-after:avoid}h3{font-size:11px;color:#164e63;margin:12px 0 5px}.meta{color:#52677a}.cards{width:100%;border-collapse:separate;border-spacing:5px}.card{border:1px solid #bae6fd;background:#f0f9ff;padding:8px;text-align:center}.card strong{display:block;font-size:17px;color:#0369a1}.notice{background:#f8fafc;border-left:4px solid #38bdf8;padding:8px;margin:8px 0}.warning{border-left-color:#f59e0b;background:#fffbeb}table{width:100%;border-collapse:collapse;margin-bottom:9px}tr{page-break-inside:avoid}th,td{border:1px solid #cbd5e1;padding:5px;vertical-align:top}th{background:#eaf5fb;text-align:left;color:#164e63}.score{white-space:nowrap;width:58px}.meter{height:7px;background:#e2e8f0}.meter span{display:block;height:7px;background:#0284c7}.bar{height:6px;background:#e2e8f0;width:100px;display:inline-block;margin-right:5px}.bar span{display:block;height:6px;background:#38bdf8}.empty{color:#64748b;font-style:italic}.page-break{page-break-before:always}footer{position:fixed;bottom:-11mm;left:0;right:0;border-top:1px solid #d8e1e8;padding-top:4px;color:#64748b;font-size:8px}';
        $periodLabel = $this->date((string) ($period['from'] ?? '')).' au '.$this->date((string) ($period['until'] ?? ''));
        $weaknessLabel = [] === $weaknesses ? 'Aucune faiblesse évaluée à 2 ou moins.' : 'Faiblesses prioritaires : '.implode(', ', array_map(fn (mixed $item): string => $this->domainLabel((string) $item), $weaknesses)).'.';

        return '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>'.$css.'</style></head><body>'.
            '<header><h1>'.$this->escape($title).'</h1><div class="meta">'.$this->escape($organization).' · Exercice '.$year.' · Version '.$version.'<br>Période : '.$this->escape($periodLabel).' · Généré le '.$this->escape($this->dateTime((string) ($data['generatedAt'] ?? ''))).' par '.$this->escape((string) ($generatedBy['name'] ?? 'RiskPilot')).'</div></header>'.
            '<h2>1. Synthèse exécutive</h2><table class="cards"><tr><td class="card"><strong>'.(int) ($totals['activities'] ?? 0).'</strong>activités tracées</td><td class="card"><strong>'.(int) ($totals['contributors'] ?? 0).'</strong>contributeurs</td><td class="card"><strong>'.(int) ($totals['domains'] ?? 0).'</strong>domaines actifs</td><td class="card"><strong>'.(null === $average ? '—' : $this->escape(number_format((float) $average, 2, ',', ''))).'</strong>maturité / 5</td></tr></table>'.
            '<div class="notice '.([] === $weaknesses ? '' : 'warning').'">'.$this->escape($weaknessLabel).' '.(true === ($maturity['complete'] ?? false) ? 'Les dix domaines sont évalués.' : (int) ($maturity['assessedDomains'] ?? 0).' domaine(s) sur 10 évalué(s) ; compléter les autres avant présentation en comité.').'</div>'.
            '<h2>2. Répartition de l’activité</h2><h3>Chronologie mensuelle</h3><table><thead><tr><th>Mois</th><th>Volume</th><th>Répartition</th></tr></thead><tbody>'.$monthRows.'</tbody></table>'.
            '<h3>Domaines</h3>'.$this->rankingTable((array) ($data['byDomain'] ?? []), 'Domaine').'<h3>Types d’action</h3>'.$this->rankingTable((array) ($data['byAction'] ?? []), 'Action').'<h3>Contributeurs</h3>'.$this->rankingTable((array) ($data['contributors'] ?? []), 'Contributeur').
            '<h2>3. Maturité cyber</h2><p>Échelle de 0 à 5 par pas de 0,5. Les domaines non évalués sont exclus de la moyenne.</p><table><thead><tr><th>Domaine</th><th>Score</th><th>Niveau</th><th>Justification</th></tr></thead><tbody>'.$maturityRows.'</tbody></table>'.
            '<h2>4. Méthodologie et limites</h2><p>'.$this->escape((string) ($data['methodology'] ?? 'Non renseignée')).'</p><div class="notice">Ce rapport est un instantané versionné. Il restitue les mutations journalisées sur la période ; il ne constitue pas à lui seul une certification réglementaire ni une preuve d’exhaustivité des activités non tracées.</div>'.
            '<h2 class="page-break">5. Annexe — journal détaillé</h2><p>'.count((array) ($data['activities'] ?? [])).' événement(s). Les valeurs métier avant/après et les données techniques du client sont volontairement exclues.</p><table><thead><tr><th>Date</th><th>Domaine</th><th>Action</th><th>Objet</th><th>Acteur</th><th>Intégrité</th></tr></thead><tbody>'.($activityRows ?: '<tr><td colspan="6" class="empty">Aucune activité sur la période.</td></tr>').'</tbody></table>'.
            '<footer>RiskPilot · Rapport annuel gouverné · '.$this->escape($organization).' · '.$year.'</footer></body></html>';
    }

    /** @param array<string, mixed> $data */
    private function decisionDocument(string $title, array $data): string
    {
        $snapshot = (array) ($data['snapshot'] ?? []);
        $blocks = array_map('strtolower', array_map('strval', (array) ($data['blocks'] ?? [])));
        $organization = (string) ($data['organization'] ?? 'Organisation non renseignée');
        $cards = ['Risques' => (int) ($snapshot['risks'] ?? 0), 'Contrôles' => (int) ($snapshot['controls'] ?? 0), 'Évaluations' => (int) ($snapshot['assessments'] ?? 0), 'Actions' => (int) ($snapshot['actions'] ?? 0), 'Tiers' => (int) ($snapshot['thirdParties'] ?? 0)];
        $cardHtml = '';
        foreach ($cards as $label => $value) {
            $cardHtml .= '<td class="card"><strong>'.$value.'</strong>'.$this->escape($label).'</td>';
        }
        $sections = '';
        if (in_array('risks', $blocks, true)) {
            $rows = '';
            foreach ((array) ($snapshot['riskItems'] ?? []) as $item) {
                $item = (array) $item;
                $rows .= '<tr><td>'.$this->escape((string) ($item['title'] ?? '')).'</td><td>'.$this->escape($this->domainLabel((string) ($item['status'] ?? ''))).'</td><td>'.(int) ($item['currentScore'] ?? 0).'</td><td>'.(int) ($item['residualScore'] ?? 0).'</td><td>'.$this->escape($this->domainLabel((string) ($item['treatment'] ?? ''))).'</td><td>'.$this->escape((string) ($item['owner'] ?? '')).'</td></tr>';
            }
            $sections .= '<h2>2. Risques prioritaires</h2>'.$this->decisionTable(['Risque', 'Statut', 'Actuel', 'Résiduel', 'Traitement', 'Responsable'], $rows, 'Aucun risque sélectionné.');
        }
        if (in_array('actions', $blocks, true)) {
            $rows = '';
            foreach ((array) ($snapshot['actionItems'] ?? []) as $item) {
                $item = (array) $item;
                $rows .= '<tr><td>'.$this->escape((string) ($item['title'] ?? '')).'</td><td>'.$this->escape($this->domainLabel((string) ($item['priority'] ?? ''))).'</td><td>'.$this->escape($this->domainLabel((string) ($item['status'] ?? ''))).'</td><td>'.(int) ($item['progress'] ?? 0).'%</td><td>'.$this->escape($this->date((string) ($item['dueAt'] ?? ''))).'</td><td>'.$this->escape((string) ($item['owner'] ?? '')).'</td></tr>';
            }
            $sections .= '<h2>3. Plans d’action prioritaires</h2>'.$this->decisionTable(['Action', 'Priorité', 'Statut', 'Avancement', 'Échéance', 'Responsable'], $rows, 'Aucune action sélectionnée.');
        }
        if (in_array('compliance', $blocks, true)) {
            $rows = '';
            foreach ((array) ($snapshot['complianceItems'] ?? []) as $item) {
                $item = (array) $item;
                $rows .= '<tr><td>'.$this->escape((string) ($item['framework'] ?? '')).'</td><td>'.$this->escape((string) ($item['scope'] ?? '')).'</td><td>'.$this->escape($this->domainLabel((string) ($item['status'] ?? ''))).'</td><td>'.$this->escape(number_format((float) ($item['score'] ?? 0), 1, ',', '').' %').'</td><td>'.$this->escape($this->date((string) ($item['assessedAt'] ?? ''))).'</td></tr>';
            }
            $sections .= '<h2>4. Situation de conformité</h2>'.$this->decisionTable(['Référentiel', 'Périmètre', 'Statut', 'Score', 'Évaluation'], $rows, 'Aucune évaluation sélectionnée.');
        }
        $css = '@page{margin:20mm 14mm 18mm}body{font-family:"DejaVu Sans",sans-serif;color:#17324d;font-size:9px;line-height:1.4}header{border-bottom:3px solid #0284c7;padding-bottom:11px;margin-bottom:14px}h1{font-size:21px;color:#075985;margin:0 0 4px}h2{font-size:14px;color:#075985;margin:18px 0 7px;page-break-after:avoid}.meta{color:#52677a}.cards{width:100%;border-collapse:separate;border-spacing:4px}.card{border:1px solid #bae6fd;background:#f0f9ff;padding:7px;text-align:center}.card strong{display:block;font-size:16px;color:#0369a1}table{width:100%;border-collapse:collapse;margin-bottom:9px}tr{page-break-inside:avoid}th,td{border:1px solid #cbd5e1;padding:5px;vertical-align:top}th{background:#eaf5fb;text-align:left;color:#164e63}.notice{background:#f8fafc;border-left:4px solid #38bdf8;padding:8px;margin:8px 0}.empty{color:#64748b;font-style:italic}footer{position:fixed;bottom:-11mm;left:0;right:0;border-top:1px solid #d8e1e8;padding-top:4px;color:#64748b;font-size:8px}';

        return '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>'.$css.'</style></head><body><header><h1>'.$this->escape($title).'</h1><div class="meta">'.$this->escape($organization).' · '.$this->escape($this->domainLabel((string) ($data['reportType'] ?? 'MANAGEMENT_COMMITTEE'))).'<br>Modèle v'.$this->escape((string) ($data['templateVersion'] ?? '1')).' · Généré le '.$this->escape($this->dateTime((string) ($data['generatedAt'] ?? ''))).' par '.$this->escape((string) ($data['generatedBy'] ?? 'RiskPilot')).' · Modèle approuvé par '.$this->escape((string) ($data['approvedBy'] ?? 'Non renseigné')).'</div></header>'.
            '<h2>1. Synthèse exécutive</h2><table class="cards"><tr>'.$cardHtml.'</tr></table><div class="notice">Ce rapport fige les données visibles au moment de sa génération. Les éléments ci-dessous sont limités aux blocs approuvés dans le modèle.</div>'.$sections.
            '<h2>5. Décisions et suites</h2><p>Les arbitrages, décisions et recommandations doivent être consignés dans le dossier de gouvernance associé. Ce rapport fournit les priorités factuelles et ne remplace pas la validation humaine du comité.</p>'.
            '<h2>6. Méthodologie et limites</h2><p>Les dix premiers éléments sont classés par criticité pour les risques et par échéance pour les actions. Les scores de conformité proviennent des dernières évaluations visibles. Les données restent soumises à la qualité et à l’exhaustivité des enregistrements sources.</p>'.
            '<footer>RiskPilot · Rapport de décision gouverné · '.$this->escape($organization).'</footer></body></html>';
    }

    /** @param list<string> $headers */
    private function decisionTable(array $headers, string $rows, string $empty): string
    {
        $head = implode('', array_map(fn (string $header): string => '<th>'.$this->escape($header).'</th>', $headers));

        return '<table><thead><tr>'.$head.'</tr></thead><tbody>'.('' === $rows ? '<tr><td colspan="'.count($headers).'" class="empty">'.$this->escape($empty).'</td></tr>' : $rows).'</tbody></table>';
    }

    /** @param array<string, int> $items */
    private function rankingTable(array $items, string $heading): string
    {
        if ([] === $items) {
            return '<p class="empty">Aucune donnée.</p>';
        }
        $max = max(1, ...array_map('intval', array_values($items)));
        $rows = '';
        foreach ($items as $label => $count) {
            $rows .= $this->metricRow($this->domainLabel((string) $label), (int) $count, $max);
        }

        return '<table><thead><tr><th>'.$this->escape($heading).'</th><th>Volume</th><th>Répartition</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    private function metricRow(string $label, int $count, int $max): string
    {
        return '<tr><td>'.$this->escape($label).'</td><td>'.$count.'</td><td><span class="bar"><span style="width:'.round($count * 100 / $max).'%"></span></span></td></tr>';
    }

    private function domainLabel(string $value): string
    {
        return ucfirst(mb_strtolower(str_replace('_', ' ', $value)));
    }

    private function actionLabel(string $value): string
    {
        return match (strtoupper($value)) {
            'CREATE' => 'Création', 'UPDATE' => 'Modification', 'DELETE' => 'Suppression', default => $this->domainLabel($value),
        };
    }

    private function date(string $value): string
    {
        try {
            return '' === $value ? 'Non renseignée' : (new \DateTimeImmutable($value))->format('d/m/Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function dateTime(string $value): string
    {
        try {
            return '' === $value ? 'Non renseigné' : (new \DateTimeImmutable($value))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
