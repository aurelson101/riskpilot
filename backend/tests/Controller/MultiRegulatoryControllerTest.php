<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\ThirdParty;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MultiRegulatoryControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $manager;
    private User $viewer;
    private User $foreign;
    private ThirdParty $provider;
    private ThirdParty $foreignProvider;
    private JWTTokenManagerInterface $tokens;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($entityManager);
        $tool->dropSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $organization = new Organization('Primary');
        $other = new Organization('Other');
        $this->manager = new User('manager@example.test', 'Rita', 'Manager', $organization, [User::ROLE_RISK_MANAGER]);
        $this->viewer = new User('viewer@example.test', 'Victor', 'Viewer', $organization, [User::ROLE_VIEWER]);
        $this->foreign = new User('foreign@example.test', 'Fay', 'Manager', $other, [User::ROLE_RISK_MANAGER]);
        $this->provider = new ThirdParty($organization, $this->manager, 'Cloud Provider', 'CRITICAL');
        $this->foreignProvider = new ThirdParty($other, $this->foreign, 'Foreign Provider', 'HIGH');
        foreach ([$organization, $other, $this->manager, $this->viewer, $this->foreign, $this->provider, $this->foreignProvider] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $this->tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
    }

    private function as(User $user): void
    {
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$this->tokens->create($user));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function ago(string $interval): string
    {
        return (new \DateTimeImmutable('-'.$interval))->format(DATE_ATOM);
    }

    public function testDoraIncidentTracksDeadlinesAndCannotCloseBeforeEveryReportIsFiled(): void
    {
        $this->as($this->viewer);
        $this->client->jsonRequest('POST', '/api/regulatory-incidents', ['regime' => 'DORA_MAJOR_INCIDENT', 'title' => 'X', 'description' => 'X', 'awareAt' => $this->ago('1 hour')]);
        self::assertResponseStatusCodeSame(403);

        $this->as($this->manager);
        $this->client->jsonRequest('POST', '/api/regulatory-incidents', ['regime' => 'DORA_MAJOR_INCIDENT', 'title' => 'Panne de paiement', 'description' => 'Indisponibilité du service de paiement', 'awareAt' => $this->ago('30 hours')]);
        self::assertResponseStatusCodeSame(201);
        $incident = $this->json();
        self::assertSame('OVERDUE', $incident['worstStatus'], 'The 24-hour initial notification is overdue after 30 hours.');
        self::assertSame(['INITIAL', 'INTERMEDIATE', 'FINAL'], array_column($incident['steps'], 'key'));

        $this->client->jsonRequest('PUT', '/api/regulatory-incidents/'.$incident['id'], ['status' => 'CLOSED']);
        self::assertResponseStatusCodeSame(422, 'Closing needs every applicable report.');
        $this->client->jsonRequest('PUT', '/api/regulatory-incidents/'.$incident['id'], ['completed' => ['INITIAL' => $this->ago('20 hours'), 'BOGUS' => $this->ago('1 hour')]]);
        self::assertResponseStatusCodeSame(422, 'Unknown step.');
        $this->client->jsonRequest('PUT', '/api/regulatory-incidents/'.$incident['id'], ['completed' => ['INITIAL' => $this->ago('40 hours')]]);
        self::assertResponseStatusCodeSame(422, 'A filing cannot precede awareness.');
        $this->client->jsonRequest('PUT', '/api/regulatory-incidents/'.$incident['id'], ['completed' => ['INITIAL' => $this->ago('20 hours'), 'INTERMEDIATE' => $this->ago('10 hours'), 'FINAL' => $this->ago('1 hour')], 'status' => 'CLOSED']);
        self::assertResponseIsSuccessful();
        self::assertSame('DONE', $this->json()['worstStatus']);
        self::assertSame('CLOSED', $this->json()['status']);

        $this->client->request('GET', '/api/regulatory-incidents?regime=DORA_MAJOR_INCIDENT');
        self::assertSame(['open' => 0, 'overdue' => 0, 'dueSoon' => 0], $this->json()['summary']);
        $this->client->request('GET', '/api/regulatory-incidents?regime=NOPE');
        self::assertResponseStatusCodeSame(422);
    }

    public function testFactsAreOnlyAcceptedForTheirRegimeAndInputIsValidated(): void
    {
        $this->as($this->manager);
        $base = ['title' => 'Incident', 'description' => 'Description', 'awareAt' => $this->ago('2 hours')];
        foreach ([
            ['regime' => 'AI_ACT_SERIOUS_INCIDENT', 'classifiedAt' => $this->ago('1 hour')],
            ['regime' => 'DORA_MAJOR_INCIDENT', 'death' => true],
            ['regime' => 'CRA_INCIDENT', 'correctiveAvailableAt' => $this->ago('1 hour')],
            ['regime' => 'UNKNOWN'],
            ['regime' => 'AI_ACT_SERIOUS_INCIDENT', 'awareAt' => 'tomorrow'],
            ['regime' => 'AI_ACT_SERIOUS_INCIDENT', 'awareAt' => (new \DateTimeImmutable('+2 days'))->format(DATE_ATOM)],
            ['regime' => 'AI_ACT_SERIOUS_INCIDENT', 'death' => 'yes'],
            ['regime' => 'AI_ACT_SERIOUS_INCIDENT', 'productId' => 1],
        ] as $override) {
            $this->client->jsonRequest('POST', '/api/regulatory-incidents', $override + $base);
            self::assertResponseStatusCodeSame(422, json_encode($override, JSON_THROW_ON_ERROR));
        }
    }

    public function testAiActIncidentUsesTheShortestDeadlineAndLinksOnlyOwnSystems(): void
    {
        $this->as($this->manager);
        $this->client->jsonRequest('POST', '/api/ai-governance/systems', ['name' => 'Scoring', 'purpose' => 'Notation', 'provider' => 'Interne', 'riskLevel' => 'HIGH', 'status' => 'ACTIVE', 'humanOversight' => 'Validation humaine', 'aiActRole' => 'DEPLOYER', 'annexIiiCategory' => 'ESSENTIAL_SERVICES']);
        self::assertResponseStatusCodeSame(201);
        $system = $this->json();
        self::assertSame('DEPLOYER', $system['aiAct']['role']);
        self::assertContains('ART-26', array_column($system['aiAct']['obligations'], 'reference'));
        self::assertNotContains('ART-9', array_column($system['aiAct']['obligations'], 'reference'), 'Provider-only requirement is not listed for a deployer.');

        $this->client->jsonRequest('POST', '/api/regulatory-incidents', ['regime' => 'AI_ACT_SERIOUS_INCIDENT', 'title' => 'Décision erronée', 'description' => 'Refus injustifié', 'awareAt' => $this->ago('3 days'), 'widespread' => true, 'aiSystemId' => $system['id']]);
        self::assertResponseStatusCodeSame(201);
        $incident = $this->json();
        self::assertSame('ART-73.3', $incident['steps'][0]['article']);
        self::assertSame('OVERDUE', $incident['worstStatus'], 'Two days elapsed.');
        self::assertSame($system['id'], $incident['aiSystem']['id']);

        $this->client->request('GET', '/api/regulatory-incidents/'.$incident['id'].'/report');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('AI Act art. 73', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('ART-73.3', (string) $this->client->getResponse()->getContent());

        $this->as($this->foreign);
        $this->client->request('GET', '/api/regulatory-incidents/'.$incident['id'].'/report');
        self::assertResponseStatusCodeSame(404, 'Tenant isolation.');
        $this->client->jsonRequest('POST', '/api/regulatory-incidents', ['regime' => 'AI_ACT_SERIOUS_INCIDENT', 'title' => 'T', 'description' => 'D', 'awareAt' => $this->ago('1 hour'), 'aiSystemId' => $system['id']]);
        self::assertResponseStatusCodeSame(422, 'A foreign AI system cannot be linked.');
        $this->client->request('GET', '/api/regulatory-incidents');
        self::assertSame([], $this->json()['items']);
    }

    public function testAiActQualificationRules(): void
    {
        $this->as($this->manager);
        $this->client->jsonRequest('POST', '/api/ai-governance/systems', ['name' => 'Chatbot', 'purpose' => 'Support', 'provider' => 'Fournisseur', 'riskLevel' => 'LIMITED', 'annexIiiCategory' => 'EMPLOYMENT']);
        self::assertResponseStatusCodeSame(422, 'Annex III needs a high-risk level.');
        $this->client->jsonRequest('POST', '/api/ai-governance/systems', ['name' => 'Chatbot', 'purpose' => 'Support', 'provider' => 'Fournisseur', 'riskLevel' => 'LIMITED', 'systemicRisk' => true]);
        self::assertResponseStatusCodeSame(422, 'Systemic risk needs a general-purpose model.');
        $this->client->jsonRequest('POST', '/api/ai-governance/systems', ['name' => 'Chatbot', 'purpose' => 'Support', 'provider' => 'Fournisseur', 'riskLevel' => 'LIMITED', 'aiActRole' => 'EMPEROR']);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/ai-governance/systems', ['name' => 'Modèle', 'purpose' => 'Base', 'provider' => 'Labo', 'riskLevel' => 'LIMITED', 'aiActRole' => 'PROVIDER', 'generalPurpose' => true, 'systemicRisk' => true, 'euRegistrationReference' => 'EU-123']);
        self::assertResponseStatusCodeSame(201);
        $references = array_column($this->json()['aiAct']['obligations'], 'reference');
        foreach (['ART-4', 'ART-50', 'ART-53', 'ART-51', 'ART-55'] as $reference) {
            self::assertContains($reference, $references);
        }
        $id = $this->json()['id'];
        $this->client->jsonRequest('PUT', '/api/ai-governance/systems/'.$id, ['aiActRole' => 'NOT_APPLICABLE']);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['aiAct']['obligations']);
        $this->client->jsonRequest('PUT', '/api/ai-governance/systems/'.$id, ['riskLevel' => 'PROHIBITED', 'aiActRole' => '']);
        self::assertResponseIsSuccessful();
        self::assertSame(['ART-5'], array_column($this->json()['aiAct']['obligations'], 'reference'));
    }

    public function testCraProductInventoryReportsReadinessGaps(): void
    {
        $this->as($this->viewer);
        $this->client->jsonRequest('POST', '/api/cra/products', ['name' => 'Box', 'version' => '1.0']);
        self::assertResponseStatusCodeSame(403);

        $this->as($this->manager);
        $placed = (new \DateTimeImmutable('-1 year'))->format('Y-m-d');
        $this->client->jsonRequest('POST', '/api/cra/products', ['name' => 'Passerelle IoT', 'version' => '2.1', 'category' => 'IMPORTANT_CLASS_II', 'status' => 'ON_MARKET', 'placedOnMarketAt' => $placed, 'supportEndsAt' => (new \DateTimeImmutable('+2 years'))->format('Y-m-d'), 'conformityRoute' => 'INTERNAL_CONTROL', 'declarationReference' => 'DOC-1']);
        self::assertResponseStatusCodeSame(201);
        $product = $this->json();
        foreach (['SBOM_MISSING', 'DISCLOSURE_CONTACT_MISSING', 'SUPPORT_PERIOD_UNDER_FIVE_YEARS', 'THIRD_PARTY_ASSESSMENT_REQUIRED'] as $gap) {
            self::assertContains($gap, $product['gaps']);
        }

        $this->client->jsonRequest('POST', '/api/cra/products', ['name' => 'Passerelle IoT', 'version' => '2.1']);
        self::assertResponseStatusCodeSame(422, 'Duplicate product and version.');
        $this->client->jsonRequest('POST', '/api/cra/products', ['name' => 'Sans date', 'version' => '1', 'status' => 'ON_MARKET']);
        self::assertResponseStatusCodeSame(422, 'On-market products need a market date.');
        $this->client->jsonRequest('POST', '/api/cra/products', ['name' => 'Date', 'version' => '1', 'placedOnMarketAt' => '12/31/2026']);
        self::assertResponseStatusCodeSame(422, 'Strict date format.');

        $this->client->jsonRequest('PUT', '/api/cra/products/'.$product['id'], ['sbomReference' => 'https://sbom.example/box.json', 'vulnerabilityContact' => 'psirt@example.test', 'supportEndsAt' => (new \DateTimeImmutable('+5 years'))->format('Y-m-d'), 'conformityRoute' => 'THIRD_PARTY']);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['gaps']);

        $this->as($this->foreign);
        $this->client->request('GET', '/api/cra/products');
        self::assertSame(0, $this->json()['summary']['products']);
        $this->client->jsonRequest('PUT', '/api/cra/products/'.$product['id'], ['name' => 'Piraté']);
        self::assertResponseStatusCodeSame(404);

        $this->as($this->manager);
        $this->client->jsonRequest('POST', '/api/regulatory-incidents', ['regime' => 'CRA_VULNERABILITY', 'title' => 'CVE exploitée', 'description' => 'Exploitation active', 'awareAt' => $this->ago('1 hour'), 'productId' => $product['id']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Passerelle IoT', $this->json()['product']['name']);
        self::assertTrue($this->json()['steps'][2]['provisional']);
    }

    public function testDoraRegisterCompletenessIsolationAndSafeCsvExport(): void
    {
        $this->as($this->viewer);
        $this->client->jsonRequest('POST', '/api/dora/arrangements', ['thirdPartyId' => $this->provider->getId(), 'serviceType' => 'SAAS', 'description' => 'X']);
        self::assertResponseStatusCodeSame(403);

        $this->as($this->manager);
        $this->client->jsonRequest('POST', '/api/dora/arrangements', ['thirdPartyId' => $this->foreignProvider->getId(), 'serviceType' => 'SAAS', 'description' => 'X']);
        self::assertResponseStatusCodeSame(422, 'Foreign provider.');
        $this->client->jsonRequest('POST', '/api/dora/arrangements', ['thirdPartyId' => $this->provider->getId(), 'serviceType' => 'SAAS', 'description' => 'X', 'providerLei' => 'BADLEI']);
        self::assertResponseStatusCodeSame(422, 'LEI format.');
        $this->client->jsonRequest('POST', '/api/dora/arrangements', ['thirdPartyId' => $this->provider->getId(), 'serviceType' => 'SAAS', 'description' => 'X', 'startsAt' => '2026-05-01', 'endsAt' => '2026-04-01']);
        self::assertResponseStatusCodeSame(422, 'End before start.');

        $this->client->jsonRequest('POST', '/api/dora/arrangements', ['thirdPartyId' => $this->provider->getId(), 'serviceType' => 'CLOUD_INFRASTRUCTURE', 'description' => '=HYPERLINK("http://evil")', 'supportsCriticalFunction' => true, 'providerLei' => '529900t8bm49aursdo55']);
        self::assertResponseStatusCodeSame(201);
        $arrangement = $this->json();
        self::assertSame('529900T8BM49AURSDO55', $arrangement['providerLei']);
        foreach (['CONTRACT_REFERENCE_MISSING', 'FUNCTION_NOT_DESCRIBED', 'SUBSTITUTABILITY_NOT_ASSESSED', 'EXIT_PLAN_NOT_TESTED', 'DATA_LOCATION_MISSING'] as $gap) {
            self::assertContains($gap, $arrangement['gaps']);
        }

        $this->client->jsonRequest('PUT', '/api/dora/arrangements/'.$arrangement['id'], [
            'functionSupported' => 'Paiements', 'contractReference' => 'CTR-9', 'startsAt' => '2025-01-01', 'dataLocation' => 'UE', 'subcontractors' => 'Aucun',
            'substitutability' => 'DIFFICULT', 'exitPlanTestedAt' => (new \DateTimeImmutable('-2 months'))->format('Y-m-d'),
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['gaps']);
        $this->client->jsonRequest('PUT', '/api/dora/arrangements/'.$arrangement['id'], ['exitPlanTestedAt' => (new \DateTimeImmutable('-18 months'))->format('Y-m-d')]);
        self::assertSame(['EXIT_PLAN_TEST_OUTDATED'], $this->json()['gaps']);

        $this->client->request('GET', '/api/dora/arrangements');
        self::assertSame(['arrangements' => 1, 'criticalFunctions' => 1, 'withGaps' => 1], $this->json()['summary']);
        $this->client->request('GET', '/api/dora/register.csv');
        self::assertResponseIsSuccessful();
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Cloud Provider', $csv);
        self::assertStringContainsString("'=HYPERLINK", $csv, 'Formula injection is neutralised.');
        self::assertStringNotContainsString(';=HYPERLINK', $csv);

        $this->as($this->foreign);
        $this->client->request('GET', '/api/dora/arrangements');
        self::assertSame([], $this->json()['items']);
        $this->client->request('GET', '/api/dora/register.csv');
        self::assertStringNotContainsString('Cloud Provider', (string) $this->client->getResponse()->getContent());
        $this->client->jsonRequest('PUT', '/api/dora/arrangements/'.$arrangement['id'], ['description' => 'Piraté']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeadlineRemindersAreIdempotentAndSkipClosedOrNotApplicableCases(): void
    {
        $this->as($this->manager);
        $create = function (array $extra): array {
            $this->client->jsonRequest('POST', '/api/regulatory-incidents', $extra + ['regime' => 'DORA_MAJOR_INCIDENT', 'title' => 'Panne', 'description' => 'Panne de paiement', 'awareAt' => $this->ago('30 hours')]);
            self::assertResponseStatusCodeSame(201);

            return $this->json();
        };
        $create([]);
        $create(['title' => 'Hors champ', 'applicable' => false]);
        $done = $create(['title' => 'Déjà notifié']);
        $this->client->jsonRequest('PUT', '/api/regulatory-incidents/'.$done['id'], ['completed' => ['INITIAL' => $this->ago('20 hours'), 'INTERMEDIATE' => $this->ago('10 hours'), 'FINAL' => $this->ago('1 hour')], 'status' => 'CLOSED']);
        self::assertResponseIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:regulatory:remind-deadlines'));
        $tester->execute([]);
        $tester->execute([]);
        $notifications = $entityManager->getRepository(Notification::class)->findBy(['type' => 'REGULATORY_DEADLINE']);
        self::assertGreaterThanOrEqual(1, count($notifications));
        self::assertLessThanOrEqual(3, count($notifications), 'At most one reminder per overdue step and day, whatever the number of runs.');
        foreach ($notifications as $notification) {
            self::assertSame($this->manager->getId(), $notification->getRecipient()->getId());
            self::assertStringContainsString('Panne', $notification->getMessage());
            self::assertStringNotContainsString('Hors champ', $notification->getMessage());
            self::assertStringNotContainsString('Déjà notifié', $notification->getMessage());
        }
        self::assertSame('DORA : notification initiale en retard', $notifications[0]->getTitle());
    }
}
