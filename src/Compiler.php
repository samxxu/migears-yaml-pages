<?php

declare(strict_types=1);

namespace MiGears\YamlPages;

use MiGears\Pages\Compiler as PagesCompiler;
use MiGears\YamlPages\Exception\CompileException;

/**
 * YAML frontend: parses YAML page declarations into the array node model, then
 * hands them to the shared compiler in migears/pages.
 *
 * Everything after parsing — node compilation, interpolation, validation,
 * attribute forwarding — is inherited. All frontends therefore share one
 * compiler and one node model; what stays here is the YAML surface syntax.
 *
 * YAML can spell any attribute name once it is quoted ("@click", ":href",
 * "x-on:click"), so unlike the XML frontend this one needs no name mapping —
 * only a pointer for anyone arriving from XML with the '__click' spelling.
 */
class Compiler extends PagesCompiler
{
    /**
     * Turn YAML source into the array node model.
     *
     * yaml_parse() reports syntax problems through PHP warnings, so they are
     * captured here rather than left to pollute output.
     *
     * @return array<string, mixed>
     */
    protected function parse(string $source): array
    {
        $error = null;
        set_error_handler(static function (int $severity, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        try {
            $page = yaml_parse($source);
        } finally {
            restore_error_handler();
        }

        if ($page === false || $page === null) {
            throw new CompileException('YAML syntax error' . ($error !== null ? ': ' . $error : ''));
        }
        // A YAML sequence parses into a PHP list, which is an array too: without
        // the list test it slips past this guard and fails deeper as
        // `page: unknown field "0"` — naming a field the author never wrote. `[]` is
        // the empty mapping as much as the empty sequence, so it is left to the
        // "missing page content" path.
        if (! is_array($page) || ($page !== [] && array_is_list($page))) {
            throw new CompileException('YAML root must be a mapping (page object)');
        }

        return $page;
    }

    /**
     * Only one spelling needs translating here: '__event' is the XML variant's
     * way of writing '@event', because '@' is not a legal XML attribute name.
     * YAML writes it directly, so a migrant gets told that instead of a bare
     * "unknown attribute".
     */
    protected function mapAttributeName(string $name, string $path): string
    {
        if (str_starts_with($name, '__') && $name !== '__') {
            $this->error("{$path}: unknown attribute \"{$name}\"; __event is the XML variant's spelling, write \"@"
                . substr($name, 2) . '" directly in YAML (quoted)');
        }

        return parent::mapAttributeName($name, $path);
    }

    protected function newException(string $message): CompileException
    {
        return new CompileException($message);
    }
}
