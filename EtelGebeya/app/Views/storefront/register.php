<?php
declare(strict_types=1);
use App\Core\View;
use App\Core\Auth;

$locale = $GLOBALS['__i18n']->locale();
$flash  = $flash ?? null;
$token  = Auth::getInstance()->csrfToken();
?>
<style>
.auth-wrap {
  min-height: 80vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 3rem 1.25rem;
  background: radial-gradient(ellipse 70% 60% at 50% 0%, rgba(212,175,55,.07), transparent);
}
.auth-card {
  width: 100%;
  max-width: 500px;
  background: linear-gradient(145deg, #1c1208, #130d05);
  border: 1px solid rgba(212,175,55,.22);
  border-radius: 24px;
  padding: 2.5rem 2.25rem;
  box-shadow: 0 24px 60px rgba(0,0,0,.6);
  animation: fadeUp .5s cubic-bezier(.22,1,.36,1) both;
}
@keyframes fadeUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:none} }

.auth-logo {
  display: flex; align-items: center; gap: .65rem;
  margin-bottom: 2rem; text-decoration: none;
}
.auth-logo-icon {
  width: 40px; height: 40px;
  background: linear-gradient(135deg, #FFE066, #D4AF37, #B8902E);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; font-weight: 900; color: #080604;
  box-shadow: 0 4px 14px rgba(212,175,55,.35);
}
.auth-logo-text { font-size: 1.1rem; font-weight: 800; color: #F7EFD9; }

.auth-heading { font-size: 1.55rem; font-weight: 900; margin-bottom: .35rem; }
.auth-heading span {
  background: linear-gradient(135deg, #FFE066, #D4AF37);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.auth-sub { color: #7A6840; font-size: .88rem; margin-bottom: 1.75rem; }

/* Role selector */
.role-selector { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-bottom: 1.5rem; }
.role-option { position: relative; }
.role-option input[type=radio] { position: absolute; opacity: 0; pointer-events: none; }
.role-label {
  display: flex; flex-direction: column; align-items: center; gap: .4rem;
  padding: 1rem .75rem;
  background: rgba(255,255,255,.03);
  border: 1.5px solid rgba(212,175,55,.15);
  border-radius: 14px;
  cursor: pointer;
  transition: border-color .2s, background .2s, box-shadow .2s;
  text-align: center;
}
.role-label:hover { border-color: rgba(212,175,55,.35); background: rgba(212,175,55,.05); }
.role-option input:checked + .role-label {
  border-color: #D4AF37;
  background: rgba(212,175,55,.1);
  box-shadow: 0 0 0 3px rgba(212,175,55,.12);
}
.role-icon { font-size: 1.8rem; line-height: 1; }
.role-title { font-weight: 700; font-size: .9rem; color: #F7EFD9; }
.role-desc  { font-size: .72rem; color: #7A6840; line-height: 1.4; }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
@media(max-width:480px) { .form-row { grid-template-columns: 1fr; } }

.form-group { margin-bottom: 1.1rem; }
.form-label {
  display: block; font-size: .8rem; font-weight: 700;
  color: #C9B88A; margin-bottom: .4rem;
  text-transform: uppercase; letter-spacing: .05em;
}
.input-wrap { position: relative; }
.input-wrap i.icon {
  position: absolute; left: .85rem; top: 50%; transform: translateY(-50%);
  color: #7A6840; font-size: .95rem; pointer-events: none;
}
.form-input {
  width: 100%;
  padding: .7rem .9rem .7rem 2.5rem;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(212,175,55,.18);
  border-radius: 12px;
  color: #F7EFD9;
  font-size: .92rem;
  font-family: inherit;
  outline: none;
  transition: border-color .2s, box-shadow .2s, background .2s;
}
.form-input::placeholder { color: #5C4209; }
.form-input:focus {
  border-color: #D4AF37;
  background: rgba(212,175,55,.06);
  box-shadow: 0 0 0 3px rgba(212,175,55,.12);
}
.form-input.no-icon { padding-left: .9rem; }

.pw-toggle {
  position: absolute; right: .85rem; top: 50%; transform: translateY(-50%);
  background: none; border: none; color: #7A6840; cursor: pointer; font-size: .95rem;
  transition: color .2s;
}
.pw-toggle:hover { color: #D4AF37; }

.pw-strength { margin-top: .4rem; display: flex; gap: .25rem; }
.pw-strength-bar {
  flex: 1; height: 3px; border-radius: 2px;
  background: rgba(212,175,55,.12);
  transition: background .3s;
}

.terms-row {
  display: flex; align-items: flex-start; gap: .65rem;
  font-size: .82rem; color: #7A6840; margin-bottom: 1.25rem;
}
.terms-row input[type=checkbox] { accent-color: #D4AF37; margin-top: 2px; flex-shrink: 0; }
.terms-row a { color: #D4AF37; }

.submit-btn {
  width: 100%;
  padding: .85rem;
  background: linear-gradient(135deg, #FFE066, #D4AF37, #B8902E);
  color: #080604;
  font-weight: 800;
  font-size: 1rem;
  border: none;
  border-radius: 12px;
  cursor: pointer;
  transition: opacity .2s, transform .15s, box-shadow .2s;
  display: flex; align-items: center; justify-content: center; gap: .5rem;
}
.submit-btn:hover { opacity: .92; transform: translateY(-1px); box-shadow: 0 8px 28px rgba(212,175,55,.35); }

.flash-msg {
  display: flex; align-items: flex-start; gap: .6rem;
  padding: .8rem 1rem; border-radius: 10px;
  font-size: .88rem; margin-bottom: 1.25rem;
  border: 1px solid transparent;
}
.flash-error   { background: rgba(239,68,68,.1);   border-color: rgba(239,68,68,.3);  color: #FCA5A5; }
.flash-success { background: rgba(16,185,129,.1);  border-color: rgba(16,185,129,.3); color: #6EE7B7; }

.auth-switch { text-align: center; margin-top: 1.5rem; font-size: .88rem; color: #7A6840; }
.auth-switch a { color: #D4AF37; font-weight: 700; text-decoration: none; }
.auth-switch a:hover { color: #FFE066; }

.section-divider {
  font-size: .72rem; font-weight: 700; color: #5C4209;
  text-transform: uppercase; letter-spacing: .08em;
  margin: 1.25rem 0 .75rem;
  display: flex; align-items: center; gap: .75rem;
}
.section-divider::before, .section-divider::after {
  content: ''; flex: 1; height: 1px; background: rgba(212,175,55,.1);
}
</style>

<div class="auth-wrap">
  <div class="auth-card">

    <!-- Logo -->
    <a href="<?= View::url('/') ?>" class="auth-logo">
      <div class="auth-logo-icon">EG</div>
      <span class="auth-logo-text">Etel Gebeya</span>
    </a>

    <h1 class="auth-heading"><span><?= t('auth.create_account') ?></span></h1>
    <p class="auth-sub"><?= t('auth.register_sub') ?></p>

    <!-- Flash -->
    <?php if ($flash): ?>
      <div class="flash-msg flash-<?= e($flash['type']) ?>">
        <i class="bi bi-<?= $flash['type'] === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
        <?= e($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="<?= View::url('/register') ?>" autocomplete="on" id="reg-form">
      <?= csrf() ?>

      <!-- Account type -->
      <div class="section-divider"><?= t('auth.i_want_to') ?></div>
      <div class="role-selector">
        <label class="role-option">
          <input type="radio" name="role" value="customer" checked>
          <span class="role-label">
            <span class="role-icon">&#x1F6CD;&#xFE0F;</span>
            <span class="role-title"><?= t('auth.role_buyer') ?></span>
            <span class="role-desc"><?= t('auth.role_buyer_desc') ?></span>
          </span>
        </label>
        <label class="role-option">
          <input type="radio" name="role" value="vendor">
          <span class="role-label">
            <span class="role-icon">&#x1F3EA;</span>
            <span class="role-title"><?= t('auth.role_vendor') ?></span>
            <span class="role-desc"><?= t('auth.role_vendor_desc') ?></span>
          </span>
        </label>
      </div>

      <!-- Personal info -->
      <div class="section-divider"><?= t('auth.your_details') ?></div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="name"><?= t('auth.full_name') ?></label>
          <div class="input-wrap">
            <i class="bi bi-person icon"></i>
            <input type="text" id="name" name="name" class="form-input"
                   placeholder="Abebe Kebede" required autocomplete="name"
                   value="<?= e($_POST['name'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="phone"><?= t('auth.phone') ?></label>
          <div class="input-wrap">
            <i class="bi bi-phone icon"></i>
            <input type="tel" id="phone" name="phone" class="form-input"
                   placeholder="+251 9X XXX XXXX" autocomplete="tel"
                   value="<?= e($_POST['phone'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="email"><?= t('auth.email') ?></label>
        <div class="input-wrap">
          <i class="bi bi-envelope icon"></i>
          <input type="email" id="email" name="email" class="form-input"
                 placeholder="you@email.com" required autocomplete="email"
                 value="<?= e($_POST['email'] ?? '') ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="password"><?= t('auth.password') ?></label>
          <div class="input-wrap">
            <i class="bi bi-lock icon"></i>
            <input type="password" id="password" name="password" class="form-input"
                   placeholder="<?= e(t('auth.min_8_chars')) ?>" required autocomplete="new-password"
                   oninput="checkStrength(this.value)">
            <button type="button" class="pw-toggle" onclick="togglePw('password',this)" tabindex="-1">
              <i class="bi bi-eye"></i>
            </button>
          </div>
          <div class="pw-strength" id="pw-bars">
            <div class="pw-strength-bar" id="bar1"></div>
            <div class="pw-strength-bar" id="bar2"></div>
            <div class="pw-strength-bar" id="bar3"></div>
            <div class="pw-strength-bar" id="bar4"></div>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="confirm"><?= t('auth.confirm_password') ?></label>
          <div class="input-wrap">
            <i class="bi bi-lock-fill icon"></i>
            <input type="password" id="confirm" name="confirm" class="form-input"
                   placeholder="<?= e(t('auth.repeat_password')) ?>" required autocomplete="new-password">
            <button type="button" class="pw-toggle" onclick="togglePw('confirm',this)" tabindex="-1">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Terms -->
      <label class="terms-row">
        <input type="checkbox" required>
        <?= t('auth.terms_agree') ?> <a href="<?= View::url('/terms') ?>"><?= t('auth.terms_link') ?></a>
        <?= t('auth.and') ?> <a href="<?= View::url('/privacy') ?>"><?= t('auth.privacy_link') ?></a>
      </label>

      <button type="submit" class="submit-btn">
        <i class="bi bi-person-plus"></i> <?= t('auth.create_account') ?>
      </button>
    </form>

    <div class="auth-switch">
      <?= t('auth.have_account') ?> <a href="<?= View::url('/login') ?>"><?= t('auth.sign_in') ?></a>
    </div>

  </div>
</div>

<script>
function togglePw(id, btn) {
  const inp = document.getElementById(id);
  const isText = inp.type === 'text';
  inp.type = isText ? 'password' : 'text';
  btn.querySelector('i').className = isText ? 'bi bi-eye' : 'bi bi-eye-slash';
}

function checkStrength(val) {
  let score = 0;
  if (val.length >= 8)                     score++;
  if (/[A-Z]/.test(val))                   score++;
  if (/[0-9]/.test(val))                   score++;
  if (/[^A-Za-z0-9]/.test(val))           score++;
  const colors = ['#EF4444','#F59E0B','#3B82F6','#10B981'];
  for (let i = 1; i <= 4; i++) {
    const bar = document.getElementById('bar' + i);
    bar.style.background = i <= score ? colors[score - 1] : 'rgba(212,175,55,.12)';
  }
}
</script>
