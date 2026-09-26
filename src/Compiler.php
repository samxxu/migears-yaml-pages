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
        // composer checks "ext-yaml" when installing, not when running, and the
        // extension is a PECL install that a deployment can simply lack. Naming
        // it here turns what would be an uncaught Error out of yaml_parse() into
        // a compile error the caller can report like any other.
        if (! function_exists('yaml_parse')) {
            throw new CompileException('ext-yaml is not loaded; yaml_parse() is required to read a page declaration (pecl install yaml)');
        }

        $errors = [];
        $ndocs = 0;
        set_error_handler(static function (int $severity, string $message) use (&$errors): bool {
            $errors[] = $message;
            return true;
        });
        try {
            // -1 asks for every document in the stream, which is the only way to
            // learn how many there are: $ndocs counts the documents read up to the
            // requested one, so asking for the first (the default) reports 1 no
            // matter what follows a separator — and everything after the first
            // '---' is dropped without a word.
            $documents = yaml_parse($source, -1, $ndocs);
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
            $prefix = $documents === false
                ? 'YAML syntax error'
                : 'YAML parse error: part of the document would be dropped';
            throw new CompileException($prefix . ': ' . implode('; ', $errors));
        }

        // A stream may hold several documents ('---' separated), but a page
        // declaration is exactly one of them. Compiling the first and dropping the
        // rest is the silent loss this frontend refuses everywhere else, so the
        // count is checked — including the trailing-separator case, where the
        // second document is empty and nothing would render from it.
        if ($ndocs > 1) {
            throw new CompileException(
                "YAML stream holds {$ndocs} documents; a page declaration is a single document (remove the --- separators)"
            );
        }

        // pos -1 wraps the stream's documents in a list; with one document that is
        // a one-element list. A document handed back unwrapped is left as it is
        // rather than assumed away.
        $page = is_array($documents) && array_is_list($documents) ? ($documents[0] ?? null) : $documents;

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
