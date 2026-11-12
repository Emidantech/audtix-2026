-- Audtix Systems: tax service requests
-- Run once in the Supabase SQL editor, or place in supabase/migrations and run `supabase db push`.
--
-- Design notes
--  * Clients never sign up. The public site posts to an Edge Function, and only that function
--    (using the service role) can read or write these tables.
--  * Row level security is ON with no public policies, so the browser can never query the data.
--  * Every question has its own column so staff can filter and report. The full validated
--    submission is also kept in `payload` (including any extra questions added later).

create sequence if not exists public.tax_request_seq start 1;

-- Reference shown to the client and in the email, for example AUD-2026-00042.
create or replace function public.next_tax_reference()
returns text
language sql
volatile
as $$
  select 'AUD-' || to_char(now() at time zone 'Africa/Kampala', 'YYYY') || '-' || lpad(nextval('public.tax_request_seq')::text, 5, '0');
$$;

create table if not exists public.tax_requests (
  id                 uuid primary key default gen_random_uuid(),
  reference          text not null unique default public.next_tax_reference(),
  created_at         timestamptz not null default now(),
  updated_at         timestamptz not null default now(),

  -- staff workflow
  status             text not null default 'new'
                       check (status in ('new','contacted','in_progress','awaiting_documents','filed','closed','spam')),
  assigned_to        text,
  staff_notes        text,

  -- 1. About the applicant
  applicant_type     text not null check (applicant_type in ('individual','business','organisation')),
  display_name       text not null check (char_length(display_name) between 2 and 200),
  contact_person     text check (char_length(contact_person) <= 200),
  has_tin            text not null check (has_tin in ('yes','no','unsure')),
  tin                text check (tin ~ '^[0-9]{10}$'),
  reg_number         text check (char_length(reg_number) <= 60),
  email              text not null check (char_length(email) <= 254 and email ~* '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'),
  phone              text not null check (char_length(phone) between 7 and 30),
  preferred_contact  text not null default 'email' check (preferred_contact in ('email','whatsapp','phone')),
  location           text check (char_length(location) <= 120),

  -- 2. What they need
  services           text[] not null
                       check (cardinality(services) between 1 and 12
                              and services <@ array['annual_return','provisional_return','vat_returns','paye_nssf','withholding',
                                                    'tin_registration','vat_registration','clearance','audit_support','advisory',
                                                    'bookkeeping','other']::text[]),
  other_service      text check (char_length(other_service) <= 200),
  tax_periods        text not null check (char_length(tax_periods) between 1 and 200),
  timeline           text check (timeline in ('urgent','month','flexible')),

  -- 3. About the business
  year_end           text check (year_end in ('30_june','31_december','other','unsure')),
  turnover_band      text check (turnover_band in ('under_10m','10m_50m','50m_150m','150m_300m','300m_1bn','over_1bn','unsure')),
  employees_band     text check (employees_band in ('none','1_5','6_20','21_50','over_50')),
  vat_registered     text check (vat_registered in ('yes','no','unsure')),
  bookkeeping        text check (bookkeeping in ('software','spreadsheets_paper','none')),
  ura_notice         text check (ura_notice in ('yes','no')),
  ura_notice_details text check (char_length(ura_notice_details) <= 2000),
  notes              text check (char_length(notes) <= 2000),
  heard_from         text check (heard_from in ('social','email','referral','event','search','other')),

  -- consent and handling
  consent            boolean not null check (consent),
  consent_at         timestamptz not null default now(),
  source_page        text,
  user_agent         text,
  ip_hash            text,                       -- salted SHA-256, never the raw address
  email_status       text not null default 'pending' check (email_status in ('pending','sent','failed')),
  email_error        text,

  -- everything that was submitted, including extra questions added to the form later
  payload            jsonb not null default '{}'::jsonb
);

comment on table public.tax_requests is 'Tax service requests submitted from the Audtix website. No client accounts.';
comment on column public.tax_requests.ip_hash is 'Salted hash used only for rate limiting.';
comment on column public.tax_requests.payload is 'Validated submission as JSON, including the extra object for questions without a column.';

create index if not exists tax_requests_created_idx on public.tax_requests (created_at desc);
create index if not exists tax_requests_status_idx  on public.tax_requests (status, created_at desc);
create index if not exists tax_requests_email_idx   on public.tax_requests (lower(email));
create index if not exists tax_requests_ip_idx      on public.tax_requests (ip_hash, created_at desc);

-- Audit trail: when it arrived, whether the email went out, and any staff action.
create table if not exists public.tax_request_events (
  id          bigint generated always as identity primary key,
  request_id  uuid not null references public.tax_requests (id) on delete cascade,
  at          timestamptz not null default now(),
  actor       text not null default 'system',
  action      text not null,
  detail      text
);
create index if not exists tax_request_events_req_idx on public.tax_request_events (request_id, at);

create or replace function public.touch_updated_at()
returns trigger
language plpgsql
as $$
begin
  new.updated_at := now();
  return new;
end;
$$;

drop trigger if exists tax_requests_touch on public.tax_requests;
create trigger tax_requests_touch
  before update on public.tax_requests
  for each row execute function public.touch_updated_at();

-- Lock everything down. Only the service role (used by the Edge Function) can touch the data.
alter table public.tax_requests       enable row level security;
alter table public.tax_request_events enable row level security;

revoke all on public.tax_requests, public.tax_request_events from anon, authenticated;
revoke all on sequence public.tax_request_seq from anon, authenticated;
revoke all on function public.next_tax_reference() from public, anon, authenticated;

grant select, insert, update on public.tax_requests       to service_role;
grant select, insert         on public.tax_request_events to service_role;
grant usage, select on sequence public.tax_request_seq to service_role;
grant usage, select on sequence public.tax_request_events_id_seq to service_role;
grant execute on function public.next_tax_reference() to service_role;
