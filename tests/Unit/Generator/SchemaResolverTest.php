<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Unit\Generator;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Generator\NamingStrategy;
use MaxBeckers\OpenApiGenerator\Generator\SchemaKind;
use MaxBeckers\OpenApiGenerator\Generator\SchemaResolver;
use MaxBeckers\OpenApiGenerator\Spec\Components;
use MaxBeckers\OpenApiGenerator\Spec\Discriminator;
use MaxBeckers\OpenApiGenerator\Spec\Schema;
use PHPUnit\Framework\TestCase;

class SchemaResolverTest extends TestCase
{
    private SchemaResolver $resolver;

    protected function setUp(): void
    {
        $config = new GeneratorConfig();
        $naming = new NamingStrategy($config);
        $this->resolver = new SchemaResolver($naming);
    }

    // -------------------------------------------------------------------------
    // Basic classification
    // -------------------------------------------------------------------------

    public function testScalarSchemaClassifiedAsAlias(): void
    {
        $components = new Components();
        $components->schemas['UserId'] = $this->makeSchema(type: 'string');

        $kinds = $this->resolver->resolve($components);

        self::assertSame(SchemaKind::Alias, $kinds['UserId']);
    }

    public function testTopLevelRefClassifiedAsAlias(): void
    {
        $components = new Components();
        $schema = new Schema();
        $schema->ref = '#/components/schemas/Address';
        $components->schemas['HomeAddress'] = $schema;
        $components->schemas['Address'] = $this->makeObjectSchema(['street']);

        $kinds = $this->resolver->resolve($components);

        self::assertSame(SchemaKind::Alias, $kinds['HomeAddress']);
    }

    public function testObjectSchemaClassifiedAsObject(): void
    {
        $components = new Components();
        $components->schemas['Address'] = $this->makeObjectSchema(['street', 'city']);

        $kinds = $this->resolver->resolve($components);

        self::assertSame(SchemaKind::Object, $kinds['Address']);
    }

    public function testEnumSchemaClassifiedAsEnum(): void
    {
        $components = new Components();
        $components->schemas['Color'] = $this->makeEnumSchema(['red', 'green', 'blue']);

        $kinds = $this->resolver->resolve($components);

        self::assertSame(SchemaKind::Enum, $kinds['Color']);
    }

    public function testIntegerEnumClassifiedAsEnum(): void
    {
        $components = new Components();
        $components->schemas['Priority'] = $this->makeEnumSchema([1, 2, 3], 'integer');

        $kinds = $this->resolver->resolve($components);

        self::assertSame(SchemaKind::Enum, $kinds['Priority']);
    }

    public function testArraySchemaClassifiedAsAlias(): void
    {
        $components = new Components();
        $items = new Schema();
        $items->ref = '#/components/schemas/Pet';
        $schema = new Schema();
        $schema->type = 'array';
        $schema->items = $items;
        $components->schemas['Pet'] = $this->makeObjectSchema(['id', 'name']);
        $components->schemas['Pets'] = $schema;

        $kinds = $this->resolver->resolve($components);

        self::assertSame(SchemaKind::Alias, $kinds['Pets']);
    }

    // -------------------------------------------------------------------------
    // allOf (extend pattern)
    // -------------------------------------------------------------------------

    public function testAllOfClassifiedAsObject(): void
    {
        $components = new Components();
        $components->schemas['Base'] = $this->makeObjectSchema(['id']);
        $components->schemas['Extended'] = $this->makeAllOfSchema(
            refName: 'Base',
            extraProps: ['name'],
        );

        $kinds = $this->resolver->resolve($components);

        self::assertSame(SchemaKind::Object, $kinds['Extended']);
    }

    // -------------------------------------------------------------------------
    // oneOf with discriminator → Interface
    // -------------------------------------------------------------------------

    public function testOneOfWithDiscriminatorClassifiedAsInterface(): void
    {
        $components = new Components();
        $components->schemas['Dog'] = $this->makeObjectSchema(['type', 'breed']);
        $components->schemas['Cat'] = $this->makeObjectSchema(['type', 'indoor']);
        $components->schemas['Animal'] = $this->makeOneOfDiscriminatorSchema(
            refs: ['#/components/schemas/Dog', '#/components/schemas/Cat'],
            discriminatorProperty: 'type',
        );

        $kinds = $this->resolver->resolve($components);

        self::assertSame(SchemaKind::Interface, $kinds['Animal']);
    }

    // -------------------------------------------------------------------------
    // Inline object hoisting
    // -------------------------------------------------------------------------

    public function testInlineObjectPropertyIsHoisted(): void
    {
        $components = new Components();

        $inlineAddress = new Schema();
        $inlineAddress->type = 'object';
        $inlineAddress->properties['line1'] = $this->makeSchema(type: 'string');

        $order = new Schema();
        $order->type = 'object';
        $order->properties['address'] = $inlineAddress;

        $components->schemas['Order'] = $order;

        $this->resolver->resolve($components);

        // Hoisted schema should now be in components->schemas
        self::assertArrayHasKey('OrderAddress', $components->schemas);
    }

    public function testHoistedInlineSchemaIsClassifiedAsObject(): void
    {
        $components = new Components();

        $inlineAddress = new Schema();
        $inlineAddress->type = 'object';
        $inlineAddress->properties['line1'] = $this->makeSchema(type: 'string');

        $order = new Schema();
        $order->type = 'object';
        $order->properties['address'] = $inlineAddress;

        $components->schemas['Order'] = $order;

        $kinds = $this->resolver->resolve($components);

        self::assertSame(SchemaKind::Object, $kinds['OrderAddress']);
    }

    public function testNonInlineRefPropertyIsNotHoisted(): void
    {
        $components = new Components();

        $refProp = new Schema();
        $refProp->ref = '#/components/schemas/Address';

        $order = new Schema();
        $order->type = 'object';
        $order->properties['address'] = $refProp;

        $components->schemas['Address'] = $this->makeObjectSchema(['street']);
        $components->schemas['Order'] = $order;

        $this->resolver->resolve($components);

        // No extra hoisted key beyond what was there
        self::assertArrayNotHasKey('OrderAddress', $components->schemas);
    }

    // -------------------------------------------------------------------------
    // Circular reference detection
    // -------------------------------------------------------------------------

    public function testCircularDirectRefDetected(): void
    {
        $components = new Components();

        $parent = new Schema();
        $parent->ref = '#/components/schemas/Node';

        $node = new Schema();
        $node->type = 'object';
        $node->properties['value'] = $this->makeSchema(type: 'string');
        $node->properties['parent'] = $parent;

        $components->schemas['Node'] = $node;

        $this->resolver->resolve($components);

        self::assertContains('parent', $this->resolver->getCircularProperties('Node'));
    }

    public function testCircularArrayRefDetected(): void
    {
        $components = new Components();

        $itemRef = new Schema();
        $itemRef->ref = '#/components/schemas/Node';

        $children = new Schema();
        $children->type = 'array';
        $children->items = $itemRef;

        $node = new Schema();
        $node->type = 'object';
        $node->properties['value'] = $this->makeSchema(type: 'string');
        $node->properties['children'] = $children;

        $components->schemas['Node'] = $node;

        $this->resolver->resolve($components);

        self::assertContains('children', $this->resolver->getCircularProperties('Node'));
    }

    public function testNonCircularPropertyNotMarked(): void
    {
        $components = new Components();
        $components->schemas['Tag'] = $this->makeObjectSchema(['id', 'name']);
        $components->schemas['Pet'] = $this->makeObjectSchemaWithRef(['name'], ['tag' => 'Tag']);

        $this->resolver->resolve($components);

        self::assertNotContains('tag', $this->resolver->getCircularProperties('Pet'));
    }

    // -------------------------------------------------------------------------
    // getKind helper
    // -------------------------------------------------------------------------

    public function testGetKindAfterResolve(): void
    {
        $components = new Components();
        $components->schemas['Color'] = $this->makeEnumSchema(['red', 'green']);
        $this->resolver->resolve($components);

        self::assertSame(SchemaKind::Enum, $this->resolver->getKind('Color'));
    }

    public function testGetKindForUnknownReturnsAlias(): void
    {
        $components = new Components();
        $this->resolver->resolve($components);

        self::assertSame(SchemaKind::Alias, $this->resolver->getKind('NonExistent'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeSchema(string $type): Schema
    {
        $s = new Schema();
        $s->type = $type;

        return $s;
    }

    /** @param string[] $propertyNames */
    private function makeObjectSchema(array $propertyNames): Schema
    {
        $s = new Schema();
        $s->type = 'object';
        foreach ($propertyNames as $name) {
            $s->properties[$name] = $this->makeSchema('string');
        }

        return $s;
    }

    /**
     * @param string[] $scalarProps
     * @param array<string, string> $refProps map of propName => schemaName
     */
    private function makeObjectSchemaWithRef(array $scalarProps, array $refProps): Schema
    {
        $s = $this->makeObjectSchema($scalarProps);
        foreach ($refProps as $propName => $schemaName) {
            $ref = new Schema();
            $ref->ref = '#/components/schemas/' . $schemaName;
            $s->properties[$propName] = $ref;
        }

        return $s;
    }

    /** @param array<int, int|string> $values */
    private function makeEnumSchema(array $values, string $type = 'string'): Schema
    {
        $s = new Schema();
        $s->type = $type;
        $s->enum = $values;

        return $s;
    }

    /** @param string[] $extraProps */
    private function makeAllOfSchema(string $refName, array $extraProps): Schema
    {
        $ref = new Schema();
        $ref->ref = '#/components/schemas/' . $refName;

        $extra = new Schema();
        $extra->type = 'object';
        foreach ($extraProps as $name) {
            $extra->properties[$name] = $this->makeSchema('string');
        }

        $s = new Schema();
        $s->allOf = [$ref, $extra];

        return $s;
    }

    /** @param string[] $refs */
    private function makeOneOfDiscriminatorSchema(array $refs, string $discriminatorProperty): Schema
    {
        $members = array_map(static function (string $refStr): Schema {
            $s = new Schema();
            $s->ref = $refStr;

            return $s;
        }, $refs);

        $discriminator = new Discriminator();
        $discriminator->propertyName = $discriminatorProperty;

        $s = new Schema();
        $s->oneOf = $members;
        $s->discriminator = $discriminator;

        return $s;
    }
}
