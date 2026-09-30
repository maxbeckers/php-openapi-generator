# Opt-in framework integration tests

These are independent Composer projects. They are excluded from the root PHPUnit
suite and `composer test`. Each suite generates the shared `fixture.yaml` through
the package's `OpenApiService` at test startup, in a unique temporary directory
that is removed when the PHP process exits. No example or tracked output is
changed. The Symfony fixture boots a `MicroKernelTrait` kernel with imported
controller attributes; Laravel uses Orchestra Testbench and registers the
generated routes under `/v1`. Neither the Symfony serializer nor a generated
HTTP client is used by these server-side fixtures.

Install dependencies separately, from the repository root:

```sh
composer --working-dir=tests/Framework/symfony install
composer --working-dir=tests/Framework/laravel install
```

Run either or both suites explicitly (Linux/macOS):

```sh
OPENAPI_GEN_FRAMEWORK_TESTS=1 composer --working-dir=tests/Framework/symfony test
OPENAPI_GEN_FRAMEWORK_TESTS=1 composer --working-dir=tests/Framework/laravel test
```

PowerShell, with a specific PHP executable (use an installed Composer PHAR):

```powershell
$php = 'C:\development\php\php-8.4.22\php.exe'
& $php composer.phar --working-dir=tests/Framework/symfony install
& $php composer.phar --working-dir=tests/Framework/laravel install
$env:OPENAPI_GEN_FRAMEWORK_TESTS = '1'
& $php tests/Framework/symfony/vendor/bin/phpunit -c tests/Framework/symfony/phpunit.xml.dist
& $php tests/Framework/laravel/vendor/bin/phpunit -c tests/Framework/laravel/phpunit.xml.dist
```

Without `OPENAPI_GEN_FRAMEWORK_TESTS=1`, the isolated tests skip themselves.
Both projects resolve the local package through a Composer path repository; do
not install them into the root project's vendor directory. Composer lock files
are ignored by the root `.gitignore`, so `install` resolves each scaffold's
supported dependencies on first use.

CI runs these projects in a dedicated matrix:

| Framework | Dependency lane | PHP |
|---|---|---|
| Symfony | 7.4 | 8.2 |
| Symfony | 8.x | 8.4 |
| Laravel | 12 via Testbench 10 | 8.2 |
| Laravel | 13 via Testbench 11 | 8.4 |

Each lane resolves its own dependencies, generates code at test startup, boots
the real framework application, verifies route/container registration, and
performs functional HTTP requests against the generated controllers.

## Petstore example smoke test

The `petstore-smoke` CI job installs both Symfony examples through their local
path repositories. Composer's `post-autoload-dump` plugin hook generates each
example's code. The job starts the server with PHP's built-in web server and
executes the generated client against it. To run the same smoke test locally
after installing both examples from the repository root:

```sh
composer --working-dir=examples/petstore-server-symfony update --no-interaction
composer --working-dir=examples/petstore-client-symfony update --no-interaction
php -S 127.0.0.1:8080 -t examples/petstore-server-symfony/public examples/petstore-server-symfony/public/index.php
```

In a second terminal, run:

```sh
PETSTORE_BASE_URL=http://127.0.0.1:8080 php tests/Examples/petstore-client-smoke.php
```
