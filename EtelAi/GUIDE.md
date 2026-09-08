# ETEL Agent OS — Complete Deployment Guide
## Get Live on Phone + Computer, Free Forever

---

## What you have (your files)

```
etel-os/
├── index.html          ← The entire ETEL app (one file)
├── manifest.json       ← Makes it installable on phone/desktop
├── sw.js               ← Service worker (offline mode)
├── skills/
│   └── registry.json   ← All 27 AI skill definitions
└── icons/
    └── icon-192.svg    ← App icon (convert to PNG — see Step 1)
```

---

## STEP 1 — Create your app icons (2 minutes)

You need two PNG icons for the phone install prompt.

**Option A — Free online (easiest):**
1. Go to https://svgtopng.com
2. Upload `icons/icon-192.svg`
3. Download as PNG → save as `icons/icon-192.png`
4. Do the same, resize to 512×512 → save as `icons/icon-512.png`

**Option B — If you have ImageMagick:**
```bash
magick icons/icon-192.svg -resize 192x192 icons/icon-192.png
magick icons/icon-192.svg -resize 512x512 icons/icon-512.png
```

---

## STEP 2 — Put your files on GitHub (5 minutes)

GitHub stores your code for free forever.

1. Go to https://github.com and create a free account (if you don't have one)
2. Click the **+** button → **New repository**
3. Name it: `etel-os`
4. Set to **Public** (required for free hosting)
5. Click **Create repository**

Now upload your files:
- Click **Add file** → **Upload files**
- Drag ALL your files into the box:
  - `index.html`
  - `manifest.json`
  - `sw.js`
  - `skills/registry.json`
  - `icons/icon-192.png`
  - `icons/icon-512.png`
- Click **Commit changes**

---

## STEP 3 — Deploy to Vercel (3 minutes, FREE FOREVER)

Vercel gives you a live website URL at zero cost, forever.

1. Go to https://vercel.com and sign up with your GitHub account
2. Click **Add New → Project**
3. Find and select your `etel-os` repository
4. Click **Deploy** (no settings needed — it detects it automatically)
5. Wait ~60 seconds

✅ Vercel gives you a URL like: `https://etel-os.vercel.app`

**That is your ETEL live URL. Save it.**

> Alternative free host: https://netlify.com — drag your etel-os folder
> onto the Netlify dashboard. Same result, also free forever.

---

## STEP 4 — Install on your PHONE (Android)

1. Open your phone browser (Chrome on Android, Safari on iPhone)
2. Go to your Vercel URL: `https://etel-os.vercel.app`
3. **Android:** Tap the 3-dot menu → **Add to Home Screen** → **Install**
4. **iPhone:** Tap the Share button (box with arrow) → **Add to Home Screen**
5. ETEL appears on your home screen like a real app

✅ It works offline. It remembers your installed skills forever.

---

## STEP 5 — Install on your COMPUTER

1. Open Chrome or Edge on your computer
2. Go to `https://etel-os.vercel.app`
3. Look for the install icon in the address bar (a small computer icon with +)
4. Click it → **Install**
5. ETEL opens as a standalone desktop app

> Or press: `Ctrl+Shift+A` in Chrome to force the install dialog

✅ ETEL now lives on your taskbar/dock like any app.

---

## STEP 6 — Add your Claude API key (connect real AI)

This makes your ETEL agent actually think using Claude.

1. Go to https://console.anthropic.com
2. Sign up for a free account
3. Go to **API Keys** → **Create Key**
4. Copy your key (starts with `sk-ant-...`)

Now add it to ETEL:
- Open `index.html` in a text editor (Notepad, VS Code, etc.)
- Find this line near the bottom of the `<script>` section:
  ```
  // ADD YOUR CLAUDE API KEY HERE
  const CLAUDE_KEY = '';
  ```
- Paste your key:
  ```
  const CLAUDE_KEY = 'sk-ant-YOUR-KEY-HERE';
  ```
- Save and re-upload to GitHub → Vercel auto-redeploys

> **Free tier:** Anthropic gives free API credits to start.
> **100% offline alternative:** Install Ollama (https://ollama.com) on
> your computer → run `ollama pull llama3` → set key to `'local'`

---

## STEP 7 — Connect Obsidian Vault (memory)

1. Download Obsidian free: https://obsidian.md
2. Create a new vault called `ETEL-vault`
3. Inside, create folders:
   ```
   ETEL-vault/
   ├── etel/
   │   ├── sessions/     ← Agent writes daily notes here
   │   ├── skills/       ← Skill notes
   │   ├── tasks/        ← Task planner output
   │   └── memory/       ← Long-term memory
   ```
4. In ETEL → Settings → paste your vault path:
   ```
   ~/Documents/ETEL-vault
   ```

✅ Every agent conversation now saves to your vault as Markdown.
Your knowledge graph grows permanently, forever, on your own device.

---

## STEP 8 — Connect HERMES (optional, advanced)

HERMES is the message bus that lets ETEL talk to other agents.

**Free setup with n8n (self-hosted):**
1. Go to https://n8n.io → Download free desktop app
2. Create a new workflow
3. Add webhook node → copy the webhook URL
4. In ETEL Settings → HERMES URL → paste the URL
5. ETEL now routes tasks, events and triggers through n8n

**Alternative: use Make.com free tier (800 ops/month free)**

---

## YOUR ETEL IS NOW LIVE

| What | Status |
|------|--------|
| Website | ✅ Live at your Vercel URL |
| Phone app | ✅ Installed from browser |
| Desktop app | ✅ Installed via Chrome |
| Skills | ✅ Download any of 27 free skills |
| Memory | ✅ Obsidian Vault connected |
| AI engine | ✅ Claude API or Ollama local |
| Offline mode | ✅ Works without internet |
| Cost | ✅ Free forever |

---

## KEEPING ETEL UPDATED

Whenever you want to update ETEL:
1. Edit `index.html` on your computer
2. Push to GitHub (drag file into GitHub website → commit)
3. Vercel auto-deploys in ~30 seconds
4. Phone and desktop app update automatically next time you open it

---

## TROUBLESHOOTING

**"Install" button not showing on phone:**
→ Must use HTTPS URL (Vercel gives you this automatically)
→ Must open in Chrome (Android) or Safari (iPhone)

**Skills not saving between sessions:**
→ Check your browser allows localStorage for the site
→ Settings → Privacy → make sure site data isn't blocked

**Agent not responding:**
→ Check your Claude API key is correct
→ Check you have API credits at console.anthropic.com

**Offline mode not working:**
→ Visit the site once online first (service worker needs to cache)
→ Then it works offline

---

## FREE FOREVER COST BREAKDOWN

| Service | Cost |
|---------|------|
| GitHub repo | Free |
| Vercel hosting | Free (100GB bandwidth/month) |
| Obsidian app | Free |
| Ollama local AI | Free (runs on your computer) |
| Claude API | Free credits to start; ~$0.003/query after |
| n8n desktop | Free |
| PWA install | Free (built into browser) |
| **Total** | **$0** |

---

*ETEL Agent OS · Your intelligence, your device, your freedom.*
