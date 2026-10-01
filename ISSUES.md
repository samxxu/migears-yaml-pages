# migears-yaml-pages — Known Issues

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> From the miGears Full-Module Code Review Report (6th round, 2026-10-01).

| | |
|---|---|
| Status | **Best state** |
| Size | src 93 lines (net) · 139 tests · 2 src files |

Legend — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs

## At a glance

| | |
|---|---|
| Unsettled | P0 0 · P1 0 · P2 1 · P3 1 · other 0 |
| Settled | 3 of 5 |
| Waiting on the owner | _nothing_ |
| Waiting on the coordinator | _nothing_ |
| Waiting on the reviewer | _nothing_ |
| Deferred, owing nobody | `P2-1`, `P3-2` |

| id | level | status | title |
|---|---|---|---|
| [`P0-1`](issues/P0-1.md) | P0 | **verified** | The `yaml.decode_php` guard read `(int) $decodePhp !== 0`, which is … |
| [`P2-1`](issues/P2-1.md) | P2 | **deferred** | Duplicate mapping keys in a YAML document are silently merged by … |
| [`P3-1`](issues/P3-1.md) | P3 | **verified** | When the root is a YAML sequence the message says 'got array' (gettype … |
| [`P3-2`](issues/P3-2.md) | P3 | **deferred** | The same semantic value is accepted differently from the XML side (see … |
| [`P3-3`](issues/P3-3.md) | P3 | **verified** | `set_error_handler` during parsing treats any severity as fatal, so a … |

## Unclosed

What is left to do here: every item whose `status` is not `verified` or `closed`,
highest severity first. `waiting on` is the party who acts next, read from that status.

| | |
|---|---|
| Unclosed | **2** of 5 |
| By status | `deferred` 2 |
| Waiting on | - 2 |

| level | item | status | waiting on | title |
|---|---|---|---|---|
| **P2** | [`P2-1`](issues/P2-1.md) | `deferred` | - | Duplicate mapping keys in a YAML document are silently merged by … |
| **P3** | [`P3-2`](issues/P3-2.md) | `deferred` | - | The same semantic value is accepted differently from the XML side (see … |

## Verdict

The front end is the thinnest in the family and its one real security item is closed; the duplicate-key limitation stays a recorded, documented boundary.

## Fixed since the last round

No item was awaiting a verdict; the P0 object-injection guard is hardened (the alphabetic truthy spellings On/yes/true no longer defeat it), a sequence root is named as a sequence, and the severity filter only treats warnings and notices as lost data.

## Test gaps

As with xml-pages, the parity test skips on a standalone checkout; duplicate mapping keys remain undetectable at this layer by construction, since libyaml merges them first.

## Verification protocol

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.


---

# migears-yaml-pages — 已知问题

> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> 出自 miGears 全模块代码评审报告（6th round，2026-10-01）。

| | |
|---|---|
| 状态 | **状态最好** |
| 体量 | src 93 行（净）· 139 个用例 · 2 个源文件 |

级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## 状态一览

| | |
|---|---|
| 未了结 | P0 0 · P1 0 · P2 1 · P3 1 · 其他 0 |
| 已了结 | 3 / 5 |
| 等模块主 | _无_ |
| 等协调人 | _无_ |
| 等评审方 | _无_ |
| 已暂缓，不欠谁 | `P2-1`, `P3-2` |

| id | 级别 | 状态 | 标题 |
|---|---|---|---|
| [`P0-1`](issues/P0-1.md) | P0 | **verified** | `yaml.decode_php` 守卫写的是 `(int) $decodePhp !== 0`，会被字母拼写的真值 … |
| [`P2-1`](issues/P2-1.md) | P2 | **deferred** | YAML 文档中的重复映射键会被 libyaml 静默合并，PHP 看到文档之前第一个值就已丢失、第二个值胜出，且无告警。这是 README … |
| [`P3-1`](issues/P3-1.md) | P3 | **verified** | 根为 YAML 序列时报 "got array"（gettype 只给 array，不区分 list），而 spec §9 举例为 "got … |
| [`P3-2`](issues/P3-2.md) | P3 | **deferred** | 同一语义值与 XML 侧接受面不同（完整例子见 xml-pages 一节）：YAML 要求原生标量，required: 1 与 rows: … |
| [`P3-3`](issues/P3-3.md) | P3 | **verified** | 解析期间装的 set_error_handler 把任何 severity 都当致命，因此将来 ext-yaml … |

## 未关闭

本模块还剩什么要做：所有 `status` 不是 `verified` 或 `closed` 的条目，按严重度从高到低。
`waiting on` 是下一步该动手的一方，由其状态读出。

| | |
|---|---|
| 未关闭 | **2** / 5 |
| 按状态 | `deferred` 2 |
| 等在谁 | - 2 |

| 级别 | 条目 | 状态 | 等在谁 | 标题 |
|---|---|---|---|---|
| **P2** | [`P2-1`](issues/P2-1.md) | `deferred` | - | YAML 文档中的重复映射键会被 libyaml 静默合并，PHP 看到文档之前第一个值就已丢失、第二个值胜出，且无告警。这是 README … |
| **P3** | [`P3-2`](issues/P3-2.md) | `deferred` | - | 同一语义值与 XML 侧接受面不同（完整例子见 xml-pages 一节）：YAML 要求原生标量，required: 1 与 rows: … |

## 结论

该前端是本族中最薄的，而它唯一真实的安全项已关闭；重复键限制保持为已记录、已文档化的边界。

## 本轮已修复确认

No item was awaiting a verdict; the P0 object-injection guard is hardened (the alphabetic truthy spellings On/yes/true no longer defeat it), a sequence root is named as a sequence, and the severity filter only treats warnings and notices as lost data.

## 测试盲区

与 xml-pages 一样，单独检出时对拍会跳过；重复映射键在本层结构性不可探测，因为 libyaml 先一步合并了它们。

## 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- `phpunit.xml.dist` 中的 warning/notice/deprecation/risky 开关：四个全开
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
