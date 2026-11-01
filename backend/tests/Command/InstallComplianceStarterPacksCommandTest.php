<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Framework;
use App\Entity\Requirement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallComplianceStarterPacksCommandTest extends KernelTestCase
{
    public function testItInstallsNewPacksAndCompletesAnInstalledFrameworkWithoutDuplicates(): void
    {
        $kernel = self::bootKernel();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        // État d'une installation antérieure : NIS2 avec ses dix exigences d'origine seulement.
        $legacy = new Framework('NIS2', '2022/2555');
        $manager->persist($legacy);
        foreach (['ART-20', 'ART-21.2-A', 'ART-21.2-B', 'ART-21.2-C', 'ART-21.2-D', 'ART-21.2-E', 'ART-21.2-F', 'ART-21.2-G', 'ART-21.2-H-J', 'ART-23'] as $reference) {
            $manager->persist(new Requirement($legacy, $reference, 'Exigence d’origine', 'Historique'));
        }
        $manager->flush();
        $legacyId = $legacy->getId();
        $manager->clear();

        $command = (new Application($kernel))->find('app:compliance:install-starter-packs');
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));

        $repository = $manager->getRepository(Framework::class);
        $nis2 = $repository->find($legacyId);
        self::assertInstanceOf(Framework::class, $nis2);
        self::assertSame(18, $manager->getRepository(Requirement::class)->count(['framework' => $nis2]));
        $iso = $repository->findOneBy(['name' => 'ISO/IEC 27001', 'version' => '2022']);
        self::assertSame(126, $manager->getRepository(Requirement::class)->count(['framework' => $iso]));
        self::assertInstanceOf(Framework::class, $repository->findOneBy(['name' => 'ISO/IEC 42001', 'version' => '2023']));
        self::assertInstanceOf(Framework::class, $repository->findOneBy(['name' => 'TISAX / VDA ISA', 'version' => '6']));
        $oldRequirement = $manager->getRepository(Requirement::class)->findOneBy(['framework' => $nis2, 'reference' => 'ART-20']);
        self::assertSame('Exigence d’origine', $oldRequirement?->getTitle(), 'Existing requirements are never rewritten.');
        $child = $manager->getRepository(Requirement::class)->findOneBy(['framework' => $nis2, 'reference' => 'ART-23.4-A']);
        self::assertSame('ART-23', $child?->getParentRequirement()?->getReference());

        $total = $manager->getRepository(Requirement::class)->count([]);
        self::assertSame(0, $tester->execute([]));
        self::assertSame($total, $manager->getRepository(Requirement::class)->count([]), 'A second run must not create duplicates.');
        self::assertStringContainsString('déjà à jour', $tester->getDisplay());
    }
}
