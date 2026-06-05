<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Unit\Generator;

use MaxBeckers\OpenApiGenerator\Generator\OperationFilter;
use MaxBeckers\OpenApiGenerator\Loader\OpenApiLoader;
use PHPUnit\Framework\TestCase;

class OperationFilterTest extends TestCase
{
    private OperationFilter $filter;
    private OpenApiLoader $loader;

    protected function setUp(): void
    {
        $this->filter = new OperationFilter();
        $this->loader = new OpenApiLoader();
    }

    // -------------------------------------------------------------------------
    // Tag filtering
    // -------------------------------------------------------------------------

    public function testIncludeTagsKeepsMatchingOperations(): void
    {
        $spec = $this->loadFixture();
        $filtered = $this->filter->filter($spec, includeTags: ['orders']);

        $ops = $this->collectOperationIds($filtered);

        self::assertContains('createOrder', $ops);
        self::assertNotContains('listCatalogItems', $ops);
    }

    public function testExcludeTagsRemovesMatchingOperations(): void
    {
        $spec = $this->loadFixture();
        $filtered = $this->filter->filter($spec, excludeTags: ['catalog']);

        $ops = $this->collectOperationIds($filtered);

        self::assertContains('createOrder', $ops);
        self::assertNotContains('listCatalogItems', $ops);
    }

    public function testEmptyTagFiltersKeepAll(): void
    {
        $spec = $this->loadFixture();
        $filtered = $this->filter->filter($spec);

        $ops = $this->collectOperationIds($filtered);

        self::assertContains('createOrder', $ops);
        self::assertContains('listCatalogItems', $ops);
    }

    // -------------------------------------------------------------------------
    // OperationId filtering
    // -------------------------------------------------------------------------

    public function testIncludeOperationIdsKeepsOnlyListed(): void
    {
        $spec = $this->loadFixture();
        $filtered = $this->filter->filter($spec, includeOperationIds: ['createOrder']);

        $ops = $this->collectOperationIds($filtered);

        self::assertContains('createOrder', $ops);
        self::assertNotContains('listCatalogItems', $ops);
    }

    public function testExcludeOperationIdsRemovesListed(): void
    {
        $spec = $this->loadFixture();
        $filtered = $this->filter->filter($spec, excludeOperationIds: ['createOrder']);

        $ops = $this->collectOperationIds($filtered);

        self::assertNotContains('createOrder', $ops);
        self::assertContains('listCatalogItems', $ops);
    }

    // -------------------------------------------------------------------------
    // Schema reachability cascade
    // -------------------------------------------------------------------------

    public function testSchemasPrunedWhenOnlyReachableFromExcludedOps(): void
    {
        $spec = $this->loadFixture();

        // Filter to orders tag only — catalog-only schemas should be pruned.
        $filtered = $this->filter->filter($spec, includeTags: ['orders']);

        // Schemas used by order operations must survive.
        self::assertArrayHasKey('Money', $filtered->components->schemas);
        self::assertArrayHasKey('Order', $filtered->components->schemas);
        self::assertArrayHasKey('OrderStatus', $filtered->components->schemas);
        self::assertArrayHasKey('CreateOrderRequest', $filtered->components->schemas);
        self::assertArrayHasKey('ApiError', $filtered->components->schemas);
        self::assertArrayHasKey('Address', $filtered->components->schemas);

        // CatalogItem is ONLY referenced by the catalog operation → must be pruned.
        self::assertArrayNotHasKey('CatalogItem', $filtered->components->schemas);
    }

    public function testOrphanSchemasAlwaysPruned(): void
    {
        $spec = $this->loadFixture();
        // Orphan schemas (never referenced by any operation) are always pruned.
        $filtered = $this->filter->filter($spec, includeTags: ['orders', 'catalog']);

        self::assertArrayNotHasKey('OrphanSchema', $filtered->components->schemas);
    }

    public function testNoFilterStillPrunesOrphans(): void
    {
        $spec = $this->loadFixture();
        // Even when filter() is called with no args, orphan schemas are pruned
        // because they're unreachable from any operation.
        $filtered = $this->filter->filter($spec);

        self::assertArrayNotHasKey('OrphanSchema', $filtered->components->schemas);
    }

    public function testNoFilterPreservesAllReferencedSchemas(): void
    {
        $spec = $this->loadFixture();
        $filtered = $this->filter->filter($spec);

        // All schemas referenced by operations survive.
        self::assertArrayHasKey('Money', $filtered->components->schemas);
        self::assertArrayHasKey('Order', $filtered->components->schemas);
        self::assertArrayHasKey('CatalogItem', $filtered->components->schemas);
    }

    public function testTransitiveRefsArePreserved(): void
    {
        $spec = $this->loadFixture();
        // Order references Money transitively (Order → total → Money).
        $filtered = $this->filter->filter($spec, includeTags: ['orders']);

        self::assertArrayHasKey('Money', $filtered->components->schemas);
    }

    // -------------------------------------------------------------------------
    // Path filtering
    // -------------------------------------------------------------------------

    public function testIncludePathsKeepsMatchingPaths(): void
    {
        $spec = $this->loadFixture();
        $filtered = $this->filter->filter($spec, includePaths: ['/orders*']);

        self::assertArrayHasKey('/orders', $filtered->paths);
        self::assertArrayNotHasKey('/catalog/items', $filtered->paths);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return string[] */
    private function collectOperationIds(\MaxBeckers\OpenApiGenerator\Spec\OpenApiSpec $spec): array
    {
        $ids = [];
        foreach ($spec->paths as $pathItem) {
            foreach ($pathItem->getOperations() as $op) {
                if ($op->operationId !== null) {
                    $ids[] = $op->operationId;
                }
            }
        }

        return $ids;
    }

    private function loadFixture(): \MaxBeckers\OpenApiGenerator\Spec\OpenApiSpec
    {
        return $this->loader->loadFile(__DIR__ . '/../../Fixtures/feature-test.yaml');
    }
}
