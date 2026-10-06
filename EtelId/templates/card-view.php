<?php
/**
 * Etel-ID Premium — 3D Card View Wrapper v3.3.0
 * Renders front + back in a CSS perspective flip scene.
 * Required: $member (object)
 * Optional: $show_flip_btn (bool), $show_print_btn (bool)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! isset($show_flip_btn) )  $show_flip_btn  = true;
if ( ! isset($show_print_btn) ) $show_print_btn = false;

if ( ! isset($companies) ) $companies = EtelID_DB::get_companies($member->id);

$uid = 'eid_' . $member->id . '_' . substr(md5($member->etel_id), 0, 6);
?>
<style>
.eid-cv-scene { perspective:1200px; display:inline-block; cursor:pointer; }
.eid-cv-wrap  {
  width:460px; height:290px; position:relative;
  transform-style:preserve-3d;
  transition:transform .8s cubic-bezier(.4,.2,.2,1);
}
@media(max-width:500px){ .eid-cv-wrap{ width:320px; height:202px; } }
.eid-cv-wrap.flipped { transform:rotateY(180deg); }
.eid-cv-face {
  position:absolute; inset:0;
  border-radius:10px; overflow:hidden;
  backface-visibility:hidden;
  -webkit-backface-visibility:hidden;
  box-shadow:0 12px 32px rgba(0,0,0,.35);
}
.eid-cv-face-back { transform:rotateY(180deg); }
.eid-cv-face .eid-card-front,
.eid-cv-face .eid-card-back { position:absolute; inset:0; border-radius:10px; }
</style>

<div style="display:flex;flex-direction:column;align-items:center;gap:12px;">
  <div class="eid-cv-scene" onclick="etelIDFlip('<?php echo esc_js($uid); ?>')">
    <div class="eid-cv-wrap" id="<?php echo esc_attr($uid); ?>">

      <!-- FRONT FACE -->
      <div class="eid-cv-face">
        <?php include ETELID_PATH . 'templates/card-front.php'; ?>
      </div>

      <!-- BACK FACE (different template = different data) -->
      <div class="eid-cv-face eid-cv-face-back">
        <?php include ETELID_PATH . 'templates/card-back.php'; ?>
      </div>

    </div>
  </div>

  <?php if ($show_flip_btn): ?>
  <button onclick="etelIDFlip('<?php echo esc_js($uid); ?>')"
    style="background:rgba(212,175,55,.1);border:1.5px solid rgba(212,175,55,.4);color:#D4AF37;padding:8px 22px;border-radius:20px;font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:2px;text-transform:uppercase;cursor:pointer;transition:all .2s;">
    ↺ &nbsp; FLIP CARD
  </button>
  <?php endif; ?>

  <?php if ($show_print_btn): ?>
  <a href="<?php echo esc_url(home_url('/print/'.$member->etel_id)); ?>"
     target="_blank"
     style="background:linear-gradient(135deg,#4A3210,#7A5518,#C8922A,#D4AF37);color:#FDF6D8;padding:10px 22px;border-radius:8px;font-family:'Syne',sans-serif;font-size:13px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
    🖨 Print Card
  </a>
  <?php endif; ?>
</div>

<script>
function etelIDFlip(id) {
  var el = document.getElementById(id);
  if (el) el.classList.toggle('flipped');
}
</script>
