const DEMOS = {
  customer:{ph:'+251911000001',pw:'etel1234'},
  courier: {ph:'+251911000002',pw:'etel1234'},
  driver:  {ph:'+251911000005',pw:'etel1234'},
  service_giver: {ph:'+251911000003',pw:'etel1234'},
  admin:   {ph:'0900000000',   pw:'admin1234'},
};
const TITLES = {customer:'Customer Login',courier:'Courier Login',driver:'Driver Login',service_giver:'Service Giver Login',admin:'Admin Login'};
const ICONS  = {customer:'📦',courier:'🛵',driver:'🚗',service_giver:'🏪',admin:'⚙️'};
let role='customer', mode='login';

// Show demo band only in dev mode (set localStorage.etelDev=1 in console)
if(localStorage.getItem('etelDev')==='1'){
  const db = document.getElementById('demo-band');
  if(db) db.style.display='block';
}

// Pre-fill role from URL
const params=new URLSearchParams(location.search);
if(params.get('role')) setRole(params.get('role'));
if(params.get('mode')==='register') switchMode('register');

function setRole(r){
  role=r;
  // Switching tabs while EtelID mode is on left the form showing a
  // stale "EtelID Sign-In" header for whichever tab was active when it
  // was toggled on, and could submit admin_etelid_login while a
  // non-admin tab was actually selected. Reset back to plain
  // phone+password on every tab switch.
  if (window.ETELID_MODE) window.toggleEtelIdMode();
  ['customer','courier','driver','service_giver','admin'].forEach(x=>{
    const btnId = x === 'customer' ? 'tab-c' : x === 'courier' ? 'tab-d' : x === 'driver' ? 'tab-r' : x === 'service_giver' ? 'tab-s' : 'tab-a';
    const btnEl = document.getElementById(btnId);
    if(btnEl) btnEl.classList.toggle('on',x===r);
  });
  const hdrIcon = document.getElementById('fhdr-icon');
  if(hdrIcon) hdrIcon.textContent=ICONS[r];
  const hdrTitle = document.getElementById('fhdr-title');
  if(hdrTitle) hdrTitle.textContent=mode==='login'?TITLES[r]:'Create Account';
  const demoPh = document.getElementById('demo-ph');
  if(demoPh) demoPh.textContent=DEMOS[r].ph.replace(/(\d{4})(\d{3})(\d{3})/,'$1 $2 $3');
  const courierFields = document.getElementById('courier-fields');
  if(courierFields) courierFields.style.display=r==='courier'&&mode==='register'?'block':'none';
  clearAlert();
}
function switchMode(m){
  mode=m;
  const fvLogin = document.getElementById('fv-login');
  if(fvLogin) fvLogin.classList.toggle('on',m==='login');
  const fvRegister = document.getElementById('fv-register');
  if(fvRegister) fvRegister.classList.toggle('on',m==='register');
  const demoBand = document.getElementById('demo-band');
  if(demoBand) {
    if(localStorage.getItem('etelDev')==='1' && m==='login') {
      demoBand.style.display='block';
    } else {
      demoBand.style.display='none';
    }
  }
  const hdrTitle = document.getElementById('fhdr-title');
  if(hdrTitle) hdrTitle.textContent=m==='login'?TITLES[role]:'Create Account';
  const courierFields = document.getElementById('courier-fields');
  if(courierFields) courierFields.style.display=role==='courier'&&m==='register'?'block':'none';
  clearAlert();
}
function fillDemo(f){
  const d=DEMOS[role];
  if(f==='phone') {
    const inpPhone = document.getElementById('inp-phone');
    if(inpPhone) inpPhone.value=d.ph;
  }
  else {
    const inpPw = document.getElementById('inp-pw');
    if(inpPw) inpPw.value=d.pw;
  }
}
function togglePw(){
  const i=document.getElementById('inp-pw');
  if(i) i.type=i.type==='password'?'text':'password';
}
function togglePw2(){
  const i=document.getElementById('reg-pw');
  if(i) i.type=i.type==='password'?'text':'password';
}
function _activeAlertId(){
  /* Return the alert div id for whichever panel is currently visible */
  const fv = document.getElementById('fv-forgot');
  if (fv && fv.classList.contains('on')) return 'falert-forgot';
  return 'falert';
}
function showAlert(msg,type){
  const a=document.getElementById(_activeAlertId());
  if(a) {
    a.innerHTML=msg;
    a.className='falert on '+(type||'err');
    a.scrollIntoView({behavior:'smooth',block:'nearest'});
  }
}
function clearAlert(){
  ['falert','falert-forgot'].forEach(function(id){
    const a=document.getElementById(id);
    if(a) a.className='falert';
  });
}

async function doLogin(e){
  e.preventDefault();
  const inpPhone = document.getElementById('inp-phone');
  const inpPw = document.getElementById('inp-pw');
  if(!inpPhone || !inpPw) return;
  const phone=inpPhone.value.trim().replace(/\s/g,'');
  const pw   =inpPw.value;
  if(!phone||!pw){showAlert('Please fill in all fields.');return;}
  const btn=document.getElementById('sbtn'); 
  const btnTxt = document.getElementById('sbtn-txt');
  if(btn) btn.disabled=true;
  if(btnTxt) btnTxt.innerHTML='<span class="spinner spinner-sm"></span>';
  clearAlert();
  const action = window.ETELID_MODE
    ? (role === 'admin' ? 'admin_etelid_login' : 'etelid_login')
    : (role === 'service_giver' ? 'sg_login' : 'login');
  const payload = window.ETELID_MODE
    ? { identifier: phone, password: pw }
    : { phone, password: pw };
  try {
    const res=await api(action,payload);
    if(btn) btn.disabled=false; 
    if(btnTxt) btnTxt.textContent='Sign In →';
    if(!res.success){showAlert(res.message||'Invalid phone or password.');return;}
    
    // Save auth data
    Auth.save(res.data);
    
    // Get user role from saved data
    const savedUser = Auth.getUser();
    const ur = savedUser ? savedUser.role : null;
    
    if (!ur) {
      showAlert('Login error: User role not found. Please try again.');
      return;
    }
    
    // Role-based redirect
    const roleMap = {
      customer: 'customer.html',
      courier: 'courier/',
      driver: 'driver/',
      service_giver: 'servicegiver.html',
      admin: 'admin.html'
    };

    const dest = roleMap[ur] || 'index.html';
    // Staff who signed in via EtelID (admin_etelid_login) land on the
    // admin page most relevant to their EtelGroup role instead of
    // always starting at the full Dashboard - e.g. a Dispatcher goes
    // straight to Rides, Finance goes straight to Pricing. Only applies
    // to the EtelID staff path; a plain admin phone+password login
    // (which has no staff_role at all) is unaffected and lands on
    // admin.html's normal default.
    const STAFF_LANDING = {
      dispatcher: 'rides',
      finance: 'pricing',
      'traffic officer': 'rides',
      manager: 'dashboard',
    };
    let finalDest = dest;
    if (ur === 'admin' && savedUser.staff_role) {
      const landing = STAFF_LANDING[String(savedUser.staff_role).trim().toLowerCase()];
      if (landing) finalDest += '?landing=' + encodeURIComponent(landing);
    }
    showAlert('Welcome! Redirecting as ' + ur.replace('_', ' ') + '...', 'ok');
    setTimeout(()=>{ location.href = finalDest; }, 600);
  } catch (err) {
    if(btn) btn.disabled=false;
    if(btnTxt) btnTxt.textContent='Sign In →';
    showAlert('Server connection error. Please try again.');
  }
}

async function doRegister(e){
  e.preventDefault();
  if(role==='service_giver'||role==='admin'){
    showAlert('Service Giver and Admin accounts must be created by an administrator. Please contact admin.');
    return;
  }
  const regName = document.getElementById('reg-name');
  const regPhone = document.getElementById('reg-phone');
  const regPw = document.getElementById('reg-pw');
  if(!regName || !regPhone || !regPw) return;
  const name=regName.value.trim();
  const phone=regPhone.value.trim().replace(/\s/g,'');
  const pw=regPw.value;
  if(!name||!phone||!pw){showAlert('Please fill all required fields.');return;}
  const btn=document.getElementById('rbtn'); 
  const btnTxt = document.getElementById('rbtn-txt');
  if(btn) btn.disabled=true;
  if(btnTxt) btnTxt.innerHTML='<span class="spinner spinner-sm"></span>';
  clearAlert();
  const body={name,phone,password:pw,role,lang:'en'};
  if(role==='courier'){
    const regVehicle = document.getElementById('reg-vehicle');
    const regZone = document.getElementById('reg-zone');
    if(regVehicle) body.vehicle=regVehicle.value;
    if(regZone) body.zone=regZone.value;
  }
  try {
    const res=await api('register',body);
    if(btn) btn.disabled=false; 
    if(btnTxt) btnTxt.textContent='Create Account →';
    if(!res.success){showAlert(res.message||'Registration failed.');return;}
    showAlert('Account created! Signing in...','ok');
    setTimeout(async()=>{
      const lr=await api('login',{phone,password:pw});
      if(lr.success){
        Auth.save(lr.data);
        const savedUser = Auth.getUser();
        const r = savedUser ? savedUser.role : null;
        if (r) {
          const roleMap = {
            customer: 'customer.html',
            courier: 'courier/',
            driver: 'driver/',
            service_giver: 'servicegiver.html',
            admin: 'admin.html'
          };
          location.href = roleMap[r] || 'index.html';
        }
      }
    },800);
  } catch (err) {
    if(btn) btn.disabled=false;
    if(btnTxt) btnTxt.textContent='Create Account →';
    showAlert('Server connection error. Please try again.');
  }
}


/* ── EtelID single sign-on mode ──────────────────────────────
   One EtelID = access to the whole ETEL ecosystem. Toggles the
   login form to accept EG-... id / FIN / card no. / phone with
   the member's EtelID password. */
window.ETELID_MODE = false;
window.toggleEtelIdMode = function () {
  window.ETELID_MODE = !window.ETELID_MODE;
  const lbl   = document.querySelector('label[class="flbl"]') || document.querySelector('.flbl');
  const phone = document.getElementById('inp-phone');
  const title = document.getElementById('fhdr-title');
  const icon  = document.getElementById('fhdr-icon');
  const btn   = document.getElementById('etelid-toggle');
  const sbtn  = document.getElementById('sbtn-txt');
  if (window.ETELID_MODE) {
    if (phone) { phone.type = 'text'; phone.placeholder = 'EtelID (EG-...), FIN, card no. or phone'; }
    if (lbl)   lbl.innerHTML = '<span>🪪</span>EtelID / FIN / Card / Phone';
    if (title) title.textContent = 'EtelID Sign-In';
    if (icon)  icon.textContent = '🪪';
    if (btn)   btn.textContent = '← Back to phone login';
    if (sbtn)  sbtn.textContent = 'Sign In with EtelID →';
  } else {
    if (phone) { phone.type = 'tel'; phone.placeholder = '+251 9XX XXX XXX'; }
    if (lbl)   lbl.innerHTML = '<span>📱</span>Phone Number';
    // Was hardcoded to "Customer Login"/📦 regardless of which role tab
    // was actually selected - switching back off EtelID mode on the
    // Driver/Courier/Admin tab still showed "Customer Login". Now
    // restores the title/icon for whichever tab is actually active.
    if (title) title.textContent = TITLES[role] || 'Login';
    if (icon)  icon.textContent = ICONS[role] || '📦';
    if (btn)   btn.textContent = '🪪 Sign in with EtelID — one ID for everything';
    if (sbtn)  sbtn.textContent = 'Sign In →';
  }
};

/* ══════════════════════════════════════════════════════════════
   PASSWORD RESET — Forgot password flow
   NOTE: this whole block (showForgot/doSendOtp/doVerifyOtp/
   doResetPassword and their frst-step1/2/3, frst-btn1/2/3,
   forgot-title/forgot-icon DOM ids) was confirmed-dead code as of
   2026-07-12: login.html's actual "Forgot password?" markup uses a
   different single-panel layout (frst-otp-wrap/frst-newpw-wrap/
   frst-btn ids) driven by an inline <script> in login.html itself,
   whose showForgot()/doForgotStep() are declared AFTER this file
   loads and silently override these same-named functions - so this
   version was never reachable from any button, and its DOM lookups
   always returned null. Removed rather than left in place, per this
   session's policy of deleting code confirmed dead by tracing call
   sites rather than guessing. The live flow now calls
   reset_request/reset_verify/reset_password (api/boot.php), which
   accepts both this file's reset_token contract and the live UI's
   raw-OTP-resend contract, so restoring this file's version later
   (if the UI is ever redesigned back to a 3-step layout) would work
   without further backend changes.
══════════════════════════════════════════════════════════════ */

// Also handle switchMode('login') from forgot panel back button
const _origSwitchMode = switchMode;
switchMode = function(m) {
  _origSwitchMode(m);
  const fvForgot = document.getElementById('fv-forgot');
  if (fvForgot) fvForgot.classList.remove('on');
};

