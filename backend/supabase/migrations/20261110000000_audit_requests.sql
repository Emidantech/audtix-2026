-- Audtix Systems: audit service requests (separate from tax requests; nothing there is changed).
-- Run once in the Supabase SQL editor, or place in supabase/migrations and run `supabase db push`.
-- Requires 20261105000000_tax_requests.sql to have been run first (it defines touch_updated_at()).
-- Clients never sign up. Only the Edge Function (service role) can read or write these tables.

create sequence if not exists public.audit_request_seq start 1;

create or replace function public.next_audit_reference()
returns text
language sql
volatile
as $$
  select 'AUDIT-' || to_char(now() at time zone 'Africa/Kampala', 'YYYY') || '-' || lpad(nextval('public.audit_request_seq')::text, 5, '0');
$$;

create table if not exists public.audit_requests (
  id             uuid primary key default gen_random_uuid(),
  reference      text not null unique default public.next_audit_reference(),
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now(),

  -- staff workflow
  status         text not null default 'new'
                   check (status in ('new','contacted','scoping','awaiting_documents','in_progress','reporting','closed','spam')),
  assigned_to    text,
  staff_notes    text,

  -- headline answers (every answer is also kept in payload)
  org_name       text not null check (char_length(org_name) between 2 and 200),
  org_type       text not null,
  contact_person text not null check (char_length(contact_person) between 2 and 200),
  email          text not null check (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'),
  phone          text not null check (char_length(phone) between 7 and 30),
  audit_types    text[] not null check (cardinality(audit_types) >= 1),
  audit_period   text not null check (char_length(audit_period) between 1 and 200),
  payload        jsonb not null,

  -- delivery
  email_status   text not null default 'pending' check (email_status in ('pending','sent','failed')),
  email_error    text,
  pdf_path       text,
  pdf_bytes      integer,
  file_count     integer not null default 0,

  -- request metadata
  source_page    text,
  user_agent     text,
  ip_hash        text
);
create index if not exists audit_requests_created_idx on public.audit_requests (created_at desc);
create index if not exists audit_requests_ip_idx on public.audit_requests (ip_hash, created_at);
create index if not exists audit_requests_status_idx on public.audit_requests (status);

create table if not exists public.audit_request_events (
  id          bigint generated always as identity primary key,
  request_id  uuid not null references public.audit_requests (id) on delete cascade,
  at          timestamptz not null default now(),
  actor       text not null default 'system',
  action      text not null,
  detail      text
);
create index if not exists audit_request_events_req_idx on public.audit_request_events (request_id, at);

create table if not exists public.audit_request_files (
  id          bigint generated always as identity primary key,
  request_id  uuid not null references public.audit_requests (id) on delete cascade,
  doc_type    text not null check (doc_type in ('financial_statements','trial_balance','bank_statements','tax_returns','registration','previous_audit','donor_agreements','other_docs')),
  file_name   text not null check (char_length(file_name) <= 200),
  mime_type   text not null check (mime_type in ('application/pdf','image/jpeg','image/png')),
  size_bytes  integer not null check (size_bytes > 0),
  created_at  timestamptz not null default now()
);
create index if not exists audit_request_files_req_idx on public.audit_request_files (request_id);

drop trigger if exists audit_requests_touch on public.audit_requests;
create trigger audit_requests_touch
  before update on public.audit_requests
  for each row execute function public.touch_updated_at();

alter table public.audit_requests       enable row level security;
alter table public.audit_request_events enable row level security;
alter table public.audit_request_files  enable row level security;
revoke all on public.audit_requests, public.audit_request_events, public.audit_request_files from anon, authenticated;
revoke all on sequence public.audit_request_seq from anon, authenticated;
revoke all on function public.next_audit_reference() from public, anon, authenticated;
grant select, insert, update on public.audit_requests to service_role;
grant select, insert on public.audit_request_events, public.audit_request_files to service_role;
grant usage, select on sequence public.audit_request_seq to service_role;
grant usage, select on sequence public.audit_request_events_id_seq to service_role;
grant usage, select on sequence public.audit_request_files_id_seq to service_role;
grant execute on function public.next_audit_reference() to service_role;

-- Private Storage bucket for the combined PDFs (Supabase only; skipped on plain Postgres).
do $$
begin
  if exists (select 1 from information_schema.schemata where schema_name = 'storage') then
    insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
    values ('audit-request-pdfs', 'audit-request-pdfs', false, 31457280, array['application/pdf'])
    on conflict (id) do nothing;
  end if;
end $$;
