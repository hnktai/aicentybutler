CREATE OR REPLACE FUNCTION public.butler_identity_scope_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
DECLARE
    v_binding butler_identity_bindings%ROWTYPE;
    v_master UUID := '0193a5b0-7000-7000-8000-000000000001'::uuid;
    v_family TEXT;
    v_butler TEXT;
BEGIN
    SELECT * INTO v_binding
    FROM butler_identity_bindings
    WHERE external_user_id = NEW.user_id AND status = 'active';
    IF NOT FOUND THEN
        RETURN NEW;
    END IF;

    IF NEW.tenant_id <> v_master AND NEW.tenant_id <> v_binding.tenant_id THEN
        RAISE EXCEPTION 'P0 tenant isolation violation: identity % belongs to tenant %, attempted tenant %',
            NEW.user_id, v_binding.tenant_id, NEW.tenant_id;
    END IF;

    IF TG_TABLE_NAME = 'user_agent_profiles' THEN
        v_family := COALESCE(NEW.metadata->>'family_name', '');
        v_butler := COALESCE(NEW.metadata->>'butler_name', '');
        IF v_family <> '' AND v_binding.family_name <> '' AND v_family <> v_binding.family_name THEN
            RAISE EXCEPTION 'P0 family identity mismatch for %: expected %, got %',
                NEW.user_id, v_binding.family_name, v_family;
        END IF;
        IF v_butler <> '' AND v_binding.butler_name <> '' AND v_butler <> v_binding.butler_name THEN
            RAISE EXCEPTION 'P0 butler identity mismatch for %: expected %, got %',
                NEW.user_id, v_binding.butler_name, v_butler;
        END IF;
    ELSIF TG_TABLE_NAME = 'user_context_files' THEN
        IF NEW.file_name = 'USER.md' THEN
            IF v_binding.family_name <> '' AND position(v_binding.family_name IN NEW.content) = 0 THEN
                RAISE EXCEPTION 'P0 USER.md family mismatch for identity %', NEW.user_id;
            END IF;
            IF v_binding.butler_name <> '' AND position(v_binding.butler_name IN NEW.content) = 0 THEN
                RAISE EXCEPTION 'P0 USER.md butler mismatch for identity %', NEW.user_id;
            END IF;
        END IF;
    END IF;

    RETURN NEW;
END;
$function$;
