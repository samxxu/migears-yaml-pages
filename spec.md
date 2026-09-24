# migears/yaml-pages Module Specification

Version: 2.0.0 (draft, pending review)
Date: 2026-09-20

## 1. Positioning

yaml-pages is an optional companion module of the miGears framework: a YAML-based declarative page definition tool that compiles page declarations into template files for migears/template (`.tpl.php` syntax). It is not a core component, does not take on any runtime responsibility, and only performs a compile-time "declaration → template" translation.

It is the **YAML syntax front-end** of `migears/pages`: after parsing `.page.yaml` into the array IR of the pages package, node compilation, validation, interpolation, and attribute passthrough are all handled by the shared compiler (the IR contract is described in migears/pages' spec.md). This package keeps only the YAML parsing layer and a few spelling hooks, and shares the same node vocabulary and compiled artifacts as `migears/xml-pages`.

It solves three problems:

1. **AI generation accuracy** — structured YAML declarations are more reliably generated error-free by large models than template code that mixes HTML/PHP.
2. **Page structure readability** — what a page looks like and which data it binds is clear at a glance from the YAML, and non-developers can participate too.
3. **Extends rather than replaces** — migears/template itself is minimal and limited in capabilities; yaml-pages uses a declarative abstraction layer to pin down the common page shapes (lists, forms, conditionals, loops), letting business development focus on data and structure.

## 2. Boundaries

### 2.1 In scope

- Page structure definition (node tree)
- Data binding (`{{ path }}` interpolation)
- Conditional display (`if`)
- Loops over lists (`each`, `table`)
- Form fields (`form` + `field`)
- Table column definitions (`table` + `column`)
- layout inheritance (`layout` + `sections`)
- Built-in components + custom component references
- Attribute passthrough (front-end framework directives plus `class` / `id` / `style` are emitted verbatim onto tags)
- The generic `el` node (an attribute container for things like `x-data`)

### 2.2 Out of scope (explicitly not done)

- Business logic, event handling, state management, routing — all stay out of YAML; these are handled by the front-end framework
- Runtime YAML parsing — compilation is the only entry point; runtime depends only on the generated template
- Third-party composer dependencies — the parsing layer uses `yaml_parse` from the PECL `ext-yaml` extension (`pecl install yaml`), bringing in no third-party composer packages

## 3. Core Principles

### 3.1 YAML is the single source of truth

Every change to a page goes back through YAML. The generated `.tpl.php` is a **derived file**: it can be overwritten by recompiling at any time and must not be hand-edited. The workflow is fixed: change YAML → run compilation → render.

### 3.2 Deliberate two-stage compilation

First compilation: yaml-pages parses the YAML declaration into the array IR of `migears/pages`, where the shared compiler turns it into `.tpl.php` sugar-syntax templates. This stage keeps the output readable — each DSL keyword maps visibly to the corresponding template syntax, so developers understand the declaration's semantics by reading the output and stay in control of the generated code.

Second compilation: migears/template's `TemplateCompiler` compiles the `.tpl.php` into a pure PHP template (mtime-cached, recompiled only after a template change). Rendering is done by PHP: at template runtime variables are output to the browser as HTML, and the declaration layer never enters runtime.

Both compilations have their own purpose; they are not merged and not skipped.

### 3.3 Extremely lightweight

The implementation stays within the same order of magnitude (this package's parsing layer is about 80 lines — the compilation logic all lives in the migears/pages shared layer, about 1050 lines; the CLI is about 110 lines; components are plain PHP template files). Any feature that would significantly bloat the implementation is rejected.

### 3.4 Compilation is validation

At compile time the structure, fields, paths, and keys are fully validated and **nothing is silently dropped**: unknown keys and misspelled directive names always raise an error rather than being quietly ignored. Whenever YAML cannot express a template capability, the compiler raises an error directly rather than inventing workaround syntax on the YAML side. Error messages must carry a node path so they are locatable.

## 4. Declaration Format

File extension is `.page.yaml`; the compiled artifact has the same name with `.tpl.php` (e.g. `users.page.yaml` → `users.tpl.php`).

The root mapping is a single page; no `type` field is needed:

```yaml
title: 用户管理
layout: layout/admin
sections:
  title: [...]
  content: [...]
```

### 4.1 Top-level fields

| Field | Type | Required | Description |
|------|------|------|------|
| `title` | string | no | Page title, written into the `title` section |
| `layout` | string | no | The inherited layout template name (e.g. `layout/admin`) |
| `body` | array | conditional | The page's body node tree when there is no `layout` |
| `sections` | object | conditional | When there is a `layout`, each section name → array of node trees |

Rule: when `layout` exists, `sections` is required and `body` is disallowed; when `layout` is absent, `body` is required and `sections` is disallowed. Violating this is a compile error.

The values of `body` and `sections` are all **node-tree arrays** ("nodes" for short). When `title` exists, a `title` section is auto-generated (only effective with a `layout`; without a layout it is ignored with a warning).

### 4.2 YAML writing notes

The parsing layer is libyaml (ext-yaml), which follows YAML 1.1. The following forms must be quoted, otherwise YAML mis-parses them:

| Scenario | Wrong spelling | Correct spelling |
|------|----------|----------|
| Value starts with `{` / `[` | `text: {{ user.name }}` | `text: '{{ user.name }}'` |
| Value starts with `!` (tag prefix) | `when: !user.hidden` | `when: '!user.hidden'` |
| Value contains `: ` (mapping separator) | `text: 时间: 12:00` | `text: '时间: 12:00'` |
| Value contains ` #` (comment prefix) | `text: a # b` | `text: 'a # b'` |
| **Key** starts with `@` or `:` | `@click: go()` | `"@click": go()` |

Other notes:

- `{{ ... }}` in the *middle* of a value (e.g. `/users/{{ user.id }}/edit`) can be written bare, without quotes.
- `true`/`false`/`yes`/`no`/`on`/`off` are booleans in YAML 1.1 — `required: true` is intentional boolean semantics; add quotes if you need a literal string.
- `level: 2` and `rows: 4` parse as integers, consistent with the `heading.level` and `textarea.rows` type checks.
- In double-quoted strings `\n` is a newline; use single quotes when you need the literal two characters `\n`.
- A key **containing** a colon (`x-on:click:`, `wire:click:`) can be written bare — a colon immediately followed by a non-whitespace character is not a mapping separator.
- Merge keys are not supported: `<<: {class: box}` makes libyaml warn (`expected a mapping for merging, but found scalar`) and hand back a page without those attributes. Since **any** parse warning fails the compilation (§9), spell the attributes out — `class: box` — instead of merging.

### 4.3 Attribute passthrough

Keys on a node are handled in three groups:

1. **DSL fields** — fields consumed by the node type itself (e.g. `heading.level`, `link.href`, `form.action`), including structural subkeys (`then`, `body`, `fields`, `columns`, `data`, `options`, `content`).
2. **Passthrough attributes** — emitted verbatim onto the tag produced by the node. The whitelist:
   - `@event` — the Alpine / Vue event shorthand, written `"@click"` (a key starting with `@` must be quoted)
   - directive names containing a colon: `x-on:click`, `x-bind:href`, `v-on:click`, `wire:click`, `on:click`, `:href` (a key starting with `:` is likewise quoted)
   - prefixes: `x-`, `v-`, `hx-`, `data-`
   - common HTML hooks: `class`, `id`, `style`, plus `bind` (the front-end framework's binding attribute, whose value is a browser-side variable name)
3. **Everything else is a compile error** — unknown keys are treated as typos and never dropped silently.

Nodes that emit no tag (`text`, `if`, `each`, `component`) do not accept passthrough attributes; wrap them in `el` instead. The page root is the same — it only recognizes `title` / `layout` / `body` / `sections`.

Passthrough attribute values are first HTML-attribute-escaped (`ENT_COMPAT`, keeping single quotes readable) and then `{{ }}`-interpolated — the order must not be reversed, or the quotes in the `## ##` sugar syntax would be broken by escaping. Scalar values are normalized into a form HTML attributes can carry: integer `7` → `"7"`, boolean `true` → `"true"`, empty value `x-cloak:` → `x-cloak=""` (the YAML spelling of a valueless attribute); non-scalars (maps/arrays) are an error.

**The only difference from the XML variant**: XML attribute names cannot hold `@`, so there `__click` stands for `@click`; YAML writes `"@click"` directly — **there is no `__` mapping**, nor is an `<attr>` node needed (a quoted YAML key can express any attribute name). Writing `__click` by mistake raises an error that tells you to use `"@click"`.

**Targeted error for hyphen forms**: `x-on-*`, `x-bind-*`, `x-transition-*` do not exist in Alpine (Alpine always uses the colon form). Because the `x-` prefix would ordinarily let these through, such spellings would be silently passed through, compile successfully, yet the directive would not work — so they are intercepted separately with advice:

```
body[0]: unknown attribute "x-on-click"; Alpine event/binding directives use the colon form, write "x-on:click" or "@click"
```

## 5. Data Binding Syntax

### 5.1 Path expressions

A path is the only carrier of data binding; its grammar is strict:

```
path   := segment ( "." segment )*
segment := [A-Za-z_][A-Za-z0-9_]*
```

The first segment is the variable name; subsequent segments are array-key accesses. Examples:

| Path | Compiles to |
|------|--------|
| `users` | `$users` |
| `user.name` | `$user['name']` |
| `form.errors.email` | `$form['errors']['email']` |

The compiled access uniformly carries `?? ''` (text/attribute context) or `?? null` (condition/loop context) as a fallback, to avoid warnings about undefined keys.

### 5.2 Interpolation `{{ path }}`

Text and attribute values support `{{ path }}` interpolation, compiled to **auto-escaped** output:

```yaml
- type: text
  text: 你好，{{ user.name }}
```

Compiles to:

```php
你好，## $user['name'] ?? '' ##
```

`## ##` is compiled by TemplateCompiler into `<?= $this->e($user['name'] ?? '') ?>`; the XSS protection is provided by the template engine.

Interpolation appears in only two contexts, compiled differently:

| Context | Compilation method | Example |
|--------|----------|------|
| HTML text / attribute (text, heading, link, etc.) | keep the `## expr ##` sugar as-is | `href="/users/## $user['id'] ?? '' ##"` |
| PHP array literal (a component's `data`) | string concatenation `'...' . ($expr) . '...'`, **no pre-escaping** | `'title' => '编辑 ' . ($user['name'] ?? '')` |

The PHP context must never emit `## ##` sugar — it would be re-substituted by TemplateCompiler into the PHP string literal, causing a syntax error.

**Escaping contract**: values in the PHP context reach the component unescaped; the escaping responsibility lies with the component template, which chooses `$this->e()` (text) or `$this->raw()` (trusted HTML) per field semantics. If the compiler pre-escaped, it would stack with the component template's escaping into double escaping (`&amp;lt;`).

Interpolation takes effect only in these two contexts. All other fields are **literal fields**: `layout`, section names, `form.method`, `field.name`, `field.label`, an option's value and display text, `table.empty`, `column.label`, `component.name`. These fields are emitted verbatim; writing `{{ }}` in them has no effect and is a compile error (no longer silently ignored).

### 5.3 Invalid paths and interpolation symbols

Anything inside `{{ ... }}` that does not match the path grammar (function calls, arithmetic, string literals, nested interpolation) is a compile error, reported with a node path.

Interpolation uses at most two braces: any occurrence of `{{{` or `}}}` is a compile error. Three braces fool the pairing count (in `{{{ a }}}` there is one `{{` and one `}}`, which looks paired), and the regex only matches the inner `{{ a }}`, leaving the remaining braces verbatim in the output, so the page would show scrambled `{` and `}`.

### 5.4 Data shape constraint

Paths compile to array access (`$user['name']`). Page data is defined as **array-shaped**, normalized at the boundary by the controller (Domain entities converted to arrays). This is a documented constraint; the module does not do object compatibility.

## 6. Node Vocabulary

Every node in body/sections must have a `type` field. There are 9 node types plus 2 nested structures; the nested structures (`field`, `column`) need not write `type` (the position already determines the type), but if written it must match the position:

| Node | Purpose |
|------|------|
| `text` | Text, supports interpolation |
| `heading` | Heading |
| `link` | Link |
| `if` | Conditional display |
| `each` | Loop over a list |
| `form` + `field` | Form and its fields |
| `table` + `column` | Table and its columns |
| `el` | Generic element container, carries attributes and a child node tree |
| `component` | References a built-in or custom component |

### 6.1 text

```yaml
- type: text
  text: 你好，{{ user.name }}
```

`text` is required, emitted verbatim (the literal part is author-controlled and may contain HTML). Interpolations are auto-escaped. Multi-line strings are allowed.

### 6.2 heading

```yaml
- type: heading
  level: 2
  text: 用户管理
```

`level` takes values 1–6, default 1; out of range is a compile error. Compiles to `<hN>...</hN>`.

### 6.3 link

```yaml
- type: link
  href: /users/{{ user.id }}/edit
  text: 编辑
```

`href` and `text` are required, both support interpolation (auto-escaped, safe in the attribute context). `target` is optional and supports interpolation, with no validation of its value — HTML allows named targets beyond `_blank`, and an enumerated whitelist would wrongly reject legitimate uses.

### 6.4 if

```yaml
- type: if
  when: user.loggedIn
  then:
    - type: text
      text: A
  else:
    - type: text
      text: B
```

`when` is required; the path may carry a leading `!` for negation; `then` is a required node tree; `else` is optional. Compiles to:

```php
<?php if ($user['loggedIn'] ?? null): ?>
  ...then...
<?php else: ?>
  ...else...
<?php endif ?>
```

The negated form `when: '!user.hidden'` (note the quotes) compiles to `<?php if (!($user['hidden'] ?? null)): ?>`.

### 6.5 each

```yaml
- type: each
  items: users
  as: user
  index: i
  body:
    - type: text
      text: '{{ user.name }}'
```

`items` is a required path, `as` defaults to `item`, `index` is optional. The `!` negation belongs only to `if.when`; a `!` on `items` is reported as an invalid path. Compiles to:

```php
<?php foreach ($users as $i => $user): ?>
  ...body...
<?php endforeach ?>
```

Nested `each` is allowed; an inner `as` with the same name naturally shadows according to PHP semantics.

### 6.6 form + field

```yaml
- type: form
  action: /users/save
  method: post
  fields:
    - name: name
      label: 姓名
      input: text
      value: user.name
      required: true
      placeholder: 请输入姓名
    - name: role
      label: 角色
      input: select
      options:
        admin: 管理员
        user: 普通用户
    - name: bio
      label: 简介
      input: textarea
      rows: 4
      value: user.bio
    - name: active
      label: 启用
      input: checkbox
      checked: user.active
    - name: submit
      label: 保存
      input: submit
```

**form**: `action` is required, `method` defaults to `post`, `fields` is a required array.

**field** fields:

| Field | Type | Required | Description |
|------|------|------|------|
| `name` | string | yes | Field name (the `name` / `id` attribute) |
| `label` | string | yes | Label text; for `submit` type it is the button text |
| `input` | enum | no | See below, default `text` |
| `value` | path | no | Bound value, compiled to `value="## $path ?? '' ##"`; not supported on `submit` (button text uses `label`) |
| `required` | bool | no | Default false; adds `required` on inputs that support the attribute; writing `true` on `hidden` / `submit` is a compile error |
| `placeholder` | string | no | Only text/password/email/number; on other inputs it is a compile error |
| `options` | object | select only | A mapping in the `admin: 管理员` form |
| `checked` | path | checkbox only | Outputs the `checked` attribute when truthy; on other inputs it is a compile error |
| `rows` | int | textarea only | Default 4; on other inputs it is a compile error |

The `input` enum: `text`, `password`, `email`, `number`, `textarea`, `select`, `checkbox`, `hidden`, `submit`. An invalid enum value is a compile error. A `select` missing `options`, `options` used on an unsupported input, or `value` used on a select are all compile errors.

The field **scope of use** is also a hard constraint; going out of range is a compile error (these fields used to be silently dropped): `placeholder` only on text/password/email/number, `checked` only on checkbox, `rows` only on textarea, `value` not supported on submit, `required` only on text/password/email/number/textarea/select/checkbox. When `required` is truthy, the `required` attribute is output on select/textarea/checkbox too.

`field` is a nested structure: it need not write `type` (the position is the type, equivalent to the `<field>` element name in the XML variant); if `type` is written, its value must be `field`, otherwise it is a compile error. The values of `name`, `label`, and `options`' values/text are literal fields and do not support `{{ }}` interpolation.

Example compiled output (excerpt):

```php
<form action="/users/save" method="post">
  <label for="name">姓名</label>
  <input type="text" name="name" id="name" value="## $user['name'] ?? '' ##" required>
  <label for="role">角色</label>
  <select name="role" id="role">
    <option value="admin">管理员</option>
    <option value="user">普通用户</option>
  </select>
  <input type="submit" value="保存">
</form>
```

### 6.7 table + column

```yaml
- type: table
  items: users
  as: user
  empty: 暂无数据
  columns:
    - label: ID
      pop: '{{ user.id }}'
    - label: 姓名
      pop: '{{ user.name }}'
    - label: 操作
      content:
        - type: link
          href: /users/{{ user.id }}/edit
          text: 编辑
```

`items` is required, `as` defaults to `row`, `empty` is optional (empty-list hint), `columns` is a required array. **column**: `label` is required; exactly one of `pop` (a data reference server-rendered into the cell, written `'{{ row.id }}'`) or `content` (a node tree in row-variable scope) is required, and providing both is a compile error. `pop` must include `{{ }}` and its first segment must equal the table's `as` variable.

`column` likewise need not write `type`; if written, the value must be `column`, otherwise it is a compile error. `label` and `empty` are literal text and do not support `{{ }}` interpolation.

Compiles to:

```php
<table>
<thead><tr><th>ID</th><th>姓名</th><th>操作</th></tr></thead>
<tbody>
<?php if (($users ?? []) === []): ?>
  <tr><td colspan="3">暂无数据</td></tr>
<?php else: ?>
<?php foreach ($users as $user): ?>
<tr>
<td>## $user['id'] ?? '' ##</td>
<td>## $user['name'] ?? '' ##</td>
<td><a href="/users/## $user['id'] ?? '' ##/edit">编辑</a></td>
</tr>
<?php endforeach ?>
<?php endif ?>
</tbody>
</table>
```

Nodes in `content` columns run in row-variable scope and can reference `user.*` directly.

### 6.8 component

```yaml
- type: component
  name: card
  data:
    title: '{{ user.name }}'
    body: 简介
```

`name` is required; `data` is an optional mapping whose values support interpolation (PHP-context concatenation compilation, no pre-escaping). An empty `data` mapping (`data: {}`) is equivalent to writing no `data` at all — both compile to the no-argument form `<?= $this->component('c') ?>` (behavior shared by all three front-ends: an empty mapping used to emit `component('c', [ , ])`, which is not valid PHP). Compiles to:

```php
<?= $this->component('card', [
    'title' => ($user['name'] ?? ''),
    'body' => '简介',
]) ?>
```

Interpolated values reach the component unescaped; the component template decides escaping (see the §5.2 escaping contract). Among the built-ins, `card.title`/`button.text`/`alert.text`/`badge.text` go through `$this->e()`, while `card.body` goes through `$this->raw()`.

### 6.9 el

```yaml
- type: el
  tag: div
  x-data: '{ open: false }'
  class: panel
  body:
    - type: heading
      level: 3
      text: '{{ user.name }}'
    - type: text
      text: 正文
```

`tag` is required (lowercase HTML tag name); `body` is the child node tree (may be omitted, treated as empty). Accepts any passthrough attribute. This is the only way to give attributes like `x-data` a mounting point — `text`/`if`/`each` do not emit tags themselves. Compiles to:

```php
<div x-data="{ open: false }" class="panel">
<h3>## $user['name'] ?? '' ##</h3>
正文
</div>
```

## 7. Component Mechanism

Built-in and custom components use the same mechanism: both are migears/template component template files, called at runtime by `$this->component('name', $data)`.

**Built-in components** (shipped with the package, template files in `components/`):

- `card` — card: `title`, `body`
- `button` — button: `text`, `href` (optional; renders `<button>` when there is no href), `type` (default `default`, may be `primary`)
- `alert` — alert bar: `type` (`info`/`success`/`warning`/`danger`, default `info`), `text`
- `badge` — label: `text`, `type` (a field of the same name as alert's, default `default`; the value is spliced verbatim into the class, with no enum validation)

The built-in component files are ordinary miGears/template components (output via `$this->e()`); users can read and copy-modify them directly.

**Custom components**: the user writes PHP template files per migears/template's component conventions — `.php` for the native spelling, `.tpl.php` for `## ##` sugar syntax (`## $expr ##` escaped, `### $expr ###` verbatim output, and it goes through TemplateCompiler to leave a compiled cache; `.tpl.php` wins over a same-named `.php`) — e.g. `components/my-card.php`, referenced in YAML as `- type: component, name: my-card`. No registration is needed; `name` is the template name. This package ships a runnable custom component example `examples/components/my-card.php`, referenced by name from `examples/full-featured.page.yaml`.

Runtime assembly: the page template needs to find the component files. The README explains adding the package's `components/` directory to the template search path via `$tpl->addPath()`, or copying it into the project's template directory. Resolution is decided by migears/template's `findTemplate()`: first `<path>/<name>.tpl.php`, then `<path>/<name>.php` (the `.tpl.php` pass walks all paths before the `.php` pass, so sugar-syntax files win), paths searched in reverse order of `addPath()` so later-added directories hit first — same-named files can therefore override built-in components (theme override); `name` may be a subdirectory path (`admin/table` matches `<path>/admin/table.php`); a missing file is not a compile error, and rendering throws `Component not found` only then.

## 8. CLI

Entry point `bin/yaml-pages` (a PHP shebang script):

```
php bin/yaml-pages compile <input> [output-dir] [--check]
php bin/yaml-pages --help
```

| Argument | Description |
|------|------|
| `compile` | Subcommand. `<input>` is a `.page.yaml` file or directory; a directory is processed recursively for all `.page.yaml` files |
| `[output-dir]` | Optional. Defaults to the same directory as the source (in-place generation); when given, output goes there keeping the same name |
| `--check` | Validate only, write nothing |
| `--help` | Usage description (standard help, no further subcommands) |

Behavior conventions:

- Output filename: `users.page.yaml` → `users.tpl.php`
- Existing artifacts are overwritten unconditionally (derived-file semantics)
- When processing a directory, reports per file `compiled: <source> -> <target>`; a failure does not interrupt the other files
- Exit code: 0 if all succeed; 1 if any fails
- An unrecognised `-`/`--option` is an error: it never falls through to the positional arguments, where a mistyped `--check` would silently become the output directory and turn a dry run into a real write
- An incomplete installation is reported before any file is read, so the message appears once instead of once per page: a `Compiler` class that cannot be autoloaded (a checkout where `composer install` never ran) and a missing `ext-yaml` each write one line to stderr and exit 1
- `--help` is answered before those checks, so help still works in an installation that cannot compile anything
- Any `Error` raised inside the compiler is caught at the top level and reported as `fatal: <message>` with exit code 1, keeping the exit-code contract instead of PHP's uncaught-fatal 255

## 9. Error Handling

All errors throw `CompileException` (extends `\RuntimeException`), which the CLI catches and prints to stderr in the format:

```
views/pages/users.page.yaml: sections.content[2]: unknown node type "foo"
```

Error categories and message requirements:

| Category | Detection | Example |
|------|------|------|
| YAML syntax error | `yaml_parse` returns false — a genuine parse failure only | YAML syntax error: ... |
| YAML parse warning | `yaml_parse` succeeds but emits one or more warnings — the tree it returned is missing part of the document | YAML parse error: part of the document would be dropped: ... |
| Empty document | the document is empty (`''` / `~` / `null`) | YAML document is empty; a page declaration must be a mapping |
| Root type error | root is not a mapping, naming the type actually received | YAML root must be a mapping (page object), got boolean |
| Structure error | a top-level rule is violated | both layout and body specified |
| Unknown node | type not in the vocabulary | unknown node type |
| Missing/invalid field | required field missing, enum out of range, type mismatch | if missing when; level is 7 |
| method type error | `form.method` is not a string (validated before any coercion, no leaked PHP warnings) | method must be string "get" or "post", got array |
| Path error | interpolation/path grammar mismatch | invalid path "user..name" |
| Context error | pop/content mutually exclusive etc. | column contains both pop and content; pop does not reference the row variable |
| Literal error | `{{ }}` written in a literal field | "empty" is a literal field, {{ }} interpolation not supported |
| Template-layer marker | `##` appears in a literal field (`label` / `name` / `tag` / `empty` / option etc.) — these fields are written verbatim into the output with no place to escape | body[0].fields[0]: "label" is a literal, "##" not allowed (template-layer syntax) |
| Nested-structure type error | field/column type does not match the position | type must be "field" |
| Unknown key | neither a DSL field of that node nor on the passthrough whitelist | unknown attribute "levl" |
| Brace mangling | interpolation has `{{{` or `}}}` | interpolation cannot use three consecutive braces |
| Root field type error | `layout` / `title` is not a string, `sections` is not a mapping | page: layout must be a string, got array |
| List-shape error | a node tree (value of `then` / `else` / `body` / `content` / `sections`) or `fields` / `columns` is written as a mapping | sections.content: must be a node-tree array (list), currently key-value mapping; wrap it in [ ] as a list |
| section value type error | one of `sections`' values is not a node-tree array (string / null) | sections.content: must be a node-tree array, got NULL |
| column value type error | `column.content` is not a node-tree array, or written as a single-node mapping (not wrapped in a `-` list) | columns[0].content: must be a node-tree array (list), currently key-value mapping |
| required type error | `field.required` is not a boolean (e.g. quoted `'true'`) | required must be a boolean, got string |
| option text type error | an option's display text is not a string | option "a" text must be a string, got array |
| Hyphen directive name | `x-on-*` / `x-bind-*` / `x-transition-*` (Alpine only has the colon form) | write "x-on:click" or "@click" |
| `__` misuse | `__event` is the XML variant's spelling | write "@click" directly in YAML (quoted) |
| Attribute without a mounting point | a passthrough attribute appears on a node that emits no tag | node "text" emits no tag, wrap the content with type: el |
| Attribute value type error | passthrough attribute value is not a scalar | attribute "x" value must be scalar, got array |

**Every parsing warning is fatal.** libyaml sometimes reports a warning yet still returns a (truncated) syntax tree. Those warnings used to be discarded, so the page compiled successfully while the affected part had quietly disappeared — the typical case is the merge key: `<<: {class: box}` emits `expected a mapping for merging, but found scalar` and then returns a page without that attribute. So now **any** warning from `yaml_parse()` fails the compilation, and **all** warnings are listed rather than only the last one. A real parse failure (`yaml_parse` returns `false`) is reported as `YAML syntax error: ...`; a successful parse that warned is reported as `YAML parse error: part of the document would be dropped: ...`.

**Empty and scalar documents are not syntax errors.** `''`, `~` and `null` are valid YAML documents that carry no mapping, so they report `YAML document is empty; a page declaration must be a mapping` instead of a syntax error. For the same reason a non-mapping root names the type it actually got: `false` (a complete YAML document in its own right, not a parse failure) reports `YAML root must be a mapping (page object), got boolean`, a string root reports `... got string`, and `YAML syntax error` stays reserved for genuine parse failures.

The compiler maintains a path from the root to each node (e.g. `sections.content[2]`), so errors always carry a path. When a YAML syntax error cannot be located to a node, the parser message plus the file path is output.

Fail-fast: the first error is thrown; the CLI continues processing the remaining files in the directory.

**An incomplete installation is reported rather than crashed into.** `Compiler::parse()` checks `function_exists('yaml_parse')` and raises a `CompileException` naming `ext-yaml` when it is absent: composer only validates the `ext-*` requirements at install time, and ext-yaml is a PECL install a deployment can simply lack — without the check the call itself raises an `Error` that a caller cannot catch by type. The CLI checks the same condition up front, together with the Composer autoloader, so the message is printed once rather than once per file; whatever still escapes is caught as `fatal: <message>` and reported with exit code 1.

## 10. Module Structure

```
migears-yaml-pages/
├── composer.json            name: migears/yaml-pages; require: php ^8.1, ext-yaml, migears/pages ^2.0
├── README.md                bilingual (中文/English), architecture, installation, quick start, YAML reference, error handling, testing notes
├── LICENSE
├── bin/
│   └── yaml-pages           CLI entry point
├── src/
│   ├── Compiler.php         YAML parsing layer (YAML → array IR, about 80 lines), extends the shared compiler of migears/pages
│   └── Exception/
│       └── CompileException.php
├── components/              built-in component templates
│   ├── card.php
│   ├── button.php
│   ├── alert.php
│   └── badge.php
├── examples/                full-featured examples (compilable and renderable)
│   ├── full-featured.page.yaml   covers all declaration syntax
│   ├── views/layout/main.php     companion minimal layout
│   └── components/my-card.php    custom component example, referenced by name from full-featured.page.yaml
└── tests/
    ├── CompilerTest.php
    ├── CliTest.php
    ├── IntegrationTest.php
    ├── BundledComponentsTest.php    cross-package copy consistency (validated on a monorepo checkout, skipped on standalone install)
    └── fixtures/
        ├── pages/           .page.yaml input samples
        └── views/           layouts for integration tests
```

composer dependency notes: what actually runs at runtime are the generated template and the built-in components, which all depend on migears/template; the compile time depends on migears/pages' shared compiler, hence it is set as `require` (the pages package itself declares migears/template). The parsing layer uses PECL ext-yaml's `yaml_parse` (`pecl install yaml`), with no third-party composer packages.

Copy note: `components/*.php`, `bin/yaml-pages`, and the other front-end `migears/xml-pages` are byte-for-byte identical (the four built-ins are byte-identical). This is a deliberately accepted cost — components must ship with the package to be found by `addPath`, and the CLI depends on each one's own parsing extension — but changing one place (e.g. badge's default type) must be mirrored in the other, and the component inventories and tests on both sides must be checked together. `tests/BundledComponentsTest.php` turns this constraint into an executable check: on a monorepo checkout it compares the component inventory and contents byte for byte, and skips on a standalone install (sibling package absent).

## 11. Test Plan (TDD)

Unit tests are driven by YAML strings/fixtures: feed a `.page.yaml` and assert the compiled output matches the expected `.tpl.php` exactly (or contains a given fragment).

Regression tests of the shared compilation layer (base behavior of node grammar, interpolation, passthrough, validation) are handled by migears/pages' CompilerTest; this package's tests focus on YAML parsing and the overall behavior after inheritance.

| Group | Cases |
|------|------|
| Text | text plain / single interpolation / multiple interpolation / multi-line |
| Structure | each heading level, out-of-range level error; link href/text interpolation |
| Conditional | if then / if then+else / `!` negation / missing when error |
| Loop | each basic / index / nested / missing items error |
| Form | each input enum / select options / checkbox checked / submit / invalid enum / select missing options / options on an unsupported input / method non-string reports a type error without leaking PHP warnings / required non-boolean / option text non-string |
| Table | pop columns (`{{ row.x }}`) / content columns / empty / as default and custom / pop+content both present error / missing columns error / columns written as a mapping error |
| Layout | layout+sections / standalone body / both present error / both missing error / title section |
| Component | no data / data interpolation (PHP-context concatenation) / data literal |
| Binding | path-grammar boundaries (invalid characters, empty segment, `!` only allowed on when) |
| Negation boundary | `each.items` with `!` errors (`!` belongs only to `if.when`) |
| Nested structures | `type: field` / `type: column` written correctly passes, written as the other errors |
| Literal | `{{ }}` in literal fields such as `field.label`, `table.empty`, `option` errors |
| Template-layer marker | `##` in text is escaped per template-layer syntax (output contains `\##`); a single `#` needs no escaping (shared layer, reachable from the front-ends too) |
| Parsing | YAML syntax error errors, non-mapping root errors, an empty document is not a syntax error, and an empty mapping reaches the page-content rule |
| Passthrough | Alpine / Vue / htmx / Livewire directives and `class`/`id`/`style` passthrough; `"@click"` quoted key; `x-on:click` bare key; value escaping; interpolation inside values; scalar normalization (integer/boolean/empty value) |
| Passthrough misuse | unknown key errors; attributes on tag-less nodes (`text`/`if`/`each`/`component`) errors; unknown root field errors |
| el | with body / empty body / missing tag error / invalid tag error |
| Cross-variant hints | `__click` errors with a hint to rewrite `"@click"`; `x-on-click` errors with a hint to `x-on:click` or `@click` |
| Interpolation symbols | `{{{ a }}}` / `{{ a }}}` / `{{{ a }}` errors; adjacent `{{ a }}{{ b }}` still passes |
| section value type | `sections`' value not an array (string/null) gives a readable error rather than a PHP TypeError |
| column value type | `column.content` not an array, or written as a single-node mapping, gives a readable error rather than a PHP TypeError or `content[type]: 节点必须是对象` |
| Root and list shape | `body` not an array or written as a single mapping, `layout` not a string, `sections` not a mapping; `then` / `each.body` / `fields` / `columns` written as mappings give readable errors |
| CLI | single-file compile / directory recursion / output-dir / --check / --help / unknown option rejected / failure exit code |
| Installation | missing Composer autoloader / missing `ext-yaml` / an unexpected `Error`: one stderr line, exit code 1, no stack trace; `--help` still answers |
| Integration | compiled artifact renders successfully after second compilation via TemplateCompiler (interop with migears/template) |
| Copy consistency | built-in components byte-for-byte identical to `migears/xml-pages` (validated on a monorepo checkout, skipped on standalone install) |

## 12. Explicitly Not Done (future candidates)

- Event handling, state management, routing — never enter
- Custom components authored in YAML (components exist only as PHP templates)
- Expression-language extensions (arithmetic, functions, ternaries)
- Runtime YAML parsing / hot reload
- HTML form controls beyond `input` (file upload, date picker, etc.)

---

# migears/yaml-pages 模块规格说明

版本：2.0.0（草案，待评审）
日期：2026-09-20

## 1. 定位

yaml-pages 是 miGears 框架的可选配套模块：一种基于 YAML 的声明式页面定义工具，把页面声明编译为 migears/template 的模板文件（`.tpl.php` 语法）。它不是核心组件，不承担运行期职责，只做编译期的"声明 → 模板"翻译。

它是 `migears/pages` 的 **YAML 语法前端**：把 `.page.yaml` 解析成 pages 包的数组 IR 后，节点编译、校验、插值、属性透传全部由共享编译器完成（IR 契约见 migears/pages 的 spec.md）。本包只保留 YAML 解析层与少量拼写钩子，与 `migears/xml-pages` 共享同一套节点词表与编译产物。

它解决三个问题：

1. **AI 生成准确率**——结构化的 YAML 声明比混合 HTML/PHP 的模板代码更容易被大模型无差错地生成。
2. **页面结构可读**——页面长什么样、绑定了哪些数据，扫一眼 YAML 就清楚，非开发者也能参与。
3. **拓展而非替代**——migears/template 本身极简、能力有限；yaml-pages 用一层声明式抽象把常用页面形态（列表、表单、条件、循环）固定下来，让业务开发聚焦在数据与结构上。

## 2. 边界

### 2.1 范围内

- 页面结构定义（节点树）
- 数据绑定（`{{ path }}` 插值）
- 条件显示（`if`）
- 循环列表（`each`、`table`）
- 表单字段（`form` + `field`）
- 表格列定义（`table` + `column`）
- layout 继承（`layout` + `sections`）
- 内置组件 + 自定义组件引用
- 属性透传（前端框架指令与 `class` / `id` / `style` 原样输出到标签）
- 通用元素节点 `el`（承载 `x-data` 之类的属性容器）

### 2.2 范围外（明确不做）

- 业务逻辑、事件处理、状态管理、路由定义——一律不进 YAML；这些由前端框架承担
- 运行期解析 YAML——编译是唯一入口，运行期只依赖生成的模板
- composer 第三方依赖——解析层用 PECL 扩展 ext-yaml 的 `yaml_parse`（`pecl install yaml`），不引入任何 composer 第三方包

## 3. 核心原则

### 3.1 YAML 是唯一事实标准

页面的一切修改都回到 YAML 完成。生成的 `.tpl.php` 是**派生文件**，可随时被重新编译覆盖，不应被手工修改。工作流固定为：改 YAML → 运行编译 → 渲染。

### 3.2 刻意两次编译

第一次编译：yaml-pages 把 YAML 声明解析为 `migears/pages` 的数组 IR，由共享编译器翻译为 `.tpl.php` 糖语法模板。这一步保留产物可读性——每个 DSL 词汇对应什么模板语法一目了然，开发者通过读产物理解声明语义、掌控生成代码。

第二次编译：migears/template 的 `TemplateCompiler` 把 `.tpl.php` 编译成纯 PHP 模板文件（mtime 缓存，仅模板变更后重编一次）。渲染由 PHP 执行：模板运行时把变量以 HTML 形式输出给浏览器，声明层不进入运行期。

两次编译各有意义，不合并、不省略。

### 3.3 极轻量

实现规模保持在同一量级（本包解析层约 80 行——编译逻辑全部在 migears/pages 共享层约 1050 行；CLI 约 110 行，组件为纯模板 PHP 文件）。任何让实现显著膨胀的特性都拒绝。

### 3.4 编译即校验

编译期对结构、字段、路径、键做完整校验，**不静默丢弃**：未知键、拼错的指令名一律报错，而不是被悄悄忽略。凡是 YAML 表达不了模板能力的场景，编译期直接报错，不在 YAML 侧发明变通语法。错误信息必须带节点路径，可定位。

## 4. 声明格式

文件扩展名 `.page.yaml`，编译产物同名 `.tpl.php`（如 `users.page.yaml` → `users.tpl.php`）。

根映射即为一个页面，无需 `type` 字段：

```yaml
title: 用户管理
layout: layout/admin
sections:
  title: [...]
  content: [...]
```

### 4.1 顶层字段

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `title` | string | 否 | 页面标题，写入 `<title>` section |
| `layout` | string | 否 | 继承的布局模板名（如 `layout/admin`） |
| `body` | array | 视情况 | 无 `layout` 时的页面主体节点树 |
| `sections` | object | 视情况 | 有 `layout` 时，各 section 名 → 节点树数组 |

规则：`layout` 存在时 `sections` 必填、`body` 禁用；`layout` 不存在时 `body` 必填、`sections` 禁用。违反即编译错误。

`body` 与 `sections` 值均为**节点树数组**（下称"节点"）。`title` 存在时自动生成一个 `title` section（仅在有 `layout` 时生效，无 layout 时忽略并告警）。

### 4.2 YAML 编写注意

解析层是 libyaml（ext-yaml），遵循 YAML 1.1。以下写法必须引号包裹，否则会被 YAML 误解析：

| 场景 | 错误写法 | 正确写法 |
|------|----------|----------|
| 值以 `{` / `[` 开头 | `text: {{ user.name }}` | `text: '{{ user.name }}'` |
| 值以 `!` 开头（tag 前缀） | `when: !user.hidden` | `when: '!user.hidden'` |
| 值内含 `: `（映射分隔） | `text: 时间: 12:00` | `text: '时间: 12:00'` |
| 值内含 ` #`（注释前缀） | `text: a # b` | `text: 'a # b'` |
| **键**以 `@` 或 `:` 开头 | `@click: go()` | `"@click": go()` |

其余注意：

- `{{ ... }}` 在**值中间**（如 `/users/{{ user.id }}/edit`）可裸写，无需引号。
- `true`/`false`/`yes`/`no`/`on`/`off` 在 YAML 1.1 中是布尔值——`required: true` 是故意的布尔语义；若需字面字符串请加引号。
- `level: 2`、`rows: 4` 解析为整数，与 `heading.level`、`textarea.rows` 的类型校验一致。
- 双引号字符串中 `\n` 是换行；需要字面 `\n` 两个字符时用单引号。
- 键**内含**冒号（`x-on:click:`、`wire:click:`）可裸写——冒号后面紧跟非空白字符即不构成映射分隔。
- 不支持 merge key：`<<: {class: box}` 会让 libyaml 发出 `expected a mapping for merging, but found scalar` 警告，并交回一个不含这些属性的页面。由于**任何**解析警告都会导致编译失败（§9），请把属性逐个写出来（`class: box`），不要用合并。

### 4.3 属性透传

节点上的键分三类处理：

1. **DSL 字段**——该节点类型自己消费的字段（如 `heading.level`、`link.href`、`form.action`），含结构性子键（`then`、`body`、`fields`、`columns`、`data`、`options`、`content`）。
2. **透传属性**——原样输出到该节点生成的标签上。白名单：
   - `@event`——Alpine / Vue 的事件简写，写作 `"@click"`（键以 `@` 开头必须加引号）
   - 带冒号的指令名：`x-on:click`、`x-bind:href`、`v-on:click`、`wire:click`、`on:click`、`:href`（键以 `:` 开头同样加引号）
   - 前缀：`x-`、`v-`、`hx-`、`data-`
   - 常用 HTML 钩子：`class`、`id`、`style`，以及 `bind`（前端框架的绑定属性，值是浏览器端变量名）
3. **其余一律编译错误**——未知键视为拼写错误，绝不静默丢弃。

不输出标签的节点（`text`、`if`、`each`、`component`）不接受透传属性，需用 `el` 包裹。页面根同理，只认 `title` / `layout` / `body` / `sections`。

透传属性的值先做 HTML 属性转义（`ENT_COMPAT`，保留单引号可读性），再做 `{{ }}` 插值——顺序不能反，否则 `## ##` 糖语法里的引号会被转义破坏。标量值统一按 HTML 属性可承载的形式归一：整数 `7` → `"7"`、布尔 `true` → `"true"`、空值 `x-cloak:` → `x-cloak=""`（无值属性的 YAML 写法）；非标量（映射/数组）报错。

**与 XML 版的唯一差异**：XML 的属性名装不下 `@`，所以那边用 `__click` 表示 `@click`；YAML 直接写 `"@click"` 即可，**没有 `__` 映射**，也不需要 `<attr>` 节点（YAML 的引号键可以表达任何属性名）。误写 `__click` 会报错并提示改成 `"@click"`。

**连字符形式的定向报错**：`x-on-*`、`x-bind-*`、`x-transition-*` 在 Alpine 中不存在（Alpine 一律用冒号）。由于 `x-` 前缀本会放行，这类拼写会被静默透传、编译成功而指令失效——因此单独拦截并给出建议：

```
body[0]: 未知属性 "x-on-click"；Alpine 的事件/绑定指令用冒号形式，请写 "x-on:click" 或 "@click"
```

## 5. 数据绑定语法

### 5.1 路径表达式

路径是数据绑定的唯一载体，文法严格：

```
path   := segment ( "." segment )*
segment := [A-Za-z_][A-Za-z0-9_]*
```

首段即变量名，后续段为数组键访问。示例：

| 路径 | 编译为 |
|------|--------|
| `users` | `$users` |
| `user.name` | `$user['name']` |
| `form.errors.email` | `$form['errors']['email']` |

编译后的访问统一带 `?? ''`（文本/属性上下文）或 `?? null`（条件/循环上下文）兜底，避免未定义键告警。

### 5.2 插值 `{{ path }}`

文本与属性值中支持 `{{ path }}` 插值，编译为**自动转义**输出：

```yaml
- type: text
  text: 你好，{{ user.name }}
```

编译为：

```php
你好，## $user['name'] ?? '' ##
```

`## ##` 由 TemplateCompiler 编译为 `<?= $this->e($user['name'] ?? '') ?>`，XSS 防护由模板引擎承担。

插值只出现在两种上下文，编译方式不同：

| 上下文 | 编译方式 | 示例 |
|--------|----------|------|
| HTML 文本 / 属性（text、heading、link 等） | 原样保留 `## expr ##` 糖 | `href="/users/## $user['id'] ?? '' ##"` |
| PHP 数组字面量（component 的 `data`） | 字符串拼接 `'...' . ($expr) . '...'`，**不预转义** | `'title' => '编辑 ' . ($user['name'] ?? '')` |

PHP 上下文绝不能输出 `## ##` 糖——它会被 TemplateCompiler 二次替换进 PHP 字符串字面量，造成语法错误。

**转义契约**：PHP 上下文的值以未转义形式传给组件，转义责任在组件模板——按字段语义选 `$this->e()`（文本）或 `$this->raw()`（信任的 HTML）。编译期若预转义，会与组件模板的转义叠成双重转义（`&amp;lt;`）。

插值只在这两种上下文生效。其余字段是**字面量字段**：`layout`、section 名、`form.method`、`field.name`、`field.label`、`option` 的 value 与显示文本、`table.empty`、`column.label`、`component.name`。这些字段原样输出，在其中写 `{{ }}` 不生效，属编译错误（不再静默忽略）。

### 5.3 非法路径与插值符号

任何 `{{ ... }}` 内不符合路径文法的内容（函数调用、算术、字符串字面量、嵌套插值）都是编译错误，带节点路径上报。

插值符号最多两个花括号：出现 `{{{` 或 `}}}` 即编译错误。三个花括号会骗过配对计数（`{{{ a }}}` 里 `{{` 与 `}}` 各一个，看起来配对成功），正则只匹配到内层 `{{ a }}`，剩下的花括号原样留在产物里，页面就会显示错乱的 `{` `}`。

### 5.4 数据形态约束

路径编译为数组访问（`$user['name']`）。页面数据约定为**数组形态**，由控制器在边界处归一化（Domain 实体转为数组）。这是文档化约束，不在本模块内做对象兼容。

## 6. 节点词表

body/sections 中的每个节点必须有 `type` 字段。共 9 种节点 + 2 种内嵌结构；内嵌结构（`field`、`column`）不必写 `type`（位置已决定类型），若写出则必须与位置一致：

| 节点 | 用途 |
|------|------|
| `text` | 文本，支持插值 |
| `heading` | 标题 |
| `link` | 链接 |
| `if` | 条件显示 |
| `each` | 循环列表 |
| `form` + `field` | 表单及其字段 |
| `table` + `column` | 表格及其列 |
| `el` | 通用元素容器，承载属性与子节点树 |
| `component` | 引用内置或自定义组件 |

### 6.1 text

```yaml
- type: text
  text: 你好，{{ user.name }}
```

`text` 必填，原样输出（字面部分由作者控制，可含 HTML）。插值自动转义。多行字符串允许。

### 6.2 heading

```yaml
- type: heading
  level: 2
  text: 用户管理
```

`level` 取值 1–6，默认 1，越界即编译错误。编译为 `<hN>...</hN>`。

### 6.3 link

```yaml
- type: link
  href: /users/{{ user.id }}/edit
  text: 编辑
```

`href`、`text` 必填，均支持插值（插值自动转义，属性上下文安全）。`target` 可选、支持插值，取值不校验——HTML 允许 `_blank` 之外的命名目标，枚举白名单会误杀合法用法。

### 6.4 if

```yaml
- type: if
  when: user.loggedIn
  then:
    - type: text
      text: A
  else:
    - type: text
      text: B
```

`when` 必填，路径可带 `!` 前缀取反；`then` 必填节点树；`else` 可选。编译为：

```php
<?php if ($user['loggedIn'] ?? null): ?>
  ...then...
<?php else: ?>
  ...else...
<?php endif ?>
```

取反形式 `when: '!user.hidden'`（注意引号）编译为 `<?php if (!($user['hidden'] ?? null)): ?>`。

### 6.5 each

```yaml
- type: each
  items: users
  as: user
  index: i
  body:
    - type: text
      text: '{{ user.name }}'
```

`items` 必填路径，`as` 默认 `item`，`index` 可选。`!` 取反只属于 `if.when`，`items` 上写 `!` 按非法路径报错。编译为：

```php
<?php foreach ($users as $i => $user): ?>
  ...body...
<?php endforeach ?>
```

嵌套 each 允许，内层 `as` 同名时按 PHP 语义自然遮蔽。

### 6.6 form + field

```yaml
- type: form
  action: /users/save
  method: post
  fields:
    - name: name
      label: 姓名
      input: text
      value: user.name
      required: true
      placeholder: 请输入姓名
    - name: role
      label: 角色
      input: select
      options:
        admin: 管理员
        user: 普通用户
    - name: bio
      label: 简介
      input: textarea
      rows: 4
      value: user.bio
    - name: active
      label: 启用
      input: checkbox
      checked: user.active
    - name: submit
      label: 保存
      input: submit
```

**form**：`action` 必填，`method` 默认 `post`，`fields` 必填数组。

**field** 字段：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `name` | string | 是 | 字段名（`name` / `id` 属性） |
| `label` | string | 是 | 标签文本；`submit` 类型时为按钮文字 |
| `input` | enum | 否 | 见下，默认 `text` |
| `value` | path | 否 | 绑定值，编译为 `value="## $path ?? '' ##"`；不支持 `submit`（按钮文字用 `label`） |
| `required` | bool | 否 | 默认 false；在支持该属性的 input 上加 `required`，`hidden` / `submit` 上写 true 属编译错误 |
| `placeholder` | string | 否 | 仅 text/password/email/number；其他 input 上属编译错误 |
| `options` | object | 仅 select | `admin: 管理员` 形式的映射 |
| `checked` | path | 仅 checkbox | 真值时输出 `checked` 属性；其他 input 上属编译错误 |
| `rows` | int | 仅 textarea | 默认 4；其他 input 上属编译错误 |

`input` 枚举：`text`、`password`、`email`、`number`、`textarea`、`select`、`checkbox`、`hidden`、`submit`。非法枚举即编译错误。`select` 缺 `options`、`options` 用在不支持的 input 上、`select` 上使用 `value`，均编译错误。

字段的**使用范围**同样是硬约束，越界即编译错误（这些字段此前会被静默丢弃）：`placeholder` 仅 text/password/email/number、`checked` 仅 checkbox、`rows` 仅 textarea、`value` 不支持 submit、`required` 仅 text/password/email/number/textarea/select/checkbox。`required` 为真时在 select / textarea / checkbox 上同样输出 `required` 属性。

`field` 是内嵌结构：不必写 `type`（位置即类型，与 XML 版 `<field>` 元素名等价）；若写出 `type`，值必须是 `field`，否则编译错误。`name`、`label`、`options` 的 value 与文本是字面量字段，不支持 `{{ }}` 插值。

编译产物示例（节选）：

```php
<form action="/users/save" method="post">
  <label for="name">姓名</label>
  <input type="text" name="name" id="name" value="## $user['name'] ?? '' ##" required>
  <label for="role">角色</label>
  <select name="role" id="role">
    <option value="admin">管理员</option>
    <option value="user">普通用户</option>
  </select>
  <input type="submit" value="保存">
</form>
```

### 6.7 table + column

```yaml
- type: table
  items: users
  as: user
  empty: 暂无数据
  columns:
    - label: ID
      pop: '{{ user.id }}'
    - label: 姓名
      pop: '{{ user.name }}'
    - label: 操作
      content:
        - type: link
          href: /users/{{ user.id }}/edit
          text: 编辑
```

`items` 必填，`as` 默认 `row`，`empty` 可选（空列表提示），`columns` 必填数组。**column**：`label` 必填；`pop`（服务端渲染进单元格的数据引用，写成 `'{{ row.id }}'`）与 `content`（节点树，行变量作用域）二选一必填，同时提供即编译错误。`pop` 必须带 `{{ }}` 且首段等于该表格的 `as` 变量。

`column` 同样不必写 `type`；若写出，值必须是 `column`，否则编译错误。`label` 与 `empty` 是字面量文本，不支持 `{{ }}` 插值。

编译为：

```php
<table>
<thead><tr><th>ID</th><th>姓名</th><th>操作</th></tr></thead>
<tbody>
<?php if (($users ?? []) === []): ?>
  <tr><td colspan="3">暂无数据</td></tr>
<?php else: ?>
<?php foreach ($users as $user): ?>
<tr>
<td>## $user['id'] ?? '' ##</td>
<td>## $user['name'] ?? '' ##</td>
<td><a href="/users/## $user['id'] ?? '' ##/edit">编辑</a></td>
</tr>
<?php endforeach ?>
<?php endif ?>
</tbody>
</table>
```

`content` 列的节点运行在行变量作用域内，可直接引用 `user.*`。

### 6.8 component

```yaml
- type: component
  name: card
  data:
    title: '{{ user.name }}'
    body: 简介
```

`name` 必填，`data` 可选映射，值支持插值（PHP 上下文拼接编译，不预转义）。空 `data` 映射（`data: {}`）与完全不写 `data` 等价——两者都编译为不带参形式 `<?= $this->component('c') ?>`（这是三个前端共用的行为：过去空映射会产出 `component('c', [ , ])` 这种非法 PHP）。编译为：

```php
<?= $this->component('card', [
    'title' => ($user['name'] ?? ''),
    'body' => '简介',
]) ?>
```

插值值以未转义形式到达组件，由组件模板决定转义（见 §5.2 转义契约）。内置组件中 `card.title`/`button.text`/`alert.text`/`badge.text` 走 `$this->e()`，`card.body` 走 `$this->raw()`。

### 6.9 el

```yaml
- type: el
  tag: div
  x-data: '{ open: false }'
  class: panel
  body:
    - type: heading
      level: 3
      text: '{{ user.name }}'
    - type: text
      text: 正文
```

`tag` 必填（小写 HTML 标签名），`body` 为子节点树（可省略，视为空）。接受任意透传属性。这是给 `x-data` 这类属性找挂载点的唯一方式——`text`/`if`/`each` 自己不输出标签。编译为：

```php
<div x-data="{ open: false }" class="panel">
<h3>## $user['name'] ?? '' ##</h3>
正文
</div>
```

## 7. 组件机制

内置组件与自定义组件走同一机制：都是 migears/template 的 component 模板文件，运行时由 `$this->component('name', $data)` 调用。

**内置组件**（随包分发，模板文件位于 `components/`）：

- `card` — 卡片：`title`、`body`
- `button` — 按钮：`text`、`href`（可选，无 href 时渲染 `<button>`）、`type`（默认 `default`，可选 `primary`）
- `alert` — 提示条：`type`（`info`/`success`/`warning`/`danger`，默认 `info`）、`text`
- `badge` — 标签：`text`、`type`（与 alert 同名的字段，默认 `default`；取值原样拼进 class，不做枚举校验）

内置组件文件内容为普通 miGears/template 组件（`$this->e()` 输出），用户可直接阅读、复制改造。

**自定义组件**：用户按 migears/template 的 component 规范自行编写 PHP 模板文件——`.php` 为原生写法，`.tpl.php` 为 `## ##` 糖语法（`## $expr ##` 转义、`### $expr ###` 原样输出，且会经 TemplateCompiler 落一份编译缓存；`.tpl.php` 优先于同名 `.php`）——如 `components/my-card.php`，在 YAML 中 `- type: component, name: my-card` 引用。无需注册，`name` 即模板名。本包自带一个可运行的自定义组件示例 `examples/components/my-card.php`，由 `examples/full-featured.page.yaml` 按名引用。

运行期组装：页面模板需能找到组件文件。README 说明通过 `$tpl->addPath()` 将包内 `components/` 目录加入模板搜索路径，或拷贝到项目模板目录。解析规则由 migears/template 的 `findTemplate()` 决定：先 `<path>/<name>.tpl.php`、再 `<path>/<name>.php`（`.tpl.php` 那一轮遍历完全部路径才轮到 `.php`，故糖语法文件优先），路径按 `addPath()` 逆序搜索、后加的目录先命中——同名文件因此可覆盖内置组件（主题覆盖）；`name` 可以是子目录路径（`admin/table` 命中 `<path>/admin/table.php`）；文件缺失编译期不报错，渲染时才抛 `Component not found`。

## 8. CLI

入口 `bin/yaml-pages`（PHP shebang 脚本）：

```
php bin/yaml-pages compile <input> [output-dir] [--check]
php bin/yaml-pages --help
```

| 参数 | 说明 |
|------|------|
| `compile` | 子命令。`<input>` 为 `.page.yaml` 文件或目录；目录则递归处理所有 `.page.yaml` |
| `[output-dir]` | 可选。缺省时与源文件同目录（原位生成）；指定时输出到该目录，保持同名 |
| `--check` | 仅校验不写文件 |
| `--help` | 用法说明（标准 help，不另设子命令） |

行为约定：

- 输出文件名：`users.page.yaml` → `users.tpl.php`
- 已存在的产物无条件覆盖（派生文件语义）
- 处理目录时逐文件报告 `编译: <source> → <target>`，失败不中断其他文件
- 退出码：全部成功 0；任一失败 1
- 未识别的 `-`/`--option` 一律报错：它不会落到位置参数上——否则拼错的 `--check` 会被静默当成输出目录，把干跑变成真实写盘
- 安装不完整时在任何文件被读取前报错，消息只出现一次而非每页一次：`Compiler` 无法自动加载（未跑过 `composer install` 的检出）与缺 `ext-yaml`，各自向 stderr 写一行并退出 1
- `--help` 在上述检查之前响应，因此一个什么也编译不了的环境仍然能看帮助
- 编译器内部抛出的任何 `Error` 都在顶层捕获并报 `fatal: <消息>`、退出码 1，维持退出码约定而不是 PHP 未捕获致命的 255

## 9. 错误处理

所有错误抛 `CompileException`（继承 `\RuntimeException`），CLI 捕获后打印到 stderr，格式：

```
views/pages/users.page.yaml: sections.content[2]: 未知节点类型 "foo"
```

错误分类与信息要求：

| 类别 | 检测 | 示例 |
|------|------|------|
| YAML 语法错误 | `yaml_parse` 返回 false——仅限真正的解析失败 | YAML 语法错误: ... |
| YAML 解析警告 | `yaml_parse` 成功但发出了一条或多条警告——返回的语法树已缺失文档的一部分 | YAML 解析错误（文档的部分内容会被丢弃）: ... |
| 空文档 | 文档为空（`''` / `~` / `null`） | YAML 文档为空；页面声明必须是映射 |
| 根类型错误 | 根不是映射，并给出实际收到的类型 | YAML 根必须是映射（页面对象），收到 boolean |
| 结构错误 | 顶层规则违反 | 同时指定 layout 与 body |
| 未知节点 | type 不在词表 | 未知节点类型 |
| 字段缺失/非法 | 必填缺失、枚举越界、类型不符 | if 缺 when；level 为 7 |
| method 类型错误 | `form.method` 不是字符串（校验先于任何强转，不泄漏 PHP 警告） | method 必须是字符串 "get" 或 "post"，收到 array |
| 路径错误 | 插值/路径文法不匹配 | 非法路径 "user..name" |
| 上下文错误 | pop/content 互斥等 | column 同时含 pop 与 content；pop 未引用行变量 |
| 字面量错误 | 字面量字段写了 `{{ }}` | "empty" 是字面量字段，不支持 {{ }} 插值 |
| 模板层标记 | 字面量字段（`label` / `name` / `tag` / `empty` / option 等）里出现 `##`——这些字段原样写入产物，没有可转义的位置 | body[0].fields[0]: "label" 是字面量，不允许出现 "##"（模板层语法） |
| 内嵌结构类型错误 | field/column 的 type 与位置不符 | type 必须是 "field" |
| 未知键 | 既非该节点的 DSL 字段，也不在透传白名单 | 未知属性 "levl" |
| 花括号错乱 | 插值出现 `{{{` 或 `}}}` | 插值符号不能连续三个花括号 |
| 根字段类型错误 | `layout` / `title` 不是字符串，`sections` 不是映射 | page: layout 必须是字符串，收到 array |
| 列表形态错误 | 节点树（`then` / `else` / `body` / `content` / `sections` 的值）或 `fields` / `columns` 被写成映射 | sections.content: 必须是节点树数组（列表），当前是键值映射；请用 [ ] 包成列表 |
| section 值类型错误 | `sections` 的某个值不是节点树数组（字符串 / 空值） | sections.content: 必须是节点树数组，收到 NULL |
| column 值类型错误 | `column.content` 不是节点树数组，或写成单个节点映射（未用 `-` 列表包裹） | columns[0].content: 必须是节点树数组（列表），当前是键值映射 |
| required 类型错误 | `field.required` 不是布尔（如带引号的 `'true'`） | required 必须是布尔值，收到 string |
| option 文本类型错误 | `option` 的显示文本不是字符串 | option "a" 的文本必须是字符串，收到 array |
| 连字符指令名 | `x-on-*` / `x-bind-*` / `x-transition-*`（Alpine 只有冒号形式） | 请写 "x-on:click" 或 "@click" |
| `__` 误用 | `__event` 是 XML 版的写法 | YAML 里直接写 "@click"（加引号） |
| 属性无挂载点 | 透传属性出现在不输出标签的节点上 | 节点 "text" 不输出标签，请用 type: el 包裹内容 |
| 属性值类型错误 | 透传属性值不是标量 | 属性 "x" 的值必须是标量，收到 array |

**任何解析警告都是致命的。** libyaml 有时会「报了警告但仍返回一棵语法树」——返回的树是残缺的。过去这些警告被丢弃，于是页面编译成功、而缺失的那部分已经悄悄消失——最典型的是 merge key：`<<: {class: box}` 会发出 `expected a mapping for merging, but found scalar` 警告，然后返回一个不含该属性的页面。因此现在 `yaml_parse()` 的**任何**警告都会导致编译失败，并且**所有**警告都会被列出，不再只保留最后一条。真解析失败（`yaml_parse` 返回 `false`）报 `YAML 语法错误: ...`；解析成功但有警告报 `YAML 解析错误（文档的部分内容会被丢弃）: ...`。

**空文档与标量根不是语法错误。** `''`、`~`、`null` 都是合法 YAML 文档，只是不承载任何映射，因此报 `YAML 文档为空；页面声明必须是映射`，不再误报为语法错误。同理，非映射根会给出实际类型：`false`（本身就是一份完整的 YAML 文档，不是解析失败）报 `YAML 根必须是映射（页面对象），收到 boolean`，字符串根报同一条消息、收到的类型是 `string`，`YAML 语法错误` 只留给真正的解析失败。

编译器为每个节点维护从根到自身的路径（如 `sections.content[2]`），错误必带路径。YAML 语法错误无法定位到节点时，输出解析器消息 + 文件路径。

失败即中止（fail-fast）：首个错误抛出，CLI 继续处理目录内其余文件。

**安装不完整是报错，不是撞上致命错误。** `Compiler::parse()` 检查 `function_exists('yaml_parse')`，缺失时抛出点名 `ext-yaml` 的 `CompileException`：composer 只在安装期校验 `ext-*`，而 ext-yaml 属 PECL 安装、部署环境完全可能没有——没有这道检查，调用本身就会抛出调用方无法按类型捕获的 `Error`。CLI 再把同一条件连同 Composer autoloader 一起前置检查，使消息只打印一次而非每文件一次；其余仍然逃逸的异常统一按 `fatal: <消息>` 捕获并以退出码 1 报告。

## 10. 模块结构

```
migears-yaml-pages/
├── composer.json            name: migears/yaml-pages; require: php ^8.1, ext-yaml, migears/pages ^2.0
├── README.md                双语（中英）、架构、安装、快速开始、YAML 参考、错误处理、测试说明
├── LICENSE
├── bin/
│   └── yaml-pages           CLI 入口
├── src/
│   ├── Compiler.php         YAML 解析层（YAML → 数组 IR，约 80 行），继承 migears/pages 的共享编译器
│   └── Exception/
│       └── CompileException.php
├── components/              内置组件模板
│   ├── card.php
│   ├── button.php
│   ├── alert.php
│   └── badge.php
├── examples/                全特性示例（可编译可渲染）
│   ├── full-featured.page.yaml  覆盖全部声明语法
│   ├── views/layout/main.php    配套最小布局
│   └── components/my-card.php   自定义组件示例，被 full-featured.page.yaml 按名引用
└── tests/
    ├── CompilerTest.php
    ├── CliTest.php
    ├── IntegrationTest.php
    ├── BundledComponentsTest.php   跨包副本一致性（同仓检出时校验，独立安装时跳过）
    └── fixtures/
        ├── pages/           .page.yaml 输入样例
        └── views/           集成测试用布局
```

composer 依赖说明：运行期实际执行的是生成的模板与内置组件，均依赖 migears/template；编译期依赖 migears/pages 的共享编译器，故设为 `require`（pages 包自身声明 migears/template）。解析层使用 PECL ext-yaml 的 `yaml_parse`（`pecl install yaml`），无 composer 第三方包。

复制说明：`components/*.php` 与 `bin/yaml-pages` 与另一前端 `migears/xml-pages` 逐字相同（四个内置组件 byte 级一致）。这是刻意接受的代价——组件必须随包分发才能被 `addPath` 找到，CLI 依赖各自的解析扩展——但改动其中一处（如 badge 的默认 type）必须同步另一处，两侧的组件清单与测试也需一起核对。`tests/BundledComponentsTest.php` 把这条约束变成可执行检查：同仓检出时逐字比对组件清单与内容，独立安装（兄弟包不存在）时跳过。

## 11. 测试计划（TDD）

单元测试以 YAML 字符串/fixtures 驱动：输入 `.page.yaml`，断言编译产物与期望 `.tpl.php` 完全一致（或含指定片段）。

共享编译层的回归测试（节点文法、插值、透传、校验的基类行为）由 migears/pages 的 CompilerTest 承担；本包测试聚焦 YAML 解析与继承后的整体行为。

| 分组 | 用例 |
|------|------|
| 文本 | text 纯文本 / 单插值 / 多插值 / 多行 |
| 结构 | heading 各级、越界 level 报错；link href/text 插值 |
| 条件 | if then / if then+else / `!` 取反 / when 缺失报错 |
| 循环 | each 基础 / index / 嵌套 / items 缺失报错 |
| 表单 | 各 input 枚举 / select options / checkbox checked / submit / 非法枚举 / select 缺 options / options 用在不支持的 input / method 非字符串报类型错误且不泄漏 PHP 警告 / required 非布尔 / option 文本非字符串 |
| 表格 | pop 列（`{{ row.x }}`）/ content 列 / empty / as 默认与自定义 / pop+content 同存报错 / columns 缺失报错 / columns 写成映射报错 |
| 布局 | layout+sections / body 独立 / 两者同存报错 / 双缺失报错 / title section |
| 组件 | 无 data / data 插值（PHP 上下文拼接）/ data 字面量 |
| 绑定 | 路径文法边界（非法字符、空段、`!` 只允许 when） |
| 取反边界 | `each.items` 带 `!` 报错（`!` 只属于 `if.when`） |
| 内嵌结构 | `type: field` / `type: column` 写对可通过，写成另一种即报错 |
| 字面量 | `field.label`、`table.empty`、`option` 等字面量字段写 `{{ }}` 报错 |
| 模板层标记 | 文本里出现 `##` 时按模板层语法转义（产物含 `\##`）；单个 `#` 不需转义（共享层，前端侧同样可达） |
| 解析 | YAML 语法错误报错、根非映射报错、空文档不算语法错误、空映射落到页面内容规则 |
| 透传 | Alpine / Vue / htmx / Livewire 指令与 `class`/`id`/`style` 透传；`"@click"` 引号键；`x-on:click` 裸键；值转义；值内插值；标量归一（整数/布尔/空值） |
| 透传误用 | 未知键报错；无标签节点（`text`/`if`/`each`/`component`）承载属性报错；页面根未知字段报错 |
| el | 带 body / 空 body / 缺 tag 报错 / 非法 tag 报错 |
| 跨版提示 | `__click` 报错并提示改写 `"@click"`；`x-on-click` 报错并提示 `x-on:click` 或 `@click` |
| 插值符号 | `{{{ a }}}` / `{{ a }}}` / `{{{ a }}` 报错；相邻的 `{{ a }}{{ b }}` 仍放行 |
| section 值类型 | `sections` 的值不是数组（字符串/空值）时报可读错误，而不是 PHP TypeError |
| column 值类型 | `column.content` 不是数组、或写成单个节点映射时报可读错误，而不是 PHP TypeError 或 `content[type]: 节点必须是对象` |
| 根与列表形态 | `body` 非数组或写成单个映射、`layout` 非字符串、`sections` 非映射；`then` / `each.body` / `fields` / `columns` 写成映射时报可读错误 |
| CLI | 单文件编译 / 目录递归 / output-dir / --check / --help / 未识别选项被拒 / 失败退出码 |
| 安装环境 | 缺 Composer autoloader / 缺 `ext-yaml` / 未预料 `Error`：stderr 一行、退出码 1、无调用栈；`--help` 仍可响应 |
| 集成 | 编译产物经 TemplateCompiler 二次编译后渲染成功（与 migears/template 联测） |
| 副本一致性 | 内置组件与 `migears/xml-pages` 逐字相同（同仓检出时校验，独立安装时跳过） |

## 12. 明确不做（后续候选）

- 事件处理、状态管理、路由——永不进入
- YAML 内自定义组件（组件只以 PHP 模板形态存在）
- 表达式语言扩展（算术、函数、三元）
- 运行期 YAML 解析 / 热更新
- 覆盖 `input` 之外的 HTML 表单控件（文件上传、日期选择等）