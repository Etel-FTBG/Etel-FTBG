<?php
declare(strict_types=1);
use App\Core\View;
use App\Core\Auth;

$locale = $GLOBALS['__i18n']->locale();
$flash  = $flash ?? null;
$token  = Auth::getInstance()->csrfToken();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&display=swap" rel="stylesheet">
<style>
.auth-shell {
  min-height: calc(100vh - var(--header-h, 64px));
  display: grid;
  grid-template-columns: 1fr;
}
@media (min-width: 960px) {
  .auth-shell { grid-template-columns: 1fr 1fr; }
}

/* ── Brand panel (desktop only) ───────────────────────────── */
.auth-brand {
  display: none;
  position: relative;
  padding: 3.5rem 3.5rem 3rem;
  background:
    radial-gradient(ellipse 60% 50% at 22% 15%, rgba(212,175,55,.16), transparent 62%),
    radial-gradient(ellipse 55% 45% at 85% 90%, rgba(212,175,55,.08), transparent 60%),
    linear-gradient(165deg, #130d05 0%, #0a0703 60%, #050302 100%);
  border-right: 1px solid rgba(212,175,55,.14);
  overflow: hidden;
  flex-direction: column;
  justify-content: space-between;
}
@media (min-width: 960px) { .auth-brand { display: flex; } }
.auth-brand::after {
  content: '';
  position: absolute; inset: 0;
  background-image: radial-gradient(rgba(212,175,55,.35) 1px, transparent 1px);
  background-size: 26px 26px;
  opacity: .06;
  pointer-events: none;
}
.auth-brand-top { position: relative; z-index: 1; }
.auth-brand-word {
  font-family: 'Cormorant Garamond', 'Sora', serif;
  font-weight: 600;
  font-size: clamp(2.4rem, 3.6vw, 3.4rem);
  line-height: 1.08;
  color: var(--text);
  letter-spacing: -.01em;
}
.auth-brand-word em { font-style: normal; color: var(--g1); }
.auth-brand-sub {
  margin-top: 1.1rem;
  max-width: 32ch;
  font-size: .96rem;
  line-height: 1.6;
  color: var(--text2);
}
.auth-trust {
  position: relative; z-index: 1;
  display: grid;
  gap: .85rem;
}
.auth-trust-item {
  display: flex; align-items: center; gap: .75rem;
  font-size: .86rem; color: var(--text2);
}
.auth-trust-item i {
  width: 30px; height: 30px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  border-radius: 9px;
  background: rgba(212,175,55,.1);
  border: 1px solid rgba(212,175,55,.22);
  color: var(--g2);
  font-size: .85rem;
}

/* ── Form column ───────────────────────────────────────────── */
.auth-formcol {
  display: flex; align-items: center; justify-content: center;
  padding: 3rem 1.5rem;
  background: var(--bg);
}
.auth-card {
  width: 100%;
  max-width: 400px;
  animation: authRise .45s cubic-bezier(.22,1,.36,1) both;
}
@media (prefers-reduced-motion: reduce) { .auth-card { animation: none; } }
@keyframes authRise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

.auth-logo-mobile {
  display: flex; align-items: center; gap: .6rem;
  margin-bottom: 2rem; text-decoration: none;
}
@media (min-width: 960px) { .auth-logo-mobile { display: none; } }
.auth-logo-mark {
  width: 38px; height: 38px; border-radius: 10px;
  background: var(--grad);
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; font-weight: 900; color: #080604;
}
.auth-logo-mobile span { font-weight: 800; font-size: 1.02rem; color: var(--text); }

.auth-heading { font-size: 1.5rem; font-weight: 800; color: var(--text); margin-bottom: .3rem; letter-spacing: -.01em; }
.auth-sub { color: var(--text2); font-size: .87rem; margin-bottom: 1.6rem; }

.etelid-badge {
  display: inline-flex; align-items: center; gap: .4rem;
  background: rgba(46,204,113,.08); border: 1px solid rgba(46,204,113,.24);
  border-radius: 20px; padding: .28rem .8rem;
  font-size: .74rem; font-weight: 700; color: var(--green, #2ECC71);
  margin-bottom: 1.4rem;
}

.form-group { margin-bottom: 1.05rem; }
.form-label {
  display: block; font-size: .78rem; font-weight: 700;
  color: var(--text2); margin-bottom: .4rem;
  text-transform: uppercase; letter-spacing: .05em;
}
.input-wrap { position: relative; }
.input-wrap > i {
  position: absolute; left: .85rem; top: 50%; transform: translateY(-50%);
  color: var(--text3); font-size: .92rem; pointer-events: none;
}
.form-input {
  width: 100%;
  padding: .72rem .9rem .72rem 2.5rem;
  background: rgba(255,255,255,.035);
  border: 1.5px solid var(--border);
  border-radius: 11px;
  color: var(--text);
  font-size: .92rem;
  font-family: inherit;
  outline: none;
  transition: border-color .15s, box-shadow .15s, background .15s;
}
.form-input::placeholder { color: var(--text2); opacity: .55; }
.form-input:hover { border-color: rgba(212,175,55,.3); }
.form-input:focus {
  border-color: var(--g2);
  background: rgba(212,175,55,.05);
  box-shadow: 0 0 0 3px rgba(212,175,55,.14);
}

.pw-toggle {
  position: absolute; right: .6rem; top: 50%; transform: translateY(-50%);
  width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;
  background: none; border: none; border-radius: 7px; color: var(--text2); cursor: pointer; font-size: .95rem;
  transition: color .15s, background .15s;
}
.pw-toggle:hover { color: var(--g2); background: rgba(212,175,55,.08); }

.submit-btn {
  width: 100%; padding: .8rem;
  background: var(--grad);
  color: #080604; font-weight: 800; font-size: .95rem;
  border: none;
  border-radius: 11px; cursor: pointer; margin-top: .3rem;
  transition: transform .15s, box-shadow .2s, opacity .15s;
  display: flex; align-items: center; justify-content: center; gap: .55rem;
  box-shadow: var(--shadow-gold, 0 10px 26px -8px rgba(212,175,55,.45));
}
.submit-btn:hover { transform: translateY(-1px); box-shadow: 0 14px 32px -6px rgba(212,175,55,.5); }
.submit-btn:active { transform: translateY(0); }
.submit-btn:disabled { opacity: .7; cursor: default; transform: none; }
.submit-btn .spin {
  width: 15px; height: 15px; border-radius: 50%;
  border: 2px solid rgba(8,6,4,.3); border-top-color: #080604;
  animation: spin .7s linear infinite; display: none;
}
.submit-btn.is-loading .spin, .staff-submit.is-loading .spin { display: inline-block; }
.submit-btn.is-loading .btn-label, .staff-submit.is-loading .btn-label { display: none; }
.staff-submit .spin {
  width: 15px; height: 15px; border-radius: 50%;
  border: 2px solid rgba(247,239,217,.25); border-top-color: var(--text);
  animation: spin .7s linear infinite; display: none;
}
.staff-submit:disabled { opacity: .7; cursor: default; }
@keyframes spin { to { transform: rotate(360deg); } }

.staff-toggle {
  width: 100%; padding: .55rem; margin-top: .8rem;
  background: none; border: 1px dashed var(--border);
  border-radius: 10px; color: var(--text2); font-size: .8rem; cursor: pointer;
  transition: border-color .15s, color .15s;
}
.staff-toggle:hover { border-color: rgba(212,175,55,.35); color: var(--text2); }

.staff-panel {
  margin-top: .8rem;
  padding: 1rem;
  background: rgba(255,255,255,.02);
  border: 1px solid var(--border);
  border-radius: 12px;
}
.staff-submit {
  width: 100%; padding: .72rem;
  background: rgba(255,255,255,.05);
  border: 1.5px solid var(--border);
  color: var(--text); font-weight: 700; font-size: .88rem;
  border-radius: 10px; cursor: pointer; margin-top: .5rem;
  display: flex; align-items: center; justify-content: center; gap: .5rem;
  transition: border-color .15s, background .15s;
}
.staff-submit:hover { border-color: var(--g2); background: rgba(212,175,55,.06); }

.flash-msg {
  display: flex; align-items: flex-start; gap: .6rem;
  padding: .8rem 1rem; border-radius: 10px;
  font-size: .86rem; margin-bottom: 1.25rem;
  border: 1px solid transparent;
}
.flash-error   { background: rgba(239,68,68,.1);   border-color: rgba(239,68,68,.3);  color: #FCA5A5; }
.flash-success { background: rgba(16,185,129,.1);  border-color: rgba(16,185,129,.3); color: #6EE7B7; }

.auth-switch { text-align: center; margin-top: 1.6rem; font-size: .86rem; color: var(--text2); }
.auth-switch a { color: var(--g2); font-weight: 700; text-decoration: none; }
.auth-switch a:hover { color: var(--g1); text-decoration: underline; }

.hidden { display: none !important; }

/* ── Browser-native surfaces, themed ──────────────────────── */
.auth-shell ::selection { background: rgba(212,175,55,.32); color: #fff; }
.auth-shell input { caret-color: var(--g2); }
.auth-shell a:focus-visible,
.auth-shell button:focus-visible,
.auth-shell input:focus-visible {
  outline: 2px solid var(--g2); outline-offset: 2px; border-radius: 8px;
}
</style>

<div class="auth-shell">

  <!-- Brand panel -->
  <aside class="auth-brand">
    <div class="auth-brand-top">
      <a href="<?= View::url('/') ?>" class="auth-logo-mobile" style="display:flex;margin-bottom:2.5rem">
        <div class="auth-logo-mark">EG</div>
        <span>Etel Gebeya</span>
      </a>
      <h1 class="auth-brand-word">The marketplace<br>built for <em>Ethiopia</em>.</h1>
      <p class="auth-brand-sub">One account, powered by Etel-ID, across every Etel service &mdash; shopping, delivery, and payments.</p>
    </div>
    <div class="auth-trust">
      <div class="auth-trust-item"><i class="bi bi-shield-check"></i> Buyer Protection on every order</div>
      <div class="auth-trust-item"><i class="bi bi-patch-check"></i> Verified vendors only</div>
      <div class="auth-trust-item"><i class="bi bi-geo-alt"></i> Proudly built in Hawassa</div>
    </div>
  </aside>

  <!-- Form -->
  <div class="auth-formcol">
    <div class="auth-card">

      <a href="<?= View::url('/') ?>" class="auth-logo-mobile">
        <div class="auth-logo-mark">EG</div>
        <span>Etel Gebeya</span>
      </a>

      <div class="etelid-badge">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        Powered by Etel-ID
      </div>

      <h2 class="auth-heading">Sign in to your account</h2>
      <p class="auth-sub">Use your Etel-ID to access all Etel services.</p>

      <?php if ($flash): ?>
        <div class="flash-msg flash-<?= e($flash['type']) ?>">
          <i class="bi bi-<?= $flash['type'] === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
          <?= e($flash['msg']) ?>
        </div>
      <?php endif; ?>

      <!-- PRIMARY: Etel-ID Login -->
      <form method="POST" action="<?= View::url('/login/etelid') ?>" autocomplete="on" onsubmit="return armSubmit(this)">
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
            <button type="button" class="pw-toggle" onclick="togglePw('etelid_password',this)" tabindex="-1" aria-label="Show password">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="submit-btn">
          <span class="spin"></span>
          <i class="bi bi-box-arrow-in-right btn-label"></i>
          <span class="btn-label">Sign In with Etel-ID</span>
        </button>
      </form>

      <!-- FALLBACK: Email/Password (staff / legacy) -->
      <button type="button" class="staff-toggle" onclick="document.getElementById('staff-panel').classList.toggle('hidden')">
        Staff / email login &rsaquo;
      </button>

      <div id="staff-panel" class="staff-panel hidden">
        <form method="POST" action="<?= View::url('/login') ?>" autocomplete="on" onsubmit="return armSubmit(this)">
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
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" for="password">Password</label>
            <div class="input-wrap">
              <i class="bi bi-lock"></i>
              <input type="password" id="password" name="password" class="form-input"
                     placeholder="********" required autocomplete="current-password">
              <button type="button" class="pw-toggle" onclick="togglePw('password',this)" tabindex="-1" aria-label="Show password">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="staff-submit">
            <span class="spin"></span>
            <i class="bi bi-box-arrow-in-right btn-label"></i>
            <span class="btn-label">Sign In</span>
          </button>
        </form>
      </div>

      <div class="auth-switch">
        Don't have an account? <a href="<?= View::url('/register') ?>">Create Account</a>
      </div>

    </div>
  </div>
</div>

<script>
function togglePw(id, btn) {
  const inp = document.getElementById(id);
  const isText = inp.type === 'text';
  inp.type = isText ? 'password' : 'text';
  btn.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
  btn.querySelector('i').className = isText ? 'bi bi-eye' : 'bi bi-eye-slash';
}
function armSubmit(form) {
  const btn = form.querySelector('.submit-btn, .staff-submit');
  if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
  return true;
}
</script>
