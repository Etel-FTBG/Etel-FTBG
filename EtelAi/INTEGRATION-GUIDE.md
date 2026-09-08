# ETEL OS - Integration Guide
## How to Add the Engine to Your HTML

---

## STEP 1: Add the Engine Script

In your `index.html`, add this before the closing `</body>` tag:

```html
<!-- ETEL Engine -->
<script src="etel-engine.js"></script>
```

---

## STEP 2: Initialize in Your Main Script

Replace your existing JavaScript initialization with:

```javascript
// Initialize ETEL Engine
let etelAI;

async function initEtel() {
  // Load config from localStorage or use defaults
  const config = {
    engine: localStorage.getItem('etel_engine') || 'hermes',
    ollamaUrl: localStorage.getItem('etel_ollama_url') || 'http://localhost:11434',
    ollamaModel: localStorage.getItem('etel_ollama_model') || 'llama3',
    compatUrl: localStorage.getItem('etel_compat_url') || 'http://localhost:1234/v1',
    compatModel: localStorage.getItem('etel_compat_model') || 'local-model',
    agentName: localStorage.getItem('etel_agent_name') || 'ETEL Agent',
    agentPersona: localStorage.getItem('etel_persona') || 'You are ETEL, an advanced AI assistant...',
    memoryMode: localStorage.getItem('etel_memory') || 'auto'
  };
  
  etelAI = new EtelAI(config);
  await etelAI.init();
  
  console.log('✅ ETEL Engine initialized');
  
  // Update UI
  updateSkillCount();
  loadMemoryEntries();
}

// Call on page load
document.addEventListener('DOMContentLoaded', initEtel);
```

---

## STEP 3: Update Your Send Message Function

Replace your `sendMsg()` function:

```javascript
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
    await etelAI.saveToMemory(text, response);
    
    // Update conversation history
    conversationHistory.push(
      { role: 'user', content: text },
      { role: 'assistant', content: response }
    );
    
  } catch (error) {
    hideThinking();
    addMessage('agent', '⚠️ Error: ' + error.message);
  }
}

let conversationHistory = [];
```

---

## STEP 4: Update Settings Save Function

```javascript
function saveSettings() {
  // Get values from form
  const engine = document.getElementById('engine-select').value;
  const ollamaUrl = document.getElementById('ollama-url').value;
  const ollamaModel = document.getElementById('ollama-model').value;
  const compatUrl = document.getElementById('compat-url').value;
  const compatModel = document.getElementById('compat-model').value;
  const agentName = document.getElementById('agent-name').value;
  const agentPersona = document.getElementById('agent-persona').value;
  const memoryMode = document.getElementById('memory-mode').value;
  
  // Save to localStorage
  localStorage.setItem('etel_engine', engine);
  localStorage.setItem('etel_ollama_url', ollamaUrl);
  localStorage.setItem('etel_ollama_model', ollamaModel);
  localStorage.setItem('etel_compat_url', compatUrl);
  localStorage.setItem('etel_compat_model', compatModel);
  localStorage.setItem('etel_agent_name', agentName);
  localStorage.setItem('etel_persona', agentPersona);
  localStorage.setItem('etel_memory', memoryMode);
  
  // Update config
  etelAI.config = {
    engine, ollamaUrl, ollamaModel, compatUrl, compatModel,
    agentName, agentPersona, memoryMode
  };
  
  // Update UI badge
  document.getElementById('engine-badge').textContent = 
    engine === 'ollama' ? 'OLLAMA' : 
    engine === 'openai-compat' ? 'LOCAL' : 'HERMES';
  
  alert('✅ Settings saved!');
}
```

---

## STEP 5: Add Skills View Functions

```javascript
async function updateSkillCount() {
  const skills = await etelAI.db.getSkills({ installed: true });
  document.getElementById('skill-count').textContent = skills.length;
  
  // Update skills grid
  const grid = document.getElementById('installed-skills-grid');
  grid.innerHTML = skills.map(skill => `
    <div class="skill-card installed">
      <div class="skill-card-status on">✓</div>
      <div class="skill-card-name">${skill.name}</div>
      <div class="skill-card-cat">${skill.category}</div>
      <div class="skill-card-desc">${skill.description}</div>
    </div>
  `).join('');
}

async function syncSkillsFromGitHub() {
  showThinking();
  await etelAI.loadSkillsFromGitHub();
  await updateSkillCount();
  hideThinking();
  alert('✅ Skills synced!');
}
```

---

## STEP 6: Add Memory View Functions

```javascript
async function loadMemoryEntries() {
  const memories = await etelAI.db.getMemories(50);
  const container = document.getElementById('memory-entries');
  
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
  document.getElementById('db-mem').textContent = memories.length;
}

async function clearMemory() {
  if (!confirm('Clear all memories? This cannot be undone.')) return;
  
  const tx = etelAI.db.db.transaction('memory', 'readwrite');
  const store = tx.objectStore('memory');
  await store.clear();
  
  await loadMemoryEntries();
  alert('✅ Memory cleared');
}
```

---

## STEP 7: Update Logo References

In your HTML, update all logo image sources:

```html
<!-- Sidebar logo -->
<img id="logo-img-el" src="icons/icon-192.png" alt="ETEL">

<!-- Welcome screen logo -->
<div class="welcome-logo">
  <img src="icons/icon-192.png" alt="ETEL">
</div>

<!-- About modal logo -->
<img id="about-logo-img" src="icons/icon-192.png" alt="ETEL">
```

---

## STEP 8: Add Helper Functions

```javascript
function showThinking() {
  const container = document.getElementById('msgs-container');
  const thinking = document.createElement('div');
  thinking.id = 'thinking-indicator';
  thinking.className = 'msg agent';
  thinking.innerHTML = `
    <div class="msg-avatar agent">
      <img src="icons/icon-192.png" alt="ETEL">
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

function hideThinking() {
  const thinking = document.getElementById('thinking-indicator');
  if (thinking) thinking.remove();
}

function addMessage(role, content) {
  const container = document.getElementById('msgs-container');
  const msg = document.createElement('div');
  msg.className = `msg ${role}`;
  
  const avatar = role === 'agent' 
    ? `<img src="icons/icon-192.png" alt="ETEL">`
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

function formatMessage(text) {
  // Convert markdown-style formatting
  return text
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/`(.+?)`/g, '<code>$1</code>')
    .replace(/```(\w+)?\n([\s\S]+?)```/g, '<pre>$2</pre>')
    .replace(/\n/g, '<br>');
}
```

---

## COMPLETE INTEGRATION CHECKLIST

- [ ] Add `etel-engine.js` script tag
- [ ] Initialize `EtelAI` on page load
- [ ] Update `sendMsg()` to use `etelAI.generate()`
- [ ] Update `saveSettings()` to persist config
- [ ] Add `updateSkillCount()` function
- [ ] Add `loadMemoryEntries()` function
- [ ] Update all logo image sources to `icons/icon-192.png`
- [ ] Add helper functions (showThinking, addMessage, etc)
- [ ] Test with Ollama running
- [ ] Test memory saving
- [ ] Test skill matching

---

## TESTING

### Test 1: HERMES Mode (No Ollama)
```
User: Write Python code to print hello
Expected: Template code + message about enabling Ollama
```

### Test 2: Ollama Mode
```
1. Start Ollama: ollama serve
2. Settings → Select Ollama
3. User: Write Python code to print hello
Expected: Real AI-generated code
```

### Test 3: Memory
```
1. Have a conversation
2. Go to Memory tab
3. Expected: Conversation saved with tags
```

### Test 4: Skills
```
1. Go to Skills tab
2. Expected: 27 skills shown as installed
3. Click skill → See description
```

---

## TROUBLESHOOTING

**"etelAI is not defined"**
→ Make sure `etel-engine.js` is loaded before your main script

**Skills not loading**
→ Check `skills/registry.json` exists
→ Open DevTools → Application → IndexedDB → Check `etel-os` database

**Ollama not connecting**
→ Run `ollama serve` in terminal
→ Check URL is `http://localhost:11434`
→ Try `curl http://localhost:11434` to verify

**Memory not saving**
→ Check Settings → Memory → Set to "Automatic"
→ Check browser allows IndexedDB (not in private mode)

---

*Your ETEL OS is now fully upgraded! 🚀*
