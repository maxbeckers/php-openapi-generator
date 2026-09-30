<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Generator\Context;

/**
 * A security scheme supported by generated clients.
 */
readonly class SecuritySchemeContext
{
    public const KIND_BEARER = 'bearer';
    public const KIND_BASIC = 'basic';
    public const KIND_HTTP = 'http';
    public const KIND_API_KEY = 'apiKey';

    /**
     * @param string      $name         Scheme name as declared in components.securitySchemes
     * @param string      $propertyName camelCase credential property name
     * @param string      $kind         One of the KIND_* constants
     * @param string|null $in           For apiKey: header | query | cookie
     * @param string|null $parameterName For apiKey: the header/query/cookie name
     * @param string|null $httpScheme   For generic HTTP schemes: the Authorization scheme token
     * @param string|null $description
     */
    public function __construct(
        public string $name,
        public string $propertyName,
        public string $kind,
        public ?string $in = null,
        public ?string $parameterName = null,
        public ?string $httpScheme = null,
        public ?string $description = null,
    ) {
    }
}
