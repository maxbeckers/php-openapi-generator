<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Plugin\Extension;

/**
 * Result returned by a property extension plugin.
 *
 * Plugins may return null to indicate no changes are required.
 */
readonly class PropertyExtensionResult
{
    /**
     * @param string|null $extraCode extra PHP code to inject into the fromArray() body for this property
     * @param string[]    $extraAttributes PHP 8 attributes to add to the property (e.g. '#[SensitiveParameter]').
     */
    public function __construct(
        public ?string $extraCode = null,
        public array $extraAttributes = [],
    ) {
    }
}
