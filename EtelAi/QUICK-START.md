# ETEL OS - QUICK START
## Get Your AI Running in 5 Minutes

---

## 🎯 WHAT YOU HAVE NOW

✅ **Your coin logo** - Gold/blue ETEL branding  
✅ **Local AI engine** - Works with Ollama, LM Studio, Jan.ai  
✅ **27 AI skills** - Code, analysis, writing, research, translation, etc.  
✅ **Memory system** - Like Obsidian, saves everything locally  
✅ **No API keys** - 100% free forever  

---

## ⚡ 5-MINUTE SETUP

### 1. Install Ollama (2 minutes)

**Windows:**
```cmd
# Download installer from ollama.com
# Or use winget:
winget install Ollama.Ollama
```

**After install:**
```cmd
# Pull a model (choose one):
ollama pull llama3          # Best overall (4.7GB)
ollama pull mistral         # Fast and smart (4.1GB)
ollama pull phi3            # Tiny but capable (2.3GB)
ollama pull codellama       # Best for coding (3.8GB)
```

**Start Ollama:**
```cmd
ollama serve
```

✅ Leave this terminal open. Ollama is now running!

---

### 2. Update Your HTML (1 minute)

Open `index.html` and add this before `</body>`:

```html
<!-- ETEL Engine -->
<script src="etel-engine.js"></script>
<script>
// Initialize ETEL
let etelAI;
let conversationHistory = [];

async function initEtel() {
  const config = {
    engine: localStorage.getItem('etel_engine') || 'ollama',
    ollamaUrl: 'http://localhost:11434',
    ollamaModel: 'llama3',
    agentPersona: 'You are ETEL, an advanced AI assistant. You are helpful, intelligent, and multilingual.',
    memoryMode: 'auto'
  };
  
  etelAI = new EtelAI(config);
  await etelAI.init();
  console.log('✅ ETEL ready');
}

document.addEventListener('DOMContentLoaded', initEtel);
</script>
```

---

### 3. Update Your Logo (30 seconds)

Replace all logo references with your coin logo:

```html
<!-- Find and replace all instances of: -->
<img src="" alt="ETEL">

<!-- With: -->
<img src="icons/icon-192.png" alt="ETEL">
```

Or use Find & Replace (Ctrl+H):
- Find: `src=""`
- Replace: `src="icons/icon-192.png"`

---

### 4. Test It! (1 minute)

Open `index.html` in your browser and try:

```
Write Python code to scrape a website
```

```
Translate to Amharic: Hello, how are you?
```

```
Analyze the pros and cons of electric vehicles
```

✅ You should get real AI responses!

---

## 🎨 CUSTOMIZE YOUR BRANDING

### Change Colors

In your CSS, update these variables:

```css
:root {
  --gold: #C9A227;      /* Your primary color */
  --gold-l: #D4AF37;    /* Lighter shade */
  --blue: #4A8FD4;      /* Accent color */
}
```

### Change Agent Name

In Settings modal:
1. Click Settings
2. Agent Name: `Your Name`
3. Personality: `You are [your description]...`
4. Save

---

## 📊 HOW IT WORKS

```
User types message
    ↓
ETEL matches to best skill (code, research, etc)
    ↓
Loads skill's system prompt from database
    ↓
Sends to Ollama with prompt + user message
    ↓
Ollama generates response
    ↓
ETEL displays response
    ↓
Saves to memory vault
```

---

## 🔧 SETTINGS EXPLAINED

### Engine Options:

**HERMES** (Built-in)
- Rule-based responses
- Works offline, no install
- Limited capabilities
- Good for testing

**Ollama** (Recommended)
- Real AI responses
- 100% local and free
- Works offline after model download
- Like ChatGPT/Claude

**OpenAI Compatible**
- For LM Studio, Jan.ai, etc
- Same as Ollama but different interface
- Also 100% local and free

---

## 💾 WHERE DATA IS STORED

**Skills:** Browser IndexedDB → `etel-os` database → `skills` store  
**Memory:** Browser IndexedDB → `etel-os` database → `memory` store  
**Settings:** Browser localStorage → `etel_*` keys  

**To backup:**
1. Open DevTools (F12)
2. Application → IndexedDB → Right-click `etel-os` → Export
3. Save JSON file

**To restore:**
1. Import JSON back to IndexedDB
2. Or re-sync skills from GitHub

---

## 🚀 DEPLOY TO WEB

### Option 1: Vercel (Easiest)

```bash
# Install Vercel CLI
npm i -g vercel

# Deploy
cd etel-os
vercel --prod
```

You get: `https://etel-os.vercel.app`

### Option 2: Netlify (Drag & Drop)

1. Go to netlify.com
2. Drag your `etel-os` folder
3. Done!

### Option 3: GitHub Pages

```bash
git init
git add .
git commit -m "ETEL OS"
git push origin main

# Enable Pages in repo settings
```

---

## 📱 INSTALL AS APP

### Phone (Android/iPhone):
1. Open your deployed URL
2. Browser menu → **Add to Home Screen**
3. ETEL appears as native app

### Desktop (Chrome/Edge):
1. Open your URL
2. Address bar → Install icon
3. ETEL opens as desktop app

---

## 🐛 COMMON ISSUES

### "Ollama connection failed"

**Fix:**
```cmd
# Check Ollama is running:
ollama serve

# Check model is downloaded:
ollama list

# Test connection:
curl http://localhost:11434
```

### "Skills not loading"

**Fix:**
1. Check `skills/registry.json` exists
2. Open DevTools → Console → Look for errors
3. Try: Skills tab → **Sync from GitHub**

### "Memory not saving"

**Fix:**
1. Settings → Memory → Set to **Automatic**
2. Check not in private/incognito mode
3. Check browser allows IndexedDB

### "Logo not showing"

**Fix:**
1. Verify `icons/icon-192.png` exists
2. Check file is PNG format (not SVG)
3. Clear cache: Ctrl+Shift+R

---

## 🎯 NEXT STEPS

1. ✅ Test all 27 skills
2. ✅ Customize colors and branding
3. ✅ Deploy to web
4. ✅ Install as app on phone
5. ✅ Share with friends!

---

## 💡 PRO TIPS

**Faster responses:**
- Use smaller models: `phi3` or `mistral`
- Or use quantized versions: `llama3:8b-q4`

**Better code generation:**
- Use `codellama` model
- Or `deepseek-coder`

**Multilingual support:**
- Most models support 50+ languages
- Amharic works best with `llama3`

**Save bandwidth:**
- Models download once
- Then work 100% offline
- No internet needed after setup

---

## 📚 LEARN MORE

**Ollama models:** ollama.com/library  
**LM Studio:** lmstudio.ai  
**Jan.ai:** jan.ai  
**ETEL docs:** See UPGRADE-COMPLETE.md  

---

*You're all set! Start chatting with your AI! 🎉*
