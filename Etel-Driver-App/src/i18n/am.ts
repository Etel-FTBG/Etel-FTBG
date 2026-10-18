// English -> Amharic (first draft; a native speaker should review before release).
// Keys are the exact English text shown in the app. Leading/trailing emoji and arrows are ignored when matching,
// so "🧭 Navigate" is found under 'Navigate'. Text with no entry stays English.
export const AM: Record<string, string> = {
  // shared words
  'Cancel': 'ሰርዝ', 'Back': 'ተመለስ', 'Done': 'ተጠናቋል', 'Send': 'ላክ', 'Submit': 'ላክ', 'Settings': 'ቅንብሮች', 'Language': 'ቋንቋ',
  'Today': 'ዛሬ', 'History': 'ታሪክ', 'Loading…': 'በመጫን ላይ…', 'Error': 'ስህተት',
  'Requests': 'ጥያቄዎች', 'Earnings': 'ገቢ', 'Chats': 'መልዕክቶች', 'Profile': 'መገለጫ', 'Notifications': 'ማሳወቂያዎች',
  'Pickup': 'መነሻ', 'Drop-off': 'መድረሻ', 'Dropoff': 'መድረሻ', 'Passenger': 'ተሳፋሪ', 'Fare': 'ዋጋ', 'Distance': 'ርቀት', 'Call': 'ደውል', 'Chat': 'ውይይት',
  'From': 'ከ', 'To': 'ወደ', 'Color': 'ቀለም', 'Type': 'ዓይነት', 'Make': 'ሞዴል', 'Plate': 'ታርጋ', 'Online': 'በመስመር ላይ',
  // errors, offline
  'Something went wrong': 'የሆነ ችግር ተፈጥሯል',
  'The app hit an unexpected problem. Your online status and trips are safe.': 'አፕሊኬሽኑ ያልተጠበቀ ችግር አጋጥሞታል። የመስመር ላይ ሁኔታዎና ጉዞዎችዎ አስተማማኝ ናቸው።',
  'Try again': 'እንደገና ሞክር', 'No connection — retrying': 'ግንኙነት የለም — እንደገና በመሞከር ላይ',
  // app lock
  'ETEL Drive is locked': 'ኢቴል ሾፌር ተቆልፏል', 'Use your fingerprint or screen lock to continue.': 'ለመቀጠል የጣት አሻራዎን ወይም የስክሪን መቆለፊያዎን ይጠቀሙ።', 'Sign out instead': 'በምትኩ ውጣ',
  // login
  'ETEL DRIVER': 'ኢቴል ሾፌር', 'Welcome back, Driver': 'እንኳን ደህና መጡ፣ ሾፌር', 'Sign in to continue.': 'ለመቀጠል ይግቡ።', 'Forgot password?': 'የይለፍ ቃል ረሱ?',
  'Unable to sign in': 'መግባት አልተቻለም', 'Signing in…': 'በመግባት ላይ…', 'SIGN IN': 'ግባ', "Don't have a driver account?": 'የሾፌር አካውንት የለዎትም?',
  'Apply to drive': 'ለመንዳት ያመልክቱ', 'Secure ETEL Driver': 'ደህንነቱ የተጠበቀ ኢቴል ሾፌር', 'Phone number': 'ስልክ ቁጥር', 'Password': 'የይለፍ ቃል',
  // requests
  '· Ready for rides': '· ለጉዞ ዝግጁ', 'NEW RIDE REQUEST': 'አዲስ የጉዞ ጥያቄ', 'Decline': 'አልቀበልም', 'ONLINE': 'በመስመር ላይ', 'OFFLINE': 'ከመስመር ውጪ',
  'Waiting for nearby requests': 'በአቅራቢያ ያሉ ጥያቄዎችን በመጠበቅ ላይ', 'Go offline': 'ከመስመር ውጣ', 'You are not receiving ride requests.': 'የጉዞ ጥያቄዎችን እየተቀበሉ አይደለም።',
  "Today's earnings": 'የዛሬ ገቢ', "Today's trips": 'የዛሬ ጉዞዎች', 'Street Hail — link a customer': 'የመንገድ ጥሪ — ደንበኛ ያገናኙ',
  'Slide to Accept': 'ለመቀበል ያንሸራትቱ', 'Slide to go online': 'መስመር ላይ ለመግባት ያንሸራትቱ',
  'Permission needed': 'ፈቃድ ያስፈልጋል', 'Location required': 'አካባቢ ያስፈልጋል', 'ETEL Drive needs your location to go online.': 'መስመር ላይ ለመግባት ኢቴል ሾፌር አካባቢዎን ይፈልጋል።',
  'Cancel ride?': 'ጉዞውን ይሰርዙ?', 'This will reopen the request for other drivers.': 'ይህ ጥያቄውን ለሌሎች ሾፌሮች እንደገና ይከፍተዋል።', 'Yes, cancel': 'አዎ፣ ሰርዝ',
  // street hail
  'Street Hail': 'የመንገድ ጥሪ', 'Link a walk-up customer to start a trip with them right now.': 'አሁን ጉዞ ለመጀመር በመንገድ ያገኙትን ደንበኛ ያገናኙ።',
  'Open trip (GPS meter)': 'ክፍት ጉዞ (በጂፒኤስ ሜትር)', 'By distance': 'በርቀት', 'Start Trip': 'ጉዞ ጀምር', 'Customer phone or EtelID': 'የደንበኛ ስልክ ወይም ኢቴል-አይዲ', 'Distance (km)': 'ርቀት (ኪ.ሜ)',
  // active ride
  'TRIP COMPLETED': 'ጉዞ ተጠናቋል', 'Thanks for rating!': 'ደረጃ ስለሰጡ እናመሰግናለን!', 'ETEL Driver': 'ኢቴል ሾፌር', 'Map unavailable for this ride': 'ለዚህ ጉዞ ካርታ አይገኝም',
  'GOING TO PICKUP': 'ወደ መነሻ በመሄድ ላይ', 'Navigate': 'አቅጣጫ ምራኝ', 'TRIP IN PROGRESS': 'ጉዞ በመካሄድ ላይ', 'Navigate to Destination': 'ወደ መድረሻ አቅጣጫ ምራኝ',
  'Arrived — Bill Customer': 'ደርሰናል — ደንበኛውን ያስከፍሉ', 'ARRIVED': 'ደርሰናል', 'Waiting for passenger to confirm arrival…': 'ተሳፋሪው መድረሱን እስኪያረጋግጥ በመጠበቅ ላይ…',
  'Cancel Ride': 'ጉዞ ሰርዝ', 'Base fare': 'መነሻ ዋጋ', 'Distance fare': 'የርቀት ዋጋ', 'Wait fee': 'የመጠበቂያ ክፍያ', 'Surge': 'የፍላጎት ጭማሪ',
  // earnings, goals
  'Total Earnings': 'ጠቅላላ ገቢ', 'Gross Fare': 'ጠቅላላ ዋጋ', 'Platform Fee': 'የመድረክ ክፍያ', 'Net ETB / hour (after fuel est.)': 'የተጣራ ብር / በሰዓት (ከነዳጅ ግምት በኋላ)',
  'Trip History': 'የጉዞ ታሪክ', "Trip history isn't available yet — the backend update for this hasn't been deployed to the live server.": 'የጉዞ ታሪክ እስካሁን አይገኝም — የዚህ ዝማኔ በቀጥታ አገልጋዩ ላይ ገና አልተሰራጨም።',
  'No completed trips yet.': 'እስካሁን የተጠናቀቀ ጉዞ የለም።', 'Goals & Performance': 'ግቦችና አፈጻጸም', "Today's Progress": 'የዛሬ ሂደት', 'Goal Tiers': 'የግብ ደረጃዎች',
  // wallet
  'Account & Wallet': 'አካውንትና ዋሌት', 'Available balance': 'የሚገኝ ቀሪ ሂሳብ', 'Cash Out to Etel Pay': 'ወደ ኢቴል ፔይ አውጣ', 'Cash Out': 'ገንዘብ አውጣ',
  'Manual Top-Up (bank transfer / cash deposit)': 'በእጅ መሙላት (የባንክ ዝውውር / በጥሬ ገንዘብ ማስገባት)', 'Attach Receipt Photo': 'የደረሰኝ ፎቶ ያያይዙ', 'Submit Top-Up Request': 'የመሙያ ጥያቄ ላክ',
  'No top-up requests yet.': 'እስካሁን የመሙያ ጥያቄ የለም።', 'Amount (ETB)': 'መጠን (ብር)', 'Note (optional)': 'ማስታወሻ (አማራጭ)', 'Cashed out': 'ገንዘብ ወጥቷል', 'Cashout failed.': 'ገንዘብ ማውጣት አልተሳካም።',
  'Invalid amount': 'ልክ ያልሆነ መጠን', 'Enter an amount greater than 0.': 'ከ0 የሚበልጥ መጠን ያስገቡ።', 'Receipt required': 'ደረሰኝ ያስፈልጋል', 'Attach a photo of the receipt.': 'የደረሰኙን ፎቶ ያያይዙ።',
  'Allow photo library access to attach the receipt.': 'ደረሰኙን ለማያያዝ የፎቶ ማህደር መዳረሻን ይፍቀዱ።', 'Your top-up request was submitted.': 'የመሙያ ጥያቄዎ ተልኳል።', 'Submitted': 'ተልኳል',
  // documents
  'Documents': 'ሰነዶች', 'Take Photo': 'ፎቶ አንሳ', 'Expiry date (YYYY-MM-DD)': 'የሚያበቃበት ቀን (YYYY-MM-DD)', 'Camera permission needed': 'የካሜራ ፈቃድ ያስፈልጋል',
  'Allow camera access to photograph the document.': 'ሰነዱን ለማንሳት የካሜራ መዳረሻን ይፍቀዱ።', 'Photo required': 'ፎቶ ያስፈልጋል', 'Take a photo of the document first.': 'መጀመሪያ የሰነዱን ፎቶ ያንሱ።',
  'Invalid date': 'ልክ ያልሆነ ቀን', 'Enter the expiry date as YYYY-MM-DD.': 'የሚያበቃበትን ቀን በYYYY-MM-DD ቅርጸት ያስገቡ።', 'Your document was submitted for admin review.': 'ሰነድዎ ለአስተዳዳሪ ግምገማ ተልኳል።',
  // notifications, help, chats
  'Nothing here yet.': 'እስካሁን እዚህ ምንም የለም።', 'Help & Support': 'እርዳታና ድጋፍ', 'Emergency': 'ድንገተኛ',
  'For an emergency during a trip, use the SOS button (hold for a second) on the Requests or Active Ride screen — it alerts ETEL support immediately with your location.': 'በጉዞ ወቅት ለድንገተኛ ሁኔታ በጥያቄዎች ወይም በንቁ ጉዞ ገጽ ላይ ያለውን የ SOS አዝራር (ለአንድ ሰከንድ ይያዙ) ይጠቀሙ — ወዲያውኑ ከአካባቢዎ ጋር ለኢቴል ድጋፍ ያሳውቃል።',
  'Account issues': 'የአካውንት ችግሮች', "For account, payout, or compliance document issues, contact your onboarding admin directly — a general support channel hasn't been set up in the app yet.": 'ለአካውንት፣ ለክፍያ ወይም ለሰነድ ችግሮች ወደ አስተዳዳሪዎ በቀጥታ ይደውሉ — አጠቃላይ የድጋፍ መስመር በአፕሊኬሽኑ ውስጥ ገና አልተዘጋጀም።',
  'No active conversation': 'ንቁ ውይይት የለም', 'Chat opens automatically once you accept a ride.': 'ጉዞ ሲቀበሉ ውይይቱ በራሱ ይከፈታል።', 'typing…': 'እየጻፈ ነው…', 'Message…': 'መልዕክት…',
  // profile
  'Vehicle Information': 'የተሽከርካሪ መረጃ', 'Night Mode — better visibility at night': 'የሌሊት ሁነታ — በሌሊት የተሻለ እይታ', 'Require fingerprint / screen lock to open': 'ለመክፈት የጣት አሻራ / የስክሪን መቆለፊያ ጠይቅ',
  'Sign out': 'ውጣ', 'Not enabled': 'አልተነቃም', 'Could not verify your fingerprint or screen lock, so the lock was left off.': 'የጣት አሻራዎን ወይም የስክሪን መቆለፊያዎን ማረጋገጥ አልተቻለም፣ ስለዚህ መቆለፊያው አልበራም።',
};

// Text with numbers in it.
export const AM_RULES: Array<[RegExp, string]> = [
  [/^(\d+) trips?$/, '$1 ጉዞዎች'],
  [/^(\d+(?:\.\d+)?) km$/, '$1 ኪ.ሜ'],
  [/^~(\d+) min$/, '~$1 ደቂቃ'],
];
