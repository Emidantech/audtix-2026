import assert from "node:assert/strict"; import fs from "node:fs";
import { validateSubmission } from "../supabase/functions/tax-request/validate.ts";
const r = validateSubmission(JSON.parse(fs.readFileSync(process.argv[2], "utf8")));
assert.ok(r.ok, JSON.stringify(r.errors)); console.log("browser payload accepted by server validator", r.clean.services);
