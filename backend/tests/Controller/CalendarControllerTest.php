<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ActionPlan;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CalendarControllerTest extends WebTestCase
{
    public function testLocalizedPrivateCalendarAndRevocation(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $org = new Organization('Calendar');
        $otherOrg = new Organization('Foreign');
        $user = new User('calendar@example.test', 'Cal', 'Owner', $org, [User::ROLE_ADMIN]);
        $other = new User('other@example.test', 'Other', 'Owner', $org);
        $user->setLocale('en');
        $action = (new ActionPlan("Audit\x01 ".str_repeat('é', 90), $org, null, $user, new \DateTimeImmutable('2030-01-02')))->setStatus('COMPLETED')->setPriority('HIGH');
        $cancelled = (new ActionPlan('CANCELLED-HIDDEN', $org, null, $user, new \DateTimeImmutable('2030-01-02')))->setStatus('CANCELLED');
        $otherAction = new ActionPlan('OTHER-OWNER-HIDDEN', $org, null, $other, new \DateTimeImmutable('2030-01-02'));
        $foreignAction = new ActionPlan('FOREIGN-HIDDEN', $otherOrg, null, $user, new \DateTimeImmutable('2030-01-02'));
        foreach ([$org, $otherOrg, $user, $other, $action, $cancelled, $otherAction, $foreignAction] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$jwt);
        $client->request('POST', '/api/me/calendar');
        self::assertResponseStatusCodeSame(201);
        $url = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['url'];
        $path = parse_url($url, PHP_URL_PATH);
        $client->setServerParameter('HTTP_AUTHORIZATION', '');
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $raw = $client->getResponse()->getContent();
        $ical = str_replace("\r\n ", '', $raw);
        self::assertSame(1, substr_count($ical, 'BEGIN:VEVENT'));
        self::assertStringContainsString('X-WR-CALNAME:RiskPilot - My actions', $ical);
        self::assertStringContainsString('Priority: High\\nStatus: Completed\\nRisk: No linked risk', $ical);
        self::assertStringContainsString('CREATED:', $ical);
        self::assertStringContainsString('LAST-MODIFIED:', $ical);
        self::assertMatchesRegularExpression('/UID:action-\d+@[a-f0-9]{24}\.riskpilot/', $ical);
        self::assertStringContainsString('STATUS:CONFIRMED', $ical);
        self::assertStringNotContainsString('STATUS:COMPLETED', $ical);
        self::assertStringContainsString('DTEND;VALUE=DATE:20300103', $ical);
        self::assertStringNotContainsString("\x01", $ical);
        self::assertStringContainsString(str_repeat('é', 90), $ical);
        foreach (explode("\r\n", $raw) as $line) {
            self::assertLessThanOrEqual(75, strlen($line));
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $user->getId());
        $user->setLocale('fr');
        $em->flush();
        $client->request('GET', $path);
        $ical = str_replace("\r\n ", '', $client->getResponse()->getContent());
        self::assertStringContainsString('Mes actions', $ical);
        self::assertStringContainsString('Priorité : Haute\\nStatut : Terminée', $ical);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$jwt);
        $client->request('DELETE', '/api/me/calendar');
        self::assertResponseStatusCodeSame(204);
        $client->setServerParameter('HTTP_AUTHORIZATION', '');
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
    }
}
