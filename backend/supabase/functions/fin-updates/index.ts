// Supabase Edge Function: financial updates from Ugandan and East African institutions.
// Deploy with:  supabase functions deploy fin-updates --no-verify-jwt
// The Free tools page calls it with a plain GET. Add or remove institutions in ../_shared/sources.json.
import { handle } from "./handler.ts";

// deno-lint-ignore no-explicit-any
declare const Deno: any;

Deno.serve((req: Request) =>
  handle(req, { fetch: (u, i) => fetch(u, i), now: () => new Date() }, (Deno.env.get("ALLOWED_ORIGINS") || "*").split(",").map((s: string) => s.trim())),
);
