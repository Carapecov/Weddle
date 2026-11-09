<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION ww_document_identity_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id IS DISTINCT FROM OLD.id THEN
  RAISE EXCEPTION 'document UUID is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER document_identity_immutable BEFORE UPDATE OF id ON fiscal_documents
 FOR EACH ROW EXECUTE FUNCTION ww_document_identity_immutable();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS document_identity_immutable ON fiscal_documents;
DROP FUNCTION IF EXISTS ww_document_identity_immutable();
SQL);
    }
};
