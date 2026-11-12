<?php
/* AdminLTE 4 layout for every admin page: left sidebar, top navbar with quick links, footer.
   Assets are bundled in admin/assets/vendor (no CDN, nothing to install). */

const ASSET_V = '20261112';

function nav_counts(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = ['q' => array_fill_keys(array_keys(QUOTE_STATUSES), 0), 'q_all' => 0, 'svc' => [], 'r_new' => 0, 'r_tax' => 0, 'r_audit' => 0, 'latest' => []];
    try {
        $pdo = db();
        foreach ($pdo->query('SELECT status, COUNT(*) n FROM quotes GROUP BY status') as $r) { $c['q'][$r['status']] = (int) $r['n']; $c['q_all'] += (int) $r['n']; }
        foreach ($pdo->query("SELECT service, COUNT(*) n FROM quotes WHERE status IN ('pending','in_review') GROUP BY service") as $r) $c['svc'][$r['service']] = (int) $r['n'];
        foreach ($pdo->query("SELECT category, COUNT(*) n FROM requests WHERE status = 'new' GROUP BY category") as $r) { $c['r_' . $r['category']] = (int) $r['n']; $c['r_new'] += (int) $r['n']; }
        $c['latest'] = $pdo->query("SELECT 'quote' k, id, quote_no ref, COALESCE(company, client_name) who, service x, created_at FROM quotes WHERE status = 'pending'
                                    UNION ALL SELECT 'request' k, id, request_id ref, client_name who, category x, created_at FROM requests WHERE status = 'new'
                                    ORDER BY created_at DESC LIMIT 6")->fetchAll();
    } catch (Throwable $e) {}
    return $c;
}

function badge(int $n, string $color = 'secondary'): string { return $n ? '<span class="nav-badge badge text-bg-' . $color . ' me-3">' . $n . '</span>' : ''; }
function status_badge(string $s): string { return '<span class="badge text-bg-' . (QUOTE_STATUS_COLORS[$s] ?? 'secondary') . '">' . h(QUOTE_STATUSES[$s] ?? $s) . '</span>'; }

function nav_link(string $href, string $icon, string $label, bool $active, string $extra = ''): string {
    return '<li class="nav-item"><a href="' . h($href) . '" class="nav-link' . ($active ? ' active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '><i class="nav-icon bi bi-' . $icon . '"></i><p>' . h($label) . $extra . '</p></a></li>';
}
function nav_tree(string $icon, string $label, bool $open, string $children, string $extra = ''): string {
    return '<li class="nav-item' . ($open ? ' menu-open' : '') . '"><a href="#" class="nav-link' . ($open ? ' active' : '') . '"><i class="nav-icon bi bi-' . $icon . '"></i><p>' . h($label) . $extra . '<i class="nav-arrow bi bi-chevron-right"></i></p></a><ul class="nav nav-treeview">' . $children . '</ul></li>';
}

/**
 * $active: dashboard | requests | requests:tax | requests:audit | quotes:all | quotes:<status> | service:<key> | quote:new | admins | log | password
 * $crumbs: [[label, href|null], ...]
 */
function page_head(string $title, ?array $admin = null, string $active = '', array $crumbs = [], string $actions = ''): void {
    $f = flash();
    $assets = 'assets/vendor';
    echo '<!doctype html><html lang="en" data-bs-theme="light" data-lte-color-mode="off"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
       . '<title>' . h($title) . ' | Audtix Admin</title>'
       . '<link rel="stylesheet" href="' . $assets . '/bootstrap-icons/bootstrap-icons.min.css?v=' . ASSET_V . '">'
       . '<link rel="stylesheet" href="' . $assets . '/adminlte/css/adminlte.min.css?v=' . ASSET_V . '">'
       . '<link rel="stylesheet" href="admin.css?v=' . ASSET_V . '"></head>';

    if (!$admin) { // sign-in, setup: centred card, no navigation
        echo '<body class="login-page bg-body-secondary"><div class="login-box"><div class="login-logo"><a href="../index.html"><img src="../img/logo-blue.png" alt="" width="60" height="40" class="me-2">Audtix&nbsp;<b>Admin</b></a></div><div class="card card-outline card-primary"><div class="card-body login-card-body">';
        if ($f) echo '<div class="alert alert-' . ($f[0] === 'bad' ? 'danger' : 'success') . '" role="status">' . h($f[1]) . '</div>';
        return;
    }

    $c = nav_counts(); $super = $admin['role'] === 'super_admin';
    [$sec, $sub] = array_pad(explode(':', $active, 2), 2, '');

    echo '<body class="layout-fixed sidebar-expand-lg bg-body-tertiary"><div class="app-wrapper">';

    /* ---------- top navbar: quick links ---------- */
    $n = $c['q']['pending'] + $c['r_new'];
    echo '<nav class="app-header navbar navbar-expand bg-body"><div class="container-fluid">'
       . '<ul class="navbar-nav"><li class="nav-item"><a class="nav-link" data-lte-toggle="sidebar" href="#" role="button" aria-label="Toggle sidebar"><i class="bi bi-list"></i></a></li>'
       . '<li class="nav-item d-none d-md-block"><a href="index.php" class="nav-link">Dashboard</a></li>'
       . '<li class="nav-item d-none d-md-block"><a href="quotes.php?status=pending" class="nav-link">Pending quotes' . ($c['q']['pending'] ? ' <span class="badge text-bg-warning">' . $c['q']['pending'] . '</span>' : '') . '</a></li>'
       . '<li class="nav-item d-none d-md-block"><a href="requests.php" class="nav-link">Requests</a></li>'
       . '<li class="nav-item d-none d-lg-block"><a href="quote-edit.php" class="nav-link"><i class="bi bi-plus-lg"></i> New quotation</a></li></ul>'
       . '<ul class="navbar-nav ms-auto">'
       . '<li class="nav-item"><form class="nav-search" action="search.php" method="get" role="search"><label class="visually-hidden" for="nav-q">Find by number, name or email</label><input id="nav-q" class="form-control form-control-sm" type="search" name="q" placeholder="Find QT-… / TAX-… / name" maxlength="120"></form></li>'
       . '<li class="nav-item dropdown"><a class="nav-link" data-bs-toggle="dropdown" href="#" aria-label="Notifications"><i class="bi bi-bell-fill"></i>' . ($n ? '<span class="navbar-badge badge text-bg-danger">' . $n . '</span>' : '') . '</a>'
       . '<div class="dropdown-menu dropdown-menu-lg dropdown-menu-end"><span class="dropdown-item dropdown-header">' . $n . ' waiting for a first look</span><div class="dropdown-divider"></div>';
    foreach ($c['latest'] as $l) {
        $href = $l['k'] === 'quote' ? 'quote.php?id=' . (int) $l['id'] : 'request.php?id=' . (int) $l['id'];
        $ic = $l['k'] === 'quote' ? 'file-earmark-text' : 'inbox';
        echo '<a href="' . $href . '" class="dropdown-item"><i class="bi bi-' . $ic . ' me-2"></i><span class="font-monospace small">' . h($l['ref']) . '</span><span class="d-block small text-secondary text-truncate">' . h($l['who']) . ' &middot; ' . h(eat($l['created_at'])) . '</span></a><div class="dropdown-divider"></div>';
    }
    echo '<a href="quotes.php?status=pending" class="dropdown-item dropdown-footer">See pending quotations</a></div></li>'
       . '<li class="nav-item d-none d-sm-block"><a class="nav-link" href="#" data-lte-toggle="fullscreen" aria-label="Full screen"><i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i><i data-lte-icon="minimize" class="bi bi-fullscreen-exit" style="display:none"></i></a></li>'
       . '<li class="nav-item dropdown user-menu"><a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown"><span class="avatar">' . h(mb_strtoupper(mb_substr($admin['full_name'], 0, 1))) . '</span><span class="d-none d-md-inline ms-1">' . h($admin['full_name']) . '</span></a>'
       . '<ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end"><li class="user-header text-bg-primary"><span class="avatar avatar-lg">' . h(mb_strtoupper(mb_substr($admin['full_name'], 0, 1))) . '</span><p>' . h($admin['full_name']) . '<small>' . ($super ? 'Super admin' : 'Admin') . ' &middot; ' . h($admin['email']) . '</small></p></li>'
       . '<li class="user-footer d-flex justify-content-between"><a href="password.php" class="btn btn-default btn-flat">My password</a><form method="post" action="logout.php" class="m-0">' . csrf_field() . '<button class="btn btn-default btn-flat">Sign out</button></form></li></ul></li>'
       . '</ul></div></nav>';

    /* ---------- left sidebar ---------- */
    echo '<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark"><div class="sidebar-brand"><a href="index.php" class="brand-link"><img src="../img/logo-white.png" alt="" class="brand-image" width="48" height="32"><span class="brand-text fw-light">Audtix <b>Admin</b></span></a></div>'
       . '<div class="sidebar-wrapper"><nav class="mt-2" aria-label="Main navigation"><ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false" role="navigation">';
    echo nav_link('index.php', 'speedometer2', 'Dashboard', $sec === 'dashboard');

    echo '<li class="nav-header">QUOTATIONS</li>';
    $kids = nav_link('quotes.php', 'collection', 'All quotations', $active === 'quotes:all', badge($c['q_all']));
    foreach (QUOTE_STATUSES as $k => $v) $kids .= nav_link('quotes.php?status=' . $k, QUOTE_STATUS_ICONS[$k], $v, $active === 'quotes:' . $k, badge($c['q'][$k], in_array($k, ['pending', 'in_review'], true) ? QUOTE_STATUS_COLORS[$k] : 'secondary'));
    echo nav_tree('file-earmark-text', 'Quotations', $sec === 'quotes' || $sec === 'quote', $kids, $c['q']['pending'] ? '<span class="nav-badge badge text-bg-warning me-3">' . $c['q']['pending'] . '</span>' : '');
    $kids = '';
    try { foreach (quote_services() as $k => $s) $kids .= nav_link('quotes.php?service=' . $k, 'dot', $s['title'], $active === 'service:' . $k, badge($c['svc'][$k] ?? 0, 'info')); } catch (Throwable $e) {}
    echo nav_tree('grid-3x3-gap', 'By service', $sec === 'service', $kids);
    echo nav_link('quote-edit.php', 'plus-circle', 'New quotation', $active === 'quote:new');

    echo '<li class="nav-header">SERVICE REQUESTS</li>';
    $kids = nav_link('requests.php', 'inboxes', 'All requests', $active === 'requests')
          . nav_link('requests.php?category=tax', 'receipt', 'Tax', $active === 'requests:tax', badge($c['r_tax'], 'warning'))
          . nav_link('requests.php?category=audit', 'clipboard-check', 'Audit', $active === 'requests:audit', badge($c['r_audit'], 'warning'));
    echo nav_tree('inbox', 'Tax and audit', $sec === 'requests', $kids, $c['r_new'] ? '<span class="nav-badge badge text-bg-warning me-3">' . $c['r_new'] . '</span>' : '');

    if ($super) {
        echo '<li class="nav-header">ADMINISTRATION</li>'
           . nav_link('admins.php', 'people', 'Admins', $sec === 'admins')
           . nav_link('log.php', 'shield-lock', 'Login log', $sec === 'log');
    }
    echo '<li class="nav-header">ACCOUNT</li>' . nav_link('password.php', 'key', 'My password', $sec === 'password')
       . nav_link('../index.html', 'box-arrow-up-right', 'View website', false)
       . '<li class="nav-item"><form method="post" action="logout.php" class="m-0">' . csrf_field() . '<button class="nav-link w-100 text-start border-0 bg-transparent"><i class="nav-icon bi bi-box-arrow-right"></i><p>Sign out</p></button></form></li>';
    echo '</ul></nav></div></aside>';

    /* ---------- page header ---------- */
    echo '<main class="app-main"><div class="app-content-header"><div class="container-fluid"><div class="row align-items-center g-2"><div class="col-sm-6"><h1 class="mb-0 fs-3">' . h($title) . '</h1></div><div class="col-sm-6">';
    if ($actions !== '') echo '<div class="d-flex justify-content-sm-end gap-2 flex-wrap">' . $actions . '</div>';
    else {
        echo '<ol class="breadcrumb float-sm-end mb-0"><li class="breadcrumb-item"><a href="index.php">Home</a></li>';
        foreach ($crumbs as $cr) echo $cr[1] ? '<li class="breadcrumb-item"><a href="' . h($cr[1]) . '">' . h($cr[0]) . '</a></li>' : '<li class="breadcrumb-item active" aria-current="page">' . h($cr[0]) . '</li>';
        if (!$crumbs) echo '<li class="breadcrumb-item active" aria-current="page">' . h($title) . '</li>';
        echo '</ol>';
    }
    echo '</div></div></div></div><div class="app-content"><div class="container-fluid">';
    if ($f) echo '<div class="alert alert-' . ($f[0] === 'bad' ? 'danger' : 'success') . ' alert-dismissible fade show" role="status">' . h($f[1]) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
}

function page_foot(bool $auth = false): void {
    $assets = 'assets/vendor';
    if ($auth) echo '</div></div></div>';
    else echo '</div></div></main><footer class="app-footer"><div class="float-end d-none d-sm-inline">Times are East Africa Time</div><strong>&copy; ' . gmdate('Y') . ' <a href="../index.html" class="text-decoration-none">Audtix Systems</a>.</strong> Admin panel.</footer></div>';
    echo '<script src="' . $assets . '/bootstrap/js/bootstrap.bundle.min.js?v=' . ASSET_V . '"></script><script src="' . $assets . '/adminlte/js/adminlte.min.js?v=' . ASSET_V . '"></script><script src="admin.js?v=' . ASSET_V . '"></script></body></html>';
}

function pager(int $page, int $pages, int $total, callable $url, string $noun = 'item'): string {
    $o = '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2"><span class="text-secondary small">' . number_format($total) . ' ' . $noun . ($total === 1 ? '' : 's') . ' &middot; page ' . $page . ' of ' . $pages . '</span><ul class="pagination pagination-sm m-0">';
    $o .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . h($url(max(1, $page - 1))) . '">&laquo;</a></li>';
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) $o .= '<li class="page-item' . ($i === $page ? ' active' : '') . '"><a class="page-link" href="' . h($url($i)) . '">' . $i . '</a></li>';
    $o .= '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '"><a class="page-link" href="' . h($url(min($pages, $page + 1))) . '">&raquo;</a></li></ul></div>';
    return $o;
}
