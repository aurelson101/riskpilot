<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class IsmsDocumentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private JWTTokenManagerInterface $tokens;
    private User $admin;
    private User $viewer;
    private User $foreignUser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $organization = new Organization('Organisation documentaire');
        $foreignOrganization = new Organization('Organisation étrangère');
        $this->admin = new User('admin-doc@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $this->viewer = new User('viewer-doc@example.test', 'Victor', 'Lecteur', $organization, [User::ROLE_VIEWER]);
        $this->foreignUser = new User('foreign-doc@example.test', 'François', 'Étranger', $foreignOrganization, [User::ROLE_ADMIN]);
        foreach ([$organization, $foreignOrganization, $this->admin, $this->viewer, $this->foreignUser] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $this->tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
    }

    public function testDocumentLifecycleVersionsAclAndProtectedShare(): void
    {
        $this->authenticate($this->admin);
        $this->json('POST', '/api/isms-documents', ['title' => 'Politique de sécurité', 'category' => 'Politique', 'status' => 'DRAFT', 'classification' => 'CONFIDENTIAL', 'visibility' => 'RESTRICTED', 'content' => '# Version initiale', 'ownerId' => $this->admin->getId()]);
        self::assertResponseStatusCodeSame(201);
        $document = $this->payload();
        self::assertSame(1, $document['currentVersion']);

        $this->authenticate($this->admin);
        $this->client->request('GET', '/api/isms-documents');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload());

        $wordPath = tempnam(sys_get_temp_dir(), 'riskpilot-docx-');
        self::assertIsString($wordPath);
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($wordPath, \ZipArchive::OVERWRITE));
        $archive->addFromString('[Content_Types].xml', '<Types/>');
        $archive->addFromString('word/document.xml', '<document>Politique</document>');
        $archive->close();
        $uploaded = new UploadedFile($wordPath, 'politique.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $this->authenticate($this->admin);
        $this->client->request('POST', '/api/isms-documents/'.$document['id'].'/file', files: ['file' => $uploaded]);
        self::assertResponseIsSuccessful();
        self::assertSame('politique.docx', $this->payload()['file']['name']);
        self::assertSame(2, $this->payload()['currentVersion']);

        $this->authenticate($this->admin);
        $this->client->request('GET', '/api/isms-documents/'.$document['id'].'/file');
        self::assertResponseIsSuccessful();
        self::assertSame('attachment; filename=politique.docx', $this->client->getResponse()->headers->get('content-disposition'));

        $this->authenticate($this->admin);
        $this->json('POST', '/api/isms-documents/'.$document['id'].'/acl', ['userId' => $this->viewer->getId(), 'permission' => 'EDIT']);
        self::assertResponseIsSuccessful();

        $this->authenticate($this->viewer);
        $this->json('PUT', '/api/isms-documents/'.$document['id'], ['title' => 'Politique de sécurité', 'category' => 'Politique', 'status' => 'APPROVED', 'classification' => 'CONFIDENTIAL', 'visibility' => 'RESTRICTED', 'content' => '# Version approuvée', 'ownerId' => $this->admin->getId(), 'versionComment' => 'Approbation']);
        self::assertResponseStatusCodeSame(422);

        $this->authenticate($this->viewer);
        $this->json('PUT', '/api/isms-documents/'.$document['id'], ['title' => 'Politique de sécurité', 'category' => 'Politique', 'status' => 'IN_REVIEW', 'classification' => 'CONFIDENTIAL', 'visibility' => 'RESTRICTED', 'content' => '# Version approuvée', 'ownerId' => $this->admin->getId(), 'versionComment' => 'Soumission pour approbation']);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->payload()['currentVersion']);

        $this->authenticate($this->admin);
        $this->json('POST', '/api/isms-documents/'.$document['id'].'/approve', ['nextReviewAt' => (new \DateTimeImmutable('+1 year'))->format('Y-m-d')]);
        self::assertResponseIsSuccessful();
        self::assertSame('APPROVED', $this->payload()['status']);
        self::assertSame($this->admin->getId(), $this->payload()['approval']['approvedBy']['id']);

        $this->authenticate($this->admin);
        $this->json('POST', '/api/isms-documents/'.$document['id'].'/shares', ['password' => 'Secret123!', 'expiresAt' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM)]);
        self::assertResponseStatusCodeSame(201);
        $url = $this->payload()['url'];
        $token = basename((string) parse_url($url, PHP_URL_PATH));

        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->request('GET', '/api/public/documents/'.$token);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['passwordRequired']);
        $this->json('POST', '/api/public/documents/'.$token, ['password' => 'incorrect']);
        self::assertResponseStatusCodeSame(403);
        $this->json('POST', '/api/public/documents/'.$token, ['password' => 'Secret123!']);
        self::assertResponseIsSuccessful();
        self::assertSame('# Version approuvée', $this->payload()['document']['content']);

        $this->json('POST', '/api/public/documents/'.$token.'/file', ['password' => 'Secret123!']);
        self::assertResponseIsSuccessful();
        self::assertSame('attachment; filename=politique.docx', $this->client->getResponse()->headers->get('content-disposition'));
    }

    public function testTenantAndRestrictedDocumentIsolation(): void
    {
        $this->authenticate($this->admin);
        $this->json('POST', '/api/isms-documents', ['title' => 'Document privé', 'category' => 'Preuve', 'visibility' => 'RESTRICTED', 'content' => 'secret']);
        $id = $this->payload()['id'];

        $this->authenticate($this->viewer);
        $this->client->request('GET', '/api/isms-documents/'.$id);
        self::assertResponseStatusCodeSame(404);

        $this->authenticate($this->foreignUser);
        $this->client->request('GET', '/api/isms-documents/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    private function authenticate(User $user): void
    {
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$this->tokens->create($user));
    }

    /** @param array<string, mixed> $data */
    private function json(string $method, string $uri, array $data): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
