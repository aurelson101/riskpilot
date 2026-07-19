<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\IsmsDocument;
use App\Entity\IsmsDocumentAcl;
use App\Entity\Organization;
use App\Entity\User;
use App\Security\IsmsDocumentAccess;
use PHPUnit\Framework\TestCase;

final class IsmsDocumentTest extends TestCase
{
    public function testRevisionAndAclPermissions(): void
    {
        $organization = new Organization('Test');
        $owner = new User('owner@example.test', 'Olivia', 'Owner', $organization);
        $editor = new User('editor@example.test', 'Edgar', 'Editor', $organization);
        $document = new IsmsDocument($organization, $owner, 'Politique', 'Politique', 'v1');
        $document->initializeVersion($owner);
        $document->revise('v2', $editor, 'Mise à jour');
        $document->getAclEntries()->add(new IsmsDocumentAcl($document, $editor, 'EDIT'));
        $access = new IsmsDocumentAccess();

        self::assertSame(2, $document->getCurrentVersion());
        self::assertCount(2, $document->getVersions());
        self::assertTrue($access->canEdit($document, $editor));
        self::assertFalse($access->canManage($document, $editor));
        self::assertTrue($access->canManage($document, $owner));
    }

    public function testEachVersionKeepsItsBinaryReference(): void
    {
        $organization = new Organization('Test');
        $owner = new User('owner@example.test', 'Olivia', 'Owner', $organization);
        $document = new IsmsDocument($organization, $owner, 'Politique', 'Politique', 'v1');
        $document->attachFile('politique-v1.docx', 'binary-v1.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 120, str_repeat('a', 64));
        $document->initializeVersion($owner);
        $document->attachFile('politique-v2.docx', 'binary-v2.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 240, str_repeat('b', 64));
        $document->recordRevision($owner, 'Nouvelle preuve');

        $versions = $document->getVersions()->toArray();
        self::assertSame('binary-v1.docx', $versions[0]->getFileStorageName());
        self::assertSame(120, $versions[0]->getFileSize());
        self::assertSame('binary-v2.docx', $versions[1]->getFileStorageName());
        self::assertTrue($versions[1]->hasFile());
    }
}
