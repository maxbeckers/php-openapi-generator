<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Plugin\Builtin;

use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionContext;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionPluginInterface;
use MaxBeckers\OpenApiGenerator\Plugin\Extension\PropertyExtensionResult;

/**
 * Handles the x-trim extension.
 *
 * When a string property has `x-trim: <int>` in its extensions, the generated
 * fromArray() code will apply substr() to truncate the value.
 *
 * Example OAS:
 *   name:
 *     type: string
 *     x-trim: 255
 */
class TrimPlugin implements PropertyExtensionPluginInterface
{
    public function process(PropertyExtensionContext $context): ?PropertyExtensionResult
    {
        $trim = $context->extensions['x-trim'] ?? null;
        if ($trim === null) {
            return null;
        }

        $limit = (int) $trim;
        $wireName = $context->property->wireName;
        $phpName = $context->property->phpName;

        $extraCode = sprintf(
            'if (isset($data[\'%s\']) && is_string($data[\'%s\'])) { $data[\'%s\'] = substr($data[\'%s\'], 0, %d); }',
            $wireName,
            $wireName,
            $wireName,
            $wireName,
            $limit
        );

        return new PropertyExtensionResult(extraCode: $extraCode);
    }
}
