<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator;

/**
 * Classifies how a Schema should be generated.
 */
enum SchemaKind: string
{
    /** A regular DTO class with typed properties */
    case Object = 'object';

    /** A backed PHP enum */
    case Enum = 'enum';

    /**
     * A marker interface generated for oneOf/anyOf with discriminator,
     * or for allOf base schemas.
     */
    case Interface = 'interface';

    /**
     * A type alias — the schema is just a $ref or a scalar wrapper.
     * No file is generated; references are rewritten to the target type.
     */
    case Alias = 'alias';
}
