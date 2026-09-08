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
  max-width: 440px;
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

.etelid-badge {
  display: inline-flex; align-items: center; gap: .4rem;
  background: rgba(127,224,127,.08); border: 1px solid rgba(127,224,127,.25);
  border-radius: 20px; padding: .25rem .75rem;
  font-size: .75rem; font-weight: 700; color: #7FE07F;
  margin-bottom: 1.5rem;
}

.form-group { margin-bottom: 1.1rem; }
.form-label {
  display: block; font-size: .8rem; font-weight: 700;
  color: #C9B88A; margin-bottom: .4rem;
  text-transform: uppercase; letter-spacing: .05em;
}
.input-wrap { position: relative; }
.input-wrap i {
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

.pw-toggle {
  position: absolute; right: .85rem; top: 50%; transform: translateY(-50%);
  background: none; border: none; color: #7A6840; cursor: pointer; font-size: .95rem;
  transition: color .2s;
}
.pw-toggle:hover { color: #D4AF37; }

.submit-btn {
  width: 100%; padding: .85rem;
  background: linear-gradient(135deg, #1f4a1f, #2a7a2a);
  color: #7FE07F; font-weight: 800; font-size: 1rem;
  border: 1px solid rgba(127,224,127,.3);
  border-radius: 12px; cursor: pointer; margin-top: .5rem;
  transition: opacity .2s, transform .15s, box-shadow .2s;
  display: flex; align-items: center; justify-content: center; gap: .5rem;
}
.submit-btn:hover { opacity: .92; transform: translateY(-1px); box-shadow: 0 8px 28px rgba(127,224,127,.2); }
.submit-btn:active { transform: translateY(0); }

.staff-toggle {
  width: 100%; padding: .6rem; margin-top: .75rem;
  background: none; border: 1px solid rgba(212,175,55,.12);
  border-radius: 10px; color: #5C4209; font-size: .8rem; cursor: pointer;
  transition: border-color .2s, color .2s;
}
.staff-toggle:hover { border-color: rgba(212,175,55,.3); color: #7A6840; }

.staff-panel {
  margin-top: .75rem;
  padding: 1rem;
  background: rgba(255,255,255,.02);
  border: 1px solid rgba(212,175,55,.1);
  border-radius: 12px;
}
.staff-submit {
  width: 100%; padding: .75rem;
  background: linear-gradient(135deg, #FFE066, #D4AF37, #B8902E);
  color: #080604; font-weight: 800; font-size: .9rem;
  border: none; border-radius: 10px; cursor: pointer; margin-top: .5rem;
  display: flex; align-items: center; justify-content: center; gap: .5rem;
}
.staff-submit:hover { opacity: .9; }

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

.hidden { display: none !important; }
</style>

<div class="auth-wrap">
  <div class="auth-card">

    <!-- Logo -->
    <a href="<?= View::url('/') ?>" class="auth-logo">
      <div class="auth-logo-icon">EG</div>
      <span class="auth-logo-text">Etel Gebeya</span>
    </a>

    <div class="etelid-badge">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
      Powered by Etel-ID
    </div>

    <h1 class="auth-heading"><span>Sign in to Etel Gebeya</span></h1>
    <p class="auth-sub">Use your Etel-ID account to access all Etel services.</p>

    <!-- Flash -->
    <?php if ($flash): ?>
      <div class="flash-msg flash-<?= e($flash['type']) ?>">
        <i class="bi bi-<?= $flash['type'] === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
        <?= e($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <!-- PRIMARY: Etel-ID Login -->
    <form method="POST" action="<?= View::url('/login/etelid') ?>" autocomplete="on">
      <?= csrf() ?>

      <div class="form-group">
        <label class="form-label" for="etelid_identifier">Phone Number or Etel-ID</label>
        <div class="input-wrap">
          <i class="bi bi-person-badge"></i>
          <input type="text" id="etelid_identifier" name="etelid_identifier" class="form-input"
                 placeholder="+251 9X XXX XXXX  or  EG-2026-xxxxx"
                 required autofocus autocomplete="tel"
                 value="<?= e($_POST['etelid_identifier'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="etelid_password">Etel-ID Password</label>
        <div class="input-wrap">
          <i class="bi bi-lock"></i>
          <input type="password" id="etelid_password" name="etelid_password" class="form-input"
                 placeholder="Your Etel-ID password" required autocomplete="current-password">
          <button type="button" class="pw-toggle" onclick="togglePw('etelid_password',this)" tabindex="-1">
            <i class="bi bi-eye"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="submit-btn">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M12 2a5 5 0 1 0 0 10A5 5 0 0 0 12 2zm0 12c-5.33 0-8 2.67-8 4v2h16v-2c0-1.33-2.67-4-8-4z"/><path d="M20 8l2 2-6 6-3-3 2-2 1 1z"/></svg>
        Sign In with Etel-ID
      </button>
    </form>

    <!-- FALLBACK: Email/Password (staff / legacy) -->
    <button type="button" class="staff-toggle" onclick="document.getElementById('staff-panel').classList.toggle('hidden')">
      Staff / email login &rsaquo;
    </button>

    <div id="staff-panel" class="staff-panel hidden">
      <form method="POST" action="<?= View::url('/login') ?>" autocomplete="on">
        <?= csrf() ?>
        <div class="form-group">
          <label class="form-label" for="email">Email Address</label>
          <div class="input-wrap">
            <i class="bi bi-envelope"></i>
            <input type="email" id="email" name="email" class="form-input"
                   placeholder="you@email.com" required autocomplete="email"
                   value="<?= e($_POST['email'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <div class="input-wrap">
            <i class="bi bi-lock"></i>
            <input type="password" id="password" name="password" class="form-input"
                   placeholder="********" required autocomplete="current-password">
            <button type="button" class="pw-toggle" onclick="togglePw('password',this)" tabindex="-1">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="staff-submit">
          <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
      </form>
    </div>

    <div class="auth-switch">
      Don't have an account? <a href="<?= View::url('/register') ?>">Create Account</a>
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
</script>
