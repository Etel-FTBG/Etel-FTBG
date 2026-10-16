/* Customer: send a package (like a courier service). Pickup and drop-off anywhere, package details, a vehicle you choose,
 * a price for each, then pay and book. Server side: api/lib/package_orders.php (customer_package_options / _quote / _create).
 * Loaded after customer.js and uses its helpers (api, apiGet, showToast, escapeHtml, loadSavedPlaces, getNearestHawassaZone, openTrackOrder...).
 * No inline handlers: buttons use data-act (see etel-actions.js); typing is handled by two listeners on the view. */
(function () {
    'use strict';

    ETEL_I18N.register('customer-package', {
        en: {
            pkg_tile_title: 'Send a package', pkg_tile_sub: 'Anywhere in Hawassa · you pick the vehicle',
            pkg_title: 'Send a package', pkg_step1: 'Where', pkg_step2: 'Package', pkg_step3: 'Vehicle', pkg_step4: 'Pay',
            pkg_pickup: 'Pick up from', pkg_drop: 'Deliver to', pkg_addr_ph: 'Street, building, landmark',
            pkg_gps: 'Use my location', pkg_area: 'Or choose the area', pkg_area_pick: '— Choose area —', pkg_saved: 'Saved places',
            pkg_sender_name: 'Sender name', pkg_sender_phone: 'Sender phone', pkg_rcpt_name: 'Recipient name', pkg_rcpt_phone: 'Recipient phone',
            pkg_size: 'How big is it?', pkg_size_document: 'Document or envelope', pkg_size_small: 'Small (shoebox)', pkg_size_medium: 'Medium (backpack)', pkg_size_large: 'Large (box, suitcase)',
            pkg_weight: 'Weight (kg)', pkg_desc: 'What is inside?', pkg_desc_ph: 'For example: books, clothes', pkg_value: 'Value of the contents, ETB (optional)',
            pkg_notes: 'Note for the courier (optional)', pkg_ack: 'This package has no weapons, drugs, alcohol, live animals, cash or dangerous goods.',
            pkg_choose_vehicle: 'Choose a vehicle', pkg_trip: 'About {km} km · around {min} min', pkg_up_to: 'up to {kg} kg',
            pkg_pay_title: 'How will you pay?', pkg_pay_cash: 'Cash', pkg_pay_cash_sub: 'Pay the courier when they pick it up',
            pkg_pay_wallet: 'Etel Pay wallet', pkg_pay_wallet_sub: 'Pay from your wallet', pkg_pay_chapa: 'Pay online', pkg_pay_chapa_sub: 'Telebirr, CBE, bank or card (Chapa)',
            pkg_summary: 'Summary', pkg_total: 'Total', pkg_next: 'Continue', pkg_back: 'Back', pkg_book: 'Book package', pkg_booking: 'Booking…', pkg_loading: 'Getting prices…',
            pkg_done_title: 'Package booked!', pkg_done_sub: 'A courier with the vehicle you chose will pick it up.', pkg_track: 'Track package', pkg_done_btn: 'Done',
            pkg_retry: 'Try again', pkg_load_fail: 'Could not load. Check your connection.',
            pkg_err_addr_pickup: 'Enter the pickup address.', pkg_err_addr_drop: 'Enter the drop-off address.',
            pkg_err_area_pickup: 'Pick the pickup place on the map or choose its area.', pkg_err_area_drop: 'Pick the drop-off place on the map or choose its area.',
            pkg_err_sname: 'Enter the sender name.', pkg_err_sphone: 'Enter a valid sender mobile number.', pkg_err_rname: 'Enter the recipient name.', pkg_err_rphone: 'Enter a valid recipient mobile number.',
            pkg_err_size: 'Choose the package size.', pkg_err_weight: 'Enter the weight in kg.', pkg_err_desc: 'Say what is inside (a few words).', pkg_err_ack: 'Please confirm the package has no prohibited items.',
            pkg_err_vehicle: 'Choose a vehicle.', pkg_gps_fail: 'Could not get your location.',
            pkg_trk_title: 'Package details', pkg_trk_from: 'From', pkg_trk_to: 'To', pkg_trk_vehicle: 'Vehicle', pkg_trk_what: 'Package',
            pkg_pin_pick: 'Pickup PIN', pkg_pin_pick_hint: 'Show this to the courier when they come to collect the package.', pkg_pin_drop: 'Delivery PIN', pkg_pin_drop_hint: 'Give this to the recipient. They tell it to the courier, and the package is handed over only with it.',
            pkg_j_title: 'Delivery journey', pkg_j_finding: 'Finding a courier', pkg_j_enroute: 'Courier is heading to pick it up', pkg_j_carry: 'On the way to the recipient',
            pkg_j_done: 'Delivered', pkg_j_cancel: 'Cancelled', pkg_j_booked: 'Package booked', pkg_j_accepted: 'Courier accepted', pkg_j_picked: 'Picked up', pkg_j_delivered: 'Delivered',
            pkg_j_since: 'since you booked', pkg_j_total: 'Total time', pkg_j_found: 'Courier found in', pkg_j_reached: 'Reached the pickup in', pkg_j_trip: 'The trip took',
            pkg_j_now: 'running now', pkg_j_min: 'min', pkg_j_hr: 'h', pkg_j_sec: 's',
            pkg_order_label: 'Package', pkg_theme: 'Light / Dark mode'
        },
        am: {
            pkg_tile_title: 'ጥቅል ላክ', pkg_tile_sub: 'በሃዋሳ ውስጥ በማንኛውም ቦታ · ተሽከርካሪውን እርስዎ ይመርጣሉ',
            pkg_title: 'ጥቅል ላክ', pkg_step1: 'የት', pkg_step2: 'ጥቅል', pkg_step3: 'ተሽከርካሪ', pkg_step4: 'ክፍያ',
            pkg_pickup: 'የሚወሰድበት ቦታ', pkg_drop: 'የሚደርስበት ቦታ', pkg_addr_ph: 'መንገድ፣ ሕንጻ፣ ምልክት',
            pkg_gps: 'የእኔን አካባቢ ተጠቀም', pkg_area: 'ወይም አካባቢውን ይምረጡ', pkg_area_pick: '— አካባቢ ይምረጡ —', pkg_saved: 'የተቀመጡ አድራሻዎች',
            pkg_sender_name: 'የላኪ ስም', pkg_sender_phone: 'የላኪ ስልክ', pkg_rcpt_name: 'የተቀባይ ስም', pkg_rcpt_phone: 'የተቀባይ ስልክ',
            pkg_size: 'መጠኑ ምን ያህል ነው?', pkg_size_document: 'ሰነድ ወይም ፖስታ', pkg_size_small: 'ትንሽ (የጫማ ሳጥን)', pkg_size_medium: 'መካከለኛ (ቦርሳ)', pkg_size_large: 'ትልቅ (ሳጥን፣ ሻንጣ)',
            pkg_weight: 'ክብደት (ኪ.ግ)', pkg_desc: 'ውስጡ ምንድን ነው?', pkg_desc_ph: 'ለምሳሌ፦ መጽሐፍት፣ ልብስ', pkg_value: 'የይዘቱ ዋጋ በብር (አማራጭ)',
            pkg_notes: 'ለመልእክተኛው ማስታወሻ (አማራጭ)', pkg_ack: 'በዚህ ጥቅል ውስጥ የጦር መሣሪያ፣ አደንዛዥ ዕፅ፣ አልኮል፣ ሕያው እንስሳ፣ ጥሬ ገንዘብ ወይም አደገኛ ዕቃ የለም።',
            pkg_choose_vehicle: 'ተሽከርካሪ ይምረጡ', pkg_trip: 'ወደ {km} ኪ.ሜ · ወደ {min} ደቂቃ', pkg_up_to: 'እስከ {kg} ኪ.ግ',
            pkg_pay_title: 'እንዴት ይከፍላሉ?', pkg_pay_cash: 'ጥሬ ገንዘብ', pkg_pay_cash_sub: 'መልእክተኛው ሲወስድ ይክፈሉ',
            pkg_pay_wallet: 'የኢቴል ፔይ ዋሌት', pkg_pay_wallet_sub: 'ከዋሌትዎ ይክፈሉ', pkg_pay_chapa: 'በኦንላይን ይክፈሉ', pkg_pay_chapa_sub: 'ቴሌብር፣ ንግድ ባንክ፣ ባንክ ወይም ካርድ (Chapa)',
            pkg_summary: 'ማጠቃለያ', pkg_total: 'ጠቅላላ', pkg_next: 'ቀጥል', pkg_back: 'ተመለስ', pkg_book: 'ጥቅሉን ያስይዙ', pkg_booking: 'በማስያዝ ላይ…', pkg_loading: 'ዋጋ በማምጣት ላይ…',
            pkg_done_title: 'ጥቅሉ ተይዟል!', pkg_done_sub: 'የመረጡት ተሽከርካሪ ያለው መልእክተኛ ይወስደዋል።', pkg_track: 'ጥቅሉን ተከታተል', pkg_done_btn: 'ተጠናቀቀ',
            pkg_retry: 'እንደገና ሞክር', pkg_load_fail: 'መጫን አልተቻለም። የበይነመረብ ግንኙነትዎን ይፈትሹ።',
            pkg_err_addr_pickup: 'የሚወሰድበትን አድራሻ ያስገቡ።', pkg_err_addr_drop: 'የሚደርስበትን አድራሻ ያስገቡ።',
            pkg_err_area_pickup: 'የሚወሰድበትን ቦታ በካርታ ይምረጡ ወይም አካባቢውን ይምረጡ።', pkg_err_area_drop: 'የሚደርስበትን ቦታ በካርታ ይምረጡ ወይም አካባቢውን ይምረጡ።',
            pkg_err_sname: 'የላኪውን ስም ያስገቡ።', pkg_err_sphone: 'ትክክለኛ የላኪ ሞባይል ቁጥር ያስገቡ።', pkg_err_rname: 'የተቀባዩን ስም ያስገቡ።', pkg_err_rphone: 'ትክክለኛ የተቀባይ ሞባይል ቁጥር ያስገቡ።',
            pkg_err_size: 'የጥቅሉን መጠን ይምረጡ።', pkg_err_weight: 'ክብደቱን በኪ.ግ ያስገቡ።', pkg_err_desc: 'ውስጡ ምን እንደሆነ በጥቂት ቃላት ይግለጹ።', pkg_err_ack: 'እባክዎ ጥቅሉ የተከለከሉ ዕቃዎች እንደሌሉት ያረጋግጡ።',
            pkg_err_vehicle: 'ተሽከርካሪ ይምረጡ።', pkg_gps_fail: 'አካባቢዎን ማግኘት አልተቻለም።',
            pkg_trk_title: 'የጥቅሉ ዝርዝር', pkg_trk_from: 'ከ', pkg_trk_to: 'ወደ', pkg_trk_vehicle: 'ተሽከርካሪ', pkg_trk_what: 'ጥቅል',
            pkg_pin_pick: 'የመረከቢያ ፒን', pkg_pin_pick_hint: 'ሹፌሩ ጥቅሉን ለመውሰድ ሲመጣ ይህን ያሳዩት።', pkg_pin_drop: 'የማስረከቢያ ፒን', pkg_pin_drop_hint: 'ይህንን ለተቀባዩ ይስጡ። ተቀባዩ ለሹፌሩ ይነግራል፤ ጥቅሉ የሚሰጠው በዚህ ፒን ብቻ ነው።',
            pkg_j_title: 'የጉዞ ሂደት', pkg_j_finding: 'ሹፌር በመፈለግ ላይ', pkg_j_enroute: 'ሹፌሩ ጥቅሉን ለመውሰድ እየመጣ ነው', pkg_j_carry: 'ወደ ተቀባዩ በመሄድ ላይ',
            pkg_j_done: 'ተደርሷል', pkg_j_cancel: 'ተሰርዟል', pkg_j_booked: 'ጥቅሉ ተመዝግቧል', pkg_j_accepted: 'ሹፌሩ ተቀብሏል', pkg_j_picked: 'ተወስዷል', pkg_j_delivered: 'ተደርሷል',
            pkg_j_since: 'ከተመዘገበ ጀምሮ', pkg_j_total: 'ጠቅላላ ጊዜ', pkg_j_found: 'ሹፌር የተገኘው በ', pkg_j_reached: 'መውሰጃው የደረሰው በ', pkg_j_trip: 'ጉዞው የፈጀው',
            pkg_j_now: 'አሁን እየሄደ ነው', pkg_j_min: 'ደቂቃ', pkg_j_hr: 'ሰዓት', pkg_j_sec: 'ሰከንድ',
            pkg_order_label: 'ጥቅል', pkg_theme: 'ብርሃን / ጨለማ'
        }
    });

    var ZONES = ['Mehal Ketema', 'Tabor', 'Addis Ketema', 'Haik Dar', 'Tulu Dimtu', 'Misrak', 'Referral', 'Hawela Teklehaimanot', 'Dore Bafana', 'Stadium', 'Liqe Mender', 'University'];
    var SIZE_ICON = { document: '📄', small: '📦', medium: '🎒', large: '🧳' };
    var S = null;          // everything the person has entered so far
    var placesTimer = null;

    function T(k) { return ETEL_I18N.t(k); }
    function esc(s) { return escapeHtml(String(s == null ? '' : s)); }
    function view() { return document.getElementById('view-package'); }
    function side() { return { address: '', lat: null, lng: null, zone: '', placeText: '' }; }
    function fresh() {
        var u = {}; try { u = Auth.getUser() || {}; } catch (e) {}
        return { step: 1, pickup: side(), drop: side(), sender_name: u.name || '', sender_phone: u.phone || '', recipient_name: '', recipient_phone: '',
            size: '', weight: '', desc: '', value: '', notes: '', ack: false, vehicle: '', quote: null, payment: 'cash', busy: false, err: '', opts: null, done: null, loadFail: false };
    }
    function a(name, args, o) { return EtelActions.attrs(name, args, o); }
    function validPhone(p) { var d = String(p || '').replace(/[^0-9]/g, ''); return /^(251)?0?[79]\d{8}$/.test(d); }
    function point(s) { return { address: s.address.trim(), lat: s.lat, lng: s.lng, zone: s.zone }; }

    // ---------- open / close ----------
    window.pkgOpen = function () {
        S = fresh();
        var v = view(); if (!v) return;
        v.style.display = 'flex';
        v.scrollTop = 0;
        try { etelEnsureMaps(); } catch (e) {}
        try { loadSavedPlaces(); } catch (e) {}
        render();
        loadOptions();
        if (placesTimer) clearInterval(placesTimer);
        var tries = 0;
        placesTimer = setInterval(function () {
            tries++;
            if (!S || tries > 40) { clearInterval(placesTimer); placesTimer = null; return; }
            bindPlaces();
            if (S.step === 1 && !S.pickup.address && !S.drop.address) renderIfSavedArrived();
        }, 500);
    };
    window.pkgClose = function () {
        var v = view(); if (v) v.style.display = 'none';
        if (placesTimer) { clearInterval(placesTimer); placesTimer = null; }
        S = null;
    };
    var _savedShown = 0;
    function renderIfSavedArrived() {
        try { if (_savedPlacesState === 'done' && _savedPlaces.length !== _savedShown) { _savedShown = _savedPlaces.length; render(); } } catch (e) {}
    }
    function loadOptions() {
        S.loadFail = false;
        apiGet('customer_package_options', {}).then(function (res) {
            if (!S) return;
            if (res && res.success) { S.opts = res.data; } else { S.loadFail = true; }
            render();
        }).catch(function () { if (S) { S.loadFail = true; render(); } });
    }
    window.pkgRetry = function () { if (S) { loadOptions(); render(); } };

    // ---------- typing and selecting (two listeners on the view, no inline handlers) ----------
    function onField(ev) {
        var el = ev.target;
        if (!S || !el || !el.getAttribute) return;
        var f = el.getAttribute('data-f');
        if (!f) return;
        var val = el.type === 'checkbox' ? el.checked : el.value;
        var parts = f.split('.');
        if (parts.length === 2) {
            var sd = S[parts[0]];
            if (parts[1] === 'zone') {
                sd.zone = val;
                if (val) { sd.lat = null; sd.lng = null; sd.placeText = ''; }   // an area is a rougher choice than a map point
            } else if (parts[1] === 'address') {
                sd.address = val;
                if (sd.placeText && val !== sd.placeText) { sd.lat = null; sd.lng = null; sd.placeText = ''; }   // edited after picking a place: the pin no longer matches
            } else { sd[parts[1]] = val; }
        } else { S[f] = val; }
        S.err = '';
        var e2 = document.getElementById('pkg-err'); if (e2) e2.textContent = '';
        if (f === 'weight') { S.vehicle = ''; S.quote = null; }
    }

    // ---------- map search, GPS, saved places ----------
    function bindPlaces() {
        if (!S || S.step !== 1) return;
        if (!(window.google && google.maps && google.maps.places)) return;
        ['pickup', 'drop'].forEach(function (sd) {
            var input = document.getElementById('pkg-' + sd + '-addr');
            if (!input || input._pkgBound) return;
            input._pkgBound = true;
            var opts = { componentRestrictions: { country: 'et' }, fields: ['geometry', 'formatted_address', 'name'] };
            try { var b = _latLngBoundsAround(7.0504, 38.4955, 25); if (b) { opts.bounds = b; opts.strictBounds = true; } } catch (e) {}
            var ac = new google.maps.places.Autocomplete(input, opts);
            ac.addListener('place_changed', function () {
                var p = ac.getPlace();
                if (!S || !p || !p.geometry) return;
                var s = S[sd];
                s.lat = p.geometry.location.lat(); s.lng = p.geometry.location.lng();
                s.address = p.formatted_address || p.name || input.value; s.placeText = s.address;
                s.zone = getNearestHawassaZone(s.lat, s.lng);
                input.value = s.address;
                var sel = document.getElementById('pkg-' + sd + '-zone'); if (sel) sel.value = s.zone;
            });
        });
    }
    window.pkgGps = function (which) {
        if (!S) return;
        if (!navigator.geolocation) { showToast(T('pkg_gps_fail'), 'err'); return; }
        navigator.geolocation.getCurrentPosition(function (pos) {
            if (!S) return;
            var s = S[which];
            s.lat = pos.coords.latitude; s.lng = pos.coords.longitude; s.zone = getNearestHawassaZone(s.lat, s.lng);
            // Show the area and the point at once; replace it with a street name when Google can give one (its address search is
            // already allowed by the site's content policy, unlike other map services).
            s.address = s.zone + ' (' + s.lat.toFixed(4) + ', ' + s.lng.toFixed(4) + ')'; s.placeText = s.address;
            render();
            if (window.google && google.maps && google.maps.Geocoder) {
                new google.maps.Geocoder().geocode({ location: { lat: s.lat, lng: s.lng } }, function (results, status) {
                    if (!S || S[which] !== s || s.address !== s.placeText) return;
                    if (status === 'OK' && results && results[0]) {
                        s.address = results[0].formatted_address; s.placeText = s.address;
                        var i = document.getElementById('pkg-' + which + '-addr'); if (i) i.value = s.address;
                    }
                });
            }
        }, function () { showToast(T('pkg_gps_fail'), 'err'); }, { enableHighAccuracy: true, timeout: 10000 });
    };
    window.pkgSaved = function (which, id) {
        if (!S) return;
        var p = null;
        try { p = _savedPlaces.find(function (x) { return x.id == id; }); } catch (e) {}
        if (!p) return;
        var s = S[which], la = parseFloat(p.lat), ln = parseFloat(p.lng);
        s.address = p.address_line || ''; s.placeText = s.address;
        s.lat = isNaN(la) ? null : la; s.lng = isNaN(ln) ? null : ln;
        s.zone = p.zone || ((s.lat !== null && s.lng !== null) ? getNearestHawassaZone(s.lat, s.lng) : '');
        if (s.lat === null) s.placeText = '';
        render();
    };

    // ---------- choices ----------
    window.pkgSize = function (size) { if (S) { S.size = size; S.vehicle = ''; S.quote = null; S.err = ''; render(); } };
    window.pkgWeight = function (kg) { if (S) { S.weight = String(kg); S.vehicle = ''; S.quote = null; S.err = ''; render(); } };
    window.pkgVehicle = function (cls) { if (S) { S.vehicle = cls; S.err = ''; render(); } };
    window.pkgPay = function (m) { if (S) { S.payment = m; render(); } };

    // ---------- steps ----------
    function validate(step) {
        if (step === 1) {
            var out = null;
            ['pickup', 'drop'].forEach(function (k) {
                if (out) return;
                var s = S[k];
                if (!s.address.trim()) out = 'pkg_err_addr_' + k;
                else if (!(s.lat !== null && s.lng !== null) && !s.zone) out = 'pkg_err_area_' + k;
            });
            if (out) return out;
            if (!S.sender_name.trim()) return 'pkg_err_sname';
            if (!validPhone(S.sender_phone)) return 'pkg_err_sphone';
            if (!S.recipient_name.trim()) return 'pkg_err_rname';
            if (!validPhone(S.recipient_phone)) return 'pkg_err_rphone';
        }
        if (step === 2) {
            if (!S.size) return 'pkg_err_size';
            var w = parseFloat(S.weight);
            if (!(w > 0)) return 'pkg_err_weight';
            if (!S.desc.trim()) return 'pkg_err_desc';
            if (!S.ack) return 'pkg_err_ack';
        }
        if (step === 3 && !S.vehicle) return 'pkg_err_vehicle';
        return '';
    }
    window.pkgNext = function () {
        if (!S || S.busy) return;
        var e = validate(S.step);
        if (e) { S.err = T(e); render(); return; }
        S.err = '';
        if (S.step === 2) { S.step = 3; S.quote = null; S.top = true; render(); loadQuote(); return; }
        if (S.step < 4) { S.step++; S.top = true; render(); }
    };
    window.pkgBack = function () {
        if (!S) return;
        if (S.done || S.step === 1) { window.pkgClose(); return; }
        S.step--; S.err = ''; S.top = true; render();
    };
    function loadQuote() {
        S.busy = true; render();
        var mine = S;
        api('customer_package_quote', { pickup: point(S.pickup), drop: point(S.drop), size: S.size, weight_kg: parseFloat(S.weight) }).then(function (res) {
            if (S !== mine) return;
            S.busy = false;
            if (res && res.success) {
                S.quote = res.data;
                var still = S.quote.options.filter(function (o) { return o.vehicle_class === S.vehicle; });
                if (!still.length) S.vehicle = '';
            } else { S.quote = null; S.err = (res && res.message) || T('pkg_load_fail'); }
            render();
        });
    }
    function chosen() {
        if (!S || !S.quote) return null;
        var r = S.quote.options.filter(function (o) { return o.vehicle_class === S.vehicle; });
        return r.length ? r[0] : null;
    }
    window.pkgSubmit = function () {
        if (!S || S.busy) return;
        var c = chosen();
        if (!c) { S.err = T('pkg_err_vehicle'); render(); return; }
        S.busy = true; S.err = ''; render();
        var mine = S;
        api('customer_package_create', {
            pickup: point(S.pickup), drop: point(S.drop), size: S.size, weight_kg: parseFloat(S.weight), vehicle_class: S.vehicle,
            description: S.desc.trim(), declared_value: S.value, notes: S.notes.trim(),
            sender_name: S.sender_name.trim(), sender_phone: S.sender_phone.trim(), recipient_name: S.recipient_name.trim(), recipient_phone: S.recipient_phone.trim(),
            payment_method: S.payment, ack_prohibited: true
        }).then(function (res) {
            if (S !== mine) return;
            if (!res || !res.success) { S.busy = false; S.err = (res && res.message) || T('pkg_load_fail'); render(); return; }
            S.done = res.data;
            if (S.payment === 'chapa') {
                return api('chapa_init', { kind: 'order', id: res.data.order_id }).then(function (pay) {
                    if (pay && pay.success && pay.data && pay.data.checkout_url) { window.location.href = pay.data.checkout_url; return; }
                    S.busy = false; showToast('Package booked, but Chapa could not start. You can pay the courier in cash.', 'err'); render();
                });
            }
            S.busy = false; render();
        });
    };
    window.pkgTheme = function () { try { customerToggleTheme(); } catch (e) {} };
    window.pkgTrack = function (id) { window.pkgClose(); try { openTrackOrder(id); } catch (e) {} };
    window.pkgDone = function () { window.pkgClose(); try { switchCategory('orders'); } catch (e) {} };

    // ---------- drawing ----------
    function field(label, name, value, opts) {
        opts = opts || {};
        return '<label class="pkg-lbl" for="pkg-f-' + name + '">' + esc(label) + '</label>' +
            '<input class="pkg-input" id="pkg-f-' + name + '" data-f="' + name + '" type="' + (opts.type || 'text') + '" value="' + esc(value) + '"' +
            (opts.ph ? ' placeholder="' + esc(opts.ph) + '"' : '') + (opts.mode ? ' inputmode="' + opts.mode + '"' : '') + (opts.auto ? ' autocomplete="' + opts.auto + '"' : '') + '>';
    }
    function placeCard(which, title) {
        var s = S[which];
        var saved = '';
        try {
            if (_savedPlacesState === 'done' && _savedPlaces.length) {
                saved = '<div class="pkg-chips" role="group" aria-label="' + esc(T('pkg_saved')) + '">' + _savedPlaces.slice(0, 6).map(function (p) {
                    return '<button type="button" class="pkg-chip"' + a('pkgSaved', [which, Number(p.id)]) + '>' + (p.is_default ? '🏠 ' : '📍 ') + esc(p.label || p.address_line) + '</button>';
                }).join('') + '</div>';
            }
        } catch (e) {}
        var zones = '<option value="">' + esc(T('pkg_area_pick')) + '</option>' + ZONES.map(function (z) { return '<option value="' + esc(z) + '"' + (s.zone === z ? ' selected' : '') + '>' + esc(z) + '</option>'; }).join('');
        return '<section class="pkg-card"><h3 class="pkg-h">' + esc(title) + '</h3>' + saved +
            '<div class="pkg-row"><input class="pkg-input" id="pkg-' + which + '-addr" data-f="' + which + '.address" type="text" autocomplete="off" value="' + esc(s.address) + '" placeholder="' + esc(T('pkg_addr_ph')) + '" aria-label="' + esc(title) + '">' +
            '<button type="button" class="pkg-gps" title="' + esc(T('pkg_gps')) + '" aria-label="' + esc(T('pkg_gps')) + '"' + a('pkgGps', [which]) + '>📍</button></div>' +
            '<label class="pkg-lbl" for="pkg-' + which + '-zone">' + esc(T('pkg_area')) + '</label>' +
            '<select class="pkg-input" id="pkg-' + which + '-zone" data-f="' + which + '.zone">' + zones + '</select>' +
            (s.lat !== null ? '<div class="pkg-ok">✓ ' + esc(s.zone) + '</div>' : '') +
            '</section>';
    }
    function stepWhere() {
        return placeCard('pickup', T('pkg_pickup')) +
            '<section class="pkg-card">' + field(T('pkg_sender_name'), 'sender_name', S.sender_name, { auto: 'name' }) + field(T('pkg_sender_phone'), 'sender_phone', S.sender_phone, { type: 'tel', mode: 'tel', auto: 'tel' }) + '</section>' +
            placeCard('drop', T('pkg_drop')) +
            '<section class="pkg-card">' + field(T('pkg_rcpt_name'), 'recipient_name', S.recipient_name) + field(T('pkg_rcpt_phone'), 'recipient_phone', S.recipient_phone, { type: 'tel', mode: 'tel' }) + '</section>';
    }
    function stepPackage() {
        var sizes = (S.opts && S.opts.sizes) ? S.opts.sizes : [{ size: 'document' }, { size: 'small' }, { size: 'medium' }, { size: 'large' }];
        var cards = sizes.map(function (z) {
            var on = S.size === z.size;
            return '<button type="button" class="pkg-size' + (on ? ' on' : '') + '" aria-pressed="' + (on ? 'true' : 'false') + '"' + a('pkgSize', [z.size]) + '>' +
                '<span class="pkg-size-ico" aria-hidden="true">' + (SIZE_ICON[z.size] || '📦') + '</span><span>' + esc(T('pkg_size_' + z.size)) + '</span></button>';
        }).join('');
        var quick = [1, 5, 10, 20].map(function (k) { return '<button type="button" class="pkg-chip' + (String(S.weight) === String(k) ? ' on' : '') + '"' + a('pkgWeight', [k]) + '>' + k + ' kg</button>'; }).join('');
        return '<section class="pkg-card"><h3 class="pkg-h">' + esc(T('pkg_size')) + '</h3><div class="pkg-sizes">' + cards + '</div></section>' +
            '<section class="pkg-card">' + field(T('pkg_weight'), 'weight', S.weight, { type: 'number', mode: 'decimal' }) + '<div class="pkg-chips">' + quick + '</div>' +
            field(T('pkg_desc'), 'desc', S.desc, { ph: T('pkg_desc_ph') }) + field(T('pkg_value'), 'value', S.value, { type: 'number', mode: 'decimal' }) + field(T('pkg_notes'), 'notes', S.notes) +
            '<label class="pkg-ack"><input type="checkbox" data-f="ack"' + (S.ack ? ' checked' : '') + '> <span>' + esc(T('pkg_ack')) + '</span></label></section>';
    }
    function stepVehicle() {
        if (S.busy && !S.quote) return '<div class="pkg-loading" role="status">' + esc(T('pkg_loading')) + '</div>';
        if (!S.quote) return '';
        var q = S.quote;
        var head = '<div class="pkg-trip">' + esc(T('pkg_trip').replace('{km}', q.distance_km).replace('{min}', q.eta_min)) + '</div>';
        var list = q.options.map(function (o) {
            var on = S.vehicle === o.vehicle_class;
            return '<button type="button" class="pkg-veh' + (on ? ' on' : '') + '" aria-pressed="' + (on ? 'true' : 'false') + '"' + a('pkgVehicle', [o.vehicle_class]) + '>' +
                '<span class="pkg-veh-ico" aria-hidden="true">' + esc(o.icon || '🚚') + '</span>' +
                '<span class="pkg-veh-txt"><span class="pkg-veh-name">' + esc(o.label) + '</span><span class="pkg-veh-sub">' + esc(T('pkg_up_to').replace('{kg}', o.max_weight_kg)) + '</span></span>' +
                '<span class="pkg-veh-price">ETB ' + esc(o.price) + '</span></button>';
        }).join('');
        return head + '<div class="pkg-vehicles" role="group" aria-label="' + esc(T('pkg_choose_vehicle')) + '">' + list + '</div>';
    }
    function payOpt(m, title, sub) {
        var on = S.payment === m;
        return '<button type="button" class="pkg-pay' + (on ? ' on' : '') + '" aria-pressed="' + (on ? 'true' : 'false') + '"' + a('pkgPay', [m]) + '><span class="pkg-pay-t">' + esc(title) + '</span><span class="pkg-pay-s">' + esc(sub) + '</span></button>';
    }
    function stepPay() {
        var c = chosen();
        var sum = '<section class="pkg-card"><h3 class="pkg-h">' + esc(T('pkg_summary')) + '</h3>' +
            '<div class="pkg-sum"><span>📍</span><span>' + esc(S.pickup.address) + '</span></div>' +
            '<div class="pkg-sum"><span>🏁</span><span>' + esc(S.drop.address) + ' · ' + esc(S.recipient_name) + '</span></div>' +
            '<div class="pkg-sum"><span>' + esc(SIZE_ICON[S.size] || '📦') + '</span><span>' + esc(T('pkg_size_' + S.size)) + ' · ' + esc(S.weight) + ' kg · ' + esc(S.desc) + '</span></div>' +
            (c ? '<div class="pkg-sum"><span>' + esc(c.icon || '') + '</span><span>' + esc(c.label) + '</span></div>' : '') +
            '<div class="pkg-total"><span>' + esc(T('pkg_total')) + '</span><span>ETB ' + esc(c ? c.price : '') + '</span></div></section>';
        var pays = '<section class="pkg-card"><h3 class="pkg-h">' + esc(T('pkg_pay_title')) + '</h3><div class="pkg-pays">' +
            payOpt('cash', T('pkg_pay_cash'), T('pkg_pay_cash_sub')) +
            (typeof etelPayLive !== 'undefined' && etelPayLive ? payOpt('wallet', T('pkg_pay_wallet'), T('pkg_pay_wallet_sub')) : '') +
            payOpt('chapa', T('pkg_pay_chapa'), T('pkg_pay_chapa_sub')) + '</div></section>';
        return sum + pays;
    }
    function stepDone() {
        var d = S.done;
        return '<div class="pkg-done"><div class="pkg-done-ico" aria-hidden="true">✓</div><h2 class="pkg-done-t">' + esc(T('pkg_done_title')) + '</h2>' +
            '<p class="pkg-done-s">' + esc(T('pkg_done_sub')) + '</p>' +
            '<div class="pkg-code">' + esc(d.order_code) + '</div><div class="pkg-total" style="justify-content:center;gap:10px"><span>' + esc(T('pkg_total')) + '</span><span>ETB ' + esc(d.total) + '</span></div></div>';
    }

    function stepper() {
        var names = ['pkg_step1', 'pkg_step2', 'pkg_step3', 'pkg_step4'];
        return '<ol class="pkg-steps" aria-label="' + esc(T('pkg_title')) + '">' + names.map(function (k, i) {
            var n = i + 1, cls = n < S.step ? ' done' : (n === S.step ? ' now' : '');
            return '<li class="pkg-step' + cls + '"' + (n === S.step ? ' aria-current="step"' : '') + '><span class="pkg-step-n">' + (n < S.step ? '✓' : n) + '</span><span class="pkg-step-l">' + esc(T(k)) + '</span></li>';
        }).join('') + '</ol>';
    }

    function render() {
        var v0 = view(); if (!v0 || !S) return;
        var body, foot = '';
        if (S.loadFail) {
            body = '<div class="pkg-loading">' + esc(T('pkg_load_fail')) + '</div>';
            foot = '<button type="button" class="pkg-primary"' + a('pkgRetry') + '>' + esc(T('pkg_retry')) + '</button>';
        } else if (!S.opts) {
            body = '<div class="pkg-loading" role="status">' + esc(T('pkg_loading')) + '</div>';
        } else if (S.done) {
            body = stepDone();
            foot = '<button type="button" class="pkg-primary"' + a('pkgTrack', [Number(S.done.order_id)]) + '>' + esc(T('pkg_track')) + '</button>' +
                   '<button type="button" class="pkg-secondary"' + a('pkgDone') + '>' + esc(T('pkg_done_btn')) + '</button>';
        } else {
            body = stepper() + (S.step === 1 ? stepWhere() : S.step === 2 ? stepPackage() : S.step === 3 ? stepVehicle() : stepPay());
            var last = S.step === 4;
            foot = '<div id="pkg-err" class="pkg-err" role="alert">' + esc(S.err) + '</div>' +
                '<button type="button" class="pkg-primary"' + (S.busy ? ' disabled' : '') + a(last ? 'pkgSubmit' : 'pkgNext') + '>' + esc(last ? (S.busy ? T('pkg_booking') : T('pkg_book') + (chosen() ? ' · ETB ' + chosen().price : '')) : T('pkg_next')) + '</button>' +
                   (S.step > 1 ? '<button type="button" class="pkg-secondary"' + a('pkgBack') + '>' + esc(T('pkg_back')) + '</button>' : '');
        }
        var scroll = (!S.top && v0.querySelector('#pkg-scroll')) ? v0.querySelector('#pkg-scroll').scrollTop : 0;
        S.top = false;
        v0.innerHTML =
            '<header class="app-header pkg-head"><div class="header-brand"><button class="header-btn" type="button" aria-label="' + esc(T('pkg_back')) + '"' + a('pkgBack') + '>' +
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></button>' +
            '<span class="pkg-head-ico"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg></span>' +
            '<div class="brand-info"><div class="pkg-head-title">' + esc(T('pkg_title')) + '</div></div></div>' +
            '<button class="header-btn pkg-theme" type="button" aria-label="' + esc(T('pkg_theme')) + '" title="' + esc(T('pkg_theme')) + '"' + a('pkgTheme') + '>' +
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor"/></svg></button></header>' +
            '<div id="pkg-scroll" class="pkg-scroll">' + body + '</div><div class="pkg-foot">' + foot + '</div>';
        var sc = v0.querySelector('#pkg-scroll'); if (sc) sc.scrollTop = scroll;
        bindPlaces();
    }

    // one listener each; the view node is stable, its content is redrawn
    document.addEventListener('DOMContentLoaded', function () {
        var v0 = view();
        if (v0) { v0.addEventListener('input', onField); v0.addEventListener('change', onField); }
        try { ETEL_I18N.apply(ETEL_I18N.current()); } catch (e) {}
    });
    if (document.readyState !== 'loading') {
        var v1 = view();
        if (v1 && !v1._pkgWired) { v1._pkgWired = true; v1.addEventListener('input', onField); v1.addEventListener('change', onField); }
        try { ETEL_I18N.apply(ETEL_I18N.current()); } catch (e) {}
    }

    // Package details on the tracking screen (called from customer.js refreshTrackOrder).
    // The tracking data carries the vehicle's code (moto, car, x1...). Show its name instead, from the same list the booking screen uses.
    var _vehLabels = null, _vehLoading = false;
    function vehicleLabel(o) {
        if (_vehLabels && _vehLabels[o.vehicle_class]) return _vehLabels[o.vehicle_class];
        if (!_vehLabels && !_vehLoading) {
            _vehLoading = true;
            apiGet('customer_package_options', {}).then(function (res) {
                _vehLabels = {};
                if (res && res.success && res.data && res.data.vehicles) res.data.vehicles.forEach(function (v) { _vehLabels[v.vehicle_class] = v.label; });
                try { window.renderTrackPackage(o); } catch (e) {}
            }).catch(function () { _vehLoading = false; });
        }
        return o.vehicle_class;
    }
    // ── Delivery journey: the four moments of a package with their clock times, how long each part took, and a running timer for the part
    // that is happening now. All times come from the database clock (order.pkg_times, epoch seconds) so the phone's own clock never matters.
    var JT = { timer: null, offset: 0 };
    function nowSec() { return Math.floor(Date.now() / 1000) - JT.offset; }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function clockText(sec) { sec = Math.max(0, sec); var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), x = sec % 60; return (h ? h + ':' + pad2(m) : pad2(m)) + ':' + pad2(x); }
    function durText(sec) {
        sec = Math.max(0, Math.round(sec));
        if (sec < 60) return sec + ' ' + T('pkg_j_sec');
        var m = Math.floor(sec / 60);
        if (m < 60) return m + ' ' + T('pkg_j_min') + (m < 10 ? ' ' + pad2(sec % 60) + ' ' + T('pkg_j_sec') : '');
        return Math.floor(m / 60) + ' ' + T('pkg_j_hr') + ' ' + pad2(m % 60) + ' ' + T('pkg_j_min');
    }
    function wallClock(sec) { try { return new Date(sec * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; } }
    function tickJourney() {
        var card = document.getElementById('track-package-card');
        var tv = document.getElementById('view-track-order');
        if (!card || !card.querySelector('[data-pt-from]') || (tv && tv.style.display === 'none')) { clearInterval(JT.timer); JT.timer = null; return; }
        Array.prototype.forEach.call(card.querySelectorAll('[data-pt-from]'), function (el) {
            el.textContent = clockText(nowSec() - parseInt(el.getAttribute('data-pt-from'), 10));
        });
    }
    function journeyHtml(o) {
        var t = o.pkg_times;
        if (!t || !t.booked) return '';
        JT.offset = Math.floor(Date.now() / 1000) - (t.now || Math.floor(Date.now() / 1000));
        var st = o.status;
        var cancelled = st === 'cancelled', delivered = st === 'delivered';
        // which part is happening now: 1 = waiting for a courier, 2 = courier heading to the pickup, 3 = on the way to the recipient
        var phase = cancelled || delivered ? 0 : (t.picked_up || st === 'picked_up' || st === 'in_transit') ? 3 : (t.accepted || st === 'assigned') ? 2 : 1;
        var from = phase === 3 ? (t.picked_up || t.accepted || t.booked) : phase === 2 ? (t.accepted || t.booked) : t.booked;
        var head = cancelled ? T('pkg_j_cancel') : delivered ? T('pkg_j_done') : phase === 3 ? T('pkg_j_carry') : phase === 2 ? T('pkg_j_enroute') : T('pkg_j_finding');
        var big, sub;
        if (delivered && t.delivered) { big = durText(t.delivered - t.booked); sub = T('pkg_j_total'); }
        else if (cancelled) { big = t.cancelled ? wallClock(t.cancelled) : '—'; sub = ''; }
        else { big = '<span data-pt-from="' + from + '">' + clockText(nowSec() - from) + '</span>'; sub = phase === 1 ? T('pkg_j_since') : T('pkg_j_now'); }
        var steps = [
            { k: 'booked', at: t.booked, label: T('pkg_j_booked'), dur: '' },
            { k: 'accepted', at: t.accepted, label: T('pkg_j_accepted'), dur: t.accepted ? T('pkg_j_found') + ' ' + durText(t.accepted - t.booked) : '' },
            { k: 'picked', at: t.picked_up, label: T('pkg_j_picked'), dur: t.picked_up && t.accepted ? T('pkg_j_reached') + ' ' + durText(t.picked_up - t.accepted) : '' },
            { k: 'delivered', at: t.delivered, label: T('pkg_j_delivered'), dur: t.delivered && t.picked_up ? T('pkg_j_trip') + ' ' + durText(t.delivered - t.picked_up) : '' }
        ];
        // the step being waited for is the one after the last finished step
        var activeIdx = cancelled || delivered ? -1 : phase; // phase 1 -> step 1 (accepted), 2 -> step 2 (picked), 3 -> step 3 (delivered)
        var doneUpTo = delivered ? 3 : cancelled ? 0 : phase - 1;   // a step counts as done from the status too, in case an older server has no time for it
        var list = steps.map(function (sp, i) {
            var done = !!sp.at || i <= doneUpTo, active = i === activeIdx, cls = 'pkg-j-step' + (done ? ' is-done' : '') + (active ? ' is-active' : '');
            var right = done && sp.at ? '<span class="pkg-j-clock">' + esc(wallClock(sp.at)) + '</span>' : (active ? '<span class="pkg-j-clock pkg-j-live" data-pt-from="' + from + '">' + clockText(nowSec() - from) + '</span>' : '');
            return '<li class="' + cls + '"><span class="pkg-j-dot" aria-hidden="true">' + (done ? '✓' : '') + '</span><div class="pkg-j-body"><div class="pkg-j-name">' + esc(sp.label) + '</div>' +
                (sp.dur ? '<div class="pkg-j-dur">' + esc(sp.dur) + '</div>' : '') + '</div>' + right + '</li>';
        }).join('');
        return '<section class="pkg-card pkg-journey' + (cancelled ? ' is-cancelled' : delivered ? ' is-delivered' : '') + '"><div class="pkg-j-hero"><div class="pkg-j-k">' + esc(head) + '</div>' +
            '<div class="pkg-j-timer" aria-live="off">' + big + '</div>' + (sub ? '<div class="pkg-j-sub">' + esc(sub) + '</div>' : '') + '</div>' +
            '<h3 class="pkg-h pkg-j-h">' + esc(T('pkg_j_title')) + '</h3><ol class="pkg-j-steps">' + list + '</ol></section>';
    }
    var DETAIL_ICON = ['📍', '🏁', '📦', '🛵'];
    window.renderTrackPackage = function (o) {
        var box = document.getElementById('track-package-card');
        if (!box) return;
        var tl = document.getElementById('track-timeline');
        if (!o || o.order_kind !== 'package') { box.innerHTML = ''; if (tl) tl.style.display = ''; return; }
        var journey = journeyHtml(o);
        if (tl) tl.style.display = journey ? 'none' : '';   // the journey card replaces the generic list of steps
        var size = o.pkg_size ? T('pkg_size_' + o.pkg_size) : '';
        var rows = [
            [T('pkg_trk_from'), o.pickup_address || o.from_zone],
            [T('pkg_trk_to'), (o.recipient_address || o.to_zone) + (o.recipient_name ? ' · ' + o.recipient_name : '')],
            [T('pkg_trk_what'), [size, o.pkg_weight_kg ? parseFloat(o.pkg_weight_kg) + ' kg' : '', o.pkg_desc].filter(Boolean).join(' · ')],
            [T('pkg_trk_vehicle'), o.vehicle_class ? vehicleLabel(o) : '']
        ].map(function (r, i) { return r[1] ? [r[0], r[1], DETAIL_ICON[i]] : null; }).filter(Boolean);
        // The sender's two 4-digit PINs (the server only sends them to the sender): the pickup PIN goes to the courier at collection,
        // the delivery PIN goes to the recipient, who tells it to the courier at the door. Each is shown only while it is still needed.
        var live = o.status !== 'delivered' && o.status !== 'cancelled';
        var onTheWay = o.status === 'picked_up' || o.status === 'in_transit';
        function pinBlock(label, pin, hint) {
            var digits = String(pin).split('').map(function (d) { return '<span class="pkg-pin-d">' + esc(d) + '</span>'; }).join('');
            return '<div class="pkg-pin"><div class="pkg-pin-k">' + esc(label) + '</div><div class="pkg-pin-v" role="img" aria-label="' + esc(label) + ' ' + esc(String(pin).split('').join(' ')) + '">' + digits + '</div><div class="pkg-pin-h">' + esc(hint) + '</div></div>';
        }
        var pins = '';
        if (live && o.pickup_pin && !onTheWay) pins += pinBlock(T('pkg_pin_pick'), o.pickup_pin, T('pkg_pin_pick_hint'));
        if (live && o.delivery_pin) pins += pinBlock(T('pkg_pin_drop'), o.delivery_pin, T('pkg_pin_drop_hint'));
        box.innerHTML = journey + '<section class="pkg-card pkg-trk"><h3 class="pkg-h">📦 ' + esc(T('pkg_trk_title')) + '</h3>' + pins +
            rows.map(function (r) { return '<div class="pkg-trk-row"><span class="pkg-trk-ic" aria-hidden="true">' + r[2] + '</span><span class="pkg-trk-k">' + esc(r[0]) + '</span><span class="pkg-trk-v">' + esc(r[1]) + '</span></div>'; }).join('') + '</section>';
        if (box.querySelector('[data-pt-from]') && !JT.timer) JT.timer = setInterval(tickJourney, 1000);
    };
})();
