<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Framework;
use App\Entity\Organization;
use App\Entity\Requirement;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FrameworkImportControllerTest extends WebTestCase
{
    public function testPreviewConfirmationHierarchyAndAccess(): void
    {
        $client = self::createClient(); $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($em); $schema->dropSchema($metadata); $schema->createSchema($metadata);
        $org = new Organization('CSV import test');
        $admin = new User('import-admin@example.test', 'Import', 'Admin', $org, [User::ROLE_ADMIN]);
        $reader = new User('import-reader@example.test', 'Import', 'Reader', $org, ['ROLE_VIEWER']);
        foreach ([$org, $admin, $reader] as $entity) $em->persist($entity);
        $em->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $body = ['name' => 'Original local test catalogue', 'version' => '1', 'csv' => "reference;title;category;description;parentReference;status\r\nA;Root;Security;;;ACTIVE\r\nB;Child;Security;;A;ARCHIVED\r\n"];
        $client->jsonRequest('POST', '/api/frameworks/import/preview', $body);
        self::assertResponseStatusCodeSame(401);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($reader));
        foreach (['preview', 'confirm'] as $action) {
            $client->jsonRequest('POST', '/api/frameworks/import/'.$action, $body);
            self::assertResponseStatusCodeSame(403);
        }
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($admin));
        $client->jsonRequest('POST', '/api/frameworks/import/preview', $body);
        self::assertResponseIsSuccessful();
        $preview = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $preview['count']);
        self::assertSame(0, $em->getRepository(Framework::class)->count([]));
        $client->jsonRequest('POST', '/api/frameworks/import/confirm', $body);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', '/api/frameworks/import/confirm', [...$body, 'version' => '2', 'checksum' => $preview['checksum']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $em->getRepository(Framework::class)->count([]));
        $client->jsonRequest('POST', '/api/frameworks/import/confirm', [...$body, 'checksum' => $preview['checksum']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $em->getRepository(Framework::class)->count([]));
        self::assertSame(2, $em->getRepository(Requirement::class)->count([]));
        $child = $em->getRepository(Requirement::class)->findOneBy(['reference' => 'B']);
        self::assertSame('A', $child->getParentRequirement()->getReference());
        self::assertSame('ARCHIVED', $child->getStatus());
        $client->request('GET', '/api/frameworks/'.$child->getFramework()->getId().'/export.csv');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $export = $client->getResponse()->getContent();
        $client->jsonRequest('POST', '/api/frameworks/import/preview', [...$body, 'version' => '2', 'csv' => $export]);
        self::assertResponseIsSuccessful();
        $exportPreview = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $exportPreview['count']);
        self::assertSame('ARCHIVED', $exportPreview['requirements'][1]['status']);
        $client->request('GET', '/api/frameworks/999999/export.csv');
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', '/api/frameworks/import/confirm', [...$body, 'checksum' => $preview['checksum']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $em->getRepository(Framework::class)->count([]));
        $client->jsonRequest('POST', '/api/frameworks/import/preview', [...$body, 'version' => '3', 'csv' => "reference,title,category,description,parentReference\nA,Root,Security,,A\n"]);
        self::assertResponseStatusCodeSame(422);
        $parent = $child->getParentRequirement();
        $client->jsonRequest('PUT', '/api/requirements/'.$parent->getId(), ['reference' => 'A', 'title' => 'Root', 'category' => 'Security', 'parentRequirementId' => $child->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($parent->getParentRequirement());
    }
}
