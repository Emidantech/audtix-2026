import assert from "node:assert/strict";
import { parseRss, feedUrl } from "../supabase/functions/_shared/rss.ts";
const xml = `<?xml version="1.0"?><rss><channel><item><title>Shilling steadies &amp; holds - Daily Monitor</title><link>https://news.google.com/rss/articles/abc</link><pubDate>Sun, 08 Nov 2026 07:00:00 GMT</pubDate><description>&lt;a href="x"&gt;x&lt;/a&gt;</description><source url="https://monitor.co.ug">Daily Monitor</source></item>
<item><title><![CDATA[Dollar slips - Reuters]]></title><link>https://news.google.com/rss/articles/def</link><pubDate>Sat, 07 Nov 2026 10:00:00 GMT</pubDate></item>
<item><title>No link</title><pubDate>Sat, 07 Nov 2026 10:00:00 GMT</pubDate></item>
<item><title>Bad date - X</title><link>https://a.b/c</link><pubDate>nonsense</pubDate></item></channel></rss>`;
const items = parseRss(xml);
assert.equal(items.length, 2);
assert.equal(items[0].title, "Shilling steadies & holds"); assert.equal(items[0].source, "Daily Monitor"); assert.equal(items[0].sourceUrl, "https://monitor.co.ug"); assert.equal(items[0].publishedAt, "2026-11-08T07:00:00.000Z");
assert.equal(items[1].title, "Dollar slips"); assert.equal(items[1].source, "Reuters");
assert.ok(feedUrl("USD UGX").includes("when%3A14d"));
console.log("rss tests passed");
