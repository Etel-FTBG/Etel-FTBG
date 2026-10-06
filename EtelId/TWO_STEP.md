# Two-step sign-in for EtelID (authenticator app)

Same idea as the two-step sign-in in Delivery & Ride.

## For members
Portal > **Two-step sign-in**. Enter your password, scan the code with an
authenticator app (Google Authenticator, Microsoft Authenticator, Authy),
type the 6-digit code, and save the 8 backup codes (shown once). From then on
signing in needs the password AND a code. It is opt-in: nobody is forced.

## What it covers
- The EtelID login page (phone + password).
- "Sign in with EtelID" in Delivery & Ride (the code prompt appears there; the
  app's own prompt is skipped when EtelID already checked the code).
- NOT passkey sign-in (Face ID / fingerprint): that already needs the device and
  the person's biometric, which is stronger.
- NOT the "confirm with password" step on an NFC card tap.

## Safety rails
- Secret encrypted in the database; a used code cannot be replayed.
- 5 wrong codes per 10 minutes per ACCOUNT, then locked for the window.
- Backup codes: 8, single use, only hashes stored; can be regenerated.
- Turning off or getting new backup codes needs a current code.
- Starting set-up needs the current password.

## Lost phone and backup codes
An admin opens Portal > Reset Password, finds the member and presses
**Reset two-step sign-in**. The member then signs in with the password only and
can set it up again. Last resort in phpMyAdmin:
`DELETE FROM <prefix>etelid_2fa WHERE member_id = <id>;`
