# Composer Repository Generator

[![Latest Version on Packagist](https://img.shields.io/packagist/v/petebishop/composer-repository-generator.svg)](https://packagist.org/packages/petebishop/composer-repository-generator)
[![PHP Version](https://img.shields.io/packagist/php-v/petebishop/composer-repository-generator.svg)](https://packagist.org/packages/petebishop/composer-repository-generator)
[![License](https://img.shields.io/github/license/petebishop/composer-repository-generator.svg)](LICENSE)

A PHP library for dynamically generating Composer repositories without requiring the Satis binary. This package allows you to programmatically create and manage private Composer repositories on-the-fly.

## Features

- Generate Composer repositories dynamically from PHP code
- Support for multiple source repositories (Git and local paths)
- Package version management through Git tags or explicit declarations
- Configurable caching for improved performance
- Custom package filtering
- PSR-4 compliant with PHP 8.3+ support
- No binary dependencies

## Installation

You can install the package via composer:

```bash
composer require petebishop/composer-repository-generator
```

## Basic Usage

```php
<?php

require_once 'vendor/autoload.php';

use Petebishop\ComposerRepositoryGenerator\RepositoryGenerator;

// Configure the generator
$config = [
    'output_dir' => __DIR__ . '/output/repository',
];

// Create generator instance
$generator = new RepositoryGenerator($config);

// Add source repositories
$generator->addSource('https://github.com/your-org/private-package.git');
$generator->addSource('/path/to/local/package');

// Generate the repository
$packagesJsonPath = $generator->generate();

echo "Repository generated at: {$packagesJsonPath}\n";
```

## Using the Generated Repository

To use your generated repository in a project, add it to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "file:///path/to/output/repository"
        }
    ],
    "require": {
        "your-org/private-package": "^1.0"
    }
}
```

## Advanced Configuration

### Custom Package Filtering

You can filter which packages are included in your repository:

```php
// Only include library packages
$generator->setPackageFilter(function (array $packageData) {
    return ($packageData['type'] ?? '') === 'library';
});

// Source-specific filtering
$generator->addSource('https://github.com/some/repo.git', [
    'package_filter' => function (array $packageData) {
        return str_starts_with($packageData['name'] ?? '', 'your-org/');
    }
]);
```

### Caching Control

Enable or disable caching to improve performance:

```php
// Enable caching (default)
$generator->useCache(true);

// Configure cache directory
$generator->setCacheDirectory(__DIR__ . '/custom-cache');

// Clean the cache
$generator->cleanCache();

// Clean cache for a specific source only
$generator->cleanCache('https://github.com/some/repo.git');
```

### Version Filtering

Control which versions are included from Git repositories:

```php
$generator->addSource('https://github.com/some/repo.git', [
    'semver_only' => true,           // Only include SemVer-compliant tags
    'include_dev_versions' => false, // Skip dev branches
]);
```

### Custom Logger

Use a PSR-3 compatible logger for monitoring:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('repository-generator');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$generator = new RepositoryGenerator(
    config: ['output_dir' => __DIR__ . '/output'],
    logger: $logger
);
```

## API Documentation

### Main Classes

- **RepositoryGenerator**: Main entry point for repository generation
- **PackageParser**: Handles parsing package information from sources

### Key Methods

#### RepositoryGenerator

- `__construct(array $config, ?PackageParser $packageParser = null, ?Filesystem $filesystem = null, ?LoggerInterface $logger = null)`
- `addSource(string $url, array $options = []): self`
- `addSources(array $sources): self`
- `setOutputDirectory(string $outputDir): self`
- `useCache(bool $useCache = true): self`
- `setCacheDirectory(string $cacheDir): self`
- `setPackageFilter(callable $filterCallback): self`
- `generate(): string`
- `cleanCache(?string $url = null): bool`

#### PackageParser

- `__construct(?Filesystem $filesystem = null, ?string $tempDir = null)`
- `parse(string $source, array $options = []): array`
- `cleanup(): void`

## Project Status

### Completed Features
- ✅ Core repository generation functionality
- ✅ Support for Git and local repositories
- ✅ Package filtering and version management
- ✅ Caching system for improved performance
- ✅ PSR-12 compliant code style
- ✅ Comprehensive unit tests
- ✅ Full static analysis (PHPStan level 8)
- ✅ Type-safe implementation with PHP 8.3 features

### Next Steps
1. Set up code coverage reporting with Xdebug or PCOV
2. Add integration tests for real-world scenarios
3. Add more examples for common use cases
4. Set up automated API documentation generation
5. Create GitHub Actions workflow for automated releases
6. Add performance benchmarks
7. Implement package signing support
8. Add support for more repository types

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

Please make sure your code adheres to the existing style and includes appropriate tests.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

