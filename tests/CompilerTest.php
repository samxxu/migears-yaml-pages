<?php

declare(strict_types=1);

namespace MiGears\YamlPages\Tests;

use MiGears\YamlPages\Compiler;
use MiGears\YamlPages\Exception\CompileException;
use PHPUnit\Framework\TestCase;

final class CompilerTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
    }

    public function testTextPlain(): void
    {
        $out = $this->compile('body:
  - type: text
    text: Hello');
        $this->assertSame('Hello', $out);
    }

    public function testTextMultiLine(): void
    {
        $out = $this->compile("body:\n  - type: text\n    text: \"First line\\nSecond line\"");
        $this->assertSame("First line\nSecond line", $out);
    }

    public function testTextSingleInterpolation(): void
    {
        $out = $this->compile('body:
  - type: text
    text: Hello, {{ user.name }}');
        $this->assertSame('Hello, ## $user[\'name\'] ?? \'\' ##', $out);
    }

    public function testTextMultipleInterpolations(): void
    {
        $out = $this->compile("body:\n  - type: text\n    text: '{{ user.name }} ({{ user.age }})'");
        $this->assertSame('## $user[\'name\'] ?? \'\' ## (## $user[\'age\'] ?? \'\' ##)', $out);
    }

    public function testHeadingDefaultLevel(): void
    {
        $out = $this->compile('body:
  - type: heading
    text: User management');
        $this->assertSame('<h1>User management</h1>', $out);
    }

    public function testHeadingLevel(): void
    {
        $out = $this->compile('body:
  - type: heading
    level: 2
    text: User management');
        $this->assertSame('<h2>User management</h2>', $out);
    }

    public function testHeadingLevelOutOfRange(): void
    {
        $this->expectError('body:
  - type: heading
    level: 7
    text: x', 'level');
    }

    public function testLinkWithInterpolation(): void
    {
        $out = $this->compile('body:
  - type: link
    href: /users/{{ user.id }}/edit
    text: Edit');
        $this->assertSame('<a href="/users/## $user[\'id\'] ?? \'\' ##/edit">Edit</a>', $out);
    }

    public function testLinkTarget(): void
    {
        $out = $this->compile('body:
  - type: link
    href: /x
    text: x
    target: _blank');
        $this->assertSame('<a href="/x" target="_blank">x</a>', $out);
    }

    public function testLinkMissingHref(): void
    {
        $this->expectError('body:
  - type: link
    text: x', 'href');
    }

    public function testIfThen(): void
    {
        $out = $this->compile('body:
  - type: if
    when: user.loggedIn
    then:
      - type: text
        text: Welcome');
        $this->assertSame(
            "<?php if (\$user['loggedIn'] ?? null): ?>\nWelcome\n<?php endif ?>",
            $out
        );
    }

    public function testIfThenElse(): void
    {
        $out = $this->compile('body:
  - type: if
    when: user.loggedIn
    then:
      - type: text
        text: A
    else:
      - type: text
        text: B');
        $this->assertSame(
            "<?php if (\$user['loggedIn'] ?? null): ?>\nA\n<?php else: ?>\nB\n<?php endif ?>",
            $out
        );
    }

    public function testIfNegation(): void
    {
        $out = $this->compile('body:
  - type: if
    when: \'!user.hidden\'
    then:
      - type: text
        text: A');
        $this->assertStringContainsString(
            "<?php if (!(\$user['hidden'] ?? null)): ?>",
            $out
        );
    }

    public function testIfMissingWhen(): void
    {
        $this->expectError('body:
  - type: if
    then:
      - type: text
        text: A', 'when');
    }

    public function testEachBasic(): void
    {
        $out = $this->compile('body:
  - type: each
    items: users
    body:
      - type: text
        text: \'{{ item.name }}\'');
        $this->assertSame(
            "<?php foreach (\$users ?? [] as \$item): ?>\n## \$item['name'] ?? '' ##\n<?php endforeach ?>",
            $out
        );
    }

    public function testEachWithAsAndIndex(): void
    {
        $out = $this->compile('body:
  - type: each
    items: users
    as: user
    index: i
    body:
      - type: text
        text: \'{{ i }} {{ user.name }}\'');
        $this->assertStringContainsString(
            "<?php foreach (\$users ?? [] as \$i => \$user): ?>",
            $out
        );
    }

    public function testEachNested(): void
    {
        $out = $this->compile('body:
  - type: each
    items: groups
    as: group
    body:
      - type: each
        items: group.users
        as: user
        body:
          - type: text
            text: \'{{ user.name }}\'');
        $this->assertStringContainsString(
            "<?php foreach (\$groups ?? [] as \$group): ?>\n<?php foreach (\$group['users'] ?? [] as \$user): ?>",
            $out
        );
    }

    public function testEachMissingItems(): void
    {
        $this->expectError('body:
  - type: each
    body: []', 'items');
    }

    public function testFormBasic(): void
    {
        $out = $this->compile('body:
  - type: form
    action: /users/save
    fields:
      - name: name
        label: Name');
        $this->assertSame(
            "<form action=\"/users/save\" method=\"post\">\n  <label for=\"name\">Name</label>\n  <input type=\"text\" name=\"name\" id=\"name\">\n</form>",
            $out
        );
    }

    public function testFormFieldTypes(): void
    {
        $out = $this->compile('body:
  - type: form
    action: /s
    fields:
      - name: a
        label: Password
        input: password
      - name: b
        label: Email
        input: email
      - name: c
        label: Quantity
        input: number
      - name: d
        label: Hidden
        input: hidden
        value: user.token
      - name: e
        label: Save
        input: submit');
        $this->assertStringContainsString('<input type="password" name="a" id="a">', $out);
        $this->assertStringContainsString('<input type="email" name="b" id="b">', $out);
        $this->assertStringContainsString('<input type="number" name="c" id="c">', $out);
        $this->assertStringContainsString('<input type="hidden" name="d" value="## $user[\'token\'] ?? \'\' ##">', $out);
        $this->assertStringContainsString('<input type="submit" value="Save">', $out);
    }

    public function testFormFieldValueBinding(): void
    {
        $out = $this->compile('body:
  - type: form
    action: /s
    fields:
      - name: name
        label: Name
        value: user.name
        required: true
        placeholder: Enter');
        $this->assertStringContainsString(
            '<input type="text" name="name" id="name" value="## $user[\'name\'] ?? \'\' ##" placeholder="Enter" required>',
            $out
        );
    }

    public function testFormTextarea(): void
    {
        $out = $this->compile('body:
  - type: form
    action: /s
    fields:
      - name: bio
        label: About
        input: textarea
        rows: 4
        value: user.bio');
        $this->assertStringContainsString(
            '<textarea name="bio" id="bio" rows="4">## $user[\'bio\'] ?? \'\' ##</textarea>',
            $out
        );
    }

    public function testFormSelect(): void
    {
        $out = $this->compile('body:
  - type: form
    action: /s
    fields:
      - name: role
        label: Role
        input: select
        options:
          admin: Admin
          user: Standard user');
        $this->assertStringContainsString(
            '<select name="role" id="role">',
            $out
        );
        $this->assertStringContainsString('<option value="admin">Admin</option>', $out);
        $this->assertStringContainsString('<option value="user">Standard user</option>', $out);
    }

    public function testFormCheckboxChecked(): void
    {
        $out = $this->compile('body:
  - type: form
    action: /s
    fields:
      - name: active
        label: Enabled
        input: checkbox
        checked: user.active');
        $this->assertStringContainsString(
            '<input type="checkbox" name="active" id="active"<?= ($user[\'active\'] ?? null) ? \' checked\' : \'\' ?>>',
            $out
        );
    }

    public function testFormInvalidInput(): void
    {
        $this->expectError('body:
  - type: form
    action: /s
    fields:
      - name: a
        label: A
        input: color', 'input');
    }

    public function testFormSelectMissingOptions(): void
    {
        $this->expectError('body:
  - type: form
    action: /s
    fields:
      - name: a
        label: A
        input: select', 'options');
    }

    public function testFormOptionsOnUnsupportedInput(): void
    {
        $this->expectError('body:
  - type: form
    action: /s
    fields:
      - name: a
        label: A
        options:
          x: y', 'options');
    }

    public function testFormSelectWithValueRejected(): void
    {
        $this->expectError('body:
  - type: form
    action: /s
    fields:
      - name: a
        label: A
        input: select
        value: user.role
        options:
          x: y', 'value');
    }

    public function testTablePopColumns(): void
    {
        $out = $this->compile('body:
  - type: table
    items: users
    columns:
      - label: ID
        pop: "{{ row.id }}"
      - label: Name
        pop: "{{ row.name }}"');
        $this->assertSame(
            "<table>\n<thead><tr><th>ID</th><th>Name</th></tr></thead>\n<tbody>\n<?php foreach (\$users ?? [] as \$row): ?>\n<tr>\n<td>## \$row['id'] ?? '' ##</td>\n<td>## \$row['name'] ?? '' ##</td>\n</tr>\n<?php endforeach ?>\n</tbody>\n</table>",
            $out
        );
    }

    public function testTableCustomAs(): void
    {
        $out = $this->compile('body:
  - type: table
    items: users
    as: user
    columns:
      - label: ID
        pop: "{{ user.id }}"');
        $this->assertStringContainsString('<?php foreach ($users ?? [] as $user): ?>', $out);
        $this->assertStringContainsString("## \$user['id'] ?? '' ##", $out);
    }

    public function testTableContentColumn(): void
    {
        $out = $this->compile('body:
  - type: table
    items: users
    columns:
      - label: Actions
        content:
          - type: link
            href: /users/{{ user.id }}/edit
            text: Edit');
        $this->assertStringContainsString(
            '<td><a href="/users/## $user[\'id\'] ?? \'\' ##/edit">Edit</a></td>',
            $out
        );
    }

    public function testTableEmptyText(): void
    {
        $out = $this->compile('body:
  - type: table
    items: users
    empty: No data
    columns:
      - label: ID
        pop: "{{ row.id }}"');
        $this->assertStringContainsString(
            "<?php if ((\$users ?? []) === []): ?>\n<tr><td colspan=\"1\">No data</td></tr>\n<?php else: ?>",
            $out
        );
        $this->assertStringContainsString('<?php endif ?>', $out);
    }

    public function testTableColumnPopAndContentConflict(): void
    {
        $this->expectError('body:
  - type: table
    items: users
    columns:
      - label: ID
        pop: "{{ row.id }}"
        content:
          - type: text
            text: x', 'pop');
    }

    public function testTableMissingColumns(): void
    {
        $this->expectError('body:
  - type: table
    items: users', 'columns');
    }

    public function testBodyStandalone(): void
    {
        $out = $this->compile('body:
  - type: text
    text: A
  - type: text
    text: B');
        $this->assertSame("A\nB", $out);
    }

    public function testLayoutWithSections(): void
    {
        $out = $this->compile('layout: layout/admin
sections:
  content:
    - type: text
      text: Content');
        $this->assertSame(
            "<?php \$this->extends('layout/admin') ?>\n\n<?php \$this->start('content') ?>\nContent\n<?php \$this->end() ?>\n",
            $out
        );
    }

    public function testLayoutWithTitleSection(): void
    {
        $out = $this->compile('title: User management
layout: layout/admin
sections:
  content:
    - type: text
      text: Content');
        $this->assertStringContainsString(
            "<?php \$this->start('title') ?>\nUser management\n<?php \$this->end() ?>",
            $out
        );
    }

    public function testLayoutAndBodyConflict(): void
    {
        $this->expectError('layout: layout/admin
body:
  - type: text
    text: x
sections:
  content: []', 'body');
    }

    public function testLayoutWithoutSections(): void
    {
        $this->expectError('layout: layout/admin', 'sections');
    }

    public function testNoLayoutNoBody(): void
    {
        $this->expectError('{}', 'body');
    }

    public function testTitleWithoutLayoutWarns(): void
    {
        $warnings = [];
        $compiler = new Compiler(function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });
        $compiler->compileSource('title: ignored
body:
  - type: text
    text: A');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('title', $warnings[0]);
    }

    public function testComponentLiteralData(): void
    {
        $out = $this->compile('body:
  - type: component
    name: card
    data:
      title: Title
      body: About');
        $this->assertSame(
            "<?= \$this->component('card', [\n    'title' => 'Title',\n    'body' => 'About',\n]) ?>",
            $out
        );
    }

    public function testComponentInterpolatedData(): void
    {
        $out = $this->compile("body:\n  - type: component\n    name: card\n    data:\n      title: '{{ user.name }}'\n      body: 'Edit the info for {{ user.name }}'");
        $this->assertSame(
            "<?= \$this->component('card', [\n    'title' => (\$user['name'] ?? ''),\n    'body' => 'Edit the info for ' . (\$user['name'] ?? ''),\n]) ?>",
            $out
        );
    }

    public function testComponentNoData(): void
    {
        $out = $this->compile('body:
  - type: component
    name: badge');
        $this->assertSame("<?= \$this->component('badge') ?>", $out);
    }

    public function testUnknownNodeType(): void
    {
        $this->expectError('body:
  - type: marquee
    text: x', 'marquee');
    }

    public function testNodeMissingType(): void
    {
        $this->expectError('body:
  - text: x', 'type');
    }

    public function testInvalidInterpolation(): void
    {
        $this->expectError("body:\n  - type: text\n    text: '{{ user.name + 1 }}'", 'invalid path');
    }

    public function testInvalidPath(): void
    {
        $this->expectError('body:
  - type: each
    items: 1users
    body: []', 'invalid path');
    }

    public function testYamlSyntaxError(): void
    {
        $this->expectError('body: [', 'YAML');
    }

    public function testErrorCarriesNodePath(): void
    {
        try {
            $this->compile('layout: layout/admin
sections:
  content:
    - type: text
      text: A
    - type: wat
      text: x');
            $this->fail('Expected CompileException');
        } catch (CompileException $e) {
            $this->assertStringContainsString('sections.content[1]', $e->getMessage());
        }
    }

    public function testEachItemsRejectsNegation(): void
    {
        $this->expectError("body:\n  - type: each\n    items: '!users'\n    body: []", 'invalid path');
    }

    public function testFieldTypeOptionalButMustMatch(): void
    {
        $out = $this->compile('body:
  - type: form
    action: /s
    fields:
      - type: field
        name: a
        label: A');
        $this->assertStringContainsString('<input type="text" name="a" id="a">', $out);

        $this->expectError('body:
  - type: form
    action: /s
    fields:
      - type: column
        name: a
        label: A', 'type must be "field"');
    }

    public function testColumnTypeOptionalButMustMatch(): void
    {
        $out = $this->compile('body:
  - type: table
    items: users
    columns:
      - type: column
        label: ID
        pop: "{{ row.id }}"');
        $this->assertStringContainsString('<th>ID</th>', $out);

        $this->expectError('body:
  - type: table
    items: users
    columns:
      - type: field
        label: ID
        pop: "{{ row.id }}"', 'type must be "column"');
    }

    public function testFieldLabelRejectsInterpolation(): void
    {
        $this->expectError('body:
  - type: form
    action: /s
    fields:
      - name: a
        label: "{{ user.name }}"', 'does not support {{ }} interpolation');
    }

    public function testTableEmptyRejectsInterpolation(): void
    {
        $this->expectError('body:
  - type: table
    items: users
    empty: "{{ user.name }}"
    columns:
      - label: ID
        pop: "{{ row.id }}"', 'does not support {{ }} interpolation');
    }

    /* ---------------------------------------------------------------- *
     * Frontend framework compatibility: attribute passthrough / el
     * ---------------------------------------------------------------- */

    public function testPassthroughAtShorthandIsQuotedKey(): void
    {
        $out = $this->compile("body:\n  - type: el\n    tag: button\n    class: btn\n    \"@click\": open = ! open\n    \"@keydown.escape.window\": close()\n    body: []");
        $this->assertSame(
            "<button class=\"btn\" @click=\"open = ! open\" @keydown.escape.window=\"close()\"></button>",
            $out
        );
    }

    public function testPassthroughColonDirectiveNeedsNoQuotes(): void
    {
        $out = $this->compile("body:\n  - type: el\n    tag: button\n    x-on:click: go()\n    body: []");
        $this->assertSame('<button x-on:click="go()"></button>', $out);
    }

    public function testPassthroughPrefixesAndHtmlHooks(): void
    {
        $cases = [
            'hx-get: /x' => ' hx-get="/x"',
            'wire:click: save' => ' wire:click="save"',
            'v-on:click: go' => ' v-on:click="go"',
            'data-controller: menu' => ' data-controller="menu"',
            'class: box' => ' class="box"',
            'id: main' => ' id="main"',
            'style: \'color: red\'' => ' style="color: red"',
        ];
        foreach ($cases as $yaml => $expected) {
            $this->assertSame(
                '<h2' . $expected . '>Title</h2>',
                $this->compile("body:\n  - type: heading\n    level: 2\n    text: Title\n    " . $yaml),
                "field {$yaml} not forwarded as expected"
            );
        }
    }

    public function testPassthroughQuotedColonShorthand(): void
    {
        $out = $this->compile("body:\n  - type: link\n    href: /x\n    text: Go\n    \":href\": url");
        $this->assertSame('<a href="/x" :href="url">Go</a>', $out);
    }

    public function testPassthroughValueIsEscaped(): void
    {
        $out = $this->compile("body:\n  - type: heading\n    level: 2\n    text: T\n    class: 'a & b'");
        $this->assertSame('<h2 class="a &amp; b">T</h2>', $out);
    }

    public function testPassthroughKeepsSingleQuotesReadable(): void
    {
        $out = $this->compile("body:\n  - type: heading\n    level: 2\n    text: T\n    x-on:click: \"alert('hi')\"");
        $this->assertSame('<h2 x-on:click="alert(\'hi\')">T</h2>', $out);
    }

    public function testPassthroughValueSupportsInterpolation(): void
    {
        $out = $this->compile("body:\n  - type: el\n    tag: li\n    data-id: '{{ user.id }}'\n    body:\n      - type: text\n        text: hi");
        $this->assertStringContainsString('data-id="', $out);
        $this->assertStringContainsString("## \$user['id'] ?? '' ##", $out);
    }

    public function testPassthroughNormalisesScalars(): void
    {
        $out = $this->compile("body:\n  - type: el\n    tag: div\n    data-count: 7\n    data-on: true\n    x-cloak:\n    body: []");
        $this->assertSame('<div data-count="7" data-on="true" x-cloak=""></div>', $out);
    }

    public function testPassthroughOnLinkFormTableFieldColumn(): void
    {
        $out = $this->compile(
            "body:\n"
            . "  - type: link\n    href: /x\n    text: Go\n    \"@click\": go()\n"
            . "  - type: form\n    action: /s\n    x-on:submit.prevent: save()\n    fields:\n"
            . "      - type: field\n        name: q\n        label: Search\n        x-model: kw\n"
            . "  - type: table\n    items: users\n    class: grid\n    columns:\n"
            . "      - type: column\n        label: ID\n        pop: '{{ row.id }}'\n        class: w-8"
        );

        $this->assertStringContainsString('<a href="/x" @click="go()">Go</a>', $out);
        $this->assertStringContainsString('<form action="/s" method="post" x-on:submit.prevent="save()">', $out);
        $this->assertStringContainsString('name="q" id="q" x-model="kw"', $out);
        $this->assertStringContainsString('<table class="grid">', $out);
        $this->assertStringContainsString('<td class="w-8">', $out);
    }

    public function testUnknownFieldRejected(): void
    {
        $this->expectError("body:\n  - type: heading\n    level: 2\n    text: T\n    levl: 3", 'unknown attribute "levl"');
    }

    public function testPassthroughOnTaglessNodeRejected(): void
    {
        $this->expectError("body:\n  - type: text\n    text: hi\n    class: box", 'emits no tag');
    }

    public function testUnknownPageFieldRejected(): void
    {
        $this->expectError("titel: T\nbody:\n  - type: text\n    text: hi", 'unknown field "titel"');
    }

    public function testElNode(): void
    {
        $out = $this->compile("body:\n  - type: el\n    tag: section\n    class: card\n    body:\n      - type: heading\n        level: 2\n        text: Title\n      - type: text\n        text: Content");
        $this->assertSame("<section class=\"card\">\n<h2>Title</h2>\nContent\n</section>", $out);
    }

    public function testElEmptyBody(): void
    {
        $out = $this->compile("body:\n  - type: el\n    tag: div\n    x-ref: anchor");
        $this->assertSame('<div x-ref="anchor"></div>', $out);
    }

    public function testElBodyKeyLeftEmptyRejected(): void
    {
        // YAML invites leaving a key empty; a body that is present but null is a
        // mistake, not "no children" — drop the key, or write body: [].
        $this->expectError(
            "body:\n  - type: el\n    tag: div\n    body:\n",
            'must be a node tree array, got NULL'
        );
    }

    public function testElMissingTag(): void
    {
        $this->expectError("body:\n  - type: el\n    class: x\n    body: []", 'missing string field "tag"');
    }

    public function testElInvalidTag(): void
    {
        $this->expectError("body:\n  - type: el\n    tag: 'DIV!'\n    body: []", 'invalid tag');
    }

    public function testDunderPointsAtYamlSpelling(): void
    {
        $this->expectError("body:\n  - type: el\n    tag: div\n    __click: x\n    body: []", '"@click"');
    }

    public function testHyphenFormOfColonDirectiveRejected(): void
    {
        $cases = [
            'x-on-click: go()' => 'x-on:click',
            'x-bind-href: url' => 'x-bind:href',
            'x-transition-enter: fade' => 'x-transition:enter',
        ];
        foreach ($cases as $field => $suggested) {
            try {
                $this->compile("body:\n  - type: el\n    tag: div\n    " . $field . "\n    body: []");
                $this->fail("expected {$field} to fail to compile");
            } catch (CompileException $e) {
                $this->assertStringContainsString($suggested, $e->getMessage());
            }
        }
    }

    public function testHyphenEventAlsoSuggestsAtShorthand(): void
    {
        try {
            $this->compile("body:\n  - type: el\n    tag: div\n    x-on-click: go()\n    body: []");
            $this->fail('expected the compile to fail');
        } catch (CompileException $e) {
            $this->assertStringContainsString('"@click"', $e->getMessage());
        }
    }

    public function testColonlessXDirectivesStillForward(): void
    {
        $out = $this->compile("body:\n  - type: el\n    tag: div\n    x-show: open\n    x-data: '{ n: 1 }'\n    body: []");
        $this->assertStringContainsString('x-show="open"', $out);
        $this->assertStringContainsString('x-data="{ n: 1 }"', $out);
    }

    public function testTripleBraceRejected(): void
    {
        foreach (['{{{ user.name }}}', '{{ user.name }}}', '{{{ user.name }}'] as $text) {
            $this->expectError("body:\n  - type: text\n    text: '" . $text . "'", 'cannot run three braces');
        }
    }

    public function testAdjacentInterpolationsStillAllowed(): void
    {
        $out = $this->compile("body:\n  - type: text\n    text: '{{ a }}{{ b }}'");
        $this->assertSame("## \$a ?? '' #### \$b ?? '' ##", $out);
    }

    public function testSectionsValueMustBeNodeTree(): void
    {
        $this->expectError(
            "layout: layout/main\nsections:\n  content: not-an-array",
            'must be a node tree array, got string'
        );
    }

    public function testSectionsValueNullRejected(): void
    {
        $this->expectError(
            "layout: layout/main\nsections:\n  content:",
            'must be a node tree array, got NULL'
        );
    }

    public function testColumnContentMustBeNodeTree(): void
    {
        $this->expectError(
            "body:\n  - type: table\n    items: u\n    columns:\n      - label: A\n        content: bare-text",
            'must be a node tree array, got string'
        );
    }

    public function testColumnContentBareNodeMapRejected(): void
    {
        // YAML invites writing one node as a mapping; without the list wrapper
        // it is still an array, so the failure must name the missing list.
        $this->expectError(
            "body:\n  - type: table\n    items: u\n    columns:\n      - label: A\n        content:\n          type: text\n          text: x",
            'a list), but got a key-value map'
        );
    }

    public function testBodyMustBeNodeList(): void
    {
        $this->expectError('body: not-an-array', 'must be a node tree array, got string');
        $this->expectError("body:\n  type: text\n  text: x", 'a list), but got a key-value map');
    }

    public function testRootFieldsAreTypeChecked(): void
    {
        $this->expectError("layout:\n  - layout/main\nsections: {}", 'layout must be a string, got array');
        $this->expectError(
            "layout: layout/main\nsections: not-a-map",
            'sections must be a map of section name to node tree, got string'
        );
    }

    public function testStructuralChildrenMustBeNodeLists(): void
    {
        $this->expectError(
            "body:\n  - type: if\n    when: a\n    then:\n      type: text\n      text: x",
            'a list), but got a key-value map'
        );
        $this->expectError(
            "body:\n  - type: each\n    items: u\n    body:\n      type: text\n      text: x",
            'a list), but got a key-value map'
        );
    }

    public function testFormFieldsAndTableColumnsMustBeLists(): void
    {
        $this->expectError(
            "body:\n  - type: form\n    action: /s\n    fields:\n      name: a",
            'an array of fields (a list), but got a key-value map'
        );
        $this->expectError(
            "body:\n  - type: table\n    items: u\n    columns:\n      label: A\n      pop: '{{ row.id }}'",
            'an array of columns (a list), but got a key-value map'
        );
    }

    public function testFieldValueTypesAreChecked(): void
    {
        $this->expectError(
            "body:\n  - type: form\n    action: /s\n    fields:\n      - name: a\n        label: A\n        required: 'true'",
            'required must be a boolean, got string'
        );
        $this->expectError(
            "body:\n  - type: form\n    action: /s\n    fields:\n      - name: s\n        label: S\n        input: select\n        options:\n          a:\n            - x",
            'option "a" text must be a string, got array'
        );
    }

    public function testRootSequenceRejected(): void
    {
        // A YAML sequence is a list in PHP, so it used to slip past the root
        // guard and fail deeper as `unknown field "0"`.
        $this->expectError("- type: text\n  text: hi\n", 'YAML root must be a mapping (page object)');
    }

    public function testComponentDataMustBeAMapOfLiteralKeys(): void
    {
        $this->expectError(
            "body:\n  - type: component\n    name: card\n    data:\n      '{{ user.id }}': x\n",
            'is a literal field and does not support {{ }} interpolation'
        );
        $this->expectError(
            "body:\n  - type: component\n    name: card\n    data:\n      - x\n      - y\n",
            'component data must be a map of key => string (a key-value map), but got a list'
        );
    }

    public function testSectionsMustBeAMap(): void
    {
        $this->expectError(
            "layout: layout/main\nsections:\n  - type: text\n    text: x\n",
            'must be a map of section name to node tree (a key-value map), but got a list'
        );
    }

    public function testEmptyPathSegmentRejected(): void
    {
        $this->expectError("body:\n  - type: text\n    text: '{{ a..b }}'\n", 'invalid path "a..b"');
    }

    public function testOptionTextRejectsInterpolation(): void
    {
        $this->expectError(
            "body:\n  - type: form\n    action: /s\n    fields:\n      - name: s\n        label: S\n        input: select\n        options:\n          a: '{{ x }}'\n",
            'does not support {{ }} interpolation'
        );
    }

    public function testFormMethodRejectsNonStringValues(): void
    {
        // YAML parses a bare 123 into an int, so the shared type guard is
        // reachable from this frontend too — and must not leak a PHP warning.
        $this->expectError(
            "body:\n  - type: form\n    action: /s\n    method: 123\n    fields:\n      - name: a\n        label: A\n",
            'method must be the string "get" or "post", got integer'
        );
    }

    public function testTemplateMarkerInTextIsEscaped(): void
    {
        // Text goes through the shared interpolation helper, which escapes the template
        // marker, so the hashes are rendered as written instead of being evaluated.
        self::assertStringContainsString(
            '\##',
            $this->compile("body:\n  - type: text\n    text: '## note ##'\n")
        );
    }

    public function testSingleHashStaysLiteral(): void
    {
        self::assertStringContainsString(
            '# Level one heading',
            $this->compile("body:\n  - type: text\n    text: '# Level one heading'\n")
        );
    }

    public function testParseWarningsAreFatalBecauseTheyMeanLostContent(): void
    {
        // A merge key is the clearest case: libyaml warns, still hands back a tree,
        // and the merged attribute is simply gone — so the page used to compile
        // with no sign that anything had been dropped.
        $this->expectError(
            "body:\n  - type: heading\n    level: 2\n    text: T\n    <<: {class: box}\n",
            'part of the document would be dropped'
        );
    }

    public function testEmptyDocumentIsNotReportedAsASyntaxError(): void
    {
        // yaml_parse() answers null for an empty document. Calling that a syntax
        // error pointed at nothing: no line, no cause, no document.
        $this->expectError('', 'YAML document is empty');
        $this->expectError('~', 'YAML document is empty');
    }

    public function testScalarRootsNameTheirType(): void
    {
        // `false` is a complete YAML document rather than a parse failure, so it
        // belongs with the other non-mapping roots; only a genuine parse failure
        // reports a syntax error.
        $this->expectError('false', 'YAML root must be a mapping (page object), got boolean');
        $this->expectError('hello', 'YAML root must be a mapping (page object), got string');
    }

    public function testEmptyComponentDataCompilesWithoutAnArgumentArray(): void
    {
        // Shared with the other two frontends: an empty map used to emit
        // component('c', [ , ]), which is not valid PHP at all.
        self::assertSame(
            "<?= \$this->component('c') ?>",
            $this->compile("body:\n  - type: component\n    name: c\n    data: {}\n")
        );
        self::assertSame(
            "<?= \$this->component('c') ?>",
            $this->compile("body:\n  - type: component\n    name: c\n")
        );
    }

    /**
     * The missing-extension case the CLI preflights, reached through the library
     * API instead: an embedding build script gets a CompileException it can
     * report by type, not an Error that escapes as a fatal.
     *
     * An extension cannot be unloaded inside a running process, so the guard is
     * exercised in a child PHP where disable_functions has made yaml_parse()
     * vanish — which is what function_exists() answers to in either case.
     */
    public function testMissingYamlExtensionIsACompileErrorNotAnUncaughtError(): void
    {
        [$text, $code] = $this->compileInChild('yaml_parse');

        $this->assertSame(0, $code, $text);
        $this->assertStringStartsWith('CompileException: ext-yaml is not loaded', $text);
    }

    /**
     * Compile one page in a child PHP that has $function disabled, and report
     * what it raised.
     *
     * @return array{string, int}
     */
    private function compileInChild(string $function): array
    {
        $dir = sys_get_temp_dir() . '/yaml-pages-' . uniqid();
        mkdir($dir, 0755, true);
        $page = $dir . '/a.page.yaml';
        file_put_contents($page, "body:\n  - type: text\n    text: A\n");

        $probe = 'require $argv[1];'
            . ' try { (new MiGears\YamlPages\Compiler())->compileFile($argv[2]); echo "no exception"; }'
            . ' catch (MiGears\YamlPages\Exception\CompileException $e) { echo "CompileException: ", $e->getMessage(); }'
            . ' catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(); }';

        $cmd = escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('disable_functions=' . $function)
            . ' -r ' . escapeshellarg($probe)
            . ' ' . escapeshellarg(dirname(__DIR__) . '/vendor/autoload.php') . ' ' . escapeshellarg($page) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        return [implode("\n", $output), $code];
    }

    private function compile(string $yaml): string
    {
        return $this->compiler->compileSource($yaml);
    }

    private function expectError(string $yaml, string $needle): void
    {
        // try/catch rather than expectException(): a test that checks several
        // failures in one method would otherwise stop at the first one thrown and
        // leave every later case silently unasserted.
        try {
            $this->compile($yaml);
            $this->fail('should have failed to compile: ' . $needle);
        } catch (CompileException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }
}
