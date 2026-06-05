<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator;

use MaxBeckers\OpenApiGenerator\Spec\Components;
use MaxBeckers\OpenApiGenerator\Spec\Schema;

/**
 * Classifies each component schema into a SchemaKind and builds the ordered
 * list of schemas to generate, handling:
 *
 * - Inline object hoisting (anonymous object properties become named schemas)
 * - allOf / oneOf / anyOf classification
 * - Circular reference detection via DFS colouring
 */
class SchemaResolver
{
    private const UNVISITED = 0;
    private const IN_PROGRESS = 1;
    private const DONE = 2;

    /** @var array<string, int> DFS visit state per schema name */
    private array $state = [];

    /** @var array<string, SchemaKind> final classification per schema name */
    private array $kinds = [];

    /** @var array<string, Schema> resolved schema registry (includes hoisted inlines) */
    private array $schemas = [];

    /**
     * @var array<string, string[]> per schema, property names that form circular refs
     *                               (the property type should default to null / [])
     */
    private array $circularProperties = [];

    public function __construct(private readonly NamingStrategy $naming)
    {
    }

    /**
     * Analyse all component schemas and return a flat, ordered map of
     * schema-name → SchemaKind ready for generation.
     *
     * Side-effects:
     * - Mutates $components->schemas to add hoisted inline schemas.
     * - Populates $this->kinds, $this->circularProperties.
     *
     * @return array<string, SchemaKind>
     */
    public function resolve(Components $components): array
    {
        // Seed the registry from components
        $this->schemas = $components->schemas;
        $this->state = [];
        $this->kinds = [];
        $this->circularProperties = [];

        // Hoist inline object properties in all component schemas
        foreach (array_keys($this->schemas) as $name) {
            $this->hoistInlineObjects($name, $this->schemas[$name]);
        }

        // Propagate hoisted schemas back into components
        $components->schemas = $this->schemas;

        // DFS classify each schema
        foreach (array_keys($this->schemas) as $name) {
            $this->visit($name);
        }

        return $this->kinds;
    }

    /** Return the classification of a single schema name (must call resolve() first). */
    public function getKind(string $schemaName): SchemaKind
    {
        return $this->kinds[$schemaName] ?? SchemaKind::Alias;
    }

    /**
     * Return the property names that are circular for a given schema name.
     *
     * @return string[]
     */
    public function getCircularProperties(string $schemaName): array
    {
        return $this->circularProperties[$schemaName] ?? [];
    }

    // -------------------------------------------------------------------------
    // DFS classification
    // -------------------------------------------------------------------------

    private function visit(string $name): void
    {
        if (($this->state[$name] ?? self::UNVISITED) === self::DONE) {
            return;
        }

        if (($this->state[$name] ?? self::UNVISITED) === self::IN_PROGRESS) {
            // Cycle detected — do not recurse further; classification will be
            // finalised when the frame unwinds.
            return;
        }

        $this->state[$name] = self::IN_PROGRESS;

        $schema = $this->schemas[$name] ?? null;
        if ($schema === null) {
            $this->kinds[$name] = SchemaKind::Alias;
            $this->state[$name] = self::DONE;

            return;
        }

        $kind = $this->classify($name, $schema);
        $this->kinds[$name] = $kind;
        $this->state[$name] = self::DONE;
    }

    private function classify(string $name, Schema $schema): SchemaKind
    {
        // Pure $ref at top level → alias
        if ($schema->ref !== null && $schema->type === null) {
            return SchemaKind::Alias;
        }

        // Enum
        if (!empty($schema->enum)) {
            return SchemaKind::Enum;
        }

        // oneOf / anyOf with a discriminator → marker interface
        if ((!empty($schema->oneOf) || !empty($schema->anyOf)) && $schema->discriminator !== null) {
            $this->visitCompositionMembers($name, $schema->oneOf);
            $this->visitCompositionMembers($name, $schema->anyOf);

            return SchemaKind::Interface;
        }

        // oneOf / anyOf without discriminator where all members are $refs → object (union type)
        if (!empty($schema->oneOf) || !empty($schema->anyOf)) {
            $all = array_merge($schema->oneOf, $schema->anyOf);
            $this->visitCompositionMembers($name, $all);

            return SchemaKind::Object;
        }

        // allOf: single $ref + extra properties → extend the referenced class
        // allOf: multiple → flat merge (still generates as Object)
        if (!empty($schema->allOf)) {
            $this->visitCompositionMembers($name, $schema->allOf);

            return SchemaKind::Object;
        }

        // Explicit object type
        if ($schema->type === 'object' || !empty($schema->properties)) {
            $this->detectCircularProperties($name, $schema);

            return SchemaKind::Object;
        }

        // Array type with object items — items have been hoisted already; classify as Alias/scalar wrapper
        if ($schema->type === 'array') {
            if ($schema->items !== null) {
                $this->visitSchemaRef($schema->items);
            }

            return SchemaKind::Alias;
        }

        // Scalar / unknown — treat as Alias (no file generated)
        return SchemaKind::Alias;
    }

    /**
     * Visit referenced schemas reachable from composition lists.
     *
     * @param Schema[] $members
     */
    private function visitCompositionMembers(string $parentName, array $members): void
    {
        foreach ($members as $sub) {
            $this->visitSchemaRef($sub);
        }
    }

    private function visitSchemaRef(Schema $schema): void
    {
        if ($schema->ref !== null) {
            $refName = $this->extractRefName($schema->ref);
            if ($refName !== '' && isset($this->schemas[$refName])) {
                $this->visit($refName);
            }
        }
    }

    /**
     * Detect circular property references within an object schema.
     * A circular direct $ref means the property should default to null.
     * A circular array $ref means the property should default to [].
     */
    private function detectCircularProperties(string $name, Schema $schema): void
    {
        foreach ($schema->properties as $propName => $propSchema) {
            $targetName = null;

            if ($propSchema->ref !== null) {
                $targetName = $this->extractRefName($propSchema->ref);
            } elseif ($propSchema->type === 'array' && $propSchema->items?->ref !== null) {
                $targetName = $this->extractRefName($propSchema->items->ref);
            }

            if ($targetName !== null && ($this->state[$targetName] ?? self::UNVISITED) === self::IN_PROGRESS) {
                // Circular!
                $this->circularProperties[$name][] = $propName;
            } elseif ($targetName !== null && isset($this->schemas[$targetName])) {
                $this->visit($targetName);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Inline object hoisting
    // -------------------------------------------------------------------------

    /**
     * Walk a schema's properties and hoist any inline object schemas into the
     * top-level component registry.
     */
    private function hoistInlineObjects(string $schemaName, Schema $schema): void
    {
        foreach ($schema->properties as $propName => $propSchema) {
            if ($propSchema->ref !== null) {
                continue;
            }

            if ($propSchema->type === 'object' || !empty($propSchema->properties)) {
                // Hoist to a named schema
                $hoistedName = $this->naming->inlineClassName($schemaName, $propName);

                if (!isset($this->schemas[$hoistedName])) {
                    $this->schemas[$hoistedName] = $propSchema;
                    // Recurse to hoist nested inlines
                    $this->hoistInlineObjects($hoistedName, $propSchema);
                }

                // Replace the inline schema with a $ref
                $refSchema = new Schema();
                $refSchema->ref = '#/components/schemas/' . $hoistedName;
                $schema->properties[$propName] = $refSchema;
            } elseif ($propSchema->type === 'array'
                && $propSchema->items !== null
                && $propSchema->items->ref === null
                && ($propSchema->items->type === 'object' || !empty($propSchema->items->properties))
            ) {
                // Inline object inside array items → hoist
                $hoistedName = $this->naming->inlineClassName($schemaName, $propName);

                if (!isset($this->schemas[$hoistedName])) {
                    $this->schemas[$hoistedName] = $propSchema->items;
                    $this->hoistInlineObjects($hoistedName, $propSchema->items);
                }

                $refSchema = new Schema();
                $refSchema->ref = '#/components/schemas/' . $hoistedName;
                $propSchema->items = $refSchema;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function extractRefName(string $ref): string
    {
        return (string) (array_reverse(explode('/', $ref))[0] ?? '');
    }
}
