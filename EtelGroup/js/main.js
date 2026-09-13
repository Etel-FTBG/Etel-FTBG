/* ── SCROLL: glass nav + progress bar + active link ── */
(function() {
  const nav      = document.getElementById('nav');
  const bar      = document.getElementById('scroll-progress');
  const links    = document.querySelectorAll('.nav-links a[href^="#"]');
  const sections = ['hero','services','about','etel-id','contact'];

  function onScroll() {
    const y = window.scrollY;

    // Glass toggle
    nav.classList.toggle('scrolled', y > 40);

    // Progress bar
    const max = document.documentElement.scrollHeight - window.innerHeight;
    bar.style.width = max > 0 ? (y / max * 100) + '%' : '0%';

    // Active link highlight
    let current = 'hero';
    for (const id of sections) {
      const el = document.getElementById(id);
      if (el && el.getBoundingClientRect().top <= 90) current = id;
    }
    links.forEach(a => {
      const href = a.getAttribute('href').replace('#','');
      a.classList.toggle('active', href === current);
    });
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
})();

/* ══════════════════════════════════════════
   STEP 5 — ETEL-ID INTERACTIONS
══════════════════════════════════════════ */
(function() {

  /* ── Extend IntersectionObserver for new classes ── */
  const eidIO = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el = entry.target;

      if (el.classList.contains('js-eid-card') || el.classList.contains('js-eid-info')) {
        el.classList.add('visible');
      } else if (el.classList.contains('js-eid-benefit')) {
        const delay = parseInt(el.dataset.delay || 0);
        setTimeout(() => el.classList.add('visible'), delay * 110 + 300);
      } else if (el.classList.contains('js-eid-stats')) {
        el.classList.add('visible');
        // Animate counters
        el.querySelectorAll('.eid-stat-num[data-target]').forEach(num => {
          animateCounter(num);
        });
      }
      eidIO.unobserve(el);
    });
  }, { threshold: 0.15 });

  document.querySelectorAll('.js-eid-card, .js-eid-info, .js-eid-benefit, .js-eid-stats')
    .forEach(el => eidIO.observe(el));

  /* ── Counter animation ── */
  function animateCounter(el) {
    const target  = parseInt(el.dataset.target);
    const display = el.dataset.display;
    const suffix  = el.dataset.suffix || '';
    const duration = 1800;
    const start = performance.now();

    function step(now) {
      const progress = Math.min((now - start) / duration, 1);
      const ease = 1 - Math.pow(1 - progress, 3);
      const val = Math.round(ease * target);

      if (display) {
        el.textContent = progress >= 1 ? display : val;
      } else {
        el.textContent = val + suffix;
      }

      if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  /* ── ID Card mouse tilt ── */
  const card = document.getElementById('eid-card-visual');
  if (card) {
    const scene = card.parentElement;
    scene.addEventListener('mousemove', e => {
      const r = scene.getBoundingClientRect();
      const dx = (e.clientX - r.left  - r.width  / 2) / (r.width  / 2);
      const dy = (e.clientY - r.top   - r.height / 2) / (r.height / 2);
      card.style.animation = 'none';
      card.style.transform =
        `perspective(900px) rotateX(${-dy * 14}deg) rotateY(${dx * 14}deg) translateZ(8px)`;
    });
    scene.addEventListener('mouseleave', () => {
      card.style.animation = '';
      card.style.transform = '';
    });
    scene.addEventListener('touchmove', e => {
      const t = e.touches[0], r = scene.getBoundingClientRect();
      const dx = (t.clientX - r.left  - r.width  / 2) / (r.width  / 2);
      const dy = (t.clientY - r.top   - r.height / 2) / (r.height / 2);
      card.style.animation = 'none';
      card.style.transform =
        `perspective(900px) rotateX(${-dy * 8}deg) rotateY(${dx * 8}deg) translateZ(4px)`;
    }, { passive: true });
    scene.addEventListener('touchend', () => {
      card.style.animation = '';
      card.style.transform = '';
    });
  }

})();

/* ── Tier switcher (global) ── */
function switchTier(btn, tier, color, enLabel, amLabel) {
  // Deactivate all
  document.querySelectorAll('.eid-tier-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  const card      = document.getElementById('eid-card-visual');
  const tierEl    = document.getElementById('card-tier-display');
  const numberEl  = document.getElementById('card-number-display');
  const magEl     = card ? card.querySelector('.card-mag') : null;

  const tiers = {
    founding: {
      bg: 'linear-gradient(135deg, rgba(42,26,6,0.95) 0%, rgba(26,14,0,0.98) 40%, rgba(36,22,4,0.97) 100%)',
      border: 'rgba(212,175,55,0.25)',
      magColor: '#D4AF37',
      numPrefix: 'ETL',
      label: 'Founding Member',
      labelSub: 'ETEL GROUP INTERNATIONAL'
    },
    gold: {
      bg: 'linear-gradient(135deg, rgba(50,30,0,0.95) 0%, rgba(26,14,0,0.98) 40%, rgba(44,24,0,0.97) 100%)',
      border: 'rgba(212,175,55,0.35)',
      magColor: '#F5E6A3',
      numPrefix: 'ETL',
      label: 'Gold Member',
      labelSub: 'ETEL GOLD TIER'
    },
    platinum: {
      bg: 'linear-gradient(135deg, rgba(40,40,44,0.97) 0%, rgba(20,20,26,0.99) 40%, rgba(36,36,42,0.97) 100%)',
      border: 'rgba(229,228,226,0.3)',
      magColor: '#E5E4E2',
      numPrefix: 'PLT',
      label: 'Platinum Member',
      labelSub: 'ETEL PLATINUM TIER'
    },
    obsidian: {
      bg: 'linear-gradient(135deg, rgba(6,10,26,0.97) 0%, rgba(4,8,22,0.99) 40%, rgba(8,14,32,0.97) 100%)',
      border: 'rgba(74,144,217,0.4)',
      magColor: '#4A90D9',
      numPrefix: 'OBS',
      label: 'Obsidian Elite',
      labelSub: 'ETEL OBSIDIAN TIER'
    }
  };

  const t = tiers[tier];
  if (card) {
    card.style.background = t.bg;
    card.style.borderColor = t.border;
  }
  if (tierEl) {
    tierEl.innerHTML = `${t.label}<br><span style="font-size:8px;opacity:0.5;letter-spacing:0.15em;">${t.labelSub}</span>`;
  }
  if (numberEl) {
    numberEl.textContent = `${t.numPrefix} \u2022 2025 \u2022 \u2588\u2588\u2588\u2588 \u2022 0001`;
  }
  if (magEl) {
    magEl.style.background = `linear-gradient(90deg, transparent, rgba(255,255,255,0.1), ${t.magColor}, rgba(255,255,255,0.1), transparent)`;
  }
}

/* ══════════════════════════════════════════
   STEP 8 — MOBILE JS ENHANCEMENTS
══════════════════════════════════════════ */
(function() {

  /* ── 1. Viewport height fix for mobile browsers (address bar issue) ── */
  function setVH() {
    const vh = window.innerHeight * 0.01;
    document.documentElement.style.setProperty('--real-vh', vh + 'px');
  }
  setVH();
  window.addEventListener('resize', setVH, { passive: true });
  window.addEventListener('orientationchange', () => {
    setTimeout(setVH, 120); // let browser settle after rotation
  });

  /* ── 2. Disable 3D card tilt on touch devices (causes jitter) ── */
  const isTouchPrimary = window.matchMedia('(hover: none)').matches;
  if (isTouchPrimary) {
    // Remove all mouse-based tilt listeners by disabling pointer events on scenes
    document.querySelectorAll('.co-card').forEach(card => {
      card.addEventListener('mouseenter', e => e.stopImmediatePropagation());
    });
  }

  /* ── 3. Close mobile drawer on any anchor click ── */
  document.querySelectorAll('#mobile-menu a').forEach(a => {
    a.addEventListener('click', () => {
      if (typeof closeMob === 'function') closeMob();
    });
  });

  /* ── 4. Smooth-scroll polyfill guard ── */
  document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', e => {
      const id = link.getAttribute('href').slice(1);
      if (!id) return;
      const target = document.getElementById(id);
      if (!target) return;
      e.preventDefault();
      const navH = parseInt(getComputedStyle(document.documentElement)
        .getPropertyValue('--nav-h')) || 72;
      const top = target.getBoundingClientRect().top + window.scrollY - navH;
      window.scrollTo({ top, behavior: 'smooth' });
    });
  });

  /* ── 5. Prevent iOS elastic scroll from breaking fixed canvas ── */
  document.body.addEventListener('touchmove', () => {}, { passive: true });

  /* ── 6. Hero min-height: use real viewport on mobile ── */
  const heroEl = document.getElementById('hero');
  function fixHeroHeight() {
    if (heroEl) {
      const realH = window.innerHeight;
      heroEl.style.minHeight = realH + 'px';
    }
  }
  fixHeroHeight();
  window.addEventListener('orientationchange', () => {
    setTimeout(fixHeroHeight, 150);
  });

  /* ── 7. Stagger nav links on drawer open for premium feel ── */
  const ham = document.getElementById('hamburger');
  if (ham) {
    ham.addEventListener('click', () => {
      if (ham.classList.contains('open')) {
        const links = document.querySelectorAll('.mob-nav-link, .mob-company-link');
        links.forEach((el, i) => {
          el.style.opacity    = '0';
          el.style.transform  = 'translateX(18px)';
          el.style.transition = `opacity .3s ease ${i * 40}ms, transform .35s cubic-bezier(0.22,1,0.36,1) ${i * 40}ms`;
          requestAnimationFrame(() => {
            setTimeout(() => {
              el.style.opacity   = '';
              el.style.transform = '';
            }, 60);
          });
        });
      }
    });
  }

  /* ── 8. Particle canvas: reduce count on mobile for performance ── */
  // Handled by limiting draw calls — nothing to change in HTML, just set a CSS hint
  if (window.innerWidth < 720) {
    document.documentElement.classList.add('is-mobile');
  }

})();

/* ══════════════════════════════════════════
   STEP 7 — CONTACT + FOOTER REVEALS
══════════════════════════════════════════ */
(function() {
  const ctIO = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el = entry.target;
      if (el.classList.contains('js-ct-row')) {
        const d = parseInt(el.dataset.delay || 0);
        setTimeout(() => el.classList.add('visible'), d * 100 + 200);
      } else {
        el.classList.add('visible');
      }
      ctIO.unobserve(el);
    });
  }, { threshold: 0.12 });

  document.querySelectorAll('.js-ct-left, .js-ct-right, .js-ct-row')
    .forEach(el => ctIO.observe(el));
})();

/* Contact form — submits to php/contact.php, stores in the DB, shows real success/error */
(function() {
  const form = document.getElementById('etel-contact-form');
  if (!form) return;
  const errBox = document.getElementById('cf-error');
  const btnHTML = `<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px"><path d="M14.5 1.5L7 9M14.5 1.5L10 14.5 7 9l-5.5-2 13-5z"/></svg> Send Message · ላኩ`;

  form.addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = form.querySelector('.cf-submit');
    errBox.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'Sending…';

    try {
      const fd = new FormData(form);
      const res = await fetch('EtelGroup/php/contact.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.fromEntries(fd)),
      });
      const data = await res.json();

      if (data.ok) {
        btn.textContent = '✓  Message Sent · ተልኳል';
        btn.style.background = 'linear-gradient(135deg, #22c55e, #16a34a)';
        form.reset();
        setTimeout(() => {
          btn.innerHTML = btnHTML;
          btn.style.background = '';
          btn.disabled = false;
        }, 4000);
      } else {
        errBox.textContent = data.error || 'Could not send your message. Please try again.';
        errBox.style.display = 'block';
        btn.innerHTML = btnHTML;
        btn.disabled = false;
      }
    } catch {
      errBox.textContent = 'Connection error. Please check your internet and try again.';
      errBox.style.display = 'block';
      btn.innerHTML = btnHTML;
      btn.disabled = false;
    }
  });
})();

/* ══════════════════════════════════════════
   STEP 6 — STATS ANIMATIONS
══════════════════════════════════════════ */
(function() {

  /* ── Shared ease helper ── */
  function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }

  /* ── Animated count-up ── */
  function countUp(el, target, duration, suffix, display) {
    const start = performance.now();
    function step(now) {
      const p = Math.min((now - start) / duration, 1);
      const v = Math.round(easeOutCubic(p) * target);
      if (display && p >= 1) { el.textContent = display; }
      else { el.textContent = v + (suffix || ''); }
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  /* ── Member counter — real count from EtelID, animated on reveal ──
     Was a hardcoded seed that ticked up with Math.random() to simulate
     "live" signups next to a "Live Count" badge - looked real but wasn't,
     and diverged between two open tabs the moment anyone checked. Now
     fetches the actual registered-member count via EtelGroup's own API
     (which asks EtelID directly, cached briefly server-side) and counts
     up to that true number once. No fake increments after landing. */
  const memberEl = document.getElementById('member-num');
  const memberLiveBadge = document.querySelector('.live-indicator');
  let memberStarted = false;

  function apiBase() {
    return window.ETEL_GROUP_API || 'EtelGroup/api/boot.php';
  }

  function startMemberCounter() {
    if (memberStarted || !memberEl) return;
    memberStarted = true;

    fetch(apiBase() + '?action=member_count')
      .then(res => res.json())
      .then(data => {
        const count = data && data.data && Number.isFinite(data.data.count) ? data.data.count : null;
        if (count === null) {
          // Real count unavailable right now - don't show a fabricated
          // number or a "Live" claim we can't back up.
          memberEl.textContent = '—';
          if (memberLiveBadge) memberLiveBadge.style.display = 'none';
          return;
        }
        const dur = 2400;
        const start = performance.now();
        function countIn(now) {
          const p = Math.min((now - start) / dur, 1);
          const v = Math.round(easeOutCubic(p) * count);
          memberEl.textContent = v.toLocaleString();
          if (p < 1) requestAnimationFrame(countIn);
        }
        requestAnimationFrame(countIn);
      })
      .catch(() => {
        memberEl.textContent = '—';
        if (memberLiveBadge) memberLiveBadge.style.display = 'none';
      });
  }

  /* ── IntersectionObserver for all Step 6 elements ── */
  const s6IO = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el = entry.target;

      if (el.classList.contains('js-s6') || el.classList.contains('js-s6-regions')) {
        el.classList.add('visible');

      } else if (el.classList.contains('js-s6-counter')) {
        el.classList.add('visible');
        startMemberCounter();

      } else if (el.classList.contains('js-s6-grid')) {
        el.classList.add('visible');
        // Animate each stat number inside
        el.querySelectorAll('.js-s6-num').forEach((num, i) => {
          const target  = parseInt(num.dataset.target);
          const suffix  = num.dataset.suffix || '';
          const display = num.dataset.display;
          setTimeout(() => countUp(num, target, 1600, suffix, display), i * 120);
        });

      } else if (el.classList.contains('js-s6-bars')) {
        el.classList.add('visible');
        // Animate progress bars
        setTimeout(() => {
          el.querySelectorAll('.bar-fill').forEach((fill, i) => {
            setTimeout(() => fill.classList.add('animated'), i * 140);
          });
        }, 200);
      }

      s6IO.unobserve(el);
    });
  }, { threshold: 0.15 });

  document.querySelectorAll('.js-s6, .js-s6-counter, .js-s6-grid, .js-s6-bars, .js-s6-regions')
    .forEach(el => s6IO.observe(el));

})();

/* ══════════════════════════════════════════
   STEP 3+4 — SCROLL REVEAL + 3D CARD TILT
══════════════════════════════════════════ */
(function() {
  const io = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el = entry.target;

      if (el.classList.contains('js-card')) {
        const delay = parseInt(el.dataset.delay || 0);
        setTimeout(() => el.classList.add('visible'), delay * 90);
      } else if (el.classList.contains('js-pillar')) {
        const delay = parseInt(el.dataset.delay || 0);
        setTimeout(() => el.classList.add('visible'), delay * 100 + 200);
      } else if (el.classList.contains('js-tl')) {
        const delay = parseInt(el.dataset.delay || 0);
        setTimeout(() => el.classList.add('visible'), delay * 120);
      } else if (el.classList.contains('js-about-left') || el.classList.contains('js-about-right')) {
        el.classList.add('visible');
      } else {
        el.classList.add('visible');
      }
      io.unobserve(el);
    });
  }, { threshold: 0.1, rootMargin: '0px 0px -30px 0px' });

  const MAX = 11;
  function bindTilt(card) {
    if (card.dataset.tiltBound) return;
    card.dataset.tiltBound = '1';
    card.addEventListener('mousemove', e => {
      const r = card.getBoundingClientRect();
      const dx = (e.clientX - r.left - r.width/2) / (r.width/2);
      const dy = (e.clientY - r.top  - r.height/2) / (r.height/2);
      card.classList.add('tilting');
      card.style.transform =
        `perspective(700px) rotateX(${-dy*MAX}deg) rotateY(${dx*MAX}deg) translateZ(8px) scale(1.025)`;
    });
    card.addEventListener('mouseleave', () => {
      card.classList.remove('tilting');
      card.style.transform = '';
    });
    card.addEventListener('touchmove', e => {
      const t = e.touches[0], r = card.getBoundingClientRect();
      const dx = (t.clientX - r.left - r.width/2)  / (r.width/2);
      const dy = (t.clientY - r.top  - r.height/2) / (r.height/2);
      card.style.transform =
        `perspective(700px) rotateX(${-dy*5}deg) rotateY(${dx*5}deg) translateZ(4px)`;
    }, { passive: true });
    card.addEventListener('touchend', () => { card.style.transform = ''; });
  }

  // Observe/bind everything present right now (normal page-load path).
  function scan() {
    document.querySelectorAll(
      '.js-reveal, .js-card, .js-reveal-footer, .js-pillar, .js-tl, .js-about-left, .js-about-right'
    ).forEach(el => {
      if (el.dataset.ioBound) return;
      el.dataset.ioBound = '1';
      io.observe(el);
    });
    document.querySelectorAll('.co-card').forEach(bindTilt);
  }
  scan();

  // Companies (nav dropdown, mobile nav, company grid, launcher grid)
  // are now injected asynchronously after this IIFE already ran once
  // (see js/companies.js) - those elements didn't exist yet on the
  // first scan() above, so they'd otherwise sit at opacity:0 forever,
  // fully present and clickable but never visually revealed. Exposed
  // here so companies.js can re-scan right after it injects new cards.
  window.egRefreshRevealAndTilt = scan;
})();

/* ── HAMBURGER / DRAWER ── */
const hamburger  = document.getElementById('hamburger');
const mobileMenu = document.getElementById('mobile-menu');
let drawerOpen   = false;

function openMob() {
  drawerOpen = true;
  hamburger.classList.add('open');
  mobileMenu.classList.add('open');
  hamburger.setAttribute('aria-expanded', 'true');
  document.body.style.overflow = 'hidden';
}

function closeMob() {
  drawerOpen = false;
  hamburger.classList.remove('open');
  mobileMenu.classList.remove('open');
  hamburger.setAttribute('aria-expanded', 'false');
  document.body.style.overflow = '';
}

hamburger.addEventListener('click', () => drawerOpen ? closeMob() : openMob());

// Close on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape' && drawerOpen) closeMob(); });

// Close on backdrop tap (outside drawer content)
mobileMenu.addEventListener('click', e => { if (e.target === mobileMenu) closeMob(); });

/* ── LANGUAGE TOGGLE ── */
let currentLang = 'en';

function setLang(lang) {
  currentLang = lang;

  // Update desktop toggle buttons
  document.getElementById('btn-en').classList.toggle('active', lang === 'en');
  document.getElementById('btn-am').classList.toggle('active', lang === 'am');

  // Swap all data-en / data-am elements
  document.querySelectorAll('[data-en][data-am]').forEach(el => {
    const val = el.getAttribute('data-' + lang);
    if (!val) return;
    // Use innerHTML for elements that may contain tags
    if (val.includes('<')) {
      el.innerHTML = val;
    } else {
      el.textContent = val;
    }
  });
}

/* ── 3D PARTICLE FIELD (from Step 1) ── */
(function() {
  const canvas = document.getElementById('particle-canvas');
  const ctx    = canvas.getContext('2d');

  let W, H, cx, cy;
  const PARTICLE_COUNT = window.innerWidth < 720 ? 120 : 260;
  const FOV = 380;
  const NEAR = 1, FAR = 1400;

  let targetRX = 0, targetRY = 0;
  let currentRX = 0, currentRY = 0;

  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cx = W / 2; cy = H / 2;
  }

  class Particle {
    constructor() { this.reset(false); }
    reset(fromCenter) {
      const spread = fromCenter ? 50 : 600;
      this.x  = (Math.random() - 0.5) * spread * 2;
      this.y  = (Math.random() - 0.5) * spread * 2;
      this.z  = Math.random() * FAR;
      this.vx = (Math.random() - 0.5) * 0.18;
      this.vy = (Math.random() - 0.5) * 0.18;
      this.vz = -(0.4 + Math.random() * 1.2);
      const r = Math.random();
      this.type     = r < 0.55 ? 'dot' : r < 0.72 ? 'cross' : r < 0.86 ? 'diamond' : 'ring';
      this.baseSize = 0.8 + Math.random() * 3;
      this.phase    = Math.random() * Math.PI * 2;
      this.twinkle  = 0.3 + Math.random() * 0.7;
      const hues    = [[212,175,55],[245,230,163],[184,144,31],[74,144,217]];
      this.color    = hues[Math.random() < 0.05 ? 3 : Math.floor(Math.random() * 3)];
    }
    project(rX, rY) {
      const cosY = Math.cos(rY), sinY = Math.sin(rY);
      let rx = this.x * cosY - this.z * sinY;
      let rz = this.x * sinY + this.z * cosY;
      const cosX = Math.cos(rX), sinX = Math.sin(rX);
      let ry = this.y * cosX - rz * sinX;
      rz     = this.y * sinX + rz * cosX;
      const pZ = rz + FOV;
      if (pZ <= NEAR) return null;
      const sc = FOV / pZ;
      return { sx: cx + rx * sc, sy: cy + ry * sc, scale: sc, depth: pZ };
    }
    update() {
      this.x += this.vx; this.y += this.vy; this.z += this.vz;
      this.phase += 0.018;
      if (this.z < -FAR / 2) this.reset(true);
    }
  }

  const particles = Array.from({ length: PARTICLE_COUNT }, () => new Particle());
  const MAX_D2    = 120 * 120;
  let t = 0;

  function draw() {
    // Pause when tab hidden or canvas scrolled out of view
    if (window.__canvasRunning && !window.__canvasRunning()) {
      requestAnimationFrame(draw);
      return;
    }
    ctx.clearRect(0, 0, W, H);
    currentRX += (targetRX - currentRX) * 0.04;
    currentRY += (targetRY - currentRY) * 0.04;
    const rotX = currentRX + Math.sin(t * 0.0004) * 0.08;
    const rotY = currentRY + t * 0.00025;
    t++;

    const proj = [];
    for (const p of particles) {
      p.update();
      const r = p.project(rotX, rotY);
      if (r) proj.push({ p, r });
    }
    proj.sort((a, b) => b.r.depth - a.r.depth);

    ctx.lineWidth = 0.5;
    const lim = Math.min(proj.length, 120);
    for (let i = 0; i < lim; i++) {
      for (let j = i + 1; j < Math.min(lim, i + 12); j++) {
        const a = proj[i].r, b = proj[j].r;
        const dx = a.sx - b.sx, dy = a.sy - b.sy, d2 = dx*dx + dy*dy;
        if (d2 < MAX_D2) {
          const al = (1 - d2 / MAX_D2) * 0.12 * Math.max(0, 1 - (a.depth + b.depth) / 2 / FAR);
          ctx.strokeStyle = `rgba(212,175,55,${al})`;
          ctx.beginPath(); ctx.moveTo(a.sx, a.sy); ctx.lineTo(b.sx, b.sy); ctx.stroke();
        }
      }
    }

    for (const { p, r: { sx, sy, scale, depth } } of proj) {
      const da = Math.max(0, 1 - depth / FAR);
      const tw = 0.5 + 0.5 * Math.sin(p.phase) * p.twinkle;
      const al = da * tw;
      if (al < 0.01) continue;
      const sz = p.baseSize * scale * 1.5;
      if (sz < 0.3) continue;
      const [r, g, b] = p.color;
      const col = `rgba(${r},${g},${b},${al})`;
      ctx.fillStyle = col; ctx.strokeStyle = col;

      if (p.type === 'dot') {
        if (sz > 1) {
          const grd = ctx.createRadialGradient(sx, sy, 0, sx, sy, sz * 3);
          grd.addColorStop(0, `rgba(${r},${g},${b},${al * 0.3})`);
          grd.addColorStop(1, `rgba(${r},${g},${b},0)`);
          ctx.fillStyle = grd;
          ctx.beginPath(); ctx.arc(sx, sy, sz * 3, 0, Math.PI * 2); ctx.fill();
        }
        ctx.fillStyle = col;
        ctx.beginPath(); ctx.arc(sx, sy, sz, 0, Math.PI * 2); ctx.fill();
      } else if (p.type === 'cross') {
        const arm = sz * 2.5;
        ctx.lineWidth = Math.max(0.4, sz * 0.5);
        ctx.beginPath();
        ctx.moveTo(sx - arm, sy); ctx.lineTo(sx + arm, sy);
        ctx.moveTo(sx, sy - arm); ctx.lineTo(sx, sy + arm);
        ctx.stroke();
      } else if (p.type === 'diamond') {
        const d = sz * 2;
        ctx.beginPath();
        ctx.moveTo(sx, sy - d); ctx.lineTo(sx + d, sy);
        ctx.lineTo(sx, sy + d); ctx.lineTo(sx - d, sy);
        ctx.closePath(); ctx.fill();
      } else {
        ctx.lineWidth = Math.max(0.4, sz * 0.4);
        ctx.beginPath(); ctx.arc(sx, sy, sz * 1.8, 0, Math.PI * 2); ctx.stroke();
      }
    }
    requestAnimationFrame(draw);
  }

  window.addEventListener('mousemove', e => {
    targetRY = ((e.clientX / W) - 0.5) * 0.25;
    targetRX = ((e.clientY / H) - 0.5) * -0.12;
  });
  window.addEventListener('touchmove', e => {
    const t = e.touches[0];
    targetRY = ((t.clientX / W) - 0.5) * 0.15;
    targetRX = ((t.clientY / H) - 0.5) * -0.08;
  }, { passive: true });
  window.addEventListener('resize', resize);
  resize();
  draw();
})();
(function() {
  'use strict';

  /* ── 1. RAF-THROTTLED SCROLL HANDLER ──────────────────────────
     All scroll-dependent work (nav glass, progress bar, active link)
     runs in a single requestAnimationFrame per scroll tick.
  ──────────────────────────────────────────────────────────────── */
  let scrollTicking = false;

  function onScrollFrame() {
    const y   = window.scrollY;
    const nav = document.getElementById('nav');
    const bar = document.getElementById('scroll-progress');

    if (nav) nav.classList.toggle('scrolled', y > 40);

    if (bar) {
      const max = document.documentElement.scrollHeight - window.innerHeight;
      bar.style.width = max > 0 ? (y / max * 100) + '%' : '0%';
    }

    // Active nav link
    const sections = ['hero','services','about','etel-id','stats','contact'];
    let current = 'hero';
    for (const id of sections) {
      const el = document.getElementById(id);
      if (el && el.getBoundingClientRect().top <= 90) current = id;
    }
    document.querySelectorAll('.nav-links a[href^="#"]').forEach(a => {
      const href = a.getAttribute('href').replace('#','');
      a.classList.toggle('active', href === current);
    });

    scrollTicking = false;
  }

  // Replace any earlier scroll listeners with this one throttled version
  window.addEventListener('scroll', () => {
    if (!scrollTicking) {
      requestAnimationFrame(onScrollFrame);
      scrollTicking = true;
    }
  }, { passive: true });

  // Run once on load
  requestAnimationFrame(onScrollFrame);


  /* ── 2. WILL-CHANGE CLEANUP ────────────────────────────────────
     Remove will-change from cards once they've settled to free
     compositor memory. Runs 3s after page load.
  ──────────────────────────────────────────────────────────────── */
  setTimeout(() => {
    document.querySelectorAll('.co-card, .myth-sigil, .brandmark').forEach(el => {
      el.style.willChange = 'auto';
    });
  }, 3000);


  /* ── 3. LAZY IMAGE LOADING ─────────────────────────────────────
     Adds native lazy loading + a fade-in class for any <img>
     elements added by WordPress in Step 10.
  ──────────────────────────────────────────────────────────────── */
  if ('loading' in HTMLImageElement.prototype) {
    document.querySelectorAll('img[data-src]').forEach(img => {
      img.src = img.dataset.src;
      img.classList.add('lazy');
      img.addEventListener('load', () => img.classList.add('loaded'), { once: true });
    });
  } else {
    // Fallback: IntersectionObserver-based lazy load
    const lazyIO = new IntersectionObserver((entries, obs) => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        const img = entry.target;
        if (img.dataset.src) {
          img.src = img.dataset.src;
          img.classList.add('lazy');
          img.addEventListener('load', () => img.classList.add('loaded'), { once: true });
        }
        obs.unobserve(img);
      });
    }, { rootMargin: '200px' });
    document.querySelectorAll('img[data-src]').forEach(img => lazyIO.observe(img));
  }


  /* ── 4. PARTICLE CANVAS VISIBILITY PAUSE ──────────────────────
     Stop drawing particles when the tab is hidden or when the
     canvas scrolls far out of view. Saves CPU/battery.
  ──────────────────────────────────────────────────────────────── */
  let canvasRunning = true;

  document.addEventListener('visibilitychange', () => {
    canvasRunning = !document.hidden;
  });

  // Pause when canvas (hero) is more than 1.5x viewport below fold
  const canvasEl = document.getElementById('particle-canvas');
  if (canvasEl) {
    const canvasViz = new IntersectionObserver((entries) => {
      canvasRunning = entries[0].isIntersecting;
    }, { rootMargin: '50% 0px 50% 0px' });
    canvasViz.observe(canvasEl);
  }

  // Expose global flag for the draw loop to check
  window.__canvasRunning = () => canvasRunning;


  /* ── 5. PASSIVE EVENT LISTENERS ───────────────────────────────
     Ensure all wheel/touch events are passive for scroll perf.
  ──────────────────────────────────────────────────────────────── */
  const passiveSupported = (() => {
    let supported = false;
    try {
      const opts = Object.defineProperty({}, 'passive', {
        get() { supported = true; return true; }
      });
      window.addEventListener('testPassive', null, opts);
      window.removeEventListener('testPassive', null, opts);
    } catch(e) {}
    return supported;
  })();
  const passiveOpt = passiveSupported ? { passive: true } : false;
  window.__passiveOpt = passiveOpt;


  /* ── 6. FONT LOADING OBSERVER ─────────────────────────────────
     Add a class to <html> once all fonts have loaded, preventing
     layout shift from font swap (FOUT).
  ──────────────────────────────────────────────────────────────── */
  if ('fonts' in document) {
    document.fonts.ready.then(() => {
      document.documentElement.classList.add('fonts-loaded');
    });
  }


  /* ── 7. PRELOAD NEXT SECTION IMAGES ───────────────────────────
     As user scrolls, prefetch the OG image so it's cached for
     social sharing.
  ──────────────────────────────────────────────────────────────── */
  let ogPrefetched = false;
  window.addEventListener('scroll', () => {
    if (!ogPrefetched && window.scrollY > 300) {
      ogPrefetched = true;
      const link = document.createElement('link');
      link.rel = 'prefetch';
      link.href = 'https://ethelmarket.com/og-image.jpg';
      document.head.appendChild(link);
    }
  }, { passive: true, once: false });


  /* ── 8. CLS ANCHOR — lock section heights before reveal ───────
     Prevents layout shift when IntersectionObserver fires and
     opacity transitions cause reflow.
  ──────────────────────────────────────────────────────────────── */
  // All reveal targets already have opacity:0 from CSS —
  // they don't take layout space away, just visual. No extra fix needed.
  // But we do set explicit min-heights on placeholder sections at load
  // to prevent CLS when they get replaced.


  /* ── 9. CONSOLE BRAND SIGNATURE ───────────────────────────────*/
  console.log(
    '%c ኢE %c Etel Group — ethelmarket.com ',
    'background:#D4AF37;color:#1A0E00;font-size:16px;font-weight:bold;padding:4px 8px;',
    'background:#1A0E00;color:#D4AF37;font-size:13px;padding:4px 8px;'
  );
  console.log('%cBuilt with intention. Eight companies. One vision.', 'color:#8B6914;font-style:italic;');

})();

/* ══════════════════════════════════════════
   3D COIN — DRAG / HOVER / MOMENTUM / SPARKS
   Double-click / double-tap to flip
══════════════════════════════════════════ */
(function () {
  const wrap  = document.getElementById('coinWrap');
  const persp = document.getElementById('coinPerspective');
  const body_ = document.getElementById('coinBody');
  const sc    = document.getElementById('coinSparks');
  if (!wrap || !body_) return;

  const BASE = { rx:10, ry:-20 };
  let rX = BASE.rx, rY = BASE.ry, velX = 0, velY = 0;
  let drag = false, lX = 0, lY = 0;
  let autoOn = true, flipped = false;

  /* Sparks ──────────────────────────────── */
  const sparks = [];
  let sCtx = null;
  if (sc) {
    sc.width = sc.height = 520;
    sc.style.width = sc.style.height = '260px';
    sCtx = sc.getContext('2d');
  }

  function burst(n) {
    for (let i = 0; i < n; i++) {
      const a = Math.random() * Math.PI * 2;
      const s = 1.5 + Math.random() * 4;
      sparks.push({
        x:130, y:130,
        vx: Math.cos(a)*s, vy: Math.sin(a)*s,
        life:1,
        decay: 0.022 + Math.random() * 0.028,
        r: 1.5 + Math.random() * 2.5,
        hue: Math.random() < 0.65 ? '212,175,55' : Math.random() < 0.5 ? '245,230,163' : '74,144,217'
      });
    }
  }

  (function drawSparks() {
    if (sCtx) {
      sCtx.clearRect(0, 0, 520, 520);
      for (let i = sparks.length-1; i >= 0; i--) {
        const s = sparks[i];
        s.x += s.vx; s.y += s.vy;
        s.vy += 0.07; s.vx *= 0.97;
        s.life -= s.decay;
        if (s.life <= 0) { sparks.splice(i,1); continue; }
        const g = sCtx.createRadialGradient(s.x,s.y,0,s.x,s.y,s.r*3);
        g.addColorStop(0,   `rgba(${s.hue},${s.life})`);
        g.addColorStop(0.5, `rgba(${s.hue},${s.life*0.4})`);
        g.addColorStop(1,   `rgba(${s.hue},0)`);
        sCtx.fillStyle = g;
        sCtx.beginPath(); sCtx.arc(s.x,s.y,s.r*3,0,Math.PI*2); sCtx.fill();
        sCtx.strokeStyle = `rgba(${s.hue},${s.life*0.7})`;
        sCtx.lineWidth = s.r * 0.4;
        sCtx.beginPath(); sCtx.moveTo(s.x,s.y);
        sCtx.lineTo(s.x-s.vx*3, s.y-s.vy*3); sCtx.stroke();
      }
    }
    requestAnimationFrame(drawSparks);
  })();

  /* Transform ────────────────────────────── */
  function apply() {
    body_.style.transform = `rotateX(${rX}deg) rotateY(${rY}deg)`;
  }

  function stopAuto() {
    if (!autoOn) return;
    autoOn = false;
    body_.style.animation = 'none';
    apply();
  }

  function resumeAuto() {
    autoOn = true;
    body_.style.transition = 'transform 0.85s cubic-bezier(0.22,1,0.36,1)';
    rX = BASE.rx; rY = BASE.ry; apply();
    setTimeout(() => { body_.style.transition=''; body_.style.animation=''; }, 900);
  }

  /* Hover tilt ───────────────────────────── */
  persp.addEventListener('mousemove', e => {
    if (drag) return;
    stopAuto();
    const r = persp.getBoundingClientRect();
    const dx = (e.clientX - r.left - r.width/2)  / (r.width/2);
    const dy = (e.clientY - r.top  - r.height/2) / (r.height/2);
    rX = BASE.rx - dy*28; rY = BASE.ry + dx*28; apply();
  });
  persp.addEventListener('mouseleave', () => { if (!drag) resumeAuto(); });

  /* Drag ─────────────────────────────────── */
  persp.addEventListener('mousedown', e => {
    drag=true; stopAuto(); lX=e.clientX; lY=e.clientY; velX=velY=0;
    e.preventDefault();
  });
  window.addEventListener('mousemove', e => {
    if (!drag) return;
    const dx=e.clientX-lX, dy=e.clientY-lY;
    lX=e.clientX; lY=e.clientY; velX=dx; velY=dy;
    rY+=dx*0.65; rX-=dy*0.65; apply();
    if (Math.abs(dx)>10||Math.abs(dy)>10) burst(5);
  });
  window.addEventListener('mouseup', () => { if (drag) { drag=false; momentum(); } });

  /* Touch ────────────────────────────────── */
  persp.addEventListener('touchstart', e => {
    drag=true; stopAuto();
    const t=e.touches[0]; lX=t.clientX; lY=t.clientY; velX=velY=0;
  }, {passive:true});
  persp.addEventListener('touchmove', e => {
    if (!drag) return;
    const t=e.touches[0];
    const dx=t.clientX-lX, dy=t.clientY-lY;
    lX=t.clientX; lY=t.clientY; velX=dx; velY=dy;
    rY+=dx*0.6; rX-=dy*0.6; apply();
    if (Math.abs(dx)>12) burst(4);
  }, {passive:true});
  persp.addEventListener('touchend', () => { drag=false; momentum(); });

  /* Momentum ─────────────────────────────── */
  function momentum() {
    velX*=0.92; velY*=0.92;
    rY+=velX*0.5; rX-=velY*0.5; apply();
    if (Math.abs(velX)>0.2||Math.abs(velY)>0.2) requestAnimationFrame(momentum);
    else easeBack();
  }
  function easeBack() {
    rX+=(BASE.rx-rX)*0.058; rY+=(BASE.ry-rY)*0.058; apply();
    if (Math.abs(rX-BASE.rx)>0.3||Math.abs(rY-BASE.ry)>0.3) requestAnimationFrame(easeBack);
    else resumeAuto();
  }

  /* Flip on double-click / double-tap ─────── */
  let lastTap=0;
  persp.addEventListener('click', () => {
    const now=Date.now();
    if (now-lastTap<420) { flip(); lastTap=0; } else lastTap=now;
  });
  function flip() {
    stopAuto(); burst(18); burst(18);
    flipped = !flipped;
    const tY = flipped ? 180 : BASE.ry;
    const tX = flipped ? 0   : BASE.rx;
    body_.style.transition = 'transform 0.8s cubic-bezier(0.22,1,0.36,1)';
    body_.style.transform  = `rotateX(${tX}deg) rotateY(${tY}deg)`;
    rX=tX; rY=tY;
    setTimeout(() => {
      body_.style.transition='';
      if (!flipped) resumeAuto();
    }, 850);
  }
})();


/* ══════════════════════════════════════════
   ABOUT SIGIL 3D COIN INTERACTION
══════════════════════════════════════════ */
(function () {
  const persp = document.getElementById('aboutCoinPersp');
  const body_ = document.getElementById('aboutCoinBody');
  const sc    = document.getElementById('aboutSparks');
  if (!persp || !body_) return;

  const BASE = { rx:12, ry:-18 };
  let rX=BASE.rx, rY=BASE.ry, velX=0, velY=0;
  let drag=false, lX=0, lY=0, autoOn=true, flipped=false;

  /* Mini sparks */
  const sparks=[];
  let sCtx=null;
  if (sc) {
    sc.width=sc.height=280;
    sc.style.width=sc.style.height='140px';
    sCtx=sc.getContext('2d');
  }
  function burst(n) {
    for(let i=0;i<n;i++){
      const a=Math.random()*Math.PI*2, s=1+Math.random()*3;
      sparks.push({x:70,y:70,vx:Math.cos(a)*s,vy:Math.sin(a)*s,
        life:1,decay:0.03+Math.random()*0.03,r:1+Math.random()*2,
        hue:Math.random()<0.7?'212,175,55':Math.random()<0.5?'245,230,163':'74,144,217'});
    }
  }
  // This spark canvas used to redraw via requestAnimationFrame forever,
  // unconditionally, even while the About section is scrolled far out of
  // view - real continuous GPU/CPU work for a section that's mostly text.
  // Gate the loop on actual visibility instead: pause while off-screen,
  // resume once it's back in the viewport.
  let sigilVisible = true;
  const sigilRoot = document.getElementById('aboutCoinWrap');
  if (sigilRoot && 'IntersectionObserver' in window) {
    sigilVisible = false; // start paused; the observer flips it on once seen
    new IntersectionObserver((entries) => {
      sigilVisible = entries[0].isIntersecting;
    }, { threshold: 0 }).observe(sigilRoot);
  }
  (function drawSp(){
    if(sCtx && sigilVisible){
      sCtx.clearRect(0,0,280,280);
      for(let i=sparks.length-1;i>=0;i--){
        const s=sparks[i];
        s.x+=s.vx; s.y+=s.vy; s.vy+=0.08; s.vx*=0.96; s.life-=s.decay;
        if(s.life<=0){sparks.splice(i,1);continue;}
        const g=sCtx.createRadialGradient(s.x,s.y,0,s.x,s.y,s.r*2.5);
        g.addColorStop(0,`rgba(${s.hue},${s.life})`);
        g.addColorStop(1,`rgba(${s.hue},0)`);
        sCtx.fillStyle=g; sCtx.beginPath();
        sCtx.arc(s.x,s.y,s.r*2.5,0,Math.PI*2); sCtx.fill();
      }
    }
    requestAnimationFrame(drawSp);
  })();

  function apply(){ body_.style.transform=`rotateX(${rX}deg) rotateY(${rY}deg)`; }
  function stopAuto(){
    if(!autoOn)return; autoOn=false;
    body_.style.animation='none'; apply();
  }
  function resumeAuto(){
    autoOn=true;
    body_.style.transition='transform 0.8s cubic-bezier(0.22,1,0.36,1)';
    rX=BASE.rx; rY=BASE.ry; apply();
    setTimeout(()=>{body_.style.transition=''; body_.style.animation='';},850);
  }

  persp.addEventListener('mousemove', e=>{
    if(drag)return; stopAuto();
    const r=persp.getBoundingClientRect();
    const dx=(e.clientX-r.left-r.width/2)/(r.width/2);
    const dy=(e.clientY-r.top-r.height/2)/(r.height/2);
    rX=BASE.rx-dy*25; rY=BASE.ry+dx*25; apply();
  });
  persp.addEventListener('mouseleave',()=>{ if(!drag) resumeAuto(); });

  persp.addEventListener('mousedown',e=>{
    drag=true; stopAuto(); lX=e.clientX; lY=e.clientY; velX=velY=0;
    e.preventDefault();
  });
  window.addEventListener('mousemove',e=>{
    if(!drag)return;
    const dx=e.clientX-lX,dy=e.clientY-lY;
    lX=e.clientX; lY=e.clientY; velX=dx; velY=dy;
    rY+=dx*0.6; rX-=dy*0.6; apply();
    if(Math.abs(dx)>8) burst(4);
  });
  window.addEventListener('mouseup',()=>{ if(drag){drag=false;mom();} });

  persp.addEventListener('touchstart',e=>{
    drag=true; stopAuto();
    const t=e.touches[0]; lX=t.clientX; lY=t.clientY; velX=velY=0;
  },{passive:true});
  persp.addEventListener('touchmove',e=>{
    if(!drag)return;
    const t=e.touches[0],dx=t.clientX-lX,dy=t.clientY-lY;
    lX=t.clientX; lY=t.clientY; velX=dx; velY=dy;
    rY+=dx*0.55; rX-=dy*0.55; apply();
    if(Math.abs(dx)>12) burst(3);
  },{passive:true});
  persp.addEventListener('touchend',()=>{ drag=false; mom(); });

  function mom(){
    velX*=0.91; velY*=0.91;
    rY+=velX*0.45; rX-=velY*0.45; apply();
    if(Math.abs(velX)>0.2||Math.abs(velY)>0.2) requestAnimationFrame(mom);
    else easeBack();
  }
  function easeBack(){
    rX+=(BASE.rx-rX)*0.06; rY+=(BASE.ry-rY)*0.06; apply();
    if(Math.abs(rX-BASE.rx)>0.3||Math.abs(rY-BASE.ry)>0.3) requestAnimationFrame(easeBack);
    else resumeAuto();
  }

  let lastTap=0;
  persp.addEventListener('click',()=>{
    const now=Date.now();
    if(now-lastTap<400){flipIt();lastTap=0;} else lastTap=now;
  });
  function flipIt(){
    stopAuto(); burst(12); burst(10);
    flipped=!flipped;
    const tY=flipped?180:BASE.ry, tX=flipped?0:BASE.rx;
    body_.style.transition='transform 0.75s cubic-bezier(0.22,1,0.36,1)';
    body_.style.transform=`rotateX(${tX}deg) rotateY(${tY}deg)`;
    rX=tX; rY=tY;
    setTimeout(()=>{ body_.style.transition=''; if(!flipped) resumeAuto(); },800);
  }
})();

/* ── PLATFORM LAUNCHER: reveal + live status ping ── */
(function() {
  /* Reveal cards on scroll */
  var lcCards = document.querySelectorAll('.js-lcard');
  var pfFooter = document.querySelector('.platform-footer');
  if ('IntersectionObserver' in window) {
    var lcObs = new IntersectionObserver(function(entries) {
      entries.forEach(function(e) {
        if (e.isIntersecting) {
          var card = e.target;
          var delay = parseInt(card.dataset.delay || 0, 10) * 110;
          setTimeout(function() { card.classList.add('lc-visible'); }, delay);
          lcObs.unobserve(card);
        }
      });
    }, { threshold: 0.12 });
    lcCards.forEach(function(c) { lcObs.observe(c); });

    if (pfFooter) {
      var pfObs = new IntersectionObserver(function(entries) {
        if (entries[0].isIntersecting) {
          pfFooter.classList.add('visible');
          pfObs.disconnect();
        }
      }, { threshold: 0.5 });
      pfObs.observe(pfFooter);
    }
  } else {
    lcCards.forEach(function(c) { c.classList.add('lc-visible'); });
    if (pfFooter) pfFooter.classList.add('visible');
  }

  /* Live status ping via fetch no-cors
     The company list itself now comes from the database (see
     js/companies.js, which renders the launcher grid's dot/label
     elements dynamically) - so this file no longer hardcodes which
     companies exist. companies.js calls window.egRecheckLauncherStatus
     with the live company list once those DOM elements exist. */
  function setStatus(dotId, labelId, isOnline) {
    var dot   = document.getElementById(dotId);
    var label = document.getElementById(labelId);
    if (!dot || !label) return;
    dot.classList.remove('checking');
    if (isOnline) {
      dot.classList.add('online');
      label.classList.add('online');
      label.textContent = 'Online';
    } else {
      dot.classList.add('offline');
      label.classList.add('offline');
      label.textContent = 'Offline';
    }
  }

  function checkLauncherStatuses(projects) {
    projects.forEach(function(p) {
      var dotEl = document.getElementById(p.dot);
      if (dotEl) dotEl.classList.add('checking');
      var ctrl    = new AbortController();
      var timeout = setTimeout(function() { ctrl.abort(); }, 6000);
      fetch(p.url, { method: 'HEAD', mode: 'no-cors', cache: 'no-store', signal: ctrl.signal })
        .then(function() { clearTimeout(timeout); setStatus(p.dot, p.label, true);  })
        .catch(function() { clearTimeout(timeout); setStatus(p.dot, p.label, false); });
    });
  }
  window.egRecheckLauncherStatus = checkLauncherStatuses;
})();
