<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Unit\Generator;

use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\PropertyNaming;
use MaxBeckers\OpenApiGenerator\Generator\NamingStrategy;
use PHPUnit\Framework\TestCase;

class NamingStrategyTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Property naming
    // -------------------------------------------------------------------------

    public function testPropertyNamingCamelCase(): void
    {
        $naming = $this->makeNaming(PropertyNaming::CamelCase);

        self::assertSame('firstName', $naming->propertyName('first_name'));
        self::assertSame('firstName', $naming->propertyName('firstName'));
        self::assertSame('firstName', $naming->propertyName('FirstName'));
        self::assertSame('myProp', $naming->propertyName('my-prop'));
    }

    public function testPropertyNamingSnakeCase(): void
    {
        $naming = $this->makeNaming(PropertyNaming::SnakeCase);

        self::assertSame('first_name', $naming->propertyName('firstName'));
        self::assertSame('first_name', $naming->propertyName('FirstName'));
        // Already snake_case stays
        self::assertSame('first_name', $naming->propertyName('first_name'));
    }

    public function testPropertyNamingSnakeCaseOnAcronym(): void
    {
        $naming = $this->makeNaming(PropertyNaming::SnakeCase);

        // "XMLParser" → "xml_parser"
        self::assertSame('xml_parser', $naming->propertyName('XMLParser'));
        // "getHTTPSUrl" → "get_https_url"
        self::assertSame('get_https_url', $naming->propertyName('getHTTPSUrl'));
    }

    public function testPropertyNamingOriginal(): void
    {
        $naming = $this->makeNaming(PropertyNaming::Original);

        self::assertSame('firstName', $naming->propertyName('firstName'));
        self::assertSame('first_name', $naming->propertyName('first_name'));
        self::assertSame('my-prop', $naming->propertyName('my-prop'));
    }

    public function testPropertyNamingReservedWordEscaped(): void
    {
        $naming = $this->makeNaming(PropertyNaming::Original);

        self::assertSame('class_', $naming->propertyName('class'));
        self::assertSame('list_', $naming->propertyName('list'));
        self::assertSame('match_', $naming->propertyName('match'));
    }

    // -------------------------------------------------------------------------
    // Class / enum / interface naming
    // -------------------------------------------------------------------------

    public function testClassNameWithSuffix(): void
    {
        $config = new GeneratorConfig();
        $config->classSuffix = 'Dto';
        $naming = new NamingStrategy($config);

        self::assertSame('PetDto', $naming->className('Pet'));
        self::assertSame('NewPetDto', $naming->className('NewPet'));
    }

    public function testClassNameWithPrefix(): void
    {
        $config = new GeneratorConfig();
        $config->classPrefix = 'Api';
        $naming = new NamingStrategy($config);

        self::assertSame('ApiPet', $naming->className('Pet'));
    }

    public function testEnumNameUsesSeparateSuffix(): void
    {
        $config = new GeneratorConfig();
        $config->classSuffix = 'Dto';
        $config->enumSuffix = 'Enum';
        $naming = new NamingStrategy($config);

        // classSuffix not applied to enums
        self::assertSame('PetStatusEnum', $naming->enumName('PetStatus'));
        // interfaceSuffix applied to interfaces
        self::assertSame('PetInterface', $naming->interfaceName('Pet'));
    }

    public function testEnumCaseNamePascalCase(): void
    {
        $naming = $this->makeNaming(PropertyNaming::CamelCase);

        self::assertSame('Available', $naming->enumCaseName('available'));
        self::assertSame('PendingReview', $naming->enumCaseName('pending_review'));
        self::assertSame('Value404', $naming->enumCaseName('404'));
    }

    public function testEnumCaseNameNumericIntGetsValuePrefix(): void
    {
        $naming = $this->makeNaming(PropertyNaming::CamelCase);

        self::assertSame('Value1', $naming->enumCaseName('1'));
        self::assertSame('Value2', $naming->enumCaseName('2'));
        self::assertSame('Value100', $naming->enumCaseName('100'));
    }

    public function testEnumCaseNameReservedWordEscaped(): void
    {
        $naming = $this->makeNaming(PropertyNaming::CamelCase);

        // 'default' is a reserved word; should get suffix
        self::assertSame('Default_', $naming->enumCaseName('default'));
    }

    public function testClassNameReservedWordEscaped(): void
    {
        $config = new GeneratorConfig();
        $naming = new NamingStrategy($config);

        // Schema named "Class" → className produces "Class" which is reserved
        self::assertSame('Class_', $naming->className('class'));
    }

    public function testInterfaceNameUsesSeparateSuffix(): void
    {
        $config = new GeneratorConfig();
        $config->classSuffix = 'Dto';
        $config->interfaceSuffix = 'Interface';
        $naming = new NamingStrategy($config);

        self::assertSame('AnimalInterface', $naming->interfaceName('Animal'));
        // classSuffix not applied to interfaces
        self::assertNotSame('AnimalDto', $naming->interfaceName('Animal'));
    }

    public function testInlineClassNameDerived(): void
    {
        $naming = $this->makeNaming(PropertyNaming::CamelCase);

        self::assertSame('PetAddress', $naming->inlineClassName('Pet', 'address'));
        self::assertSame('OrderShippingInfo', $naming->inlineClassName('Order', 'shipping_info'));
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makeNaming(PropertyNaming $naming): NamingStrategy
    {
        $config = new GeneratorConfig();
        $config->propertyNaming = $naming;

        return new NamingStrategy($config);
    }
}
