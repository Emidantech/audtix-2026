-- Audtix Systems: SME tax assessment questions and document uploads
-- Run after 20261105000000_tax_requests.sql. Safe to run more than once.

alter table public.tax_requests
  add column if not exists business_sector     text check (business_sector in ('retail','wholesale','construction','manufacturing','agriculture','ict','health','hospitality','education','transport','professional_services','ngo','other')),
  add column if not exists operating_status    text check (operating_status in ('yes','no','temporarily_closed')),
  add column if not exists stopped_on          date,
  add column if not exists years_operating     text check (years_operating in ('lt_1','1_3','4_7','gt_7')),
  add column if not exists accounting_software text check (char_length(accounting_software) <= 120),
  add column if not exists records_available   text[] check (records_available <@ array['bank_statements','sales_invoices','purchase_invoices','expense_receipts','payroll_records','inventory_records','asset_register','previous_fs','none']::text[]),
  add column if not exists filing_status       text check (filing_status in ('yes','no','some_missing','never')),
  add column if not exists outstanding_years   text check (outstanding_years in ('current_only','1','2','3','over_3')),
  add column if not exists ura_communications  text[] check (ura_communications <@ array['audit_notice','assessment','penalty','demand_notice']::text[]),
  add column if not exists payroll_monthly     text check (payroll_monthly in ('yes','no')),
  add column if not exists biggest_challenge   text check (biggest_challenge in ('understanding_obligations','records','late_filing','penalties','ura_dispute','payroll','vat','tax_cost','systems','no_expertise','other')),
  add column if not exists accuracy_confirmed  boolean not null default false,
  add column if not exists pdf_path            text,
  add column if not exists pdf_bytes           integer,
  add column if not exists file_count          integer not null default 0;

-- The services list grew from 12 to 18 options.
alter table public.tax_requests drop constraint if exists tax_requests_services_check;
alter table public.tax_requests add constraint tax_requests_services_check
  check (cardinality(services) between 1 and 18
         and services <@ array['annual_return','provisional_return','vat_returns','paye_nssf','withholding','tin_registration','vat_registration',
                               'clearance','audit_support','advisory','bookkeeping','other','financial_statements','payroll_management',
                               'tax_health_check','ura_compliance','accounting_setup','training']::text[]);

-- One row per uploaded document (the files themselves are merged into the PDF kept in Storage).
create table if not exists public.tax_request_files (
  id          bigint generated always as identity primary key,
  request_id  uuid not null references public.tax_requests (id) on delete cascade,
  doc_type    text not null check (doc_type in ('certificate_incorporation','tin_certificate','previous_fs','previous_returns','bank_statements','sales_records','expense_records','payroll_records','ura_notices','other_supporting')),
  file_name   text not null check (char_length(file_name) <= 200),
  mime_type   text not null check (mime_type in ('application/pdf','image/jpeg','image/png')),
  size_bytes  integer not null check (size_bytes > 0),
  created_at  timestamptz not null default now()
);
create index if not exists tax_request_files_req_idx on public.tax_request_files (request_id);
alter table public.tax_request_files enable row level security;
revoke all on public.tax_request_files from anon, authenticated;
grant select, insert on public.tax_request_files to service_role;
grant usage, select on sequence public.tax_request_files_id_seq to service_role;

-- Private Storage bucket for the combined PDFs (Supabase only; skipped on plain Postgres).
do $$
begin
  if exists (select 1 from information_schema.schemata where schema_name = 'storage') then
    insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
    values ('tax-request-pdfs', 'tax-request-pdfs', false, 31457280, array['application/pdf'])
    on conflict (id) do nothing;
  end if;
end $$;
