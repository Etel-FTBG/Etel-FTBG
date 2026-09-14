# 🚀 DEPLOY ETEL TO YOUR DOMAIN
## Step-by-Step Guide for ethelmarket.com

---

## 📋 WHAT YOU'LL UPLOAD

Upload these files to: `ethelmarket.com/etel/`

```
etel/
├── index.html              ← Main interface
├── etel-engine.js          ← AI engine
├── config.js               ← Your configuration (NEW)
├── manifest.json           ← PWA config
├── sw.js                   ← Service worker
├── skills/
│   └── registry.json       ← 27 AI skills
├── icons/
│   ├── icon-192.png        ← Your coin logo
│   └── icon-512.png        ← Larger logo
└── api/
    └── api.php             ← Database API (you already have this)
```

---

## STEP 1: UPDATE CONFIG.JS

Open `config.js` and verify these settings:

```javascript
const ETEL_CONFIG = {
  domain: 'https://ethelmarket.com',
  apiUrl: 'https://ethelmarket.com/etel/api/api.php',
  
  ai: {
    engine: 'ollama',
    ollamaUrl: 'http://localhost:11434',  // Your PC for now
    ollamaModel: 'llama3',
  },
  
  // ... rest is already configured
};
```

✅ This is already set up for you!

---

## STEP 2: UPDATE INDEX.HTML

Open `index.html` and add this **before the closing `</body>` tag**:

```html
<!-- ETEL Configuration -->
<script src="config.js"></script>

<!-- ETEL Engine -->
<script src="etel-engine.js"></script>

<!-- ETEL Initialization -->
<script>
let etelAI;
let conversationHistory = [];

async function initEtel() {
  // Use config from config.js
  const config = {
    engine: ETEL_CONFIG.ai.engine,
    ollamaUrl: ETEL_CONFIG.ai.ollamaUrl,
    ollamaModel: ETEL_CONFIG.ai.ollamaModel,
    agentName: ETEL_CONFIG.agent.name,
    agentPersona: ETEL_CONFIG.agent.persona,
    memoryMode: ETEL_CONFIG.memory.mode,
    saveToDatabase: ETEL_CONFIG.memory.saveToDatabase,
    apiUrl: ETEL_CONFIG.apiUrl
  };
  
  etelAI = new EtelAI(config);
  await etelAI.init();
  
  console.log('✅ ETEL Engine initialized');
  console.log('📊 Skills loaded:', await etelAI.db.getSkills());
  
  // Update UI
  updateSkillCount();
  loadMemoryEntries();
  updateLogo();
}

// Update logo everywhere
function updateLogo() {
  const logoUrl = ETEL_CONFIG.branding.logo;
  document.getElementById('logo-img-el').src = logoUrl;
  document.getElementById('about-logo-img').src = logoUrl;
  
  // Update welcome logo if exists
  const welcomeLogo = document.querySelector('.welcome-logo img');
  if (welcomeLogo) welcomeLogo.src = logoUrl;
}

// Update skill count
async function updateSkillCount() {
  const skills = await etelAI.db.getSkills({ installed: true });
  document.getElementById('skill-count').textContent = skills.length;
  
  // Update skills grid
  const grid = document.getElementById('installed-skills-grid');
  if (grid) {
    grid.innerHTML = skills.map(skill => `
      <div class="skill-card installed">
        <div class="skill-card-status on">✓</div>
        <div class="skill-card-name">${skill.name}</div>
        <div class="skill-card-cat">${skill.category}</div>
        <div class="skill-card-desc">${skill.description}</div>
      </div>
    `).join('');
  }
}

// Load memory entries
async function loadMemoryEntries() {
  const memories = await etelAI.db.getMemories(50);
  const container = document.getElementById('memory-entries');
  
  if (!container) return;
  
  if (memories.length === 0) {
    container.innerHTML = '<div class="mem-entry">No memories yet. Start chatting!</div>';
    return;
  }
  
  container.innerHTML = memories.map(mem => `
    <div class="mem-entry">
      <div class="mem-date">${new Date(mem.timestamp).toLocaleString()}</div>
      <div class="mem-text">${mem.content.substring(0, 200)}...</div>
      <div class="mem-tags">
        ${mem.tags.map(tag => `<span class="mem-tag">${tag}</span>`).join('')}
      </div>
    </div>
  `).join('');
  
  // Update stats
  const dbMem = document.getElementById('db-mem');
  if (dbMem) dbMem.textContent = memories.length;
}

// Send message function
async function sendMsg() {
  const input = document.getElementById('msg-input');
  const text = input.value.trim();
  if (!text) return;
  
  // Add user message to UI
  addMessage('user', text);
  input.value = '';
  
  // Show thinking indicator
  showThinking();
  
  try {
    // Get AI response
    const response = await etelAI.generate(text, conversationHistory);
    
    // Add AI message to UI
    hideThinking();
    addMessage('agent', response);
    
    // Save to memory
    const skill = await etelAI.matchSkill(text);
    await etelAI.saveToMemory(text, response, skill);
    
    // Update conversation history
    conversationHistory.push(
      { role: 'user', content: text },
      { role: 'assistant', content: response }
    );
    
    // Update memory view if visible
    loadMemoryEntries();
    
  } catch (error) {
    hideThinking();
    addMessage('agent', '⚠️ Error: ' + error.message);
  }
}

// Show thinking indicator
function showThinking() {
  const container = document.getElementById('msgs-container');
  const thinking = document.createElement('div');
  thinking.id = 'thinking-indicator';
  thinking.className = 'msg agent';
  thinking.innerHTML = `
    <div class="msg-avatar agent">
      <img src="${ETEL_CONFIG.branding.logo}" alt="ETEL">
    </div>
    <div class="msg-body">
      <div class="thinking-bubble">
        <div class="dot-pulse">
          <span></span><span></span><span></span>
        </div>
        <span class="thinking-text">Thinking...</span>
      </div>
    </div>
  `;
  container.appendChild(thinking);
  container.scrollTop = container.scrollHeight;
}

// Hide thinking indicator
function hideThinking() {
  const thinking = document.getElementById('thinking-indicator');
  if (thinking) thinking.remove();
}

// Add message to UI
function addMessage(role, content) {
  const container = document.getElementById('msgs-container');
  const msg = document.createElement('div');
  msg.className = `msg ${role}`;
  
  const avatar = role === 'agent' 
    ? `<img src="${ETEL_CONFIG.branding.logo}" alt="ETEL">`
    : 'U';
  
  msg.innerHTML = `
    <div class="msg-avatar ${role}">${avatar}</div>
    <div class="msg-body">
      <div class="msg-content">${formatMessage(content)}</div>
    </div>
  `;
  
  container.appendChild(msg);
  container.scrollTop = container.scrollHeight;
}

// Format message (markdown-style)
function formatMessage(text) {
  return text
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/`(.+?)`/g, '<code>$1</code>')
    .replace(/```(\w+)?\n([\s\S]+?)```/g, '<pre>$2</pre>')
    .replace(/\n/g, '<br>');
}

// Clear chat
function clearChat() {
  if (!confirm('Clear all messages?')) return;
  document.getElementById('msgs-container').innerHTML = '';
  conversationHistory = [];
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', initEtel);

// Make sendMsg available globally
window.sendMsg = sendMsg;
</script>
```

---

## STEP 3: UPLOAD FILES TO YOUR SERVER

### Option A: Using cPanel File Manager

1. Log in to your cPanel
2. Go to **File Manager**
3. Navigate to `public_html/`
4. Create folder: `etel`
5. Upload all files:
   - `index.html`
   - `etel-engine.js`
   - `config.js`
   - `manifest.json`
   - `sw.js`
   - `skills/` folder (with registry.json)
   - `icons/` folder (with your logos)

### Option B: Using FTP (FileZilla)

1. Connect to your server via FTP
2. Navigate to `public_html/`
3. Create folder: `etel`
4. Upload all files

---

## STEP 4: VERIFY DATABASE API

Your `api.php` should already be at:
```
ethelmarket.com/etel/api/api.php
```

Test it by visiting:
```
https://ethelmarket.com/etel/api/api.php?path=health
```

Should return:
```json
{
  "ok": true,
  "data": {
    "status": "ETEL API running",
    "version": "1.0.0",
    "db": "EtelAI"
  }
}
```

✅ If you see this, your database is connected!

---

## STEP 5: TEST YOUR ETEL

1. **Start Ollama on your PC:**
   ```cmd
   ollama serve
   ```

2. **Open your ETEL:**
   ```
   https://ethelmarket.com/etel/
   ```

3. **Open DevTools (F12) → Console**
   
   Should see:
   ```
   ✅ ETEL Engine initialized
   📊 Skills loaded: [27 skills]
   ```

4. **Test AI:**
   
   Type in chat:
   ```
   Write Python code to print hello world
   ```
   
   Should get real AI response!

---

## STEP 6: CONFIGURE SETTINGS

In your ETEL interface:

1. Click **Settings** (bottom left)
2. Verify:
   - Engine: **Ollama Local**
   - Server URL: `http://localhost:11434`
   - Model: `llama3`
3. Click **Save**

---

## ✅ VERIFICATION CHECKLIST

- [ ] Ollama installed on PC
- [ ] Model downloaded: `ollama pull llama3`
- [ ] Ollama running: `ollama serve`
- [ ] Files uploaded to `ethelmarket.com/etel/`
- [ ] `config.js` updated with your domain
- [ ] `index.html` updated with initialization code
- [ ] Database API working (test health endpoint)
- [ ] ETEL opens at `https://ethelmarket.com/etel/`
- [ ] Console shows "ETEL Engine initialized"
- [ ] Skills show: 27 installed
- [ ] AI responds to test message
- [ ] Memory saves conversations

---

## 🐛 TROUBLESHOOTING

### "ETEL Engine not initialized"

**Check:**
1. `config.js` is uploaded
2. `etel-engine.js` is uploaded
3. Scripts are loaded in correct order
4. Open Console (F12) for errors

### "Ollama connection failed"

**Check:**
1. Ollama is running: `ollama serve`
2. Model is downloaded: `ollama list`
3. Settings → Ollama URL is `http://localhost:11434`

### "Skills not loading"

**Check:**
1. `skills/registry.json` is uploaded
2. File is valid JSON (no syntax errors)
3. Console for errors

### "Database not saving"

**Check:**
1. `api.php` is accessible
2. Database credentials are correct
3. Test health endpoint works

---

## 🎯 NEXT STEPS

Once everything works locally:

1. ✅ Test all 27 skills
2. ✅ Customize branding
3. ✅ Add custom skills
4. ✅ Share with friends (they need Ollama too)
5. ✅ Plan cloud server for 24/7 access

---

## 📞 NEED HELP?

If you get stuck:
1. Check Console (F12) for errors
2. Test each component separately
3. Verify Ollama is running
4. Check database connection

---

*Your ETEL is now running on YOUR domain with YOUR database!* 🚀
