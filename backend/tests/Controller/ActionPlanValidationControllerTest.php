<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ActionPlanValidationControllerTest extends WebTestCase
{
    public function testInvalidCollectionsReturnValidationErrorsAndValidActionsStillSave(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $org = new Organization('Actions');
        $user = new User('actions@example.test', 'Action', 'Owner', $org, [User::ROLE_ADMIN]);
        $em->persist($org);
        $em->persist($user);
        $em->flush();
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$jwt);
        $valid = ['title' => 'Audit remediation', 'ownerId' => $user->getId(), 'origin' => 'AUDIT', 'startDate' => '2030-01-01', 'dueDate' => '2030-02-01'];
        foreach ([
            ['estimatedCost' => 10000000000], ['actualCost' => 10000000000], ['estimatedEffortDays' => 1000000],
            ['evidence' => array_fill(0, 101, 'https://example.test/evidence')],
            ['evidence' => ['https://example.test/'.str_repeat('a', 2048)]],
            ['frameworkIds' => [-1]], ['requirementIds' => [-1]],
            ['nonConformities' => [[]]], ['nonConformities' => array_fill(0, 101, ['type' => 'AUDIT_FINDING', 'id' => 1])],
            ['customFields' => ['nested' => ['array']]], ['completionDate' => '2029-12-31'],
        ] as $override) {
            $client->jsonRequest('POST', '/api/actions', [...$valid, ...$override]);
            self::assertResponseStatusCodeSame(422);
        }
        $client->jsonRequest('POST', '/api/actions', $valid);
        self::assertResponseStatusCodeSame(201);
    }
}
