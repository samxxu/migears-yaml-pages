<?php

declare(strict_types=1);

namespace MiGears\YamlPages\Tests;

use MiGears\YamlPages\Compiler;
use PHPUnit\Framework\TestCase;

/**
 * The parse-time severity rule cannot be reached from a document: no YAML source
 * makes libyaml emit a deprecation, and the rule is exactly about what should
 * happen when a future extension starts emitting one. It is probed through a
 * subclass instead of being left uncovered — the same reason the missing-extension
 * and decode_php guards run in a child PHP.
 */
final class ParseSeverityTest extends TestCase
{
    public function testOnlySeveritiesThatMeanLostDataBecomeCompileErrors(): void
    {
        foreach ([E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE] as $severity) {
            $this->assertTrue(
                SeverityProbe::reportsLostData($severity),
                'severity ' . $severity . ' means libyaml could not read the document'
            );
        }

        foreach ([E_DEPRECATED, E_USER_DEPRECATED, E_ERROR, E_USER_ERROR] as $severity) {
            $this->assertFalse(
                SeverityProbe::reportsLostData($severity),
                'severity ' . $severity . ' says nothing about this page'
            );
        }
    }
}

final class SeverityProbe extends Compiler
{
    public static function reportsLostData(int $severity): bool
    {
        return parent::reportsLostData($severity);
    }
}
