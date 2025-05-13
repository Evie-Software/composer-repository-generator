<?php

declare(strict_types=1);

/**
 * This file is part of the Composer Repository Generator package.
 *
 * (c) Pete Bishop <pete@example.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EvieSoftware\ComposerRepositoryGenerator;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Parses composer.json files from source repositories.
 *
 * This class is responsible for extracting and validating metadata from
 * composer.json files found in source repositories, handling version
 * information from Git tags or explicit declarations, and parsing package dependencies.
 */
class PackageParser
{
    /**
     * @var Filesystem Filesystem instance for file operations
     */
    private Filesystem $filesystem;

    /**
     * @var string|null Temporary directory for cloning repositories
     */
    private ?string $tempDir = null;

    /**
     * Create a new PackageParser instance.
     *
     * @param Filesystem|null $filesystem Filesystem instance for file operations
     * @param string|null     $tempDir    Temporary directory for Git operations
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException If filesystem operations fail
     */
    public function __construct(
        ?Filesystem $filesystem = null,
        ?string $tempDir = null,
    ) {
        $this->filesystem = $filesystem ?? new Filesystem();
        $this->tempDir = $tempDir ?? sys_get_temp_dir() . '/composer-repo-parser-' . uniqid();

        // Ensure the temporary directory exists
        $this->filesystem->mkdir($this->tempDir);
    }

    /**
     * Destructor to ensure cleanup.
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException If cleanup fails
     */
    public function __destruct()
    {
        $this->cleanup();
    }

    /**
     * Parse a composer.json file from a source repository.
     *
     * @param string               $source  Source URL or path
     * @param array<string, mixed> $options Parser options
     *
     * @throws \RuntimeException                                             If parsing fails
     * @throws \Symfony\Component\Process\Exception\LogicException           If process setup fails
     * @throws \Symfony\Component\Process\Exception\RuntimeException         If process execution fails
     * @throws \Symfony\Component\Process\Exception\InvalidArgumentException If process setup fails
     * @throws \Symfony\Component\Filesystem\Exception\IOException           If filesystem operations fail
     *
     * @return array<string, mixed> Package information
     */
    public function parse(string $source, array $options = []): array
    {
        return match ($this->determineSourceType($source)) {
            'git' => $this->parseFromGit($source, $options),
            'path' => $this->parseFromPath($source, $options),
            default => throw new \RuntimeException("Unsupported source type for: $source"),
        };
    }

    /**
     * Clean up temporary resources.
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException If filesystem operations fail
     */
    public function cleanup(): void
    {
        if ($this->tempDir && $this->filesystem->exists($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }
    }

    /**
     * Determine the type of source (git, path, etc.).
     *
     * @param string $source Source URL or path
     *
     * @return string Source type
     */
    private function determineSourceType(string $source): string
    {
        if (preg_match('/^(https?:\/\/|git@)/', $source)) {
            return 'git';
        }

        return 'path';
    }

    /**
     * Parse a composer.json file from a Git repository.
     *
     * @param string               $gitUrl  Git repository URL
     * @param array<string, mixed> $options Parser options
     *
 * @throws \RuntimeException                                             If Git operations fail
 * @throws \Symfony\Component\Process\Exception\LogicException           If process fails
 * @throws \Symfony\Component\Process\Exception\RuntimeException         If process execution fails
 * @throws \Symfony\Component\Process\Exception\InvalidArgumentException If process setup fails
 * @throws \Symfony\Component\Filesystem\Exception\IOException           If filesystem operations fail
 *
 * @return array<string, mixed> Package information
     */
    private function parseFromGit(string $gitUrl, array $options = []): array
    {
        $repoDir = $this->cloneOrUpdateRepo($gitUrl, $options);
        $versions = $this->getVersionsFromGit($repoDir, $options);

        if (empty($versions)) {
            throw new \RuntimeException("No valid versions found in Git repository: $gitUrl");
        }

        $packages = [];
        $createArchives = $options['create_archives'] ?? false;

        foreach ($versions as $version => $ref) {
            try {
                // Checkout the specific version
                $this->gitCheckout($repoDir, $ref);

                // Parse composer.json at this version
                $composerJsonPath = $repoDir . '/composer.json';
                if (!file_exists($composerJsonPath)) {
                    continue; // Skip versions without composer.json
                }

                $packageInfo = $this->parseComposerJson($composerJsonPath, [
                    'version' => $version,
                    'reference' => $ref,
                    'source' => [
                        'type' => 'git',
                        'url' => $gitUrl,
                        'reference' => $ref,
                    ],
                ]);

                if (!$this->shouldIncludePackage($packageInfo, $options)) {
                    continue;
                }

                $packageName = $packageInfo['name'] ?? null;
                if (!$packageName) {
                    continue; // Skip packages without a name
                }

                // Create archive if requested
                if ($createArchives && isset($options['archive_dir'])) {
                    $archiveFile = $this->createArchive($repoDir, $packageName, $version, $options['archive_dir']);
                    if ($archiveFile !== null) {
                        $packageInfo['dist'] = [
                            'type' => 'zip',
                            'url' => $archiveFile,
                            'reference' => $ref,
                            'shasum' => hash_file('sha256', $archiveFile),
                        ];
                    }
                }

                $packages[$packageName][$version] = $packageInfo;
            } catch (\Exception $e) {
                // Log error and continue with next version
                // In a real implementation, we'd want to use a proper logger
                error_log("Error parsing version $version: " . $e->getMessage());
                continue;
            }
        }

        return $packages;
    }

    /**
     * Clone or update a Git repository.
     *
     * @param string               $gitUrl  Git repository URL
     * @param array<string, mixed> $options Parser options
     *
     * @throws \RuntimeException                                             If Git operations fail
     * @throws \Symfony\Component\Process\Exception\LogicException           If process fails
     * @throws \Symfony\Component\Process\Exception\RuntimeException         If process execution fails
     * @throws \Symfony\Component\Process\Exception\InvalidArgumentException If process setup fails
     * @throws \Symfony\Component\Filesystem\Exception\IOException           If filesystem operations fail
     *
     * @return string Path to the cloned repository
     */
    private function cloneOrUpdateRepo(string $gitUrl, array $options = []): string
    {
        // Use MD5 of URL to ensure unique directory names even with auth tokens
        $repoName = md5($gitUrl);
        $repoDir = $this->tempDir . '/' . $repoName;

        if ($this->filesystem->exists($repoDir . '/.git')) {
            // Repository already exists, update it
            $process = new Process(['git', 'fetch', '--all', '--tags'], $repoDir);
            $process->setTimeout(300); // 5 minutes timeout for large repos
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException('Git fetch failed: ' . $process->getErrorOutput());
            }
        } else {
            // Clone the repository
            $process = new Process(['git', 'clone', $gitUrl, $repoDir]);
            $process->setTimeout(300); // 5 minutes timeout for large repos
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException('Git clone failed: ' . $process->getErrorOutput());
            }
        }

        return $repoDir;
    }

    /**
     * Get all relevant versions from a Git repository.
     *
     * @param string               $repoDir Path to the Git repository
     * @param array<string, mixed> $options Parser options
     *
     * @throws \Symfony\Component\Process\Exception\LogicException   If process fails
     * @throws \Symfony\Component\Process\Exception\RuntimeException If process execution fails
     *
     * @return array<string, string> Map of version names to Git references
     */
    private function getVersionsFromGit(string $repoDir, array $options = []): array
    {
        $versions = [];

        // Get all tags
        $process = new Process(['git', 'tag'], $repoDir);
        $process->run();

        if ($process->isSuccessful()) {
            $tags = array_filter(explode("\n", trim($process->getOutput())));
            foreach ($tags as $tag) {
                // Filter out non-semver tags if specified in options
                if (!empty($options['semver_only']) && !$this->isSemverTag($tag)) {
                    continue;
                }

                $version = ltrim($tag, 'v');
                $versions[$version] = $tag;
            }
        }

        // If requested, include dev versions from branches
        if (!empty($options['include_dev_versions'])) {
            $process = new Process(['git', 'branch', '-r'], $repoDir);
            $process->run();

            if ($process->isSuccessful()) {
                $branches = array_filter(explode("\n", trim($process->getOutput())));
                foreach ($branches as $branch) {
                    $branch = trim(str_replace('origin/', '', $branch));
                    if ($branch === 'HEAD') {
                        continue;
                    }

                    $devVersion = 'dev-' . $branch;
                    $versions[$devVersion] = $branch;
                }
            }
        }

        // Sort versions in descending order (newest first)
        krsort($versions);

        return $versions;
    }

    /**
     * Check if a tag follows Semantic Versioning.
     *
     * @param string $tag Git tag
     *
     * @return bool Whether the tag is a valid semver tag
     */
    private function isSemverTag(string $tag): bool
    {
        // Remove 'v' prefix if present
        $tag = ltrim($tag, 'v');

        // Simple semver regex - could be more comprehensive in a real implementation
        return (bool) preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/', $tag);
    }

    /**
     * Checkout a specific Git reference.
     *
     * @param string $repoDir Path to the Git repository
     * @param string $ref     Git reference (tag, branch, commit)
     *
     * @throws \RuntimeException                                     If checkout fails
     * @throws \Symfony\Component\Process\Exception\LogicException   If process fails
     * @throws \Symfony\Component\Process\Exception\RuntimeException If process execution fails
     */
    private function gitCheckout(string $repoDir, string $ref): void
    {
        $process = new Process(['git', 'checkout', $ref], $repoDir);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Git checkout of '$ref' failed: " . $process->getErrorOutput());
        }
    }

    /**
     * Parse a composer.json file from a local path.
     *
     * @param string               $path    Path to the directory containing composer.json
     * @param array<string, mixed> $options Parser options
     *
     * @throws \RuntimeException If parsing fails
     *
     * @return array<string, mixed> Package information
     */
    private function parseFromPath(string $path, array $options = []): array
    {
        $composerJsonPath = rtrim($path, '/') . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            throw new \RuntimeException("composer.json not found at: $composerJsonPath");
        }

        $packageInfo = $this->parseComposerJson($composerJsonPath);

        if (!$this->shouldIncludePackage($packageInfo, $options)) {
            return [];
        }

        $packageName = $packageInfo['name'] ?? null;
        if (!$packageName) {
            throw new \RuntimeException("Package name not found in composer.json: $composerJsonPath");
        }

        // For local paths, use the version from composer.json or default to dev-main
        $version = $packageInfo['version'] ?? 'dev-main';

        return [
            $packageName => [
                $version => $packageInfo,
            ],
        ];
    }

    /**
     * Parse a composer.json file and extract package information.
     *
     * @param string               $composerJsonPath Path to composer.json file
     * @param array<string, mixed> $additionalInfo   Additional package information
     *
     * @throws \RuntimeException If parsing fails
     *
     * @return array<string, mixed> Package information
     */
    private function parseComposerJson(string $composerJsonPath, array $additionalInfo = []): array
    {
        $content = file_get_contents($composerJsonPath);

        if ($content === false) {
            throw new \RuntimeException("Failed to read file: $composerJsonPath");
        }

        $composerJson = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in $composerJsonPath: " . json_last_error_msg());
        }

        // Merge additional info with the parsed package info
        return array_merge($composerJson, $additionalInfo);
    }

    /**
     * Determine if a package should be included based on filtering options.
     *
     * @param array<string, mixed> $packageInfo Package information
     * @param array<string, mixed> $options     Parser options
     *
     * @return bool Whether the package should be included
     */
    private function shouldIncludePackage(array $packageInfo, array $options = []): bool
    {
        // Skip if package type doesn't match filter
        if (!empty($options['type']) && ($packageInfo['type'] ?? null) !== $options['type']) {
            return false;
        }

        // Skip if package name doesn't match filter
        if (!empty($options['name_pattern'])) {
            $packageName = $packageInfo['name'] ?? '';
            if (!preg_match($options['name_pattern'], $packageName)) {
                return false;
            }
        }

        // Skip if custom filter callback returns false
        if (!empty($options['filter_callback']) && is_callable($options['filter_callback'])) {
            return (bool) $options['filter_callback']($packageInfo);
        }

        return true;
    }

    /**
     * Create a ZIP archive for a specific version.
     *
     * @param string $repoDir   Repository directory
     * @param string $name      Package name
     * @param string $version   Version string
     * @param string $outputDir Output directory for archives
     *
     * @return string|null Archive file path or null if creation fails
     */
    private function createArchive(string $repoDir, string $name, string $version, string $outputDir): ?string
    {
        $archiveFile = sprintf(
            '%s/%s-%s.zip',
            rtrim($outputDir, '/'),
            str_replace('/', '-', $name),
            $version
        );

        try {
            $process = new Process(['git', 'archive', '--format=zip', '--output=' . $archiveFile, $version], $repoDir);
            $process->setTimeout(300); // 5 minutes timeout for large repositories
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException('Failed to create archive: ' . $process->getErrorOutput());
            }

            return $archiveFile;
        } catch (\Exception $e) {
            error_log("Failed to create archive for $name@$version: " . $e->getMessage());
            return null;
        }
    }
}
