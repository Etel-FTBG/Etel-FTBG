<?php
use App\Core\View;
require __DIR__.'/_layout.php';
ob_start();
?>
<div class="dash-content">
  <div class="dash-header">
    <div><h1 class="dash-title">Payouts</h1><p class="dash-sub">Track your earnings and withdrawal requests</p></div>
  </div>
  <div style="text-align:center;padding:5rem 2rem;color:var(--eg-text-dim)">
    <div style="font-size:3.5rem;margin-bottom:1rem">/* U+1F4B3 */</div>
    <h3 style="margin-bottom:.5rem">Payout system coming soon</h3>
    <p>Once enabled, you'll be able to request withdrawals via Telebirr, CBE Birr, or bank transfer.</p>
  </div>
</div>
<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), ['__content'=>$__content,'navItems'=>$navItems,'role'=>'vendor','pageTitle'=>$pageTitle]));
