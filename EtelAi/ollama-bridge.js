// ═══════════════════════════════════════════════════════════
// ETEL Ollama Bridge · v1.0
// Talks to local or tunneled Ollama for free AI
// ═══════════════════════════════════════════════════════════

class OllamaBridge {
  constructor(config) {
    this.baseUrl = config.ai.ollamaUrl || 'http://localhost:11434';
    this.defaultModel = config.ai.ollamaModel || 'tinyllama';
  }

  // Update URL at runtime (e.g. after user pastes Cloudflare tunnel URL)
  setUrl(url) {
    this.baseUrl = url.replace(/\/$/, '');
    localStorage.setItem('etel_ollama_url', this.baseUrl);
  }

  getUrl() {
    return localStorage.getItem('etel_ollama_url') || this.baseUrl;
  }

  // Check if Ollama is reachable
  async ping() {
    try {
      const r = await fetch(`${this.getUrl()}/api/tags`, { signal: AbortSignal.timeout(3000) });
      return r.ok;
    } catch {
      return false;
    }
  }

  // List installed models
  async listModels() {
    try {
      const r = await fetch(`${this.getUrl()}/api/tags`);
      const data = await r.json();
      return (data.models || []).map(m => m.name);
    } catch {
      return [];
    }
  }

  // Pull (download) a model — streams progress
  async pullModel(modelName, onProgress) {
    const r = await fetch(`${this.getUrl()}/api/pull`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: modelName, stream: true }),
    });
    const reader = r.body.getReader();
    const decoder = new TextDecoder();
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      const lines = decoder.decode(value).split('\n').filter(Boolean);
      for (const line of lines) {
        try {
          const json = JSON.parse(line);
          if (onProgress) onProgress(json);
        } catch {}
      }
    }
  }

  // Chat with streaming — calls onToken for each word
  async chat({ model, messages, systemPrompt, onToken, onDone }) {
    const msgs = [];
    if (systemPrompt) msgs.push({ role: 'system', content: systemPrompt });
    msgs.push(...messages);

    const r = await fetch(`${this.getUrl()}/api/chat`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ model: model || this.defaultModel, messages: msgs, stream: true }),
    });

    if (!r.ok) {
      const err = await r.text();
      throw new Error(`Ollama error: ${err}`);
    }

    const reader = r.body.getReader();
    const decoder = new TextDecoder();
    let fullText = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      const lines = decoder.decode(value).split('\n').filter(Boolean);
      for (const line of lines) {
        try {
          const json = JSON.parse(line);
          const token = json.message?.content || '';
          if (token) {
            fullText += token;
            if (onToken) onToken(token, fullText);
          }
          if (json.done && onDone) onDone(fullText);
        } catch {}
      }
    }
    return fullText;
  }
}

// ─── Multi-Agent Manager ─────────────────────────────────
class EtelAgentManager {
  constructor(config, bridge) {
    this.config = config;
    this.bridge = bridge;
    this.agents = config.agents?.list || [];
    this.running = {};   // agentId → { abort, status }
  }

  // Run one agent and stream its response
  async runAgent(agentId, userMessage, onToken, onDone) {
    const agent = this.agents.find(a => a.id === agentId);
    if (!agent) throw new Error(`Agent "${agentId}" not found`);

    // Pick the best installed model
    const installed = await this.bridge.listModels();
    const model = installed.find(m => m.startsWith(agent.model)) || installed[0] || 'tinyllama';

    this.running[agentId] = { status: 'running' };

    try {
      const result = await this.bridge.chat({
        model,
        messages: [{ role: 'user', content: userMessage }],
        systemPrompt: agent.persona,
        onToken: (tok, full) => onToken && onToken(agentId, tok, full),
        onDone: (full) => {
          this.running[agentId].status = 'done';
          onDone && onDone(agentId, full);
        },
      });
      return result;
    } catch (err) {
      this.running[agentId].status = 'error';
      throw err;
    }
  }

  // Run ALL agents on the same prompt in parallel
  async runAll(userMessage, onToken, onDone) {
    const limit = this.config.agents?.maxParallel || 4;
    const selected = this.agents.slice(0, limit);
    return Promise.all(
      selected.map(agent => this.runAgent(agent.id, userMessage, onToken, onDone))
    );
  }

  getStatus(agentId) {
    return this.running[agentId]?.status || 'idle';
  }
}

// Export globals
window.OllamaBridge = OllamaBridge;
window.EtelAgentManager = EtelAgentManager;
