<?php

declare(strict_types=1);

namespace App\Application;

use Dompdf\Dompdf;
use Dompdf\Options;

final class PdfReportRenderer
{
    /** @param array<string, mixed> $data */
    public function renderAnnualReport(string $title, array $data, string $locale = 'fr'): string
    {
        $title = 'en' === $locale
            ? sprintf('Annual report %d — v%d', (int) ($data['year'] ?? 0), (int) ($data['version'] ?? 1))
            : sprintf('Rapport annuel %d — v%d', (int) ($data['year'] ?? 0), (int) ($data['version'] ?? 1));

        return $this->renderDocument($title, $this->annualDocument($title, $data, $locale), $data, 'ANNUAL');
    }

    /** @param array<string, mixed> $data */
    public function renderDecisionReport(string $title, array $data, string $locale = 'fr'): string
    {
        return $this->renderDocument($title, $this->decisionDocument($title, $data, $locale), $data, 'DECISION');
    }

    /** @param array<string, mixed> $data */
    public function renderExecutiveReport(string $title, array $data, string $locale = 'fr'): string
    {
        return $this->renderDocument($title, $this->executiveDocument($title, $data, $locale), $data, 'EXECUTIVE');
    }

    /** @param array<string, mixed> $data */
    private function renderDocument(string $title, string $html, array $data, string $type): string
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
        $pdf->addInfo('Subject', 'Risk governance report — '.$this->documentId($type, $data));
        $pdf->addInfo('Keywords', 'RiskPilot, governance, '.$type.', '.$this->documentId($type, $data));
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(510, 810, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.35, 0.42, 0.5]);

        return $this->stabilize($pdf->output(), (string) ($data['generatedAt'] ?? ''), $this->documentId($type, $data));
    }

    /** @param array<string, mixed> $data */
    private function annualDocument(string $title, array $data, string $locale): string
    {
        $locale = 'en' === $locale ? 'en' : 'fr';
        $year = (int) ($data['year'] ?? 0);
        $version = (int) ($data['version'] ?? 1);
        $title = $this->t(sprintf('Rapport annuel %d — v%d', $year, $version), sprintf('Annual report %d — v%d', $year, $version), $locale);
        $documentId = $this->documentId('ANNUAL', $data);
        $organization = (string) ($data['organization'] ?? 'Organisation non renseignée');
        $period = (array) ($data['period'] ?? []);
        $totals = (array) ($data['totals'] ?? []);
        $maturity = (array) ($data['maturity'] ?? []);
        $assessments = (array) ($maturity['assessments'] ?? []);
        $weaknesses = (array) ($maturity['weaknesses'] ?? []);
        $generatedBy = (array) ($data['generatedBy'] ?? []);
        $average = $maturity['average'] ?? null;

        $months = 'en' === $locale
            ? ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
            : ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
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
            $maturityRows .= '<tr><td><strong>'.$this->escape($this->domainLabel((string) $domain, $locale)).'</strong></td>'.
                '<td class="score">'.(null === $score ? $this->t('Non évalué', 'Not assessed', $locale) : $this->escape(number_format($score, 1, 'en' === $locale ? '.' : ',', '').' / 5')).'</td>'.
                '<td>'.(null === $score ? '<span class="empty">'.$this->t('À compléter', 'To complete', $locale).'</span>' : '<div class="meter"><span style="width:'.($score * 20).'%"></span></div>').'</td>'.
                '<td>'.$this->escape((string) ($assessment['rationale'] ?? '') ?: $this->t('Non renseignée', 'Not provided', $locale)).'</td></tr>';
        }

        $activityRows = '';
        foreach ((array) ($data['activities'] ?? []) as $activity) {
            $activity = (array) $activity;
            $entityType = (string) ($activity['entityType'] ?? 'Objet');
            $entityId = $activity['entityId'] ?? null;
            $entityReference = $entityType.(null === $entityId || '' === $entityId ? ' (global)' : ' #'.(string) $entityId);
            $activityRows .= '<tr><td>'.$this->escape($this->date((string) ($activity['occurredAt'] ?? ''), $locale)).'</td><td>'.$this->escape($this->domainLabel((string) ($activity['domain'] ?? ''), $locale)).'</td><td>'.$this->escape($this->actionLabel((string) ($activity['action'] ?? ''), $locale)).'</td><td>'.$this->escape($entityReference).'</td><td>'.$this->escape((string) ($activity['actor'] ?? '')).'</td><td>'.(true === ($activity['sealed'] ?? false) ? $this->t('Scellée', 'Sealed', $locale) : $this->t('Non scellée', 'Unsealed', $locale)).'</td></tr>';
        }

        $css = $this->sharedCss().'.keep{page-break-inside:avoid}.page-break{page-break-before:always}';
        $periodLabel = $this->date((string) ($period['from'] ?? ''), $locale).' '.$this->t('au', 'to', $locale).' '.$this->date((string) ($period['until'] ?? ''), $locale);
        $weaknessLabel = [] === $weaknesses ? $this->t('Aucune faiblesse évaluée à 2 ou moins.', 'No weakness assessed at 2 or below.', $locale) : $this->t('Faiblesses prioritaires : ', 'Priority weaknesses: ', $locale).implode(', ', array_map(fn (mixed $item): string => $this->domainLabel((string) $item, $locale), $weaknesses)).'.';

        return '<!doctype html><html lang="'.$locale.'"><head><meta charset="UTF-8"><title>'.$this->escape($title).'</title><style>'.$css.'</style></head><body>'.
            $this->brandHeader($title, $organization, $documentId, $this->t('INTERNE', 'INTERNAL', $locale), $this->t('Version approuvée', 'Approved version', $locale)).
            '<div class="meta">'.$this->t('Exercice', 'Reporting year', $locale).' '.$year.' · '.$this->t('Version', 'Version', $locale).' '.$version.'<br>'.$this->t('Période', 'Period', $locale).' : '.$this->escape($periodLabel).' · '.$this->t('Généré le', 'Generated on', $locale).' '.$this->escape($this->dateTime((string) ($data['generatedAt'] ?? ''), $locale)).' '.$this->t('par', 'by', $locale).' '.$this->escape((string) ($generatedBy['name'] ?? 'RiskPilot')).'</div></header>'.
            '<h2>1. '.$this->t('Synthèse exécutive', 'Executive summary', $locale).'</h2><table class="cards"><tr><td class="card"><strong>'.(int) ($totals['activities'] ?? 0).'</strong>'.$this->t('activités tracées', 'tracked activities', $locale).'</td><td class="card"><strong>'.(int) ($totals['contributors'] ?? 0).'</strong>'.$this->t('contributeurs', 'contributors', $locale).'</td><td class="card"><strong>'.(int) ($totals['domains'] ?? 0).'</strong>'.$this->t('domaines actifs', 'active domains', $locale).'</td><td class="card"><strong>'.(null === $average ? '—' : $this->escape(number_format((float) $average, 2, 'en' === $locale ? '.' : ',', ''))).'</strong>'.$this->t('maturité / 5', 'maturity / 5', $locale).'</td></tr></table>'.
            '<div class="notice '.([] === $weaknesses ? '' : 'warning').'">'.$this->escape($weaknessLabel).' '.(true === ($maturity['complete'] ?? false) ? $this->t('Les dix domaines sont évalués.', 'All ten domains are assessed.', $locale) : (int) ($maturity['assessedDomains'] ?? 0).$this->t(' domaine(s) sur 10 évalué(s) ; compléter les autres avant présentation en comité.', ' of 10 domain(s) assessed; complete the others before committee review.', $locale)).'</div>'.
            '<h2>2. '.$this->t('Répartition de l’activité', 'Activity breakdown', $locale).'</h2><h3>'.$this->t('Chronologie mensuelle', 'Monthly timeline', $locale).'</h3><table><thead><tr><th>'.$this->t('Mois', 'Month', $locale).'</th><th>'.$this->t('Volume', 'Volume', $locale).'</th><th>'.$this->t('Répartition', 'Distribution', $locale).'</th></tr></thead><tbody>'.$monthRows.'</tbody></table>'.
            '<div class="keep"><h3>'.$this->t('Domaines', 'Domains', $locale).'</h3>'.$this->rankingTable((array) ($data['byDomain'] ?? []), $this->t('Domaine', 'Domain', $locale), $locale, 'domain').'</div><div class="keep"><h3>'.$this->t('Types d’action', 'Action types', $locale).'</h3>'.$this->rankingTable((array) ($data['byAction'] ?? []), $this->t('Action', 'Action', $locale), $locale, 'action').'</div><div class="keep"><h3>'.$this->t('Contributeurs', 'Contributors', $locale).'</h3>'.$this->rankingTable((array) ($data['contributors'] ?? []), $this->t('Contributeur', 'Contributor', $locale), $locale, 'raw').'</div>'.
            '<h2>3. '.$this->t('Maturité cyber', 'Cyber maturity', $locale).'</h2><p>'.$this->t('Échelle de 0 à 5 par pas de 0,5. Les domaines non évalués sont exclus de la moyenne.', 'Scale from 0 to 5 in 0.5 increments. Unassessed domains are excluded from the average.', $locale).'</p><table><thead><tr><th>'.$this->t('Domaine', 'Domain', $locale).'</th><th>'.$this->t('Score', 'Score', $locale).'</th><th>'.$this->t('Niveau', 'Level', $locale).'</th><th>'.$this->t('Justification', 'Rationale', $locale).'</th></tr></thead><tbody>'.$maturityRows.'</tbody></table>'.
            '<div class="keep"><h2>4. '.$this->t('Méthodologie et limites', 'Methodology and limitations', $locale).'</h2><p>'.$this->escape((string) ($data['methodology'] ?? $this->t('Non renseignée', 'Not provided', $locale))).'</p><div class="notice">'.$this->t('Ce rapport est un instantané versionné. Il restitue les mutations journalisées sur la période ; il ne constitue pas à lui seul une certification réglementaire ni une preuve d’exhaustivité des activités non tracées.', 'This report is a versioned snapshot of logged changes during the period. It is not, by itself, a regulatory certification or proof that unlogged activities are complete.', $locale).'</div></div>'.
            '<h2 class="page-break">5. '.$this->t('Annexe — journal détaillé', 'Appendix — detailed log', $locale).'</h2><p>'.count((array) ($data['activities'] ?? [])).$this->t(' événement(s). Les valeurs métier avant/après et les données techniques du client sont volontairement exclues.', ' event(s). Before/after business values and client technical data are intentionally excluded.', $locale).'</p><table><thead><tr><th>'.$this->t('Date', 'Date', $locale).'</th><th>'.$this->t('Domaine', 'Domain', $locale).'</th><th>'.$this->t('Action', 'Action', $locale).'</th><th>'.$this->t('Objet', 'Object', $locale).'</th><th>'.$this->t('Acteur', 'Actor', $locale).'</th><th>'.$this->t('Intégrité', 'Integrity', $locale).'</th></tr></thead><tbody>'.($activityRows ?: '<tr><td colspan="6" class="empty">'.$this->t('Aucune activité sur la période.', 'No activity during the period.', $locale).'</td></tr>').'</tbody></table>'.
            $this->footer($this->t('Rapport annuel gouverné', 'Governed annual report', $locale), $organization, $documentId).'</body></html>';
    }

    /** @param array<string, mixed> $data */
    private function decisionDocument(string $title, array $data, string $locale): string
    {
        $locale = 'en' === $locale ? 'en' : 'fr';
        $documentId = $this->documentId('DECISION', $data);
        $snapshot = (array) ($data['snapshot'] ?? []);
        $blocks = array_map('strtolower', array_map('strval', (array) ($data['blocks'] ?? [])));
        $organization = (string) ($data['organization'] ?? 'Organisation non renseignée');
        $cards = [$this->t('Risques', 'Risks', $locale) => (int) ($snapshot['risks'] ?? 0), $this->t('Contrôles', 'Controls', $locale) => (int) ($snapshot['controls'] ?? 0), $this->t('Évaluations', 'Assessments', $locale) => (int) ($snapshot['assessments'] ?? 0), $this->t('Actions', 'Actions', $locale) => (int) ($snapshot['actions'] ?? 0), $this->t('Tiers', 'Third parties', $locale) => (int) ($snapshot['thirdParties'] ?? 0)];
        $cardHtml = '';
        foreach ($cards as $label => $value) {
            $cardHtml .= '<td class="card"><strong>'.$value.'</strong>'.$this->escape($label).'</td>';
        }
        $sections = '';
        if (in_array('risks', $blocks, true)) {
            $rows = '';
            foreach ((array) ($snapshot['riskItems'] ?? []) as $item) {
                $item = (array) $item;
                $rows .= '<tr><td>'.$this->escape((string) ($item['title'] ?? '')).'</td><td>'.$this->escape($this->domainLabel((string) ($item['status'] ?? ''), $locale)).'</td><td>'.(int) ($item['currentScore'] ?? 0).'</td><td>'.(int) ($item['residualScore'] ?? 0).'</td><td>'.$this->escape($this->domainLabel((string) ($item['treatment'] ?? ''), $locale)).'</td><td>'.$this->escape((string) ($item['owner'] ?? '')).'</td></tr>';
            }
            $empty = !array_key_exists('riskItems', $snapshot) && (int) ($snapshot['risks'] ?? 0) > 0 ? $this->t('Détail non conservé dans cet instantané historique.', 'Details were not retained in this historical snapshot.', $locale) : $this->t('Aucun risque sélectionné.', 'No risk selected.', $locale);
            $sections .= '<h2>2. '.$this->t('Risques prioritaires', 'Priority risks', $locale).'</h2>'.$this->decisionTable([$this->t('Risque', 'Risk', $locale), $this->t('Statut', 'Status', $locale), $this->t('Actuel', 'Current', $locale), $this->t('Résiduel', 'Residual', $locale), $this->t('Traitement', 'Treatment', $locale), $this->t('Responsable', 'Owner', $locale)], $rows, $empty);
        }
        if (in_array('actions', $blocks, true)) {
            $rows = '';
            foreach ((array) ($snapshot['actionItems'] ?? []) as $item) {
                $item = (array) $item;
                $rows .= '<tr><td>'.$this->escape((string) ($item['title'] ?? '')).'</td><td>'.$this->escape($this->domainLabel((string) ($item['priority'] ?? ''), $locale)).'</td><td>'.$this->escape($this->domainLabel((string) ($item['status'] ?? ''), $locale)).'</td><td>'.(int) ($item['progress'] ?? 0).'%</td><td>'.$this->escape($this->date((string) ($item['dueAt'] ?? ''), $locale)).'</td><td>'.$this->escape((string) ($item['owner'] ?? '')).'</td></tr>';
            }
            $empty = !array_key_exists('actionItems', $snapshot) && (int) ($snapshot['actions'] ?? 0) > 0 ? $this->t('Détail non conservé dans cet instantané historique.', 'Details were not retained in this historical snapshot.', $locale) : $this->t('Aucune action sélectionnée.', 'No action selected.', $locale);
            $sections .= '<h2>3. '.$this->t('Plans d’action prioritaires', 'Priority action plans', $locale).'</h2>'.$this->decisionTable([$this->t('Action', 'Action', $locale), $this->t('Priorité', 'Priority', $locale), $this->t('Statut', 'Status', $locale), $this->t('Avancement', 'Progress', $locale), $this->t('Échéance', 'Due date', $locale), $this->t('Responsable', 'Owner', $locale)], $rows, $empty);
        }
        if (in_array('compliance', $blocks, true)) {
            $rows = '';
            foreach ((array) ($snapshot['complianceItems'] ?? []) as $item) {
                $item = (array) $item;
                $rows .= '<tr><td>'.$this->escape((string) ($item['framework'] ?? '')).'</td><td>'.$this->escape((string) ($item['scope'] ?? '')).'</td><td>'.$this->escape($this->domainLabel((string) ($item['status'] ?? ''), $locale)).'</td><td>'.$this->escape(number_format((float) ($item['score'] ?? 0), 1, 'en' === $locale ? '.' : ',', '').' %').'</td><td>'.$this->escape($this->date((string) ($item['assessedAt'] ?? ''), $locale)).'</td></tr>';
            }
            $empty = !array_key_exists('complianceItems', $snapshot) && (int) ($snapshot['assessments'] ?? 0) > 0 ? $this->t('Détail non conservé dans cet instantané historique.', 'Details were not retained in this historical snapshot.', $locale) : $this->t('Aucune évaluation sélectionnée.', 'No assessment selected.', $locale);
            $sections .= '<h2>4. '.$this->t('Situation de conformité', 'Compliance position', $locale).'</h2>'.$this->decisionTable([$this->t('Référentiel', 'Framework', $locale), $this->t('Périmètre', 'Scope', $locale), $this->t('Statut', 'Status', $locale), $this->t('Score', 'Score', $locale), $this->t('Évaluation', 'Assessment', $locale)], $rows, $empty);
        }
        $css = $this->sharedCss();

        return '<!doctype html><html lang="'.$locale.'"><head><meta charset="UTF-8"><title>'.$this->escape($title).'</title><style>'.$css.'</style></head><body>'.
            $this->brandHeader($title, $organization, $documentId, $this->t('CONFIDENTIEL', 'CONFIDENTIAL', $locale), $this->t('Modèle approuvé', 'Approved template', $locale)).
            '<div class="meta">'.$this->escape($this->domainLabel((string) ($data['reportType'] ?? 'MANAGEMENT_COMMITTEE'), $locale)).'<br>'.$this->t('Modèle', 'Template', $locale).' v'.$this->escape((string) ($data['templateVersion'] ?? '1')).' · '.$this->t('Généré le', 'Generated on', $locale).' '.$this->escape($this->dateTime((string) ($data['generatedAt'] ?? ''), $locale)).' '.$this->t('par', 'by', $locale).' '.$this->escape((string) ($data['generatedBy'] ?? 'RiskPilot')).' · '.$this->t('Modèle approuvé par', 'Template approved by', $locale).' '.$this->escape((string) ($data['approvedBy'] ?? $this->t('Non renseigné', 'Not provided', $locale))).'</div></header>'.
            '<h2>1. '.$this->t('Synthèse exécutive', 'Executive summary', $locale).'</h2><table class="cards"><tr>'.$cardHtml.'</tr></table><div class="notice">'.$this->t('Ce rapport fige les données visibles au moment de sa génération. Les éléments ci-dessous sont limités aux blocs approuvés dans le modèle.', 'This report freezes the data visible at generation time. The sections below are limited to blocks approved in the template.', $locale).'</div>'.$sections.
            '<div class="keep"><h2>5. '.$this->t('Décisions et suites', 'Decisions and follow-up', $locale).'</h2><p>'.$this->t('Les arbitrages, décisions et recommandations doivent être consignés dans le dossier de gouvernance associé. Ce rapport fournit les priorités factuelles et ne remplace pas la validation humaine du comité.', 'Arbitrations, decisions and recommendations must be recorded in the associated governance file. This report provides factual priorities and does not replace human committee approval.', $locale).'</p></div>'.
            '<div class="keep"><h2>6. '.$this->t('Méthodologie et limites', 'Methodology and limitations', $locale).'</h2><p>'.$this->t('Les dix premiers éléments sont classés par criticité pour les risques et par échéance pour les actions. Les scores de conformité proviennent des dernières évaluations visibles. Les données restent soumises à la qualité et à l’exhaustivité des enregistrements sources.', 'The first ten items are ranked by risk criticality and action due date. Compliance scores come from the latest visible assessments. Data remains subject to the quality and completeness of source records.', $locale).'</p></div>'.
            $this->footer($this->t('Rapport de décision gouverné', 'Governed decision report', $locale), $organization, $documentId).'</body></html>';
    }

    /** @param array<string, mixed> $data */
    private function executiveDocument(string $title, array $data, string $locale): string
    {
        $locale = 'en' === $locale ? 'en' : 'fr';
        $organization = (string) ($data['organization'] ?? $this->t('Organisation non renseignée', 'Organization not provided', $locale));
        $summary = (array) ($data['summary'] ?? []);
        $vision = (array) ($data['vision'] ?? []);
        $documentId = $this->documentId('EXECUTIVE', $data);
        $cards = [
            $this->t('Risques', 'Risks', $locale) => (int) ($summary['totalRisks'] ?? 0),
            $this->t('Critiques', 'Critical', $locale) => (int) ($summary['criticalRisks'] ?? 0),
            $this->t('Élevés', 'High', $locale) => (int) ($summary['highRisks'] ?? 0),
            $this->t('Actions en retard', 'Overdue actions', $locale) => (int) ($summary['overdueActions'] ?? 0),
            $this->t('Échéances à 30 jours', 'Due within 30 days', $locale) => (int) ($summary['dueActions'] ?? 0),
            $this->t('Conformité', 'Compliance', $locale) => number_format((float) ($summary['globalCompliance'] ?? 0), 1, 'en' === $locale ? '.' : ',', '').' %',
        ];
        $cardHtml = '';
        foreach ($cards as $label => $value) {
            $cardHtml .= '<td class="card"><strong>'.$this->escape((string) $value).'</strong>'.$this->escape($label).'</td>';
        }
        $riskRows = '';
        foreach ((array) ($data['topRisks'] ?? []) as $item) {
            $item = (array) $item;
            $riskRows .= '<tr><td>'.$this->escape((string) ($item['title'] ?? '')).'</td><td>'.$this->escape($this->domainLabel((string) ($item['status'] ?? ''), $locale)).'</td><td>'.(int) ($item['score'] ?? 0).'</td></tr>';
        }
        $actionRows = '';
        foreach ((array) ($data['dueActions'] ?? []) as $item) {
            $item = (array) $item;
            $actionRows .= '<tr><td>'.$this->escape((string) ($item['title'] ?? '')).'</td><td>'.$this->escape($this->domainLabel((string) ($item['priority'] ?? ''), $locale)).'</td><td>'.$this->escape($this->domainLabel((string) ($item['status'] ?? ''), $locale)).'</td><td>'.$this->escape($this->date((string) ($item['dueDate'] ?? ''), $locale)).'</td></tr>';
        }
        $complianceRows = '';
        foreach ((array) ($data['complianceByFramework'] ?? []) as $framework => $score) {
            $complianceRows .= '<tr><td>'.$this->escape((string) $framework).'</td><td>'.$this->escape(number_format((float) $score, 1, 'en' === $locale ? '.' : ',', '').' %').'</td></tr>';
        }
        $controls = (array) ($vision['controls'] ?? []);
        $thirdParties = (array) ($vision['thirdParties'] ?? []);
        $financialScenarios = (array) ($vision['financialScenarios'] ?? []);
        $css = $this->sharedCss();

        return '<!doctype html><html lang="'.$locale.'"><head><meta charset="UTF-8"><title>'.$this->escape($title).'</title><style>'.$css.'</style></head><body>'.
            $this->brandHeader($title, $organization, $documentId, $this->t('CONFIDENTIEL', 'CONFIDENTIAL', $locale), $this->t('Situation courante', 'Current position', $locale)).
            '<div class="meta">'.$this->t('Généré le', 'Generated on', $locale).' '.$this->escape($this->dateTime((string) ($data['generatedAt'] ?? ''), $locale)).' '.$this->t('par', 'by', $locale).' '.$this->escape((string) ($data['generatedBy'] ?? 'RiskPilot')).'</div></header>'.
            '<h2>1. '.$this->t('Indicateurs exécutifs', 'Executive indicators', $locale).'</h2><table class="cards"><tr>'.$cardHtml.'</tr></table>'.
            '<div class="keep"><h2>2. '.$this->t('Vision 360°', '360° view', $locale).'</h2><table><thead><tr><th>'.$this->t('Contrôles déployés', 'Implemented controls', $locale).'</th><th>'.$this->t('Tiers critiques', 'Critical third parties', $locale).'</th><th>'.$this->t('Scénarios financiers', 'Financial scenarios', $locale).'</th></tr></thead><tbody><tr><td>'.(int) ($controls['implemented'] ?? 0).' / '.(int) ($controls['total'] ?? 0).'</td><td>'.(int) ($thirdParties['critical'] ?? 0).' / '.(int) ($thirdParties['total'] ?? 0).'</td><td>'.count($financialScenarios).'</td></tr></tbody></table></div>'.
            '<h2>3. '.$this->t('Risques prioritaires', 'Priority risks', $locale).'</h2>'.$this->decisionTable([$this->t('Scénario', 'Scenario', $locale), $this->t('Statut', 'Status', $locale), $this->t('Score actuel', 'Current score', $locale)], $riskRows, $this->t('Aucun risque prioritaire.', 'No priority risk.', $locale)).
            '<h2>4. '.$this->t('Actions à échéance', 'Actions due', $locale).'</h2>'.$this->decisionTable([$this->t('Action', 'Action', $locale), $this->t('Priorité', 'Priority', $locale), $this->t('Statut', 'Status', $locale), $this->t('Échéance', 'Due date', $locale)], $actionRows, $this->t('Aucune action à échéance.', 'No action due.', $locale)).
            '<div class="keep"><h2>5. '.$this->t('Conformité par référentiel', 'Compliance by framework', $locale).'</h2>'.$this->decisionTable([$this->t('Référentiel', 'Framework', $locale), $this->t('Score', 'Score', $locale)], $complianceRows, $this->t('Aucune évaluation de conformité.', 'No compliance assessment.', $locale)).'</div>'.
            '<div class="notice">'.$this->t('Ce document restitue les données visibles par l’utilisateur authentifié au moment de l’export. Il ne constitue pas une certification et doit être validé avant diffusion.', 'This document reflects data visible to the authenticated user at export time. It is not a certification and must be reviewed before distribution.', $locale).'</div>'.
            $this->footer($this->t('Rapport exécutif gouverné', 'Governed executive report', $locale), $organization, $documentId).'</body></html>';
    }

    private function sharedCss(): string
    {
        return '@page{margin:18mm 14mm 18mm}body{font-family:"DejaVu Sans",sans-serif;color:#17324d;font-size:9px;line-height:1.4}header{border-bottom:3px solid #0284c7;padding-bottom:10px;margin-bottom:13px}.brand{width:100%;border:0;margin:0 0 8px}.brand td{border:0;padding:0}.brand-name{font-size:13px;font-weight:bold;color:#075985}.classification{text-align:right;font-size:7px;letter-spacing:.5px;color:#475569}.doc-title{font-size:19px;color:#075985;margin:0 0 4px;line-height:1.2;word-wrap:break-word}.document-id{font-size:7px;color:#64748b;margin-top:2px}h2{font-size:14px;color:#075985;margin:17px 0 7px;page-break-after:avoid}h3{font-size:11px;color:#164e63;margin:11px 0 5px;page-break-after:avoid}.meta{color:#52677a}.cards{width:100%;border-collapse:separate;border-spacing:4px}.card{border:1px solid #bae6fd;background:#f0f9ff;padding:7px;text-align:center}.card strong{display:block;font-size:15px;color:#0369a1}.notice{background:#f8fafc;border-left:4px solid #38bdf8;padding:8px;margin:8px 0;page-break-inside:avoid}.warning{border-left-color:#f59e0b;background:#fffbeb}table{width:100%;border-collapse:collapse;margin-bottom:9px}thead{display:table-header-group}tr{page-break-inside:avoid}th,td{border:1px solid #cbd5e1;padding:5px;vertical-align:top;overflow-wrap:break-word}th{background:#eaf5fb;text-align:left;color:#164e63}.score{white-space:nowrap;width:58px}.meter{height:7px;background:#e2e8f0}.meter span{display:block;height:7px;background:#0284c7}.bar{height:6px;background:#e2e8f0;width:100px;display:inline-block;margin-right:5px}.bar span{display:block;height:6px;background:#38bdf8}.empty{color:#64748b;font-style:italic}.keep{page-break-inside:avoid}footer{position:fixed;bottom:-11mm;left:0;right:0;border-top:1px solid #d8e1e8;padding-top:4px;color:#64748b;font-size:7px}';
    }

    private function brandHeader(string $title, string $organization, string $documentId, string $classification, string $status): string
    {
        return '<header><table class="brand" role="presentation"><tr><td><span class="brand-name">RISKpilot</span></td><td class="classification">'.$this->escape($classification).' · '.$this->escape($status).'</td></tr></table><h1 class="doc-title">'.$this->escape($title).'</h1><div class="document-id">'.$this->escape($organization).' · ID '.$this->escape($documentId).'</div>';
    }

    private function footer(string $label, string $organization, string $documentId): string
    {
        return '<footer>RISKpilot · '.$this->escape($label).' · '.$this->escape($organization).' · '.$this->escape($documentId).'</footer>';
    }

    /** @param array<string, mixed> $data */
    private function documentId(string $type, array $data): string
    {
        $fingerprint = strtoupper(substr(hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 12));

        return sprintf('RP-%s-%s', $type, $fingerprint);
    }

    private function stabilize(string $content, string $generatedAt, string $documentId): string
    {
        try {
            $date = new \DateTimeImmutable($generatedAt);
        } catch (\Throwable) {
            $date = new \DateTimeImmutable('2000-01-01T00:00:00+00:00');
        }
        $date = $date->setTimezone(new \DateTimeZone('UTC'))->format("YmdHis+00'00'");
        $content = preg_replace('/\/(CreationDate|ModDate) \(D:[^)]+\)/', '/$1 (D:'.$date.')', $content) ?? $content;
        $id = md5($documentId);

        return preg_replace('/\/ID\s*\[<[^>]+><[^>]+>\]/', '/ID[<'.$id.'><'.$id.'>]', $content) ?? $content;
    }

    /** @param list<string> $headers */
    private function decisionTable(array $headers, string $rows, string $empty): string
    {
        $head = implode('', array_map(fn (string $header): string => '<th>'.$this->escape($header).'</th>', $headers));

        return '<table><thead><tr>'.$head.'</tr></thead><tbody>'.('' === $rows ? '<tr><td colspan="'.count($headers).'" class="empty">'.$this->escape($empty).'</td></tr>' : $rows).'</tbody></table>';
    }

    /** @param array<string, int> $items */
    private function rankingTable(array $items, string $heading, string $locale, string $labelType): string
    {
        if ([] === $items) {
            return '<p class="empty">'.$this->t('Aucune donnée.', 'No data.', $locale).'</p>';
        }
        $max = max(1, ...array_map('intval', array_values($items)));
        $rows = '';
        foreach ($items as $label => $count) {
            $displayLabel = match ($labelType) {
                'action' => $this->actionLabel((string) $label, $locale),
                'raw' => (string) $label,
                default => $this->domainLabel((string) $label, $locale),
            };
            $rows .= $this->metricRow($displayLabel, (int) $count, $max);
        }

        return '<table><thead><tr><th>'.$this->escape($heading).'</th><th>'.$this->t('Volume', 'Volume', $locale).'</th><th>'.$this->t('Répartition', 'Distribution', $locale).'</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    private function metricRow(string $label, int $count, int $max): string
    {
        return '<tr><td>'.$this->escape($label).'</td><td>'.$count.'</td><td><span class="bar"><span style="width:'.round($count * 100 / $max).'%"></span></span></td></tr>';
    }

    private function domainLabel(string $value, string $locale = 'fr'): string
    {
        $key = strtoupper($value);
        $labels = [
            'IAM' => ['Gestion des identités et accès', 'Identity and access management'], 'GOVERNANCE' => ['Gouvernance', 'Governance'],
            'RISK_MANAGEMENT' => ['Gestion des risques', 'Risk management'], 'ASSET_MANAGEMENT' => ['Gestion des actifs', 'Asset management'],
            'VULNERABILITY_MANAGEMENT' => ['Gestion des vulnérabilités', 'Vulnerability management'], 'DETECTION_RESPONSE' => ['Détection et réponse', 'Detection and response'],
            'BUSINESS_CONTINUITY' => ['Continuité d’activité', 'Business continuity'], 'THIRD_PARTIES' => ['Tiers', 'Third parties'],
            'COMPLIANCE' => ['Conformité', 'Compliance'], 'AWARENESS' => ['Sensibilisation', 'Awareness'],
            'APPROVED' => ['Approuvé', 'Approved'], 'DRAFT' => ['Brouillon', 'Draft'], 'COMPLETED' => ['Terminé', 'Completed'],
            'IN_PROGRESS' => ['En cours', 'In progress'], 'OVERDUE' => ['En retard', 'Overdue'], 'BLOCKED' => ['Bloqué', 'Blocked'],
            'OPEN' => ['Ouvert', 'Open'], 'PLANNED' => ['Planifié', 'Planned'], 'CANCELLED' => ['Annulé', 'Cancelled'],
            'REDUCE' => ['Réduire', 'Reduce'], 'ACCEPT' => ['Accepter', 'Accept'], 'TRANSFER' => ['Transférer', 'Transfer'], 'AVOID' => ['Éviter', 'Avoid'],
            'LOW' => ['Faible', 'Low'], 'MEDIUM' => ['Moyenne', 'Medium'], 'HIGH' => ['Élevée', 'High'], 'CRITICAL' => ['Critique', 'Critical'],
            'MANAGEMENT_COMMITTEE' => ['Comité de direction', 'Management committee'],
        ];
        if (isset($labels[$key])) {
            return $labels[$key]['en' === $locale ? 1 : 0];
        }

        return ucfirst(mb_strtolower(str_replace('_', ' ', $value)));
    }

    private function actionLabel(string $value, string $locale): string
    {
        return match (strtoupper($value)) {
            'CREATE', 'POST' => $this->t('Création', 'Creation', $locale),
            'UPDATE', 'PUT', 'PATCH' => $this->t('Modification', 'Update', $locale),
            'DELETE' => $this->t('Suppression', 'Deletion', $locale),
            default => $this->domainLabel($value, $locale),
        };
    }

    private function date(string $value, string $locale = 'fr'): string
    {
        try {
            return '' === $value ? $this->t('Non renseignée', 'Not provided', $locale) : (new \DateTimeImmutable($value))->format('en' === $locale ? 'Y-m-d' : 'd/m/Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function dateTime(string $value, string $locale = 'fr'): string
    {
        try {
            return '' === $value ? $this->t('Non renseigné', 'Not provided', $locale) : (new \DateTimeImmutable($value))->format('en' === $locale ? 'Y-m-d H:i T' : 'd/m/Y H:i T');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function t(string $fr, string $en, string $locale): string
    {
        return 'en' === $locale ? $en : $fr;
    }
}
