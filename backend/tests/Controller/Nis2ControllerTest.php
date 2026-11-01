<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\SecurityIncident;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class Nis2ControllerTest extends WebTestCase
{
    public function testEntityClassificationIncidentQualificationDeadlinesReportAndReminders(): void
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
        $riskManager = new User('rm@example.test', 'Rita', 'Manager', $organization, [User::ROLE_RISK_MANAGER]);
        $viewer = new User('viewer@example.test', 'Victor', 'Viewer', $organization, [User::ROLE_VIEWER]);
        $foreignAdmin = new User('foreign@example.test', 'Fay', 'Admin', $other, [User::ROLE_ADMIN]);
        foreach ([$organization, $other, $admin, $riskManager, $viewer, $foreignAdmin] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $as = static fn (User $user) => $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($user));

        $as($viewer);
        $client->jsonRequest('PUT', '/api/nis2/entity', ['type' => 'ESSENTIAL']);
        self::assertResponseStatusCodeSame(403, 'Only an administrator classifies the entity.');

        $as($admin);
        $client->jsonRequest('PUT', '/api/nis2/entity', ['type' => 'DOMINANT']);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('PUT', '/api/nis2/entity', ['type' => 'ESSENTIAL', 'sector' => 'Transport', 'authority' => 'ANSSI']);
        self::assertResponseIsSuccessful();

        $as($riskManager);
        $detected = (new \DateTimeImmutable('-30 hours'))->format(DATE_ATOM);
        $client->jsonRequest('POST', '/api/resilience/incidents', ['title' => 'Rançongiciel', 'description' => 'Chiffrement de serveurs.', 'severity' => 'CRITICAL', 'ownerId' => $riskManager->getId(), 'detectedAt' => $detected, 'significant' => true, 'category' => 'SECURITY']);
        self::assertResponseStatusCodeSame(201);
        $incident = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($incident['significant']);
        self::assertTrue($incident['regulatoryNotificationRequired'], 'A significant incident always requires the regulatory notification.');

        $client->request('GET', '/api/nis2/overview');
        self::assertResponseIsSuccessful();
        $overview = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ESSENTIAL', $overview['entity']['type']);
        self::assertSame(1, $overview['summary']['significantOpen']);
        self::assertSame(1, $overview['summary']['overdue'], '30 hours after detection the 24 h early warning is overdue.');
        self::assertSame('OVERDUE', $overview['incidents'][0]['steps'][0]['status']);
        self::assertSame('PENDING', $overview['incidents'][0]['steps'][1]['status']);

        $client->request('GET', '/api/nis2/incidents/'.$incident['id'].'/report');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/markdown', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('Rançongiciel', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('ANSSI', (string) $client->getResponse()->getContent());

        $as($foreignAdmin);
        $client->request('GET', '/api/nis2/incidents/'.$incident['id'].'/report');
        self::assertResponseStatusCodeSame(404, 'Another tenant cannot read the report.');
        $client->request('GET', '/api/nis2/overview');
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['incidents']);

        // Rappels : l'alerte précoce en retard est notifiée une seule fois par jour.
        $tester = new CommandTester((new Application(self::$kernel))->find('app:nis2:remind-deadlines'));
        $tester->execute([]);
        $tester->execute([]);
        $notifications = $manager->getRepository(Notification::class)->findBy(['type' => 'NIS2_DEADLINE']);
        self::assertCount(1, $notifications, 'The reminder is idempotent for the same step, status and day.');

        $as($riskManager);
        $client->jsonRequest('PUT', '/api/resilience/incidents/'.$incident['id'], ['title' => 'Rançongiciel', 'description' => 'Chiffrement de serveurs.', 'severity' => 'CRITICAL', 'status' => 'CLOSED', 'ownerId' => $riskManager->getId()]);
        self::assertResponseStatusCodeSame(422, 'A significant incident cannot be closed without its three reports.');

        $early = (new \DateTimeImmutable('-20 hours'))->format(DATE_ATOM);
        $client->jsonRequest('PUT', '/api/resilience/incidents/'.$incident['id'], ['title' => 'Rançongiciel', 'description' => 'Chiffrement de serveurs.', 'severity' => 'CRITICAL', 'status' => 'CONTAINED', 'ownerId' => $riskManager->getId(), 'significant' => true, 'earlyWarningAt' => $early, 'finalReportAt' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM)]);
        self::assertResponseStatusCodeSame(422, 'A final report requires the notification first.');

        $client->jsonRequest('PUT', '/api/resilience/incidents/'.$incident['id'], ['title' => 'Rançongiciel', 'description' => 'Chiffrement de serveurs.', 'severity' => 'CRITICAL', 'status' => 'CONTAINED', 'ownerId' => $riskManager->getId(), 'significant' => true, 'earlyWarningAt' => $early, 'regulatoryNotificationRequired' => true, 'notifiedAt' => (new \DateTimeImmutable('-10 hours'))->format(DATE_ATOM)]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/nis2/overview');
        $steps = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['incidents'][0]['steps'];
        self::assertSame(['DONE', 'DONE', 'PENDING'], array_column($steps, 'status'));
        self::assertFalse($steps[1]['late']);

        $stored = $manager->getRepository(SecurityIncident::class)->find($incident['id']);
        self::assertContains('Alerte précoce déposée', array_column($stored->getTimeline(), 'event'));
    }
}
