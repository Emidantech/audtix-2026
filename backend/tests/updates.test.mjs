import assert from "node:assert/strict";
import { handle, build, resetCache } from "../supabase/functions/fin-updates/handler.ts";
import { parseFeed } from "../supabase/functions/_shared/rss.ts";

const NOW = new Date("2026-11-08T09:00:00Z");
const rss = (items) => `<?xml version="1.0"?><rss><channel>${items.map(([t, l, d, extra = ""]) => `<item><title>${t}</title><link>${l}</link><pubDate>${d}</pubDate><description>&lt;p&gt;Body &amp; text&lt;/p&gt;</description>${extra}</item>`).join("")}</channel></rss>`;
const atom = `<feed xmlns="http://www.w3.org/2005/Atom"><entry><title>Atom item</title><link href="https://www.ubos.org/a1"/><updated>2026-11-05T10:00:00Z</updated><summary>Atom summary</summary></entry></feed>`;

// parser
let items = parseFeed(atom); assert.equal(items.length, 1); assert.equal(items[0].url, "https://www.ubos.org/a1"); assert.equal(items[0].summary, "Atom summary");
items = parseFeed(rss([["A &amp; B", "https://x.org/1", "Fri, 06 Nov 2026 08:00:00 GMT"]])); assert.equal(items[0].title, "A & B"); assert.equal(items[0].summary, "Body & text");

const calls = [];
function mkDeps() {
  calls.length = 0;
  return { now: () => NOW, fetch: async (url) => {
    calls.push(url);
    const ok = (body) => new Response(body, { status: 200 });
    if (url === "https://ura.go.ug/en/category/ura-news/feed/") return ok(rss([
      ["URA extends filing deadline", "https://ura.go.ug/en/news/1", "Sat, 07 Nov 2026 08:00:00 GMT"],
      ["Old notice", "https://ura.go.ug/en/news/0", "Mon, 01 Jan 2026 08:00:00 GMT"],
      ["Off-site link", "https://evil.example/x", "Sat, 07 Nov 2026 09:00:00 GMT"],
      ["Future dated", "https://ura.go.ug/en/news/f", "Wed, 01 Jan 2031 08:00:00 GMT"]]));
    if (url === "https://www.ubos.org/feed/") return new Response("nope", { status: 404 });
    if (url === "https://ubos.org/feed/") return ok(atom);
    if (url.startsWith("https://news.google.com/rss/search") && decodeURIComponent(url).includes("site:bou.or.ug")) return ok(rss([
      ["Bank of Uganda holds rate - Bank of Uganda", "https://news.google.com/rss/articles/a", "Fri, 06 Nov 2026 12:00:00 GMT", '<source url="https://www.bou.or.ug">Bank of Uganda</source>'],
      ["Imposter story - Some Blog", "https://news.google.com/rss/articles/b", "Fri, 06 Nov 2026 12:00:00 GMT", '<source url="https://someblog.example">Some Blog</source>']]));
    return new Response("down", { status: 503 });
  } };
}

const p = await build(mkDeps());
const by = Object.fromEntries(p.items.map((i) => [i.sourceId, i]));
assert.ok(by.ura && by.ura.title === "URA extends filing deadline");
assert.equal(p.items.filter((i) => i.sourceId === "ura").length, 1, "old, off-site and future items removed");
assert.ok(by.ubos && by.ubos.via === "feed", "second candidate feed used after a 404");
assert.ok(by.bou && by.bou.via === "news-search" && by.bou.title === "Bank of Uganda holds rate", "news-search fallback with site check");
assert.ok(!p.items.some((i) => /Imposter/.test(i.title)));
const h = Object.fromEntries(p.sources.map((s) => [s.id, s]));
assert.equal(h.ura.ok, true); assert.equal(h.nssf.ok, false); assert.ok(h.nssf.error);
assert.deepEqual(p.items.map((i) => i.publishedAt), [...p.items.map((i) => i.publishedAt)].sort().reverse());
assert.equal(p.sources.length, 23);

// handler: cache + CORS + method
resetCache(); const d = mkDeps();
let r = await handle(new Request("https://x/f", { headers: { origin: "https://audtix.com" } }), d, ["https://audtix.com"]); assert.equal(r.status, 200); assert.equal(r.headers.get("access-control-allow-origin"), "https://audtix.com");
const n = calls.length; r = await handle(new Request("https://x/f"), d, ["*"]); assert.equal(calls.length, n, "second request served from cache");
assert.equal((await handle(new Request("https://x/f", { method: "POST" }), d, ["*"])).status, 405);
console.log("updates tests passed");
