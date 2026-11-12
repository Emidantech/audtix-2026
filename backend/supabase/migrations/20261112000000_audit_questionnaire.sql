-- Audtix Systems: audit questionnaire. Widens the allowed upload types on audit_request_files.
-- The extra questionnaire answers are stored in audit_requests.payload (jsonb), so no new columns are needed.
-- Run after 20261110000000_audit_requests.sql. Safe to run more than once.

alter table public.audit_request_files drop constraint if exists audit_request_files_doc_type_check;
alter table public.audit_request_files add constraint audit_request_files_doc_type_check
  check (doc_type in ('financial_statements','trial_balance','bank_statements','tax_returns','registration','previous_audit','donor_agreements','cashbook','bank_recs','ledgers','receivables_payables','inventory_listing','asset_register','payroll_records','loan_documents','management_letter','contracts','other_docs'));
