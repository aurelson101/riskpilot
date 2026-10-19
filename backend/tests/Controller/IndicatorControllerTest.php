<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IndicatorControllerTest extends WebTestCase
{
    public function testIndicatorInputsAreValidatedAndIdempotent(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $organization = new Organization('Indicators');
        $user = new User('indicators@example.test', 'Indicator', 'Manager', $organization, [User::ROLE_RISK_MANAGER]);
        $manager->persist($organization);
        $manager->persist($user);
        $manager->flush();
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        $valid = ['code' => 'KRI-001', 'name' => 'Exposition critique', 'kind' => 'KRI', 'unit' => '%', 'frequency' => 'MONTHLY', 'target' => null];
        $manyThresholds = [];
        for ($index = 0; $index < 101; ++$index) {
            $manyThresholds['threshold-'.$index] = $index;
        }
        foreach ([
            ['code' => []], ['name' => []], ['kind' => []], ['unit' => []], ['frequency' => []],
            ['formula' => []], ['source' => []], ['active' => 'true'], ['thresholds' => 'invalid'], ['target' => []],
            ['code' => str_repeat('c', 81)], ['name' => str_repeat('n', 181)], ['unit' => str_repeat('u', 41)],
            ['formula' => str_repeat('f', 10001)], ['source' => str_repeat('s', 256)], ['target' => '100000000000000'],
            ['thresholds' => ['' => 1]], ['thresholds' => ['warning' => 'high']], ['thresholds' => $manyThresholds],
        ] as $override) {
            $client->jsonRequest('POST', '/api/v1/indicators', [...$valid, ...$override]);
            self::assertResponseStatusCodeSame(422);
        }

        $client->jsonRequest('POST', '/api/v1/indicators', [...$valid, 'thresholds' => ['warning' => 70, 'critical' => 90], 'active' => true]);
        self::assertResponseStatusCodeSame(201);
        $indicator = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['warning' => 70, 'critical' => 90], $indicator['thresholds']);

        $uri = '/api/v1/indicators/'.$indicator['id'].'/values';
        $measurement = ['value' => 75.5, 'measuredAt' => '2026-10-18T12:00:00+00:00', 'idempotencyKey' => 'measure-001'];
        foreach ([
            ['value' => []], ['value' => '100000000000000'], ['measuredAt' => []], ['idempotencyKey' => []],
            ['idempotencyKey' => str_repeat('i', 121)], ['period' => []], ['comment' => []], ['evidence' => []], ['source' => []],
            ['period' => str_repeat('p', 81)], ['comment' => str_repeat('c', 10001)],
            ['evidence' => str_repeat('e', 2049)], ['source' => str_repeat('s', 256)], ['evidence' => 'not-a-url'],
        ] as $override) {
            $client->jsonRequest('POST', $uri, [...$measurement, ...$override]);
            self::assertResponseStatusCodeSame(422);
        }

        $client->jsonRequest('POST', $uri, [...$measurement, 'period' => '2026-10', 'comment' => 'Mesure validée', 'evidence' => 'https://example.test/evidence', 'source' => 'SIEM']);
        self::assertResponseStatusCodeSame(201);
        $first = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $client->jsonRequest('POST', $uri, $measurement);
        self::assertResponseIsSuccessful();
        $duplicate = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($first['id'], $duplicate['id']);
    }
}
