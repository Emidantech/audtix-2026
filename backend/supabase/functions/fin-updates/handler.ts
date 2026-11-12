// Collects the latest news from Ugandan and East African financial institutions.
// Needs no attention: every visitor's request refreshes a 20 minute server cache.
import registry from "../_shared/sources.json" with { type: "json" };
import { googleNewsUrl, parseFeed, type Item } from "../_shared/rss.ts";

interface Source { id: string; name: string; short: string; group: string; country: string; host: string; site: string; feeds?: string[]; aliases: string[] }
const SOURCES = registry.sources as unknown as Source[];

export interface Deps { fetch: (url: string, init?: RequestInit) => Promise<Response>; now: () => Date }
export interface Update { title: string; url: string; summary: string; publishedAt: string; sourceId: string; source: string; short: string; group: string; country: string; via: "feed" | "news-search" }
export interface Health { id: string; ok: boolean; count: number; via: string; error?: string }
export interface Payload { fetchedAt: string; items: Update[]; sources: Health[] }

const MAX_AGE_DAYS = 90;
const PER_SOURCE = 6;
const TOTAL = 120;
const TTL = 20 * 60 * 1000;
let cache: { at: number; body: Payload } | null = null;
export function resetCache() { cache = null; }

function hostOf(u: string): string { try { return new URL(u).hostname.replace(/^www\./, ""); } catch { return ""; } }
function candidates(s: Source): string[] {
  if (s.feeds && s.feeds.length) return s.feeds;
  const h = s.host;
  return [`https://www.${h}/feed/`, `https://${h}/feed/`, `https://www.${h}/rss.xml`, `https://www.${h}/feed`];
}
async function get(deps: Deps, url: string, ms = 7000): Promise<string> {
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), ms);
  try {
    const r = await deps.fetch(url, { signal: ctl.signal, headers: { "User-Agent": "AudtixFinanceDesk/1.0 (+https://audtix.com)", Accept: "application/rss+xml, application/atom+xml, application/xml, text/xml, */*" } });
    if (!r.ok) throw new Error("HTTP " + r.status);
    return await r.text();
  } finally { clearTimeout(t); }
}

async function collect(s: Source, deps: Deps): Promise<{ items: Item[]; via: "feed" | "news-search"; error?: string }> {
  let lastErr = "";
  for (const url of candidates(s)) {
    try {
      const items = parseFeed(await get(deps, url));
      if (items.length) return { items: items.filter((i) => hostOf(i.url).endsWith(s.host)), via: "feed" };
      lastErr = "feed empty";
    } catch (e) { lastErr = e instanceof Error ? e.message : String(e); }
  }
  // Fallback: Google News limited to the institution's own website.
  try {
    const items = parseFeed(await get(deps, googleNewsUrl(`site:${s.host}`, MAX_AGE_DAYS)));
    const own = items.filter((i) => hostOf(i.sourceUrl).endsWith(s.host));
    if (own.length) return { items: own, via: "news-search" };
    lastErr = lastErr || "no items";
  } catch (e) { lastErr = e instanceof Error ? e.message : String(e); }
  return { items: [], via: "feed", error: lastErr };
}

export async function build(deps: Deps): Promise<Payload> {
  const now = deps.now().getTime();
  const cutoff = now - MAX_AGE_DAYS * 86400000;
  const results = await Promise.all(SOURCES.map(async (s) => ({ s, r: await collect(s, deps) })));
  const all: Update[] = [];
  const health: Health[] = [];
  const seen = new Set<string>();
  for (const { s, r } of results) {
    const mine = r.items
      .filter((i) => { const t = Date.parse(i.publishedAt); return t >= cutoff && t <= now + 86400000; })
      .sort((a, b) => Date.parse(b.publishedAt) - Date.parse(a.publishedAt))
      .slice(0, PER_SOURCE);
    for (const i of mine) {
      const key = i.title.toLowerCase().slice(0, 70);
      if (seen.has(key)) continue;
      seen.add(key);
      all.push({ title: i.title, url: i.url, summary: i.summary, publishedAt: i.publishedAt, sourceId: s.id, source: s.name, short: s.short, group: s.group, country: s.country, via: r.via });
    }
    health.push({ id: s.id, ok: mine.length > 0, count: mine.length, via: r.via, ...(r.error && !mine.length ? { error: r.error } : {}) });
  }
  all.sort((a, b) => Date.parse(b.publishedAt) - Date.parse(a.publishedAt));
  return { fetchedAt: deps.now().toISOString(), items: all.slice(0, TOTAL), sources: health };
}

export async function handle(req: Request, deps: Deps, origins: string[]): Promise<Response> {
  const origin = req.headers.get("origin") || "";
  const allow = origins.includes("*") ? "*" : origins.includes(origin) ? origin : origins[0];
  const headers = { "Access-Control-Allow-Origin": allow, "Content-Type": "application/json", "Cache-Control": "public, max-age=300", Vary: "Origin" };
  if (req.method === "OPTIONS") return new Response(null, { status: 204, headers: { ...headers, "Access-Control-Allow-Headers": "content-type, authorization, apikey" } });
  if (req.method !== "GET") return new Response('{"error":"method_not_allowed"}', { status: 405, headers });
  if (cache && deps.now().getTime() - cache.at < TTL) return new Response(JSON.stringify(cache.body), { headers });
  const body = await build(deps);
  if (body.items.length) cache = { at: deps.now().getTime(), body };
  return new Response(JSON.stringify(body), { headers });
}
