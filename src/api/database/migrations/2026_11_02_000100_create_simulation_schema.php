<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('O simulador exige PostgreSQL; não usar SQLite/MySQL como substituto.');
        }
        DB::unprepared(<<<'SQL'
CREATE TABLE merchants (
 id UUID PRIMARY KEY, name VARCHAR(53) NOT NULL CHECK (name ~ '^DEMO-[A-Z0-9_-]{3,48}$'),
 virtual_account_id VARCHAR(61) NOT NULL UNIQUE CHECK (virtual_account_id ~ '^DEMO-ACCOUNT-[A-Z0-9_-]{3,48}$'),
 created_at TIMESTAMPTZ NOT NULL
);
CREATE TABLE demo_credentials (
 id UUID PRIMARY KEY, merchant_id UUID REFERENCES merchants(id), role VARCHAR(16) NOT NULL CHECK(role IN ('operator','merchant')),
 token_hash CHAR(64) NOT NULL UNIQUE CHECK(token_hash ~ '^[0-9a-f]{64}$'),
 revoked_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL,
 CHECK((role='operator' AND merchant_id IS NULL) OR (role='merchant' AND merchant_id IS NOT NULL))
);
CREATE TABLE fiscal_documents (
 id UUID PRIMARY KEY, merchant_id UUID NOT NULL REFERENCES merchants(id),
 reference VARCHAR(57) NOT NULL UNIQUE CHECK(reference ~ '^DEMO-DOC-[A-Z0-9_-]{3,48}$'),
 gross_amount_cents BIGINT NOT NULL CHECK(gross_amount_cents BETWEEN 1 AND 1000000000000),
 cbs_amount_cents BIGINT NOT NULL CHECK(cbs_amount_cents BETWEEN 0 AND 1000000000000),
 ibs_amount_cents BIGINT NOT NULL CHECK(ibs_amount_cents BETWEEN 0 AND 1000000000000),
 status VARCHAR(16) NOT NULL CHECK(status='ISSUED'), created_at TIMESTAMPTZ NOT NULL,
 CHECK(cbs_amount_cents <= gross_amount_cents - ibs_amount_cents), UNIQUE(id,merchant_id)
);
CREATE TABLE payments (
 id UUID PRIMARY KEY, merchant_id UUID NOT NULL REFERENCES merchants(id), fiscal_document_id UUID NOT NULL,
 amount_cents BIGINT NOT NULL CHECK(amount_cents BETWEEN 1 AND 1000000000000),
 currency CHAR(3) NOT NULL CHECK(currency='BRL'), scenario VARCHAR(16) NOT NULL CHECK(scenario IN ('approved','declined')),
 status VARCHAR(16) NOT NULL CHECK(status IN ('CREATED','AUTHORIZED','SETTLED','FAILED','REFUNDED')),
 failure_code VARCHAR(40), version BIGINT NOT NULL CHECK(version>=1),
 created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL,
 FOREIGN KEY(fiscal_document_id,merchant_id) REFERENCES fiscal_documents(id,merchant_id),
 UNIQUE(id,merchant_id), UNIQUE(id,fiscal_document_id),
 CHECK((status='FAILED' AND failure_code IS NOT NULL AND failure_code IN ('AUTHORIZATION_DECLINED','SETTLEMENT_TERMINAL_FAILURE'))
    OR (status<>'FAILED' AND failure_code IS NULL))
);
CREATE INDEX payments_merchant_status_time ON payments(merchant_id,status,created_at);
CREATE TABLE payment_events (
 id UUID PRIMARY KEY, payment_id UUID NOT NULL REFERENCES payments(id),
 event_type VARCHAR(32) NOT NULL, previous_status VARCHAR(16), new_status VARCHAR(16) NOT NULL,
 occurred_at TIMESTAMPTZ NOT NULL, correlation_id UUID NOT NULL, metadata_safe JSONB NOT NULL DEFAULT '{}',
 UNIQUE(id,payment_id),
 CHECK(jsonb_typeof(metadata_safe)='object' AND metadata_safe - ARRAY['operation','failure_code'] = '{}'::jsonb),
 CHECK(NOT(metadata_safe ? 'operation') OR (jsonb_typeof(metadata_safe->'operation')='string' AND metadata_safe->>'operation' IN ('create_payment','authorize_payment','settle_payment','refund_payment'))),
 CHECK(NOT(metadata_safe ? 'failure_code') OR (jsonb_typeof(metadata_safe->'failure_code')='string' AND metadata_safe->>'failure_code' IN ('AUTHORIZATION_DECLINED','SETTLEMENT_TERMINAL_FAILURE','TRANSIENT_FAILURE'))),
 CHECK(event_type IN ('CREATED','AUTHORIZED','AUTHORIZATION_DECLINED','SETTLED','SETTLEMENT_FAILED','REFUNDED','TRANSIENT_ATTEMPT')),
 CHECK(new_status IN ('CREATED','AUTHORIZED','SETTLED','FAILED','REFUNDED')),
 CHECK(previous_status IS NULL OR previous_status IN ('CREATED','AUTHORIZED','SETTLED','FAILED','REFUNDED')),
 CHECK((event_type='CREATED' AND previous_status IS NULL) OR (event_type<>'CREATED' AND previous_status IS NOT NULL)),
 CHECK((event_type='CREATED' AND previous_status IS NULL AND new_status='CREATED')
 OR (event_type='AUTHORIZED' AND previous_status='CREATED' AND new_status='AUTHORIZED')
 OR (event_type='AUTHORIZATION_DECLINED' AND previous_status='CREATED' AND new_status='FAILED')
 OR (event_type='SETTLED' AND previous_status='AUTHORIZED' AND new_status='SETTLED')
 OR (event_type='SETTLEMENT_FAILED' AND previous_status='AUTHORIZED' AND new_status='FAILED')
 OR (event_type='REFUNDED' AND previous_status='SETTLED' AND new_status='REFUNDED')
 OR (event_type='TRANSIENT_ATTEMPT' AND previous_status=new_status AND new_status IN ('CREATED','AUTHORIZED','SETTLED')))
);
CREATE UNIQUE INDEX payment_events_unique_transition ON payment_events(payment_id,event_type) WHERE event_type <> 'TRANSIENT_ATTEMPT';
CREATE INDEX payment_events_payment_time ON payment_events(payment_id,occurred_at,id);
CREATE TABLE document_settlements (
 fiscal_document_id UUID PRIMARY KEY REFERENCES fiscal_documents(id), payment_id UUID NOT NULL UNIQUE,
 settlement_event_id UUID NOT NULL UNIQUE, created_at TIMESTAMPTZ NOT NULL,
 FOREIGN KEY(payment_id,fiscal_document_id) REFERENCES payments(id,fiscal_document_id),
 FOREIGN KEY(settlement_event_id,payment_id) REFERENCES payment_events(id,payment_id)
);
CREATE TABLE ledger_entries (
 id UUID PRIMARY KEY, payment_id UUID NOT NULL REFERENCES payments(id), event_id UUID NOT NULL,
 account_code VARCHAR(32) NOT NULL CHECK(account_code IN ('CLEARING','MERCHANT_PAYABLE','CBS_PAYABLE','IBS_PAYABLE')),
 direction VARCHAR(8) NOT NULL CHECK(direction IN ('DEBIT','CREDIT')),
 amount_cents BIGINT NOT NULL CHECK(amount_cents BETWEEN 1 AND 1000000000000),
 reversal_of_entry_id UUID UNIQUE REFERENCES ledger_entries(id), created_at TIMESTAMPTZ NOT NULL,
 FOREIGN KEY(event_id,payment_id) REFERENCES payment_events(id,payment_id),
 UNIQUE(event_id,account_code)
);
CREATE INDEX ledger_entries_payment_time ON ledger_entries(payment_id,created_at,id);
CREATE TABLE idempotency_records (
 id UUID PRIMARY KEY, merchant_id UUID NOT NULL REFERENCES merchants(id),
 operation VARCHAR(32) NOT NULL CHECK(operation IN ('create_payment','authorize_payment','settle_payment','refund_payment')),
 resource_scope VARCHAR(36) NOT NULL, key VARCHAR(128) NOT NULL CHECK(key ~ '^[!-~]{1,128}$'),
 request_hash CHAR(64) NOT NULL CHECK(request_hash ~ '^[0-9a-f]{64}$'),
 http_status SMALLINT CHECK(http_status BETWEEN 200 AND 499), response_body JSONB, response_headers JSONB, completed_at TIMESTAMPTZ,
 UNIQUE(merchant_id,operation,resource_scope,key),
 CHECK((http_status IS NULL AND response_body IS NULL AND response_headers IS NULL AND completed_at IS NULL)
 OR (http_status IS NOT NULL AND response_body IS NOT NULL AND response_headers IS NOT NULL AND completed_at IS NOT NULL)),
 CHECK((operation='create_payment' AND resource_scope='create') OR
       (operation<>'create_payment' AND resource_scope ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'))
);

CREATE FUNCTION ww_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'historical record is immutable' USING ERRCODE='23514';
END $$;
CREATE TRIGGER ledger_immutable BEFORE UPDATE OR DELETE ON ledger_entries FOR EACH ROW EXECUTE FUNCTION ww_immutable();
CREATE TRIGGER events_immutable BEFORE UPDATE OR DELETE ON payment_events FOR EACH ROW EXECUTE FUNCTION ww_immutable();
CREATE TRIGGER settlements_immutable BEFORE UPDATE OR DELETE ON document_settlements FOR EACH ROW EXECUTE FUNCTION ww_immutable();

CREATE FUNCTION ww_freeze_document() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM payments WHERE fiscal_document_id=OLD.id) THEN
  RAISE EXCEPTION 'document linked to payment is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER document_frozen BEFORE UPDATE ON fiscal_documents FOR EACH ROW EXECUTE FUNCTION ww_freeze_document();

CREATE FUNCTION ww_guard_payment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE gross BIGINT;
BEGIN
 IF TG_OP='INSERT' THEN
  SELECT gross_amount_cents INTO gross FROM fiscal_documents WHERE id=NEW.fiscal_document_id FOR UPDATE;
  IF NEW.amount_cents IS DISTINCT FROM gross OR NEW.status<>'CREATED' OR NEW.version<>1 THEN
   RAISE EXCEPTION 'invalid initial payment' USING ERRCODE='23514';
  END IF;
 ELSE
  IF ROW(NEW.id,NEW.merchant_id,NEW.fiscal_document_id,NEW.amount_cents,NEW.currency,NEW.scenario,NEW.created_at)
     IS DISTINCT FROM ROW(OLD.id,OLD.merchant_id,OLD.fiscal_document_id,OLD.amount_cents,OLD.currency,OLD.scenario,OLD.created_at)
     OR NEW.version<>OLD.version+1
     OR NOT ((OLD.status='CREATED' AND NEW.status IN ('AUTHORIZED','FAILED'))
         OR (OLD.status='AUTHORIZED' AND NEW.status IN ('SETTLED','FAILED'))
         OR (OLD.status='SETTLED' AND NEW.status='REFUNDED')) THEN
   RAISE EXCEPTION 'invalid payment transition or immutable amount' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER payment_guard BEFORE INSERT OR UPDATE ON payments FOR EACH ROW EXECUTE FUNCTION ww_guard_payment();

CREATE FUNCTION ww_validate_event(target UUID) RETURNS void LANGUAGE plpgsql AS $$
DECLARE e payment_events%ROWTYPE; p payments%ROWTYPE; d fiscal_documents%ROWTYPE;
 n BIGINT; debits NUMERIC; credits NUMERIC; expected_count INTEGER; original_event UUID; original_count BIGINT;
BEGIN
 SELECT * INTO e FROM payment_events WHERE id=target;
 IF NOT FOUND THEN RETURN; END IF;
 SELECT * INTO p FROM payments WHERE id=e.payment_id;
 SELECT * INTO d FROM fiscal_documents WHERE id=p.fiscal_document_id;
 SELECT count(*),coalesce(sum(amount_cents) FILTER(WHERE direction='DEBIT'),0),
        coalesce(sum(amount_cents) FILTER(WHERE direction='CREDIT'),0)
 INTO n,debits,credits FROM ledger_entries WHERE event_id=e.id;
 IF e.event_type='SETTLED' THEN
  expected_count := 1 + ((p.amount_cents-d.cbs_amount_cents-d.ibs_amount_cents)>0)::int
                       + (d.cbs_amount_cents>0)::int + (d.ibs_amount_cents>0)::int;
  IF p.status NOT IN ('SETTLED','REFUNDED') OR n<>expected_count OR debits<>p.amount_cents OR credits<>p.amount_cents
   OR NOT EXISTS(SELECT 1 FROM document_settlements WHERE fiscal_document_id=d.id AND payment_id=p.id AND settlement_event_id=e.id)
   OR EXISTS(SELECT 1 FROM ledger_entries l WHERE l.event_id=e.id AND (
      l.reversal_of_entry_id IS NOT NULL
      OR l.direction<>CASE WHEN l.account_code='CLEARING' THEN 'DEBIT' ELSE 'CREDIT' END
      OR l.amount_cents<>CASE l.account_code WHEN 'CLEARING' THEN p.amount_cents
          WHEN 'MERCHANT_PAYABLE' THEN p.amount_cents-d.cbs_amount_cents-d.ibs_amount_cents
          WHEN 'CBS_PAYABLE' THEN d.cbs_amount_cents WHEN 'IBS_PAYABLE' THEN d.ibs_amount_cents END)) THEN
   RAISE EXCEPTION 'unbalanced or invalid settlement' USING ERRCODE='23514';
  END IF;
 ELSIF e.event_type='REFUNDED' THEN
  SELECT id INTO original_event FROM payment_events WHERE payment_id=p.id AND event_type='SETTLED';
  SELECT count(*) INTO original_count FROM ledger_entries WHERE event_id=original_event;
  IF p.status<>'REFUNDED' OR original_event IS NULL OR original_count=0 OR n<>original_count OR debits<>credits OR debits<>p.amount_cents
   OR EXISTS(SELECT 1 FROM ledger_entries l LEFT JOIN ledger_entries o ON o.id=l.reversal_of_entry_id
      WHERE l.event_id=e.id AND (o.id IS NULL OR o.event_id<>original_event OR o.payment_id<>p.id
        OR o.account_code<>l.account_code OR o.amount_cents<>l.amount_cents OR o.direction=l.direction)) THEN
   RAISE EXCEPTION 'unbalanced or invalid refund' USING ERRCODE='23514';
  END IF;
 ELSE
  IF n<>0 THEN RAISE EXCEPTION 'nonfinancial event has ledger entries' USING ERRCODE='23514'; END IF;
 END IF;
END $$;

CREATE FUNCTION ww_check_event_trigger() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='payment_events' THEN
  PERFORM ww_validate_event(NEW.id);
 ELSIF TG_TABLE_NAME='document_settlements' THEN
  PERFORM ww_validate_event(NEW.settlement_event_id);
 ELSE
  PERFORM ww_validate_event(NEW.event_id);
 END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER event_finance_valid AFTER INSERT ON payment_events DEFERRABLE INITIALLY DEFERRED
 FOR EACH ROW EXECUTE FUNCTION ww_check_event_trigger();
CREATE CONSTRAINT TRIGGER ledger_finance_valid AFTER INSERT ON ledger_entries DEFERRABLE INITIALLY DEFERRED
 FOR EACH ROW EXECUTE FUNCTION ww_check_event_trigger();
CREATE CONSTRAINT TRIGGER claim_finance_valid AFTER INSERT ON document_settlements DEFERRABLE INITIALLY DEFERRED
 FOR EACH ROW EXECUTE FUNCTION ww_check_event_trigger();

CREATE FUNCTION ww_check_payment_finance() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p payments%ROWTYPE; settlement UUID; refund UUID;
BEGIN
 SELECT * INTO p FROM payments WHERE id=NEW.id;
 IF NOT EXISTS(SELECT 1 FROM payment_events WHERE payment_id=p.id AND event_type='CREATED') THEN
  RAISE EXCEPTION 'payment requires initial audit event' USING ERRCODE='23514';
 END IF;
 IF p.status IN ('AUTHORIZED','SETTLED','REFUNDED') AND NOT EXISTS(SELECT 1 FROM payment_events WHERE payment_id=p.id AND event_type='AUTHORIZED') THEN
  RAISE EXCEPTION 'authorized history required' USING ERRCODE='23514';
 END IF;
 IF p.status='FAILED' AND NOT EXISTS(SELECT 1 FROM payment_events WHERE payment_id=p.id AND event_type=CASE WHEN p.failure_code='AUTHORIZATION_DECLINED' THEN 'AUTHORIZATION_DECLINED' ELSE 'SETTLEMENT_FAILED' END) THEN
  RAISE EXCEPTION 'failed state requires failure audit event' USING ERRCODE='23514';
 END IF;
 SELECT id INTO settlement FROM payment_events WHERE payment_id=p.id AND event_type='SETTLED';
 SELECT id INTO refund FROM payment_events WHERE payment_id=p.id AND event_type='REFUNDED';
 IF p.status IN ('SETTLED','REFUNDED') AND settlement IS NULL THEN
  RAISE EXCEPTION 'settled payment requires financial event' USING ERRCODE='23514';
 END IF;
 IF p.status='REFUNDED' AND refund IS NULL THEN
  RAISE EXCEPTION 'refunded payment requires compensation event' USING ERRCODE='23514';
 END IF;
 IF p.status NOT IN ('SETTLED','REFUNDED') AND (settlement IS NOT NULL OR refund IS NOT NULL) THEN
  RAISE EXCEPTION 'financial event incompatible with payment state' USING ERRCODE='23514';
 END IF;
 IF settlement IS NOT NULL THEN PERFORM ww_validate_event(settlement); END IF;
 IF refund IS NOT NULL THEN PERFORM ww_validate_event(refund); END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER payment_finance_valid AFTER INSERT OR UPDATE ON payments DEFERRABLE INITIALLY DEFERRED
 FOR EACH ROW EXECUTE FUNCTION ww_check_payment_finance();

CREATE FUNCTION ww_guard_idempotency() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'idempotency history cannot be deleted' USING ERRCODE='23514'; END IF;
 IF OLD.http_status IS NOT NULL OR ROW(NEW.id,NEW.merchant_id,NEW.operation,NEW.resource_scope,NEW.key,NEW.request_hash)
   IS DISTINCT FROM ROW(OLD.id,OLD.merchant_id,OLD.operation,OLD.resource_scope,OLD.key,OLD.request_hash) THEN
  RAISE EXCEPTION 'idempotency result is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER idempotency_immutable BEFORE UPDATE OR DELETE ON idempotency_records FOR EACH ROW EXECUTE FUNCTION ww_guard_idempotency();

CREATE FUNCTION ww_check_idempotency_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE final_status SMALLINT;
BEGIN
 SELECT http_status INTO final_status FROM idempotency_records WHERE id=NEW.id;
 IF final_status IS NULL THEN RAISE EXCEPTION 'incomplete idempotency result' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER idempotency_complete AFTER INSERT OR UPDATE ON idempotency_records DEFERRABLE INITIALLY DEFERRED
 FOR EACH ROW EXECUTE FUNCTION ww_check_idempotency_final();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS idempotency_records,ledger_entries,document_settlements,payment_events,payments,fiscal_documents,demo_credentials,merchants CASCADE;
DROP FUNCTION IF EXISTS ww_check_idempotency_final(),ww_guard_idempotency(),ww_check_payment_finance(),ww_check_event_trigger(),ww_validate_event(UUID),ww_guard_payment(),ww_freeze_document(),ww_immutable() CASCADE;
SQL);
    }
};
