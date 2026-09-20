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
    text: 你好');
        $this->assertSame('你好', $out);
    }

    public function testTextMultiLine(): void
    {
        $out = $this->compile("body:\n  - type: text\n    text: \"第一行\\n第二行\"");
        $this->assertSame("第一行\n第二行", $out);
    }

    public function testTextSingleInterpolation(): void
    {
        $out = $this->compile('body:
  - type: text
    text: 你好，{{ user.name }}');
        $this->assertSame('你好，## $user[\'name\'] ?? \'\' ##', $out);
    }

    public function testTextMultipleInterpolations(): void
    {
        $out = $this->compile("body:\n  - type: text\n    text: '{{ user.name }}（{{ user.age }}）'");
        $this->assertSame('## $user[\'name\'] ?? \'\' ##（## $user[\'age\'] ?? \'\' ##）', $out);
    }

    public function testHeadingDefaultLevel(): void
    {
        $out = $this->compile('body:
  - type: heading
    text: 用户管理');
        $this->assertSame('<h1>用户管理</h1>', $out);
    }

    public function testHeadingLevel(): void
    {
        $out = $this->compile('body:
  - type: heading
    level: 2
    text: 用户管理');
        $this->assertSame('<h2>用户管理</h2>', $out);
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
    text: 编辑');
        $this->assertSame('<a href="/users/## $user[\'id\'] ?? \'\' ##/edit">编辑</a>', $out);
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
        text: 欢迎');
        $this->assertSame(
            "<?php if (\$user['loggedIn'] ?? null): ?>\n欢迎\n<?php endif ?>",
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
        label: 姓名');
        $this->assertSame(
            "<form action=\"/users/save\" method=\"post\">\n  <label for=\"name\">姓名</label>\n  <input type=\"text\" name=\"name\" id=\"name\">\n</form>",
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
        label: 密码
        input: password
      - name: b
        label: 邮箱
        input: email
      - name: c
        label: 数量
        input: number
      - name: d
        label: 隐藏
        input: hidden
        value: user.token
      - name: e
        label: 保存
        input: submit');
        $this->assertStringContainsString('<input type="password" name="a" id="a">', $out);
        $this->assertStringContainsString('<input type="email" name="b" id="b">', $out);
        $this->assertStringContainsString('<input type="number" name="c" id="c">', $out);
        $this->assertStringContainsString('<input type="hidden" name="d" value="## $user[\'token\'] ?? \'\' ##">', $out);
        $this->assertStringContainsString('<input type="submit" value="保存">', $out);
    }

    public function testFormFieldValueBinding(): void
    {
        $out = $this->compile('body:
  - type: form
    action: /s
    fields:
      - name: name
        label: 姓名
        value: user.name
        required: true
        placeholder: 请输入');
        $this->assertStringContainsString(
            '<input type="text" name="name" id="name" value="## $user[\'name\'] ?? \'\' ##" placeholder="请输入" required>',
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
        label: 简介
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
        label: 角色
        input: select
        options:
          admin: 管理员
          user: 普通用户');
        $this->assertStringContainsString(
            '<select name="role" id="role">',
            $out
        );
        $this->assertStringContainsString('<option value="admin">管理员</option>', $out);
        $this->assertStringContainsString('<option value="user">普通用户</option>', $out);
    }

    public function testFormCheckboxChecked(): void
    {
        $out = $this->compile('body:
  - type: form
    action: /s
    fields:
      - name: active
        label: 启用
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

    public function testTableBindColumns(): void
    {
        $out = $this->compile('body:
  - type: table
    items: users
    columns:
      - label: ID
        bind: id
      - label: 姓名
        bind: name');
        $this->assertSame(
            "<table>\n<thead><tr><th>ID</th><th>姓名</th></tr></thead>\n<tbody>\n<?php foreach (\$users ?? [] as \$row): ?>\n<tr>\n<td>## \$row['id'] ?? '' ##</td>\n<td>## \$row['name'] ?? '' ##</td>\n</tr>\n<?php endforeach ?>\n</tbody>\n</table>",
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
        bind: id');
        $this->assertStringContainsString('<?php foreach ($users ?? [] as $user): ?>', $out);
        $this->assertStringContainsString("## \$user['id'] ?? '' ##", $out);
    }

    public function testTableContentColumn(): void
    {
        $out = $this->compile('body:
  - type: table
    items: users
    columns:
      - label: 操作
        content:
          - type: link
            href: /users/{{ user.id }}/edit
            text: 编辑');
        $this->assertStringContainsString(
            '<td><a href="/users/## $user[\'id\'] ?? \'\' ##/edit">编辑</a></td>',
            $out
        );
    }

    public function testTableEmptyText(): void
    {
        $out = $this->compile('body:
  - type: table
    items: users
    empty: 暂无数据
    columns:
      - label: ID
        bind: id');
        $this->assertStringContainsString(
            "<?php if ((\$users ?? []) === []): ?>\n<tr><td colspan=\"1\">暂无数据</td></tr>\n<?php else: ?>",
            $out
        );
        $this->assertStringContainsString('<?php endif ?>', $out);
    }

    public function testTableColumnBindAndContentConflict(): void
    {
        $this->expectError('body:
  - type: table
    items: users
    columns:
      - label: ID
        bind: id
        content:
          - type: text
            text: x', 'bind');
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
      text: 主体');
        $this->assertSame(
            "<?php \$this->extends('layout/admin') ?>\n\n<?php \$this->start('content') ?>\n主体\n<?php \$this->end() ?>\n",
            $out
        );
    }

    public function testLayoutWithTitleSection(): void
    {
        $out = $this->compile('title: 用户管理
layout: layout/admin
sections:
  content:
    - type: text
      text: 主体');
        $this->assertStringContainsString(
            "<?php \$this->start('title') ?>\n用户管理\n<?php \$this->end() ?>",
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
        $compiler->compileSource('title: 忽略
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
      title: 标题
      body: 简介');
        $this->assertSame(
            "<?= \$this->component('card', [\n    'title' => '标题',\n    'body' => '简介',\n]) ?>",
            $out
        );
    }

    public function testComponentInterpolatedData(): void
    {
        $out = $this->compile("body:\n  - type: component\n    name: card\n    data:\n      title: '{{ user.name }}'\n      body: '编辑 {{ user.name }} 的信息'");
        $this->assertSame(
            "<?= \$this->component('card', [\n    'title' => (\$user['name'] ?? ''),\n    'body' => '编辑 ' . (\$user['name'] ?? '') . ' 的信息',\n]) ?>",
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
        $this->expectError("body:\n  - type: text\n    text: '{{ user.name + 1 }}'", '非法路径');
    }

    public function testInvalidPath(): void
    {
        $this->expectError('body:
  - type: each
    items: 1users
    body: []', '路径');
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
        $this->expectError("body:\n  - type: each\n    items: '!users'\n    body: []", '非法路径');
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
        label: A', 'type 必须是 "field"');
    }

    public function testColumnTypeOptionalButMustMatch(): void
    {
        $out = $this->compile('body:
  - type: table
    items: users
    columns:
      - type: column
        label: ID
        bind: id');
        $this->assertStringContainsString('<th>ID</th>', $out);

        $this->expectError('body:
  - type: table
    items: users
    columns:
      - type: field
        label: ID
        bind: id', 'type 必须是 "column"');
    }

    public function testFieldLabelRejectsInterpolation(): void
    {
        $this->expectError('body:
  - type: form
    action: /s
    fields:
      - name: a
        label: "{{ user.name }}"', '不支持 {{ }} 插值');
    }

    public function testTableEmptyRejectsInterpolation(): void
    {
        $this->expectError('body:
  - type: table
    items: users
    empty: "{{ user.name }}"
    columns:
      - label: ID
        bind: id', '不支持 {{ }} 插值');
    }

    /* ---------------------------------------------------------------- *
     * 前端框架兼容：属性透传 / el
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
                '<h2' . $expected . '>标题</h2>',
                $this->compile("body:\n  - type: heading\n    level: 2\n    text: 标题\n    " . $yaml),
                "字段 {$yaml} 未按预期透传"
            );
        }
    }

    public function testPassthroughQuotedColonShorthand(): void
    {
        $out = $this->compile("body:\n  - type: link\n    href: /x\n    text: 去\n    \":href\": url");
        $this->assertSame('<a href="/x" :href="url">去</a>', $out);
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
            . "  - type: link\n    href: /x\n    text: 去\n    \"@click\": go()\n"
            . "  - type: form\n    action: /s\n    x-on:submit.prevent: save()\n    fields:\n"
            . "      - type: field\n        name: q\n        label: 查\n        x-model: kw\n"
            . "  - type: table\n    items: users\n    class: grid\n    columns:\n"
            . "      - type: column\n        label: ID\n        bind: id\n        class: w-8"
        );

        $this->assertStringContainsString('<a href="/x" @click="go()">去</a>', $out);
        $this->assertStringContainsString('<form action="/s" method="post" x-on:submit.prevent="save()">', $out);
        $this->assertStringContainsString('name="q" id="q" x-model="kw"', $out);
        $this->assertStringContainsString('<table class="grid">', $out);
        $this->assertStringContainsString('<td class="w-8">', $out);
    }

    public function testUnknownFieldRejected(): void
    {
        $this->expectError("body:\n  - type: heading\n    level: 2\n    text: T\n    levl: 3", '未知属性 "levl"');
    }

    public function testPassthroughOnTaglessNodeRejected(): void
    {
        $this->expectError("body:\n  - type: text\n    text: hi\n    class: box", '不输出标签');
    }

    public function testUnknownPageFieldRejected(): void
    {
        $this->expectError("titel: T\nbody:\n  - type: text\n    text: hi", '未知字段 "titel"');
    }

    public function testElNode(): void
    {
        $out = $this->compile("body:\n  - type: el\n    tag: section\n    class: card\n    body:\n      - type: heading\n        level: 2\n        text: 标题\n      - type: text\n        text: 正文");
        $this->assertSame("<section class=\"card\">\n<h2>标题</h2>\n正文\n</section>", $out);
    }

    public function testElEmptyBody(): void
    {
        $out = $this->compile("body:\n  - type: el\n    tag: div\n    x-ref: anchor");
        $this->assertSame('<div x-ref="anchor"></div>', $out);
    }

    public function testElMissingTag(): void
    {
        $this->expectError("body:\n  - type: el\n    class: x\n    body: []", '缺少 string 字段 "tag"');
    }

    public function testElInvalidTag(): void
    {
        $this->expectError("body:\n  - type: el\n    tag: 'DIV!'\n    body: []", '非法的 tag');
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
                $this->fail("{$field} 应当编译失败");
            } catch (CompileException $e) {
                $this->assertStringContainsString($suggested, $e->getMessage());
            }
        }
    }

    public function testHyphenEventAlsoSuggestsAtShorthand(): void
    {
        try {
            $this->compile("body:\n  - type: el\n    tag: div\n    x-on-click: go()\n    body: []");
            $this->fail('应当编译失败');
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
            $this->expectError("body:\n  - type: text\n    text: '" . $text . "'", '三个花括号');
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
            "layout: layout/main\nsections:\n  content: 不是数组",
            'section 的值必须是节点树数组'
        );
    }

    public function testSectionsValueNullRejected(): void
    {
        $this->expectError(
            "layout: layout/main\nsections:\n  content:",
            'section 的值必须是节点树数组'
        );
    }

    public function testColumnContentMustBeNodeTree(): void
    {
        $this->expectError(
            "body:\n  - type: table\n    items: u\n    columns:\n      - label: A\n        content: 裸文本",
            'content: 必须是节点树数组'
        );
    }

    private function compile(string $yaml): string
    {
        return $this->compiler->compileSource($yaml);
    }

    private function expectError(string $yaml, string $needle): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage($needle);
        $this->compile($yaml);
    }
}
