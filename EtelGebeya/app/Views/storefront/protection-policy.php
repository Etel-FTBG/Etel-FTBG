<?php
use App\Core\View;
$locale = $locale ?? 'en';
ob_start();
?>
<style>
.pp-wrap{max-width:820px;margin:0 auto;padding:1.5rem 1rem 3rem}
.pp-section{background:var(--card,#130d05);border:1.5px solid var(--border-dim,rgba(212,175,55,.08));border-radius:18px;padding:1.4rem 1.6rem;margin-bottom:1.1rem}
.pp-section h2{font-size:1.05rem;color:var(--g2,#D4AF37);margin:0 0 .7rem;display:flex;align-items:center;gap:.5rem}
.pp-section p, .pp-section li{font-size:.88rem;line-height:1.8;color:var(--text-dim,#C9B88A)}
.pp-section ul{margin:.3rem 0;padding-left:1.3rem}
.pp-badge{display:inline-block;background:rgba(212,175,55,.1);color:var(--g2,#D4AF37);border:1px solid rgba(212,175,55,.2);border-radius:8px;padding:.15rem .6rem;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem}
</style>

<div class="pp-wrap">
  <div style="text-align:center;margin-bottom:1.5rem">
    <h1 class="cp-page-title"><i class="bi bi-shield-check"></i> Buyer &amp; Seller Protection Policy</h1>
    <p style="color:var(--text-muted,#7A6840);font-size:.85rem">How ኢE Pay Wallet escrow protects every order on Etel Gebeya</p>
  </div>

  <div class="pp-section">
    <span class="pp-badge">For Buyers</span>
    <h2><i class="bi bi-lock"></i> Your payment is held, not sent instantly</h2>
    <p>When you pay with the ኢE Pay Wallet, your money is not sent to the seller right away. It is held safely by
       Etel Gebeya until your order is confirmed as received. This protects you from paying for something that
       never arrives, arrives damaged, or isn't what was described.</p>
    <ul>
      <li><strong>Cancel any time before shipping</strong> -- while your order is still "New" or "Confirmed", you can
          cancel it yourself and your payment is refunded to your wallet immediately.</li>
      <li><strong>Confirm when you receive it</strong> -- once the seller marks your order delivered, tap
          <em>"I Received My Order"</em> on your order page to release payment to the seller.</li>
      <li><strong>Auto-confirm after 5 days</strong> -- if you don't confirm manually, payment automatically releases
          to the seller 5 days after delivery, unless you've reported a problem.</li>
      <li><strong>Report a problem</strong> -- if something is wrong (not delivered, wrong item, defective, not as
          described), tap <em>"Report a Problem"</em> on your order page before the 5 days are up. This pauses the
          release and our team reviews it.</li>
    </ul>
  </div>

  <div class="pp-section">
    <span class="pp-badge">For Sellers</span>
    <h2><i class="bi bi-shop"></i> You get paid once the customer confirms</h2>
    <p>Ship what was ordered, mark each order item as Confirmed &rarr; Shipped &rarr; Delivered promptly and
       accurately in your Vendor Panel. Your payout for that item is released automatically once the customer
       confirms receipt, or 5 days after you mark it delivered if the customer doesn't respond.</p>
    <ul>
      <li>Marking an item "Delivered" before it has actually reached the customer is a violation and can result in
          your store being suspended.</li>
      <li>If a customer opens a dispute, your payout for that order is held until an admin reviews it. Respond
          honestly and promptly to any dispute contact -- repeated disputes against your store can lead to review or
          suspension.</li>
      <li>Payouts are sent directly to your Etel Pay wallet (or your configured payout method in Vendor Settings) --
          no manual payout request needed for wallet-paid orders.</li>
    </ul>
  </div>

  <div class="pp-section">
    <span class="pp-badge">Dispute Process</span>
    <h2><i class="bi bi-flag"></i> What happens when a problem is reported</h2>
    <p>A buyer's dispute report pauses the payout for that order. An Etel Gebeya admin reviews the order, delivery
       status, and both sides' account of what happened, then decides one of two outcomes:</p>
    <ul>
      <li><strong>Refund the buyer</strong> -- the held payment returns to the customer's ኢE Pay Wallet.</li>
      <li><strong>Release to the seller</strong> -- if the order was fulfilled correctly, the held payment is
          released to the seller as normal.</li>
    </ul>
    <p>Cash on Delivery, Telebirr and CBE Birr orders are not held in escrow (no money passes through the platform
       wallet for those), so this policy applies specifically to ኢE Pay Wallet payments.</p>
  </div>
</div>

<?php
$__content = ob_get_clean();
echo \App\Core\View::capture('_layouts/public', [
    '__content' => $__content,
    'pageTitle'  => $pageTitle ?? 'Buyer & Seller Protection Policy -- Etel Gebeya',
    'locale'     => $locale ?? 'en',
]);
