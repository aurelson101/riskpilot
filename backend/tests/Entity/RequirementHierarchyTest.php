<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Framework;
use App\Entity\Requirement;
use PHPUnit\Framework\TestCase;

final class RequirementHierarchyTest extends TestCase
{
    public function testValidHierarchyAndRemovingParent(): void
    {
        $framework = new Framework('Local framework', '1');
        $root = new Requirement($framework, 'A', 'Root', 'Security');
        $child = new Requirement($framework, 'B', 'Child', 'Security');
        $leaf = new Requirement($framework, 'C', 'Leaf', 'Security');
        $child->setParentRequirement($root);
        $leaf->setParentRequirement($child);
        self::assertSame($child, $leaf->getParentRequirement());
        self::assertSame($root, $child->getParentRequirement());
        $leaf->setParentRequirement(null);
        self::assertNull($leaf->getParentRequirement());
    }

    public function testSelfParentIsRejected(): void
    {
        $item = new Requirement(new Framework('Local', '1'), 'A', 'Root', 'Security');
        $this->expectException(\InvalidArgumentException::class);
        $item->setParentRequirement($item);
    }

    public function testDescendantParentIsRejectedWithoutChangingPreviousParent(): void
    {
        $framework = new Framework('Local', '1');
        $root = new Requirement($framework, 'A', 'Root', 'Security');
        $child = new Requirement($framework, 'B', 'Child', 'Security');
        $leaf = new Requirement($framework, 'C', 'Leaf', 'Security');
        $child->setParentRequirement($root);
        $leaf->setParentRequirement($child);
        try {
            $root->setParentRequirement($leaf);
            self::fail('Cycle accepted.');
        } catch (\InvalidArgumentException) {
            self::assertNull($root->getParentRequirement());
            self::assertSame($root, $child->getParentRequirement());
        }
    }

    public function testAnotherFrameworkIsRejected(): void
    {
        $item = new Requirement(new Framework('Local', '1'), 'A', 'Root', 'Security');
        $parent = new Requirement(new Framework('Other', '1'), 'B', 'Parent', 'Security');
        $this->expectException(\InvalidArgumentException::class);
        $item->setParentRequirement($parent);
    }

    public function testExistingAncestorCycleIsRejected(): void
    {
        $framework = new Framework('Local', '1');
        $item = new Requirement($framework, 'A', 'Root', 'Security');
        $parent = new Requirement($framework, 'B', 'Parent', 'Security');
        // Simulate historical corruption without invoking the protected setter.
        (new \ReflectionProperty(Requirement::class, 'parentRequirement'))->setValue($parent, $parent);
        $this->expectException(\InvalidArgumentException::class);
        $item->setParentRequirement($parent);
    }

    public function testDifferentObjectsWithSamePersistedIdentityCannotCreateCycle(): void
    {
        $framework = new Framework('Local', '1');
        $item = new Requirement($framework, 'A', 'Root', 'Security');
        $alias = new Requirement($framework, 'A', 'Root', 'Security');
        $id = new \ReflectionProperty(Requirement::class, 'id');
        $id->setValue($item, 12);
        $id->setValue($alias, 12);
        $this->expectException(\InvalidArgumentException::class);
        $item->setParentRequirement($alias);
    }
}
