<?php
/* my-card — a custom component example, referenced from a page with name: my-card.
 *
 * A component is just an ordinary miGears Template file; there are only four rules:
 *   1. The keys in data become local variables inside the component (here title / body);
 *      whatever the page passes is what the component sees;
 *   2. Escaping is up to the component — text uses $this->e(), trusted HTML uses $this->raw().
 *      Page-side interpolation arrives unescaped, so re-escaping here is the one correct escape;
 *   3. A component can call $this->component() again inside itself;
 *   4. No registration is needed: put the file on any registered template search path and
 *      it is found by name matching the file name.
 */ ?><div class="my-card">
    <h3 class="my-card-title"><?= $this->e($title ?? '') ?></h3>
    <div class="my-card-body"><?= $this->raw((string) ($body ?? '')) ?></div>
</div>
