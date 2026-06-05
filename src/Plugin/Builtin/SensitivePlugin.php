<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Plugin\Builtin;

use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionContext;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionPluginInterface;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionResult;

/**
 * Handles the x-sensitive extension.
 *
 * When a property has `x-sensitive: true`, the generated property gets the
 * #[SensitiveParameter] attribute (PHP 8.2+).
 *
 * Example OAS:
 *   password:
 *     type: string
 *     x-sensitive: true
 */
class SensitivePlugin implements PropertyExtensionPluginInterface
{
    public function process(PropertyExtensionContext $context): ?PropertyExtensionResult
    {
        $sensitive = $context->extensions['x-sensitive'] ?? false;
        if (!$sensitive) {
            return null;
        }

        return new PropertyExtensionResult(
            extraAttributes: ['#[\\SensitiveParameter]'],
        );
    }
}
