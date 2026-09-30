<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiGenerator\Tests\Integration;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\ValidationStrategy;
use MaxBeckers\OpenApiGenerator\Tests\Support\GeneratedCodeTestSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class GeneratedValidationRuntimeTest extends TestCase
{
    use GeneratedCodeTestSupport;

    private const FIXTURES_DIR = __DIR__ . '/../Fixtures';

    private ?\Closure $autoloader = null;
    private string $namespace;

    protected function setUp(): void
    {
        $this->initializeGeneratedCodeTestSupport();
    }

    protected function tearDown(): void
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
        }
        $this->removeGeneratedCodeOutput();
    }

    public function testNativeValidationChecksConstraintBoundariesFormatsAndNestedModels(): void
    {
        $this->generateValidationModels(ValidationStrategy::NativeMethod);
        $payloadClass = $this->namespace . '\\ValidationPayload';
        $payload = $payloadClass::fromArray(self::validPayload());

        self::assertSame([], $payload->validate());
        self::assertSame([], $payloadClass::fromArray(self::validPayload(['optionalCode' => null]))->validate());

        $cases = [
            [['code' => 'A'], 'code must have length >= 2.'],
            [['code' => 'ABCDEF'], 'code must have length <= 5.'],
            [['code' => 'Ab'], 'code must match pattern ^[A-Z]+$.'],
            [['optionalCode' => 'A'], 'optionalCode must have length >= 2.'],
            [['amount' => 0], 'amount must be >= 1.'],
            [['amount' => 11], 'amount must be <= 10.'],
            [['threshold' => 1], 'threshold must be > 1.'],
            [['threshold' => 10], 'threshold must be < 10.'],
            [['quantity' => 3], 'quantity must be a multiple of 2.'],
            [['tags' => ['one']], 'tags must contain at least 2 item(s).'],
            [['tags' => ['one', 'two', 'three', 'four']], 'tags must contain at most 3 item(s).'],
            [['tags' => ['same', 'same']], 'tags must contain unique items.'],
            [['state' => 'pending'], 'state must be one of: active, archived.'],
            [['email' => 'not-an-email'], 'email must be a valid email address.'],
            [['identifier' => 'not-a-uuid'], 'identifier must be a valid UUID.'],
            [['callback' => 'not a url'], 'callback must be a valid URI.'],
            [['child' => ['label' => 'x']], 'child.label must have length >= 2.'],
            [['children' => [['label' => 'x']]], 'children.0.label must have length >= 2.'],
            [['__remove' => ['code']], 'code must have length >= 2.'],
        ];

        foreach ($cases as [$overrides, $expectedError]) {
            $model = $payloadClass::fromArray(self::validPayload($overrides));
            self::assertContains($expectedError, $model->validate(), 'Expected validation error: ' . $expectedError);
        }

        $dateTimeModel = $payloadClass::fromArray(self::validPayload());
        self::assertInstanceOf(\DateTimeInterface::class, $dateTimeModel->occurredAt);
    }

    #[DataProvider('invalidDateValues')]
    public function testInvalidDateAndDateTimeInputsAreRejectedDuringHydration(
        ValidationStrategy $strategy,
        string $field,
        string $value,
    ): void {
        $this->generateValidationModels($strategy);

        $this->expectException(\Exception::class);
        ($this->namespace . '\\ValidationPayload')::fromArray(
            self::validPayload([$field => $value]),
        );
    }

    /**
     * @return iterable<string, array{ValidationStrategy, string, string}>
     */
    public static function invalidDateValues(): iterable
    {
        foreach (ValidationStrategy::cases() as $strategy) {
            if ($strategy === ValidationStrategy::None) {
                continue;
            }

            yield $strategy->value . '-date' => [$strategy, 'birthDate', 'not-a-date'];
            yield $strategy->value . '-date-time' => [$strategy, 'occurredAt', 'not-a-date-time'];
        }
    }

    public function testSymfonyAttributeValidationEnforcesBoundariesFormatsAndNestedModels(): void
    {
        $this->generateValidationModels(ValidationStrategy::SymfonyConstraints);
        $payloadClass = $this->namespace . '\\ValidationPayload';
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $violations = $validator->validate($payloadClass::fromArray(self::validPayload()));
        self::assertCount(0, $violations, (string) $violations);
        self::assertCount(0, $validator->validate(
            $payloadClass::fromArray(self::validPayload(['optionalCode' => null])),
        ));

        $cases = [
            [['code' => 'A'], 'code'],
            [['code' => 'ABCDEF'], 'code'],
            [['code' => 'Ab'], 'code'],
            [['optionalCode' => 'A'], 'optionalCode'],
            [['amount' => 0], 'amount'],
            [['amount' => 11], 'amount'],
            [['threshold' => 1], 'threshold'],
            [['threshold' => 10], 'threshold'],
            [['quantity' => 3], 'quantity'],
            [['tags' => ['one']], 'tags'],
            [['tags' => ['one', 'two', 'three', 'four']], 'tags'],
            [['tags' => ['same', 'same']], 'tags'],
            [['state' => 'pending'], 'state'],
            [['email' => 'not-an-email'], 'email'],
            [['identifier' => 'not-a-uuid'], 'identifier'],
            [['callback' => 'not a url'], 'callback'],
            [['child' => ['label' => 'x']], 'label'],
            [['children' => [['label' => 'x']]], 'label'],
            [['__remove' => ['code']], 'code'],
        ];

        foreach ($cases as [$overrides, $expectedPath]) {
            $violations = $validator->validate($payloadClass::fromArray(self::validPayload($overrides)));
            self::assertNotCount(0, $violations, 'Expected Symfony violation for ' . $expectedPath);
            self::assertStringContainsString($expectedPath, (string) $violations[0]->getPropertyPath());
        }
    }

    public function testLaravelRulesValidateBoundaryValuesFormatsEnumsAndNestedModels(): void
    {
        $this->generateValidationModels(ValidationStrategy::LaravelValidation);
        $payloadClass = $this->namespace . '\\ValidationPayload';
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        $rules = $payloadClass::rules();

        self::assertArrayHasKey('child.label', $rules);
        self::assertArrayHasKey('children.*.label', $rules);
        $validValidator = $factory->make(self::validPayload(), $rules);
        self::assertTrue($validValidator->passes(), json_encode($validValidator->errors()->toArray(), JSON_THROW_ON_ERROR));

        $cases = [
            [['code' => 'A'], 'code'],
            [['code' => 'ABCDEF'], 'code'],
            [['code' => 'Ab'], 'code'],
            [['optionalCode' => 'A'], 'optionalCode'],
            [['amount' => 0], 'amount'],
            [['amount' => 11], 'amount'],
            [['threshold' => 1], 'threshold'],
            [['threshold' => 10], 'threshold'],
            [['quantity' => 3], 'quantity'],
            [['tags' => ['one']], 'tags'],
            [['tags' => ['one', 'two', 'three', 'four']], 'tags'],
            [['tags' => ['same', 'same']], 'tags.'],
            [['state' => 'pending'], 'state'],
            [['email' => 'not-an-email'], 'email'],
            [['identifier' => 'not-a-uuid'], 'identifier'],
            [['birthDate' => 'not-a-date'], 'birthDate'],
            [['occurredAt' => 'not-a-date-time'], 'occurredAt'],
            [['callback' => 'not a url'], 'callback'],
            [['child' => ['label' => 'x']], 'child.label'],
            [['children' => [['label' => 'x']]], 'children.0.label'],
            [['__remove' => ['code']], 'code'],
        ];

        foreach ($cases as [$overrides, $expectedKey]) {
            $validator = $factory->make(self::validPayload($overrides), $rules);
            self::assertFalse($validator->passes(), 'Expected Laravel validation failure for ' . $expectedKey);
            $errorKeys = array_keys($validator->errors()->toArray());
            if (str_ends_with($expectedKey, '.')) {
                self::assertNotEmpty(array_filter($errorKeys, static fn (string $key): bool => str_starts_with($key, $expectedKey)));
            } else {
                self::assertContains($expectedKey, $errorKeys);
            }
        }
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function validPayload(array $overrides = []): array
    {
        $remove = $overrides['__remove'] ?? [];
        unset($overrides['__remove']);
        $payload = array_replace([
            'code' => 'AB',
            'optionalCode' => null,
            'amount' => 2,
            'threshold' => 2.5,
            'quantity' => 4,
            'tags' => ['one', 'two'],
            'state' => 'active',
            'email' => 'ada@example.com',
            'identifier' => '550e8400-e29b-41d4-a716-446655440000',
            'birthDate' => '1990-02-03',
            'occurredAt' => '2026-09-29T10:15:30+00:00',
            'callback' => 'https://example.com/callback',
            'child' => ['label' => 'valid'],
            'children' => [['label' => 'also valid']],
        ], $overrides);

        foreach ($remove as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    private function generateValidationModels(ValidationStrategy $strategy): void
    {
        $this->namespace = 'Generated\\Validation' . bin2hex(random_bytes(6));
        $config = new GeneratorConfig();
        $config->specFile = 'validation-runtime.yaml';
        $config->modelNamespace = $this->namespace;
        $config->modelOutputDir = 'Model';
        $config->apiNamespace = '';
        $config->apiOutputDir = '';
        $config->generationTarget = GenerationTarget::Server;
        $config->validationStrategy = $strategy;
        $config->phpReadonly = false;
        $config->generateFromArray = true;
        $config->generateToArray = true;
        $config->phpVersion = '8.2';

        $this->generateCode($config, self::FIXTURES_DIR, false);

        $prefix = $this->namespace . '\\';
        $modelDirectory = $this->generatedCodeOutputDir . DIRECTORY_SEPARATOR . 'Model';
        $this->autoloader = static function (string $class) use ($prefix, $modelDirectory): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $path = $modelDirectory . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        };
        spl_autoload_register($this->autoloader);
    }
}
