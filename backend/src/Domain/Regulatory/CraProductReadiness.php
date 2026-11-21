<?php

declare(strict_types=1);

namespace App\Domain\Regulatory;

use App\Entity\DigitalProduct;

/**
 * Écarts de préparation d'un produit au Cyber Resilience Act (2024/2847). Ce sont des signaux de pilotage :
 * ils ne remplacent pas l'évaluation de conformité du produit ni la lecture des normes harmonisées applicables.
 */
final class CraProductReadiness
{
    /** @return list<string> codes d'écart */
    public function gaps(DigitalProduct $product, \DateTimeImmutable $now): array
    {
        if (in_array($product->getStatus(), ['WITHDRAWN', 'SUPPORT_ENDED'], true)) {
            return [];
        }
        $gaps = [];
        $manufacturer = in_array($product->getRole(), ['MANUFACTURER', 'OPEN_SOURCE_STEWARD'], true);
        if ($manufacturer) {
            if (null === $product->getSbomReference()) {
                $gaps[] = 'SBOM_MISSING';
            }
            if (null === $product->getVulnerabilityContact()) {
                $gaps[] = 'DISCLOSURE_CONTACT_MISSING';
            }
        }
        if ('ON_MARKET' !== $product->getStatus()) {
            return $gaps;
        }
        $placed = $product->getPlacedOnMarketAt();
        $end = $product->getSupportEndsAt();
        if ($manufacturer) {
            if (null === $end) {
                $gaps[] = 'SUPPORT_END_UNDEFINED';
            } elseif ($end <= $now) {
                $gaps[] = 'SUPPORT_ENDED_STILL_ON_MARKET';
            } elseif ($end <= $now->modify('+12 months')) {
                $gaps[] = 'SUPPORT_EXPIRING';
            }
            // Au moins cinq ans de support, sauf si la durée d'usage attendue du produit est plus courte (art. 13 §8).
            if (null !== $end && null !== $placed && $end < $placed->modify('+5 years')) {
                $gaps[] = 'SUPPORT_PERIOD_UNDER_FIVE_YEARS';
            }
        }
        if ('NOT_ASSESSED' === $product->getConformityRoute()) {
            $gaps[] = 'CONFORMITY_NOT_ASSESSED';
        } elseif (null === $product->getDeclarationReference()) {
            $gaps[] = 'DECLARATION_MISSING';
        }
        if ('INTERNAL_CONTROL' === $product->getConformityRoute() && in_array($product->getCategory(), ['IMPORTANT_CLASS_II', 'CRITICAL'], true)) {
            $gaps[] = 'THIRD_PARTY_ASSESSMENT_REQUIRED';
        }

        return $gaps;
    }
}
