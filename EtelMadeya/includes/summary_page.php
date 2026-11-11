<?php
// Shared printable end-of-day summary. Expects: $date, $cid, $stations, $assets ('../' or ''), $picker (bool). Used by admin/summary.php and station-summary.php.
$e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
function nf($v) { return number_format((float)$v, 1); }
$grand = ['served' => 0, 'liters' => 0.0];
ensureOpsTables();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Daily summary <?= $e($date) ?> - Etel Madeya</title>
<style>
:root{--ink:#1b1b1b;--gold:#b8901a;--line:#d9c98f;--soft:#faf6ea}
*{box-sizing:border-box}body{margin:0;font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--ink);background:#eee}
.bar{position:sticky;top:0;background:#1a1400;color:#fff;padding:10px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.bar b{color:#d4af37}.bar button,.bar a{background:#d4af37;color:#1a1400;border:0;border-radius:10px;padding:9px 16px;font-weight:700;cursor:pointer;text-decoration:none;font-size:14px}
.page{background:#fff;max-width:820px;margin:16px auto;padding:28px 30px;box-shadow:0 2px 12px rgba(0,0,0,.15)}
.hd{display:flex;align-items:center;gap:14px;border-bottom:3px solid var(--gold);padding-bottom:12px;margin-bottom:16px}
.hd img{width:54px;height:54px;object-fit:contain}.hd h1{margin:0;font-size:22px}.hd small{color:#666}
h2{font-size:17px;margin:0 0 2px}.sub{color:#666;margin-bottom:12px}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:12px 0}
.kpi{border:1.5px solid var(--line);background:var(--soft);border-radius:10px;padding:8px 10px}.kpi b{display:block;font-size:20px}.kpi span{font-size:12px;color:#666}
table{width:100%;border-collapse:collapse;margin-top:6px}th,td{padding:7px 8px;border-bottom:1px solid #e6dcb5;text-align:left}th{background:var(--soft);font-size:12px;text-transform:uppercase;letter-spacing:.04em}
td.n,th.n{text-align:right}tfoot td{font-weight:800;border-top:2px solid var(--gold)}
.warn{margin-top:10px;padding:9px 12px;border-radius:10px;border:1.5px solid #d93025;background:#fdecea;color:#8a1c14;font-weight:600}
.okb{margin-top:10px;padding:9px 12px;border-radius:10px;border:1.5px solid #2e9e4f;background:#e8f5ec;color:#1d6b35}
.sign{display:flex;gap:30px;margin-top:34px}.sign div{flex:1;border-top:1px solid #444;padding-top:4px;font-size:12px;color:#555}
.foot{margin-top:14px;font-size:11px;color:#888}
.brk{page-break-after:always}
@media print{body{background:#fff}.bar{display:none}.page{box-shadow:none;margin:0;max-width:none;padding:6mm 4mm}@page{size:A4;margin:10mm}}
</style></head><body>
<div class="bar"><b>Daily summary</b>
<?php if ($picker): ?>
<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0">
<input type="date" name="date" value="<?= $e($date) ?>" style="padding:8px;border-radius:8px;border:0">
<select name="controller_id" style="padding:8px;border-radius:8px;border:0"><option value="0">All stations</option>
<?php foreach (db()->fetchAll("SELECT id, station_name FROM controllers WHERE is_active = 1 ORDER BY station_name") as $c): ?>
<option value="<?= (int)$c['id'] ?>" <?= $cid == $c['id'] ? 'selected' : '' ?>><?= $e($c['station_name']) ?></option><?php endforeach; ?>
</select><button type="submit">Show</button></form>
<?php endif; ?>
<button type="button" onclick="window.print()">Print / Save as PDF</button></div>
<?php if (!$stations): ?><div class="page"><p>No station found.</p></div><?php endif; ?>
<?php $i = 0; foreach ($stations as $st): $i++;
  $L = madeyaLiterStats((int)$st['id'], $date);
  $F = madeyaFuelStatus((int)$st['id']);
  $grand['served'] += $L['served']; $grand['liters'] += $L['liters'];
  $first = db()->fetchOne("SELECT MIN(served_at) a, MAX(served_at) b FROM report WHERE controller_id = ? AND report_date = ?", [(int)$st['id'], $date]);
  $isToday = ($date === date('Y-m-d'));
?>
<div class="page<?= $i < count($stations) ? ' brk' : '' ?>">
  <div class="hd"><img src="<?= $e($assets) ?>assets/img/etel-logo.png" alt=""><div><h1>Etel Madeya - Daily summary</h1><small><?= $e(date('l, d F Y', strtotime($date))) ?></small></div></div>
  <h2><?= $e($st['station_name']) ?></h2>
  <div class="sub"><?= $e(trim(($st['region'] ? $st['region'] . ' - ' : '') . $st['station_address'], ' -')) ?></div>
  <div class="kpis">
    <div class="kpi"><b><?= (int)$L['served'] ?></b><span>Vehicles served</span></div>
    <div class="kpi"><b><?= nf($L['liters']) ?> L</b><span>Fuel given (est.)</span></div>
    <div class="kpi"><b><?= nf($L['avg']) ?> L</b><span>Average per vehicle</span></div>
    <div class="kpi"><b><?= $first && $first['a'] ? $e(date('H:i', strtotime($first['a']))) . ' - ' . $e(date('H:i', strtotime($first['b']))) : '-' ?></b><span>First - last served</span></div>
  </div>
  <table><thead><tr><th>Vehicle type</th><th class="n">Tank (L)</th><th class="n">Served</th><th class="n">Liters</th><th class="n">Avg (L)</th></tr></thead><tbody>
  <?php $any = false; foreach ($L['by_type'] as $t): if (!$t['served']) continue; $any = true; ?>
    <tr><td><?= $e($t['name']) ?></td><td class="n"><?= $t['tank_liters'] === null ? '-' : nf($t['tank_liters']) ?></td><td class="n"><?= (int)$t['served'] ?></td><td class="n"><?= nf($t['liters']) ?></td><td class="n"><?= $t['served'] ? nf($t['liters'] / $t['served']) : '-' ?></td></tr>
  <?php endforeach; if (!$any): ?><tr><td colspan="5" style="color:#888">No vehicles served.</td></tr><?php endif; ?>
  </tbody><tfoot><tr><td>Total</td><td></td><td class="n"><?= (int)$L['served'] ?></td><td class="n"><?= nf($L['liters']) ?></td><td class="n"><?= nf($L['avg']) ?></td></tr></tfoot></table>
  <?php if ($L['unknown']): ?><div class="foot"><?= (int)$L['unknown'] ?> vehicle(s) have no tank size, so they are counted but add no liters.</div><?php endif; ?>
  <?php if ($isToday): ?>
    <h2 style="margin-top:16px">Still waiting now</h2>
    <div class="sub"><?= (int)$L['queued'] ?> vehicle(s) in the queue, needing about <?= nf($L['queue_liters']) ?> L.</div>
    <?php if ($F['set']): ?>
      <div class="<?= in_array($F['status'], ['short','out']) ? 'warn' : 'okb' ?>">Fuel left: <?= nf($F['remaining']) ?> L (of <?= nf($F['available']) ?> L entered<?= $F['updated_at'] ? ' at ' . $e(date('H:i d/m', strtotime($F['updated_at']))) : '' ?>).
        <?php if ($F['status'] === 'out'): ?> NO FUEL LEFT.<?php elseif ($F['status'] === 'short'): ?> Not enough for the queue - short by about <?= nf($F['short_by']) ?> L.<?php elseif ($F['status'] === 'low'): ?> Running low for the queue.<?php else: ?> Enough for the queue.<?php endif; ?></div>
    <?php endif; ?>
  <?php endif; ?>
  <?php
    $dl = db()->fetchOne("SELECT COALESCE(SUM(liters),0) l, COUNT(*) n FROM fuel_deliveries WHERE controller_id = ? AND kind = 'delivery' AND DATE(created_at) = ?", [(int)$st['id'], $date]);
    if ($dl && (int)$dl['n'] > 0): ?>
    <div class="okb">Fuel delivered this day: <b><?= nf($dl['l']) ?> L</b> in <?= (int)$dl['n'] ?> delivery(ies).</div>
  <?php endif;
    $cl = db()->fetchOne("SELECT * FROM day_closings WHERE controller_id = ? AND close_date = ? AND reopened_at IS NULL", [(int)$st['id'], $date]);
    if ($cl): ?>
    <div class="okb">Day closed at <?= $e(date('H:i', strtotime($cl['closed_at']))) ?><?= $cl['closed_by'] ? ' by ' . $e($cl['closed_by']) : '' ?>: <?= (int)$cl['served'] ?> vehicles, <?= nf($cl['liters']) ?> L<?= (int)$cl['queued_left'] ? ', ' . (int)$cl['queued_left'] . ' still waiting (carried to the next day)' : '' ?>.</div>
  <?php endif; ?>
  <div class="sign"><div>Station controller</div><div>Checked by</div></div>
  <div class="foot">Generated <?= $e(date('d/m/Y H:i')) ?> - liters use the recorded amount, or the vehicle type's tank size.</div>
</div>
<?php endforeach; ?>
<?php if (count($stations) > 1): ?>
<div class="page"><div class="hd"><img src="<?= $e($assets) ?>assets/img/etel-logo.png" alt=""><div><h1>All stations - <?= $e(date('d F Y', strtotime($date))) ?></h1></div></div>
<div class="kpis"><div class="kpi"><b><?= count($stations) ?></b><span>Stations</span></div><div class="kpi"><b><?= (int)$grand['served'] ?></b><span>Vehicles served</span></div><div class="kpi"><b><?= nf($grand['liters']) ?> L</b><span>Fuel given (est.)</span></div></div></div>
<?php endif; ?>
</body></html>
