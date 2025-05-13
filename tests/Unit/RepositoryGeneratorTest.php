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

use EvieSoftware\ComposerRepositoryGenerator\PackageParser;
use EvieSoftware\ComposerRepositoryGenerator\RepositoryGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @covers \EvieSoftware\ComposerRepositoryGenerator\RepositoryGenerator
 */
class RepositoryGeneratorTest extends TestCase
{
    /** @var MockObject&Filesystem */
    private Filesystem $filesystem;

    /** @var MockObject&PackageParser */
    private PackageParser $packageParser;

    /** @var MockObject&LoggerInterface */
    private LoggerInterface $logger;

    private string $tempDir;

    /**
     * Set up test environment.
     *
 * @throws \PHPUnit\Framework\Exception
 * @throws \PHPUnit\Framework\InvalidArgumentException
 * @throws \PHPUnit\Framework\MockObject\Exception
 * @throws \PHPUnit\Event\NoPreviousThrowableException
 * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary directory for testing
        $this->tempDir = sys_get_temp_dir() . '/composer-repo-test-' . uniqid();
        mkdir($this->tempDir);
        mkdir($this->tempDir . '/cache');

        // Create mocks for dependencies
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->packageParser = $this->createMock(PackageParser::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * Clean up test environment.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up temporary directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    /**
 * @throws \PHPUnit\Framework\Exception
 * @throws \PHPUnit\Framework\MockObject\Exception
 * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function testCanBeInstantiated(): void
    {
        $config = [
            'output_dir' => $this->tempDir . '/output',
            'cache_dir' => $this->tempDir . '/cache',
        ];

        // Since we're using mocks, we need to expect the mkdir() call
        $this->filesystem->expects($this->once())
            ->method('mkdir')
            ->with([
                $config['output_dir'],
                $config['cache_dir'],
            ]);

        $generator = new RepositoryGenerator(
            config: $config,
            packageParser: $this->packageParser,
            filesystem: $this->filesystem,
            logger: $this->logger
        );

        $this->assertInstanceOf(RepositoryGenerator::class, $generator);
    }

    /**
 * @throws \PHPUnit\Framework\Exception
 * @throws \PHPUnit\Framework\ExpectationFailedException
 * @throws \PHPUnit\Framework\MockObject\Exception
 * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function testAddSource(): void
    {
        $config = [
            'output_dir' => $this->tempDir . '/output',
            'cache_dir' => $this->tempDir . '/cache',
        ];

        $this->filesystem->expects($this->once())
            ->method('mkdir');

        $generator = new RepositoryGenerator(
            config: $config,
            packageParser: $this->packageParser,
            filesystem: $this->filesystem,
            logger: $this->logger
        );

        $result = $generator->addSource('https://github.com/example/repo.git');

        // Test that the method returns $this for method chaining
        $this->assertSame($generator, $result);
    }

    /**
 * @throws \PHPUnit\Framework\Exception
 * @throws \PHPUnit\Framework\MockObject\Exception
 * @throws \RuntimeException
 * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function testGenerateThrowsExceptionWhenNoSourcesAdded(): void
    {
        $config = [
            'output_dir' => $this->tempDir . '/output',
            'cache_dir' => $this->tempDir . '/cache',
        ];

        $this->filesystem->expects($this->once())
            ->method('mkdir');

        $generator = new RepositoryGenerator(
            config: $config,
            packageParser: $this->packageParser,
            filesystem: $this->filesystem,
            logger: $this->logger
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No source repositories added');

        $generator->generate();
    }

    /**
     * This test demonstrates how to test a successful repository generation
     * using mocks to simulate the behavior of dependencies.
     *
 * @throws \PHPUnit\Framework\Exception
 * @throws \PHPUnit\Framework\ExpectationFailedException
 * @throws \PHPUnit\Framework\MockObject\Exception
 * @throws \RuntimeException
 * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function testGenerateSuccess(): void
    {
        $config = [
            'output_dir' => $this->tempDir . '/output',
            'cache_dir' => $this->tempDir . '/cache',
        ];

        // Expected packages data that would come from PackageParser
        $packages = [
            'example/package' => [
                '1.0.0' => [
                    'name' => 'example/package',
                    'version' => '1.0.0',
                    'type' => 'library',
                ],
            ],
        ];

        // Setup filesystem mock for directories
        $this->filesystem->expects($this->exactly(2))
            ->method('mkdir')
            ->willReturnCallback(function ($dirs) use ($config) {
                if (is_array($dirs)) {
                    $this->assertContains($config['output_dir'], $dirs);
                    $this->assertContains($config['cache_dir'], $dirs);
                } else {
                    $this->assertStringStartsWith($config['output_dir'], $dirs);
                }
                return true;
            });

        // Setup filesystem mock for file operations
        $this->filesystem->expects($this->atLeast(3))
            ->method('dumpFile')
            ->willReturnCallback(function ($path, $content) {
                $this->assertIsString($path);
                $this->assertNotEmpty($content);
                $decoded = json_decode($content, true);
                $this->assertIsArray($decoded);

                if (str_contains($path, 'packages.json')) {
                    $this->assertArrayHasKey('packages', $decoded);
                }

                return true;
            });

        // Setup package parser mock
        $this->packageParser->expects($this->once())
            ->method('parse')
            ->willReturn($packages);

        // Create the generator and add a source
        $generator = new RepositoryGenerator(
            config: $config,
            packageParser: $this->packageParser,
            filesystem: $this->filesystem,
            logger: $this->logger
        );

        $generator->addSource('https://github.com/example/repo.git');

        // Test the generate method
        $result = $generator->generate();

        // Verify the result is the path to packages.json
        $this->assertStringContainsString('packages.json', $result);
    }

    /**
     * Helper method to recursively remove a directory.
     *
     * @param string $dir Directory path to remove
     */
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
