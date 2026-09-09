// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - DRIVER INTERFACE JAVASCRIPT
// ═══════════════════════════════════════════════════════════════════

let currentLang = 'en';

// ─── INITIALIZATION ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    navigate('home');
});

// ─── NAVIGATION ─────────────────────────────────────────────────────
function navigate(page) {
    // Update active nav link
    document.querySelectorAll('.nav-link').forEach(link => {
        link.classList.remove('active');
        if (link.dataset.page === page) {
            link.classList.add('active');
        }
    });
    
    // Load page content
    const content = document.getElementById('mainContent');
    
    switch(page) {
        case 'home':
            content.innerHTML = getHomePage();
            break;
        case 'check':
            content.innerHTML = getCheckPage();
            break;
        case 'about':
            content.innerHTML = getAboutPage();
            break;
        default:
            content.innerHTML = getHomePage();
    }
}

// ─── PAGE TEMPLATES ─────────────────────────────────────────────────
function getHomePage() {
    return `
        <div class="container">
            <h1 class="page-title" data-t="Welcome to Etel Madeya|እንኳን ወደ ኢትል ማደያ በደህና መጡ">Welcome to Etel Madeya</h1>
            <p class="page-subtitle" data-t="Modern Gas Station Queue Management|ዘመናዊ የነዳጅ ወረፋ አስተዳደር">Modern Gas Station Queue Management</p>
            
            <div style="max-width: 800px; margin: 0 auto;">
                <div class="card">
                    <h2 class="card-title" data-t="Quick Actions|ፈጣን እርምጃዎች">Quick Actions</h2>
                    <div style="display: grid; grid-template-columns: 1fr; gap: 20px;">
                        <button onclick="navigate('check')" class="btn btn-primary btn-block">
                            🔍 <span data-t="Check Position|ቦታ ፈልግ">Check Position</span>
                        </button>
                    </div>
                </div>
                
                <div class="card">
                    <h2 class="card-title" data-t="How It Works|እንዴት እንደሚሰራ">How It Works</h2>
                    <ol style="line-height: 2;">
                        <li data-t="Controller registers your vehicle|ተቆጣጣሪው ተሽከርካሪዎን ያስመዝግባል">Controller registers your vehicle</li>
                        <li data-t="You receive your queue position|የወረፋ ቦታዎን ይቀበላሉ">You receive your queue position</li>
                        <li data-t="Check your position anytime|ቦታዎን በማንኛውም ጊዜ ያረጋግጡ">Check your position anytime</li>
                        <li data-t="Get notified when your turn is near|ተራዎ ሲቀርብ ማሳወቂያ ይደርስዎታል">Get notified when your turn is near</li>
                    </ol>
                </div>
                
                <div class="card">
                    <h2 class="card-title" data-t="Important Notice|አስፈላጊ ማስታወቂያ">Important Notice</h2>
                    <p style="color: var(--gold); line-height: 1.8;">
                        <strong data-t="Registration is done by station controllers only.|ምዝገባ በጣቢያ ተቆጣጣሪዎች ብቻ ይከናወናል።">Registration is done by station controllers only.</strong><br>
                        <span data-t="Please visit the station to register your vehicle.|እባክዎ ተሽከርካሪዎን ለመመዝገብ ጣቢያውን ይጎብኙ።">Please visit the station to register your vehicle.</span><br>
                        <span data-t="Once registered, you can check your position here anytime.|አንዴ ከተመዘገቡ በኋላ ቦታዎን በማንኛውም ጊዜ እዚህ ማረጋገጥ ይችላሉ።">Once registered, you can check your position here anytime.</span>
                    </p>
                </div>
            </div>
        </div>
    `;
}

function getCheckPage() {
    return `
        <div class="container">
            <h1 class="page-title" data-t="Check Your Position|ቦታዎን ያረጋግጡ">Check Your Position</h1>
            <p class="page-subtitle" data-t="Enter your plate number to see your queue position|የታርጋ ቁጥርዎን ያስገቡ">Enter your plate number to see your queue position</p>
            
            <div style="max-width: 600px; margin: 0 auto;">
                <div class="card">
                    <form onsubmit="checkPosition(event)">
                        <div class="form-group">
                            <label for="checkPlate" data-t="Plate Number|የታርጋ ቁጥር">Plate Number</label>
                            <input type="text" id="checkPlate" class="form-control" placeholder="AA-3-12345" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            🔍 <span data-t="Check Position|ቦታ ፈልግ">Check Position</span>
                        </button>
                    </form>
                </div>
                
                <div id="positionResult"></div>
            </div>
        </div>
    `;
}

function getAboutPage() {
    return `
        <div class="container">
            <h1 class="page-title" data-t="About Etel Madeya|ስለ ኢትል ማደያ">About Etel Madeya</h1>
            
            <div style="max-width: 800px; margin: 0 auto;">
                <div class="card">
                    <h2 class="card-title">Our Mission</h2>
                    <p>Etel Madeya provides a modern, efficient queue management system for gas stations across Ethiopia. We help reduce wait times, improve fairness, and make fuel distribution more transparent.</p>
                </div>
                
                <div class="card">
                    <h2 class="card-title">Features</h2>
                    <ul style="line-height: 2;">
                        <li>✅ Real-time queue position tracking</li>
                        <li>✅ Fair queue management</li>
                        <li>✅ Multiple vehicle type support</li>
                        <li>✅ SMS/Alert notifications</li>
                        <li>✅ Bilingual support (English & Amharic)</li>
                    </ul>
                </div>
                
                <div class="card">
                    <h2 class="card-title">Contact Us</h2>
                    <p>📍 Addis Ababa, Ethiopia</p>
                    <p>☎ +251-911-000-000</p>
                    <p>✉ info@etelmadeya.com</p>
                </div>
            </div>
        </div>
    `;
}

// ─── API FUNCTIONS ──────────────────────────────────────────────────
async function checkPosition(event) {
    event.preventDefault();
    
    const plate = document.getElementById('checkPlate').value.trim();
    const resultDiv = document.getElementById('positionResult');
    
    resultDiv.innerHTML = '<div class="card"><p style="text-align:center;">Loading...</p></div>';
    
    try {
        const response = await fetch(`${API}&action=check_position&plate=${encodeURIComponent(plate)}`);
        const data = await response.json();
        
        if (data.success) {
            const v = data.vehicle;
            let html = `
                <div class="card">
                    <div class="position-result">
                        <h2>Your Position</h2>
                        <div class="position-number">#${v.position}</div>
                        <p class="position-label">${v.ahead} vehicles ahead of you</p>
                        
                        ${v.alert ? '<div class="alert alert-warning">⚠️ Your turn is coming soon! Please be ready.</div>' : ''}
                        
                        <div class="vehicle-info">
                            <p><strong>Plate:</strong> ${v.plate_number}</p>
                            <p><strong>Vehicle Type:</strong> ${v.vehicle_type_name} (${v.vehicle_type_name_am})</p>
                            <p><strong>Queue Type:</strong> ${v.queue_type.toUpperCase()}</p>
                            <p><strong>Token:</strong> ${v.token}</p>
                            <p><strong>Station:</strong> ${v.station_name}</p>
                        </div>
                        
                        <button onclick="checkPosition(event)" class="btn btn-primary">🔄 Refresh</button>
                    </div>
                </div>
            `;
            resultDiv.innerHTML = html;
            
            // Now load the active queue for this driver's controller
            loadActiveQueue(v.controller_id, v.plate_number);
        } else {
            resultDiv.innerHTML = `
                <div class="card">
                    <div class="alert alert-warning">
                        ❌ ${data.message || 'Vehicle not found in queue'}
                    </div>
                </div>
            `;
        }
    } catch (error) {
        resultDiv.innerHTML = `
            <div class="card">
                <div class="alert alert-warning">
                    ❌ Error checking position. Please try again.
                </div>
            </div>
        `;
    }
}

// ─── ACTIVE QUEUE VIEW FOR DRIVERS ──────────────────────────────────
let driverQueuePage = 1;
let driverQueuePages = [];

async function loadActiveQueue(controllerId, myPlate) {
    const container = document.getElementById('positionResult');
    
    // Add a loading section below the position card
    const queueSection = document.createElement('div');
    queueSection.id = 'activeQueueSection';
    queueSection.innerHTML = '<div class="card"><p style="text-align:center;">Loading active queue...</p></div>';
    container.appendChild(queueSection);
    
    try {
        const response = await fetch(`${API}&action=driver_active_queue&controller_id=${controllerId}`);
        const data = await response.json();
        
        if (data.success && data.queue.length > 0) {
            driverQueuePages = data.pages || [data.queue];
            driverQueuePage = 1;
            
            // Find which page the driver's plate is on
            const myPlateUpper = myPlate.toUpperCase();
            for (let i = 0; i < driverQueuePages.length; i++) {
                const found = driverQueuePages[i].some(v => v.plate_number.toUpperCase() === myPlateUpper);
                if (found) {
                    driverQueuePage = i + 1;
                    break;
                }
            }
            
            renderDriverQueue(myPlate, data.station);
        } else {
            queueSection.innerHTML = '';
        }
    } catch (error) {
        console.error('Error loading active queue:', error);
        document.getElementById('activeQueueSection').innerHTML = '';
    }
}

function renderDriverQueue(myPlate, station) {
    const section = document.getElementById('activeQueueSection');
    if (!section) return;
    
    const totalPages = driverQueuePages.length;
    const pageData = driverQueuePages[driverQueuePage - 1] || [];
    const myPlateUpper = myPlate.toUpperCase();
    const stationName = station ? station.station_name : 'Station';
    
    let tableRows = pageData.map(v => {
        const isMe = v.plate_number.toUpperCase() === myPlateUpper;
        const rowClass = isMe ? 'style="background: rgba(212,175,55,0.25); font-weight: bold;"' : '';
        const marker = isMe ? ' ← YOU' : '';
        return `<tr ${rowClass}>
            <td>#${v.display_position}</td>
            <td>${v.plate_number}${marker}</td>
            <td>${v.icon} ${v.vehicle_type_name}</td>
            <td>${v.queue_type.toUpperCase()}</td>
        </tr>`;
    }).join('');
    
    // Build page nav
    let pageNav = '';
    if (totalPages > 1) {
        let buttons = '';
        for (let i = 1; i <= totalPages; i++) {
            const active = i === driverQueuePage ? 'background: var(--gold, #d4af37); color: #000;' : '';
            buttons += `<button onclick="goDriverPage(${i}, '${myPlate.replace(/'/g, "\\'")}')" 
                         style="padding: 6px 14px; margin: 2px; border: 1px solid var(--gold, #d4af37); 
                                border-radius: 6px; cursor: pointer; ${active}">${i}</button>`;
        }
        pageNav = `<div style="text-align: center; margin-top: 12px;">
            <strong>Import Page:</strong> ${buttons}
            <div style="margin-top: 6px; font-size: 0.85em; opacity: 0.7;">
                Page ${driverQueuePage} of ${totalPages} — ${pageData.length} vehicles on this page
            </div>
        </div>`;
    }
    
    section.innerHTML = `
        <div class="card" style="margin-top: 16px;">
            <h2 class="card-title" data-t="Active Queue — ${stationName}|ንቁ ወረፋ — ${stationName}">
                Active Queue — ${stationName}
            </h2>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.95em;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--gold, #d4af37);">
                            <th style="padding: 8px; text-align: left;">#</th>
                            <th style="padding: 8px; text-align: left;">Plate</th>
                            <th style="padding: 8px; text-align: left;">Type</th>
                            <th style="padding: 8px; text-align: left;">Queue</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${tableRows}
                    </tbody>
                </table>
            </div>
            ${pageNav}
        </div>
    `;
}

function goDriverPage(page, myPlate) {
    driverQueuePage = page;
    // Re-read station from the existing section context
    renderDriverQueue(myPlate, null);
}

// ─── LANGUAGE TOGGLE ────────────────────────────────────────────────
function toggleLang() {
    currentLang = currentLang === 'en' ? 'am' : 'en';
    document.getElementById('langBtn').textContent = currentLang === 'en' ? '🇪🇹 አማ' : '🇬🇧 Eng';
    
    // Update all translatable elements
    document.querySelectorAll('[data-t]').forEach(el => {
        const translations = el.dataset.t.split('|');
        el.textContent = currentLang === 'en' ? translations[0] : translations[1];
    });
    
    document.querySelectorAll('[data-t-ph]').forEach(el => {
        const translations = el.dataset.tPh.split('|');
        el.placeholder = currentLang === 'en' ? translations[0] : translations[1];
    });
}

// ─── NOTIFICATION ───────────────────────────────────────────────────
function showNotification(message, type = 'success') {
    const notification = document.getElementById('notification');
    notification.textContent = message;
    notification.className = `notification ${type}`;
    notification.style.display = 'block';
    
    setTimeout(() => {
        notification.style.display = 'none';
    }, 5000);
}
