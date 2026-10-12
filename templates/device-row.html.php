<?php
/**
 * One table row. Collapsed rows show one line; the .more parts appear when a row is expanded.
 *
 * Inside the cells, lines end in a PHP tag so no whitespace gets between the inline chips.
 *
 * @var Device $device
 * @var DeviceType $type
 * @var ?string $customName
 * @var bool $online
 * @var bool $merged whether the input is a merged inventory
 * @var int $scans number of merged scans
 */

$vendor = $device->vendor ?? ($device->macRandomized ? 'Private MAC' : 'Unknown vendor');
$details = array_map(esc(...), $device->names());
if ($customName !== null) {
    array_unshift($details, esc($vendor));
}
if ($device->role !== null) {
    array_unshift($details, '<b>' . esc($device->role) . '</b>');
}
if ($services = $device->mdnsServices()) {
    $details[] = 'mDNS: ' . esc(implode(', ', $services));
}
// Blue marker for named devices, yellow for online ones that still need a name.
$rowClass = implode(' ', array_filter([
    $customName !== null ? 'named' : ($online ? 'unnamed' : ''),
    $online ? '' : 'offline',
]));
$osName = $device->os->bestGuess ?? $device->os->details;
$typeLabel = esc($type->label());
?>
<tr class="<?= $rowClass ?>" data-key="<?= esc($device->mac ?? $device->ip) ?>" data-type="<?= $type->value ?>"
  data-mac="<?= esc($device->mac) ?>" tabindex="0" aria-expanded="false">
<td class="mono"><span class="chev">›</span><span class="dot<?= $online ? ' up' : '' ?>"></span><?= esc($device->ip) ?></td>
<td><div class="device">
  <span class="icon" title="<?= $typeLabel ?>"><svg viewBox="0 0 24 24" role="img" aria-label="<?= $typeLabel ?>"><?= $type->icon() ?></svg></span>
  <div><?php if ($customName !== null): ?><span class="name"><?= esc($customName) ?></span><?php else: ?><?= esc($vendor) ?><?php endif ?>
<div class="muted more"><?= implode('<br>', $details) ?></div></div>
</div></td>
<td class="mono"><?= esc($device->mac ?? '—') ?><?php if ($device->mac !== null): ?>
<button type="button" class="copy" data-copy="<?= esc($device->mac) ?>" title="Copy MAC address" aria-label="Copy MAC address <?= esc($device->mac) ?>"><?php ?>
<svg class="to-copy" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><?php ?>
<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><?php ?>
<svg class="copied" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></button><?php endif ?>
<?php if ($device->macRandomized): ?><div class="muted more">randomized</div><?php endif ?></td>
<td><?php if ($device->ports === null): ?><span class="muted">not scanned</span><?php else: ?>
<?php if (!$device->openPorts()): ?><span class="muted less">none open</span><?php endif ?>
<?php foreach ($device->ports as $port):
    $title = implode(' — ', array_filter([$port->version, $port->hint], fn(?string $value) => $value !== null));
    [$class, $label] = match (true) {
        !$port->isOpen() => ['chip filtered more', "$port->port/" . ($port->service ?? '?') . ' (' . ($port->state ?? 'None') . ')'],
        $port->hint !== null => ['chip hint', "$port->port $port->hint"],
        default => ['chip', "$port->port/" . ($port->service ?? '?')],
    }; ?>
<span class="<?= $class ?>" title="<?= esc($title) ?>"><?= esc($label) ?></span><?php endforeach ?>
<?php if ($device->portsNotShown !== null): ?><div class="muted more"><?= esc($device->portsNotShown) ?></div><?php endif ?>
<?php endif ?></td>
<td><?php if ($osName === null): ?><span class="muted">unknown</span><?php else: ?><?= esc($osName) ?><?php ?>
<?php if (!empty($device->os->accuracy)): ?> <span class="muted"><?= $device->os->accuracy ?>%</span><?php endif ?>
<?php if ($device->os->source === 'local'): ?><div class="muted more">read locally</div><?php ?>
<?php elseif (!$device->openPorts()): ?><div class="badge more">unreliable: no open ports</div><?php endif ?>
<?php endif ?></td>
<td class="mono"><?= $device->latencyMs !== null ? sprintf('%g ms', $device->latencyMs) : '—' ?></td>
<td><?php if ($merged): ?><?= $device->timesSeen ?>/<?= $scans ?><div class="muted more">last <?= esc(fmt_time($device->lastSeen)) ?></div><?php ?>
<?php else: ?><?= esc($device->deepScan) ?><?php endif ?></td>
</tr>
