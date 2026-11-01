<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AiSystem;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AiGovernanceControllerTest extends WebTestCase
{
    public function testRegisterImpactAssessmentWorkflowCopilotEntryAndMandatoryDocuments(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $organization = new Organization('Primary');
        $other = new Organization('Other');
        $admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $author = new User('author@example.test', 'Rita', 'Manager', $organization, [User::ROLE_RISK_MANAGER]);
        $viewer = new User('viewer@example.test', 'Victor', 'Viewer', $organization, [User::ROLE_VIEWER]);
        $foreign = new User('foreign@example.test', 'Fay', 'Manager', $other, [User::ROLE_RISK_MANAGER]);
        foreach ([$organization, $other, $admin, $author, $viewer, $foreign] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $as = static fn (User $user) => $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($user));

        // Le copilote est inscrit au registre dès que l'administrateur enregistre la configuration IA.
        $as($admin);
        $client->jsonRequest('PUT', '/api/settings/ai', ['provider' => 'MISTRAL', 'model' => 'mistral-large-latest', 'apiKey' => 'test-key', 'dataPolicy' => 'MINIMAL', 'enabled' => false]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/ai-governance/systems');
        $systems = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $systems);
        self::assertTrue($systems[0]['builtIn']);
        self::assertSame('SUSPENDED', $systems[0]['status']);
        self::assertSame('LIMITED', $systems[0]['riskLevel']);
        $copilotId = $systems[0]['id'];

        $as($viewer);
        $client->jsonRequest('POST', '/api/ai-governance/systems', ['name' => 'X', 'purpose' => 'X', 'provider' => 'X', 'riskLevel' => 'MINIMAL']);
        self::assertResponseStatusCodeSame(403, 'A viewer cannot register an AI system.');

        $as($author);
        $client->jsonRequest('POST', '/api/ai-governance/systems', ['name' => 'Tri des CV', 'purpose' => 'Présélection de candidatures', 'provider' => 'Interne', 'riskLevel' => 'PROHIBITED', 'status' => 'ACTIVE']);
        self::assertResponseStatusCodeSame(422, 'A prohibited system cannot be active.');
        $client->jsonRequest('POST', '/api/ai-governance/systems', ['name' => 'Tri des CV', 'purpose' => 'Présélection de candidatures', 'provider' => 'Interne', 'riskLevel' => 'HIGH', 'status' => 'ACTIVE']);
        self::assertResponseStatusCodeSame(422, 'A high-risk active system needs a human-oversight description.');
        $client->jsonRequest('POST', '/api/ai-governance/systems', ['name' => 'Tri des CV', 'purpose' => 'Présélection de candidatures', 'provider' => 'Interne', 'riskLevel' => 'HIGH', 'status' => 'ACTIVE', 'humanOversight' => 'Un recruteur valide chaque décision.', 'dataCategories' => ['CV', 'CV', 'Évaluations']]);
        self::assertResponseStatusCodeSame(201);
        $system = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['CV', 'Évaluations'], $system['dataCategories']);
        self::assertFalse($system['approvedAssessment']);

        $client->jsonRequest('PUT', '/api/ai-governance/systems/'.$copilotId, ['provider' => 'Autre']);
        self::assertResponseStatusCodeSame(422, 'The built-in copilot entry follows the AI configuration.');

        $client->request('GET', '/api/ai-governance/overview');
        $overview = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $overview['systems']);
        self::assertSame(2, $overview['missingApprovedAssessment']);

        // Évaluation d'impact : relecture obligatoire, approbation par une autre personne, immuabilité.
        $client->jsonRequest('POST', '/api/ai-governance/systems/'.$system['id'].'/impact-assessments', ['affectedGroups' => 'Candidats', 'benefits' => 'Gain de temps', 'harms' => '', 'mitigations' => '', 'residualRisk' => 'MEDIUM']);
        self::assertResponseStatusCodeSame(201);
        $assessment = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $client->request('POST', '/api/ai-governance/impact-assessments/'.$assessment['id'].'/submit');
        self::assertResponseStatusCodeSame(422, 'Harms and mitigations are required before review.');
        $client->jsonRequest('PUT', '/api/ai-governance/impact-assessments/'.$assessment['id'], ['harms' => 'Biais de sélection', 'mitigations' => 'Revue humaine et audit des biais']);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ai-governance/impact-assessments/'.$assessment['id'].'/submit');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/ai-governance/impact-assessments/'.$assessment['id'].'/approve');
        self::assertResponseStatusCodeSame(403, 'Approval is an administrator action.');
        $as($admin);
        $author2 = $manager->find(User::class, $author->getId());
        self::assertInstanceOf(User::class, $author2);
        $client->request('POST', '/api/ai-governance/impact-assessments/'.$assessment['id'].'/approve');
        self::assertResponseIsSuccessful();
        self::assertSame('APPROVED', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['status']);

        $as($author);
        $client->jsonRequest('PUT', '/api/ai-governance/impact-assessments/'.$assessment['id'], ['harms' => 'modifié']);
        self::assertResponseStatusCodeSame(422, 'An approved assessment is immutable.');
        $client->request('GET', '/api/ai-governance/overview');
        self::assertSame(1, json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['missingApprovedAssessment']);

        $as($foreign);
        $client->request('GET', '/api/ai-governance/systems/'.$system['id'].'/impact-assessments');
        self::assertResponseStatusCodeSame(404, 'Another tenant cannot read the assessments.');
        $client->request('GET', '/api/ai-governance/systems');
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));

        $as($admin);
        $client->request('GET', '/api/ai-governance/mandatory-documents');
        self::assertResponseIsSuccessful();
        $documents = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(10, count($documents['items']));
        self::assertSame(10, $documents['missing']);
    }

    public function testAuthorCannotApproveTheirOwnAssessment(): void
    {
        $organization = new Organization('Primary');
        $author = new User('author@example.test', 'Rita', 'Manager', $organization, [User::ROLE_ADMIN]);
        $system = new AiSystem($organization, $author, 'Assistant', 'Aide à la rédaction', 'Mistral', null, [], 'MINIMAL', 'ACTIVE', null, null);
        $assessment = new \App\Entity\AiImpactAssessment($system, $author);
        $assessment->update('Employés', 'Gain de temps', 'Erreurs factuelles', 'Relecture humaine', 'LOW', null);
        $assessment->submit();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('distinct');
        $assessment->approve($author);
    }
}
