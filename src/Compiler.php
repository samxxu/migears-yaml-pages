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
        $errors = [];
        set_error_handler(static function (int $severity, string $message) use (&$errors): bool {
            $errors[] = $message;
            return true;
        });
        try {
            $page = yaml_parse($source);
        } finally {
            restore_error_handler();
        }

        // A warning that still leaves a tree behind is the dangerous case: the
        // document parsed, so it compiles, and whatever libyaml could not read is
        // simply gone. A merge key reports exactly that way — `<<: {class: box}`
        // warns and then returns the page without the merged attribute, so the
        // page renders and the class is missing with nothing to show for it.
        // Keeping every warning also means a failure names all of them instead of
        // only the last one.
        if ($errors !== []) {
            $prefix = $page === false
                ? 'YAML syntax error'
                : 'YAML parse error: part of the document would be dropped';
            throw new CompileException($prefix . ': ' . implode('; ', $errors));
        }

        if ($page === null) {
            throw new CompileException('YAML document is empty; a page declaration must be a mapping');
        }
        // A YAML sequence parses into a PHP list, which is an array too: without
        // the list test it slips past this guard and fails deeper as
        // `page: unknown field "0"` — naming a field the author never wrote. `[]` is
        // the empty mapping as much as the empty sequence, so it is left to the
        // "missing page content" path.
        if (! is_array($page) || ($page !== [] && array_is_list($page))) {
            throw new CompileException('YAML root must be a mapping (page object), got ' . gettype($page)
                . '; a page declaration is a mapping of title / layout / body / sections');
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
