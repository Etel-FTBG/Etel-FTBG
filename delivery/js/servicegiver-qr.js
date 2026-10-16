/* Service Giver page: QR scanner (camera, jsQR decode, sg_scan_qr). Was an inline script in servicegiver.html. */
    /* ── Real QR decode loop (was camera-preview-only before) ──
       Draws each video frame to a canvas, runs jsQR against the pixel
       data, and on a decoded result calls the real sg_scan_qr backend
       action -- this used to just show a live camera feed and do
       nothing else. */
    var _qrType = 'pickup';
    var _qrScanning = false;
    function setQrType(type) {
        _qrType = type;
        document.getElementById('qr-type-pickup').style.opacity = type === 'pickup' ? 1 : .5;
        document.getElementById('qr-type-delivery').style.opacity = type === 'delivery' ? 1 : .5;
    }
    setQrType('pickup');

    function _qrExtractOrderId(text) {
        try {
            var obj = JSON.parse(text);
            if (obj && obj.order_id) return parseInt(obj.order_id);
        } catch (e) {}
        var m = String(text).match(/order_id=(\d+)/) || String(text).match(/(\d{2,})/);
        return m ? parseInt(m[1]) : null;
    }

    async function startQRScanner() {
        var video = document.getElementById('qr-video');
        var canvas = document.getElementById('qr-canvas');
        var ctx = canvas.getContext('2d', { willReadFrequently: true });
        var resultEl = document.getElementById('qr-result');
        resultEl.className = 'qr-result'; resultEl.style.display = 'none';

        try {
            var stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            video.srcObject = stream;
            await video.play();
        } catch (error) {
            showNotification(cameraAccessMessage(error), 'error');
            return;
        }

        _qrScanning = true;
        requestAnimationFrame(_qrTick);

        function _qrTick() {
            if (!_qrScanning) return;
            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
                var code = window.jsQR ? jsQR(img.data, img.width, img.height) : null;
                if (code && code.data) {
                    _qrScanning = false;
                    (video.srcObject.getTracks() || []).forEach(function (t) { t.stop(); });
                    _qrHandleDecoded(code.data);
                    return;
                }
            }
            requestAnimationFrame(_qrTick);
        }
    }

    async function _qrHandleDecoded(text) {
        var resultEl = document.getElementById('qr-result');
        var orderId = _qrExtractOrderId(text);
        if (!orderId) {
            resultEl.className = 'qr-result err'; resultEl.textContent = '❌ Could not read an order ID from that QR code.';
            return;
        }
        resultEl.className = 'qr-result'; resultEl.style.display = 'block'; resultEl.textContent = 'Confirming…';
        var data = await apiCall('sg_scan_qr', { order_id: orderId, qr_type: _qrType });
        if (data) {
            resultEl.className = 'qr-result ok';
            resultEl.textContent = '✅ Order #' + orderId + ' ' + _qrType + ' confirmed!';
            if (typeof loadDashboardData === 'function') loadDashboardData();
        } else {
            resultEl.className = 'qr-result err';
            resultEl.textContent = '❌ Could not confirm order #' + orderId + '.';
        }
    }
    
