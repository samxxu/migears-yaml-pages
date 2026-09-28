# migears-yaml-pages — Known Issues / 已知问题

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> From the miGears Full-Module Code Review Report (4th round, 2026-09-27).

| | |
|---|---|
| Status / 状态 | **P2 open / P2 待修** |
| Size / 体量 | src 162 lines (78 net) · 135 tests · 2 src files · 19× test-to-source ratio |

Legend / 图例 — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs
级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## At a glance / 状态一览

| | |
|---|---|
| Items / 条目 | P0 0 · P1 0 · P2 1 · P3 3 · other 0 |
| Answered / 已回复 | 0 of 4 |
| Waiting / 等待回复 | `P2-1`, `P3-1`, `P3-2`, `P3-3` |

| id | level | status | title |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **open** | Duplicate mapping keys are still merged silently by libyaml, and this … |
| [`P3-1`](issues/P3-1.md) | P3 | **open** | When the root is a YAML sequence the message says 'got array' (gettype … |
| [`P3-2`](issues/P3-2.md) | P3 | **open** | The same semantic value is accepted differently from the XML side (see … |
| [`P3-3`](issues/P3-3.md) | P3 | **open** | `set_error_handler` during parsing treats any severity as fatal, so a … |

## Verdict / 结论

The best example in the workspace of "thinnest possible business code": 162 lines of source that parse, count documents, guard decode_php and hint at `__` prefixes, with everything else pushed into the shared layer. Its residual problem is inherited, not its own: libyaml merges duplicate mapping keys before PHP sees them.

全仓「业务代码最精炼」的样板：162 行源码完成解析、文档计数、decode_php 守卫与 __ 前缀提示，其余全部下沉到共享层。它的残留问题是继承来的、不是自身的：libyaml 在 PHP 看到之前就合并了重复映射键。

## Fixed since the last round / 本轮已修复确认

上一轮 P1-1（属性名字符集）与 P2-1（section 名不 trim）已在共享层修复并有对拍；P2-2（decode_php 断言前提不成立、锚点超威胁模型）经复核结案，注释措辞已改；P3-1（CLI USAGE 文案四处不一致）已统一，两 CLI 现在只差命名空间、扩展名提示与包名。 

## Test gaps / 测试盲区

Duplicate `options`/`data` keys are untested (only body and sections are pinned); YAML 1.1 booleans (`y`/`yes`/`on`) and quoted-vs-native types have no boundary cases; the parity corpus is too thin to catch shared-layer divergences.

重复的 options/data 键未被测试（只钉住了 body 与 sections）；YAML 1.1 布尔（y/yes/on）与引号-vs-原生类型无边界用例；对拍语料太薄，抓不到共享层分歧。

## Verification protocol / 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
