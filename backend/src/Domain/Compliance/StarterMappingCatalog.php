<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

/**
 * Correspondances amorcées entre les packs de démarrage, exprimées uniquement par identifiants (aucun texte normatif).
 * Ce sont des propositions de travail à valider : elles ne valent jamais équivalence ni conformité.
 */
final class StarterMappingCatalog
{
    public const RATIONALE_PREFIX = '[Suggestion à valider] ';

    private const ISO_27001 = 'iso27001';
    private const ISO_42001 = 'iso42001';
    private const NIS2 = 'nis2';
    private const TISAX = 'tisax';
    private const AI_ACT = 'ai-act';
    private const DORA = 'dora';
    private const CRA = 'cra';

    /** Clauses de même numéro dans les deux normes (structure harmonisée ISO). */
    private const SHARED_CLAUSES = ['4.1', '4.2', '4.3', '4.4', '5.1', '5.2', '5.3', '6.1.1', '6.1.2', '6.1.3', '6.2', '6.3', '7.1', '7.2', '7.3', '7.4', '7.5', '8.1', '8.2', '8.3', '9.1', '9.2', '9.3', '10.1', '10.2'];

    /** Exigence cible NIS2 => références ISO 27001 qui y contribuent. */
    private const NIS2_FROM_27001 = [
        'ART-20' => ['CLAUSE-5.1', 'CLAUSE-5.2', 'CLAUSE-5.3'],
        'ART-21.2-A' => ['CLAUSE-6.1.2', 'CLAUSE-6.1.3', 'A.5.1'],
        'ART-21.2-B' => ['A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.28', 'A.6.8', 'A.8.15', 'A.8.16'],
        'ART-21.2-C' => ['A.5.29', 'A.5.30', 'A.8.13', 'A.8.14'],
        'ART-21.2-D' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'],
        'ART-21.2-E' => ['A.8.8', 'A.8.25', 'A.8.26', 'A.8.27', 'A.8.28', 'A.8.29', 'A.8.30', 'A.8.31', 'A.8.32'],
        'ART-21.2-F' => ['CLAUSE-9.1', 'CLAUSE-9.2', 'CLAUSE-9.3', 'A.5.35', 'A.5.36'],
        'ART-21.2-G' => ['A.6.3', 'A.8.7', 'A.8.19'],
        'ART-21.2-H' => ['A.8.24'],
        'ART-21.2-I' => ['A.6.1', 'A.6.2', 'A.5.9', 'A.5.10', 'A.5.11', 'A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.8.2', 'A.8.3'],
        'ART-21.2-J' => ['A.8.5', 'A.5.14', 'A.8.20', 'A.8.21'],
        'ART-21.3' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22'],
        'ART-23' => ['A.5.24', 'A.5.25', 'A.5.26'],
    ];

    /** Section TISAX (cible) => références ISO 27001 qui y contribuent. */
    private const TISAX_FROM_27001 = [
        '1.1' => ['A.5.1', 'A.5.36'],
        '1.2' => ['A.5.2', 'A.5.3', 'A.5.8'],
        '1.3' => ['A.5.9', 'A.5.10', 'A.5.11', 'A.5.12', 'A.5.13'],
        '1.4' => ['CLAUSE-6.1.2', 'CLAUSE-6.1.3', 'CLAUSE-8.2', 'CLAUSE-8.3'],
        '1.5' => ['CLAUSE-9.2', 'CLAUSE-9.3', 'A.5.35'],
        '1.6' => ['A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.28', 'A.6.8'],
        '2.1' => ['A.6.1', 'A.6.2', 'A.6.3', 'A.6.4', 'A.6.5', 'A.6.6', 'A.6.7'],
        '3.1' => ['A.7.1', 'A.7.2', 'A.7.3', 'A.7.4', 'A.7.5', 'A.7.6', 'A.7.7', 'A.7.8', 'A.7.9', 'A.7.10', 'A.7.11', 'A.7.12', 'A.7.13', 'A.7.14', 'A.5.29', 'A.5.30'],
        '4.1' => ['A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.8.2', 'A.8.3', 'A.8.5'],
        '5.1' => ['A.8.24'],
        '5.2' => ['A.8.7', 'A.8.8', 'A.8.9', 'A.8.13', 'A.8.15', 'A.8.16', 'A.8.19', 'A.8.32'],
        '5.3' => ['A.5.14', 'A.8.20', 'A.8.21', 'A.8.22', 'A.8.23', 'A.8.25', 'A.8.26', 'A.8.28'],
        '6.1' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'],
        '7.1' => ['A.5.31', 'A.5.32', 'A.5.33'],
        '9.2' => ['A.5.34'],
    ];

    /** Exigence cible AI Act => références ISO 42001 qui y contribuent (système de management de l'IA). */
    private const AI_ACT_FROM_42001 = [
        'ART-4' => ['A.4.6', 'CLAUSE-7.2', 'CLAUSE-7.3'],
        'ART-6' => ['A.5.2', 'A.9.4'],
        'ART-9' => ['CLAUSE-6.1.2', 'CLAUSE-6.1.3', 'A.6.1.2', 'A.6.1.3'],
        'ART-10' => ['A.7.2', 'A.7.3', 'A.7.4', 'A.7.5', 'A.7.6', 'A.4.3'],
        'ART-11' => ['A.6.2.3', 'A.6.2.7', 'A.4.2'],
        'ART-12' => ['A.6.2.8'],
        'ART-13' => ['A.8.2', 'A.8.5'],
        'ART-14' => ['A.9.2', 'A.9.3', 'A.6.2.6'],
        'ART-15' => ['A.6.2.4', 'A.6.2.5'],
        'ART-17' => ['CLAUSE-4.4', 'CLAUSE-5.2', 'CLAUSE-9.2', 'CLAUSE-10.2'],
        'ART-25' => ['A.10.2', 'A.10.3', 'A.10.4'],
        'ART-26' => ['A.9.2', 'A.9.4', 'A.6.2.6'],
        'ART-27' => ['CLAUSE-6.1.4', 'A.5.2', 'A.5.3', 'A.5.4', 'A.5.5'],
        'ART-50' => ['A.8.2', 'A.8.5'],
        'ART-72' => ['A.6.2.6', 'CLAUSE-9.1'],
        'ART-73' => ['A.8.3', 'A.8.4'],
    ];

    /** Exigence cible DORA => références ISO 27001 qui y contribuent. */
    private const DORA_FROM_27001 = [
        'ART-5' => ['CLAUSE-5.1', 'CLAUSE-5.2', 'CLAUSE-5.3', 'A.5.1'],
        'ART-6' => ['CLAUSE-6.1.2', 'CLAUSE-6.1.3', 'A.5.1'],
        'ART-8' => ['A.5.9', 'A.5.10'],
        'ART-9' => ['A.8.5', 'A.8.20', 'A.8.24', 'A.8.8', 'A.8.9'],
        'ART-10' => ['A.8.15', 'A.8.16'],
        'ART-11' => ['A.5.29', 'A.5.30'],
        'ART-12' => ['A.8.13', 'A.8.14'],
        'ART-13' => ['A.5.27', 'A.6.3'],
        'ART-17' => ['A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.28', 'A.6.8'],
        'ART-19' => ['A.5.24', 'A.5.26'],
        'ART-25' => ['A.8.29', 'A.8.8'],
        'ART-28' => ['A.5.19', 'A.5.21', 'A.5.22', 'A.5.23'],
        'ART-30' => ['A.5.20'],
    ];

    /** Exigence cible CRA => références ISO 27001 qui y contribuent. */
    private const CRA_FROM_27001 = [
        'ANNEX-I-P1.4' => ['A.8.5', 'A.5.15', 'A.5.16', 'A.5.17', 'A.5.18'],
        'ANNEX-I-P1.5' => ['A.8.24'],
        'ANNEX-I-P1.12' => ['A.8.15', 'A.8.16'],
        'ANNEX-I-P2.1' => ['A.8.8', 'A.8.9'],
        'ANNEX-I-P2.2' => ['A.8.8', 'A.8.32'],
        'ANNEX-I-P2.3' => ['A.8.29'],
        'ART-13.2' => ['CLAUSE-6.1.2', 'A.8.25'],
        'ART-13.5' => ['A.5.19', 'A.5.21', 'A.8.30'],
        'ART-13.6' => ['A.8.8', 'A.5.24', 'A.5.26'],
    ];

    /**
     * @return list<array{source: array{0: string, 1: string}, target: array{0: string, 1: string}, coverage: int, rationale: string}> pack source/cible et référence
     */
    public function mappings(): array
    {
        $mappings = [];
        foreach (self::SHARED_CLAUSES as $number) {
            $mappings[] = $this->entry(self::ISO_27001, 'CLAUSE-'.$number, self::ISO_42001, 'CLAUSE-'.$number, 70, 'Clause de même numéro dans la structure harmonisée ISO.');
        }
        foreach (self::NIS2_FROM_27001 as $target => $sources) {
            foreach ($sources as $source) {
                $mappings[] = $this->entry(self::ISO_27001, $source, self::NIS2, $target, 50, 'Mesure ISO 27001 contribuant à l’exigence NIS2 ; périmètre et niveau à confirmer.');
            }
        }
        foreach (self::TISAX_FROM_27001 as $target => $sources) {
            foreach ($sources as $source) {
                $mappings[] = $this->entry(self::ISO_27001, $source, self::TISAX, $target, 50, 'Mesure ISO 27001 contribuant à la section VDA ISA ; la maturité reste évaluée séparément.');
            }
        }

        foreach (self::AI_ACT_FROM_42001 as $target => $sources) {
            foreach ($sources as $source) {
                $mappings[] = $this->entry(self::ISO_42001, $source, self::AI_ACT, $target, 40, 'Mesure ISO 42001 contribuant à l’exigence du règlement sur l’IA ; la qualification du système et les obligations légales restent à confirmer.');
            }
        }
        foreach (self::DORA_FROM_27001 as $target => $sources) {
            foreach ($sources as $source) {
                $mappings[] = $this->entry(self::ISO_27001, $source, self::DORA, $target, 40, 'Mesure ISO 27001 contribuant à l’exigence DORA ; proportionnalité et normes techniques à confirmer.');
            }
        }
        foreach (self::CRA_FROM_27001 as $target => $sources) {
            foreach ($sources as $source) {
                $mappings[] = $this->entry(self::ISO_27001, $source, self::CRA, $target, 30, 'Mesure ISO 27001 de l’organisation contribuant à l’exigence produit du CRA ; ne vaut pas évaluation de conformité du produit.');
            }
        }

        return $mappings;
    }

    /**
     *      *
     * @return array{source: array{0: string, 1: string}, target: array{0: string, 1: string}, coverage: int, rationale: string}
     */
    private function entry(string $source, string $sourceReference, string $target, string $targetReference, int $coverage, string $rationale): array
    {
        return ['source' => [$source, $sourceReference], 'target' => [$target, $targetReference], 'coverage' => $coverage, 'rationale' => self::RATIONALE_PREFIX.$rationale];
    }
}
