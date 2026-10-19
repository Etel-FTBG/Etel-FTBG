/* ETEL Ride - English / Amharic switch (additive script, no bundle release).

   The rider app's text is built in many places (page HTML, ride.js, other add-on scripts), so instead of editing
   each one this script translates what is on screen: it looks every visible piece of text up in the dictionary
   below and swaps it, and keeps watching the page so text drawn later (vehicle list, trip status, history) is
   translated too. The original English is remembered per node, so switching back restores it exactly.
   - Language is stored under the same key as the other ETEL apps (etel_lang), so one choice carries across apps.
   - A button in the header switches language. Text with no entry here simply stays English.
   - Amharic wording is a first draft for a native speaker to review: change the right-hand side of the
     lines below, nothing else needs to move.
   - Never touches typed input values, scripts, styles or the Google Places dropdown. */
(function () {
    'use strict';
    if (window.__etelRideI18n) return;
    window.__etelRideI18n = true;

    var KEY = 'etel_lang';
    var AM = {
        'ETEL Ride': 'ኢቴል ራይድ',
        'Driver': 'ሾፌር', 'Book': 'ጉዞ ይዘዙ', 'Street Hail': 'የመንገድ ጥሪ', 'Active': 'አሁን ያለ', 'History': 'ታሪክ',
        'Update Now': 'አሁን አዘምን',
        'Where are you going?': 'ወዴት ይሄዳሉ?',
        'Quick zones': 'ፈጣን ቦታዎች', 'no GPS needed': 'ጂፒኤስ አያስፈልግም',
        'Set pickup': 'መነሻ ያዘጋጁ', 'Set drop-off': 'መድረሻ ያዘጋጁ',
        'Sending a package instead?': 'ጥቅል መላክ ፈልገዋል?',
        'Rides are for you — Delivery is for your packages': 'ጉዞ ለእርስዎ ነው — ዴሊቨሪ ለጥቅሎችዎ ነው',
        'Open meter': 'ክፍት ሜትር', '— no destination? Tell the driver on the way': '— መድረሻ የለም? በመንገድ ላይ ለሾፌሩ ይንገሩት',
        'Choose vehicle': 'ተሽከርካሪ ይምረጡ',
        'Tap the map to set pickup & drop-off, or type above': 'መነሻና መድረሻ ለማዘጋጀት ካርታውን ይንኩ ወይም ከላይ ይጻፉ',
        'Change': 'ቀይር',
        'Bajaj': 'ባጃጅ', 'Moto': 'ሞተር', 'Car': 'መኪና', 'Minibus': 'ሚኒባስ', 'EV Car': 'የኤሌክትሪክ መኪና',
        'Cheapest': 'በጣም ርካሽ', 'Fastest': 'በጣም ፈጣን',
        'No drivers online': 'ሾፌር በመስመር ላይ የለም', 'Drivers available': 'ሾፌሮች አሉ',
        'See All Types on Map': 'ሁሉንም ዓይነቶች በካርታ ይመልከቱ',
        'Estimated Fare': 'የተገመተ ዋጋ', 'Peak-hour pricing': 'የትራፊክ ሰዓት ዋጋ',
        'Final fare is based on the actual distance your driver travels, from pickup to drop-off — this is just an estimate.':
            'የመጨረሻው ዋጋ ሾፌሩ ከመነሻ እስከ መድረሻ በሚጓዘው ትክክለኛ ርቀት ላይ ይመሰረታል — ይህ ግምት ብቻ ነው።',
        'Cash': 'ጥሬ ገንዘብ', 'Wallet': 'ዋሌት', 'Chapa': 'ቻፓ',
        'Schedule for later': 'ለኋላ ያዘጋጁ', 'Slide to request ride': 'ጉዞ ለመጠየቅ ያንሸራትቱ',
        'Finding your driver…': 'ሾፌር እየፈለግን ነው…', 'This usually takes a few seconds': 'ይህ ብዙውን ጊዜ ጥቂት ሰከንዶችን ይወስዳል',
        'Connecting with nearby drivers': 'በአቅራቢያ ካሉ ሾፌሮች ጋር እየተገናኘን ነው',
        'Pickup': 'መነሻ', 'Your location': 'የእርስዎ አካባቢ', 'Destination': 'መድረሻ', 'Cancel Ride': 'ጉዞ ሰርዝ',
        'Driver on the way': 'ሾፌር እየመጣ ነው', 'Tracking your driver': 'ሾፌርዎን እየተከታተልን ነው',
        'Your Driver': 'ሾፌርዎ', 'Call': 'ደውል', 'Chat': 'ውይይት', 'Issues': 'ችግሮች',
        'Pickup at': 'መነሻ በ', 'Fare estimate': 'የዋጋ ግምት', 'Driver is waiting': 'ሾፌር እየጠበቀ ነው',
        'View Route So Far': 'እስካሁን ያለውን መንገድ ይመልከቱ',
        'Trip Complete': 'ጉዞ ተጠናቋል', 'Loading...': 'በመጫን ላይ...', 'Loading…': 'በመጫን ላይ…',
        'Rate Your Driver': 'ሾፌርዎን ደረጃ ይስጡ', 'How was your ride?': 'ጉዞዎ እንዴት ነበር?',
        'Submit Rating': 'ደረጃ ላክ', 'Skip': 'ዝለል',
        'Ride History': 'የጉዞ ታሪክ', 'All your past trips': 'ያለፉ ጉዞዎችዎ ሁሉ',
        'How it works': 'እንዴት እንደሚሰራ',
        'Why are you cancelling?': 'ለምን ይሰርዛሉ?', 'Help us improve your experience': 'አገልግሎታችንን እንድናሻሽል ይርዱን',
        'Plans changed': 'እቅድ ተቀይሯል', 'Wrong address': 'የተሳሳተ አድራሻ', 'Weather': 'የአየር ሁኔታ', 'Found another ride': 'ሌላ ጉዞ አግኝቻለሁ',
        'Continue with cancellation': 'ስረዛውን ቀጥል', 'Go back': 'ተመለስ',
        'Vehicle': 'ተሽከርካሪ', 'Plate': 'ታርጋ', 'Copy': 'ቅዳ', 'Phone': 'ስልክ', 'Close': 'ዝጋ',
        'Report an Issue': 'ችግር ያሳውቁ', 'Our team reviews every report. Select what happened:': 'ቡድናችን ሁሉንም ሪፖርቶች ይመረምራል። የሆነውን ይምረጡ፦',
        "Driver didn't show up": 'ሾፌሩ አልመጣም', 'Wrong route taken': 'የተሳሳተ መንገድ ተወስዷል', 'Safety concern': 'የደህንነት ስጋት',
        'Fare dispute': 'የዋጋ ክርክር', 'Driver behavior': 'የሾፌር ባህሪ', 'Other': 'ሌላ',
        'Submit Report': 'ሪፖርት ላክ', 'Cancel': 'ሰርዝ', 'Save': 'አስቀምጥ',
        'VERIFIED RIDER': 'የተረጋገጠ ተሳፋሪ', 'Rides': 'ጉዞዎች', 'Total Spent': 'ጠቅላላ ወጪ', 'Your Rating': 'የእርስዎ ደረጃ',
        'Saved Addresses': 'የተቀመጡ አድራሻዎች', 'Quick pickup and drop-off spots': 'ፈጣን የመነሻና መድረሻ ቦታዎች',
        'Favorite Routes': 'ተወዳጅ መንገዶች', 'Save trips you take often': 'ብዙ ጊዜ የሚጓዙባቸውን ጉዞዎች ያስቀምጡ',
        'Messages': 'መልዕክቶች', 'Ride updates and support': 'የጉዞ ዝማኔዎችና ድጋፍ',
        'Referral & Rewards': 'ጓደኛ መጋበዝና ሽልማት', 'Earn ETB with friends': 'ከጓደኞችዎ ጋር ብር ያግኙ',
        'Safety contacts': 'የደህንነት ዕውቂያዎች', 'Two-step sign-in': 'ባለሁለት ደረጃ መግቢያ',
        'Receipt printer': 'የደረሰኝ ማተሚያ', 'Display Size': 'የማሳያ መጠን',
        'Settings & Privacy': 'ቅንብርና ግላዊነት', 'Notifications and password': 'ማሳወቂያዎችና የይለፍ ቃል',
        'Balance and payments': 'ቀሪ ሂሳብና ክፍያዎች', 'Become a Driver': 'ሾፌር ይሁኑ', 'Earn with ETEL Ride': 'በኢቴል ራይድ ገቢ ያግኙ',
        'Logout': 'ውጣ', 'Label': 'መለያ', 'Address': 'አድራሻ', 'Subject': 'ርዕስ', 'Message': 'መልዕክት',
        'Set your trip': 'ጉዞዎን ያዘጋጁ', "Tap the map or type where you are and where you're going. No GPS? Use the Quick zones.": 'ያሉበትንና የሚሄዱበትን ቦታ ካርታው ላይ ይንኩ ወይም ይጻፉ። ጂፒኤስ የለም? ፈጣን ቦታዎችን ይጠቀሙ።',
        'See the price first': 'መጀመሪያ ዋጋውን ይመልከቱ', 'Pick Bajaj, Moto or Car and see the fare for your trip before you book. Pay cash, wallet or Chapa.': 'ባጃጅ፣ ሞተር ወይም መኪና ይምረጡና ከመያዝዎ በፊት የጉዞዎን ዋጋ ይመልከቱ። በጥሬ ገንዘብ፣ በዋሌት ወይም በቻፓ ይክፈሉ።',
        'Coming soon': 'በቅርቡ ይመጣል',
        'Send emergency alert?': 'የአደጋ ጊዜ ማንቂያ ይላክ?', 'This immediately notifies ETEL support with your role, location, and current trip (if any). Use this if you feel unsafe or something is wrong right now.': 'ይህ ወዲያውኑ የእርስዎን ሚና፣ አካባቢና የአሁኑን ጉዞ (ካለ) ለኢቴል ድጋፍ ያሳውቃል። ደህንነት ካልተሰማዎት ወይም አሁን የሆነ ችግር ካለ ይህን ይጠቀሙ።',
        'Send SOS Now': 'አሁኑኑ SOS ላክ', 'Sending…': 'በመላክ ላይ…', 'Sending your location…': 'አካባቢዎ በመላክ ላይ…', 'Could not send - try again': 'መላክ አልተቻለም - እንደገና ይሞክሩ', 'Network error - try again': 'የአውታረ መረብ ስህተት - እንደገና ይሞክሩ',
        'Ride safely': 'በሰላም ይጓዙ', "Check the driver's name and plate, share your trip, and use SOS any time.": 'የሾፌሩን ስምና ታርጋ ያረጋግጡ፣ ጉዞዎን ያጋሩ፣ እንዲሁም በማንኛውም ጊዜ SOS ይጠቀሙ።',
        'Next': 'ቀጣይ', 'Get started': 'ጀምር',
        /* messages the app builds while booking and during a trip */
        'Checking your ride…': 'ጉዞዎን በማረጋገጥ ላይ…', 'Could not load ride': 'ጉዞውን መጫን አልተቻለም', 'Check your connection and try again': 'ግንኙነትዎን ይፈትሹና እንደገና ይሞክሩ',
        'No Active Ride': 'ንቁ ጉዞ የለም', 'Book a new ride from the Book tab': 'ከ«ጉዞ ይዘዙ» ትር አዲስ ጉዞ ይዘዙ', 'Arriving in ~1 min': 'በ~1 ደቂቃ ውስጥ ይደርሳል', 'On the way to destination': 'ወደ መድረሻ በመሄድ ላይ',
        'Booking…': 'በመያዝ ላይ…', 'Book Ride': 'ጉዞ ይዘዙ', 'Submitting…': 'በመላክ ላይ…', 'Processing...': 'በመስራት ላይ...', 'Network error - try again': 'የኔትወርክ ችግር - እንደገና ይሞክሩ',
        'Re-checking your previous payment…': 'ቀዳሚ ክፍያዎን እንደገና በመፈተሽ ላይ…', 'Looking up payment account…': 'የክፍያ አካውንት በመፈለግ ላይ…', 'Sending payment…': 'ክፍያ በመላክ ላይ…',
        'Could not load route.': 'መንገዱን መጫን አልተቻለም።',
        'Please select a rating': 'እባክዎ ደረጃ ይምረጡ', 'Please select what happened': 'እባክዎ የሆነውን ይምረጡ', 'Please set your pickup location': 'እባክዎ የመነሻ ቦታዎን ያዘጋጁ',
        'Ride cancelled': 'ጉዞ ተሰርዟል', 'Ride was cancelled': 'ጉዞው ተሰርዟል', 'Driver phone not available': 'የሾፌሩ ስልክ አይገኝም',
        'Thanks for riding with ETEL!': 'ከኢቴል ጋር ስለተጓዙ እናመሰግናለን!', 'Thanks for riding with ETEL! 🚗': 'ከኢቴል ጋር ስለተጓዙ እናመሰግናለን! 🚗',
        'Old ride cancelled — booking new one…': 'የቀድሞው ጉዞ ተሰርዟል — አዲስ በመያዝ ላይ…', 'Network error — could not cancel. Please try again.': 'የኔትወርክ ችግር — መሰረዝ አልተቻለም። እባክዎ እንደገና ይሞክሩ።',
        'Could not find your phone number. Please pay cash.': 'ስልክ ቁጥርዎን ማግኘት አልተቻለም። እባክዎ በጥሬ ገንዘብ ይክፈሉ።',
        'Set a drop-off place, or choose a distance in km': 'መድረሻ ያዘጋጁ ወይም ርቀቱን በኪ.ሜ ይምረጡ', 'No coordinates for this location': 'ለዚህ ቦታ መጋጠሚያ የለም',
        'Add Address': 'አድራሻ ጨምር', 'Contact Support': 'ድጋፍን ያግኙ', 'Send to Support': 'ወደ ድጋፍ ላክ',
        "Check that the driver’s name and plate number match what’s shown in the app before getting in.": "ከመግባትዎ በፊት የሾፌሩ ስምና የታርጋ ቁጥር በመተግበሪያው ላይ ከሚታየው ጋር እንደሚመሳሰል ያረጋግጡ።",
        "No connection — your fare and distance are still being tracked, this will update the moment you're back online.": "ግንኙነት የለም — ክፍያዎና ርቀትዎ አሁንም እየተመዘገበ ነው፣ መስመር ላይ ሲመለሱ ወዲያውኑ ይታደሳል።",
        "Tracking reminder not showing?": "የክትትል ማስታወሻ አይታይም?",
        "Share": "አጋራ",
        "A driver will link you directly — no booking needed": "ሾፌር በቀጥታ ያገናኝዎታል — ማስያዝ አያስፈልግም",
        "1. Find an ETEL driver on the street": "1. በመንገድ ላይ የኢቴል ሾፌር ያግኙ",
        "2. Give them your name or phone number": "2. ስምዎን ወይም ስልክ ቁጥርዎን ይስጧቸው",
        "3. The driver links your phone — the ride appears here automatically": "3. ሾፌሩ ስልክዎን ያገናኛል — ጉዞው እዚህ በራስ-ሰር ይታያል",
        "4. Track your trip and pay at the end": "4. ጉዞዎን ይከታተሉ፣ በመጨረሻም ይክፈሉ",
        "Waiting for driver to link your ride": "ሾፌሩ ጉዞዎን እስኪያገናኝ በመጠበቅ ላይ",
        "This page checks every 5 seconds": "ይህ ገጽ በየ5 ሰከንዱ ይፈትሻል",
        "✓ Auto-detection is active": "✓ ራስ-ሰር ማግኘት ንቁ ነው",
        "If a driver links your ride, you will be taken to the active ride screen automatically": "ሾፌር ጉዞዎን ካገናኘ በራስ-ሰር ወደ ንቁ የጉዞ ገጽ ይወሰዳሉ",
        "← Book a ride instead": "← በምትኩ ጉዞ ይዘዙ",
        "My initiative": "በራሴ ውሳኔ",
        "Driver actions": "የሾፌር ድርጊቶች",
        "Asked to cancel": "እንድሰርዝ ጠየቀኝ",
        "Wrong way": "የተሳሳተ መንገድ",
        "Not moving": "አይንቀሳቀስም",
        "Asked for more money": "ተጨማሪ ገንዘብ ጠየቀ",
        "Not responding": "አይመልስም",
        "Service issues": "የአገልግሎት ችግሮች",
        "Pickup too long": "ማንሳቱ ረጅም ጊዜ ወሰደ",
        "Ride price": "የጉዞ ዋጋ",
        "Share your trip, check-ins, SOS contacts": "ጉዞዎን ያጋሩ፣ ቼክ-ኢኖች፣ የSOS እውቂያዎች",
        "A code from your phone when you sign in": "ሲገቡ ከስልክዎ የሚላክ ኮድ",
        "Not supported on this browser": "በዚህ ብሮውዘር አይደገፍም",
        "Text, icons, buttons and logos": "ጽሁፍ፣ አዶዎች፣ አዝራሮችና አርማዎች",
        "Admin Dashboard": "የአስተዳዳሪ ዳሽቦርድ",
        "You're signed in as admin": "እንደ አስተዳዳሪ ገብተዋል",
        "Download the ETEL app": "የኢቴል መተግበሪያን ያውርዱ",
        "Faster, and opens even with a weak connection": "ፈጣን ነው፣ ደካማ ግንኙነት ቢኖርም ይከፈታል",
        "Fix app not updating": "አለመዘመንን ያስተካክሉ",
        "Clear cache and reload if things look stuck or old": "የተጣበቀ ወይም የቆየ መስሎ ከታየ ካሼ ያጽዱና እንደገና ይጫኑ",
        "Save spots you use often for faster booking.": "ለፈጣን ማዘዝ ብዙ ጊዜ የሚጠቀሙባቸውን ቦታዎች ያስቀምጡ።",
        "Zone (optional)": "ዞን (አማራጭ)",
        "Set as default address": "እንደ ነባሪ አድራሻ አዘጋጅ",
        "Version & diagnostics": "ስሪትና ምርመራ",
        "Save trips you take often for one-tap rebooking.": "ብዙ ጊዜ የሚሄዱባቸውን ጉዞዎች በአንድ ንክኪ እንደገና ለማዘዝ ያስቀምጡ።",
        "+ Save Current Route": "+ አሁኑን መንገድ አስቀምጥ",
        "⭐ Save a Route": "⭐ መንገድ አስቀምጥ",
        "Pickup address": "የመነሻ አድራሻ",
        "Drop-off address": "የመድረሻ አድራሻ",
        "Save Route": "መንገዱን አስቀምጥ",
        "Ride updates and past support conversations.": "የጉዞ ማሳወቂያዎችና ያለፉ የድጋፍ ንግግሮች።",
        "Alert sent to ETEL support": "ማንቂያው ወደ ኢቴል ድጋፍ ተልኳል",
        "Tap Share, then \"Add to Home Screen\" to install": "አጋራን ይንኩ፣ ከዚያ ለመጫን \"ወደ መነሻ ገጽ አክል\" ን ይምረጡ",
        "Already installed on this device": "በዚህ መሣሪያ ላይ ቀድሞ ተጭኗል",
        "Install isn’t available yet - try again in a moment": "መጫን እስካሁን አይቻልም - ከጥቂት ቆይታ በኋላ እንደገና ይሞክሩ",
        "App installed — find it on your home screen": "መተግበሪያው ተጭኗል — በመነሻ ገጽዎ ላይ ያገኙታል",
        "Payment received through Chapa. Thank you!": "ክፍያው በቻፓ ተቀብሏል። እናመሰግናለን!",
        "Your Chapa payment was not completed.": "የቻፓ ክፍያዎ አልተጠናቀቀም።",
        "Still waiting for Chapa to confirm. It will update automatically.": "ቻፓ እስኪያረጋግጥ አሁንም በመጠበቅ ላይ። በራስ-ሰር ይዘምናል።",
        "Have a promo code?": "የማስተዋወቂያ ኮድ አለዎት?",
        "Offer your own price": "የራስዎን ዋጋ ያቅርቡ",
        "Promo discount": "የማስተዋወቂያ ቅናሽ",
        "The total above is already after your promo.": "ከላይ ያለው ድምር የማስተዋወቂያ ቅናሹ ከተቀነሰ በኋላ ነው።",
        "The promo discount was paid by Etel.": "የማስተዋወቂያ ቅናሹን የከፈለው ኢቴል ነው።",
        "Your offer": "የእርስዎ ዋጋ",
        "Allowed range": "የሚፈቀድ ክልል",
        "Drivers can accept your price or suggest another.": "አሽከርካሪዎች ዋጋዎን ሊቀበሉ ወይም ሌላ ዋጋ ሊጠቁሙ ይችላሉ።",
        "Offers from drivers": "ከአሽከርካሪዎች የመጡ ዋጋዎች",
        "Raise offer": "ዋጋ ጨምር",
        "Waiting for drivers to answer…": "የአሽከርካሪዎች ምላሽ በመጠበቅ ላይ…",
        "Accept": "ተቀበል",
        "km away": "ኪ.ሜ ርቀት",
        "The price is fixed once you choose a driver.": "አሽከርካሪ ከመረጡ በኋላ ዋጋው ይወሰናል።",
        "Promo code": "የማስተዋወቂያ ኮድ",
        "Apply": "ተግብር",
        "Enter a promo code": "የማስተዋወቂያ ኮድ ያስገቡ",
        "That promo code is not valid": "ይህ የማስተዋወቂያ ኮድ ትክክል አይደለም",
        "That promo code has not started yet": "ይህ የማስተዋወቂያ ኮድ ገና አልጀመረም",
        "That promo code has expired": "ይህ የማስተዋወቂያ ኮድ ጊዜው አልፏል",
        "That promo code has been used up": "ይህ የማስተዋወቂያ ኮድ ተሟጦ አልቋል",
        "You have already used that promo code": "ይህን የማስተዋወቂያ ኮድ ቀደም ብለው ተጠቅመዋል",
        "Could not check the code. Try again.": "ኮዱን ማረጋገጥ አልተቻለም። እንደገና ይሞክሩ።",
        "Promo codes": "የማስተዋወቂያ ኮዶች",
        "Make a code for riders to use. It works after an admin approves it. Etel pays for the discount.": "ለተሳፋሪዎች የሚሆን ኮድ ይፍጠሩ። አስተዳዳሪ ካጸደቀው በኋላ ይሰራል። ቅናሹን የሚከፍለው ኢቴል ነው።",
        "Code": "ኮድ",
        "Type": "ዓይነት",
        "% off the fare": "ከዋጋው % ቅናሽ",
        "ETB off the fare": "ከዋጋው የብር ቅናሽ",
        "Amount": "መጠን",
        "Number of uses": "የአጠቃቀም ብዛት",
        "Send for approval": "ለማጽደቅ ላክ",
        "My codes": "የእኔ ኮዶች",
        "No codes yet.": "እስካሁን ኮድ የለም።",
        "Waiting for approval": "ማጽደቂያ በመጠበቅ ላይ",
        "Approved": "ጸድቋል",
        "Rejected": "ተቀባይነት አላገኘም",
        "Switched off": "ጠፍቷል",
        "Sent for approval. It works once an admin approves it.": "ለማጽደቅ ተልኳል። አስተዳዳሪ ሲያጸድቀው ይሰራል።",
        "Make a code for riders": "ለተሳፋሪዎች ኮድ ይፍጠሩ",
        "Could not send the code. Try again.": "ኮዱን መላክ አልተቻለም። እንደገና ይሞክሩ።",
        "Code must be 3-20 letters or numbers": "ኮዱ ከ3-20 ፊደላት ወይም ቁጥሮች መሆን አለበት",
        "That code already exists - pick another": "ይህ ኮድ አስቀድሞ አለ - ሌላ ይምረጡ",
        "Tip your driver": "ሾፌርዎን ይሸልሙ",
        "For your last trip": "ለመጨረሻው ጉዞዎ",
        "Tip after a completed trip.": "ጉዞ ከተጠናቀቀ በኋላ ይሸልሙ።",
        "Could not load": "መጫን አልተቻለም",
        "Could not load. Try again.": "መጫን አልተቻለም። እንደገና ይሞክሩ።",
        "Or another amount (ETB)": "ወይም ሌላ መጠን (ብር)",
        "1. Send it to one of these accounts": "1. ከእነዚህ አካውንቶች ወደ አንዱ ይላኩ",
        "2. Transfer reference": "2. የዝውውር ማጣቀሻ ቁጥር",
        "Copied": "ተቀድቷል",
        "Send tip": "ሽልማት ላክ",
        "Your driver gets all of it, after Etel confirms your transfer.": "ሾፌርዎ ሙሉውን ያገኛል፣ ኢቴል ዝውውርዎን ካረጋገጠ በኋላ።",
        "Etel has not set up a bank account for tips yet.": "ኢቴል ለሽልማት የባንክ አካውንት እስካሁን አላዘጋጀም።",
        "Choose an amount of at least ETB 5": "ቢያንስ 5 ብር ይምረጡ",
        "Enter the transfer reference from your bank": "ከባንክዎ የዝውውር ማጣቀሻ ቁጥር ያስገቡ",
        "Could not send the tip": "ሽልማቱን መላክ አልተቻለም",
        "Could not send the tip. Try again.": "ሽልማቱን መላክ አልተቻለም። እንደገና ይሞክሩ።",
        "Thank you! The tip reaches your driver once Etel confirms the transfer.": "እናመሰግናለን! ኢቴል ዝውውሩን ካረጋገጠ በኋላ ሽልማቱ ሾፌርዎ ጋር ይደርሳል።",
        "Waiting for Etel to confirm your transfer": "ኢቴል ዝውውርዎን እስኪያረጋግጥ በመጠበቅ ላይ",
        "Sent to your driver. Thank you!": "ለሾፌርዎ ተልኳል። እናመሰግናለን!",
        "Etel could not find the transfer": "ኢቴል ዝውውሩን ማግኘት አልቻለም",
        "No completed trip to tip for yet.": "ለመሸለም የተጠናቀቀ ጉዞ እስካሁን የለም።",
        "You can tip once the trip is completed": "ጉዞው ከተጠናቀቀ በኋላ መሸለም ይችላሉ",
        "It is too late to tip for this trip": "ለዚህ ጉዞ ለመሸለም ጊዜው አልፏል",
        "You already left a tip for this trip": "ለዚህ ጉዞ ቀድሞ ሽልማት ትተዋል",
        "That transfer reference was already used for another tip": "ይህ የዝውውር ማጣቀሻ ቁጥር ለሌላ ሽልማት ቀድሞ ጥቅም ላይ ውሏል"
    };
    var AM_ATTR = {
        'Pickup — or tap the map': 'መነሻ — ወይም ካርታውን ይንኩ', 'Where to?': 'ወዴት?', 'Use my current location': 'አሁን ያለሁበትን ተጠቀም',
        'Pickup location': 'የመነሻ ቦታ', 'Drop-off location': 'የመድረሻ ቦታ', 'Full map': 'ሙሉ ካርታ', 'Payment method': 'የክፍያ ዘዴ',
        'Leave a comment (optional)…': 'አስተያየት ይጻፉ (አማራጭ)…', 'Switch to light theme': 'ወደ ብርሃን ገጽታ ቀይር', 'Switch to dark theme': 'ወደ ጨለማ ገጽታ ቀይር',
        "Dismiss": "ዝጋ",
        "Light theme": "ብሩህ ገጽታ",
        "Expand or collapse ride panel": "የጉዞ ፓነልን ዘርጋ ወይም ሰብስብ",
        "Choose a vehicle": "ተሽከርካሪ ይምረጡ",
        "Why did my ride reminder stop?": "የጉዞ ማስታወሻዬ ለምን ቆመ?",
        "View driver profile": "የሾፌሩን መገለጫ ይመልከቱ",
        "Loading": "በመጫን ላይ",
        "Any additional comments? (optional)": "ተጨማሪ አስተያየት አለዎት? (አማራጭ)",
        "Add details (optional)": "ዝርዝር ያክሉ (አማራጭ)",
        "Home, Work, etc.": "ቤት፣ ስራ፣ ወዘተ",
        "Building, street, landmark…": "ህንፃ፣ ጎዳና፣ ምልክት…",
        "e.g. Tabor": "ለምሳሌ ታቦር",
        "e.g. Home to Work": "ለምሳሌ ከቤት ወደ ስራ",
        "What's this about?": "ስለምንድን ነው?",
        "Tell us what's going on…": "ምን እየሆነ እንዳለ ይንገሩን…",
        "Tap to refresh. Hold to fully update.": "ለማደስ ይንኩ። ሙሉ ለሙሉ ለማዘመን ይያዙ።",
        "Refresh": "አድስ",
        "Emergency SOS": "የአደጋ ጊዜ SOS",
        "Promo code": "የማስተዋወቂያ ኮድ",
        "Remove": "አስወግድ",
        "Close": "ዝጋ",
        "Tip your driver": "ሾፌርዎን ይሸልሙ",
        "Tip amount": "የሽልማት መጠን",
        "From your bank message": "ከባንክዎ መልእክት"
    };
    var RULES = [   // [pattern, replacement] for text with numbers in it
        [/^about ETB (\d+) off$/, 'ወደ ETB $1 ቅናሽ'],
        [/^(\d+(?:\.\d+)?)% off your fare$/, 'ከዋጋዎ $1% ቅናሽ'],
        [/^ETB (\d+(?:\.\d+)?) off your fare$/, 'ከዋጋዎ ETB $1 ቅናሽ'],
        [/^ETB (\d+(?:\.\d+)?) off$/, 'ETB $1 ቅናሽ'],
        [/^Approved · (.+)$/, 'ጸድቋል · $1'],
        [/^(\d+(?:\.\d+)?)% off \(most ETB (\d+(?:\.\d+)?)\)$/, '$1% ቅናሽ (እስከ ETB $2)'],
        [/^(\d+(?:\.\d+)?)% off$/, '$1% ቅናሽ'],
        [/^fr ETB (\d+)$/, 'ከ ETB $1'],
        [/^~(\d+) min away · (\d+) seats?$/, '~$1 ደቂቃ ይርቃል · $2 መቀመጫ'],
        [/^No drivers online · (\d+) seats?$/, 'ሾፌር በመስመር ላይ የለም · $1 መቀመጫ'],
        [/^Drivers available · (\d+) seats?$/, 'ሾፌሮች አሉ · $1 መቀመጫ'],
        [/^~(\d+) min$/, '~$1 ደቂቃ'],
        [/^(\d+(?:\.\d+)?) km · (\d+) mins?$/, '$1 ኪ.ሜ · $2 ደቂቃ'],
        [/^(\d+(?:\.\d+)?) km$/, '$1 ኪ.ሜ']
    ];

    function lang() { try { return localStorage.getItem(KEY) === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
    function strip(s) { var m = /^([^A-Za-z0-9'"~]*)([\s\S]*?)([^A-Za-z0-9'"…).?]*)$/.exec(s); return m ? m : [s, '', s, '']; }

    function lookup(raw, table) {
        var t = raw.replace(/\s+/g, ' ').trim();
        if (!t) return null;
        if (table[t]) return table[t];
        var p = strip(t);                      // leading/trailing emoji, arrows, "+" etc. are kept as they are
        if (p[2] && p[2] !== t && table[p[2]]) return p[1] + table[p[2]] + p[3];
        if (table === AM) for (var i = 0; i < RULES.length; i++) if (RULES[i][0].test(t)) return t.replace(RULES[i][0], RULES[i][1]);
        return null;
    }

    var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, SELECT: 1, OPTION: 1, NOSCRIPT: 1 };   // INPUT has no text to translate, only its placeholder
    function skipEl(el) {
        for (; el && el.nodeType === 1; el = el.parentNode) {
            if (SKIP[el.tagName]) return true;
            if (el.classList && (el.classList.contains('pac-container') || el.id === 'map' || el.hasAttribute('data-no-i18n'))) return true;
        }
        return false;
    }

    var store = new WeakMap();   // text node -> { orig, shown }
    function textNode(n, to) {
        var rec = store.get(n);
        if (rec && n.nodeValue !== rec.shown) rec = null;        // something else rewrote it: treat as new English text
        if (to === 'am') {
            var src = rec ? rec.orig : n.nodeValue;
            var tr = lookup(src, AM);
            if (!tr) { if (rec) store.delete(n); return; }
            var lead = /^\s*/.exec(src)[0], trail = /\s*$/.exec(src)[0];
            var out = lead + tr + trail;
            if (n.nodeValue !== out) n.nodeValue = out;
            store.set(n, { orig: src, shown: out });
        } else if (rec) {
            n.nodeValue = rec.orig; store.delete(n);
        }
    }
    function attrs(el, to) {
        ['placeholder', 'title', 'aria-label'].forEach(function (a) {
            var o = 'data-i18n-o-' + a, cur = el.getAttribute(a);
            if (cur == null) return;
            if (to === 'am') {
                var src = el.hasAttribute(o) && el.getAttribute(a) === el.getAttribute(o + '-shown') ? el.getAttribute(o) : cur;
                var tr = lookup(src, AM_ATTR);
                if (tr) { el.setAttribute(o, src); el.setAttribute(o + '-shown', tr); el.setAttribute(a, tr); }
            } else if (el.hasAttribute(o)) {
                if (cur === el.getAttribute(o + '-shown')) el.setAttribute(a, el.getAttribute(o));
                el.removeAttribute(o); el.removeAttribute(o + '-shown');
            }
        });
    }

    function walk(root, to) {
        if (!root) return;
        if (root.nodeType === 1 && root.tagName === 'TEXTAREA') { if (!skipEl(root.parentNode)) attrs(root, to); return; }
        if (skipEl(root.nodeType === 1 ? root : root.parentNode)) return;
        if (root.nodeType === 3) { textNode(root, to); return; }
        if (root.nodeType !== 1) return;
        attrs(root, to);
        var tw = document.createTreeWalker(root, 1 | 4, { acceptNode: function (n) {
            if (n.nodeType === 1) {
                if (n.tagName === 'TEXTAREA') { if (!skipEl(n.parentNode)) attrs(n, to); return 2; }   // placeholder only, never its value
                return skipEl(n) ? 2 : 1;                      // 2 = reject the whole subtree
            }
            return 1;
        } });
        for (var n = tw.nextNode(); n; n = tw.nextNode()) { if (n.nodeType === 3) textNode(n, to); else attrs(n, to); }
    }

    var obs = null;
    function run(roots) {
        var to = lang();
        roots.forEach(function (r) { walk(r, to); });
        if (obs) obs.takeRecords();                // our own edits are not news
    }

    function setLang(l) {
        try { localStorage.setItem(KEY, l); } catch (e) { /* not saved: still applies for this visit */ }
        document.documentElement.setAttribute('lang', l);
        run([document.body]);
        updateButton();
        try { window.dispatchEvent(new CustomEvent('etel:lang', { detail: l })); } catch (e) {}
    }

    function updateButton() {
        var b = document.getElementById('ride-lang-btn');
        if (!b) return;
        var am = lang() === 'am';
        b.textContent = am ? 'EN' : 'አማ';
        b.setAttribute('aria-label', am ? 'Switch to English' : 'ወደ አማርኛ ቀይር');
        b.setAttribute('data-no-i18n', '1');
        b.title = am ? 'English' : 'አማርኛ';
    }
    function addButton() {
        if (document.getElementById('ride-lang-btn')) return;
        var host = document.querySelector('.ride-header-user');
        if (!host) return;
        var b = document.createElement('button');
        b.type = 'button'; b.id = 'ride-lang-btn'; b.className = 'ride-theme-btn ride-lang-btn';
        b.addEventListener('click', function () { setLang(lang() === 'am' ? 'en' : 'am'); });
        host.insertBefore(b, host.firstChild);
        updateButton();
    }

    function start() {
        addButton();
        if (lang() === 'am') document.documentElement.setAttribute('lang', 'am');
        run([document.body]);
        var pending = [], timer = 0;
        obs = new MutationObserver(function (recs) {
            for (var i = 0; i < recs.length; i++) {
                var r = recs[i];
                if (r.type === 'childList') { for (var j = 0; j < r.addedNodes.length; j++) pending.push(r.addedNodes[j]); }
                else if (r.type === 'characterData') pending.push(r.target);
                else if (r.type === 'attributes') pending.push(r.target);
            }
            if (!timer && lang() === 'am') timer = setTimeout(function () {
                timer = 0; var batch = pending; pending = [];
                run(batch.filter(function (n) { return n && n.isConnected; }));
            }, 30);
            else if (lang() !== 'am') pending = [];
        });
        obs.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['placeholder', 'title', 'aria-label'] });
        var tries = 0, iv = setInterval(function () { addButton(); updateButton(); if (++tries > 40) clearInterval(iv); }, 750);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    window.EtelRideI18n = { setLang: setLang, lang: lang };
})();
