<?php
/* Shared engine for the HR, Finance, CRM, Marketing and Procurement lists: one definition per list drives the table,
   the add/edit form, search and delete. Pages: mod.php?m=<key>. Additive: nothing here touches the existing modules. */

const MOD_STATUS_COLORS = ['active' => 'success', 'approved' => 'success', 'paid' => 'success', 'won' => 'success', 'received' => 'success', 'completed' => 'success', 'recorded' => 'secondary',
    'pending' => 'warning', 'draft' => 'secondary', 'planned' => 'info', 'sent' => 'primary', 'lead' => 'secondary', 'qualified' => 'info', 'proposal' => 'primary', 'negotiation' => 'warning',
    'lost' => 'danger', 'rejected' => 'danger', 'cancelled' => 'dark', 'inactive' => 'secondary', 'on_leave' => 'warning', 'left' => 'dark', 'running' => 'primary', 'paused' => 'warning', 'ordered' => 'primary'];
const MOD_CURRENCIES = ['UGX' => 'UGX', 'USD' => 'USD', 'EUR' => 'EUR', 'KES' => 'KES', 'GBP' => 'GBP'];

function mod_defs(): array {
    $st = fn(array $k) => array_combine($k, array_map(fn($x) => ucfirst(str_replace('_', ' ', $x)), $k));
    return [
    'hr_employees' => ['perm' => 'hr', 'title' => 'Employees', 'one' => 'employee', 'icon' => 'person-badge', 'table' => 'hr_employees', 'nav' => 'hr', 'order' => 't.full_name',
        'search' => ['t.full_name', 't.emp_no', 't.email', 't.phone', 't.job_title', 't.department'], 'filter' => ['status', $st(['active', 'on_leave', 'inactive', 'left'])],
        'cols' => [['emp_no', 'No.', 'mono'], ['full_name', 'Name', 'strong'], ['job_title', 'Role'], ['department', 'Department'], ['basic_salary', 'Basic pay', 'money'], ['status', 'Status', 'badge']],
        'sum' => ['basic_salary'],
        'fields' => [['full_name', 'Full name', 'text', null, 1], ['emp_no', 'Employee number', 'text'], ['job_title', 'Job title', 'text'], ['department', 'Department', 'text'], ['emp_type', 'Type', 'select', $st(['permanent', 'contract', 'casual', 'intern'])],
            ['hire_date', 'Date joined', 'date'], ['basic_salary', 'Basic salary per month (UGX)', 'money'], ['allowances', 'Fixed allowances per month (UGX)', 'money'], ['email', 'Email', 'email'], ['phone', 'Phone', 'tel'],
            ['nssf_no', 'NSSF number', 'text'], ['tin', 'TIN', 'text'], ['bank_details', 'Bank or mobile money details', 'text'], ['status', 'Status', 'select', $st(['active', 'on_leave', 'inactive', 'left'])], ['notes', 'Notes', 'textarea']],
        'before' => function (array &$d, ?array $old) { if (($d['emp_no'] ?? '') === '' && !$old) { $n = (int) db()->query('SELECT COUNT(*) FROM hr_employees')->fetchColumn() + 1; $d['emp_no'] = 'EMP-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT); } return null; }],
    'hr_leave' => ['perm' => 'hr', 'title' => 'Leave', 'one' => 'leave request', 'icon' => 'calendar2-week', 'table' => 'hr_leave', 'nav' => 'hr', 'order' => 't.start_date DESC',
        'join' => 'LEFT JOIN hr_employees e ON e.id = t.employee_id', 'extra' => 'e.full_name AS emp_name', 'search' => ['e.full_name', 't.leave_type', 't.reason'], 'filter' => ['status', $st(['pending', 'approved', 'rejected', 'cancelled'])],
        'cols' => [['emp_name', 'Employee', 'strong'], ['leave_type', 'Type'], ['start_date', 'From', 'date'], ['end_date', 'To', 'date'], ['days', 'Days'], ['status', 'Status', 'badge']],
        'fields' => [['employee_id', 'Employee', 'employee', null, 1], ['leave_type', 'Leave type', 'select', $st(['annual', 'sick', 'maternity', 'paternity', 'compassionate', 'unpaid', 'study'])], ['start_date', 'First day', 'date', null, 1], ['end_date', 'Last day', 'date', null, 1],
            ['status', 'Status', 'select', $st(['pending', 'approved', 'rejected', 'cancelled'])], ['reason', 'Reason', 'textarea']],
        'before' => function (array &$d, ?array $old) { $a = strtotime((string) $d['start_date']); $b = strtotime((string) $d['end_date']); if (!$a || !$b || $b < $a) return 'The last day must be on or after the first day.';
            $days = 0; for ($t = $a; $t <= $b; $t += 86400) if ((int) gmdate('N', $t) < 6) $days++; $d['days'] = (string) max(1, $days); return null; }],
    'fin_expenses' => ['perm' => 'fin', 'title' => 'Expenses', 'one' => 'expense', 'icon' => 'wallet2', 'table' => 'fin_expenses', 'nav' => 'fin', 'order' => 't.exp_date DESC, t.id DESC',
        'search' => ['t.payee', 't.description', 't.category', 't.reference'], 'filter' => ['category', null], 'sum' => ['amount'],
        'cols' => [['exp_date', 'Date', 'date'], ['category', 'Category'], ['payee', 'Paid to', 'strong'], ['description', 'Details'], ['amount', 'Amount', 'money'], ['status', 'Status', 'badge']],
        'fields' => [['exp_date', 'Date', 'date', null, 1], ['category', 'Category', 'select', array_combine($c = ['Rent', 'Salaries', 'Utilities', 'Internet and airtime', 'Transport', 'Equipment', 'Software and subscriptions', 'Marketing', 'Professional fees', 'Taxes and levies', 'Supplies', 'General'], $c)],
            ['payee', 'Paid to', 'text'], ['description', 'Details', 'text'], ['amount', 'Amount', 'money', null, 1], ['currency', 'Currency', 'select', MOD_CURRENCIES], ['method', 'Paid by', 'select', ['Cash' => 'Cash', 'Bank' => 'Bank', 'Mobile money' => 'Mobile money', 'Card' => 'Card']],
            ['reference', 'Receipt or reference no.', 'text'], ['status', 'Status', 'select', $st(['recorded', 'approved', 'paid', 'rejected'])]]],
    'crm_deals' => ['perm' => 'crm', 'title' => 'Leads and deals', 'one' => 'deal', 'icon' => 'bullseye', 'table' => 'crm_deals', 'nav' => 'crm', 'order' => 't.updated_at DESC',
        'search' => ['t.title', 't.company', 't.contact_name', 't.email', 't.phone'], 'filter' => ['stage', $st(['lead', 'qualified', 'proposal', 'negotiation', 'won', 'lost'])], 'sum' => ['deal_value'],
        'cols' => [['title', 'Deal', 'strong'], ['company', 'Company'], ['contact_name', 'Contact'], ['stage', 'Stage', 'badge'], ['deal_value', 'Value', 'money'], ['expected_close', 'Close by', 'date']],
        'fields' => [['title', 'Deal or lead title', 'text', null, 1], ['company', 'Company', 'text'], ['contact_name', 'Contact person', 'text'], ['email', 'Email', 'email'], ['phone', 'Phone', 'tel'],
            ['stage', 'Stage', 'select', $st(['lead', 'qualified', 'proposal', 'negotiation', 'won', 'lost'])], ['deal_value', 'Expected value (UGX)', 'money'], ['expected_close', 'Expected close date', 'date'],
            ['source', 'Source', 'select', ['website' => 'Website', 'chatbot' => 'Website chat', 'quotation' => 'Quotation request', 'referral' => 'Referral', 'event' => 'Event', 'call' => 'Cold call', 'social' => 'Social media', 'other' => 'Other']],
            ['owner_name', 'Owner', 'text'], ['next_step', 'Next step', 'text'], ['notes', 'Notes', 'textarea']]],
    'mkt_campaigns' => ['perm' => 'mkt', 'title' => 'Campaigns', 'one' => 'campaign', 'icon' => 'megaphone', 'table' => 'mkt_campaigns', 'nav' => 'mkt', 'order' => 't.start_date DESC, t.id DESC',
        'search' => ['t.name', 't.audience', 't.channel'], 'filter' => ['status', $st(['planned', 'running', 'paused', 'completed', 'cancelled'])], 'sum' => ['budget', 'spend', 'leads'],
        'cols' => [['name', 'Campaign', 'strong'], ['channel', 'Channel'], ['status', 'Status', 'badge'], ['budget', 'Budget', 'money'], ['spend', 'Spent', 'money'], ['leads', 'Leads', 'int'], ['conversions', 'Won', 'int']],
        'fields' => [['name', 'Campaign name', 'text', null, 1], ['channel', 'Channel', 'select', ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'social' => 'Social media', 'radio' => 'Radio', 'print' => 'Print', 'event' => 'Event', 'seo' => 'Website and SEO', 'ads' => 'Paid ads', 'other' => 'Other']],
            ['status', 'Status', 'select', $st(['planned', 'running', 'paused', 'completed', 'cancelled'])], ['start_date', 'Starts', 'date'], ['end_date', 'Ends', 'date'], ['budget', 'Budget (UGX)', 'money'], ['spend', 'Spent so far (UGX)', 'money'],
            ['audience', 'Target audience', 'text'], ['leads', 'Leads generated', 'number'], ['conversions', 'Customers won', 'number'], ['notes', 'Notes', 'textarea']]],
    'proc_suppliers' => ['perm' => 'proc', 'title' => 'Suppliers', 'one' => 'supplier', 'icon' => 'truck', 'table' => 'proc_suppliers', 'nav' => 'proc', 'order' => 't.name',
        'search' => ['t.name', 't.contact_name', 't.email', 't.phone', 't.category', 't.tin'], 'filter' => ['status', $st(['active', 'inactive'])],
        'cols' => [['name', 'Supplier', 'strong'], ['category', 'Category'], ['contact_name', 'Contact'], ['phone', 'Phone'], ['email', 'Email'], ['status', 'Status', 'badge']],
        'fields' => [['name', 'Supplier name', 'text', null, 1], ['category', 'What they supply', 'text'], ['contact_name', 'Contact person', 'text'], ['phone', 'Phone', 'tel'], ['email', 'Email', 'email'], ['tin', 'TIN', 'text'], ['address', 'Address', 'text'],
            ['status', 'Status', 'select', $st(['active', 'inactive'])], ['notes', 'Notes', 'textarea']]],
    'proc_orders' => ['perm' => 'proc', 'title' => 'Purchase orders', 'one' => 'purchase order', 'icon' => 'cart-check', 'table' => 'proc_orders', 'nav' => 'proc', 'order' => 't.order_date DESC, t.id DESC',
        'search' => ['t.po_no', 't.supplier_name', 't.notes'], 'filter' => ['status', $st(['draft', 'approved', 'ordered', 'received', 'cancelled'])], 'sum' => ['total'],
        'cols' => [['po_no', 'PO no.', 'mono'], ['supplier_name', 'Supplier', 'strong'], ['order_date', 'Date', 'date'], ['expected_date', 'Expected', 'date'], ['total', 'Total', 'money'], ['status', 'Status', 'badge']],
        'fields' => [['supplier_id', 'Supplier', 'supplier', null, 1], ['order_date', 'Order date', 'date', null, 1], ['expected_date', 'Expected delivery', 'date'], ['currency', 'Currency', 'select', MOD_CURRENCIES],
            ['status', 'Status', 'select', $st(['draft', 'approved', 'ordered', 'received', 'cancelled'])], ['lines_text', 'Items: one per line as  description | quantity | unit price', 'textarea'], ['notes', 'Notes', 'textarea']],
        'before' => function (array &$d, ?array $old) {
            $s = db()->prepare('SELECT name FROM proc_suppliers WHERE id = ?'); $s->execute([(int) $d['supplier_id']]); $n = $s->fetchColumn(); if ($n === false) return 'Choose a supplier.'; $d['supplier_name'] = (string) $n;
            $t = 0.0; foreach (preg_split('/\R/', (string) ($d['lines_text'] ?? '')) as $ln) { $p = array_map('trim', explode('|', $ln)); if (count($p) >= 3) $t += (float) str_replace(',', '', $p[1]) * (float) str_replace(',', '', $p[2]); } $d['total'] = (string) round($t, 2);
            if (!$old) { $y = gmdate('Y'); $c = (int) db()->query("SELECT COUNT(*) FROM proc_orders WHERE po_no LIKE 'PO-$y-%'")->fetchColumn() + 1; do { $d['po_no'] = sprintf('PO-%s-%04d', $y, $c++); $x = db()->prepare('SELECT COUNT(*) FROM proc_orders WHERE po_no = ?'); $x->execute([$d['po_no']]); } while ((int) $x->fetchColumn()); }
            return null; }],
    ];
}

function mod_lookup(string $kind): array {
    $q = ['customer' => 'SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name', 'employee' => "SELECT id, full_name FROM hr_employees WHERE status <> 'left' ORDER BY full_name", 'supplier' => "SELECT id, name FROM proc_suppliers WHERE status = 'active' ORDER BY name"][$kind] ?? null;
    $o = []; if ($q) try { foreach (db()->query($q) as $r) $o[(int) $r['id']] = (string) array_values($r)[1]; } catch (Throwable $e) {}
    return $o;
}
function mod_money($v): string { return number_format((float) $v, 0); }
function mod_badge(string $v): string { return '<span class="badge text-bg-' . (MOD_STATUS_COLORS[$v] ?? 'secondary') . '">' . h(ucfirst(str_replace('_', ' ', $v))) . '</span>'; }
function mod_setup_notice(Throwable $e): void {
    $missing = stripos($e->getMessage(), "doesn't exist") !== false || stripos($e->getMessage(), 'no such table') !== false;
    echo '<div class="alert alert-warning">' . ($missing ? 'The tables for this module have not been created yet. ' . (is_super() ? '<a class="fw-semibold" href="modules-setup.php">Run the one-click setup</a> (it only adds tables).' : 'Ask a super admin to run <b>Modules database setup</b>.') : 'Something went wrong: ' . h($e->getMessage())) . '</div>';
}
