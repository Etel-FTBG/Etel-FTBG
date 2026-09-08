/**
 * etel-printer-web.js
 * ────────────────────────────────────────────────────────────────
 * Talks to a Bluetooth Classic (SPP) ESC/POS thermal printer from
 * the browser, using the Web Serial API.
 *
 * WHY WEB SERIAL AND NOT WEB BLUETOOTH:
 * This printer is Bluetooth *Classic* (SPP profile), not BLE. The
 * Web Bluetooth API only supports BLE (GATT) devices, so it cannot
 * see this printer at all. The working path is:
 *   1. Pair the printer with Windows the normal way (Settings →
 *      Bluetooth & devices → Add device). Windows creates a
 *      virtual COM port for it (e.g. COM5) automatically.
 *   2. This script uses navigator.serial to open THAT COM port and
 *      write raw ESC/POS command bytes to it.
 *
 * BROWSER SUPPORT: Chrome / Edge / Opera on Windows, macOS, Linux,
 * or ChromeOS (desktop). It does NOT work in Firefox, Safari, or
 * any mobile browser (Android Chrome's Web Serial only sees USB
 * devices, not Bluetooth virtual ports) — for Android, use the
 * native Java EtelPrinter class instead (see EtelPrinter.java).
 *
 * USAGE (see etel-printer-demo.html for a full working example):
 *
 *   const printer = new EtelPrinter();
 *   await printer.connect();              // one-time permission prompt
 *   await printer.printReceipt({
 *     header: ['Etel Market', 'Hawassa Branch'],
 *     items: [
 *       { name: 'Bread',  qty: 2, price: 25 },
 *       { name: 'Milk 1L', qty: 1, price: 60 },
 *     ],
 *     total: 110,
 *     footer: ['Thank you!', 'ኢትኤል ቡድን'],
 *   });
 *
 * For Amharic / non-Latin text, the printer's built-in font has no
 * Ethiopic glyphs, so plain text printing will come out blank or
 * garbled. Use printImageFromCanvas() instead — render the Amharic
 * text onto an HTML canvas using a real font (Noto Sans Ethiopic,
 * already used elsewhere in Etel's products) and this module turns
 * that canvas into a raster image the printer can print as dots.
 */

class EtelPrinter {
  constructor() {
    this.port = null;
    this.writer = null;
  }

  /* ─── Connection ─── */

  isSupported() {
    return 'serial' in navigator;
  }

  async connect(baudRate = 9600) {
    if (!this.isSupported()) {
      throw new Error('Web Serial API is not available in this browser. Use Chrome or Edge on a desktop computer.');
    }
    // Lets the user pick the printer's paired COM port from a system dialog.
    this.port = await navigator.serial.requestPort();
    await this.port.open({ baudRate });
    this.writer = this.port.writable.getWriter();
  }

  async disconnect() {
    if (this.writer) {
      await this.writer.close();
      this.writer = null;
    }
    if (this.port) {
      await this.port.close();
      this.port = null;
    }
  }

  get isConnected() {
    return !!this.writer;
  }

  async _write(bytes) {
    if (!this.writer) throw new Error('Printer not connected. Call connect() first.');
    await this.writer.write(bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes));
  }

  /* ─── Low-level ESC/POS commands ─── */

  static CMD = {
    INIT:            [0x1B, 0x40],             // ESC @  — reset printer
    ALIGN_LEFT:      [0x1B, 0x61, 0x00],
    ALIGN_CENTER:    [0x1B, 0x61, 0x01],
    ALIGN_RIGHT:     [0x1B, 0x61, 0x02],
    BOLD_ON:         [0x1B, 0x45, 0x01],
    BOLD_OFF:        [0x1B, 0x45, 0x00],
    DOUBLE_ON:       [0x1D, 0x21, 0x11],        // double height + width
    DOUBLE_OFF:      [0x1D, 0x21, 0x00],
    UNDERLINE_ON:    [0x1B, 0x2D, 0x01],
    UNDERLINE_OFF:   [0x1B, 0x2D, 0x00],
    FEED_LINE:       [0x0A],
  };

  async init() { await this._write(EtelPrinter.CMD.INIT); }
  async align(mode) {
    const map = { left: 'ALIGN_LEFT', center: 'ALIGN_CENTER', right: 'ALIGN_RIGHT' };
    await this._write(EtelPrinter.CMD[map[mode] || 'ALIGN_LEFT']);
  }
  async bold(on)      { await this._write(on ? EtelPrinter.CMD.BOLD_ON      : EtelPrinter.CMD.BOLD_OFF); }
  async doubleSize(on){ await this._write(on ? EtelPrinter.CMD.DOUBLE_ON    : EtelPrinter.CMD.DOUBLE_OFF); }
  async underline(on) { await this._write(on ? EtelPrinter.CMD.UNDERLINE_ON : EtelPrinter.CMD.UNDERLINE_OFF); }
  async feed(lines = 1) {
    for (let i = 0; i < lines; i++) await this._write(EtelPrinter.CMD.FEED_LINE);
  }

  /** Plain ASCII/Latin text only — see printImageFromCanvas() for Amharic. */
  async printText(text, { align = 'left', bold = false, doubleSize = false } = {}) {
    await this.align(align);
    await this.bold(bold);
    await this.doubleSize(doubleSize);
    const encoder = new TextEncoder();
    await this._write(encoder.encode(text + '\n'));
    // Reset styling so it doesn't leak into the next line.
    if (bold) await this.bold(false);
    if (doubleSize) await this.doubleSize(false);
  }

  async printDivider(char = '-', width = 32) {
    await this.printText(char.repeat(width));
  }

  /**
   * Structured receipt printer — English/Latin content.
   * { header: string[], items: {name,qty,price}[], total: number,
   *   footer: string[], lineWidth: number (default 32 for 58mm paper) }
   */
  async printReceipt({ header = [], items = [], total = null, footer = [], lineWidth = 32 } = {}) {
    await this.init();

    for (const line of header) await this.printText(line, { align: 'center', bold: true });
    if (header.length) await this.printDivider('-', lineWidth);

    for (const item of items) {
      const qtyPrice = `${item.qty} x ${item.price}`;
      const left = item.name;
      const right = qtyPrice;
      const pad = Math.max(1, lineWidth - left.length - right.length);
      await this.printText(left + ' '.repeat(pad) + right);
    }

    if (total !== null) {
      await this.printDivider('-', lineWidth);
      const label = 'TOTAL';
      const value = String(total);
      const pad = Math.max(1, lineWidth - label.length - value.length);
      await this.printText(label + ' '.repeat(pad) + value, { bold: true });
    }

    if (footer.length) {
      await this.feed(1);
      for (const line of footer) await this.printText(line, { align: 'center' });
    }

    await this.feed(3); // leave room to tear the paper
  }

  /* ─── Raster image printing (for Amharic / logos / anything non-ASCII) ───
   * Render your content onto a <canvas> (any font, any script — including
   * Noto Sans Ethiopic) at the printer's pixel width (203 px for 58mm
   * printers, 384 px for 80mm printers — adjust to match your printer),
   * then pass that canvas here. It will be converted to 1-bit black/white
   * and sent as a raster image the printer prints dot-for-dot, completely
   * bypassing the printer's built-in (Latin-only) font. */
  async printImageFromCanvas(canvas, { threshold = 128 } = {}) {
    const ctx = canvas.getContext('2d');
    const { width, height } = canvas;
    const imageData = ctx.getImageData(0, 0, width, height).data;

    const bytesPerRow = Math.ceil(width / 8);
    const raster = new Uint8Array(bytesPerRow * height);

    for (let y = 0; y < height; y++) {
      for (let x = 0; x < width; x++) {
        const i = (y * width + x) * 4;
        const lum = 0.299 * imageData[i] + 0.587 * imageData[i + 1] + 0.114 * imageData[i + 2];
        const isBlack = lum < threshold;
        if (isBlack) {
          const byteIndex = y * bytesPerRow + (x >> 3);
          raster[byteIndex] |= (0x80 >> (x % 8));
        }
      }
    }

    const xL = bytesPerRow & 0xFF, xH = (bytesPerRow >> 8) & 0xFF;
    const yL = height & 0xFF,      yH = (height >> 8) & 0xFF;
    const header = new Uint8Array([0x1D, 0x76, 0x30, 0x00, xL, xH, yL, yH]); // GS v 0 — print raster bit image

    const full = new Uint8Array(header.length + raster.length);
    full.set(header, 0);
    full.set(raster, header.length);

    await this.align('center');
    await this._write(full);
    await this.feed(1);
  }

  /** Convenience: render a line of text (e.g. Amharic) into a canvas and print it as an image. */
  async printTextAsImage(text, { font = '28px "Noto Sans Ethiopic", sans-serif', width = 384, padding = 8 } = {}) {
    if (typeof document !== 'undefined' && document.fonts && document.fonts.ready) {
      try { await document.fonts.load(font); await document.fonts.ready; } catch (e) { /* font may not be installed — falls back silently */ }
    }
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    ctx.font = font;
    const metrics = ctx.measureText(text);
    canvas.width = width;
    canvas.height = Math.ceil((metrics.fontBoundingBoxAscent || 30) + (metrics.fontBoundingBoxDescent || 10)) + padding * 2;

    const ctx2 = canvas.getContext('2d'); // re-grab after resizing (resize clears context)
    ctx2.fillStyle = '#fff';
    ctx2.fillRect(0, 0, canvas.width, canvas.height);
    ctx2.fillStyle = '#000';
    ctx2.font = font;
    ctx2.textAlign = 'center';
    ctx2.textBaseline = 'top';
    ctx2.fillText(text, canvas.width / 2, padding);

    await this.printImageFromCanvas(canvas);
  }
}

// Make available both as an ES module export and a plain global
// (so it can be dropped into a WordPress page with a plain <script> tag).
if (typeof module !== 'undefined' && module.exports) module.exports = EtelPrinter;
if (typeof window !== 'undefined') window.EtelPrinter = EtelPrinter;
