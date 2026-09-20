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

实现规模保持在同一量级（本包解析层约 75 行——编译逻辑全部在 migears/pages 共享层约 900 行；CLI 约 110 行，组件为纯模板 PHP 文件）。任何让实现显著膨胀的特性都拒绝。

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

### 4.3 属性透传

节点上的键分三类处理：

1. **DSL 字段**——该节点类型自己消费的字段（如 `heading.level`、`link.href`、`form.action`），含结构性子键（`then`、`body`、`fields`、`columns`、`data`、`options`、`content`）。
2. **透传属性**——原样输出到该节点生成的标签上。白名单：
   - `@event`——Alpine / Vue 的事件简写，写作 `"@click"`（键以 `@` 开头必须加引号）
   - 带冒号的指令名：`x-on:click`、`x-bind:href`、`v-on:click`、`wire:click`、`on:click`、`:href`（键以 `:` 开头同样加引号）
   - 前缀：`x-`、`v-`、`hx-`、`data-`
   - 常用 HTML 钩子：`class`、`id`、`style`
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

### 5.3 非法表达式

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

`href`、`text` 必填，均支持插值（插值自动转义，属性上下文安全）。`target` 可选。

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
| `value` | path | 否 | 绑定值，编译为 `value="## $path ?? '' ##"` |
| `required` | bool | 否 | 默认 false，加 `required` 属性 |
| `placeholder` | string | 否 | 仅 text/password/email/number |
| `options` | object | 仅 select | `admin: 管理员` 形式的映射 |
| `checked` | path | 仅 checkbox | 真值时输出 `checked` 属性 |
| `rows` | int | 仅 textarea | 默认 4 |

`input` 枚举：`text`、`password`、`email`、`number`、`textarea`、`select`、`checkbox`、`hidden`、`submit`。非法枚举即编译错误。`select` 缺 `options`、`options` 用在不支持的 input 上、`select` 上使用 `value`，均编译错误。

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
      bind: id
    - label: 姓名
      bind: name
    - label: 操作
      content:
        - type: link
          href: /users/{{ user.id }}/edit
          text: 编辑
```

`items` 必填，`as` 默认 `row`，`empty` 可选（空列表提示），`columns` 必填数组。**column**：`label` 必填；`bind`（相对行变量的路径）与 `content`（节点树，行变量作用域）二选一必填，同时提供即编译错误。

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

`name` 必填，`data` 可选映射，值支持插值（PHP 上下文拼接编译，不预转义）。编译为：

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
- `badge` — 标签：`text`、`type`（同 alert）

内置组件文件内容为普通 miGears/template 组件（`$this->e()` 输出），用户可直接阅读、复制改造。

**自定义组件**：用户按 migears/template 的 component 规范自行编写 PHP 模板文件（如 `components/my-card.php`），在 YAML 中 `- type: component, name: my-card` 引用。无需注册，`name` 即模板名。

运行期组装：页面模板需能找到组件文件。README 说明通过 `$tpl->addPath()` 将包内 `components/` 目录加入模板搜索路径，或拷贝到项目模板目录。

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

## 9. 错误处理

所有错误抛 `CompileException`（继承 `\RuntimeException`），CLI 捕获后打印到 stderr，格式：

```
views/pages/users.page.yaml: sections.content[2]: 未知节点类型 "foo"
```

错误分类与信息要求：

| 类别 | 检测 | 示例 |
|------|------|------|
| YAML 语法错误 | `yaml_parse` 失败（返回 false）+ 捕获解析警告 | YAML 语法错误: ... |
| 根类型错误 | 根不是映射 | YAML 根必须是映射（页面对象） |
| 结构错误 | 顶层规则违反 | 同时指定 body 与 sections |
| 未知节点 | type 不在词表 | 未知节点类型 |
| 字段缺失/非法 | 必填缺失、枚举越界、类型不符 | if 缺 when；level 为 7 |
| 路径错误 | 插值/路径文法不匹配 | 非法表达式 |
| 上下文错误 | bind/content 互斥等 | column 同时含 bind 与 content |
| 字面量错误 | 字面量字段写了 `{{ }}` | "empty" 是字面量字段，不支持 {{ }} 插值 |
| 内嵌结构类型错误 | field/column 的 type 与位置不符 | type 必须是 "field" |
| 未知键 | 既非该节点的 DSL 字段，也不在透传白名单 | 未知属性 "levl" |
| 花括号错乱 | 插值出现 `{{{` 或 `}}}` | 插值符号不能连续三个花括号 |
| section 值类型错误 | `sections` 的某个值不是节点树数组 | sections.content: section 的值必须是节点树数组 |
| column 值类型错误 | `column.content` 不是节点树数组 | columns[0].content: 必须是节点树数组 |
| 连字符指令名 | `x-on-*` / `x-bind-*` / `x-transition-*`（Alpine 只有冒号形式） | 请写 "x-on:click" 或 "@click" |
| `__` 误用 | `__event` 是 XML 版的写法 | YAML 里直接写 "@click"（加引号） |
| 属性无挂载点 | 透传属性出现在不输出标签的节点上 | 节点 "text" 不输出标签，请用 type: el 包裹内容 |
| 属性值类型错误 | 透传属性值不是标量 | 属性 "x" 的值必须是标量，收到 array |

编译器为每个节点维护从根到自身的路径（如 `sections.content[2]`），错误必带路径。YAML 语法错误无法定位到节点时，输出解析器消息 + 文件路径。

失败即中止（fail-fast）：首个错误抛出，CLI 继续处理目录内其余文件。

## 10. 模块结构

```
migears-yaml-pages/
├── composer.json            name: migears/yaml-pages; require: php >=8.1, ext-yaml, migears/pages ^2.0
├── README.md                双语（中英）、架构、安装、快速开始、YAML 参考、错误处理、测试说明
├── LICENSE
├── bin/
│   └── yaml-pages           CLI 入口
├── src/
│   ├── Compiler.php         YAML 解析层（YAML → 数组 IR，约 75 行），继承 migears/pages 的共享编译器
│   └── Exception/
│       └── CompileException.php
├── components/              内置组件模板
│   ├── card.php
│   ├── button.php
│   ├── alert.php
│   └── badge.php
├── examples/                全特性示例（可编译可渲染）
│   ├── full-featured.page.yaml  覆盖全部声明语法
│   └── views/layout/main.php    配套最小布局
└── tests/
    ├── CompilerTest.php
    ├── CliTest.php
    ├── IntegrationTest.php
    └── fixtures/
        ├── pages/           .page.yaml 输入样例
        └── views/           集成测试用布局
```

composer 依赖说明：运行期实际执行的是生成的模板与内置组件，均依赖 migears/template；编译期依赖 migears/pages 的共享编译器，故设为 `require`（pages 包自身声明 migears/template）。解析层使用 PECL ext-yaml 的 `yaml_parse`（`pecl install yaml`），无 composer 第三方包。

## 11. 测试计划（TDD）

单元测试以 YAML 字符串/fixtures 驱动：输入 `.page.yaml`，断言编译产物与期望 `.tpl.php` 完全一致（或含指定片段）。

共享编译层的回归测试（节点文法、插值、透传、校验的基类行为）由 migears/pages 的 CompilerTest 承担；本包测试聚焦 YAML 解析与继承后的整体行为。

| 分组 | 用例 |
|------|------|
| 文本 | text 纯文本 / 单插值 / 多插值 / 多行 |
| 结构 | heading 各级、越界 level 报错；link href/text 插值；非法 target |
| 条件 | if then / if then+else / `!` 取反 / when 缺失报错 |
| 循环 | each 基础 / index / 嵌套 / items 缺失报错 |
| 表单 | 各 input 枚举 / select options / checkbox checked / submit / 非法枚举 / select 缺 options / options 用在不支持的 input |
| 表格 | bind 列 / content 列 / empty / as 默认与自定义 / bind+content 同存报错 / columns 缺失报错 |
| 布局 | layout+sections / body 独立 / 两者同存报错 / 双缺失报错 / title section |
| 组件 | 无 data / data 插值（PHP 上下文拼接）/ data 字面量 |
| 绑定 | 路径文法边界（非法字符、空段、`!` 只允许 when） |
| 取反边界 | `each.items` 带 `!` 报错（`!` 只属于 `if.when`） |
| 内嵌结构 | `type: field` / `type: column` 写对可通过，写成另一种即报错 |
| 字面量 | `field.label`、`table.empty`、`option` 等字面量字段写 `{{ }}` 报错 |
| 解析 | YAML 语法错误报错、根非映射报错 |
| 透传 | Alpine / Vue / htmx / Livewire 指令与 `class`/`id`/`style` 透传；`"@click"` 引号键；`x-on:click` 裸键；值转义；值内插值；标量归一（整数/布尔/空值） |
| 透传误用 | 未知键报错；无标签节点（`text`/`if`/`each`/`component`）承载属性报错；页面根未知字段报错 |
| el | 带 body / 空 body / 缺 tag 报错 / 非法 tag 报错 |
| 跨版提示 | `__click` 报错并提示改写 `"@click"`；`x-on-click` 报错并提示 `x-on:click` 或 `@click` |
| 插值符号 | `{{{ a }}}` / `{{ a }}}` / `{{{ a }}` 报错；相邻的 `{{ a }}{{ b }}` 仍放行 |
| section 值类型 | `sections` 的值不是数组（字符串/空值）时报可读错误，而不是 PHP TypeError |
| column 值类型 | `column.content` 不是数组时报可读错误，而不是 PHP TypeError |
| CLI | 单文件编译 / 目录递归 / output-dir / --check / --help / 失败退出码 |
| 集成 | 编译产物经 TemplateCompiler 二次编译后渲染成功（与 migears/template 联测） |

## 12. 明确不做（后续候选）

- 事件处理、状态管理、路由——永不进入
- YAML 内自定义组件（组件只以 PHP 模板形态存在）
- 表达式语言扩展（算术、函数、三元）
- 运行期 YAML 解析 / 热更新
- 覆盖 `input` 之外的 HTML 表单控件（文件上传、日期选择等）
