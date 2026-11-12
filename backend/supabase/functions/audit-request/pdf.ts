// Builds ONE PDF: the completed form first, then every uploaded document (PDF pages appended,
// photos placed one per page). The pdf-lib module is passed in so this runs in Deno and Node.
import { docLabel, kampalaTime, rowsFor, type Clean } from "./validate.ts";

// deno-lint-ignore no-explicit-any
type Lib = any;

export interface UploadedFile {
  docType: string;
  name: string;
  mime: string;
  bytes: Uint8Array;
}
export interface BuiltPdf {
  pdf: Uint8Array;
  merged: number;
  notMerged: UploadedFile[]; // files pdf-lib could not read (for example password-protected PDFs)
}

const A4: [number, number] = [595.28, 841.89];
const M = 48;
const BLUE = [0.016, 0.475, 0.933];
const INK = [0.043, 0.106, 0.2];
const MUTED = [0.28, 0.35, 0.46];

// Standard PDF fonts only cover Latin-1 text; anything else becomes "?".
function latin(s: string): string {
  return s.replace(/[\u2018\u2019]/g, "'").replace(/[\u201C\u201D]/g, '"').replace(/[\u2013\u2014]/g, "-").replace(/\u2026/g, "...").replace(/[\r\t]/g, " ").replace(/[^\n\x20-\x7E -ÿ]/g, "?");
}

export async function buildPdf(lib: Lib, c: Clean, reference: string, whenDate: Date, files: UploadedFile[]): Promise<BuiltPdf> {
  const { PDFDocument, StandardFonts, rgb } = lib;
  const when = kampalaTime(whenDate);
  const doc = await PDFDocument.create();
  doc.setTitle(`Audit service request ${reference}`);
  doc.setAuthor("Audtix Systems");
  doc.setCreator("Audtix Systems website");
  const font = await doc.embedFont(StandardFonts.Helvetica);
  const bold = await doc.embedFont(StandardFonts.HelveticaBold);
  const col = (a: number[]) => rgb(a[0], a[1], a[2]);

  let page = doc.addPage(A4);
  let y = A4[1] - M;
  const width = A4[0] - M * 2;

  const newPage = () => {
    page = doc.addPage(A4);
    y = A4[1] - M;
  };
  const wrap = (text: string, f: Lib, size: number, maxW: number): string[] => {
    const out: string[] = [];
    for (const para of latin(text).split("\n")) {
      let line = "";
      for (const word of para.split(" ")) {
        let w = word;
        while (f.widthOfTextAtSize(w, size) > maxW) {
          // break very long tokens
          let n = w.length;
          while (n > 1 && f.widthOfTextAtSize(w.slice(0, n), size) > maxW) n--;
          if (line) { out.push(line); line = ""; }
          out.push(w.slice(0, n));
          w = w.slice(n);
        }
        const t = line ? line + " " + w : w;
        if (f.widthOfTextAtSize(t, size) <= maxW) line = t;
        else { out.push(line); line = w; }
      }
      out.push(line);
    }
    return out;
  };

  // Header band
  page.drawRectangle({ x: 0, y: A4[1] - 92, width: A4[0], height: 92, color: col(BLUE) });
  page.drawText("Audtix Systems", { x: M, y: A4[1] - 44, size: 20, font: bold, color: rgb(1, 1, 1) });
  page.drawText("Audit service request", { x: M, y: A4[1] - 68, size: 13, font, color: rgb(1, 1, 1) });
  page.drawText(latin(reference), { x: A4[0] - M - bold.widthOfTextAtSize(latin(reference), 14), y: A4[1] - 44, size: 14, font: bold, color: rgb(1, 1, 1) });
  const wtxt = latin(when);
  page.drawText(wtxt, { x: A4[0] - M - font.widthOfTextAtSize(wtxt, 10), y: A4[1] - 68, size: 10, font, color: rgb(1, 1, 1) });
  y = A4[1] - 92 - 30;

  const labelW = 170;
  for (const [k, v] of rowsFor(c)) {
    const lines = wrap(v, font, 10.5, width - labelW - 8);
    const klines = wrap(k, font, 9.5, labelW - 8);
    const h = Math.max(lines.length * 14, klines.length * 13) + 12;
    if (y - h < M + 20) newPage();
    page.drawLine({ start: { x: M, y: y + 4 }, end: { x: M + width, y: y + 4 }, thickness: 0.5, color: rgb(0.83, 0.89, 0.95) });
    klines.forEach((l, i) => page.drawText(l, { x: M, y: y - 10 - i * 13, size: 9.5, font, color: col(MUTED) }));
    lines.forEach((l, i) => page.drawText(l, { x: M + labelW, y: y - 10 - i * 14, size: 10.5, font, color: col(INK) }));
    y -= h;
  }

  // Attachments summary
  if (files.length) {
    if (y - 60 < M) newPage();
    y -= 14;
    page.drawText("Documents attached to this PDF", { x: M, y, size: 12, font: bold, color: col(BLUE) });
    y -= 18;
    for (const f of files) {
      const l = wrap(`${docLabel(f.docType)}: ${f.name} (${Math.max(1, Math.round(f.bytes.length / 1024))} KB)`, font, 10, width);
      for (const t of l) {
        if (y < M + 14) newPage();
        page.drawText(t, { x: M, y, size: 10, font, color: col(INK) });
        y -= 13;
      }
    }
  }

  const formPages = doc.getPageCount();

  // Uploaded documents
  const notMerged: UploadedFile[] = [];
  let merged = 0;
  const divider = (f: UploadedFile, note?: string) => {
    const p = doc.addPage(A4);
    p.drawRectangle({ x: 0, y: A4[1] - 60, width: A4[0], height: 60, color: col(BLUE) });
    p.drawText(latin(`Attachment: ${docLabel(f.docType)}`), { x: M, y: A4[1] - 38, size: 14, font: bold, color: rgb(1, 1, 1) });
    p.drawText(latin(f.name).slice(0, 90), { x: M, y: A4[1] - 84, size: 10, font, color: col(MUTED) });
    if (note) p.drawText(latin(note), { x: M, y: A4[1] - 110, size: 11, font, color: col(INK) });
    return p;
  };

  for (const f of files) {
    try {
      if (f.mime === "application/pdf") {
        const src = await PDFDocument.load(f.bytes, { ignoreEncryption: false, updateMetadata: false });
        const idx = src.getPageIndices();
        if (idx.length === 0) throw new Error("empty pdf");
        const first = idx.length;
        divider(f, `${first} page${first === 1 ? "" : "s"} follow.`);
        const pages = await doc.copyPages(src, idx);
        pages.forEach((p: Lib) => doc.addPage(p));
      } else {
        const img = f.mime === "image/png" ? await doc.embedPng(f.bytes) : await doc.embedJpg(f.bytes);
        const p = doc.addPage(A4);
        p.drawText(latin(`${docLabel(f.docType)}: ${f.name}`).slice(0, 100), { x: M, y: A4[1] - M + 14, size: 9, font, color: col(MUTED) });
        const maxW = A4[0] - M * 2, maxH = A4[1] - M * 2 - 20;
        const k = Math.min(maxW / img.width, maxH / img.height, 1.5);
        const w = img.width * k, h = img.height * k;
        p.drawImage(img, { x: (A4[0] - w) / 2, y: A4[1] - M - 10 - h, width: w, height: h });
      }
      merged++;
    } catch (_e) {
      notMerged.push(f);
      divider(f, "This file could not be merged (it may be password protected or damaged). It is attached to the email separately.");
    }
  }

  const total = doc.getPageCount();
  doc.getPages().slice(0, formPages).forEach((p: Lib, i: number) => {
    const t = `${latin(reference)}  |  Page ${i + 1} of ${total}`;
    p.drawText(t, { x: A4[0] / 2 - font.widthOfTextAtSize(t, 8) / 2, y: 22, size: 8, font, color: col(MUTED) });
  });
  return { pdf: await doc.save(), merged, notMerged };
}
