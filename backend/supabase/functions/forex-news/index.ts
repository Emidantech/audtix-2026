// Supabase Edge Function: fetches recent forex headlines on the server (browsers cannot read RSS feeds directly).
// Deploy with:  supabase functions deploy forex-news --no-verify-jwt
// The Free tools page calls it as  <endpoint>?q=Uganda+shilling+dollar+exchange+rate  and keeps only trusted publishers.
import { feedUrl, parseRss } from "../_shared/rss.ts";

// deno-lint-ignore no-explicit-any
declare const Deno: any;

const cache = new Map<string, { at: number; body: string }>();
const TTL = 10 * 60 * 1000;

Deno.serve(async (req: Request) => {
  const origins = (Deno.env.get("ALLOWED_ORIGINS") || "*").split(",").map((s: string) => s.trim());
  const origin = req.headers.get("origin") || "";
  const allow = origins.includes("*") ? "*" : origins.includes(origin) ? origin : origins[0];
  const headers = { "Access-Control-Allow-Origin": allow, "Content-Type": "application/json", "Cache-Control": "public, max-age=300", Vary: "Origin" };
  if (req.method === "OPTIONS") return new Response(null, { status: 204, headers: { ...headers, "Access-Control-Allow-Headers": "content-type, authorization, apikey" } });
  if (req.method !== "GET") return new Response('{"items":[]}', { status: 405, headers });

  const q = (new URL(req.url).searchParams.get("q") || "").replace(/[^\w\s.\-/]/g, " ").trim().slice(0, 80);
  if (q.length < 3) return new Response('{"items":[]}', { status: 400, headers });
  const hit = cache.get(q);
  if (hit && Date.now() - hit.at < TTL) return new Response(hit.body, { headers });
  try {
    const r = await fetch(feedUrl(q), { signal: AbortSignal.timeout(10000), headers: { "User-Agent": "AudtixForexDesk/1.0" } });
    if (!r.ok) throw new Error("feed " + r.status);
    const body = JSON.stringify({ fetchedAt: new Date().toISOString(), items: parseRss(await r.text()) });
    cache.set(q, { at: Date.now(), body });
    return new Response(body, { headers });
  } catch (_e) {
    return new Response('{"items":[],"error":"feed_unavailable"}', { status: 502, headers });
  }
});
