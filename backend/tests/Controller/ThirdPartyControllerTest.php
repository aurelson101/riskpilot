<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ThirdPartyControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private JWTTokenManagerInterface $tokens;
    private User $manager;
    private User $viewer;
    private User $foreign;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $organization = new Organization('Organisation');
        $other = new Organization('Autre');
        $this->manager = new User('manager@example.test', 'Marie', 'Risques', $organization, [User::ROLE_RISK_MANAGER]);
        $this->viewer = new User('viewer@example.test', 'Victor', 'Lecture', $organization, [User::ROLE_VIEWER]);
        $this->foreign = new User('foreign@example.test', 'François', 'Externe', $other, [User::ROLE_ADMIN]);
        foreach ([$organization, $other, $this->manager, $this->viewer, $this->foreign] as $entity) {
            $manager->persist($entity);
        } $manager->flush();
        $this->tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
    }

    public function testSupplierQuestionnairePortalAndReview(): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/third-parties', ['name' => 'Cloud SA', 'contactEmail' => 'security@cloud.test', 'services' => 'Hébergement', 'dataCategories' => ['clients'], 'criticality' => 'CRITICAL', 'status' => 'ACTIVE', 'ownerId' => $this->manager->getId(), 'exitPlan' => 'Export et réversibilité']);
        self::assertResponseStatusCodeSame(201);
        $thirdParty = $this->payload();
        $this->client->jsonRequest('POST', '/api/third-parties/'.$thirdParty['id'].'/assessments', ['reviewerId' => $this->manager->getId(), 'title' => 'Évaluation 2026', 'version' => 2, 'expiresAt' => (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM), 'questions' => [['id' => 'q1', 'label' => 'MFA activé ?', 'weight' => 5], ['id' => 'q2', 'label' => 'PRA testé ?', 'weight' => 5]]]);
        self::assertResponseStatusCodeSame(201);
        $assessment = $this->payload();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->request('GET', '/api/public/supplier-assessments/'.$assessment['publicToken']);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->payload()['questions']);
        $this->client->jsonRequest('POST', '/api/public/supplier-assessments/'.$assessment['publicToken'], ['responses' => ['q1' => true, 'q2' => true], 'evidence' => ['ISO27001.pdf']]);
        self::assertResponseIsSuccessful();
        self::assertSame('SUBMITTED', $this->payload()['status']);
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/supplier-assessments/'.$assessment['id'].'/review', ['score' => 82, 'comment' => 'Preuves cohérentes']);
        self::assertResponseIsSuccessful();
        self::assertSame(82, $this->payload()['score']);
        $this->client->request('GET', '/api/third-parties');
        self::assertSame(82, $this->payload()[0]['cyberScore']);
    }

    public function testTenantRelationsAreRejected(): void
    {
        $this->authenticate($this->foreign);
        $this->client->jsonRequest('POST', '/api/third-parties', ['name' => 'Tiers', 'criticality' => 'HIGH', 'ownerId' => $this->manager->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCampaignValidationRejectsMalformedInputBeforeCreation(): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/third-parties', $this->thirdPartyInput('Tiers campagne'));
        self::assertResponseStatusCodeSame(201);
        $thirdParty = $this->payload();
        $valid = $this->campaignInput();
        $invalidInputs = [
            ['expiresAt' => 'tomorrow'],
            ['expiresAt' => '2030-02-30T12:00:00+00:00'],
            ['expiresAt' => '2030-01-01T25:00:00+00:00'],
            ['expiresAt' => '2030-01-01T12:00:00'],
            ['expiresAt' => '2030-01-01'],
            ['expiresAt' => '2020-01-01T12:00:00Z'],
            ['expiresAt' => []],
            ['expiresAt' => null],
            ['title' => []],
            ['title' => str_repeat('é', 201)],
            ['title' => '   '],
            ['version' => '1'],
            ['version' => null],
            ['version' => 0],
            ['reviewerId' => $this->foreign->getId()],
            ['reviewerId' => (string) $this->manager->getId()],
            ['questions' => []],
            ['questions' => ['q1' => $valid['questions'][0]]],
            ['questions' => [$valid['questions'][0], [...$valid['questions'][0], 'id' => ' q1 ']]],
            ['questions' => [[...$valid['questions'][0], 'id' => ['q1']]]],
            ['questions' => [[...$valid['questions'][0], 'label' => true]]],
            ['questions' => [[...$valid['questions'][0], 'weight' => 0]]],
            ['questions' => [[...$valid['questions'][0], 'weight' => 101]]],
            ['questions' => [[...$valid['questions'][0], 'weight' => '5']]],
            ['questions' => [[...$valid['questions'][0], 'weight' => null]]],
            ['questions' => array_map(static fn (int $id): array => ['id' => 'q'.$id, 'label' => 'Question', 'weight' => 1], range(1, 101))],
        ];
        foreach ($invalidInputs as $input) {
            $this->client->jsonRequest('POST', '/api/third-parties/'.$thirdParty['id'].'/assessments', [...$valid, ...$input]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('INVALID_INPUT', $this->payload()['code']);
            $this->client->request('GET', '/api/third-parties');
            self::assertSame([], $this->payload()[0]['assessments']);
        }

        $this->client->jsonRequest('POST', '/api/third-parties/'.$thirdParty['id'].'/assessments', [
            ...$valid,
            'expiresAt' => (new \DateTimeImmutable('+30 days', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'questions' => [['id' => ' q1 ', 'label' => ' MFA activé ? ']],
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload();
        self::assertSame('DRAFT', $created['status']);
        self::assertSame(64, strlen($created['publicToken']));
        $this->client->request('GET', '/api/supplier-assessments/'.$created['id']);
        self::assertSame([['id' => 'q1', 'label' => 'MFA activé ?', 'weight' => 1]], $this->payload()['questions']);

        $this->client->jsonRequest('POST', '/api/third-parties/'.$thirdParty['id'].'/assessments', [
            ...$valid,
            'questions' => [['id' => '0', 'label' => 'MFA ?', 'weight' => 1], ['id' => '1', 'label' => 'PRA ?', 'weight' => 1]],
        ]);
        self::assertResponseStatusCodeSame(201);
        $numeric = $this->payload();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->jsonRequest('POST', '/api/public/supplier-assessments/'.$numeric['publicToken'], ['responses' => (object) ['0' => false, '1' => 'Conforme']]);
        self::assertResponseIsSuccessful();
        self::assertSame('SUBMITTED', $this->payload()['status']);
    }

    public function testCampaignCreationIsPermissionAndTenantScoped(): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/third-parties', $this->thirdPartyInput('Tiers campagne'));
        self::assertResponseStatusCodeSame(201);
        $thirdParty = $this->payload();

        $this->authenticate($this->viewer);
        $this->client->jsonRequest('POST', '/api/third-parties/'.$thirdParty['id'].'/assessments', $this->campaignInput());
        self::assertResponseStatusCodeSame(403);
        $this->authenticate($this->foreign);
        $this->client->jsonRequest('POST', '/api/third-parties/'.$thirdParty['id'].'/assessments', [...$this->campaignInput(), 'reviewerId' => $this->foreign->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->authenticate($this->manager);
        $this->client->request('GET', '/api/third-parties');
        self::assertSame([], $this->payload()[0]['assessments']);
    }

    public function testPublicResponsesAndEvidenceAreValidatedBeforeMutation(): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/third-parties', $this->thirdPartyInput('Tiers réponse'));
        self::assertResponseStatusCodeSame(201);
        $thirdParty = $this->payload();
        $this->client->jsonRequest('POST', '/api/third-parties/'.$thirdParty['id'].'/assessments', $this->campaignInput());
        self::assertResponseStatusCodeSame(201);
        $assessment = $this->payload();
        $valid = ['responses' => ['q1' => false, 'q2' => 'PRA testé et planifié'], 'evidence' => [' Rapport déclaré.pdf ']];
        $invalidInputs = [
            ['responses' => 'oui'],
            ['responses' => []],
            ['responses' => [true, false]],
            ['responses' => ['q1' => true]],
            ['responses' => ['q1' => true, 'q2' => null]],
            ['responses' => ['q1' => true, 'q2' => []]],
            ['responses' => ['q1' => true, 'q2' => 1]],
            ['responses' => ['q1' => true, 'q2' => '   ']],
            ['responses' => ['q1' => true, 'q2' => str_repeat('é', 4001)]],
            ['responses' => ['q1' => true, 'q2' => true, 'unknown' => true]],
            ['evidence' => 'Rapport.pdf'],
            ['evidence' => null],
            ['evidence' => [['name' => 'Rapport.pdf']]],
            ['evidence' => [true]],
            ['evidence' => ['   ']],
            ['evidence' => ['<a href="https://example.test">Rapport</a>']],
            ['evidence' => ['name' => 'Rapport.pdf']],
            ['evidence' => [str_repeat('é', 501)]],
            ['evidence' => array_fill(0, 11, 'Rapport.pdf')],
        ];
        foreach ($invalidInputs as $input) {
            $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
            $this->client->jsonRequest('POST', '/api/public/supplier-assessments/'.$assessment['publicToken'], [...$valid, ...$input]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('INVALID_INPUT', $this->payload()['code']);
            $this->authenticate($this->manager);
            $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
            $details = $this->payload();
            self::assertSame('DRAFT', $details['status']);
            self::assertSame([], $details['responses']);
            self::assertSame([], $details['evidence']);
            self::assertNull($details['submittedAt']);
        }

        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->jsonRequest('POST', '/api/public/supplier-assessments/'.$assessment['publicToken'], $valid);
        self::assertResponseIsSuccessful();
        self::assertSame('SUBMITTED', $this->payload()['status']);
        $this->authenticate($this->manager);
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        self::assertSame($valid['responses'], $this->payload()['responses']);
        self::assertSame(['Rapport déclaré.pdf'], $this->payload()['evidence']);
    }

    public function testAssessmentDetailsAreReadableWithoutExposingThePublicToken(): void
    {
        $assessment = $this->submittedAssessment();
        $this->authenticate($this->viewer);
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        self::assertResponseIsSuccessful();
        $details = $this->payload();
        self::assertSame('SUBMITTED', $details['status']);
        self::assertSame('Évaluation fournisseur', $details['title']);
        self::assertSame(1, $details['version']);
        self::assertSame($this->manager->getId(), $details['reviewer']['id']);
        self::assertSame([['id' => 'q1', 'label' => 'MFA activé ?', 'weight' => 5]], $details['questions']);
        self::assertSame(['q1' => true], $details['responses']);
        self::assertSame(['Rapport déclaré.pdf'], $details['evidence']);
        self::assertArrayNotHasKey('publicToken', $details);

        $this->client->jsonRequest('POST', '/api/supplier-assessments/'.$assessment['id'].'/review', ['score' => 82, 'comment' => 'Tentative en lecture seule']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        self::assertSame($details, $this->payload());

        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAssessmentDetailsAndReviewAreTenantScoped(): void
    {
        $assessment = $this->submittedAssessment();
        $this->authenticate($this->foreign);
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('NOT_FOUND', $this->payload()['code']);

        $this->client->jsonRequest('POST', '/api/supplier-assessments/'.$assessment['id'].'/review', ['score' => 82, 'comment' => 'Tentative autre organisation']);
        self::assertResponseStatusCodeSame(404);
        $this->authenticate($this->manager);
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        self::assertSame('SUBMITTED', $this->payload()['status']);
        self::assertNull($this->payload()['reviewedAt']);
    }

    #[DataProvider('invalidReviewProvider')]
    public function testInvalidReviewIsRejectedWithoutMutation(mixed $score, mixed $comment): void
    {
        $assessment = $this->submittedAssessment();
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        $before = $this->payload();

        $this->client->jsonRequest('POST', '/api/supplier-assessments/'.$assessment['id'].'/review', ['score' => $score, 'comment' => $comment]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('INVALID_INPUT', $this->payload()['code']);
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        self::assertSame($before, $this->payload());
        $this->client->request('GET', '/api/third-parties');
        self::assertSame(0, $this->payload()[0]['cyberScore']);
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function invalidReviewProvider(): iterable
    {
        yield 'score chaîne' => ['82', 'Preuves cohérentes'];
        yield 'score fractionnaire' => [82.5, 'Preuves cohérentes'];
        yield 'score booléen' => [true, 'Preuves cohérentes'];
        yield 'score tableau' => [[], 'Preuves cohérentes'];
        yield 'score absent' => [null, 'Preuves cohérentes'];
        yield 'score négatif' => [-1, 'Preuves cohérentes'];
        yield 'score au-delà de cent' => [101, 'Preuves cohérentes'];
        yield 'commentaire tableau' => [82, []];
        yield 'commentaire booléen' => [82, true];
        yield 'commentaire numérique' => [82, 123];
        yield 'commentaire absent' => [82, null];
        yield 'commentaire vide' => [82, '   '];
    }

    public function testPublicSubmissionCannotOverwriteSubmittedOrReviewedAssessment(): void
    {
        $assessment = $this->submittedAssessment();
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        $submitted = $this->payload();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->jsonRequest('POST', '/api/public/supplier-assessments/'.$assessment['publicToken'], ['responses' => ['q1' => false], 'evidence' => ['Remplacement.pdf']]);
        self::assertResponseStatusCodeSame(422);
        $this->authenticate($this->manager);
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        self::assertSame($submitted, $this->payload());

        $this->client->jsonRequest('POST', '/api/supplier-assessments/'.$assessment['id'].'/review', ['score' => 82, 'comment' => 'Preuves cohérentes']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        $reviewed = $this->payload();
        self::assertSame('REVIEWED', $reviewed['status']);
        self::assertSame(82, $reviewed['score']);
        self::assertSame('Preuves cohérentes', $reviewed['reviewComment']);
        self::assertNotNull($reviewed['reviewedAt']);
        self::assertArrayNotHasKey('publicToken', $reviewed);

        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->jsonRequest('POST', '/api/public/supplier-assessments/'.$assessment['publicToken'], ['responses' => ['q1' => false], 'evidence' => ['Remplacement.pdf']]);
        self::assertResponseStatusCodeSame(422);
        $this->authenticate($this->manager);
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        self::assertSame($reviewed, $this->payload());
        $this->client->request('GET', '/api/third-parties');
        self::assertSame(82, $this->payload()[0]['cyberScore']);
    }

    public function testCreateAndUpdateThirdPartyWithStrictContractDates(): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/third-parties', [
            ...$this->thirdPartyInput('Prestataire contrat'),
            'contractReference' => 'CTR-2026-01',
            'contractEndsAt' => '2027-12-31',
            'nextAssessmentAt' => '2026-12-15',
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload();
        self::assertSame('CTR-2026-01', $created['contractReference']);
        self::assertSame('2027-12-31', $created['contractEndsAt']);
        self::assertSame('2026-12-15', $created['nextAssessmentAt']);

        $this->client->jsonRequest('PUT', '/api/third-parties/'.$created['id'], [
            ...$this->thirdPartyInput('Prestataire contrat actualisé'),
            'contractReference' => 'CTR-2027-02',
            'contractEndsAt' => null,
            'nextAssessmentAt' => '2027-06-30',
        ]);
        self::assertResponseIsSuccessful();
        $updated = $this->payload();
        self::assertSame('Prestataire contrat actualisé', $updated['name']);
        self::assertSame('CTR-2027-02', $updated['contractReference']);
        self::assertNull($updated['contractEndsAt']);
        self::assertSame('2027-06-30', $updated['nextAssessmentAt']);
    }

    #[DataProvider('invalidDateProvider')]
    public function testInvalidDatesAreRejectedWithoutPartialUpdate(string $field, mixed $invalid): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/third-parties', [
            ...$this->thirdPartyInput('Prestataire inchangé'),
            'contractReference' => 'CTR-STABLE',
            'contractEndsAt' => '2027-12-31',
            'nextAssessmentAt' => '2026-12-15',
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload();

        $this->client->jsonRequest('PUT', '/api/third-parties/'.$created['id'], [
            ...$this->thirdPartyInput('Modification à refuser'),
            'contractReference' => 'CTR-MODIFIED',
            'contractEndsAt' => '2028-01-31',
            'nextAssessmentAt' => '2028-02-01',
            $field => $invalid,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('INVALID_INPUT', $this->payload()['code']);

        $this->client->request('GET', '/api/third-parties');
        self::assertResponseIsSuccessful();
        $stored = $this->payload()[0];
        self::assertSame('Prestataire inchangé', $stored['name']);
        self::assertSame('CTR-STABLE', $stored['contractReference']);
        self::assertSame('2027-12-31', $stored['contractEndsAt']);
        self::assertSame('2026-12-15', $stored['nextAssessmentAt']);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidDateProvider(): iterable
    {
        yield 'jour inexistant' => ['contractEndsAt', '2026-02-30'];
        yield 'date relative' => ['contractEndsAt', 'tomorrow'];
        yield 'booléen' => ['contractEndsAt', true];
        yield 'tableau' => ['nextAssessmentAt', []];
        yield 'date avec heure' => ['nextAssessmentAt', '2026-12-15T10:00:00+00:00'];
    }

    public function testViewerCannotManageThirdParties(): void
    {
        $this->authenticate($this->viewer);
        $this->client->jsonRequest('POST', '/api/third-parties', $this->thirdPartyInput('Interdit'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('FORBIDDEN', $this->payload()['code']);
    }

    public function testThirdPartyFromAnotherOrganizationCannotBeUpdated(): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/third-parties', $this->thirdPartyInput('Tiers local'));
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload();

        $this->authenticate($this->foreign);
        $this->client->jsonRequest('PUT', '/api/third-parties/'.$created['id'], [
            'name' => 'Tentative étrangère',
            'criticality' => 'HIGH',
            'status' => 'ACTIVE',
            'ownerId' => $this->foreign->getId(),
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('NOT_FOUND', $this->payload()['code']);
    }

    private function authenticate(User $user): void
    {
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$this->tokens->create($user));
    }

    /** @return array<string, mixed> */
    private function submittedAssessment(): array
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/third-parties', $this->thirdPartyInput('Tiers évalué'));
        self::assertResponseStatusCodeSame(201);
        $thirdParty = $this->payload();
        $this->client->jsonRequest('POST', '/api/third-parties/'.$thirdParty['id'].'/assessments', [
            'reviewerId' => $this->manager->getId(),
            'title' => 'Évaluation fournisseur',
            'version' => 1,
            'expiresAt' => (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM),
            'questions' => [['id' => 'q1', 'label' => 'MFA activé ?', 'weight' => 5]],
        ]);
        self::assertResponseStatusCodeSame(201);
        $assessment = $this->payload();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->jsonRequest('POST', '/api/public/supplier-assessments/'.$assessment['publicToken'], ['responses' => ['q1' => true], 'evidence' => ['Rapport déclaré.pdf']]);
        self::assertResponseIsSuccessful();
        $this->authenticate($this->manager);

        return $assessment;
    }

    /** @return array<string, mixed> */
    public function testProfileValidationRejectsMalformedFieldsWithoutPartialMutation(): void
    {
        $this->authenticate($this->manager);
        $valid = $this->thirdPartyInput('Tiers inchangé');
        $this->client->jsonRequest('POST', '/api/third-parties', $valid);
        self::assertResponseStatusCodeSame(201);
        $original = $this->payload();
        $invalid = [
            ['ownerId' => (string) $this->manager->getId()], ['ownerId' => 0], ['ownerId' => 2147483648],
            ['name' => []], ['name' => ' '], ['name' => str_repeat('é', 201)],
            ['contactEmail' => []], ['contactEmail' => 'invalid'], ['contactEmail' => str_repeat('a', 181)],
            ['criticality' => null], ['criticality' => []], ['status' => 'UNKNOWN'], ['status' => null],
            ['dataCategories' => 'clients'], ['dataCategories' => [null]], ['dataCategories' => [123]], ['dataCategories' => ['key' => 'value']], ['dataCategories' => array_fill(0, 101, 'x')], ['dataCategories' => [str_repeat('x', 201)]],
            ['certifications' => [false]], ['certifications' => str_repeat('x', 201)], ['certifications' => array_fill(0, 101, 'x')],
            ['dataCategories' => null], ['certifications' => null],
            ['services' => false], ['exitPlan' => []], ['dependencies' => str_repeat('x', 10001)], ['riskSummary' => 5], ['compensatingMeasures' => []], ['sla' => str_repeat('x', 201)], ['contractReference' => str_repeat('x', 201)],
        ];
        foreach ($invalid as $patch) {
            $this->client->jsonRequest('PUT', '/api/third-parties/'.$original['id'], [...$valid, 'name' => 'Mutation interdite', ...$patch]);
            self::assertResponseStatusCodeSame(422);
            $this->client->request('GET', '/api/third-parties');
            self::assertSame($original, $this->payload()[0]);
        }
        $this->client->jsonRequest('PUT', '/api/third-parties/'.$original['id'], [...$valid, 'contactEmail' => ' owner@example.test ', 'dataCategories' => [' clients ', 'clients', ''], 'certifications' => ['ISO 27001', ' ISO 27001 ']]);
        self::assertResponseIsSuccessful();
        self::assertSame('owner@example.test', $this->payload()['contactEmail']);
        self::assertSame(['clients'], $this->payload()['dataCategories']);
        self::assertSame(['ISO 27001'], $this->payload()['certifications']);
    }

    public function testPublicResponsesAreNotCachedAndSupportCanonicalUppercaseTokens(): void
    {
        $assessment = $this->submittedAssessment();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->request('GET', '/api/public/supplier-assessments/'.strtoupper($assessment['publicToken']));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        $this->client->request('POST', '/api/public/supplier-assessments/'.$assessment['publicToken'], server: ['CONTENT_TYPE' => 'application/json'], content: '{broken');
        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        foreach (['short', str_repeat('z', 64), str_repeat('a', 65)] as $token) {
            $this->client->request('GET', '/api/public/supplier-assessments/'.$token);
            self::assertResponseStatusCodeSame(404);
            self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        }
    }

    public function testReviewCommentLimitDoesNotMutateSubmittedAssessment(): void
    {
        $assessment = $this->submittedAssessment();
        $this->client->jsonRequest('POST', '/api/supplier-assessments/'.$assessment['id'].'/review', ['score' => 70, 'comment' => str_repeat('é', 10001)]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/api/supplier-assessments/'.$assessment['id']);
        self::assertSame('SUBMITTED', $this->payload()['status']);
    }

    private function thirdPartyInput(string $name): array
    {
        return [
            'name' => $name,
            'criticality' => 'HIGH',
            'status' => 'ACTIVE',
            'ownerId' => $this->manager->getId(),
        ];
    }

    /** @return array<string, mixed> */
    private function campaignInput(): array
    {
        return [
            'reviewerId' => $this->manager->getId(),
            'title' => 'Évaluation fournisseur',
            'version' => 1,
            'expiresAt' => (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM),
            'questions' => [['id' => 'q1', 'label' => 'MFA activé ?', 'weight' => 5], ['id' => 'q2', 'label' => 'PRA testé ?', 'weight' => 5]],
        ];
    }

    /** @return array<mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
