<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ActionPlan;
use App\Entity\Asset;
use App\Entity\ComplianceAssessment;
use App\Entity\ComplianceResult;
use App\Entity\Framework;
use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\Requirement;
use App\Entity\RiskScenario;
use App\Entity\Scope;
use App\Entity\SecurityControl;
use App\Entity\Threat;
use App\Entity\User;
use App\Entity\Vulnerability;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $organization = new Organization('RiskPilot Demo');
        $admin = new User('admin@riskpilot.local', 'Alice', 'Durand', $organization, [User::ROLE_SUPER_ADMIN]);
        $riskManager = new User('risk.manager@riskpilot.local', 'Romain', 'Martin', $organization, [User::ROLE_RISK_MANAGER]);
        $actionOwner = new User('action.owner@riskpilot.local', 'Sophie', 'Bernard', $organization, [User::ROLE_ACTION_OWNER]);
        foreach ([$admin, $riskManager, $actionOwner] as $user) {
            $user->setPassword($this->hasher->hashPassword($user, 'ChangeMe123!'));
        }
        $scopes = [new Scope('Siège et fonctions centrales', 'ORGANIZATION', $organization), new Scope('Production numérique', 'DEPARTMENT', $organization), new Scope('Services cloud', 'INFRASTRUCTURE', $organization)];
        $assetNames = ['Portail client', 'Base clients', 'ERP Finance', 'Messagerie', 'Annuaire', 'Plateforme cloud', 'Serveur de sauvegarde', 'Réseau interne', 'Postes administrateurs', 'Fournisseur SaaS'];
        $assets = [];
        foreach ($assetNames as $index => $name) {
            $asset = new Asset($name, ['APPLICATION', 'DATA', 'SERVER', 'CLOUD_SERVICE'][$index % 4], $scopes[$index % 3], $organization);
            $asset->setCriticality(2 + ($index % 4))->setOwner(0 === $index % 2 ? $riskManager : $actionOwner);
            $assets[] = $asset;
        }
        $threatNames = ['Hameçonnage ciblé', 'Rançongiciel', 'Compromission fournisseur', 'Abus de privilèges', 'Fuite de données', 'Déni de service', 'Erreur de configuration', 'Maliciel', 'Vol d’équipement', 'Défaillance électrique'];
        $threats = [];
        foreach ($threatNames as $index => $name) {
            $threats[] = new Threat($name, ['HUMAN', 'TECHNICAL', 'ENVIRONMENTAL'][$index % 3], $organization);
        }
        $vulnerabilityNames = ['MFA incomplet', 'Correctifs en retard', 'Droits excessifs', 'Sauvegarde non testée', 'Journalisation partielle', 'Chiffrement incomplet', 'Configuration cloud permissive', 'Sensibilisation insuffisante', 'Dépendance fournisseur', 'Segmentation limitée'];
        $vulnerabilities = [];
        foreach ($vulnerabilityNames as $index => $name) {
            $item = new Vulnerability($name, ['ACCESS', 'PATCH', 'CONFIGURATION'][$index % 3], ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'][$index % 4], $organization);
            $item->replaceAffectedAssets([$assets[$index]]);
            $vulnerabilities[] = $item;
        }
        $controls = [(new SecurityControl('Authentification multifacteur', 'Identités', $organization))->setEffectiveness(75)->setImplementationStatus('IMPLEMENTED')->setOwner($actionOwner), (new SecurityControl('Sauvegardes hors ligne', 'Résilience', $organization))->setEffectiveness(65)->setImplementationStatus('PARTIAL')->setOwner($actionOwner), (new SecurityControl('Supervision centralisée', 'Détection', $organization))->setEffectiveness(55)->setImplementationStatus('PARTIAL')->setOwner($riskManager)];
        $risks = [];
        for ($index = 0; $index < 15; ++$index) {
            $likelihood = 2 + ($index % 4);
            $impact = 3 + ($index % 3);
            $currentLikelihood = max(1, $likelihood - 1);
            $residualLikelihood = max(1, $currentLikelihood - 1);
            $risks[] = (new RiskScenario(sprintf('%s sur %s', $threats[$index % 10]->getName(), $assets[$index % 10]->getName()), $organization, $scopes[$index % 3], $assets[$index % 10], $threats[$index % 10], $riskManager))->replaceVulnerabilities([$vulnerabilities[$index % 10]])->replaceCurrentControls([$controls[$index % 3]])->setEvaluations($likelihood, $impact, $likelihood * $impact, $currentLikelihood, $impact, $currentLikelihood * $impact, $residualLikelihood, max(1, $impact - 1), $residualLikelihood * max(1, $impact - 1))->setStatus('APPROVED')->setTreatmentDecision('REDUCE')->setReviewDate(new \DateTimeImmutable(sprintf('+%d days', 30 + $index * 5)));
        }
        $actions = [];
        for ($index = 0; $index < 20; ++$index) {
            $action = (new ActionPlan(sprintf('Action %02d — %s', $index + 1, ['Renforcer les accès', 'Appliquer les correctifs', 'Tester la reprise', 'Mettre à jour la procédure'][$index % 4]), $organization, $risks[$index % 15], $actionOwner, new \DateTimeImmutable(sprintf('%+d days', -10 + $index * 5))))->setRelatedControl($controls[$index % 3])->setPriority(['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'][$index % 4])->setProgress(($index * 13) % 100)->setStartDate(new \DateTimeImmutable('-20 days'))->setExpectedRiskReduction(2 + ($index % 8));
            if (0 === $index % 6) {
                $action->setStatus('COMPLETED');
            } elseif (0 === $index % 4) {
                $action->setStatus('BLOCKED');
            } else {
                $action->setStatus('IN_PROGRESS');
            } $actions[] = $action;
        }
        $framework = (new Framework('Cadre Cyber Démonstration', '2026'))->setPublisher('RiskPilot Community')->setDescription('Référentiel générique de démonstration ne reproduisant aucune norme protégée.');
        $requirementData = [['GOV-01', 'Définir la gouvernance', 'Gouvernance'], ['ID-01', 'Gérer les identités', 'Protection'], ['PR-01', 'Protéger les données', 'Protection'], ['DE-01', 'Détecter les événements', 'Détection'], ['RE-01', 'Préparer la reprise', 'Résilience']];
        $requirements = [];
        foreach ($requirementData as [$reference, $title, $category]) {
            $requirements[] = new Requirement($framework, $reference, $title, $category);
        }
        $assessment = (new ComplianceAssessment($organization, $framework, $scopes[0], $admin, new \DateTimeImmutable()))->setStatus('COMPLETED');
        foreach ($requirements as $index => $requirement) {
            (new ComplianceResult($assessment, $requirement))->setMaturityLevel(2 + ($index % 4))->setComplianceStatus(['COMPLIANT', 'PARTIAL', 'NON_COMPLIANT', 'COMPLIANT', 'PARTIAL'][$index])->setComment('Résultat de démonstration.')->setRemediationAction($actions[$index]);
        }
        $assessment->recalculateScore();
        $notification = new Notification($actionOwner, 'ACTION_DUE_SOON', 'Actions à suivre', 'Plusieurs actions de démonstration arrivent à échéance.', '/actions');
        foreach ([$organization, $admin, $riskManager, $actionOwner, ...$scopes, ...$assets, ...$threats, ...$vulnerabilities, ...$controls, ...$risks, ...$actions, $framework, ...$requirements, $assessment, $notification] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
    }
}
