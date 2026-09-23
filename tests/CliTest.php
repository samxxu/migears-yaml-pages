<?php

declare(strict_types=1);

namespace MiGears\YamlPages\Tests;

use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private string $bin;

    protected function setUp(): void
    {
        $this->bin = dirname(__DIR__) . '/bin/yaml-pages';
    }

    public function testHelp(): void
    {
        [$output, $code] = $this->runCli(['--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('compile', $output);
    }

    public function testCompileSingleFile(): void
    {
        $dir = $this->tempDir();
        $source = $dir . '/hello.page.yaml';
        file_put_contents($source, 'body:
  - type: text
    text: Hello');

        [$output, $code] = $this->runCli(['compile', $source]);
        $this->assertSame(0, $code, $output);

        $this->assertFileExists($dir . '/hello.tpl.php');
        $this->assertSame('Hello', file_get_contents($dir . '/hello.tpl.php'));
    }

    public function testCompileDirectory(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/a.page.yaml', 'body:
  - type: text
    text: A');
        file_put_contents($dir . '/b.page.yaml', 'body:
  - type: text
    text: B');
        file_put_contents($dir . '/ignore.txt', 'not a page');

        [$output, $code] = $this->runCli(['compile', $dir]);
        $this->assertSame(0, $code, $output);
        $this->assertFileExists($dir . '/a.tpl.php');
        $this->assertFileExists($dir . '/b.tpl.php');
        $this->assertFileDoesNotExist($dir . '/ignore.tpl.php');
    }

    public function testCompileToOutputDir(): void
    {
        $srcDir = $this->tempDir();
        $outDir = $this->tempDir();
        file_put_contents($srcDir . '/a.page.yaml', 'body:
  - type: text
    text: A');

        [$output, $code] = $this->runCli(['compile', $srcDir . '/a.page.yaml', $outDir]);
        $this->assertSame(0, $code, $output);
        $this->assertFileExists($outDir . '/a.tpl.php');
        $this->assertFileDoesNotExist($srcDir . '/a.tpl.php');
    }

    public function testCheckDoesNotWrite(): void
    {
        $dir = $this->tempDir();
        $source = $dir . '/a.page.yaml';
        file_put_contents($source, 'body:
  - type: text
    text: A');

        [$output, $code] = $this->runCli(['compile', $source, '--check']);
        $this->assertSame(0, $code, $output);
        $this->assertFileDoesNotExist($dir . '/a.tpl.php');
    }

    public function testFailureExitCode(): void
    {
        $dir = $this->tempDir();
        $source = $dir . '/bad.page.yaml';
        file_put_contents($source, 'body: [');

        [$output, $code] = $this->runCli(['compile', $source]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('bad.page.yaml', $output);
    }

    public function testInvalidYamlKeepsGoingForDirectory(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/good.page.yaml', 'body:
  - type: text
    text: A');
        file_put_contents($dir . '/bad.page.yaml', 'body: [');
        file_put_contents($dir . '/bad2.page.yaml', 'body:
  - type: nope');

        [$output, $code] = $this->runCli(['compile', $dir]);
        $this->assertSame(1, $code);
        $this->assertFileExists($dir . '/good.tpl.php');
    }

    /**
     * @param list<string> $args
     * @return array{string, int}
     */
    private function runCli(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        return [implode("\n", $output), $code];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/yaml-pages-' . uniqid();
        mkdir($dir, 0755, true);
        $this->assertDirectoryExists($dir);
        return $dir;
    }
}
