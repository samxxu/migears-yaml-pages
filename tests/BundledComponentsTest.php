<?php

declare(strict_types=1);

namespace MiGears\YamlPages\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Both frontend packages ship the same set of bundled components
 * (see spec.md §10, the copy note).
 *
 * "Edit one and you must sync the other" is a documented promise; this test
 * turns it into an executable check: byte-compare when both packages are
 * checked out as sibling directories, and skip when installed standalone.
 */
final class BundledComponentsTest extends TestCase
{
    private const SIBLING = '/migears-xml-pages/components';

    public function testBundledComponentsMatchTheOtherFrontend(): void
    {
        $ownDir = dirname(__DIR__) . '/components';
        $siblingDir = dirname(__DIR__, 2) . self::SIBLING;

        if (! is_dir($siblingDir)) {
            self::markTestSkipped('migears/xml-pages was not checked out in the same repo; skipping the copy-consistency check');
        }

        $own = $this->componentNames($ownDir);
        $sibling = $this->componentNames($siblingDir);

        self::assertNotEmpty($own);
        self::assertSame($own, $sibling, 'the two frontends must ship the same component manifests');

        foreach ($own as $name) {
            self::assertSame(
                file_get_contents($siblingDir . '/' . $name),
                file_get_contents($ownDir . '/' . $name),
                "$name differs from the migears/xml-pages copy — the two must be byte-for-byte identical"
            );
        }
    }

    /**
     * @return list<string>
     */
    private function componentNames(string $dir): array
    {
        $names = array_map('basename', glob($dir . '/*.php') ?: []);
        sort($names);

        return $names;
    }
}
