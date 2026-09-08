/**
 * Etel-ID Premium — Admin JavaScript
 *
 * @package    Etel-ID Premium
 * @version    2.0.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @link       https://etelgroup.com
 * @since      2.0.0
 *
 * Handles AJAX delete and toggle-status actions in the admin member table.
 * Fades out deleted rows; updates status badge text and class in-place.
 */
(function($){
  'use strict';
  $(document).on('click','.eid-delete',function(){
    if(!confirm(EtelIDAdmin.confirm_delete))return;
    var $b=$(this),id=$b.data('id');
    $b.prop('disabled',true).css('opacity','.4');
    $.post(EtelIDAdmin.ajax,{action:'etelid_admin_action',sub:'delete',id:id,nonce:EtelIDAdmin.nonce})
    .done(function(r){
      if(r.success){$b.closest('tr').animate({opacity:0},400,function(){$(this).remove()});}
      else{$b.prop('disabled',false).css('opacity','1');alert('Delete failed.');}
    }).fail(function(){$b.prop('disabled',false).css('opacity','1');});
  });
  $(document).on('click','.eid-toggle',function(){
    var $b=$(this),id=$b.data('id');
    $b.prop('disabled',true);
    $.post(EtelIDAdmin.ajax,{action:'etelid_admin_action',sub:'toggle',id:id,nonce:EtelIDAdmin.nonce})
    .done(function(r){
      if(r.success){
        var $badge=$b.closest('tr').find('.eid-badge'),st=r.data.status;
        $badge.attr('class','eid-badge eid-badge-'+st).text(st.charAt(0).toUpperCase()+st.slice(1));
      }
      $b.prop('disabled',false);
    }).fail(function(){$b.prop('disabled',false);});
  });
})(jQuery);

/* ══ Biometric Enrollment — v3.0.0 ══ */
jQuery(function($) {

    /* Enroll any modality */
    $(document).on('click', '.etelid-bio-enroll-btn', function() {
        var $btn    = $(this);
        var method  = $btn.data('method');
        var mid     = $btn.data('mid');
        var $msg    = $('#bio-msg-' + method);
        var $panel  = $('#panel-' + method);

        /* Physical biometrics — special handling */
        if ( method === 'physical' ) {
            var pData = {
                action:    'etelid_admin',
                sub:       'bio_enroll',
                nonce:     EtelIDAdmin.nonce,
                member_id: mid,
                method:    'physical',
                value:     JSON.stringify({
                    height:  $('#phys-height').val(),
                    weight:  $('#phys-weight').val(),
                    eye:     $('#phys-eye').val(),
                    hair:    $('#phys-hair').val(),
                    skin:    $('#phys-skin').val(),
                    marks:   $('#phys-marks').val(),
                }),
                quality: 100,
            };
            $btn.prop('disabled', true).text('Saving…');
            $.post(EtelIDAdmin.ajax, pData, function(res) {
                if (res.success) {
                    $msg.css('color','#2ecc71').text('✓ Saved!');
                } else {
                    $msg.css('color','#e74c3c').text('✗ ' + (res.data || 'Error'));
                }
                $btn.prop('disabled', false).text('💾 Save Physical Biometrics');
            });
            return;
        }

        var value   = $('#bio-val-' + method).val().trim();
        var quality = parseInt($('#bio-qual-' + method).val()) || 80;
        if ( ! value ) { $msg.css('color','#e74c3c').text('⚠ Enter a value first.'); return; }

        $btn.prop('disabled', true).text('Enrolling…');
        $msg.css('color','#aaa').text('');

        $.post(EtelIDAdmin.ajax, {
            action:    'etelid_admin',
            sub:       'bio_enroll',
            nonce:     EtelIDAdmin.nonce,
            member_id: mid,
            method:    method,
            value:     value,
            quality:   quality,
        }, function(res) {
            if (res.success) {
                $msg.css('color','#2ecc71').text('✓ Enrolled! Score: ' + res.data.score + '%');
                $btn.text('🔄 Re-enroll');
                if ($panel.length) {
                    $panel.addClass('enrolled');
                    $panel.find('.ebp-badge.not-enrolled').attr('class','ebp-badge enrolled').text('✓ Enrolled');
                }
            } else {
                $msg.css('color','#e74c3c').text('✗ ' + (res.data || 'Error'));
            }
            $btn.prop('disabled', false);
        });
    });

    /* WebAuthn browser registration */
    $(document).on('click', '#webauthn-register-btn', function() {
        var $btn  = $(this);
        var mid   = $btn.data('mid');
        var name  = $btn.data('name');
        var eid   = $btn.data('id');
        var $msg  = $('#bio-msg-webauthn');

        if ( ! window.PublicKeyCredential ) {
            $msg.css('color','#e74c3c').text('✗ WebAuthn not supported in this browser.');
            return;
        }

        $btn.prop('disabled', true).text('Waiting for device…');
        $msg.css('color','#aaa').text('Starting registration…');

        /* Build a minimal PublicKeyCredentialCreationOptions */
        var challenge = new Uint8Array(32);
        window.crypto.getRandomValues(challenge);
        var userId = new TextEncoder().encode(String(mid));

        navigator.credentials.create({
            publicKey: {
                challenge:   challenge,
                rp:          { name: 'Etel-ID Premium', id: window.location.hostname },
                user:        { id: userId, name: eid, displayName: name },
                pubKeyCredParams: [
                    { type: 'public-key', alg: -7  },   /* ES256 */
                    { type: 'public-key', alg: -257 },  /* RS256 */
                ],
                authenticatorSelection: {
                    authenticatorAttachment: 'platform',
                    requireResidentKey: false,
                    userVerification: 'preferred',
                },
                timeout: 60000,
                attestation: 'none',
            }
        }).then(function(credential) {
            var credId  = btoa(String.fromCharCode.apply(null, new Uint8Array(credential.rawId)));
            var pubKey  = btoa(String.fromCharCode.apply(null, new Uint8Array(credential.response.attestationObject)));
            $('#bio-val-webauthn').val(credId + '|' + pubKey);
            $msg.css('color','#2ecc71').text('✓ Device registered! Click Enroll to save.');
            $btn.prop('disabled', false).text('🔑 Register Another Device');
        }).catch(function(err) {
            $msg.css('color','#e74c3c').text('✗ ' + err.message);
            $btn.prop('disabled', false).text('🔑 Register WebAuthn Device (Browser)');
        });
    });
});
