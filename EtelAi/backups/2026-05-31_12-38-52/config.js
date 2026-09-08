// ═══════════════════════════════════════════════════════════
// ETEL OS Configuration · v3.1 · Ollama + Multi-Agent
// ═══════════════════════════════════════════════════════════

const ETEL_CONFIG = {
  // Your domain and API
  domain: 'https://ethelmarket.com',
  apiUrl: 'https://ethelmarket.com/etel/api/api.php',

  // AI Engine — switch engine to use different backends
  ai: {
    engine: 'openai-compat',    // 'openai-compat' = OpenJarvis API | 'ollama' = direct Ollama | 'claude' = cloud

    // OpenJarvis API (OpenAI-compatible, port 8000) — active when engine = 'openai-compat'
    compatUrl: 'http://localhost:8000/v1',
    compatModel: 'qwen3.5:4b',   // model loaded in OpenJarvis

    // Ollama fallback (port 11434) — active when engine = 'ollama'
    ollamaUrl: 'http://localhost:11434',
    ollamaModel: 'qwen3:0.6b',  // matches the model in OpenJarvis

    // REMOTE (paste your Cloudflare Tunnel URL here after setup - see SETUP-OLLAMA.md):
    // ollamaUrl: 'https://YOUR-TUNNEL.trycloudflare.com',

    ollamaModels: [
      { id: 'qwen3.5:4b',       name: 'Qwen3.5 4B',      desc: 'OpenJarvis default · balanced', free: true },
      { id: 'qwen3:0.6b',       name: 'Qwen3 0.6B',      desc: 'Ultra fast · direct Ollama',  free: true },
      { id: 'tinyllama',       name: 'TinyLlama 1.1B',  desc: 'Ultra fast · 638 MB',         free: true },
      { id: 'phi3',            name: 'Phi-3 Mini',      desc: 'Smart · 2.3 GB',              free: true },
      { id: 'mistral',         name: 'Mistral 7B',      desc: 'Balanced · 4.1 GB',           free: true },
      { id: 'llama3',          name: 'Llama 3 8B',      desc: 'Powerful · 4.7 GB',           free: true },
      { id: 'deepseek-r1:7b', name: 'DeepSeek R1',     desc: 'Reasoning · 4.7 GB',          free: true },
      { id: 'codellama',       name: 'Code Llama',      desc: 'Code expert · 3.8 GB',        free: true },
    ],
  },

  // Multi-agent: list agents active at the same time
  agents: {
    enabled: true,
    maxParallel: 4,             // how many can run at once
    list: [
      {
        id: 'assistant',
        name: 'ETEL Assistant',
        icon: '🤖',
        model: 'tinyllama',
        persona: 'You are ETEL, an advanced AI assistant. Be helpful, concise, and multilingual.',
        skills: ['writer', 'summarizer', 'translator'],
      },
      {
        id: 'coder',
        name: 'Code Agent',
        icon: '💻',
        model: 'codellama',
        persona: 'You are an expert software engineer. Write clean, production-ready code.',
        skills: ['code-gen', 'debugger', 'code-review'],
      },
      {
        id: 'researcher',
        name: 'Research Agent',
        icon: '🔬',
        model: 'mistral',
        persona: 'You are a deep research specialist. Find facts, analyze data, and cite sources.',
        skills: ['researcher', 'analyzer', 'fact-checker'],
      },
      {
        id: 'jarvis',
        name: 'OpenJarvis',
        icon: '🧠',
        model: 'qwen3.5:4b',
        persona: 'You are Jarvis, a powerful personal AI assistant with broad knowledge.',
        skills: ['planner', 'meeting-assistant', 'prompt-engineer'],
        source: 'github',        // loaded from Agents/OpenJarvis-main.zip
      },
    ],
  },

  // Agent identity (default)
  agent: {
    name: 'ETEL AI',
    persona: 'You are ETEL, an advanced AI assistant created by ETEL Group in Hawassa, Ethiopia. You are intelligent, helpful, multilingual (including Amharic and Tigrinya), and skilled in coding, analysis, writing, research, and more. Always be thoughtful, accurate, and concise.',
  },

  // Memory
  memory: {
    mode: 'auto',
    saveToDatabase: true,
  },

  // Skills — GitHub sources (free, downloaded once)
  skillSources: [
    { name: 'ETEL Core',    url: null,              local: 'skills/registry.json' },
    { name: 'OpenJarvis',   url: null,              local: 'Agents/OpenJarvis-main.zip' },
    { name: 'Graphify',     url: null,              local: 'Agents/graphify-8.zip' },
  ],

  // Branding
  branding: {
    logo: 'icons/icon-192.png',
    name: 'ETEL AI',
    nameAmharic: 'ኢቴሌ',
    colors: {
      gold: '#C9A227',
      goldLight: '#D4AF37',
      blue: '#4A8FD4',
    }
  }
};

if (typeof module !== 'undefined' && module.exports) {
  module.exports = ETEL_CONFIG;
}
