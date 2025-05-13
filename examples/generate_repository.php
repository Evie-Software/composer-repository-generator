<?php

/**
 * Example script demonstrating how to use the Composer Repository Generator.
 *
 * This example shows how to:
 * - Set up the RepositoryGenerator with custom configuration
 * - Add multiple source repositories (both Git and local)
 * - Configure package filters
 * - Enable caching
 * - Generate the repository
 * - Handle errors properly
 */

declare(strict_types=1);

/**
 * This file is part of the Composer Repository Generator package.
 *
 * (c) Pete Bishop <peter.bishop@evie.software>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// This assumes the example is run from the project root using: php examples/generate_repository.php
require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use EvieSoftware\ComposerRepositoryGenerator\PackageParser;
use EvieSoftware\ComposerRepositoryGenerator\RepositoryGenerator;
use Symfony\Component\Filesystem\Filesystem;

// Step 1: Set up logging
$logger = new Logger('repository-generator');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/repository.log', Logger::DEBUG));

// Step 2: Define configuration
$config = [
    'output_dir' => __DIR__ . '/../output/repository',
    'cache_dir' => __DIR__ . '/../cache',
];

try {
    // Step 3: Initialize the RepositoryGenerator with our configuration
    $filesystem = new Filesystem();
    $packageParser = new PackageParser($filesystem);
    $generator = new RepositoryGenerator(
        config: $config,
        packageParser: $packageParser,
        filesystem: $filesystem,
        logger: $logger
    );

    // Step 4: Configure the generator
    $generator->useCache(true);  // Enable caching (already default but shown for example)

    // Step 5: Define a custom package filter (optional)
    // This example filter only includes library packages
    $generator->setPackageFilter(function (array $packageData) {
        // Only include libraries (not applications, metapackages, etc.)
        return ($packageData['type'] ?? '') === 'library';
    });

    // Step 6: Add source repositories

    // Example Git repository
    $generator->addSource(
        'https://github.com/symfony/filesystem.git',
        [
            'include_dev_versions' => false,
            'semver_only' => true,
        ]
    );

    // Another Git repository with custom options
    $generator->addSource(
        'https://github.com/symfony/finder.git',
        [
            'include_dev_versions' => true,  // Include development branches
            'semver_only' => false,          // Include all tags, not just semver ones
        ]
    );

    // Local repository example
    $generator->addSource(
        __DIR__ . '/../',  // Using this package itself as an example
        [
            // Source-specific filter that overrides the global one
            'package_filter' => function (array $packageData) {
                return true; // Include all packages from this source
            },
        ]
    );

    // Step 7: Generate the repository
    $logger->info('Starting repository generation...');
    $packagesJsonPath = $generator->generate();
    $logger->info('Repository generated successfully', ['path' => $packagesJsonPath]);

    // Step 8: Display success message with usage instructions
    echo "Repository generated successfully!\n";
    echo "Generated packages.json: {$packagesJsonPath}\n\n";
    echo "To use this repository in a project, add the following to composer.json:\n\n";
    echo json_encode([
        'repositories' => [
            [
                'type' => 'composer',
                'url' => 'file://' . realpath($config['output_dir']),
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "\n";

} catch (\Exception $e) {
    // Step 9: Handle errors
    $logger->error('An error occurred during repository generation', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
