// Generates the WordPress.org directory icon and banner PNGs.
// Renders at 4x and box-downsamples for clean anti-aliased edges.
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const SS = 4; // supersample factor

/* ---------- PNG encoding ---------- */

const crcTable = (() => {
  const t = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c;
  }
  return t;
})();

function crc32(buf) {
  let c = -1;
  for (let i = 0; i < buf.length; i++) c = crcTable[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
}

function chunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}

function encodePNG(w, h, rgba) {
  const raw = Buffer.alloc((w * 4 + 1) * h);
  let o = 0;
  for (let y = 0; y < h; y++) {
    raw[o++] = 0; // filter: none
    rgba.copy(raw, o, y * w * 4, (y + 1) * w * 4);
    o += w * 4;
  }
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8;  // bit depth
  ihdr[9] = 6;  // colour type RGBA
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

/* ---------- canvas ---------- */

function canvas(w, h) {
  const W = w * SS, H = h * SS;
  const px = new Float32Array(W * H * 4);

  function blend(x, y, r, g, b, a) {
    if (x < 0 || y < 0 || x >= W || y >= H || a <= 0) return;
    const i = (y * W + x) * 4;
    const ia = 1 - a;
    px[i] = px[i] * ia + r * a;
    px[i + 1] = px[i + 1] * ia + g * a;
    px[i + 2] = px[i + 2] * ia + b * a;
    px[i + 3] = px[i + 3] * ia + a;
  }

  return {
    W, H,
    // Vertical/diagonal linear gradient across the whole canvas.
    gradient(c0, c1, angle = 0.35) {
      for (let y = 0; y < H; y++) {
        for (let x = 0; x < W; x++) {
          const t = Math.min(1, Math.max(0, (x / W) * angle + (y / H) * (1 - angle)));
          blend(x, y, c0[0] + (c1[0] - c0[0]) * t, c0[1] + (c1[1] - c0[1]) * t, c0[2] + (c1[2] - c0[2]) * t, 1);
        }
      }
    },
    rect(x, y, w, h, c, a = 1) {
      const x0 = Math.round(x * SS), y0 = Math.round(y * SS);
      const x1 = Math.round((x + w) * SS), y1 = Math.round((y + h) * SS);
      for (let yy = y0; yy < y1; yy++) for (let xx = x0; xx < x1; xx++) blend(xx, yy, c[0], c[1], c[2], a);
    },
    roundRect(x, y, w, h, r, c, a = 1) {
      const x0 = x * SS, y0 = y * SS, w0 = w * SS, h0 = h * SS, r0 = r * SS;
      for (let yy = Math.floor(y0); yy < Math.ceil(y0 + h0); yy++) {
        for (let xx = Math.floor(x0); xx < Math.ceil(x0 + w0); xx++) {
          const cx = Math.min(Math.max(xx + 0.5, x0 + r0), x0 + w0 - r0);
          const cy = Math.min(Math.max(yy + 0.5, y0 + r0), y0 + h0 - r0);
          const dx = xx + 0.5 - cx, dy = yy + 0.5 - cy;
          if (dx * dx + dy * dy <= r0 * r0) blend(xx, yy, c[0], c[1], c[2], a);
        }
      }
    },
    circle(cx, cy, r, c, a = 1) {
      const cx0 = cx * SS, cy0 = cy * SS, r0 = r * SS;
      for (let yy = Math.floor(cy0 - r0); yy <= Math.ceil(cy0 + r0); yy++) {
        for (let xx = Math.floor(cx0 - r0); xx <= Math.ceil(cx0 + r0); xx++) {
          const dx = xx + 0.5 - cx0, dy = yy + 0.5 - cy0;
          if (dx * dx + dy * dy <= r0 * r0) blend(xx, yy, c[0], c[1], c[2], a);
        }
      }
    },
    ring(cx, cy, rOuter, rInner, c, a = 1, fromAngle = -Math.PI, toAngle = Math.PI) {
      const cx0 = cx * SS, cy0 = cy * SS, ro = rOuter * SS, ri = rInner * SS;
      for (let yy = Math.floor(cy0 - ro); yy <= Math.ceil(cy0 + ro); yy++) {
        for (let xx = Math.floor(cx0 - ro); xx <= Math.ceil(cx0 + ro); xx++) {
          const dx = xx + 0.5 - cx0, dy = yy + 0.5 - cy0;
          const d2 = dx * dx + dy * dy;
          if (d2 > ro * ro || d2 < ri * ri) continue;
          const ang = Math.atan2(dy, dx);
          if (ang < fromAngle || ang > toAngle) continue;
          blend(xx, yy, c[0], c[1], c[2], a);
        }
      }
    },
    // Triangle via barycentric coverage, used for the folded document corner.
    tri(p0, p1, p2, c, a = 1) {
      const P = [p0, p1, p2].map(p => [p[0] * SS, p[1] * SS]);
      const minX = Math.floor(Math.min(...P.map(p => p[0])));
      const maxX = Math.ceil(Math.max(...P.map(p => p[0])));
      const minY = Math.floor(Math.min(...P.map(p => p[1])));
      const maxY = Math.ceil(Math.max(...P.map(p => p[1])));
      const area = (P[1][0] - P[0][0]) * (P[2][1] - P[0][1]) - (P[2][0] - P[0][0]) * (P[1][1] - P[0][1]);
      if (area === 0) return;
      for (let yy = minY; yy <= maxY; yy++) {
        for (let xx = minX; xx <= maxX; xx++) {
          const x = xx + 0.5, y = yy + 0.5;
          const w0 = ((P[1][0] - P[0][0]) * (y - P[0][1]) - (x - P[0][0]) * (P[1][1] - P[0][1])) / area;
          const w1 = ((x - P[0][0]) * (P[2][1] - P[0][1]) - (P[2][0] - P[0][0]) * (y - P[0][1])) / area;
          if (w0 >= 0 && w1 >= 0 && w0 + w1 <= 1) blend(xx, yy, c[0], c[1], c[2], a);
        }
      }
    },
    save(file, w, h) {
      const out = Buffer.alloc(w * h * 4);
      for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
          let r = 0, g = 0, b = 0, a = 0;
          for (let sy = 0; sy < SS; sy++) {
            for (let sx = 0; sx < SS; sx++) {
              const i = ((y * SS + sy) * W + (x * SS + sx)) * 4;
              r += px[i]; g += px[i + 1]; b += px[i + 2]; a += px[i + 3];
            }
          }
          const n = SS * SS, o = (y * w + x) * 4;
          out[o] = Math.round(r / n);
          out[o + 1] = Math.round(g / n);
          out[o + 2] = Math.round(b / n);
          out[o + 3] = Math.round(Math.min(255, (a / n) * 255));
        }
      }
      fs.writeFileSync(file, encodePNG(w, h, out));
      console.log('wrote', path.basename(file), `${w}x${h}`, fs.statSync(file).size, 'bytes');
    },
  };
}

/* ---------- palette ---------- */

const NAVY_DARK = [17, 26, 51];
const NAVY_MID = [32, 52, 104];
const INDIGO = [60, 88, 178];
const PAPER = [248, 250, 253];
const PAPER_EDGE = [214, 222, 236];
const RED = [214, 54, 56];
const GOLD = [247, 184, 61];

/* ---------- shared artwork ---------- */

// A sheet of paper with a folded top-right corner and a red PDF band,
// with a padlock shackle arcing over it.
function drawMark(cv, cx, cy, size) {
  const w = size * 0.62;
  const h = size * 0.80;
  const x = cx - w / 2;
  const y = cy - h / 2 + size * 0.04;
  const fold = size * 0.20;

  // Soft shadow.
  cv.roundRect(x + size * 0.025, y + size * 0.03, w, h, size * 0.05, [0, 0, 0], 0.18);

  // Page body.
  cv.roundRect(x, y, w, h, size * 0.05, PAPER, 1);
  // Clip the top-right corner away, then lay the fold over it.
  cv.tri([x + w - fold, y], [x + w, y], [x + w, y + fold], NAVY_DARK, 1);
  cv.tri([x + w - fold, y], [x + w, y + fold], [x + w - fold, y + fold], PAPER_EDGE, 1);

  // Red PDF band.
  cv.rect(x + w * 0.10, y + h * 0.52, w * 0.80, h * 0.155, RED, 1);
  // Text lines.
  cv.rect(x + w * 0.16, y + h * 0.28, w * 0.50, h * 0.035, PAPER_EDGE, 1);
  cv.rect(x + w * 0.16, y + h * 0.38, w * 0.38, h * 0.035, PAPER_EDGE, 1);
  cv.rect(x + w * 0.16, y + h * 0.80, w * 0.44, h * 0.035, PAPER_EDGE, 1);

  // Padlock: shackle arc plus body, sitting on the lower-right corner.
  const lx = x + w * 0.90;
  const ly = y + h * 0.88;
  const lw = size * 0.30;
  const lh = size * 0.22;
  cv.ring(lx, ly - lh * 0.34, lw * 0.34, lw * 0.20, GOLD, 1, -Math.PI, 0);
  cv.roundRect(lx - lw / 2, ly - lh * 0.34, lw, lh, size * 0.022, GOLD, 1);
  cv.circle(lx, ly + lh * 0.12, size * 0.026, NAVY_DARK, 1);
}

/* ---------- icon ---------- */

function icon(size, file) {
  const cv = canvas(size, size);
  cv.gradient(NAVY_MID, NAVY_DARK, 0.5);
  // Corner glow.
  cv.circle(size * 0.18, size * 0.14, size * 0.48, INDIGO, 0.22);
  drawMark(cv, size * 0.5, size * 0.47, size * 0.86);
  cv.save(file, size, size);
}

/* ---------- banner ---------- */

function banner(w, h, file) {
  const cv = canvas(w, h);
  cv.gradient(NAVY_DARK, NAVY_MID, 0.85);

  // Diagonal light streaks for depth.
  for (let i = 0; i < 7; i++) {
    const bw = w * 0.055;
    const bx = w * 0.42 + i * bw * 1.9;
    cv.tri([bx, h], [bx + bw, h], [bx + bw + h * 0.42, 0], INDIGO, 0.10);
    cv.tri([bx, h], [bx + bw + h * 0.42, 0], [bx + h * 0.42, 0], INDIGO, 0.10);
  }
  cv.circle(w * 0.80, h * 0.20, h * 0.75, INDIGO, 0.16);
  cv.circle(w * 0.06, h * 0.92, h * 0.55, INDIGO, 0.12);

  drawMark( cv, w * 0.20, h * 0.50, h * 0.82 );

  cv.save(file, w, h);
}

/* ---------- run ---------- */

const out = process.argv[2];
fs.mkdirSync(out, { recursive: true });
icon(128, path.join(out, 'icon-128x128.png'));
icon(256, path.join(out, 'icon-256x256.png'));
banner(772, 250, path.join(out, 'banner-772x250.png'));
banner(1544, 500, path.join(out, 'banner-1544x500.png'));
