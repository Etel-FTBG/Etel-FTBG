        // ── AUTH GUARD ──────────────────────────────────────────────────
        if (!requireRole(['courier','admin'])) throw new Error('Access denied');
        const user = Auth.getUser();

        // ── STATE ───────────────────────────────────────────────────────
        let availableJobs = [];

        // ── HELPERS ─────────────────────────────────────────────────────
        function apiGet(action, params) {
            if (!params) params = {};
            const qs = Object.entries(params).map(function(e){ return e[0]+'='+encodeURIComponent(e[1]); }).join('&');
            return fetch(ETEL.api + '?action=' + action + (qs ? '&'+qs : ''), {
                headers: {'Authorization': 'Bearer ' + Auth.getToken()}
            }).then(function(r){ return r.json(); });
        }
        function fmtDate(ts) {
            if (!ts) return '—';
            const d = new Date(ts);
            return d.toLocaleDateString('en-GB',{day:'2-digit',month:'short'}) + ' ' + d.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
        }

        // ── TAB SWITCHING ───────────────────────────────────────────────
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(function(t){ t.classList.remove('active'); });
            document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
            if (tabName === 'jobs') loadAvailableJobs();
            else if (tabName === 'active') loadActiveOrders();
            else if (tabName === 'history') loadHistory();
        }

        // ── NEW JOB ALERT (ringtone) ─────────────────────────────────────
        // Same synthesis approach as driver.js's alertNewRequest() - no
        // external audio file needed, works offline, no licensing concerns.
        // Slightly different pitch (660/990 vs driver's 880/1175) so a
        // courier who's also logged in as a driver elsewhere can tell the
        // two apart by ear. Rings continuously per-job (not a fixed count)
        // until accepted or muted - see driver.js for the same pattern.
        var _courierAudioCtx = null;
        if (!window._courierRingingTimers) window._courierRingingTimers = {};

        function _playCourierRingBurst() {
            try {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                if (!_courierAudioCtx) _courierAudioCtx = new Ctx();
                var ctx = _courierAudioCtx;
                if (ctx.state === 'suspended') ctx.resume().catch(function () {});
                [660, 990].forEach(function (freq, i) {
                    [{ type: 'sine', gain: 0.9 }, { type: 'triangle', gain: 0.5 }].forEach(function (layer) {
                        var osc = ctx.createOscillator();
                        var gain = ctx.createGain();
                        osc.type = layer.type;
                        osc.frequency.value = freq;
                        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
                        var start = ctx.currentTime + i * 0.16;
                        gain.gain.exponentialRampToValueAtTime(layer.gain, start + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.26);
                        osc.connect(gain); gain.connect(ctx.destination);
                        osc.start(start); osc.stop(start + 0.28);
                    });
                });
            } catch (e) { /* audio not available -- non-fatal */ }
        }
        function startRingingForJob(jobId) {
            if (window._courierRingingTimers[jobId]) return;
            if (navigator.vibrate) navigator.vibrate([200, 100, 200, 100, 200]);
            _playCourierRingBurst();
            window._courierRingingTimers[jobId] = setInterval(function () {
                if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
                _playCourierRingBurst();
            }, 1400);
        }
        function stopRingingForJob(jobId) {
            if (window._courierRingingTimers[jobId]) {
                clearInterval(window._courierRingingTimers[jobId]);
                delete window._courierRingingTimers[jobId];
            }
        }
        function stopAllCourierRinging() {
            Object.keys(window._courierRingingTimers).forEach(stopRingingForJob);
        }
        function muteJobRing(jobId, btn) {
            stopRingingForJob(jobId);
            if (btn) { btn.textContent = '🔇 Muted'; btn.disabled = true; btn.style.opacity = '0.5'; }
        }

        // ── LOAD AVAILABLE JOBS ─────────────────────────────────────────
        async function loadAvailableJobs() {
            document.getElementById('jobs-content').innerHTML = '<div style="text-align:center;padding:40px;color:#A8A8B8;font-size:14px">📍 Getting your location…</div>';
            // Request GPS first so distance filter works immediately
            if (locationGranted === null) {
                await new Promise(function(resolve) { initCourierLocation(resolve); });
            }
            document.getElementById('jobs-content').innerHTML = UI.loading('Loading available jobs...');
            const res = await apiGet('courier_get_available_orders', {});
            if (!res.success) {
                document.getElementById('jobs-content').innerHTML = '<div style="padding:20px;color:#E63946;font-size:13px">' + (res.message||'Failed to load jobs') + '</div>';
                return;
            }
            availableJobs = res.data.orders;
            if (!window._seenJobIds) window._seenJobIds = {};
            availableJobs.forEach(function (o) { window._seenJobIds[o.id] = true; });
            renderJobs(availableJobs);
        }

        function filterJobs(filter) {
            document.querySelectorAll('.filter-btn').forEach(function(b){ b.classList.remove('active'); });
            event.target.classList.add('active');
            if (filter === 'high') {
                renderJobs(availableJobs.filter(function(j){ return parseFloat(j.fare_total||0) >= 100; }));
            } else {
                renderJobs(availableJobs);
            }
        }

        function paymentBadgeHtml(method) {
            if (!method) return '';
            var m = String(method).toLowerCase();
            var map = {
                cash:     { label: '💵 Cash',     bg: 'rgba(255,193,7,.15)',  fg: '#e0a800' },
                wallet:   { label: '💰 ኢE Pay',   bg: 'rgba(212,175,55,.18)', fg: '#D4AF37' },
                telebirr: { label: '📱 Telebirr', bg: 'rgba(33,150,243,.15)', fg: '#2196f3' },
                abysinia: { label: '🏦 Bank',      bg: 'rgba(78,205,196,.15)', fg: '#4ECDC4' },
                cbe:      { label: '🏛️ Bank',      bg: 'rgba(78,205,196,.15)', fg: '#4ECDC4' }
            };
            var cfg = map[m] || { label: m.toUpperCase(), bg: 'rgba(255,255,255,.1)', fg: '#A8A8B8' };
            return '<span style="display:inline-block;padding:3px 9px;border-radius:6px;font-size:10.5px;font-weight:700;background:' + cfg.bg + ';color:' + cfg.fg + ';white-space:nowrap">' + cfg.label + '</span>';
        }

        function renderJobs(jobs) {
            // Show ALL available jobs to every courier — no zone/distance limit.
            // Distance is shown as info only (when GPS available) so the courier
            // can decide whether to take it.
            var visible = jobs || [];

            if (!visible.length) {
                document.getElementById('jobs-content').innerHTML =
                    '<div style="text-align:center;padding:60px 20px;color:#A8A8B8">' +
                    '<div style="font-size:48px;margin-bottom:16px">📦</div>' +
                    '<div style="font-size:16px;font-weight:600;margin-bottom:8px">No jobs available right now</div>' +
                    '<div style="font-size:13px">New orders appear here as soon as they\'re placed</div>' +
                    '<button onclick="loadAvailableJobs()" style="margin-top:20px;padding:12px 24px;background:var(--primary);color:#1a1a2e;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer">Refresh</button>' +
                    '</div>';
                return;
            }

            // Sort nearest-first when GPS is available (closest jobs at top), but still show all
            visible = visible.slice().sort(function(a, b) {
                var da = jobDistanceKm(a), db = jobDistanceKm(b);
                if (da === null && db === null) return 0;
                if (da === null) return 1;
                if (db === null) return -1;
                return da - db;
            });

            document.getElementById('jobs-content').innerHTML = visible.map(function(job) {
                var dist = jobDistanceKm(job);
                var distBadge = dist !== null
                    ? '<span class="dist-badge">' + dist.toFixed(1) + ' km away</span>'
                    : '';
                return '<div class="job-card">' +
                    '<div class="job-header">' +
                    '<span class="job-id">' + escapeHtml(job.order_code) + '</span>' +
                    '<span class="job-status">📦 ' + escapeHtml((job.package_type||'delivery').toUpperCase()) + '</span>' +
                    '<button onclick="muteJobRing(' + job.id + ', this)" style="margin-left:8px;padding:4px 9px;border:1px solid rgba(255,255,255,.2);background:transparent;color:#A8A8B8;border-radius:8px;font-size:11px;cursor:pointer">🔇 Mute</button>' +
                    '</div><div class="job-info">' +
                    '<div class="job-address"><span class="job-address-icon">🏪</span><span>Pickup: <strong>' + escapeHtml(job.from_zone||'—') + '</strong> ' + distBadge + '</span></div>' +
                    '<div class="job-address" style="margin-top:6px"><span class="job-address-icon">📍</span><span>Dropoff: ' + escapeHtml(job.to_zone||'—') + ' · ' + escapeHtml(job.recipient_name||'—') + (job.delivery_photo_url ? ' · 📷' : '') + '</span></div>' +
                    '<div class="job-meta">' +
                    '<div class="job-meta-item">📦 ' + escapeHtml(job.package_type||'package') + '</div>' +
                    '<div class="job-meta-item">🕐 ' + fmtDate(job.placed_at) + '</div>' +
                    (job.payment_method ? '<div class="job-meta-item">' + paymentBadgeHtml(job.payment_method) + '</div>' : '') +
                    '</div><div class="job-fee">You earn: ETB ' + parseFloat(job.delivery_fee != null ? job.delivery_fee : 0).toFixed(2) + '</div>' +
                    '<div class="job-actions">' +
                    '<button class="btn btn-primary" onclick="acceptJob(' + job.id + ',\'' + escapeHtml(job.order_code).replace(/'/g,"\\'") + '\')">Accept Job</button>' +
                    '<button class="btn btn-secondary" onclick="skipJob(' + job.id + ')">Skip</button>' +
                    '</div></div></div>';
            }).join('');
        }

        // ── ACCEPT / SKIP ───────────────────────────────────────────────
        async function acceptJob(orderId, orderCode) {
            if (!confirm('Accept job ' + orderCode + '?')) return;
            stopRingingForJob(orderId);
            const res = await api('courier_accept_order', {order_id: orderId});
            if (res && res.success) {
                showToast('Job accepted! Go to Active tab.');
                availableJobs = availableJobs.filter(function(j){ return j.id !== orderId; });
                renderJobs(availableJobs);
                // Switch to Active tab
                setTimeout(function(){
                    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
                    document.querySelectorAll('.tab-content').forEach(function(t){ t.classList.remove('active'); });
                    document.getElementById('active').classList.add('active');
                    document.querySelectorAll('.tab-btn')[1].classList.add('active');
                    loadActiveOrders();
                }, 800);
            } else {
                showToast((res && res.message) || 'Failed to accept job', 'err');
            }
        }

        function skipJob(orderId) {
            stopRingingForJob(orderId);
            availableJobs = availableJobs.filter(function(j){ return j.id !== orderId; });
            renderJobs(availableJobs);
        }

        // ── LOAD ACTIVE ORDERS ──────────────────────────────────────────
        async function loadActiveOrders() {
            document.getElementById('active-content').innerHTML = UI.loading('Loading active deliveries...');
            // Fetch all courier orders without status filter, then exclude delivered/cancelled
            const res = await apiGet('courier_get_my_orders', {});
            if (!res.success) {
                document.getElementById('active-content').innerHTML = '<div style="text-align:center;padding:40px;color:#A8A8B8">Could not load active orders.</div>';
                return;
            }
            const active = (res.data.orders || []).filter(function(o) {
                return o.status !== 'delivered' && o.status !== 'cancelled';
            });
            renderActiveOrders(active);
        }

        function renderActiveOrders(orders) {
            window._activeOrders = orders; // cache for re-renders
            if (!orders.length) {
                document.getElementById('active-content').innerHTML =
                    '<div style="text-align:center;padding:60px 20px;color:#A8A8B8">' +
                    '<div style="font-size:48px;margin-bottom:16px">🚗</div>' +
                    '<div style="font-size:16px;font-weight:600;margin-bottom:8px">No active deliveries</div>' +
                    '<div style="font-size:13px">Accept a job from the Jobs tab</div>' +
                    '</div>';
                return;
            }
            document.getElementById('active-content').innerHTML = orders.map(function(o) {
                const statusLabel = {
                    assigned:   '📦 Go to Shop — Pickup',
                    picked_up:  '🚚 Deliver to Customer',
                    in_transit: '🚗 In Transit to Customer'
                }[o.status] || o.status;

                // ── Define shop GPS FIRST (used in both map section AND navigation buttons) ──
                var shopLat  = parseFloat(o.sg_lat)  || null;
                var shopLng  = parseFloat(o.sg_lng)  || null;
                var shopName = o.sg_business_name    || o.from_zone || 'Shop';
                var shopAddr = o.sg_address          || o.from_zone || '';

                // ── Customer delivery GPS ──
                var delivLat  = parseFloat(o.delivery_lat)  || null;
                var delivLng  = parseFloat(o.delivery_lng)  || null;
                var delivAddr = (o.recipient_address && o.recipient_address.trim())
                                ? o.recipient_address
                                : (o.to_zone || '') + ', Hawassa';

                // Extract delivery address from notes (prefixed "Address: ...")
                var addrLine = '';
                if (o.notes && o.notes.startsWith('Address:')) {
                    var addrText = o.notes.split('\n')[0].replace('Address:', '').trim();
                    addrLine = '<div class="job-address" style="margin-top:6px;color:#A8A8B8;font-size:12px"><span class="job-address-icon">🏠</span><span>' + escapeHtml(addrText) + '</span></div>';
                }

                // ── Navigation + confirmation buttons ──
                var actionBtns = '';
                var confState = window._confState && window._confState[o.id];

                if (o.status === 'assigned') {
                    // Navigate to SHOP — use exact GPS if available, else zone centroid
                    var navShopClick = shopLat && shopLng
                        ? 'navigateToShop(\'' + (o.from_zone||'').replace(/'/g,"\\'") + '\',' + shopLat + ',' + shopLng + ')'
                        : 'navigateToShop(\'' + (o.from_zone||'').replace(/'/g,"\\'") + '\')';

                    var navShop = '<button class="nav-btn nav-btn-shop" style="width:100%;padding:14px;font-size:15px;font-weight:700;background:linear-gradient(135deg,#D4AF37,#b8922e);color:#1a1a2e;border:none;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:8px" onclick="' + navShopClick + '">' +
                        '🗺️ NAVIGATE TO SHOP</button>';

                    if (confState === 'pickup_pending') {
                        actionBtns = navShop +
                            '<div class="conf-waiting" style="margin-top:8px;padding:14px;background:linear-gradient(135deg, rgba(212,175,55,.12), rgba(212,175,55,.03));border:1px solid rgba(212,175,55,.35);border-radius:12px;text-align:center;box-shadow:0 10px 24px -8px rgba(0,0,0,.4)">' +
                            '<div style="display:flex;align-items:center;justify-content:center;gap:8px;font-size:12.5px;font-weight:600;color:#F2CC6B"><span class="conf-pulse-dot" style="width:8px;height:8px;border-radius:50%;background:#F2CC6B;display:inline-block;animation:confPulse 1.4s ease-in-out infinite"></span>Waiting for service giver to confirm pickup…</div>' +
                            '<button class="conf-cancel-btn" onclick="cancelConfirmation(' + o.id + ',\'pickup\')" style="margin-top:8px;font-size:11px;padding:5px 14px;border-radius:7px;border:1px solid rgba(255,255,255,.15);background:transparent;color:#B8B0A0;cursor:pointer">Cancel request</button></div>';
                    } else {
                        actionBtns = navShop +
                            '<button onclick="showOrderQR(' + o.id + ',\'pickup\')" style="width:100%;padding:12px;font-size:13px;font-weight:700;background:rgba(212,175,55,.15);color:#D4AF37;border:1px solid rgba(212,175,55,.4);border-radius:10px;cursor:pointer;margin-bottom:8px">' +
                            '📱 Show Pickup QR</button>' +
                            '<button onclick="requestPickupConfirmation(' + o.id + ',\'' + (o.order_code||'').replace(/[<>"'\\]/g, '') + '\')" style="width:100%;padding:12px;font-size:13px;font-weight:600;background:rgba(52,152,219,.15);color:#3498db;border:1px solid rgba(52,152,219,.4);border-radius:10px;cursor:pointer">' +
                            '📦 I\'m at the shop — Request Pickup Confirmation</button>';
                    }

                } else if (o.status === 'picked_up' || o.status === 'in_transit') {
                    // Navigate to CUSTOMER
                    // Stripped, not just quote-escaped: this string is embedded
                    // both inside a JS string literal AND inside an HTML
                    // onclick="..." attribute, so any of these chars in a
                    // user-entered address could break out of either context.
                    var delivAddrSafe = delivAddr.replace(/[<>"'\\]/g, '');
                    var navCustClick = delivLat && delivLng
                        ? 'navigateToCustomer(' + delivLat + ',' + delivLng + ',\'' + delivAddrSafe + '\')'
                        : 'navigateToCustomer(null,null,\'' + delivAddrSafe + '\')';

                    var navCust = '<button class="nav-btn nav-btn-customer" style="width:100%;padding:14px;font-size:15px;font-weight:700;background:linear-gradient(135deg,#2ECC71,#27ae60);color:#fff;border:none;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:8px" onclick="' + navCustClick + '">' +
                        '🗺️ NAVIGATE TO CUSTOMER</button>';

                    if (confState === 'delivery_pending') {
                        actionBtns = navCust +
                            '<div class="conf-waiting" style="margin-top:8px;padding:10px;background:rgba(46,204,113,.1);border-radius:8px;text-align:center;font-size:12px">' +
                            '⏳ Waiting for customer to confirm receipt…<br>' +
                            '<button class="conf-cancel-btn" onclick="cancelConfirmation(' + o.id + ',\'delivery\')" style="margin-top:6px;font-size:11px;padding:4px 12px;border-radius:6px;border:1px solid #888;background:transparent;color:#B8B0A0;cursor:pointer">Cancel request</button></div>';
                    } else {
                        actionBtns = navCust +
                            '<button onclick="showOrderQR(' + o.id + ',\'delivery\')" style="width:100%;padding:12px;font-size:13px;font-weight:700;background:rgba(212,175,55,.15);color:#D4AF37;border:1px solid rgba(212,175,55,.4);border-radius:10px;cursor:pointer;margin-bottom:8px">' +
                            '📱 Show Delivery QR</button>' +
                            '<button onclick="requestDeliveryConfirmation(' + o.id + ',\'' + (o.order_code||'').replace(/[<>"'\\]/g, '') + '\')" style="width:100%;padding:12px;font-size:13px;font-weight:600;background:rgba(46,204,113,.15);color:#2ECC71;border:1px solid rgba(46,204,113,.4);border-radius:10px;cursor:pointer">' +
                            '🚪 I\'ve delivered — Request Customer Confirmation</button>';
                    }
                }

                // ── Shop location map thumbnail (always shown while assigned) ──
                var shopMapHtml = '';
                if (shopLat && shopLng) {
                    var staticUrl = 'https://maps.googleapis.com/maps/api/staticmap?center=' + shopLat + ',' + shopLng +
                        '&zoom=16&size=600x180&scale=2&maptype=roadmap' +
                        '&markers=color:red%7Clabel:S%7C' + shopLat + ',' + shopLng +
                        '&key=' + (ETEL.mapsKey || 'AIzaSyDeZ0j7cZcctskKKO_D27Qgm_38sXHy0QA');
                    var mapsLink = 'https://www.google.com/maps/dir/?api=1&destination=' + shopLat + ',' + shopLng + '&travelmode=driving';
                    shopMapHtml = '<div style="margin:10px 0 4px;font-size:11px;color:#A8A8B8;font-weight:600;letter-spacing:.05em">📍 SHOP LOCATION</div>' +
                        '<a href="' + mapsLink + '" target="_blank" rel="noopener" style="display:block;border-radius:10px;overflow:hidden;position:relative">' +
                        '<img src="' + staticUrl + '" style="width:100%;height:130px;object-fit:cover;border-radius:10px;border:1px solid rgba(212,175,55,.2)" alt="Shop location map" onerror="this.onerror=null;this.style.display=\'none\'">' +
                        '<div style="position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,.75);color:#fff;font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px">Open in Maps ↗</div>' +
                        '</a>' +
                        '<div style="font-size:11px;color:#A8A8B8;margin-top:4px">📍 ' + escapeHtml(shopAddr) + '</div>';
                } else {
                    var zc = ZONE_COORDS[o.from_zone];
                    if (zc) {
                        var mapsZoneLink = 'https://www.google.com/maps/dir/?api=1&destination=' + zc.lat + ',' + zc.lng + '&travelmode=driving';
                        shopMapHtml = '<div style="margin:10px 0 4px;font-size:11px;color:#A8A8B8;font-weight:600;letter-spacing:.05em">📍 PICKUP ZONE</div>' +
                            '<a href="' + mapsZoneLink + '" target="_blank" rel="noopener" ' +
                            'style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.2);border-radius:10px;color:#D4AF37;text-decoration:none">' +
                            '<span style="font-size:22px">🗺️</span>' +
                            '<div><div style="font-size:13px;font-weight:700">' + escapeHtml(o.from_zone||'Unknown zone') + '</div>' +
                            '<div style="font-size:11px;opacity:.7">Tap to open in Google Maps navigation</div></div>' +
                            '</a>';
                    }
                }

                // ── Customer destination map thumbnail (shown when picked_up) ──
                var custMapHtml = '';
                if ((o.status === 'picked_up' || o.status === 'in_transit') && delivLat && delivLng) {
                    var custStaticUrl = 'https://maps.googleapis.com/maps/api/staticmap?center=' + delivLat + ',' + delivLng +
                        '&zoom=16&size=600x180&scale=2&maptype=roadmap' +
                        '&markers=color:blue%7Clabel:C%7C' + delivLat + ',' + delivLng +
                        '&key=' + (ETEL.mapsKey || 'AIzaSyDeZ0j7cZcctskKKO_D27Qgm_38sXHy0QA');
                    var custMapsLink = 'https://www.google.com/maps/dir/?api=1&destination=' + delivLat + ',' + delivLng + '&travelmode=driving';
                    custMapHtml = '<div style="margin:10px 0 4px;font-size:11px;color:#A8A8B8;font-weight:600;letter-spacing:.05em">📍 CUSTOMER LOCATION</div>' +
                        '<a href="' + custMapsLink + '" target="_blank" rel="noopener" style="display:block;border-radius:10px;overflow:hidden;position:relative">' +
                        '<img src="' + custStaticUrl + '" style="width:100%;height:130px;object-fit:cover;border-radius:10px;border:1px solid rgba(52,152,219,.2)" alt="Customer location map" onerror="this.onerror=null;this.style.display=\'none\'">' +
                        '<div style="position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,.75);color:#fff;font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px">Open in Maps ↗</div>' +
                        '</a>' +
                        '<div style="font-size:11px;color:#A8A8B8;margin-top:4px">📍 ' + escapeHtml(delivAddr) + '</div>';
                }

                var locationMapHtml = (o.status === 'assigned') ? shopMapHtml : custMapHtml;

                return '<div class="job-card accepted">' +
                    '<div class="job-header"><span class="job-id">' + escapeHtml(o.order_code) + '</span>' +
                    '<div class="status-badge"><div class="status-dot"></div><span>' + statusLabel + '</span></div></div>' +
                    '<div class="job-info">' +
                    '<div class="job-address"><span class="job-address-icon">🏪</span><span>Pickup: <strong>' + escapeHtml(shopName) + '</strong> · ' + escapeHtml(o.from_zone||'—') + '</span></div>' +
                    '<div class="job-address" style="margin-top:6px"><span class="job-address-icon">📍</span><span>Dropoff: ' + escapeHtml(o.to_zone||'—') + '</span></div>' +
                    addrLine +
                    '<div class="job-meta" style="margin-top:8px">' +
                    '<div class="job-meta-item">👤 ' + escapeHtml(o.recipient_name||'—') + '</div>' +
                    '<div class="job-meta-item">📞 ' + escapeHtml(o.recipient_phone||'—') + '</div>' +
                    (o.payment_method ? '<div class="job-meta-item">' + paymentBadgeHtml(o.payment_method) + '</div>' : '') +
                    '</div>' +
                    (o.delivery_photo_url
                        ? '<a href="' + escUrl(o.delivery_photo_url) + '" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:8px;margin-top:8px;text-decoration:none">' +
                          '<img src="' + escUrl(o.delivery_photo_url) + '" style="width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid rgba(255,255,255,.15)" alt="Gate/entrance photo">' +
                          '<span style="font-size:12px;color:#A8A8B8">📷 Customer added a gate/entrance photo — tap to view</span>' +
                          '</a>'
                        : '') +
                    (String(o.payment_method).toLowerCase() === 'cash' && (o.status === 'picked_up' || o.status === 'in_transit')
                        ? '<div style="margin-top:6px;font-size:11.5px;font-weight:700;color:#e0a800;background:rgba(255,193,7,.1);padding:6px 10px;border-radius:8px">💵 Collect ETB ' + parseFloat(o.fare_total||0).toFixed(2) + ' cash from the customer</div>'
                        : '') +
                    '<div class="job-fee" style="color:var(--accent-green)">You earn: ETB ' + parseFloat(o.delivery_fee != null ? o.delivery_fee : 0).toFixed(2) + '</div>' +
                    '<div class="job-actions" style="margin-top:12px">' + actionBtns + '</div>' +
                    locationMapHtml +
                    '</div></div>';
            }).join('');

            // Auto-draw route on the courier map for the first active order
            var first = orders[0];
            if (first && map) {
                if (first.status === 'assigned') {
                    var sLat = parseFloat(first.sg_lat) || null;
                    var sLng = parseFloat(first.sg_lng) || null;
                    var zone = first.from_zone || '';
                    if (sLat && sLng) {
                        drawRouteOnMap(sLat, sLng, 'Shop · ' + zone);
                    } else if (ZONE_COORDS[zone]) {
                        drawRouteOnMap(ZONE_COORDS[zone].lat, ZONE_COORDS[zone].lng, 'Shop · ' + zone);
                    }
                } else if (first.status === 'picked_up' || first.status === 'in_transit') {
                    var dLat = parseFloat(first.delivery_lat) || null;
                    var dLng = parseFloat(first.delivery_lng) || null;
                    var dAddr = (first.recipient_address && first.recipient_address.trim())
                        ? first.recipient_address : (first.to_zone || '') + ', Hawassa';
                    if (dLat && dLng) drawRouteOnMap(dLat, dLng, dAddr);
                    else if (ZONE_COORDS[first.to_zone]) drawRouteOnMap(ZONE_COORDS[first.to_zone].lat, ZONE_COORDS[first.to_zone].lng, dAddr);
                }
            }
        }

        // ── HAWASSA ZONE COORDINATES ──────────────────────────────────────
        const ZONE_COORDS = {
            'Stadium':               { lat: 7.0606, lng: 38.4775 },
            'Mehal Ketema':          { lat: 7.0559, lng: 38.4762 },
            'Tabor':                 { lat: 7.0400, lng: 38.4900 },
            'Liqe Mender':           { lat: 7.0700, lng: 38.4800 },
            'Referral':              { lat: 7.0490, lng: 38.4680 },
            'Addis Ketema':          { lat: 7.0450, lng: 38.4750 },
            'University':            { lat: 7.0620, lng: 38.5110 },
            'Misrak':                { lat: 7.0800, lng: 38.4850 },
            'Haik Dar':              { lat: 7.0350, lng: 38.5000 },
            'Tulu Dimtu':            { lat: 7.1000, lng: 38.5000 },
            'Hawela Teklehaimanot':  { lat: 7.0650, lng: 38.4900 },
            'Dore Bafana':           { lat: 7.1200, lng: 38.4700 }
        };
        const HAWASSA_CENTER = { lat: 7.0559, lng: 38.4762 };
        const HAWASSA_MAX_RADIUS_KM = 60; // ETEL only operates in and around Hawassa
        let _locWarnedThisSession = false;

        // ── COURIER GPS LOCATION ──────────────────────────────────────────
        let courierLat = null, courierLng = null, locationGranted = null;

        function initCourierLocation(onDone) {
            if (!navigator.geolocation) { locationGranted = false; if (onDone) onDone(); return; }
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    courierLat = pos.coords.latitude;
                    courierLng = pos.coords.longitude;
                    locationGranted = true;
                    if (onDone) onDone();
                    navigator.geolocation.watchPosition(function(p) {
                        courierLat = p.coords.latitude;
                        courierLng = p.coords.longitude;
                    });
                },
                function() { locationGranted = false; if (onDone) onDone(); },
                { enableHighAccuracy: true, timeout: 8000 }
            );
        }

        function haversineKm(lat1, lng1, lat2, lng2) {
            var R = 6371, dLat = (lat2-lat1)*Math.PI/180, dLng = (lng2-lng1)*Math.PI/180;
            var a = Math.sin(dLat/2)*Math.sin(dLat/2) +
                    Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*
                    Math.sin(dLng/2)*Math.sin(dLng/2);
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        }

        // Guards against browser geolocation's network-based fallback (used
        // on any device without real GPS hardware, e.g. a desktop browser)
        // resolving hundreds of km away from Hawassa -- this has been
        // observed resolving to Addis Ababa on this ISP. Distances and
        // routes should never silently trust a location that far off.
        function courierLocationIsPlausible() {
            if (!courierLat || !courierLng) return false;
            return haversineKm(courierLat, courierLng, HAWASSA_CENTER.lat, HAWASSA_CENTER.lng) <= HAWASSA_MAX_RADIUS_KM;
        }

        function jobDistanceKm(job) {
            if (!courierLocationIsPlausible()) return null;
            var c = ZONE_COORDS[job.from_zone];
            if (!c) return null;
            return haversineKm(courierLat, courierLng, c.lat, c.lng);
        }

        // ── CONFIRMATION STATE ─────────────────────────────────────────
        // keyed by orderId → 'pickup_pending' | 'delivery_pending' | undefined
        window._confState = {};
        window._confPollers = {}; // active polling intervals

        function setConfState(orderId, state) {
            window._confState[orderId] = state;
        }
        function clearConfState(orderId) {
            delete window._confState[orderId];
            if (window._confPollers[orderId]) {
                clearInterval(window._confPollers[orderId]);
                delete window._confPollers[orderId];
            }
        }

        function startPolling(orderId, type) {
            if (window._confPollers[orderId]) clearInterval(window._confPollers[orderId]);
            const action = type === 'pickup' ? 'check_pickup_confirmation' : 'check_delivery_confirmation';
            window._confPollers[orderId] = setInterval(async function() {
                try {
                    const res = await apiGet(action, {order_id: orderId});
                    if (res && res.success && res.data && res.data.status === 'confirmed') {
                        clearConfState(orderId);
                        if (type === 'pickup') {
                            showToast('✅ Pickup confirmed by service giver! Order is now in transit.');
                        } else {
                            showToast('🎉 Delivery confirmed by customer! Great job!');
                        }
                        loadActiveOrders();
                        if (type === 'delivery') {
                            setTimeout(function() {
                                document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
                                document.querySelectorAll('.tab-content').forEach(function(t){ t.classList.remove('active'); });
                                document.getElementById('history').classList.add('active');
                                document.querySelectorAll('.tab-btn')[2].classList.add('active');
                                loadHistory();
                            }, 1200);
                        }
                    }
                } catch(e) { /* ignore network errors during polling */ }
            }, 5000);
        }

        async function requestPickupConfirmation(orderId, orderCode) {
            if (!confirm('Request pickup confirmation from the service giver for order ' + orderCode + '?\n\nThey will see your request in their app and must confirm before the order is marked as picked up.')) return;
            const res = await api('request_pickup_confirmation', {order_id: orderId});
            if (res && res.success) {
                setConfState(orderId, 'pickup_pending');
                renderActiveOrders(window._activeOrders || []);
                startPolling(orderId, 'pickup');
                showToast('Request sent! Waiting for service giver to confirm…');
            } else {
                showToast((res && res.message) || 'Failed to send request', 'err');
            }
        }

        async function requestDeliveryConfirmation(orderId, orderCode) {
            if (!confirm('Request delivery confirmation from the customer for order ' + orderCode + '?\n\nThey will see your request in their app and must confirm receipt and payment.')) return;
            const res = await api('request_delivery_confirmation', {order_id: orderId});
            if (res && res.success) {
                setConfState(orderId, 'delivery_pending');
                renderActiveOrders(window._activeOrders || []);
                startPolling(orderId, 'delivery');
                showToast('Request sent! Waiting for customer to confirm…');
            } else {
                showToast((res && res.message) || 'Failed to send request', 'err');
            }
        }

        // ── ORDER QR (instant scan-based confirmation, alternate to request/wait flow) ──
        window._orderQrPoller = null;
        function showOrderQR(orderId, type) {
            var box = document.getElementById('order-qr-code');
            box.innerHTML = '';
            new QRCode(box, {
                text: JSON.stringify({ order_id: orderId, type: type }),
                width: 200, height: 200,
                colorDark: '#000000', colorLight: '#ffffff'
            });
            document.getElementById('order-qr-title').textContent =
                type === 'pickup' ? 'Show this to the Service Giver' : 'Show this to the Customer';
            document.getElementById('order-qr-sub').textContent =
                'They scan it in their app to confirm ' + type + ' instantly';
            document.getElementById('order-qr-overlay').style.display = 'flex';

            // Delivery is confirmed by the customer (real payment/cash
            // acknowledgement, not the courier or a service giver), so
            // fire the existing request so the customer's app has a
            // pending confirmation ready the instant they scan this QR.
            if (type === 'delivery' && window._confState[orderId] !== 'delivery_pending') {
                api('request_delivery_confirmation', { order_id: orderId }).catch(function(){});
            }

            var startStatus = type === 'pickup' ? 'assigned' : (window._activeOrders.find(function(o){return o.id===orderId;})||{}).status;
            if (window._orderQrPoller) clearInterval(window._orderQrPoller);
            window._orderQrPoller = setInterval(async function() {
                await loadActiveOrders();
                var o = (window._activeOrders || []).find(function(x){ return x.id === orderId; });
                if (!o || o.status !== startStatus) {
                    clearInterval(window._orderQrPoller);
                    window._orderQrPoller = null;
                    closeOrderQR();
                    showToast(type === 'pickup' ? '✅ Pickup confirmed! Order is now in transit.' : '🎉 Delivery confirmed! Great job!');
                }
            }, 4000);
        }
        function closeOrderQR() {
            document.getElementById('order-qr-overlay').style.display = 'none';
            if (window._orderQrPoller) { clearInterval(window._orderQrPoller); window._orderQrPoller = null; }
        }

        function cancelConfirmation(orderId, type) {
            clearConfState(orderId);
            renderActiveOrders(window._activeOrders || []);
            showToast('Confirmation request cancelled');
        }

        // ── UPDATE STATUS (kept for admin override if needed) ───────────
        async function updateStatus(orderId, status) {
            const labels = {picked_up:'Picked Up', in_transit:'In Transit', delivered:'Delivered'};
            if (!confirm('Override and mark as ' + (labels[status]||status) + '?')) return;
            const res = await api('courier_update_order_status', {order_id: orderId, status: status});
            if (res && res.success) {
                clearConfState(orderId);
                showToast(labels[status] + '! ' + (status === 'delivered' ? 'Great job! 🎉' : ''));
                if (status === 'delivered') {
                    setTimeout(function(){
                        document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
                        document.querySelectorAll('.tab-content').forEach(function(t){ t.classList.remove('active'); });
                        document.getElementById('history').classList.add('active');
                        document.querySelectorAll('.tab-btn')[2].classList.add('active');
                        loadHistory();
                    }, 1000);
                } else {
                    loadActiveOrders();
                }
            } else {
                showToast((res && res.message) || 'Update failed', 'err');
            }
        }

        // ── LOAD HISTORY ────────────────────────────────────────────────
        async function loadHistory() {
            document.getElementById('history-content').innerHTML = UI.loading('Loading history...');
            const res = await apiGet('courier_get_my_orders', {status: 'delivered'});
            if (!res.success) {
                document.getElementById('history-content').innerHTML = '<div style="text-align:center;padding:40px;color:#A8A8B8">No history available.</div>';
                return;
            }
            const orders = res.data.orders || [];
            if (!orders.length) {
                document.getElementById('history-content').innerHTML =
                    '<div style="text-align:center;padding:60px 20px;color:#A8A8B8">' +
                    '<div style="font-size:48px;margin-bottom:16px">📋</div>' +
                    '<div style="font-size:16px;font-weight:600;margin-bottom:8px">No completed deliveries yet</div>' +
                    '</div>';
                return;
            }
            const totalEarned = orders.reduce(function(s,o){ return s + parseFloat(o.delivery_fee != null ? o.delivery_fee : 0); }, 0);
            document.getElementById('history-content').innerHTML =
                '<div style="background:rgba(46,204,113,.1);border:1px solid rgba(46,204,113,.3);border-radius:10px;padding:14px;margin-bottom:16px;text-align:center">' +
                '<div style="font-size:13px;color:#A8A8B8;margin-bottom:4px">Total Earned</div>' +
                '<div style="font-size:22px;font-weight:700;color:#2ECC71">ETB ' + totalEarned.toFixed(2) + '</div>' +
                '<div style="font-size:12px;color:#A8A8B8">' + orders.length + ' deliveries completed</div>' +
                '</div>' +
                orders.map(function(o) {
                    return '<div class="job-card" style="opacity:0.85">' +
                        '<div class="job-header"><span class="job-id">' + escapeHtml(o.order_code) + '</span>' +
                        '<span class="job-status" style="background:rgba(46,204,113,.2);color:var(--accent-green);border-color:rgba(46,204,113,.3)">✓ DELIVERED</span></div>' +
                        '<div class="job-info">' +
                        '<div class="job-address"><span class="job-address-icon">📍</span><span>' + escapeHtml(o.from_zone||'—') + ' → ' + escapeHtml(o.to_zone||'—') + '</span></div>' +
                        (o.payment_method ? '<div style="margin-top:6px">' + paymentBadgeHtml(o.payment_method) + '</div>' : '') +
                        '<div class="job-fee" style="color:var(--accent-green)">You earned: ETB ' + parseFloat(o.delivery_fee != null ? o.delivery_fee : 0).toFixed(2) + '</div>' +
                        '</div></div>';
                }).join('');
        }

        // ── EARNINGS ────────────────────────────────────────────────────
        async function showEarnings() {
            const res = await apiGet('courier_get_my_orders', {status: 'delivered'});
            if (!res.success) { showToast('Could not load earnings', 'err'); return; }
            const orders = res.data.orders || [];
            const total = orders.reduce(function(s,o){ return s + parseFloat(o.delivery_fee != null ? o.delivery_fee : 0); }, 0);
            showToast(orders.length + ' deliveries · ETB ' + total.toFixed(2) + ' earned');
        }

        // ── PROFILE ─────────────────────────────────────────────────────
        function cpInitials(name) {
            var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
            if (!parts.length) return '?';
            return (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
        }

        var _cpProfileLoaded = false;

        function toggleProfile() {
            var overlay = document.getElementById('profile-drop');
            var panel = document.getElementById('cp-profile-panel');
            var isOpen = overlay.style.display === 'block';
            if (isOpen) { closeProfile(); return; }

            if (user) {
                document.getElementById('cp-avatar').textContent = cpInitials(user.name);
                document.getElementById('cp-name').textContent = user.name || 'Courier';
                document.getElementById('cp-phone').textContent = user.phone || '';
            }
            overlay.style.display = 'block';
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { panel.style.transform = 'translateX(0)'; });
            });
            loadCourierKpiSummary('today');
            loadCourierFuelLogs();

            if (!_cpProfileLoaded) {
                _cpProfileLoaded = true;
                loadCourierProfileStats();
            }
        }

        function closeProfile() {
            var overlay = document.getElementById('profile-drop');
            var panel = document.getElementById('cp-profile-panel');
            panel.style.transform = 'translateX(100%)';
            setTimeout(function () { overlay.style.display = 'none'; }, 340);
        }

        function loadCourierProfileStats() {
            // Real profile fields: vehicle, zone, courier code, rating
            apiGet('courier_get_profile', {}).then(function (res) {
                if (!res || !res.success || !res.data) return;
                var d = res.data;
                var vehicleEl = document.getElementById('cp-vehicle');
                var zoneEl = document.getElementById('cp-zone');
                var codeEl = document.getElementById('cp-code');
                var ratingEl = document.getElementById('cp-stat-rating');
                if (vehicleEl) vehicleEl.textContent = d.vehicle_type || 'Not set';
                if (zoneEl) zoneEl.textContent = d.zone || d.home_zone || 'Not set';
                if (codeEl) codeEl.textContent = d.courier_code || '—';
                if (ratingEl) ratingEl.textContent = (d.rating && d.rating > 0) ? d.rating.toFixed(1) + ' ⭐' : 'New';
            }).catch(function () {});

            // Deliveries + all-time earnings: same source courier-premium.js
            // already uses for the dashboard earnings card, so the numbers
            // always agree with what's shown there.
            apiGet('courier_get_my_orders', { status: 'delivered' }).then(function (res) {
                if (!res || !res.success || !res.data) return;
                var orders = res.data.orders || [];
                var total = orders.reduce(function (s, o) { return s + (parseFloat(o.delivery_fee != null ? o.delivery_fee : 0) || 0); }, 0);
                var delEl = document.getElementById('cp-stat-deliveries');
                var earnEl = document.getElementById('cp-stat-earnings');
                if (delEl) delEl.textContent = orders.length;
                if (earnEl) earnEl.textContent = 'ETB ' + Math.round(total).toLocaleString();
            }).catch(function () {});
        }

        // ── FUEL MANAGEMENT ──────────────────────────────────────────────
        // Same real cost/km concept as the driver app's fuel logs - a
        // courier logs actual fill-ups (liters, cost, optional odometer),
        // and with 2+ odometer readings the KPI card below shows a real
        // "net after fuel" figure instead of just gross earnings.
        function openCourierFuelLogForm() {
            let bg = document.getElementById('courier-fuel-bg');
            if (bg) bg.remove();
            bg = document.createElement('div');
            bg.id = 'courier-fuel-bg';
            bg.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:flex-end;justify-content:center;background:rgba(5,5,10,.7);backdrop-filter:blur(4px)';
            bg.addEventListener('click', function (e) { if (e.target === bg) bg.remove(); });
            bg.innerHTML = '<div style="width:min(460px,100vw);background:linear-gradient(180deg,#171006,#0a0703);border:1px solid rgba(212,175,55,.25);border-bottom:none;border-radius:20px 20px 0 0;padding:22px 22px max(22px,env(safe-area-inset-bottom,22px));color:#F5EDD8;font-family:inherit">' +
                '<div style="font-size:17px;font-weight:800;color:#D4AF37;margin-bottom:10px">⛽ Log a Fill-up</div>' +
                '<p style="margin-bottom:14px;font-size:12.5px;color:rgba(245,237,216,.6)">Enter what you actually paid. Adding your current odometer reading lets us calculate your real cost per km once you have 2+ logs.</p>' +
                '<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Liters</label>' +
                '<input id="courier-fuel-liters" type="number" min="0" step="0.1" placeholder="e.g. 5" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">' +
                '<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Amount paid (ETB)</label>' +
                '<input id="courier-fuel-cost" type="number" min="0" step="0.01" placeholder="e.g. 950" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">' +
                '<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Odometer reading (km) — optional but recommended</label>' +
                '<input id="courier-fuel-odo" type="number" min="0" step="0.1" placeholder="e.g. 12450" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">' +
                '<div id="courier-fuel-msg" style="display:none;font-size:12.5px;font-weight:600;line-height:1.5;padding:10px 12px;border-radius:10px;margin:10px 0"></div>' +
                '<button id="courier-fuel-submit" onclick="saveCourierFuelLog()" style="width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#FFE9A8,#D4AF37);color:#1a1a2e;font-weight:800;font-size:14px;cursor:pointer;margin-top:8px">Save Fill-up</button>' +
                '<button onclick="document.getElementById(\'courier-fuel-bg\').remove()" style="width:100%;padding:12px;margin-top:8px;border:1px solid rgba(255,255,255,.15);background:transparent;color:#F5EDD8;border-radius:12px;font-weight:700;cursor:pointer">Cancel</button>' +
                '</div>';
            document.body.appendChild(bg);
        }

        function saveCourierFuelLog() {
            var liters = parseFloat(document.getElementById('courier-fuel-liters').value);
            var cost = parseFloat(document.getElementById('courier-fuel-cost').value);
            var odoEl = document.getElementById('courier-fuel-odo');
            var odo = odoEl && odoEl.value !== '' ? parseFloat(odoEl.value) : null;
            var msgEl = document.getElementById('courier-fuel-msg');
            var btnEl = document.getElementById('courier-fuel-submit');
            function showMsg(text, ok) {
                msgEl.style.display = 'block';
                msgEl.style.color = ok ? '#2ECC71' : '#ff6b6b';
                msgEl.style.background = ok ? 'rgba(46,204,113,.1)' : 'rgba(255,107,107,.1)';
                msgEl.style.border = '1px solid ' + (ok ? 'rgba(46,204,113,.3)' : 'rgba(255,107,107,.3)');
                msgEl.textContent = text;
            }
            if (!(liters > 0)) { showMsg('Enter how many liters you bought.', false); return; }
            if (!(cost > 0)) { showMsg('Enter how much you paid.', false); return; }
            btnEl.disabled = true; btnEl.textContent = 'Saving…';
            fetch(ETEL.api, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + Auth.getToken() },
                body: JSON.stringify({ action: 'courier_add_fuel_log', liters: liters, cost_etb: cost, odometer_km: odo }),
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (res && res.success) {
                    showMsg('✓ Fill-up saved', true);
                    loadCourierFuelLogs();
                    loadCourierKpiSummary('today');
                    setTimeout(function () { var bg = document.getElementById('courier-fuel-bg'); if (bg) bg.remove(); }, 700);
                } else {
                    btnEl.disabled = false; btnEl.textContent = 'Save Fill-up';
                    showMsg((res && res.message) || 'Could not save', false);
                }
            }).catch(function () {
                btnEl.disabled = false; btnEl.textContent = 'Save Fill-up';
                showMsg('Could not save', false);
            });
        }

        function loadCourierFuelLogs() {
            var el = document.getElementById('courier-fuel-log-list');
            if (!el) return;
            el.textContent = 'Loading…';
            apiGet('courier_list_fuel_logs', {}).then(function (res) {
                if (!res || !res.success) { el.textContent = (res && res.message) || 'Could not load'; return; }
                var logs = res.data.logs || [];
                if (!logs.length) { el.innerHTML = '<div style="text-align:center;padding:10px 0;color:rgba(168,168,184,.6)">No fill-ups logged yet.</div>'; return; }
                el.innerHTML = logs.map(function (l) {
                    var date = (l.logged_at || '').slice(0, 10);
                    var odoTxt = l.odometer_km != null ? ' · odo ' + parseFloat(l.odometer_km).toFixed(0) + ' km' : '';
                    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05)">' +
                        '<div><div style="color:#F5F5F7;font-weight:600">' + date + '</div>' +
                        '<div style="font-size:11px;color:#8a8a9a">' + parseFloat(l.liters).toFixed(1) + ' L · ETB ' + parseFloat(l.cost_etb).toFixed(0) + odoTxt + '</div></div>' +
                        '<button onclick="deleteCourierFuelLog(' + l.id + ')" style="background:none;border:none;color:#ff6b6b;font-size:15px;cursor:pointer;padding:4px 8px">🗑</button></div>';
                }).join('');
            }).catch(function () { el.textContent = 'Could not load'; });
        }

        function deleteCourierFuelLog(id) {
            if (!confirm('Remove this fuel log?')) return;
            fetch(ETEL.api, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + Auth.getToken() },
                body: JSON.stringify({ action: 'courier_delete_fuel_log', id: id }),
            }).then(function (r) { return r.json(); }).then(function () {
                loadCourierFuelLogs();
                loadCourierKpiSummary('today');
            }).catch(function () { showToast('Could not delete', 'err'); });
        }

        function doLogout() { if (confirm('Logout?')) Auth.logout(); }

        // ── TOAST ────────────────────────────────────────────────────────
        function showToast(msg, type) {
            const t = document.createElement('div');
            t.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);z-index:999;padding:12px 20px;background:' + (type==='err'?'#E63946':'#2ECC71') + ';color:#fff;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.3);white-space:nowrap;max-width:90vw';
            t.textContent = msg;
            document.body.appendChild(t);
            setTimeout(function(){ t.remove(); }, 3000);
        }

        // ── REFERRAL ──────────────────────────────────────────────────────
        // Reuses driver_get_referral_info as-is - it just reads/writes a
        // referral_code against the authenticated user's row in couriers,
        // which is the same table both couriers and drivers live in, so
        // no backend change was needed to offer this here too.
        function openCourierReferral() {
            let bg = document.getElementById('courier-referral-bg');
            if (!bg) {
                bg = document.createElement('div');
                bg.id = 'courier-referral-bg';
                bg.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:flex-end;justify-content:center;background:rgba(5,5,10,.7);backdrop-filter:blur(4px)';
                bg.addEventListener('click', function(e){ if (e.target === bg) bg.remove(); });
                bg.innerHTML = '<div id="courier-referral-box" style="width:min(460px,100vw);background:linear-gradient(180deg,#171006,#0a0703);border:1px solid rgba(212,175,55,.25);border-bottom:none;border-radius:20px 20px 0 0;padding:22px 22px max(22px,env(safe-area-inset-bottom,22px));color:#F5EDD8;font-family:inherit"><div style="font-size:17px;font-weight:800;color:#D4AF37;margin-bottom:14px">👥 Invite Friends</div><div id="courier-referral-body" style="text-align:center;padding:20px;color:rgba(245,237,216,.5)">Loading…</div><button onclick="document.getElementById(\'courier-referral-bg\').remove()" style="width:100%;padding:12px;margin-top:10px;border:1px solid rgba(255,255,255,.15);background:transparent;color:#F5EDD8;border-radius:12px;font-weight:700;cursor:pointer">Close</button></div>';
                document.body.appendChild(bg);
            }
            apiGet('driver_get_referral_info').then(function(res) {
                const data = res.data || res;
                const code = (data && data.referral_code) || '—';
                const count = (data && data.referred_count) || 0;
                const shareText = 'Join me delivering for ETEL Delivery in Hawassa! Use my code ' + code + ' when you sign up: https://ethelmarket.com/delivery/login?role=courier&mode=register';
                document.getElementById('courier-referral-body').innerHTML =
                    '<p style="text-align:left;font-size:13px;line-height:1.7">Share your code with other couriers. When someone signs up with it, it\'ll count toward your referrals below.</p>' +
                    '<div style="font-family:monospace;font-size:26px;font-weight:800;letter-spacing:.15em;color:#D4AF37;text-align:center;padding:18px;background:rgba(212,175,55,.08);border:1px dashed rgba(212,175,55,.4);border-radius:14px;margin:12px 0">' + code + '</div>' +
                    '<p style="text-align:center;color:rgba(245,237,216,.6);font-size:12px">' + count + ' courier' + (count === 1 ? '' : 's') + ' referred so far</p>' +
                    '<button onclick="etelShareCourierReferral(' + JSON.stringify(shareText) + ')" style="display:block;width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#FFD700,#D4AF37);color:#1a1206;font-weight:700;font-size:13px;cursor:pointer;margin-top:8px">Share Code</button>';
            }).catch(function() {
                const body = document.getElementById('courier-referral-body');
                if (body) body.innerHTML = '<div style="color:#E63946">Could not load your code — try again shortly.</div>';
            });
        }
        function etelShareCourierReferral(text) {
            if (navigator.share) { navigator.share({ text: text }).catch(function(){}); return; }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function(){ showToast('Copied to clipboard', 'ok'); });
            } else { showToast('Share not supported on this browser', 'err'); }
        }

        // ── GOOGLE MAPS ──────────────────────────────────────────────────
        let map, courierMarker;
        let mapsReady = false, mapWatchId = null;

        // If Maps fails to authorize/load, stop GPS→map updates and show a clear panel
        document.addEventListener('etel:maps-failed', function(ev) {
            mapsReady = false;
            if (mapWatchId !== null && navigator.geolocation) {
                navigator.geolocation.clearWatch(mapWatchId);
                mapWatchId = null;
            }
            var mapEl = document.getElementById('map');
            if (mapEl) {
                var reason = ev && ev.detail ? ev.detail.reason : '';
                var msg = reason === 'auth'
                    ? 'Map authorization failed.<br><small>Add <b>https://ethelmarket.com/*</b> to the API key\u2019s allowed referrers in Google Cloud Console.</small>'
                    : 'Map could not load.<br><small>Check your internet connection and try again.</small>';
                mapEl.innerHTML =
                    '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:12px;padding:24px;text-align:center;color:#A8A8B8">' +
                    '<div style="font-size:34px">\u26A0\uFE0F</div>' +
                    '<div style="color:#D4AF37;font-weight:700">Maps API Error</div>' +
                    '<div style="font-size:13px;line-height:1.5">' + msg + '</div>' +
                    '<button onclick="location.reload()" style="padding:10px 22px;background:#D4AF37;color:#1a1a2e;border:none;border-radius:8px;font-weight:700;cursor:pointer">Retry</button>' +
                    '</div>';
            }
        });

        // Hawassa, Ethiopia
        const DEFAULT_LOC = { lat: 7.0504, lng: 38.4955 };
        const MAP_STYLES = [
            { elementType: 'geometry', stylers: [{ color: '#1a1a2e' }] },
            { elementType: 'labels.text.stroke', stylers: [{ color: '#1a1a2e' }] },
            { elementType: 'labels.text.fill', stylers: [{ color: '#d4af37' }] },
            { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#2D2D45' }] },
            { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0d1117' }] }
        ];

        function centerOnMe() {
            if (!navigator.geolocation) { showToast('Geolocation not available', 'err'); return; }
            navigator.geolocation.getCurrentPosition(function(pos) {
                const loc = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                if (map) { map.setCenter(loc); map.setZoom(16); }
                if (courierMarker) courierMarker.position = loc;
            }, function() { showToast('Could not get location', 'err'); }, { enableHighAccuracy: true, timeout: 8000 });
        }

        function initMap() {
            try {
                if (typeof google === 'undefined' || !google.maps) return;

                // Hide loading placeholder
                var loadingEl = document.getElementById('map-loading');
                if (loadingEl) loadingEl.style.display = 'none';

                var mapEl = document.getElementById('map');
                /* Ensure map container has explicit pixel height before init
                   (critical on mobile where CSS height may not be resolved yet) */
                if (mapEl && (!mapEl.offsetHeight || mapEl.offsetHeight < 50)) {
                    var mc = mapEl.parentElement;
                    if (mc) mc.style.height = Math.max(280, Math.round(window.innerHeight * 0.5)) + 'px';
                }
                map = new google.maps.Map(mapEl, {
                    zoom: 15,
                    center: DEFAULT_LOC,
                    mapId: 'ETEL_COURIER_MAP',
                    mapTypeId: google.maps.MapTypeId.HYBRID,
                    mapTypeControl: true,
                    mapTypeControlOptions: {
                        style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
                        position: google.maps.ControlPosition.TOP_RIGHT,
                        mapTypeIds: [google.maps.MapTypeId.HYBRID, google.maps.MapTypeId.ROADMAP]
                    },
                    fullscreenControl: true,
                    zoomControl: true
                });
                /* Trigger resize after a short delay so the map
                   fills its container correctly on mobile */
                setTimeout(function() {
                    try { google.maps.event.trigger(map, 'resize'); map.setCenter(DEFAULT_LOC); } catch(e) {}
                }, 300);
                setTimeout(function() {
                    try { google.maps.event.trigger(map, 'resize'); map.setCenter(DEFAULT_LOC); } catch(e) {}
                }, 1000);
                const VEHICLE_ICONS = {
                    motorcycle: '🛵', bicycle: '🚲', car: '🚗', on_foot: '🚶',
                };
                function buildCourierMarkerEl(vehicleType) {
                    const el = document.createElement('div');
                    const emoji = VEHICLE_ICONS[vehicleType] || null;
                    if (emoji) {
                        el.style.cssText = 'width:34px;height:34px;border-radius:50%;background:#1a1a2e;border:3px solid #2ECC71;box-shadow:0 0 0 3px rgba(46,204,113,.35);display:flex;align-items:center;justify-content:center;font-size:18px;line-height:1';
                        el.textContent = emoji;
                    } else {
                        // Vehicle type not yet known (profile still loading, or
                        // not set) - the plain dot this always used to be,
                        // never a fabricated vehicle guess.
                        el.style.cssText = 'width:20px;height:20px;border-radius:50%;background:#2ECC71;border:3px solid #fff;box-shadow:0 0 0 3px rgba(46,204,113,.4)';
                    }
                    return el;
                }
                const markerEl = buildCourierMarkerEl(null);
                courierMarker = new google.maps.marker.AdvancedMarkerElement({
                    position: DEFAULT_LOC,
                    map: map,
                    title: user ? user.name : 'Your Location',
                    content: markerEl
                });
                // Swap in the real vehicle icon once we know this
                // courier's actual registered type - never guessed,
                // never defaulted to one specific vehicle.
                apiGet('courier_get_profile', {}).then(function (res) {
                    if (res && res.success && res.data && res.data.vehicle_type && courierMarker) {
                        courierMarker.content = buildCourierMarkerEl(res.data.vehicle_type);
                    }
                }).catch(function () {});

                // Re-center button overlay
                const btn = document.createElement('button');
                btn.textContent = '📍 Me';
                btn.onclick = centerOnMe;
                btn.style.cssText = 'position:absolute;top:10px;left:10px;z-index:10;padding:8px 14px;background:#2ECC71;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.4)';
                document.getElementById('map').appendChild(btn);

                if (navigator.geolocation) {
                    // Jump to real location immediately
                    navigator.geolocation.getCurrentPosition(function(pos) {
                        if (!mapsReady) return;
                        const loc = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                        try {
                            map.setCenter(loc);
                            map.setZoom(16);
                            if (courierMarker) courierMarker.position = loc;
                        } catch(e) { console.warn('Map center failed:', e); }
                    }, null, { enableHighAccuracy: true, timeout: 10000 });

                    // Live tracking as courier moves
                    mapWatchId = navigator.geolocation.watchPosition(function(pos) {
                        // Guard: if Maps auth failed or map isn't ready, the
                        // AdvancedMarkerElement setter throws internally
                        // ("Cannot read properties of undefined (reading 'get')")
                        if (!mapsReady || window.ETEL_MAPS_FAILED) return;
                        const loc = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                        try {
                            if (courierMarker) courierMarker.position = loc;
                            if (map) {
                                const cur = map.getCenter();
                                if (cur) {
                                    const dist = Math.abs(cur.lat() - loc.lat) + Math.abs(cur.lng() - loc.lng);
                                    if (dist > 0.003) map.panTo(loc);
                                }
                            }
                        } catch(e) { console.warn('Live tracking update failed:', e); }
                    }, null, { enableHighAccuracy: true, maximumAge: 15000, timeout: 20000 });
                }
                mapsReady = true;
            } catch(e) { console.warn('Maps init failed:', e); }
            initMapNavAutocomplete();
        }
        // Expose initMap globally so Google Maps async callback can call it
        window.initMap = initMap;

        // ── IN-MAP ROUTING (DirectionsService) ──────────────────────────
        var dirService = null, dirRenderer = null, mapNavAC = null;

        function ensureDirections() {
            if (!map || typeof google === 'undefined') return false;
            if (!dirService) dirService = new google.maps.DirectionsService();
            if (!dirRenderer) {
                dirRenderer = new google.maps.DirectionsRenderer({
                    map: map,
                    suppressMarkers: false,
                    polylineOptions: { strokeColor: '#1A73E8', strokeWeight: 6, strokeOpacity: 0.9 }
                });
            }
            return true;
        }

        function drawRouteOnMap(destLat, destLng, label) {
            if (!ensureDirections()) { showToast('Map not ready — try again', 'err'); return; }
            var locationOk = courierLocationIsPlausible();
            var origin = locationOk
                ? new google.maps.LatLng(courierLat, courierLng)
                : new google.maps.LatLng(HAWASSA_CENTER.lat, HAWASSA_CENTER.lng);

            if (!locationOk && courierLat && courierLng && !_locWarnedThisSession) {
                _locWarnedThisSession = true;
                showToast('Your device location looks inaccurate (too far from Hawassa) — showing route from central Hawassa instead. Enable precise/GPS location for accurate routing.', 'err');
            }

            document.getElementById('map-route-time').textContent  = '…';
            document.getElementById('map-route-dist').textContent  = 'Calculating…';
            document.getElementById('map-route-label').textContent = label || 'Destination';
            document.getElementById('map-nav-bar').style.display   = 'block';
            document.getElementById('map-nav-input').value         = label || '';
            document.getElementById('map-route-panel').style.display = 'block';

            dirService.route({
                origin: origin,
                destination: new google.maps.LatLng(destLat, destLng),
                travelMode: google.maps.TravelMode.DRIVING
            }, function(result, status) {
                if (status === 'OK') {
                    dirRenderer.setDirections(result);
                    var leg = result.routes[0].legs[0];
                    document.getElementById('map-route-time').textContent = leg.duration.text;
                    document.getElementById('map-route-dist').textContent = leg.distance.text;
                } else {
                    document.getElementById('map-route-time').textContent = '—';
                    document.getElementById('map-route-dist').textContent = 'Route unavailable';
                    // Pan map to destination even if route failed
                    map.panTo({ lat: destLat, lng: destLng });
                    map.setZoom(15);
                }
            });
        }

        function clearRoute() {
            if (dirRenderer) dirRenderer.setDirections({ routes: [] });
            document.getElementById('map-nav-bar').style.display   = 'none';
            document.getElementById('map-route-panel').style.display = 'none';
        }

        function navigateToShop(zone, exactLat, exactLng) {
            if (exactLat && exactLng) {
                drawRouteOnMap(exactLat, exactLng, 'Shop · ' + zone);
                return;
            }
            var coords = ZONE_COORDS[zone];
            if (coords) {
                drawRouteOnMap(coords.lat, coords.lng, 'Shop · ' + zone);
            } else if (typeof google !== 'undefined' && google.maps) {
                var gc = new google.maps.Geocoder();
                gc.geocode({ address: zone + ', Hawassa, Ethiopia', region: 'ET' }, function(res, status) {
                    if (status === 'OK' && res[0]) {
                        var loc = res[0].geometry.location;
                        drawRouteOnMap(loc.lat(), loc.lng(), 'Shop · ' + zone);
                    } else {
                        showToast('Could not find location for ' + zone, 'err');
                    }
                });
            } else {
                showToast('Map not ready — try again in a moment', 'err');
            }
        }

        function navigateToCustomer(lat, lng, addr) {
            if (lat && lng) {
                drawRouteOnMap(parseFloat(lat), parseFloat(lng), addr || 'Customer location');
            } else if (addr && typeof google !== 'undefined' && google.maps) {
                var gc = new google.maps.Geocoder();
                gc.geocode({ address: addr + ', Hawassa, Ethiopia', region: 'ET' }, function(res, status) {
                    if (status === 'OK' && res[0]) {
                        var loc = res[0].geometry.location;
                        drawRouteOnMap(loc.lat(), loc.lng(), addr || 'Customer');
                    } else {
                        showToast('Could not find customer location', 'err');
                    }
                });
            } else {
                showToast('Customer location not available', 'err');
            }
        }

        function initMapNavAutocomplete() {
            var input = document.getElementById('map-nav-input');
            if (!input || mapNavAC || typeof google === 'undefined' || !google.maps || !google.maps.places) return;
            try {
                mapNavAC = new google.maps.places.Autocomplete(input, {
                    componentRestrictions: { country: 'et' },
                    fields: ['geometry', 'name', 'formatted_address']
                });
                mapNavAC.addListener('place_changed', function() {
                    var place = mapNavAC.getPlace();
                    if (!place || !place.geometry) return;
                    drawRouteOnMap(
                        place.geometry.location.lat(),
                        place.geometry.location.lng(),
                        place.formatted_address || place.name || input.value
                    );
                });
            } catch(e) { console.warn('Places autocomplete unavailable:', e); }
        }

        // ── PUSH NOTIFICATIONS ──────────────────────────────────────────
        // Real Web Push - wakes a courier up for a new job even if the app
        // is backgrounded, unlike the in-app poll/ringtone which only work
        // while the app is open and in the foreground. Requested once
        // (tied to a real explanation via a toast, not silently on load).
        function urlBase64ToUint8Array(base64String) {
            var padding = '='.repeat((4 - base64String.length % 4) % 4);
            var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            var rawData = window.atob(base64);
            var outputArray = new Uint8Array(rawData.length);
            for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
            return outputArray;
        }
        function subscribeCourierToPush() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
            navigator.serviceWorker.ready.then(function (reg) {
                return reg.pushManager.getSubscription().then(function (existing) {
                    if (existing) return existing;
                    return fetch(ETEL.api + '?action=get_vapid_public_key')
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (!res || !res.success || !res.data || !res.data.publicKey) throw new Error('VAPID key unavailable');
                            return reg.pushManager.subscribe({
                                userVisibleOnly: true,
                                applicationServerKey: urlBase64ToUint8Array(res.data.publicKey)
                            });
                        });
                });
            }).then(function (subscription) {
                if (!subscription) return;
                return fetch(ETEL.api, {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + Auth.getToken(), 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save_push_subscription', subscription: subscription.toJSON() })
                });
            }).catch(function (e) { console.error('[push] subscription setup failed', e); });
        }
        function maybeRequestCourierNotifications() {
            if (!('Notification' in window)) return;
            if (Notification.permission === 'granted') { subscribeCourierToPush(); return; }
            if (Notification.permission !== 'default') return;
            try { if (localStorage.getItem('etel_courier_notif_asked')) return; } catch (e) {}
            showToast('Enable notifications to hear about new jobs even when the app is in the background', 'ok');
            setTimeout(function () {
                Notification.requestPermission().then(function (p) {
                    try { localStorage.setItem('etel_courier_notif_asked', '1'); } catch (e) {}
                    if (p === 'granted') subscribeCourierToPush();
                });
            }, 1500);
        }

        // ── INIT ─────────────────────────────────────────────────────────
        loadAvailableJobs();
        maybeRequestCourierNotifications();
        initCourierSlideOnline();
        loadCourierOnlineStatus();

        // ── ONLINE / OFFLINE ─────────────────────────────────────────────
        // This slider existed in the HTML (#c-slide-online-track etc.) with
        // zero JavaScript behind it - a fully dead control, same category
        // of bug as an earlier "confirmGoOffline is not defined" gap found
        // in the driver app. Wired up here using the same proven drag
        // mechanics as the driver app's slider, calling the newly-added
        // courier_go_online/courier_go_offline backend actions (which also
        // didn't exist before this pass).
        let COURIER_IS_ONLINE = false;

        function loadCourierOnlineStatus() {
            apiGet('courier_get_profile', {}).then(function (res) {
                if (res && res.success && res.data && res.data.status === 'online') {
                    COURIER_IS_ONLINE = true;
                    setCourierOnlineUI(true);
                }
            }).catch(function () {});
        }

        function setCourierOnlineUI(isOnline) {
            var wrap = document.getElementById('c-slide-online-wrap');
            var pill = document.getElementById('c-online-pill');
            if (wrap) wrap.style.display = isOnline ? 'none' : '';
            if (pill) pill.style.display = isOnline ? '' : 'none';
        }

        function courierGoOnline() {
            apiGet('courier_go_online', {}).then(function (res) {
                if (res && res.success) {
                    COURIER_IS_ONLINE = true;
                    setCourierOnlineUI(true);
                    showToast('You\'re online', 'ok');
                } else {
                    showToast((res && res.message) || 'Could not go online', 'err');
                }
            }).catch(function () { showToast('Could not go online', 'err'); });
        }

        function courierGoOffline() {
            if (!confirm('Go offline? You will stop appearing available for new deliveries.')) return;
            apiGet('courier_go_offline', {}).then(function (res) {
                if (res && res.success) {
                    COURIER_IS_ONLINE = false;
                    setCourierOnlineUI(false);
                    showToast('You\'re offline', 'ok');
                } else {
                    showToast((res && res.message) || 'Could not go offline', 'err');
                }
            }).catch(function () { showToast('Could not go offline', 'err'); });
        }

        function initCourierSlideOnline() {
            var track = document.getElementById('c-slide-online-track');
            var thumb = document.getElementById('c-slide-online-thumb');
            var fill = document.getElementById('c-slide-online-fill');
            if (!track || !thumb) return;
            var dragging = false, startX = 0, progress = 0;

            function getClientX(e) { return e.touches ? e.touches[0].clientX : e.clientX; }
            function onStart(e) {
                if (COURIER_IS_ONLINE) return;
                dragging = true; startX = getClientX(e); progress = 0;
                e.preventDefault();
            }
            function onMove(e) {
                if (!dragging) return;
                var maxX = track.offsetWidth - thumb.offsetWidth - 12;
                if (maxX <= 0) return;
                var dx = startX - getClientX(e);
                dx = Math.max(0, Math.min(dx, maxX));
                progress = dx / maxX;
                thumb.style.transform = 'translateX(' + (-dx) + 'px)';
                if (fill) fill.style.transform = 'scaleX(' + progress + ')';
                e.preventDefault();
            }
            function onEnd() {
                if (!dragging) return;
                dragging = false;
                if (progress >= 0.75) {
                    var maxX = track.offsetWidth - thumb.offsetWidth - 12;
                    thumb.style.transform = 'translateX(' + (-maxX) + 'px)';
                    if (fill) fill.style.transform = 'scaleX(1)';
                    setTimeout(function () {
                        thumb.style.transform = '';
                        if (fill) fill.style.transform = '';
                        courierGoOnline();
                    }, 180);
                } else {
                    thumb.style.transition = 'transform .25s';
                    thumb.style.transform = 'translateX(0)';
                    if (fill) fill.style.transform = 'scaleX(0)';
                    progress = 0;
                    setTimeout(function () { thumb.style.transition = ''; }, 260);
                }
            }
            track.addEventListener('mousedown', onStart);
            track.addEventListener('touchstart', onStart, { passive: false });
            document.addEventListener('mousemove', onMove);
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('mouseup', onEnd);
            document.addEventListener('touchend', onEnd);
            // Tapping the "online" pill lets you go offline again.
            var pill = document.getElementById('c-online-pill');
            if (pill) pill.addEventListener('click', courierGoOffline);
        }

        // ── KPI SUMMARY (net ETB/hour) ───────────────────────────────────
        // Same headline metric as the driver app's KPI dashboard - take-
        // home earnings relative to time actually spent online, using the
        // new courier_shifts table for real online-time tracking.
        function loadCourierKpiSummary(period) {
            var el = document.getElementById('cp-kpi-body');
            if (!el) return;
            document.querySelectorAll('.cp-kpi-period-btn').forEach(function (b) {
                var active = b.getAttribute('data-period') === period;
                b.style.background = active ? 'rgba(212,175,55,.1)' : 'transparent';
                b.style.borderColor = active ? 'rgba(212,175,55,.3)' : 'rgba(255,255,255,.1)';
                b.style.color = active ? '#F2CC6B' : '#A8A8B8';
            });
            el.textContent = 'Loading…';
            apiGet('courier_get_kpi_summary', { period: period || 'today' }).then(function (res) {
                if (!res || !res.success) { el.textContent = (res && res.message) || 'Could not load'; return; }
                var d = res.data;
                var netTxt = d.net_etb_per_hour != null ? 'ETB ' + Math.round(d.net_etb_per_hour) + '/hr' : '—';
                var fuelRow = d.net_after_fuel != null
                    ? '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)"><span style="color:#A8A8B8">Net after fuel' + (d.using_real_fuel_data ? '' : '') + '</span><b style="color:#F2CC6B">ETB ' + Math.round(d.net_after_fuel) + '</b></div>'
                    : '';
                el.innerHTML =
                    '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)"><span style="color:#A8A8B8">Net ETB/hour</span><b style="color:#F2CC6B">' + netTxt + '</b></div>' +
                    '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)"><span style="color:#A8A8B8">Deliveries</span><b>' + d.completed_deliveries + '</b></div>' +
                    '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)"><span style="color:#A8A8B8">Earnings</span><b style="color:#2ECC71">ETB ' + Math.round(d.earnings) + '</b></div>' +
                    fuelRow +
                    '<div style="display:flex;justify-content:space-between;padding:8px 0"><span style="color:#A8A8B8">Online time</span><b>' + d.online_hours.toFixed(1) + ' hrs</b></div>' +
                    (d.note ? '<div style="font-size:11px;color:#8a8a9a;margin-top:6px">' + d.note + '</div>' : '');
            }).catch(function () { el.textContent = 'Could not load'; });
        }

        // Auto-poll for new jobs every 20 seconds while on jobs tab
        setInterval(function() {
            var activeTab = document.querySelector('.tab-content.active');
            if (activeTab && activeTab.id === 'jobs') {
                // Silent refresh — only update if tab is visible
                apiGet('courier_get_available_orders', {}).then(function(res) {
                    if (res.success && res.data.orders) {
                        // ID-based new-job detection, not length comparison -
                        // a same-size list can still contain a job the
                        // courier hasn't seen yet (one taken by someone else
                        // while a different new one appeared at the same time).
                        if (!window._seenJobIds) window._seenJobIds = {};
                        var currentIds = {};
                        res.data.orders.forEach(function (o) { currentIds[o.id] = true; });
                        Object.keys(window._seenJobIds).forEach(function (id) {
                            if (!currentIds[id]) { stopRingingForJob(id); delete window._seenJobIds[id]; }
                        });
                        var hasNew = false;
                        res.data.orders.forEach(function (o) {
                            if (!window._seenJobIds[o.id]) {
                                window._seenJobIds[o.id] = true;
                                startRingingForJob(o.id);
                                hasNew = true;
                            }
                        });
                        availableJobs = res.data.orders;
                        if (hasNew) showToast('📦 New job available!', 'ok');
                        renderJobs(availableJobs);
                    }
                }).catch(function(){});
            }
        }, 20000);

        // Poll for active order status changes every 15 seconds
        setInterval(function() {
            var activeTab = document.querySelector('.tab-content.active');
            if (activeTab && activeTab.id === 'active') {
                loadActiveOrders();
            }
        }, 15000);

        // Poll notifications every 60 seconds
        setInterval(function() {
            apiGet('get_my_notifications', {}).then(function(res) {
                if (res.success && res.data.unread > 0) {
                    // Update badge on ACTIVE tab
                    var activeBtn = document.querySelector('.tab-btn[onclick*="active"]');
                    if (activeBtn && !activeBtn.querySelector('.notif-badge')) {
                        var badge = document.createElement('span');
                        badge.className = 'notif-badge';
                        badge.style.cssText = 'display:inline-block;background:#E63946;color:#fff;border-radius:10px;font-size:9px;padding:1px 5px;margin-left:4px;font-weight:700';
                        badge.textContent = res.data.unread;
                        activeBtn.appendChild(badge);
                    }
                }
            }).catch(function(){});
        }, 60000);