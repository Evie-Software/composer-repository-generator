<?php

declare(strict_types=1);

/**
 * This file is part of the Composer Repository Generator package.
 *
 * (c) Pete Bishop <peter.bishop@evie.software>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EvieSoftware\ComposerRepositoryGenerator;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Main class for generating Composer repositories dynamically.
 *
 * This class provides functionality to create, manage, and generate
 * Composer repository metadata without requiring the Satis binary.
 */
class RepositoryGenerator
{
    /**
     * @var array<string, mixed> List of source repositories to include
     */
    private array $sources = [];

    /**
     * @var bool Whether to use caching for repository generation
     */
    private bool $useCache = true;

    /**
     * @var string Directory where repository output will be stored
     */
    private string $outputDir;

    /**
     * @var string Directory where cache files will be stored
     */
    private string $cacheDir;

    /**
     * @var callable(array<string,mixed>): bool|null Custom package filter callback
     */
    private $packageFilter = null;

    /**
     * @var Filesystem Filesystem instance for file operations
     */
    private Filesystem $filesystem;

    /**
     * @var PackageParser Package parser for reading composer.json files
     */
    private PackageParser $packageParser;

    /**
     * @var LoggerInterface Logger for recording operations
     */
    private LoggerInterface $logger;

    /**
     * @var array<string, mixed> Configuration options
     */
    private array $config;

    /**
     * @var array<string, string> Map of GitHub tokens by hostname
     */
    private array $githubTokens = [];

    /**
     * @var bool Whether to proxy private packages
     */
    private bool $proxyPrivatePackages = false;

    /**
     * @var string|null Directory for storing proxied package archives
     */
    private ?string $archiveDir = null;

    /**
     * Creates a new repository generator instance.
     *
     * @param array<string, mixed> $config Configuration options including output and cache directories
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException If directory creation fails
     */
    public function __construct(
        array $config,
        ?PackageParser $packageParser = null,
        ?Filesystem $filesystem = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem ?? new Filesystem();
        $this->logger = $logger ?? new NullLogger();
        $this->packageParser = $packageParser ?? new PackageParser($this->filesystem);
        $this->outputDir = $this->config['output_dir'] ?? sys_get_temp_dir() . '/composer-repository';
        $this->cacheDir = $this->config['cache_dir'] ?? $this->outputDir . '/cache';

        // Initialize GitHub authentication
        $this->githubTokens = $config['github_tokens'] ?? [];
        $this->proxyPrivatePackages = $config['proxy_private_packages'] ?? false;
        $this->archiveDir = $config['archive_dir'] ?? $this->outputDir . '/archives';

        // Ensure required directories exist
        $this->initializeDirectories();
    }

    /**
     * Add a source repository to include in the generated repository.
     *
     * @param string               $url     URL of the Git repository or path to local repository
     * @param array<string, mixed> $options Additional options for the source
     *
     * @return self
     */
    public function addSource(string $url, array $options = []): self
    {
        $this->sources[$url] = array_merge([
            'type' => $this->determineSourceType($url),
            'url' => $url,
            'include_all_versions' => true,
            'semver_only' => true,
            'include_dev_versions' => false,
        ], $options);

        return $this;
    }

    /**
     * Add multiple source repositories at once.
     *
     * @param array<string, array<string, mixed>> $sources Array of sources with their configurations
     *
     * @return self
     */
    public function addSources(array $sources): self
    {
        foreach ($sources as $url => $options) {
            $this->addSource($url, $options);
        }

        return $this;
    }

    /**
     * Enable or disable the use of caching during repository generation.
     *
     * @param bool $useCache Whether to use caching
     *
     * @return self
     */
    public function useCache(bool $useCache = true): self
    {
        $this->useCache = $useCache;
        return $this;
    }

    /**
     * Set the output directory for the generated repository.
     *
     * @param string $outputDir Path to the output directory
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException If directory creation fails
     *
     * @return self
     */
    public function setOutputDirectory(string $outputDir): self
    {
        $this->outputDir = $outputDir;
        $this->filesystem->mkdir($this->outputDir);
        return $this;
    }

    /**
     * Set the cache directory.
     *
     * @param string $cacheDir Path to the cache directory
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException If directory creation fails
     *
     * @return self
     */
    public function setCacheDirectory(string $cacheDir): self
    {
        $this->cacheDir = $cacheDir;
        $this->filesystem->mkdir($this->cacheDir);
        return $this;
    }

    /**
     * Set a custom filter callback for packages.
     *
     * The callback should accept a package array and return a boolean:
     * function(array $package): bool
     *
     * @param callable(array<string,mixed>): bool $filterCallback Filter function that returns true to include a package
     *
     * @return self
     */
    public function setPackageFilter(callable $filterCallback): self
    {
        $this->packageFilter = $filterCallback;
        return $this;
    }

    /**
     * Generate the composer repository (packages.json and related files).
     *
     * @throws \RuntimeException When repository generation fails
     *
     * @return string Path to the generated packages.json file
     */
    public function generate(): string
    {
        if (empty($this->sources)) {
            throw new \RuntimeException('No source repositories added. Use addSource() method to add at least one source.');
        }

        $this->logger->info('Starting repository generation');

        try {
            // Process each source repository
            $packages = $this->processSourceRepositories();

            if (empty($packages)) {
                $this->logger->warning('No packages were found in any of the source repositories');
            }

            // Generate packages.json
            $packagesJson = $this->buildPackagesJson($packages);

            // Write packages.json to output directory
            $packagesJsonPath = $this->outputDir . '/packages.json';
            $packagesJsonContent = json_encode($packagesJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($packagesJsonContent === false) {
                throw new \RuntimeException('Failed to encode packages.json data');
            }
            $this->filesystem->dumpFile($packagesJsonPath, $packagesJsonContent);

            // For each package, generate individual package metadata files
            $this->generatePackageMetadataFiles($packages);

            $this->logger->info('Repository generated successfully', [
                'output_path' => $packagesJsonPath,
                'package_count' => count($packages),
            ]);

            return $packagesJsonPath;
        } catch (\Exception $e) {
            $this->logger->error('Repository generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException('Repository generation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Clean the cache directory, optionally removing specific entries.
     *
     * @param string|null $url Optional source URL to clean cache for
     *
     * @return bool True if cleaning was successful
     */
    public function cleanCache(?string $url = null): bool
    {
        if (!$this->filesystem->exists($this->cacheDir)) {
            return true;
        }

        try {
            if ($url === null) {
                // Clean the entire cache
                $this->filesystem->remove($this->cacheDir);
                $this->filesystem->mkdir($this->cacheDir);
                $this->logger->info('Cache cleaned completely');
            } else {
                // Clean cache for a specific URL
                $finder = new Finder();
                $finder->files()
                    ->in($this->cacheDir)
                    ->name(md5($url) . '-*.json');

                foreach ($finder as $file) {
                    $this->filesystem->remove($file->getRealPath());
                }

                $this->logger->info('Cache cleaned for source', ['url' => $url]);
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to clean cache', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return false;
        }
    }

    /**
     * Add a GitHub authentication token.
     *
     * @param string $token    GitHub authentication token
     * @param string $hostname Optional GitHub hostname (defaults to github.com)
     *
     * @return self
     */
    public function addGitHubToken(string $token, string $hostname = 'github.com'): self
    {
        $this->githubTokens[$hostname] = $token;
        return $this;
    }

    /**
     * Enable or disable private package proxying.
     *
     * @param bool $enable Whether to enable proxying
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException If directory creation fails
     *
     * @return self
     */
    public function proxyPrivatePackages(bool $enable = true): self
    {
        $this->proxyPrivatePackages = $enable;

        if ($enable && $this->archiveDir !== null) {
            $this->filesystem->mkdir($this->archiveDir);
        }

        return $this;
    }

    /**
     * Create the necessary directory structure.
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException If directory creation fails
     */
    private function initializeDirectories(): void
    {
        $directories = [
            $this->outputDir,
            $this->cacheDir,
        ];

        if ($this->proxyPrivatePackages) {
            $directories[] = $this->archiveDir;
        }

        $this->filesystem->mkdir($directories);
    }

    /**
     * Determines the type of source based on the URL.
     *
     * @param string $url Repository URL or path
     *
     * @return string The repository type ('git', 'path', etc.)
     */
    private function determineSourceType(string $url): string
    {
        // Simple heuristic - can be expanded for more accurate detection
        if (preg_match('/^(https?:\/\/|git@)/', $url)) {
            return 'git';
        }

        return 'path';
    }

    /**
     * Process all source repositories to collect package information.
     *
     * @throws \RuntimeException If no valid packages are found
     *
     * @return array<string, mixed> Collected package information
     */
    private function processSourceRepositories(): array
    {
        $packages = [];
        $errors = [];

        foreach ($this->sources as $url => $config) {
            try {
                $this->logger->debug('Processing source', ['url' => $url]);
                $sourcePackages = $this->processSource($url, $config);

                if (empty($sourcePackages)) {
                    $this->logger->info('No packages found in source', ['url' => $url]);
                    continue;
                }

                $this->logger->debug('Found packages in source', [
                    'url' => $url,
                    'count' => count($sourcePackages),
                ]);

                // Merge packages, handling potential conflicts
                foreach ($sourcePackages as $packageName => $versions) {
                    if (!isset($packages[$packageName])) {
                        $packages[$packageName] = $versions;
                    } else {
                        // If package already exists, merge versions
                        $packages[$packageName] = array_merge($packages[$packageName], $versions);
                    }
                }
            } catch (\Exception $e) {
                $errors[] = "Error processing source '$url': " . $e->getMessage();
                $this->logger->error('Error processing source', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($errors) && empty($packages)) {
            throw new \RuntimeException("Failed to process any source repositories:\n" . implode("\n", $errors));
        }

        return $packages;
    }

    /**
     * Process a single source repository.
     *
     * @param string               $url    Source URL
     * @param array<string, mixed> $config Source configuration
     *
 * @throws \RuntimeException                                             When parsing or processing fails
 * @throws \Symfony\Component\Filesystem\Exception\IOException           If filesystem operations fail
 * @throws \Symfony\Component\Process\Exception\LogicException           If process setup fails
 * @throws \Symfony\Component\Process\Exception\RuntimeException         If process execution fails
 * @throws \Symfony\Component\Process\Exception\InvalidArgumentException If process setup fails
     *
     * @return array<string, mixed> Packages from this source
     */
    private function processSource(string $url, array $config): array
    {
        // If cache is enabled, try to load from cache first
        if ($this->useCache) {
            $cacheKey = $this->generateCacheKey($url, $config);
            $cachePath = $this->cacheDir . '/' . $cacheKey . '.json';

            if ($this->filesystem->exists($cachePath)) {
                $this->logger->debug('Loading packages from cache', ['url' => $url, 'cache_path' => $cachePath]);
                $cacheContent = file_get_contents($cachePath);

                if ($cacheContent !== false) {
                    $cachedPackages = json_decode($cacheContent, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $cachedPackages;
                    }
                }

                $this->logger->warning('Invalid cache data, will reprocess source', ['url' => $url]);
            }
        }

        // Prepare parser options
        $parserOptions = [
            'semver_only' => $config['semver_only'] ?? true,
            'include_dev_versions' => $config['include_dev_versions'] ?? false,
        ];

        // Add archive creation options if proxying is enabled
        if ($this->proxyPrivatePackages) {
            $parserOptions['create_archives'] = true;
            $parserOptions['archive_dir'] = $this->archiveDir;
        }

        // Add our custom package filter if set
        if ($this->packageFilter !== null) {
            $parserOptions['filter_callback'] = $this->packageFilter;
        }

        // Process GitHub URL to include authentication if available
        $processedUrl = $this->processGitHubUrl($url);

        // Parse packages from the source
        $this->logger->debug('Parsing packages from source', ['url' => $url]);
        $packages = $this->packageParser->parse($processedUrl, $parserOptions);

        // Apply any source-specific filters
        if (isset($config['package_filter']) && is_callable($config['package_filter'])) {
            $packages = $this->applyPackageFilter($packages, $config['package_filter']);
        }

        // Save to cache if enabled
        if ($this->useCache) {
            $cacheKey = $this->generateCacheKey($url, $config);
            $cachePath = $this->cacheDir . '/' . $cacheKey . '.json';

            $this->logger->debug('Saving packages to cache', ['url' => $url, 'cache_path' => $cachePath]);
            $cacheContent = json_encode($packages);
            if ($cacheContent === false) {
                throw new \RuntimeException('Failed to encode package data for caching');
            }
            $this->filesystem->dumpFile($cachePath, $cacheContent);
        }

        return $packages;
    }

    /**
     * Apply a filter callback to packages.
     *
     * @param array<string, mixed>                $packages       Packages to filter
     * @param callable(array<string,mixed>): bool $filterCallback Filter function that returns true to include a package
     *
     * @return array<string, mixed> Filtered packages
     */
    private function applyPackageFilter(array $packages, callable $filterCallback): array
    {
        $filtered = [];

        foreach ($packages as $packageName => $versions) {
            $filteredVersions = [];

            foreach ($versions as $version => $packageData) {
                if ($filterCallback($packageData)) {
                    $filteredVersions[$version] = $packageData;
                }
            }

            if (!empty($filteredVersions)) {
                $filtered[$packageName] = $filteredVersions;
            }
        }

        return $filtered;
    }

    /**
     * Generates a cache key for a repository source.
     *
     * @param string               $url    Source URL
     * @param array<string, mixed> $config Source configuration
     *
     * @throws \RuntimeException If configuration cannot be encoded
     *
     * @return string Cache key
     */
    private function generateCacheKey(string $url, array $config): string
    {
        // Filter out closures/callables from the config for caching
        $filteredConfig = array_filter($config, function ($value) {
            return !is_callable($value);
        });

        // Create a stable representation of the config for caching
        $configJson = json_encode($filteredConfig);
        if ($configJson === false) {
            throw new \RuntimeException('Failed to encode configuration for cache key generation');
        }
        $configHash = md5($configJson);

        // Create a key based on the URL and config
        $urlMd5 = md5($url);
        return $urlMd5 . '-' . $configHash;
    }

    /**
     * Generate individual package metadata files for more efficient loading.
     *
     * @param array<string, mixed> $packages Collected package information
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException If filesystem operations fail
     * @throws \RuntimeException                                   If JSON encoding fails
     *
     * @return void
     */
    private function generatePackageMetadataFiles(array $packages): void
    {
        if (empty($packages)) {
            return;
        }

        $metadataDir = $this->outputDir . '/p';
        $this->filesystem->mkdir($metadataDir);

        foreach ($packages as $packageName => $versions) {
            // Create a package-specific metadata file
            $packageData = [
                'packages' => [
                    $packageName => $versions,
                ],
            ];

            $packageDataJson = json_encode($packageData);
            if ($packageDataJson === false) {
                throw new \RuntimeException('Failed to encode package data for hash generation');
            }
            $packageHash = hash('sha256', $packageDataJson);
            $fileName = str_replace(['/', '$'], ['$', '$$'], $packageName);

            $metadataPath = sprintf('%s/%s.json', $metadataDir, $fileName);
            $hashedMetadataPath = sprintf('%s/%s$%s.json', $metadataDir, $fileName, $packageHash);

            // Encode package data
            $packageDataJson = json_encode($packageData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($packageDataJson === false) {
                throw new \RuntimeException('Failed to encode package metadata');
            }

            // Write the package metadata
            $this->filesystem->dumpFile($metadataPath, $packageDataJson);

            // Write the hashed version for content-addressable storage
            $this->filesystem->dumpFile($hashedMetadataPath, $packageDataJson);

            $this->logger->debug('Generated package metadata', [
                'package' => $packageName,
                'versions' => count($versions),
                'file' => $metadataPath,
            ]);
        }
    }

    /**
     * Build the packages.json file structure.
     *
     * @param array<string, mixed> $packages Collected package information
     *
     * @return array<string, mixed> Structure for packages.json
     */
    private function buildPackagesJson(array $packages): array
    {
        $timestamp = new \DateTime();

        return [
            'packages' => $packages,
            'metadata-url' => 'p/%package%.json',
            'providers-url' => 'p/%package%$%hash%.json',
            'available-packages' => array_keys($packages),
            'generated' => $timestamp->format(\DateTime::RFC3339),
        ];
    }

    /**
     * Process a GitHub repository URL to include authentication if available.
     *
     * @param string $url The GitHub repository URL
     *
     * @return string The processed URL
     */
    private function processGitHubUrl(string $url): string
    {
        if (!preg_match('#^https?://([^/]+)/(.+)\.git$#', $url, $matches)) {
            return $url;
        }

        $hostname = $matches[1];
        if (isset($this->githubTokens[$hostname])) {
            return sprintf(
                'https://%s@%s/%s.git',
                $this->githubTokens[$hostname],
                $hostname,
                $matches[2]
            );
        }

        return $url;
    }

    /**
     * Create a proxied archive for a package version.
     *
     * @internal This method is used internally by processSource() when package proxying is enabled
     *
     * @used-by processSource()
     *
     * @see     RepositoryGenerator::processSource()
     *
     * @param string $repoDir     The repository directory
     * @param string $packageName The package name
     * @param string $version     The package version
     *
     * @throws \RuntimeException                                     If archive creation fails
     * @throws \Symfony\Component\Process\Exception\LogicException   If process fails
     * @throws \Symfony\Component\Process\Exception\RuntimeException If process execution fails
     *
     * @return string The archive file path
     */
    private function createPackageArchive(string $repoDir, string $packageName, string $version): string
    {
        $archiveFile = sprintf(
            '%s/%s-%s.zip',
            $this->archiveDir,
            str_replace('/', '-', $packageName),
            $version
        );

        $process = new Process(['git', 'archive', '--format=zip', '--output=' . $archiveFile, $version], $repoDir);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Failed to create package archive: ' . $process->getErrorOutput());
        }

        return $archiveFile;
    }

    /**
     * Update package metadata to use proxied archives.
     *
     * @internal This method is used internally by processSource() when package proxying is enabled
     *
     * @used-by processSource()
     *
     * @see     RepositoryGenerator::processSource()
     *
     * @param array<string, mixed> &$packageData The package data to update
     * @param string               $archiveFile  The archive file path
     *
     * @return void
     */
    private function updatePackageDistUrls(array &$packageData, string $archiveFile): void
    {
        $relativePath = str_replace($this->outputDir, '', $archiveFile);
        $packageData['dist'] = [
            'type' => 'zip',
            'url' => $relativePath,
            'reference' => $packageData['source']['reference'] ?? '',
        ];
    }
}
