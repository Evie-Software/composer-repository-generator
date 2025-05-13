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

namespace EvieSoftware\ComposerRepositoryGenerator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use EvieSoftware\ComposerRepositoryGenerator\RepositoryGenerator;
use EvieSoftware\ComposerRepositoryGenerator\PackageParser;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class PrivateRepositoryTest extends TestCase
{
    private string $tempDir;
    /** @var MockObject&Filesystem */
    private Filesystem $filesystem;
    /** @var MockObject&PackageParser */
    private PackageParser $packageParser;
    /** @var MockObject&LoggerInterface */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/composer-repo-test-' . uniqid();
        mkdir($this->tempDir);
        mkdir($this->tempDir . '/cache');

        $this->filesystem = $this->createMock(Filesystem::class);
        $this->packageParser = $this->createMock(PackageParser::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDirectory($this->tempDir);
    }

    public function testGitHubTokenIsAddedToUrl(): void
    {
        $config = [
            'output_dir' => $this->tempDir . '/output',
            'github_tokens' => [
                'github.com' => 'test-token',
            ],
        ];

        // Setup filesystem expectations
        $this->filesystem->expects($this->any())
            ->method('mkdir')
            ->willReturn(null);

        $this->filesystem->expects($this->any())
            ->method('exists')
            ->willReturn(false);

        $this->filesystem->expects($this->any())
            ->method('dumpFile')
            ->willReturn(null);

        $generator = new RepositoryGenerator(
            config: $config,
            packageParser: $this->packageParser,
            filesystem: $this->filesystem,
            logger: $this->logger
        );

        // Setup package parser expectations
        $this->packageParser->expects($this->once())
            ->method('parse')
            ->with(
                $this->equalTo('https://test-token@github.com/org/repo.git'),
                $this->callback(function($options) {
                    return isset($options['semver_only']);
                })
            )
            ->willReturn([
                'example/package' => [
                    '1.0.0' => [
                        'name' => 'example/package',
                        'source' => [
                            'type' => 'git',
                            'url' => 'https://github.com/org/repo.git',
                            'reference' => 'v1.0.0'
                        ]
                    ]
                ]
            ]);

        $generator->addSource('https://github.com/org/repo.git');
        $generator->generate();
    }

    public function testProxiedPackagesIncludeArchives(): void
    {
        $config = [
            'output_dir' => $this->tempDir . '/output',
            'proxy_private_packages' => true,
        ];

        // Setup filesystem expectations
        $this->filesystem->expects($this->any())
            ->method('mkdir')
            ->willReturn(null);

        $this->filesystem->expects($this->any())
            ->method('exists')
            ->willReturn(false);

        $this->filesystem->expects($this->any())
            ->method('dumpFile')
            ->willReturn(null);

        $generator = new RepositoryGenerator(
            config: $config,
            packageParser: $this->packageParser,
            filesystem: $this->filesystem,
            logger: $this->logger
        );

        // Mock package data
        $packageData = [
            'example/package' => [
                '1.0.0' => [
                    'name' => 'example/package',
                    'version' => '1.0.0',
                    'dist' => [
                        'type' => 'zip',
                        'url' => 'archives/example-package-1.0.0.zip',
                        'reference' => 'v1.0.0'
                    ],
                    'source' => [
                        'type' => 'git',
                        'url' => 'https://github.com/org/repo.git',
                        'reference' => 'v1.0.0'
                    ],
                ],
            ],
        ];

        $this->packageParser->expects($this->once())
            ->method('parse')
            ->willReturn($packageData);

        // Verify that archive options are passed
        $this->packageParser->expects($this->once())
            ->method('parse')
            ->with(
                $this->anything(),
                $this->callback(function ($options) {
                    return isset($options['create_archives']) && $options['create_archives'] === true
                        && isset($options['archive_dir']);
                })
            );

        $generator->addSource('https://github.com/org/repo.git');
        $generator->generate();
    }

    /**
     * Tests that authentication works with custom GitHub hosts.
     */
    public function testCustomGitHubHostAuthentication(): void
    {
        $config = [
            'output_dir' => $this->tempDir . '/output',
            'github_tokens' => [
                'github.internal.company.com' => 'internal-token',
            ],
        ];

        // Setup filesystem expectations
        $this->filesystem->expects($this->any())
            ->method('mkdir')
            ->willReturn(null);

        $this->filesystem->expects($this->any())
            ->method('exists')
            ->willReturn(false);

        $this->filesystem->expects($this->any())
            ->method('dumpFile')
            ->willReturn(null);

        $generator = new RepositoryGenerator(
            config: $config,
            packageParser: $this->packageParser,
            filesystem: $this->filesystem,
            logger: $this->logger
        );

        // Setup package parser expectations for GitHub Enterprise URL
        $this->packageParser->expects($this->once())
            ->method('parse')
            ->with(
                $this->equalTo('https://internal-token@github.internal.company.com/org/repo.git'),
                $this->callback(function($options) {
                    return isset($options['semver_only']);
                })
            )
            ->willReturn([
                'example/package' => [
                    '1.0.0' => [
                        'name' => 'example/package',
                        'source' => [
                            'type' => 'git',
                            'url' => 'https://github.internal.company.com/org/repo.git',
                            'reference' => 'v1.0.0'
                        ]
                    ]
                ]
            ]);

        $generator->addSource('https://github.internal.company.com/org/repo.git');
        $generator->generate();
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

