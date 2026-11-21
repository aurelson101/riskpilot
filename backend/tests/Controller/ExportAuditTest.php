<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExportAuditTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $manager;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->manager);
        $tool->dropSchema($this->manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($this->manager->getMetadataFactory()->getAllMetadata());
        $organization = new Organization('Exports');
        $this->admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $this->manager->persist($organization);
        $this->manager->persist($this->admin);
        $this->manager->flush();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->admin));
        self::getContainer()->get('limiter.data_export')->create('export-user-'.$this->admin->getId())->reset();
    }

    public function testEveryDownloadIsRecordedInTheSealedAuditChainButOrdinaryReadsAreNot(): void
    {
        $this->client->request('GET', '/api/risks');
        $this->client->request('GET', '/api/exports/risks.csv');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/exports/actions.xlsx');
        $this->client->request('GET', '/api/exports/workbook.xlsx');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('PK', (string) $this->client->getResponse()->getContent());

        $logs = $this->manager->getRepository(AuditLog::class)->findBy(['action' => 'EXPORT'], ['id' => 'ASC']);
        self::assertCount(3, $logs);
        self::assertSame('exports', $logs[0]->getEntityType());
        self::assertSame('/api/exports/risks.csv', $logs[0]->getNewValues()['path']);
        self::assertMatchesRegularExpression('/risks-.*\.csv|registre-risques/', (string) $logs[0]->getNewValues()['file']);
        self::assertGreaterThan(0, $logs[0]->getNewValues()['bytes']);
        self::assertSame($this->admin->getId(), $logs[0]->getActorId());
        $previous = null;
        foreach ($this->manager->getRepository(AuditLog::class)->findBy([], ['id' => 'ASC']) as $log) {
            self::assertTrue($log->verify($previous), 'Export events belong to the sealed chain.');
            $previous = $log->getEventHash();
        }
    }

    public function testExportRateLimitReturns429WithRetryAfter(): void
    {
        $factory = self::getContainer()->get('limiter.data_export');
        $limiter = $factory->create('export-user-'.$this->admin->getId());
        while ($limiter->consume()->isAccepted()) {
        }
        $this->client->request('GET', '/api/exports/risks.csv');
        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
        self::assertCount(0, $this->manager->getRepository(AuditLog::class)->findBy(['action' => 'EXPORT']), 'A refused export is not a download.');
    }

    public function testMultiSheetWorkbookAndAuditEvidenceExports(): void
    {
        $this->client->request('GET', '/api/exports/workbook.xlsx?assessment=999');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/exports/workbook.xlsx');
        $path = tempnam(sys_get_temp_dir(), 'wb-');
        file_put_contents($path, (string) $this->client->getResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();
        unlink($path);
        foreach (['Registre des risques', 'Plans d’action', 'Informations'] as $name) {
            self::assertStringContainsString($name, $workbook);
        }

        $this->client->request('GET', '/api/exports/risks.csv');
        $this->client->request('GET', '/api/audit-logs/export.csv');
        self::assertResponseIsSuccessful();
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Empreinte', $csv);
        self::assertStringContainsString('EXPORT', $csv);
        $this->client->request('GET', '/api/audit-logs/export.xlsx');
        self::assertResponseIsSuccessful();
        $path = tempnam(sys_get_temp_dir(), 'au-');
        file_put_contents($path, (string) $this->client->getResponse()->getContent());
        $zip = new \ZipArchive();
        $zip->open($path);
        $info = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        unlink($path);
        self::assertStringContainsString('chaîne valide', $info);
        $this->client->request('GET', '/api/audit-logs/export.pdf');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
    }

    public function testNonAdministratorsCannotDownloadTheAuditEvidence(): void
    {
        $viewer = new User('viewer@example.test', 'Victor', 'Viewer', $this->admin->getOrganization(), [User::ROLE_VIEWER]);
        $this->manager->persist($viewer);
        $this->manager->flush();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($viewer));
        $this->client->request('GET', '/api/audit-logs/export.csv');
        self::assertResponseStatusCodeSame(403);
    }
}
