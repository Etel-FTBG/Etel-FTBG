/* ETEL Driver - English / Amharic switch (additive script, no bundle release).
   The engine is js/etel-dom-i18n.js (shared). This file is only the driver's dictionary and its wiring.
   - One language choice for all ETEL apps (storage key etel_lang), so a driver who already chose Amharic in the
     rider app sees it here too.
   - The old Profile > Language row (a small partial translation) now switches this full one.
   - The Amharic wording is a first draft for a native speaker to review: edit the right-hand side of any line.
   Text with no entry stays English. */
(function () {
    'use strict';
    if (window.__etelDriverI18n || !window.EtelDomI18n) return;
    window.__etelDriverI18n = true;

    var AM = {
        'ETEL Driver': 'ኢቴል ሾፌር', 'Menu': 'ምናሌ', 'Requests': 'ጥያቄዎች', 'Earnings': 'ገቢ', 'Chats': 'መልዕክቶች', 'Profile': 'መገለጫ', 'Exit': 'ውጣ',
        'Update Now': 'አሁን አዘምን',
        'Orders unavailable': 'ትዕዛዞች አይገኙም', 'Offline': 'ከመስመር ውጪ', 'Online': 'በመስመር ላይ',
        'Connection lost — reconnecting': 'ግንኙነት ጠፍቷል — እንደገና እየተገናኘ ነው', 'Details': 'ዝርዝር',
        'Slide to go online': 'መስመር ላይ ለመግባት ያንሸራትቱ',
        'Go online to see nearby ride requests': 'በአቅራቢያ ያሉ የጉዞ ጥያቄዎችን ለማየት መስመር ላይ ይግቡ',
        'Priority': 'ቅድሚያ', "Today's progress": 'የዛሬ ሂደት', 'Goals': 'ግቦች', 'trips': 'ጉዞዎች',
        'Loading your trips today…': 'የዛሬ ጉዞዎችዎ በመጫን ላይ…', 'Loading goals…': 'ግቦች በመጫን ላይ…',
        'Street Hail — link a customer': 'የመንገድ ጥሪ — ደንበኛ ያገናኙ',
        "Today's Earnings": 'የዛሬ ገቢ', 'Available to cash out': 'ገንዘብ ለማውጣት የሚገኝ', 'Total earned — all trips': 'ጠቅላላ ገቢ — ሁሉም ጉዞዎች',
        'Your Performance': 'አፈጻጸምዎ', 'Today': 'ዛሬ', 'This Week': 'በዚህ ሳምንት', 'This Month': 'በዚህ ወር', 'NET ETB': 'የተጣራ ብር', 'PER HOUR': 'በሰዓት',
        'Completed rides': 'የተጠናቀቁ ጉዞዎች', 'Gross fares': 'ጠቅላላ ዋጋ', 'Platform fee (5%)': 'የመድረክ ክፍያ (5%)', 'Net earnings (95%)': 'የተጣራ ገቢ (95%)',
        'Distance driven': 'የተነዳ ርቀት', 'Online time': 'በመስመር ላይ የቆየ ጊዜ', 'Fuel cost': 'የነዳጅ ወጪ', 'Net after fuel': 'ከነዳጅ በኋላ የተጣራ',
        'Fuel Management': 'የነዳጅ አስተዳደር', 'Log a Fill-up': 'ነዳጅ መሙላት ይመዝግቡ', 'Save Fill-up': 'መሙላቱን አስቀምጥ',
        'Payout': 'ክፍያ', 'Service partner': 'የአገልግሎት አጋር',
        'History': 'ታሪክ', 'Trip History': 'የጉዞ ታሪክ', 'Loading trips…': 'ጉዞዎች በመጫን ላይ…', 'Loading…': 'በመጫን ላይ…',
        'Messages': 'መልዕክቶች', 'New': 'አዲስ', 'About safety': 'ስለ ደህንነት', 'Support': 'ድጋፍ', 'Get help with your account': 'ስለ አካውንትዎ እርዳታ ያግኙ',
        'Pro news': 'የሙያ ዜና', 'Latest updates for drivers': 'ለሾፌሮች የቅርብ ጊዜ ዝማኔዎች', 'Support notifications': 'የድጋፍ ማሳወቂያዎች', 'Alerts and reminders': 'ማንቂያዎችና ማስታወሻዎች',
        'From Etel Group': 'ከኢቴል ግሩፕ', 'Partner announcements': 'የአጋር ማስታወቂያዎች', 'Warnings': 'ማስጠንቀቂያዎች', 'Account restrictions': 'የአካውንት ገደቦች',
        'Hawassa, Ethiopia': 'ሐዋሳ፣ ኢትዮጵያ', 'VERIFIED DRIVER': 'የተረጋገጠ ሾፌር', 'Invite': 'ጋብዝ', 'Rating': 'ደረጃ', 'Total rides': 'ጠቅላላ ጉዞዎች', 'Partner': 'አጋር',
        'Service classes': 'የአገልግሎት ክፍሎች', 'Payment & earnings': 'ክፍያና ገቢ', 'Cash · tap to view balance': 'ጥሬ ገንዘብ · ቀሪ ሂሳብ ለማየት ይንኩ',
        'My vehicles': 'ተሽከርካሪዎቼ', 'Complete vehicle registration': 'የተሽከርካሪ ምዝገባን ያጠናቅቁ', 'Add your vehicle details to start driving': 'መንዳት ለመጀመር የተሽከርካሪዎን መረጃ ያክሉ',
        'Update Vehicle Info': 'የተሽከርካሪ መረጃ አዘምን', 'Edit your vehicle details': 'የተሽከርካሪዎን መረጃ ያርሙ',
        'Compliance Documents': 'የማክበር ሰነዶች', 'Insurance, technical inspection, Road Fund': 'ኢንሹራንስ፣ ቴክኒካል ፍተሻ፣ የመንገድ ፈንድ',
        'Support & Safety Center': 'የድጋፍና ደህንነት ማዕከል', 'Help, safety guides, report an issue': 'እርዳታ፣ የደህንነት መመሪያዎች፣ ችግር ያሳውቁ',
        'Language': 'ቋንቋ', 'English selected': 'አማርኛ ተመርጧል',
        'Download the ETEL app': 'የኢቴል አፕ ያውርዱ', 'Faster, and opens even with a weak connection': 'ፈጣን ነው፣ ደካማ ግንኙነትም ላይ ይከፈታል',
        'Admin Dashboard': 'የአስተዳዳሪ ዳሽቦርድ', "You're signed in as admin": 'እንደ አስተዳዳሪ ገብተዋል',
        'Two-step sign-in': 'ባለሁለት ደረጃ መግቢያ', 'Delete my account': 'አካውንቴን ሰርዝ', 'Ask ETEL to delete your account and data': 'ኢቴል አካውንትዎንና መረጃዎን እንዲሰርዝ ይጠይቁ',
        'Privacy Policy': 'የግላዊነት ፖሊሲ', 'How ETEL uses your data': 'ኢቴል መረጃዎን እንዴት እንደሚጠቀም',
        'Receipt printer': 'የደረሰኝ ማተሚያ', 'Display size': 'የማሳያ መጠን', 'Fix app not updating': 'አፕ አለመዘመንን አስተካክል',
        'Clear cache and reload if things look stuck or old': 'የተጣበቀ ወይም የቆየ መስሎ ከታየ ካሼውን አጽድተው እንደገና ይጫኑ',
        'Get started': 'ጀምር', 'Driver registration': 'የሾፌር ምዝገባ', 'Choose a service': 'አገልግሎት ይምረጡ',
        'Select your vehicle type to complete trips in Hawassa': 'በሐዋሳ ጉዞዎችን ለማጠናቀቅ የተሽከርካሪዎን ዓይነት ይምረጡ',
        'Loading vehicle types…': 'የተሽከርካሪ ዓይነቶች በመጫን ላይ…', 'Enter your vehicle details': 'የተሽከርካሪዎን መረጃ ያስገቡ',
        'Type of vehicle': 'የተሽከርካሪ ዓይነት', 'Make / Brand': 'ሞዴል / ብራንድ', 'Color': 'ቀለም', 'Manufacture year': 'የተመረተበት ዓመት', 'Vehicle plate number': 'የተሽከርካሪ ታርጋ ቁጥር',
        "Driver's license information": 'የመንጃ ፈቃድ መረጃ', 'Enter your license details for verification': 'ለማረጋገጫ የመንጃ ፈቃድዎን መረጃ ያስገቡ',
        'Country of issue': 'የተሰጠበት አገር', 'Ethiopia': 'ኢትዮጵያ', 'Full name (on license)': 'ሙሉ ስም (በመንጃ ፈቃድ ላይ)', 'License number': 'የመንጃ ፈቃድ ቁጥር',
        'Date of birth (on license)': 'የትውልድ ቀን (በመንጃ ፈቃድ ላይ)', 'Photo of your license (required)': 'የመንጃ ፈቃድዎ ፎቶ (ግዴታ)', 'Tap to photograph your license': 'የመንጃ ፈቃድዎን ለማንሳት ይንኩ',
        'Take a selfie': 'ሴልፊ ያንሱ', 'Tap to take photo': 'ፎቶ ለማንሳት ይንኩ',
        'You are almost ready for your first trip!': 'ለመጀመሪያ ጉዞዎ ተቃርበዋል!', 'Plate number': 'የታርጋ ቁጥር', 'Continue': 'ቀጥል',
        'Bajaj': 'ባጃጅ', 'Moto': 'ሞተር', 'Car': 'መኪና', 'Minibus': 'ሚኒባስ',
        /* trips, requests and toasts drawn by driver.js */
        'Waiting for ride request': 'የጉዞ ጥያቄ በመጠበቅ ላይ', 'Go online to receive requests': 'ጥያቄዎችን ለመቀበል መስመር ላይ ይግቡ',
        'Waiting for customer': 'ደንበኛን በመጠበቅ ላይ', 'Not waiting': 'መጠበቅ የለም', 'Total': 'ጠቅላላ', 'Share Code': 'ኮድ አጋራ', 'Link Customer': 'ደንበኛ አገናኝ',
        'No completed rides yet': 'እስካሁን የተጠናቀቀ ጉዞ የለም', 'Could not load history': 'ታሪክ መጫን አልተቻለም', 'Could not load goals': 'ግቦችን መጫን አልተቻለም',
        'Not on file': 'በፋይል የለም', 'No fare recorded': 'የተመዘገበ ዋጋ የለም', 'Route driven': 'የተነዳ መንገድ', 'Topping up': 'ገንዘብ በመሙላት ላይ', 'Cashing out': 'ገንዘብ በማውጣት ላይ',
        'Accept': 'ተቀበል', 'Decline': 'አልቀበልም', 'Slide to Accept': 'ለመቀበል ያንሸራትቱ', 'Cancel Ride': 'ጉዞ ሰርዝ', 'Cancel': 'ሰርዝ', 'Back': 'ተመለስ', 'Next': 'ቀጣይ',
        'Pickup': 'መነሻ', 'Drop-off': 'መድረሻ', 'Passenger': 'ተሳፋሪ', 'Fare': 'ዋጋ', 'Payment': 'ክፍያ', 'Distance': 'ርቀት', 'Route': 'መንገድ', 'Current fare': 'አሁን ያለ ዋጋ',
        'Submit': 'ላክ', 'Submit for Approval': 'ለማጽደቅ ላክ', 'Submit for Review': 'ለግምገማ ላክ', 'Save Changes': 'ለውጦችን አስቀምጥ', 'Got it': 'ገባኝ', 'Saving…': 'በማስቀመጥ ላይ…',
        'Submitted ✓': 'ተልኳል ✓', 'Uploading…': 'በመጫን ላይ…', 'Could not load': 'መጫን አልተቻለም',
        'You are now online': 'አሁን በመስመር ላይ ነዎት', 'You are now offline': 'አሁን ከመስመር ውጪ ነዎት',
        'Ride accepted! Navigate to pickup': 'ጉዞ ተቀብለዋል! ወደ መነሻ ይሂዱ', 'Ride started! Drive safely 🚀': 'ጉዞ ተጀምሯል! በጥንቃቄ ይንዱ', 'Ride cancelled': 'ጉዞ ተሰርዟል',
        'Trip ended by rider — you are available again': 'ተሳፋሪው ጉዞውን አብቅቷል — እንደገና ዝግጁ ነዎት',
        'Enable location to go online': 'መስመር ላይ ለመግባት አካባቢን ያብሩ', 'Location not available': 'አካባቢ አይገኝም', 'Copied to clipboard': 'ተቀድቷል',
        'Driver account required': 'የሾፌር አካውንት ያስፈልጋል', 'No available balance to cash out': 'ለማውጣት የሚገኝ ቀሪ ሂሳብ የለም', 'Enter a valid amount': 'ትክክለኛ መጠን ያስገቡ',
        'Take a selfie to verify your identity': 'ማንነትዎን ለማረጋገጥ ሴልፊ ያንሱ', 'Take a photo of your driving license': 'የመንጃ ፈቃድዎን ፎቶ ያንሱ', 'Submitted for admin review ✓': 'ለአስተዳዳሪ ግምገማ ተልኳል ✓',
        'Enter your license number': 'የመንጃ ፈቃድ ቁጥርዎን ያስገቡ', 'Enter your date of birth as shown on your license': 'የትውልድ ቀንዎን በመንጃ ፈቃድዎ ላይ እንዳለ ያስገቡ', 'Enter the vehicle plate number': 'የተሽከርካሪውን ታርጋ ቁጥር ያስገቡ', 'Enter the name on your license': 'በመንጃ ፈቃድዎ ላይ ያለውን ስም ያስገቡ',
        'Amount exceeds your available balance': 'መጠኑ ከሚገኘው ቀሪ ሂሳብዎ በላይ ነው', 'Attach a photo of the document': 'የሰነዱን ፎቶ ያያይዙ', 'Pick the expiry date printed on the document': 'በሰነዱ ላይ የታተመውን የሚያበቃበት ቀን ይምረጡ',
        'No response from customer — trip closed, contact support if unpaid': 'ከደንበኛው ምላሽ የለም — ጉዞው ተዘግቷል፣ ክፍያ ካልተከፈለ ድጋፍን ያግኙ', 'By-distance ride — passenger will direct you 🧭': 'በርቀት የሚታሰብ ጉዞ — ተሳፋሪው ይመራዎታል 🧭',
        'Trip resolved — you are available again': 'ጉዞው ተፈትቷል — እንደገና ዝግጁ ነዎት', 'Photo selected': 'ፎቶ ተመርጧል', 'License photo selected': 'የመንጃ ፈቃድ ፎቶ ተመርጧል', 'Geolocation not supported': 'የአካባቢ መለያ አይደገፍም', 'Share not supported on this browser': 'ማጋራት በዚህ ብሮውዘር አይደገፍም',
        'Top-up submitted — pending admin approval': 'ሙሌቱ ተልኳል — የአስተዳዳሪ ማጽደቂያ በመጠበቅ ላይ',
        'Send emergency alert?': 'የአደጋ ጊዜ ማንቂያ ይላክ?', 'This immediately notifies ETEL support with your role, location, and current trip (if any). Use this if you feel unsafe or something is wrong right now.': 'ይህ ወዲያውኑ የእርስዎን ሚና፣ አካባቢና የአሁኑን ጉዞ (ካለ) ለኢቴል ድጋፍ ያሳውቃል። ደህንነት ካልተሰማዎት ወይም አሁን የሆነ ችግር ካለ ይህን ይጠቀሙ።',
        'Send SOS Now': 'አሁኑኑ SOS ላክ', 'Sending…': 'በመላክ ላይ…', 'Sending your location…': 'አካባቢዎ በመላክ ላይ…', 'Could not send - try again': 'መላክ አልተቻለም - እንደገና ይሞክሩ', 'Network error - try again': 'የአውታረ መረብ ስህተት - እንደገና ይሞክሩ',
    };
    var ATTRS = {
        'Requests': 'ጥያቄዎች', 'Earnings': 'ገቢ', 'Chats': 'መልዕክቶች', 'Profile': 'መገለጫ', 'Exit': 'ውጣ', 'Go offline': 'ከመስመር ውጣ',
        'Full map': 'ሙሉ ካርታ', 'Toggle light/dark mode': 'ብርሃን/ጨለማ ገጽታ ቀይር',
        "Driver's license number": 'የመንጃ ፈቃድ ቁጥር', 'First and last name': 'ስም እና የአባት ስም'
    };
    var RULES = [
        [/^(\d+) trips? completed$/, '$1 ጉዞዎች ተጠናቀዋል'],
        [/^(\d+) trips?$/, '$1 ጉዞዎች'],
        [/^(\d+) trips? goal$/, 'የ$1 ጉዞዎች ግብ'],
        [/^(\d+(?:\.\d+)?) km$/, '$1 ኪ.ሜ'],
        [/^~(\d+) min$/, '~$1 ደቂቃ']
    ];

    var api = EtelDomI18n.create({
        key: 'etel_lang', am: AM, attrs: ATTRS, rules: RULES,
        buttonHost: '.drv-topbar', buttonBefore: '.theme-toggle-btn', buttonId: 'drv-lang-btn', buttonClass: 'theme-toggle-btn drv-lang-btn'
    });

    // The old partial translator keeps its own key; pin it to English so it never rewrites text behind this one.
    try { localStorage.setItem('etel_driver_lang', 'en'); } catch (e) {}
    // Profile > Language used to run that old toggle: make it switch this one instead.
    document.addEventListener('click', function (ev) {
        var row = ev.target && ev.target.closest ? ev.target.closest('.profile-row[onclick*="toggleDriverLanguage"]') : null;
        if (!row) return;
        ev.stopImmediatePropagation(); ev.preventDefault();
        api.setLang(api.lang() === 'am' ? 'en' : 'am');
    }, true);
    window.EtelDriverI18n = api;
})();
