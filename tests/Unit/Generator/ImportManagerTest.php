<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Unit\Generator;

use MaxBeckers\OpenApiGenerator\Generator\ImportManager;
use PHPUnit\Framework\TestCase;

class ImportManagerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Basic import registration
    // -------------------------------------------------------------------------

    public function testAddReturnsShortName(): void
    {
        $mgr = new ImportManager('App\\Dto');

        self::assertSame('Money', $mgr->add('App\\Model\\Money'));
    }

    public function testAddSameClassTwiceReturnsShortNameBothTimes(): void
    {
        $mgr = new ImportManager('App\\Dto');

        self::assertSame('Money', $mgr->add('App\\Model\\Money'));
        self::assertSame('Money', $mgr->add('App\\Model\\Money'));
    }

    public function testAddGlobalClassReturnsShortNameWithoutImport(): void
    {
        $mgr = new ImportManager('App\\Dto');

        self::assertSame('DateTime', $mgr->add('DateTime'));
    }

    // -------------------------------------------------------------------------
    // Same-namespace detection
    // -------------------------------------------------------------------------

    public function testSameNamespaceClassNotImported(): void
    {
        $mgr = new ImportManager('App\\Model');

        $result = $mgr->add('App\\Model\\Pet');

        self::assertSame('Pet', $result);
        self::assertEmpty($mgr->getUseStatements());
    }

    public function testDifferentNamespaceClassIsImported(): void
    {
        $mgr = new ImportManager('App\\Dto');

        $mgr->add('App\\Model\\Pet');

        self::assertContains('use App\\Model\\Pet;', $mgr->getUseStatements());
    }

    // -------------------------------------------------------------------------
    // Collision handling
    // -------------------------------------------------------------------------

    public function testCollisionReturnsFqcn(): void
    {
        $mgr = new ImportManager('App\\Dto');

        $first = $mgr->add('App\\Model\\Money');
        $second = $mgr->add('App\\Payment\\Money');

        self::assertSame('Money', $first, 'First registration gets the short name');
        self::assertSame('\\App\\Payment\\Money', $second, 'Colliding class gets FQCN with backslash');
    }

    public function testCollisionDropsBothFromUseStatements(): void
    {
        $mgr = new ImportManager('App\\Dto');

        $mgr->add('App\\Model\\Money');
        $mgr->add('App\\Payment\\Money');

        // When two classes share the same short name, neither appears in use statements.
        // The first is returned as the short name, the second as FQCN with backslash.
        // Both use-statement entries are suppressed to avoid ambiguity.
        $stmts = $mgr->getUseStatements();
        $moneyStmts = array_filter($stmts, static fn ($s) => str_contains($s, 'Money'));
        self::assertCount(0, $moneyStmts, 'Both Money classes are dropped from use statements on collision');
    }

    // -------------------------------------------------------------------------
    // addNullable
    // -------------------------------------------------------------------------

    public function testAddNullableReturnsPrefixedQuestionMark(): void
    {
        $mgr = new ImportManager('App\\Dto');

        self::assertSame('?Money', $mgr->addNullable('App\\Model\\Money'));
    }

    public function testAddNullableStillRegistersImport(): void
    {
        $mgr = new ImportManager('App\\Dto');

        $mgr->addNullable('App\\Model\\Money');

        self::assertContains('use App\\Model\\Money;', $mgr->getUseStatements());
    }

    // -------------------------------------------------------------------------
    // addUnion
    // -------------------------------------------------------------------------

    public function testAddUnionReturnsBarSeparatedShortNames(): void
    {
        $mgr = new ImportManager('App\\Dto');

        $result = $mgr->addUnion(['App\\Model\\Dog', 'App\\Model\\Cat']);

        self::assertSame('Dog|Cat', $result);
    }

    public function testAddUnionRegistersAllImports(): void
    {
        $mgr = new ImportManager('App\\Dto');

        $mgr->addUnion(['App\\Model\\Dog', 'App\\Model\\Cat']);

        $stmts = $mgr->getUseStatements();
        self::assertContains('use App\\Model\\Dog;', $stmts);
        self::assertContains('use App\\Model\\Cat;', $stmts);
    }

    // -------------------------------------------------------------------------
    // getUseStatements ordering
    // -------------------------------------------------------------------------

    public function testUseStatementsAreSortedAlphabetically(): void
    {
        $mgr = new ImportManager('App\\Dto');

        $mgr->add('App\\Z\\Zebra');
        $mgr->add('App\\A\\Alpha');
        $mgr->add('App\\M\\Middle');

        $stmts = $mgr->getUseStatements();

        self::assertSame([
            'use App\\A\\Alpha;',
            'use App\\M\\Middle;',
            'use App\\Z\\Zebra;',
        ], $stmts);
    }

    public function testUseStatementsEmptyWhenNothingAdded(): void
    {
        $mgr = new ImportManager('App\\Dto');

        self::assertSame([], $mgr->getUseStatements());
    }

    public function testUseStatementsEmptyForSameNamespaceImports(): void
    {
        $mgr = new ImportManager('App\\Model');

        $mgr->add('App\\Model\\Pet');
        $mgr->add('App\\Model\\Tag');

        self::assertSame([], $mgr->getUseStatements());
    }
}
