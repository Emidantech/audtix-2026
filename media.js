/* Media section: media.html (list with type tabs) and post.html?slug=... (one article). Data comes from api/posts.php. */
(function () {
  var API = "api/posts.php";
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function safeUrl(u) { return /^\/uploads\/[a-z]+\/[0-9]{8}-[a-f0-9]{12}\.(png|jpe?g|webp|gif|pdf)$/.test(u || "") ? u.slice(1) : ""; }
  function date(d) { if (!d) return ""; var p = d.split("-"); return +p[2] + " " + ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"][+p[1] - 1] + " " + p[0]; }
  function get(q) { return fetch(API + q, { credentials: "omit" }).then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); }); }
  function card(p, big) {
    var img = safeUrl(p.cover);
    return '<a class="m-card' + (big ? " big" : "") + '" href="post.html?slug=' + encodeURIComponent(p.slug) + '">' +
      '<span class="m-img">' + (img ? '<img src="' + esc(img) + '" alt="" loading="lazy">' : '<span class="m-ph" aria-hidden="true">' + esc(p.type_name.charAt(0)) + "</span>") + "</span>" +
      '<span class="m-body"><span class="m-tag">' + esc(p.type_name) + (p.pdf ? " · PDF" : "") + "</span><b>" + esc(p.title) + "</b><span class=\"m-ex\">" + esc(p.excerpt) + '</span><span class="m-meta">' + esc(date(p.date)) + " · " + p.minutes + " min read</span></span></a>";
  }

  /* ---------- list page ---------- */
  if ($("m-grid")) {
    var params = new URLSearchParams(location.search), type = params.get("type") || "", page = 1, loading = false;
    function load(reset) {
      if (loading) return; loading = true;
      if (reset) { page = 1; $("m-grid").innerHTML = '<p class="m-empty">Loading…</p>'; $("m-feature").innerHTML = ""; }
      get("?type=" + encodeURIComponent(type) + "&page=" + page).then(function (x) {
        loading = false;
        if (!x.j.ok) throw new Error();
        var tabs = [{ name: "All", slug: "" }].concat(x.j.types);
        $("m-tabs").innerHTML = tabs.map(function (t) { return '<a href="media.html' + (t.slug ? "?type=" + encodeURIComponent(t.slug) : "") + '" data-type="' + esc(t.slug) + '"' + (t.slug === x.j.type ? ' class="on" aria-current="page"' : "") + ">" + esc(t.name) + "</a>"; }).join("");
        var posts = x.j.posts;
        if (reset) {
          $("m-grid").innerHTML = "";
          var cur = x.j.types.filter(function (t) { return t.slug === x.j.type; })[0];
          document.title = (cur ? cur.name + " | " : "Media | ") + "Audtix Systems";
          if (!posts.length) { $("m-grid").innerHTML = '<p class="m-empty">Nothing here yet. Check back soon.</p>'; }
          else if (page === 1 && posts.length > 2) { $("m-feature").innerHTML = card(posts[0], true); posts = posts.slice(1); }
        }
        $("m-grid").insertAdjacentHTML("beforeend", posts.map(function (p) { return card(p); }).join(""));
        $("m-more").hidden = x.j.page >= x.j.pages;
      }).catch(function () { loading = false; $("m-grid").innerHTML = '<p class="m-empty">The posts could not be loaded. Please refresh the page.</p>'; });
    }
    $("m-more").addEventListener("click", function () { page++; load(false); });
    $("m-tabs").addEventListener("click", function (e) {
      var a = e.target.closest("a[data-type]"); if (!a) return;
      e.preventDefault(); type = a.getAttribute("data-type");
      history.replaceState(null, "", "media.html" + (type ? "?type=" + encodeURIComponent(type) : "")); load(true);
    });
    load(true);
  }

  /* ---------- article page ---------- */
  if ($("p-article")) {
    var slug = new URLSearchParams(location.search).get("slug") || "";
    get("?slug=" + encodeURIComponent(slug)).then(function (x) {
      if (!x.j.ok) {
        $("p-title").textContent = "Post not found";
        $("p-article").innerHTML = '<p>This post may have been moved or unpublished. <a href="media.html">See all posts</a>.</p>';
        return;
      }
      var p = x.j.post, img = safeUrl(p.cover), pdf = p.pdf && safeUrl(p.pdf.url);
      document.title = p.title + " | Audtix Systems";
      var d = document.querySelector('meta[name="description"]'); if (d) d.setAttribute("content", p.excerpt);
      $("p-type").textContent = p.type_name; $("p-type").href = "media.html?type=" + encodeURIComponent(p.type);
      $("p-eyebrow").textContent = p.type_name;
      $("p-title").textContent = p.title;
      $("p-meta").textContent = [p.author ? "By " + p.author : "", date(p.date), p.minutes + " min read"].filter(Boolean).join(" · ");
      // p.html is produced by the server's safe Markdown renderer (all user text escaped)
      $("p-article").innerHTML = (img ? '<img class="post-cover-img" src="' + esc(img) + '" alt="">' : "") + (pdf ? '<a class="btn post-pdf" href="' + esc(pdf) + '" target="_blank" rel="noopener">Download: ' + esc(p.pdf.name) + "</a>" : "") + '<div class="post-content">' + p.html + "</div>" +
        '<div class="post-share"><span>Share:</span><a target="_blank" rel="noopener noreferrer" href="https://wa.me/?text=' + encodeURIComponent(p.title + " " + location.href) + '">WhatsApp</a><a target="_blank" rel="noopener noreferrer" href="https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(location.href) + '">LinkedIn</a><a target="_blank" rel="noopener noreferrer" href="https://twitter.com/intent/tweet?url=' + encodeURIComponent(location.href) + "&text=" + encodeURIComponent(p.title) + '">X</a></div>';
      $("p-side").innerHTML = '<div class="card-s"><b>Need help with this?</b><p>Our team handles tax, audit, ERP and technology projects across Uganda.</p><a class="btn" href="quote.html">Request a quotation</a></div>' +
        (x.j.related.length ? '<div class="post-related"><b>More ' + esc(p.type_name.toLowerCase()) + "</b>" + x.j.related.map(function (r) { return '<a href="post.html?slug=' + encodeURIComponent(r.slug) + '">' + esc(r.title) + "<span>" + esc(date(r.date)) + "</span></a>"; }).join("") + "</div>" : "");
    }).catch(function () { $("p-title").textContent = "Could not load this post"; $("p-article").innerHTML = '<p>Please refresh the page. <a href="media.html">See all posts</a>.</p>'; });
  }
})();
