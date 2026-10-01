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

        // `yaml.decode_php` makes a `!php/object` tag call unserialize() on whatever
        // the document contains. A page declaration is data — and machine-written
        // declarations are this module's whole scenario — so that is an
        // object-injection vector rather than a feature. The directive is turned
        // off for this parse and restored afterwards, so the host's own setting
        // does not decide what a page can do. Two installations need nothing from
        // this: one that already has the directive off, and one whose ext-yaml has
        // no such directive at all (ini_get() answers false), which cannot honour a
        // `!php/object` tag in the first place. An installation that has it on and
        // will not let it be turned off is refused below, because a document from
        // it cannot be read safely whatever we do.
        $decodePhp = ini_get('yaml.decode_php');
        $forcedDecodePhp = false;
        // Read the setting the way ext-yaml reads it, not with a cast to int:
        // `(int) 'On'` is 0, so a host that spells it `On` — or `true` / `yes`,
        // the spellings a php.ini actually uses — used to slip past this guard,
        // leave the directive on, and let libyaml unserialize whatever the tag
        // carried. See iniIsEnabled().
        if (is_string($decodePhp) && self::iniIsEnabled($decodePhp)) {
            if (! function_exists('ini_set') || ini_set('yaml.decode_php', '0') === false) {
                throw new CompileException(
                    'yaml.decode_php is enabled and cannot be turned off for this parse; a page declaration must not '
                    . 'deserialize !php/object tags (set yaml.decode_php=0 in php.ini)'
                );
            }
            $forcedDecodePhp = true;
        }

        $warnings = [];
        $ndocs = 0;
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            // Every message is swallowed either way — the caller gets a compile
            // error, not parser chatter — but only the severities libyaml uses to
            // report a document it could not read faithfully become that error.
            // A deprecation from a future ext-yaml says nothing about this page,
            // and failing a build over it would let the extension's lifecycle leak
            // into the page author's.
            if (self::reportsLostData($severity)) {
                $warnings[] = $message;
            }
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
            if ($forcedDecodePhp) {
                ini_set('yaml.decode_php', (string) $decodePhp);
            }
        }

        // A warning that still leaves a tree behind is the dangerous case: the
        // document parsed, so it compiles, and whatever libyaml could not read is
        // simply gone. A merge key reports exactly that way — `<<: {class: box}`
        // warns and then returns the page without the merged attribute, so the
        // page renders and the class is missing with nothing to show for it.
        // Keeping every warning also means a failure names all of them instead of
        // only the last one.
        if ($warnings !== []) {
            $prefix = $documents === false
                ? 'YAML syntax error'
                : 'YAML parse error: part of the document would be dropped';
            throw new CompileException($prefix . ': ' . implode('; ', $warnings));
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
            // A sequence and a mapping are both `array` to gettype(), so the list
            // test is what tells them apart — and the message has to say which one
            // arrived, or a source that plainly wrote `- ` items is reported as an
            // "array" the author cannot see in what they wrote.
            $found = is_array($page) && array_is_list($page) ? 'a sequence (a list of items)' : gettype($page);
            throw new CompileException('YAML root must be a mapping (page object), got ' . $found
                . '; a page declaration is a mapping of title / layout / body / sections');
        }

        return $page;
    }

    /**
     * Whether a PHP severity means libyaml could not read the document the way it
     * was written — the case that must fail rather than compile into a page with
     * pieces missing. Warnings and notices are how libyaml reports that; anything
     * else is noise about the extension itself.
     */
    protected static function reportsLostData(int $severity): bool
    {
        return $severity === E_WARNING
            || $severity === E_NOTICE
            || $severity === E_USER_WARNING
            || $severity === E_USER_NOTICE;
    }

    /**
     * Whether a boolean ini setting means the directive is on. ext-yaml reads
     * `yaml.decode_php` with PHP's own boolean-ini rule — the words on / yes /
     * true are on, anything else is read as a number — so the same rule decides
     * whether a `!php/object` tag would be honoured. A bare `(int)` cast is not
     * that rule: it answers 0 for every alphabetic spelling, so the directive
     * would be left on and the tag decoded. Only the on-words and non-zero
     * numbers pass.
     */
    protected static function iniIsEnabled(string $value): bool
    {
        return in_array(strtolower($value), ['on', 'yes', 'true'], true)
            || (int) $value !== 0;
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
