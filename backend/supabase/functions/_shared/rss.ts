// Minimal RSS and Atom reader. Pure functions so they can be tested anywhere.
export interface Item { title: string; url: string; source: string; sourceUrl: string; publishedAt: string; summary: string }

function decode(s: string): string {
  return s.replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, "$1").replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&quot;/g, '"').replace(/&#39;|&apos;/g, "'").replace(/&nbsp;/g, " ").replace(/&amp;/g, "&");
}
function strip(s: string): string { return decode(s).replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim(); }
function tag(block: string, name: string): string {
  const m = block.match(new RegExp(`<${name}(?:\\s[^>]*)?>([\\s\\S]*?)</${name}>`, "i"));
  return m ? m[1] : "";
}
function attr(block: string, name: string, a: string): string {
  const m = block.match(new RegExp(`<${name}\\s[^>]*?${a}="([^"]*)"`, "i"));
  return m ? decode(m[1]) : "";
}

export function parseFeed(xml: string, max = 30): Item[] {
  const out: Item[] = [];
  const blocks = [...xml.matchAll(/<item[\s>]([\s\S]*?)<\/item>/gi), ...xml.matchAll(/<entry[\s>]([\s\S]*?)<\/entry>/gi)];
  for (const m of blocks) {
    const b = m[1];
    let title = strip(tag(b, "title"));
    let url = strip(tag(b, "link"));
    if (!url) url = attr(b, "link", "href"); // Atom
    const when = new Date(strip(tag(b, "pubDate")) || strip(tag(b, "published")) || strip(tag(b, "updated")) || strip(tag(b, "dc:date")));
    let source = strip(tag(b, "source"));
    const sourceUrl = attr(b, "source", "url");
    const suffix = title.match(/^(.*) - ([^-]{2,60})$/);
    if (suffix && source && suffix[2].trim() === source.trim()) title = suffix[1];
    else if (suffix && !source && /news\.google\.com/.test(url)) { source = suffix[2]; title = suffix[1]; }
    const summary = strip(tag(b, "description") || tag(b, "summary") || tag(b, "content:encoded")).slice(0, 240);
    if (!title || !/^https?:\/\//.test(url) || Number.isNaN(when.getTime())) continue;
    out.push({ title: title.trim(), url, source: source.trim(), sourceUrl, publishedAt: when.toISOString(), summary });
    if (out.length >= max) break;
  }
  return out;
}
export const parseRss = parseFeed;

export function googleNewsUrl(q: string, days = 14): string {
  return "https://news.google.com/rss/search?q=" + encodeURIComponent(`${q} when:${days}d`) + "&hl=en-UG&gl=UG&ceid=UG:en";
}
export const feedUrl = (q: string): string => googleNewsUrl(q, 14);
