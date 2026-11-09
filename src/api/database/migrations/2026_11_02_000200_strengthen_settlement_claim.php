<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION ww_claim_requires_settlement() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(
  SELECT 1 FROM payment_events e JOIN payments p ON p.id=e.payment_id
  WHERE e.id=NEW.settlement_event_id AND e.payment_id=NEW.payment_id
    AND e.event_type='SETTLED' AND p.fiscal_document_id=NEW.fiscal_document_id
    AND p.status IN ('SETTLED','REFUNDED')
 ) THEN
  RAISE EXCEPTION 'document claim requires its completed settlement event' USING ERRCODE='23514';
 END IF;
 PERFORM ww_validate_event(NEW.settlement_event_id);
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER claim_requires_settlement AFTER INSERT ON document_settlements
 DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ww_claim_requires_settlement();
DO $$
BEGIN
 IF EXISTS(
  SELECT 1 FROM document_settlements c JOIN payment_events e ON e.id=c.settlement_event_id
  JOIN payments p ON p.id=c.payment_id
  WHERE e.event_type<>'SETTLED' OR e.payment_id<>c.payment_id
    OR p.fiscal_document_id<>c.fiscal_document_id OR p.status NOT IN ('SETTLED','REFUNDED')
 ) THEN
  RAISE EXCEPTION 'existing claim violates settlement integrity' USING ERRCODE='23514';
 END IF;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS claim_requires_settlement ON document_settlements;
DROP FUNCTION IF EXISTS ww_claim_requires_settlement();
SQL);
    }
};
