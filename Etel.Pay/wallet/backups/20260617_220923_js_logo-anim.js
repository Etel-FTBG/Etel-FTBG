/**
 * ኢE PAY — 3D Cinematic Logo Animation
 * Uses Three.js r128 (no OrbitControls needed)
 * Canvas: #c3d in login.html
 * Renders: spinning coin with logo texture + particle ring
 */
(function() {
  var canvas = document.getElementById('c3d');
  if (!canvas) return;

  /* ── Scene ─────────────────────────────────────────────── */
  var W = window.innerWidth, H = window.innerHeight;
  var renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.setSize(W, H);
  renderer.setClearColor(0x000000, 0);

  var scene  = new THREE.Scene();
  var camera = new THREE.PerspectiveCamera(45, W / H, 0.1, 100);
  camera.position.set(0, 0, 5.5);

  /* ── Lights ─────────────────────────────────────────────── */
  var amb = new THREE.AmbientLight(0xD4A843, 0.4);
  scene.add(amb);

  var key = new THREE.DirectionalLight(0xF0C84A, 2.2);
  key.position.set(3, 4, 5);
  scene.add(key);

  var fill = new THREE.DirectionalLight(0x3B82F6, 0.7);
  fill.position.set(-4, -2, 2);
  scene.add(fill);

  var rim = new THREE.DirectionalLight(0xFBE99A, 1.4);
  rim.position.set(0, 3, -3);
  scene.add(rim);

  /* ── Coin geometry ──────────────────────────────────────── */
  var coinGroup = new THREE.Group();
  scene.add(coinGroup);

  // Main coin disc
  var coinGeo  = new THREE.CylinderGeometry(1.4, 1.4, 0.18, 80, 1, false);
  var coinMat  = new THREE.MeshStandardMaterial({
    color: 0xD4A843,
    metalness: 0.92,
    roughness: 0.12,
    envMapIntensity: 1.2,
  });
  var coin = new THREE.Mesh(coinGeo, coinMat);
  coin.rotation.x = Math.PI / 2;
  coinGroup.add(coin);

  // Edge ring
  var edgeGeo = new THREE.TorusGeometry(1.4, 0.10, 24, 80);
  var edgeMat = new THREE.MeshStandardMaterial({ color: 0xF0C84A, metalness: 0.98, roughness: 0.06 });
  var edge    = new THREE.Mesh(edgeGeo, edgeMat);
  coinGroup.add(edge);

  /* ── Logo texture on coin face ──────────────────────────── */
  var logoImg = new Image();
  logoImg.crossOrigin = 'anonymous';
  logoImg.src = 'icons/logo.png';

  logoImg.onload = function() {
    // Canvas texture for the face
    var tc = document.createElement('canvas');
    tc.width = tc.height = 512;
    var ctx = tc.getContext('2d');

    // Gold base
    var grd = ctx.createRadialGradient(256,256,60,256,256,256);
    grd.addColorStop(0, '#F0C84A');
    grd.addColorStop(.5, '#D4A843');
    grd.addColorStop(1,  '#9A6E1A');
    ctx.fillStyle = grd;
    ctx.fillRect(0, 0, 512, 512);

    // Logo image centered
    var s = 340;
    ctx.drawImage(logoImg, (512-s)/2, (512-s)/2, s, s);

    // Subtle shine overlay
    var shine = ctx.createRadialGradient(180, 160, 10, 180, 160, 320);
    shine.addColorStop(0, 'rgba(255,255,255,.22)');
    shine.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = shine;
    ctx.fillRect(0, 0, 512, 512);

    var tex = new THREE.CanvasTexture(tc);

    // Front face plane
    var faceGeo  = new THREE.CircleGeometry(1.38, 80);
    var faceMat  = new THREE.MeshStandardMaterial({ map: tex, metalness: 0.5, roughness: 0.18 });
    var faceFront = new THREE.Mesh(faceGeo, faceMat);
    faceFront.position.z = 0.10;
    coinGroup.add(faceFront);

    // Back face (same texture, mirrored)
    var faceBack = new THREE.Mesh(faceGeo, faceMat.clone());
    faceBack.position.z = -0.10;
    faceBack.rotation.y = Math.PI;
    coinGroup.add(faceBack);
  };

  /* ── Particle ring ──────────────────────────────────────── */
  var PARTS = 120;
  var partPositions = new Float32Array(PARTS * 3);
  var partColors    = new Float32Array(PARTS * 3);
  for (var i = 0; i < PARTS; i++) {
    var angle = (i / PARTS) * Math.PI * 2;
    var r     = 2.1 + (Math.random() - .5) * 0.5;
    partPositions[i*3]     = Math.cos(angle) * r;
    partPositions[i*3 + 1] = (Math.random() - .5) * 0.4;
    partPositions[i*3 + 2] = Math.sin(angle) * r;
    // gold-to-white spectrum
    partColors[i*3]     = 0.85 + Math.random() * .15;
    partColors[i*3 + 1] = 0.65 + Math.random() * .25;
    partColors[i*3 + 2] = 0.20 + Math.random() * .15;
  }
  var partGeo  = new THREE.BufferGeometry();
  partGeo.setAttribute('position', new THREE.BufferAttribute(partPositions, 3));
  partGeo.setAttribute('color',    new THREE.BufferAttribute(partColors, 3));
  var partMat  = new THREE.PointsMaterial({ size: 0.045, vertexColors: true, transparent: true, opacity: 0.7 });
  var particles = new THREE.Points(partGeo, partMat);
  coinGroup.add(particles);

  /* ── Glow sprite ────────────────────────────────────────── */
  var glowC = document.createElement('canvas');
  glowC.width = glowC.height = 128;
  var gc = glowC.getContext('2d');
  var gg = gc.createRadialGradient(64,64,0,64,64,64);
  gg.addColorStop(0,   'rgba(212,168,67,.8)');
  gg.addColorStop(0.4, 'rgba(212,168,67,.15)');
  gg.addColorStop(1,   'rgba(212,168,67,0)');
  gc.fillStyle = gg;
  gc.fillRect(0,0,128,128);
  var glowTex = new THREE.CanvasTexture(glowC);
  var glowMat = new THREE.SpriteMaterial({ map: glowTex, transparent: true, opacity: 0.5, blending: THREE.AdditiveBlending });
  var glow    = new THREE.Sprite(glowMat);
  glow.scale.set(5, 5, 1);
  coinGroup.add(glow);

  /* ── Animation loop ─────────────────────────────────────── */
  var t = 0;
  var rafId = null;

  function animate() {
    rafId = requestAnimationFrame(animate);
    t += 0.012;

    // Cinematic rotation: slow main spin + gentle wobble
    coinGroup.rotation.y  = t * 0.55;
    coinGroup.rotation.x  = Math.sin(t * 0.4) * 0.18;
    coinGroup.rotation.z  = Math.cos(t * 0.3) * 0.06;

    // Particles counter-rotate slightly
    particles.rotation.y  = -t * 0.18;
    particles.rotation.x  =  t * 0.05;

    // Breathing glow
    glow.material.opacity = 0.35 + Math.sin(t * 1.8) * 0.12;
    glow.scale.setScalar(4.5 + Math.sin(t * 1.4) * 0.5);

    // Subtle camera drift
    camera.position.x = Math.sin(t * 0.18) * 0.3;
    camera.position.y = Math.cos(t * 0.14) * 0.15;
    camera.lookAt(0, 0, 0);

    renderer.render(scene, camera);
  }
  animate();

  /* ── Resize ─────────────────────────────────────────────── */
  window.addEventListener('resize', function() {
    W = window.innerWidth; H = window.innerHeight;
    camera.aspect = W / H;
    camera.updateProjectionMatrix();
    renderer.setSize(W, H);
  });

  /* ── Stop when navigating away ──────────────────────────── */
  window.addEventListener('beforeunload', function() {
    if (rafId) cancelAnimationFrame(rafId);
  });

})();
