<?php

/*
 * This file is part of the Infection Mate Plugin.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WickedOne\InfectionMateExtension\Tests\Unit\Config;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use WickedOne\InfectionMateExtension\Config\ConfigurationDetector;

class ConfigurationDetectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('project')->url();
    }

    public function testDetectReturnsNullWhenNoConfigExists(): void
    {
        $this->assertNull($this->detector()->detect());
    }

    public function testDetectPrefersJson5OverJson(): void
    {
        $this->write('infection.json', '{}');
        $this->write('infection.json5', '{}');

        $this->assertSame('infection.json5', $this->detector()->detect());
    }

    public function testDetectPrefersJsonOverDistFiles(): void
    {
        $this->write('infection.json.dist', '{}');
        $this->write('infection.json5.dist', '{}');
        $this->write('infection.json', '{}');

        $this->assertSame('infection.json', $this->detector()->detect());
    }

    public function testDetectFallsBackToDistFiles(): void
    {
        $this->write('infection.json.dist', '{}');

        $this->assertSame('infection.json.dist', $this->detector()->detect());
    }

    public function testDetectFindsJson5DistBeforeJsonDist(): void
    {
        $this->write('infection.json.dist', '{}');
        $this->write('infection.json5.dist', '{}');

        $this->assertSame('infection.json5.dist', $this->detector()->detect());
    }

    public function testDetectIgnoresDirectoryWithConfigName(): void
    {
        mkdir($this->root.'/infection.json5');

        $this->assertNull($this->detector()->detect());
    }

    public function testReadReturnsEmptyArrayWhenNoConfigExists(): void
    {
        $this->assertSame([], $this->detector()->read());
    }

    public function testReadDecodesJson5WithCommentsAndTrailingCommas(): void
    {
        $this->write('infection.json5', <<<'JSON5'
            {
                // source directories to mutate
                "source": {
                    "directories": ["src",],
                },
                /* thresholds */
                "minMsi": 80,
                "minCoveredMsi": 90,
            }
            JSON5);

        $config = $this->detector()->read();

        $this->assertSame(['source' => ['directories' => ['src']], 'minMsi' => 80, 'minCoveredMsi' => 90], $config);
    }

    public function testReadDecodesPlainJson(): void
    {
        $this->write('infection.json', '{"timeout": 10, "logs": {"text": "build/infection.log"}}');

        $this->assertSame(['timeout' => 10, 'logs' => ['text' => 'build/infection.log']], $this->detector()->read());
    }

    public function testReadReturnsEmptyArrayWhenConfigIsNotAnObject(): void
    {
        $this->write('infection.json', '"just a string"');

        $this->assertSame([], $this->detector()->read());
    }

    public function testReadThrowsOnMalformedConfig(): void
    {
        $this->write('infection.json5', '{"source": ');

        $this->expectException(\Throwable::class);

        $this->detector()->read();
    }

    public function testBinaryFallsBackToPathLookupWithoutLocalInstall(): void
    {
        $this->assertSame(['infection'], $this->detector()->binary());
    }

    public function testBinaryUsesProjectLocalInstallWhenPresent(): void
    {
        $this->write('vendor/bin/infection', '#!/usr/bin/env php');

        $this->assertSame([\PHP_BINARY, $this->root.'/vendor/bin/infection'], $this->detector()->binary());
    }

    public function testReadReturnsEmptyArrayWhenConfigIsUnreadable(): void
    {
        $this->makeUnreadableConfig();

        $this->assertSame([], (new ConfigurationDetector($this->root))->read());
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->root.'/'.$relativePath;

        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o775, true);
        }

        file_put_contents($path, $contents);
    }

    private function makeUnreadableConfig(): void
    {
        vfsStream::newFile('infection.json5', 0o000)
            ->withContent('{"source": {"directories": ["src"]}}')
            ->chown(vfsStream::OWNER_USER_1)
            ->chgrp(vfsStream::GROUP_USER_1)
            ->at(vfsStream::setup('project'));
        $this->root = 'vfs://project';
    }

    private function detector(): ConfigurationDetector
    {
        return new ConfigurationDetector($this->root);
    }
}
