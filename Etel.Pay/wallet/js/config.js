/**
 * ኢE Pay — Config
 * Single source of truth. Edit this file to change endpoints.
 */
const EP_CONFIG = {
  version: '1.0.1',
  name:    'ኢE Pay',
  nameEn:  'ETEL Pay',

  // Base URL of this wallet folder (auto-detect)
  get BASE() {
    const loc  = window.location.pathname;
    const dir  = loc.substring(0, loc.lastIndexOf('/') + 1);
    return window.location.origin + dir;
  },

  // Standalone PHP API (no WP needed)
  get ACTION()   { return this.BASE + 'api/v2_verify.php'; },
  get PORTAL()   { return this.BASE + 'api/v2_verify.php'; },
  get NONCE_EP() { return this.BASE + 'api/v2_verify.php?action=ep_nonce'; },

  // Session storage keys
  STORAGE: {
    TOKEN: 'ep_token',
    PHONE: 'ep_phone',
    LANG:  'ep_lang',
    NONCE: 'ep_nonce',
  },

  // Bilingual labels
  LANG: {
    en: {
      login:      'Sign in',
      wallet:     'Wallet',
      send:       'Send',
      receive:    'Receive',
      history:    'History',
      qr:         'My QR',
      profile:    'Profile',
      admin:      'Admin',
      balance:    'Wallet Balance',
      phone:      'Phone number',
      pin:        'Wallet PIN',
      amount:     'Amount (ETB)',
      note:       'Note',
      send_btn:   'Send money',
      confirm:    'Confirm',
      cancel:     'Cancel',
      sign_out:   'Sign out',
      loading:    'Loading…',
      no_tx:      'No transactions yet',
      home:       'Home',
      all:        'All',
      incoming:   'Incoming',
      outgoing:   'Outgoing',
      pending:    'Pending',
      create_wallet: 'Create wallet',
      full_name:  'Full name',
      email:      'Email (optional)',
      create_pin: 'Create PIN',
      confirm_pin:'Confirm PIN',
      already:    'Already have a wallet?',
      new_wallet: 'New to ኢE Pay?',
      scan_qr:    'Scan QR',
      my_qr:      'My QR code',
      pay_request:'Payment request',
      gen_qr:     'Generate QR',
      label:      'Label',
      change_pin: 'Change PIN',
      old_pin:    'Current PIN',
      new_pin:    'New PIN',
      save:       'Save changes',
      kyc_status: 'KYC status',
      top_up:     'Top up',
    },
    am: {
      login:      'ግባ',
      wallet:     'ዋሌት',
      send:       'ላክ',
      receive:    'ተቀበል',
      history:    'ታሪክ',
      qr:         'QR ኮድ',
      profile:    'መገለጫ',
      admin:      'አስተዳዳሪ',
      balance:    'የዋሌት ቀሪ ሂሳብ',
      phone:      'ስልክ ቁጥር',
      pin:        'የዋሌት PIN',
      amount:     'መጠን (ብር)',
      note:       'ማስታወሻ',
      send_btn:   'ገንዘብ ላክ',
      confirm:    'አረጋግጥ',
      cancel:     'ሰርዝ',
      sign_out:   'ውጣ',
      loading:    'በመጫን ላይ…',
      no_tx:      'ምንም ግብይቶች የሉም',
      home:       'መነሻ',
      all:        'ሁሉም',
      incoming:   'የገባ',
      outgoing:   'የወጣ',
      pending:    'በመጠባበቅ ላይ',
      create_wallet: 'ዋሌት ፍጠር',
      full_name:  'ሙሉ ስም',
      email:      'ኢሜይል (አማራጭ)',
      create_pin: 'PIN ፍጠር',
      confirm_pin:'PIN አረጋግጥ',
      already:    'ቀድሞ ዋሌት አለህ?',
      new_wallet: 'አዲስ ነህ?',
      scan_qr:    'QR ቃኝ',
      my_qr:      'የኔ QR ኮድ',
      pay_request:'የክፍያ ጥያቄ',
      gen_qr:     'QR ፍጠር',
      label:      'መግለጫ',
      change_pin: 'PIN ቀይር',
      old_pin:    'አሁን ያለ PIN',
      new_pin:    'አዲስ PIN',
      save:       'ለውጦች አስቀምጥ',
      kyc_status: 'KYC ሁኔታ',
      top_up:     'ሂሳብ ሙላ',
    }
  }
};
