-- Safe additive migration for campus, verified password changes, and the
-- March 2027 internship cycle. Existing records are preserved.
BEGIN;

ALTER TABLE public.students ADD COLUMN IF NOT EXISTS campus varchar(100);
ALTER TABLE public.admins ADD COLUMN IF NOT EXISTS campus varchar(100);
ALTER TABLE public.offers ADD COLUMN IF NOT EXISTS internship_start_date date;
ALTER TABLE public.password_reset_tokens
    ADD COLUMN IF NOT EXISTS purpose varchar(30) NOT NULL DEFAULT 'password_reset';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'password_reset_tokens_purpose_check'
          AND conrelid = 'public.password_reset_tokens'::regclass
    ) THEN
        ALTER TABLE public.password_reset_tokens
            ADD CONSTRAINT password_reset_tokens_purpose_check
            CHECK (purpose IN ('password_reset', 'password_change'));
    END IF;
END;
$$;

UPDATE public.students s
SET campus = 'Arau'
FROM public.users u
WHERE u.id = s.user_id
  AND (s.matric_id = '2025161705' OR u.name ILIKE 'Alif Mukhlis%')
  AND s.campus IS DISTINCT FROM 'Arau';

UPDATE public.admins a
SET campus = 'Arau'
WHERE a.user_id IN (
    SELECT s.coordinator_id
    FROM public.students s
    JOIN public.users u ON u.id = s.user_id
    WHERE (s.matric_id = '2025161705' OR u.name ILIKE 'Alif Mukhlis%')
      AND s.coordinator_id IS NOT NULL
)
AND a.campus IS DISTINCT FROM 'Arau';

-- Isolated staging uses reserved synthetic identities rather than Alif's row.
UPDATE public.students
SET campus = CASE matric_id WHEN 'STG000001' THEN 'Arau' ELSE 'Shah Alam' END
WHERE matric_id IN ('STG000001', 'STG000002');

UPDATE public.admins a
SET campus = CASE u.email
    WHEN 'coordinator.alpha@example.test' THEN 'Arau'
    ELSE 'Shah Alam'
END
FROM public.users u
WHERE u.id = a.user_id
  AND u.email IN ('coordinator.alpha@example.test', 'coordinator.beta@example.test');

UPDATE public.offers
SET internship_start_date = DATE '2027-03-01'
WHERE internship_start_date IS NULL;

INSERT INTO public.system_settings (setting_key, setting_value) VALUES
    ('internship_application_open', '2026-11-02'),
    ('main_offer_deadline', '2026-12-15'),
    ('placement_confirmation_deadline', '2027-01-15'),
    ('document_completion_deadline', '2027-01-31'),
    ('internship_start_date', '2027-03-01')
ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value;

COMMIT;
