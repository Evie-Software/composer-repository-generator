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

namespace EvieSoftware\ComposerRepositoryGenerator\Tests\Integration;

use PHPUnit\Framework\TestCase;
use EvieSoftware\ComposerRepositoryGenerator\RepositoryGenerator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class PrivateRepositoryIntegrationTest extends TestCase
{
    private string $tempDir;
    private string $testRepoDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/composer-repo-test-' . uniqid();
        $this->testRepoDir = $this->tempDir . '/test-repo';

        mkdir($this->tempDir);
        mkdir($this->testRepoDir);

        // Set up a test Git repository
        $this->initializeTestRepository();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDirectory($this->tempDir);
    }

    public function testCompleteProxyWorkflow(): void
    {
        $outputDir = $this->tempDir . '/output';
        $generator = new RepositoryGenerator([
            'output_dir' => $outputDir,
            'proxy_private_packages' => true,
        ]);

        // Add our test repository as a source
        $generator->addSource($this->testRepoDir);

        // Generate the repository
        $packagesJsonPath = $generator->generate();

        // Verify the packages.json exists and is valid
        $this->assertFileExists($packagesJsonPath);
        $packagesJson = json_decode(file_get_contents($packagesJsonPath), true);
        $this->assertIsArray($packagesJson);
        $this->assertArrayHasKey('packages', $packagesJson);

        // Verify the archive was created
        $archiveDir = $outputDir . '/archives';
        $this->assertDirectoryExists($archiveDir);
        $archives = glob($archiveDir . '/*.zip');
        $this->assertNotEmpty($archives);

        // Test downloading the package with Composer
        $testProjectDir = $this->tempDir . '/test-project';
        mkdir($testProjectDir);

        // Create a composer.json that uses our repository
        file_put_contents($testProjectDir . '/composer.json', json_encode([
            'name' => 'test/project',
            'repositories' => [
                [
                    'type' => 'composer',
                    'url' => 'file://' . $outputDir,
                ],
            ],
            'require' => [
                'test/package' => '*',
            ],
        ]));

        // Try to install the package
        $process = new Process(['composer', 'install'], $testProjectDir);
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'Composer install failed: ' . $process->getErrorOutput());
        $this->assertDirectoryExists($testProjectDir . '/vendor/test/package');
    }

    private function initializeTestRepository(): void
    {
        // Initialize Git repository
        $process = new Process(['git', 'init'], $this->testRepoDir);
        $process->run();
        $this->assertTrue($process->isSuccessful());

        // Create test package files
        mkdir($this->testRepoDir . '/src', 0777, true);
        file_put_contents($this->testRepoDir . '/src/Package.php', '<?php namespace Test; class Package {}');

        // Create composer.json with GitHub source
        $composerJson = [
            'name' => 'test/package',
            'description' => 'Test package for integration testing',
            'version' => '1.0.0',
            'type' => 'library',
            'source' => [
                'type' => 'git',
                'url' => 'https://github.com/test/package.git',
                'reference' => 'main'
            ],
            'require' => [
                'php' => '>=8.3'
            ],
            'autoload' => [
                'psr-4' => [
                    'Test\\' => 'src/'
                ]
            ],
            'repositories' => [
                [
                    'type' => 'vcs',
                    'url' => 'https://github.com/private/repo.git'
                ]
            ]
        ];

        file_put_contents(
            $this->testRepoDir . '/composer.json',
            json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Set up Git config and make initial commit
        $process = new Process(['git', 'config', 'user.name', 'Test Author'], $this->testRepoDir);
        $process->run();
        $process = new Process(['git', 'config', 'user.email', 'test@example.com'], $this->testRepoDir);
        $process->run();

        // Add and commit files
        $process = new Process(['git', 'add', '.'], $this->testRepoDir);
        $process->run();
        $this->assertTrue($process->isSuccessful());

        $process = new Process(['git', 'commit', '-m', 'Initial commit'], $this->testRepoDir);
        $process->setEnv([
            'GIT_AUTHOR_NAME' => 'Test Author',
            'GIT_AUTHOR_EMAIL' => 'test@example.com',
            'GIT_COMMITTER_NAME' => 'Test Author',
            'GIT_COMMITTER_EMAIL' => 'test@example.com',
        ]);
        $process->run();
        $this->assertTrue($process->isSuccessful());

        // Create an annotated tag
        $process = new Process(['git', 'tag', '-a', 'v1.0.0', '-m', 'Version 1.0.0'], $this->testRepoDir);
        $process->run();
        $this->assertTrue($process->isSuccessful());
    }

    /**
     * Tests that multiple GitHub tokens work correctly.
     */
    public function testMultipleGitHubTokens(): void
    {
        $outputDir = $this->tempDir . '/output';

        // Create test repository with multiple source URLs
        $composerJson = [
            'name' => 'test/package',
            'description' => 'Test package for integration testing',
            'version' => '1.0.0',
            'type' => 'library',
            'require' => [
                'private/repo' => '*',
                'internal/package' => '*'
            ],
            'repositories' => [
                [
                    'type' => 'vcs',
                    'url' => 'https://github.com/private/repo.git'
                ],
                [
                    'type' => 'vcs',
                    'url' => 'https://github.internal.company.com/internal/package.git'
                ]
            ],
            'source' => [
                'type' => 'git',
                'url' => 'https://github.com/test/package.git',
                'reference' => 'v1.0.1'
            ]
        ];

        file_put_contents($this->testRepoDir . '/composer.json', json_encode($composerJson, JSON_PRETTY_PRINT));

        // Commit the changes
        $process = new Process(['git', 'add', 'composer.json'], $this->testRepoDir);
        $process->run();
        $this->assertTrue($process->isSuccessful());

        $process = new Process(['git', 'commit', '-m', 'Update dependencies'], $this->testRepoDir);
        $process->setEnv([
            'GIT_AUTHOR_NAME' => 'Test Author',
            'GIT_AUTHOR_EMAIL' => 'test@example.com',
            'GIT_COMMITTER_NAME' => 'Test Author',
            'GIT_COMMITTER_EMAIL' => 'test@example.com',
        ]);
        $process->run();
        $this->assertTrue($process->isSuccessful());

        $process = new Process(['git', 'tag', '-a', 'v1.0.1', '-m', 'Version 1.0.1'], $this->testRepoDir);
        $process->run();
        $this->assertTrue($process->isSuccessful());

        // Create and configure the repository generator
        $generator = new RepositoryGenerator([
            'output_dir' => $outputDir,
            'proxy_private_packages' => true,
            'github_tokens' => [
                'github.com' => 'public-token',
                'github.internal.company.com' => 'internal-token'
            ]
        ]);

        // Add token after initialization to test the addGitHubToken method
        $generator->addGitHubToken('another-token', 'github.other.com');

        // Generate the repository
        $generator->addSource($this->testRepoDir);
        $packagesJsonPath = $generator->generate();

        // Read and parse the generated packages.json
        $this->assertFileExists($packagesJsonPath);
        $contents = file_get_contents($packagesJsonPath);
        $this->assertNotFalse($contents);

        // The packages.json should contain authenticated URLs
        $this->assertStringContainsString(
            'public-token@github.com',
            $contents,
            'GitHub token not found in source URL'
        );

        // Verify archives were created
        $archiveDir = $outputDir . '/archives';
        $this->assertDirectoryExists($archiveDir);

        $archiveFiles = glob($archiveDir . '/*.zip');
        $this->assertNotEmpty($archiveFiles, 'No archive files were generated');
    }

    /**
     * Tests that the repository handles private package downloads correctly.
     */
    public function testPrivatePackageDownload(): void
    {
        $outputDir = $this->tempDir . '/output';

        // Create and configure the repository generator
        $generator = new RepositoryGenerator([
            'output_dir' => $outputDir,
            'proxy_private_packages' => true,
            'github_tokens' => [
                'github.com' => 'public-token'
            ]
        ]);

        // Add our test repository as a source
        $generator->addSource($this->testRepoDir);

        // Generate the repository
        $packagesJsonPath = $generator->generate();

        // Verify the packages.json exists and contains the correct structure
        $this->assertFileExists($packagesJsonPath);
        $packagesJson = json_decode(file_get_contents($packagesJsonPath), true);

        $this->assertIsArray($packagesJson);
        $this->assertArrayHasKey('packages', $packagesJson);

        // Get the first package
        $packageName = array_key_first($packagesJson['packages']);
        $package = $packagesJson['packages'][$packageName];
        $version = array_key_first($package);

        // Verify package has dist information
        $this->assertArrayHasKey('dist', $package[$version], 'Package is missing dist information');
        $this->assertEquals('zip', $package[$version]['dist']['type'], 'Package dist type should be zip');

        // The dist URL should be relative to the repository root
        $distUrl = $package[$version]['dist']['url'];
        $this->assertStringStartsWith('archives/', $distUrl, 'Dist URL should be relative to repository root');

        // Verify the archive exists
        $archivePath = $outputDir . '/' . $distUrl;
        $this->assertFileExists($archivePath, 'Archive file does not exist');

        // Set up a test project to verify package installation
        $testProjectDir = $this->tempDir . '/test-project';
        mkdir($testProjectDir);

        // Create a composer.json that requires our package
        file_put_contents($testProjectDir . '/composer.json', json_encode([
            'name' => 'test/project',
            'repositories' => [
                [
                    'type' => 'composer',
                    'url' => 'file://' . $outputDir,
                ],
            ],
            'require' => [
                $packageName => $version,
            ],
        ], JSON_PRETTY_PRINT));

        // Run composer install in the test project
        $process = new Process(['composer', 'install', '--no-interaction'], $testProjectDir);
        $process->setTimeout(300); // 5 minutes timeout
        $process->run();

        // Verify the installation was successful
        $this->assertTrue($process->isSuccessful(), 'Composer install failed: ' . $process->getErrorOutput());

        // Check that the package files were installed
        $vendorDir = $testProjectDir . '/vendor/' . str_replace('/', '/', $packageName);
        $this->assertDirectoryExists($vendorDir, 'Package directory not found in vendor');
        $this->assertFileExists($vendorDir . '/src/Package.php', 'Package source file not found');
    }

    /**
     * Tests that authentication tokens are properly applied to source URLs.
     */
    public function testAuthenticatedSourceUrls(): void
    {
        $outputDir = $this->tempDir . '/output';

        // Set up repository generator with multiple tokens
        $generator = new RepositoryGenerator([
            'output_dir' => $outputDir,
            'proxy_private_packages' => true,
            'github_tokens' => [
                'github.com' => 'public-token',
                'github.internal.company.com' => 'internal-token'
            ]
        ]);

        // Add our test repository as a source
        $generator->addSource($this->testRepoDir);

        // Generate the repository
        $packagesJsonPath = $generator->generate();

        // Read and verify packages.json
        $contents = file_get_contents($packagesJsonPath);
        $this->assertNotFalse($contents);

        $packagesJson = json_decode($contents, true);
        $this->assertIsArray($packagesJson);

        // Helper function to check URLs in package data
        $checkUrls = function($data) {
            if (isset($data['source']['url']) && str_contains($data['source']['url'], 'github.com')) {
                $this->assertStringContainsString(
                    'public-token@github.com',
                    $data['source']['url'],
                    'GitHub token not found in source URL'
                );
            }
            if (isset($data['source']['url']) && str_contains($data['source']['url'], 'github.internal.company.com')) {
                $this->assertStringContainsString(
                    'internal-token@github.internal.company.com',
                    $data['source']['url'],
                    'Internal GitHub token not found in source URL'
                );
            }
        };

        // Check all packages and versions
        foreach ($packagesJson['packages'] as $packageVersions) {
            foreach ($packageVersions as $versionData) {
                $checkUrls($versionData);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $objects = scandir($dir);
        if ($objects === false) {
            return;
        }

        foreach ($objects as $object) {
            if ($object === '.' || $object === '..') {
                continue;
            }

            $path = $dir . '/' . $object;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

