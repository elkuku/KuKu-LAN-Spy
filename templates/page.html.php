<?php

use LanSpy\Render\Html;

/**
 * The whole page: a standalone HTML file with the CSS and JS inlined.
 *
 * @var int $refresh seconds between auto-reloads, 0 for none
 * @var string $css
 * @var string $script
 * @var ?string $network
 * @var ?string $gateway
 * @var string $latestScan
 * @var list<array{int, string}> $stats [count, label]
 * @var list<array{string, string, string, int}> $typeOptions [value, label, icon SVG body, device count]
 * @var string $seenHeader
 * @var list<string> $rows rendered device rows
 * @var string $generated
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($refresh): ?>
<meta http-equiv="refresh" content="<?= $refresh ?>">
<?php endif ?>
<title>LAN Devices</title>
<style>
<?= $css ?>
</style>
</head>
<body>
<main>
<h1>LAN Devices</h1>
<div class="sub"><?= Html::escape($network) ?> · gateway <?= Html::escape($gateway ?? '?') ?> · latest scan <?= Html::escape($latestScan) ?></div>
<div class="stats">
<?php foreach ($stats as [$count, $label]): ?>
<div class="stat"><b><?= $count ?></b><span><?= Html::escape($label) ?></span></div>
<?php endforeach ?>
</div>
<div class="toolbar">
<div class="filters">
<div class="filter"><span id="type-label">Type</span>
<div class="type-menu">
<button type="button" id="type-button" aria-haspopup="listbox" aria-expanded="false"
  aria-labelledby="type-label type-button"></button>
<ul id="type-list" role="listbox" aria-labelledby="type-label" hidden>
<?php foreach ($typeOptions as [$value, $label, $icon, $count]): ?>
<li role="option" tabindex="-1" data-value="<?= Html::escape($value) ?>">
  <span class="icon"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg></span><?= Html::escape($label) ?><span class="count"><?= $count ?></span>
</li>
<?php endforeach ?>
</ul>
</div>
</div>
<label class="filter">MAC <input type="search" id="mac-filter" placeholder="e.g. 58:4b" spellcheck="false"
  autocomplete="off"></label>
</div>
<button type="button" id="toggle-all">Expand all</button>
</div>
<div class="wrap">
<table>
<colgroup><col style="width:165px"><col style="width:20%"><col style="width:194px"><col>
<col style="width:17%"><col style="width:95px"><col style="width:90px"></colgroup>
<thead><tr><th>IP</th><th>Device</th><th>MAC</th><th>Ports</th><th>OS guess</th><th>Latency</th><th><?= Html::escape($seenHeader) ?></th></tr></thead>
<tbody>
<?= implode("\n", $rows) ?>
</tbody>
</table>
<div class="empty" id="no-match" hidden>No devices match the filters.<button type="button" id="clear-filters">Clear filters</button></div>
</div>
<p class="muted">Generated <?= Html::escape($generated) ?> by render-html.php.
Click a row for details. Greyed-out rows were not seen in the latest scan; a yellow edge marks online
devices without a custom name.</p>
</main>
<script>
<?= $script ?>
</script>
</body>
</html>
