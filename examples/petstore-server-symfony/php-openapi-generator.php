<?php

declare(strict_types=1);

use MaxBeckers\OpenApiGenerator\Config\FrameworkTarget;
use MaxBeckers\OpenApiGenerator\Config\GenerationTarget;
use MaxBeckers\OpenApiGenerator\Config\GeneratorConfig;
use MaxBeckers\OpenApiGenerator\Config\ValidationStrategy;

$config = new GeneratorConfig();
$config->specFile = '../openapi.yaml';
$config->outputDir = 'generated';
$config->modelNamespace = 'App\\Model';
$config->modelOutputDir = 'Model';
$config->apiNamespace = 'App\\Api';
$config->apiOutputDir = 'Api';
$config->phpVersion = '8.2';
$config->phpReadonly = true;
$config->generateFromArray = true;
$config->generateToArray = true;
$config->generationTarget = GenerationTarget::Server;
$config->frameworkTarget = FrameworkTarget::Symfony;
$config->frameworkVersion = '7.4';
$config->validateServerRequest = true;
$config->validationStrategy = ValidationStrategy::SymfonyConstraints;

return $config;
