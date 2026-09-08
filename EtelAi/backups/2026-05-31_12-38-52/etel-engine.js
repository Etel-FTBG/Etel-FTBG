// ═══════════════════════════════════════════════════════════
// ETEL OS ENGINE · v2.1 · Fixed IndexedDB + Skills + Memory
// ═══════════════════════════════════════════════════════════

// Helper: wrap IDBRequest in a Promise
function idbReq(req) {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

// Helper: wrap IDBTransaction completion
function idbTx(tx) {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error);
  });
}

class EtelDB {
  constructor() {
    this.dbName = 'etel-os';
    this.version = 1;
    this.db = null;
  }

  async init() {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(this.dbName, this.version);
      request.onerror = () => reject(request.error);
      request.onsuccess = () => { this.db = request.result; resolve(this.db); };
      request.onupgradeneeded = (e) => {
        const db = e.target.result;
        if (!db.objectStoreNames.contains('skills')) {
          const s = db.createObjectStore('skills', { keyPath: 'id' });
          s.createIndex('category', 'category', { unique: false });
          s.createIndex('installed', 'installed', { unique: false });
        }
        if (!db.objectStoreNames.contains('memory')) {
          const m = db.createObjectStore('memory', { keyPath: 'id', autoIncrement: true });
          m.createIndex('type', 'type', { unique: false });
          m.createIndex('timestamp', 'timestamp', { unique: false });
          m.createIndex('tags', 'tags', { unique: false, multiEntry: true });
        }
        if (!db.objectStoreNames.contains('sessions')) {
          const ss = db.createObjectStore('sessions', { keyPath: 'id', autoIncrement: true });
          ss.createIndex('timestamp', 'timestamp', { unique: false });
        }
      };
    });
  }

  async addSkill(skill) {
    const tx = this.db.transaction('skills', 'readwrite');
    const store = tx.objectStore('skills');
    // FIX: idbReq wraps IDBRequest in a Promise
    await idbReq(store.put(skill));
    await idbTx(tx);
  }

  async getSkills(filter = {}) {
    const tx = this.db.transaction('skills', 'readonly');
    const store = tx.objectStore('skills');
    // FIX: idbReq wraps getAll()
    const skills = await idbReq(store.getAll());
    if (filter.installed !== undefined) {
      return skills.filter(s => s.installed === filter.installed);
    }
    return skills;
  }

  async addMemory(memory) {
    const tx = this.db.transaction('memory', 'readwrite');
    const store = tx.objectStore('memory');
    memory.timestamp = Date.now();
    await idbReq(store.add(memory));
    await idbTx(tx);
  }

  async getMemories(limit = 100) {
    return new Promise((resolve, reject) => {
      const tx = this.db.transaction('memory', 'readonly');
      const store = tx.objectStore('memory');
      const index = store.index('timestamp');
      const memories = [];
      // FIX: cursor iteration uses onsuccess/onerror, not await
      const req = index.openCursor(null, 'prev');
      req.onsuccess = (e) => {
        const cursor = e.target.result;
        if (cursor && memories.length < limit) {
          memories.push(cursor.value);
          cursor.continue();
        } else {
          resolve(memories);
        }
      };
      req.onerror = () => reject(req.error);
    });
  }

  async saveSession(session) {
    const tx = this.db.transaction('sessions', 'readwrite');
    const store = tx.objectStore('sessions');
    session.timestamp = Date.now();
    await idbReq(store.add(session));
    await idbTx(tx);
  }
}

class EtelAI {
  constructor(config) {
    this.config = config;
    this.db = new EtelDB();
    this.apiUrl = config.apiUrl || null;
  }

  async init() {
    await this.db.init();
    await this.loadSkills();
  }

  async loadSkills() {
    try {
      const existing = await this.db.getSkills();
      if (existing.length > 0) return existing;

      const response = await fetch('skills/registry.json');
      if (!response.ok) throw new Error('Failed to fetch skills/registry.json');
      const data = await response.json();

      for (const skill of data.skills) {
        skill.installed = true;
        await this.db.addSkill(skill);
      }
      return data.skills;
    } catch (error) {
      console.error('Failed to load skills:', error);
      return [];
    }
  }

  async matchSkill(userInput) {
    const skills = await this.db.getSkills({ installed: true });
    const input = userInput.toLowerCase();
    const scored = skills.map(skill => {
      let score = 0;
      for (const trigger of skill.triggers || []) {
        if (input.includes(trigger.toLowerCase())) score += 10;
      }
      if (input.includes(skill.category.toLowerCase())) score += 5;
      return { skill, score };
    });
    scored.sort((a, b) => b.score - a.score);
    return scored[0]?.score > 0 ? scored[0].skill : null;
  }

  async generate(userInput, conversationHistory = []) {
    const skill = await this.matchSkill(userInput);
    const systemPrompt = skill ? skill.system_prompt : this.config.agentPersona;
    switch (this.config.engine) {
      case 'ollama': return this.generateOllama(systemPrompt, userInput, conversationHistory);
      case 'openai-compat': return this.generateOpenAICompat(systemPrompt, userInput, conversationHistory);
      default: return this.generateHermes(userInput, skill);
    }
  }

  async generateOllama(systemPrompt, userInput, history) {
    const url = `${this.config.ollamaUrl}/api/chat`;
    const messages = [{role:'system',content:systemPrompt}, ...history, {role:'user',content:userInput}];
    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({model: this.config.ollamaModel, messages, stream: false, think: false})
      });
      if (!response.ok) throw new Error('Ollama request failed: ' + response.status);
      const data = await response.json();
      return data.message.content || data.message.thinking || '';
    } catch (error) {
      return `Ollama connection failed: ${error.message}\n\nMake sure Ollama is running: ollama serve`;
    }
  }

  async generateOpenAICompat(systemPrompt, userInput, history) {
    const url = `${this.config.compatUrl}/chat/completions`;
    const messages = [{role:'system',content:systemPrompt}, ...history, {role:'user',content:userInput}];
    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({model: this.config.compatModel, messages})
      });
      if (!response.ok) throw new Error('API request failed: ' + response.status);
      const data = await response.json();
      return data.choices[0].message.content;
    } catch (error) {
      return `Connection failed: ${error.message}`;
    }
  }

  generateHermes(userInput, skill) {
    const input = userInput.toLowerCase();
    if (input.includes('write code') || input.includes('function') || input.includes('script')) {
      const langs = ['python','javascript','java','c++','rust','go','sql'];
      const lang = langs.find(l => input.includes(l)) || 'python';
      return `\`\`\`${lang}\n# ${userInput}\n\ndef main():\n    # Your code here\n    pass\n\nif __name__ == "__main__":\n    main()\n\`\`\``;
    }
    if (input.includes('translate')) return `Translation requested. Enable Ollama for 100+ language support.`;
    if (input.includes('analyze') || input.includes('explain')) return `Analysis framework:\n1. Context\n2. Key Points\n3. Insights\n4. Recommendations`;
    return `HERMES mode active. For real AI, enable Ollama (ollama.com) or OpenRouter (openrouter.ai) in Settings.`;
  }

  async saveToMemory(userInput, aiResponse, skill) {
    if (this.config.memoryMode === 'off') return;
    const memory = {
      type: 'conversation',
      title: userInput.substring(0, 50),
      content: `User: ${userInput}\n\nETEL: ${aiResponse}`,
      tags: skill ? [skill.category, skill.name] : ['general'],
      skill: skill?.id || null
    };
    await this.db.addMemory(memory);
    if (this.config.saveToDatabase && this.apiUrl) {
      try {
        await fetch(this.apiUrl + '?path=memory', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({user_id:1, ...memory, tags: memory.tags.join(',')})
        });
      } catch(e) {}
    }
  }
}

window.EtelAI = EtelAI;
window.EtelDB = EtelDB;
