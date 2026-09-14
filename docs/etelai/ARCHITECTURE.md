# ETEL OS - System Architecture
## How Everything Works Together

---

## 🏗️ SYSTEM OVERVIEW

```
┌─────────────────────────────────────────────────────────────┐
│                        ETEL OS                              │
│                    (Your Browser)                           │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐  │
│  │              USER INTERFACE (index.html)            │  │
│  │  • Chat interface                                   │  │
│  │  • Skills view                                      │  │
│  │  • Memory vault                                     │  │
│  │  • Settings                                         │  │
│  └────────────────────┬────────────────────────────────┘  │
│                       │                                    │
│  ┌────────────────────▼────────────────────────────────┐  │
│  │         ETEL ENGINE (etel-engine.js)               │  │
│  │                                                     │  │
│  │  ┌─────────────────────────────────────────────┐  │  │
│  │  │  Skill Matcher                              │  │  │
│  │  │  • Analyzes user input                      │  │  │
│  │  │  • Matches to best skill                    │  │  │
│  │  │  • Loads skill prompt                       │  │  │
│  │  └─────────────────────────────────────────────┘  │  │
│  │                                                     │  │
│  │  ┌─────────────────────────────────────────────┐  │  │
│  │  │  AI Router                                  │  │  │
│  │  │  • Routes to Ollama/LM Studio/HERMES       │  │  │
│  │  │  • Handles streaming responses              │  │  │
│  │  │  • Error handling & fallbacks               │  │  │
│  │  └─────────────────────────────────────────────┘  │  │
│  │                                                     │  │
│  │  ┌─────────────────────────────────────────────┐  │  │
│  │  │  Memory Manager                             │  │  │
│  │  │  • Saves conversations                      │  │  │
│  │  │  • Tags and categorizes                     │  │  │
│  │  │  • Search and retrieval                     │  │  │
│  │  └─────────────────────────────────────────────┘  │  │
│  └────────────────────┬────────────────────────────────┘  │
│                       │                                    │
│  ┌────────────────────▼────────────────────────────────┐  │
│  │         DATABASE (IndexedDB)                       │  │
│  │                                                     │  │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────┐ │  │
│  │  │   Skills     │  │   Memory     │  │ Sessions │ │  │
│  │  │   (27)       │  │   (∞)        │  │   (∞)    │ │  │
│  │  └──────────────┘  └──────────────┘  └──────────┘ │  │
│  └─────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                       │
                       │ HTTP Requests
                       │
        ┌──────────────▼──────────────┐
        │                             │
   ┌────▼────┐              ┌─────────▼────────┐
   │ Ollama  │              │   LM Studio      │
   │ (Local) │              │   (Local)        │
   │         │              │                  │
   │ llama3  │              │ Any GGUF model   │
   │ mistral │              │                  │
   │ phi3    │              │                  │
   └─────────┘              └──────────────────┘
```

---

## 🔄 REQUEST FLOW

### 1. User Sends Message

```
User types: "Write Python code to scrape a website"
                    ↓
        [msg-input textarea]
                    ↓
            sendMsg() function
                    ↓
        Add to UI as user message
                    ↓
        Show "thinking" indicator
```

### 2. Skill Matching

```
        etelAI.matchSkill(input)
                    ↓
    Loop through all 27 skills
                    ↓
    Check triggers: ["write code", "function", "script"]
                    ↓
    Score each skill (0-100)
                    ↓
    Return best match: "Code Generator"
                    ↓
    Load skill's system_prompt from IndexedDB
```

### 3. AI Generation

```
        etelAI.generate(input, history)
                    ↓
    Check config.engine: "ollama"
                    ↓
        generateOllama()
                    ↓
    Build messages array:
    [
      {role: "system", content: skill.system_prompt},
      {role: "user", content: input}
    ]
                    ↓
    POST to http://localhost:11434/api/chat
                    ↓
    Ollama processes with llama3 model
                    ↓
    Return AI response
```

### 4. Display & Save

```
        AI response received
                    ↓
    Hide "thinking" indicator
                    ↓
    Format response (markdown → HTML)
                    ↓
    Add to UI as agent message
                    ↓
    saveToMemory(input, response, skill)
                    ↓
    Store in IndexedDB with:
    • timestamp
    • tags: [skill.category, skill.name]
    • full conversation
                    ↓
    Update conversation history
```

---

## 💾 DATABASE SCHEMA

### Skills Store

```javascript
{
  id: "code-gen",                    // Primary key
  name: "Code Generator",
  category: "coding",
  version: "1.0.0",
  description: "Generate code in 40+ languages",
  system_prompt: "You are an expert software engineer...",
  triggers: ["write code", "function", "script"],
  tools: ["code_write", "code_debug"],
  installed: true,
  author: "ETEL Core",
  license: "MIT"
}
```

**Indexes:**
- `category` - For filtering by category
- `installed` - For showing only installed skills

### Memory Store

```javascript
{
  id: 1,                             // Auto-increment
  type: "conversation",
  title: "Python web scraping",
  content: "**User:** Write Python...\n\n**ETEL:** Here's...",
  tags: ["coding", "python"],
  skill: "code-gen",
  timestamp: 1716768000000,
  importance: 3
}
```

**Indexes:**
- `type` - conversation, fact, note, etc.
- `timestamp` - For chronological sorting
- `tags` - Multi-entry index for tag search

### Sessions Store

```javascript
{
  id: 1,                             // Auto-increment
  messages: [
    {role: "user", content: "..."},
    {role: "assistant", content: "..."}
  ],
  model: "llama3",
  engine: "ollama",
  timestamp: 1716768000000,
  title: "Python web scraping"
}
```

**Indexes:**
- `timestamp` - For recent sessions

---

## 🔌 ENGINE MODES

### HERMES Mode (Built-in)

```
User input
    ↓
Pattern matching (regex/keywords)
    ↓
Template responses
    ↓
No external API calls
    ↓
Fast but limited
```

**Use when:**
- Testing without Ollama
- Offline with no models
- Quick responses needed

### Ollama Mode (Recommended)

```
User input
    ↓
POST to http://localhost:11434/api/chat
    ↓
Ollama runs local model (llama3, mistral, etc)
    ↓
Real AI generation
    ↓
Stream or full response
```

**Use when:**
- Want real AI responses
- Have Ollama installed
- Models downloaded

### OpenAI-Compatible Mode

```
User input
    ↓
POST to http://localhost:1234/v1/chat/completions
    ↓
LM Studio / Jan.ai / llama.cpp server
    ↓
Real AI generation
    ↓
OpenAI-format response
```

**Use when:**
- Using LM Studio
- Using Jan.ai
- Using custom server

---

## 🎯 SKILL SYSTEM

### Skill Loading (On Init)

```
Page loads
    ↓
etelAI.init()
    ↓
Check IndexedDB for skills
    ↓
If empty:
    ↓
    Fetch skills/registry.json
    ↓
    Parse JSON (27 skills)
    ↓
    Store each in IndexedDB
    ↓
    Mark all as installed
    ↓
If exists:
    ↓
    Load from IndexedDB
    ↓
Skills ready
```

### Skill Matching Algorithm

```javascript
function matchSkill(input) {
  const skills = await db.getSkills({installed: true});
  const scores = [];
  
  for (const skill of skills) {
    let score = 0;
    
    // Check each trigger
    for (const trigger of skill.triggers) {
      if (input.toLowerCase().includes(trigger)) {
        score += 10;
      }
    }
    
    // Category bonus
    if (input.includes(skill.category)) {
      score += 5;
    }
    
    scores.push({skill, score});
  }
  
  // Return highest score
  scores.sort((a, b) => b.score - a.score);
  return scores[0]?.score > 0 ? scores[0].skill : null;
}
```

### Skill Prompt Injection

```
User: "Write Python code to scrape"
    ↓
Matched skill: "Code Generator"
    ↓
Load skill.system_prompt:
"You are an expert software engineer.
When activated, you write clean, well-commented,
production-ready code..."
    ↓
Build messages:
[
  {role: "system", content: skill.system_prompt},
  {role: "user", content: "Write Python code to scrape"}
]
    ↓
Send to Ollama
    ↓
Ollama responds as expert coder
```

---

## 🧠 MEMORY SYSTEM

### Auto-Save Flow

```
AI response generated
    ↓
Check config.memoryMode
    ↓
If "auto":
    ↓
    Create memory object:
    {
      type: "conversation",
      title: first 50 chars of input,
      content: full conversation,
      tags: [skill.category, skill.name],
      timestamp: now
    }
    ↓
    db.addMemory(memory)
    ↓
    Stored in IndexedDB
```

### Memory Retrieval

```
User opens Memory tab
    ↓
loadMemoryEntries()
    ↓
db.getMemories(50)
    ↓
Query IndexedDB:
    • Sort by timestamp DESC
    • Limit 50
    ↓
Return array of memories
    ↓
Render in UI with:
    • Date/time
    • Preview (first 200 chars)
    • Tags
```

### Memory Search (Future)

```
User searches: "python"
    ↓
db.searchMemories("python")
    ↓
Query IndexedDB:
    • MATCH content AGAINST "python"
    • OR tags CONTAINS "python"
    ↓
Return matching memories
    ↓
Render results
```

---

## 🔐 DATA PRIVACY

### Where Data Lives

```
┌─────────────────────────────────────┐
│  YOUR BROWSER                       │
│                                     │
│  ┌───────────────────────────────┐ │
│  │  IndexedDB                    │ │
│  │  • Skills (27)                │ │
│  │  • Memory (all conversations) │ │
│  │  • Sessions (chat history)    │ │
│  └───────────────────────────────┘ │
│                                     │
│  ┌───────────────────────────────┐ │
│  │  localStorage                 │ │
│  │  • Settings                   │ │
│  │  • Config                     │ │
│  └───────────────────────────────┘ │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│  YOUR COMPUTER                      │
│                                     │
│  ┌───────────────────────────────┐ │
│  │  Ollama                       │ │
│  │  • Models (llama3, etc)       │ │
│  │  • Runs locally               │ │
│  │  • No internet needed         │ │
│  └───────────────────────────────┘ │
└─────────────────────────────────────┘

❌ NO DATA SENT TO:
   • Cloud servers
   • External APIs
   • Third parties
   • ETEL servers (we don't have any!)
```

---

## 🚀 PERFORMANCE

### Optimization Strategies

**1. Lazy Loading**
```
Skills loaded once on init
    ↓
Cached in IndexedDB
    ↓
Subsequent loads instant
```

**2. Skill Matching**
```
O(n) complexity where n = 27 skills
    ↓
Fast even on slow devices
    ↓
< 1ms typical
```

**3. Memory Pagination**
```
Load 50 memories at a time
    ↓
Infinite scroll for more
    ↓
Keeps UI responsive
```

**4. Model Selection**
```
Small models (phi3: 2.3GB)
    ↓
Fast responses (< 2s)
    ↓
Good for most tasks

Large models (llama3: 4.7GB)
    ↓
Better quality
    ↓
Slower (3-5s)
```

---

## 🔄 UPDATE FLOW

### Skill Updates

```
User clicks "Sync Skills"
    ↓
Fetch skills/registry.json
    ↓
Compare versions with IndexedDB
    ↓
Update changed skills
    ↓
Add new skills
    ↓
Keep user data intact
```

### Engine Updates

```
Update etel-engine.js
    ↓
User refreshes page
    ↓
Service worker caches new version
    ↓
Next load uses new engine
    ↓
IndexedDB data preserved
```

---

## 🎨 CUSTOMIZATION POINTS

### 1. Add Custom Skill

```javascript
const customSkill = {
  id: "my-skill",
  name: "My Custom Skill",
  category: "custom",
  system_prompt: "You are a specialist in...",
  triggers: ["my trigger", "custom action"],
  installed: true
};

await etelAI.db.addSkill(customSkill);
```

### 2. Custom AI Engine

```javascript
class MyCustomEngine extends EtelAI {
  async generateCustom(prompt) {
    // Your custom logic
    return response;
  }
}
```

### 3. Custom Memory Type

```javascript
await etelAI.db.addMemory({
  type: "custom",
  title: "My Custom Memory",
  content: "...",
  tags: ["custom"],
  myCustomField: "value"
});
```

---

*This architecture gives you complete control and privacy while matching the capabilities of cloud AI services.* 🚀
