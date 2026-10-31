BEGIN;

ALTER TABLE public.students ADD COLUMN IF NOT EXISTS campus varchar(100);
ALTER TABLE public.admins ADD COLUMN IF NOT EXISTS campus varchar(100);
ALTER TABLE public.admins ADD COLUMN IF NOT EXISTS staff_id varchar(6);

WITH ranked AS (
    SELECT user_id, row_number() OVER (ORDER BY user_id) AS position
    FROM public.admins
)
UPDATE public.admins a
SET staff_id = lpad((100000 + ranked.position)::text, 6, '0')
FROM ranked
WHERE a.user_id = ranked.user_id;

WITH ranked AS (
    SELECT user_id, row_number() OVER (ORDER BY user_id) AS position
    FROM public.admins
), campuses AS (
    SELECT ARRAY[
        'Arau', 'Shah Alam', 'Puncak Alam', 'Tapah', 'Jasin',
        'Machang', 'Kuala Terengganu', 'Samarahan', 'Kota Kinabalu', 'Segamat'
    ]::varchar[] AS names
)
UPDATE public.admins a
SET campus = campuses.names[((ranked.position - 1) % 10) + 1]
FROM ranked, campuses
WHERE a.user_id = ranked.user_id;

UPDATE public.admins a
SET campus = 'Arau'
FROM public.students s
JOIN public.users u ON u.id = s.user_id
WHERE a.user_id = s.coordinator_id
  AND (s.matric_id = '2025161705' OR u.name = 'Alif Mukhlis bin Mohd Tarmizi');

UPDATE public.students s
SET campus = a.campus
FROM public.admins a
WHERE a.user_id = s.coordinator_id;

UPDATE public.students s
SET campus = 'Arau'
FROM public.users u
WHERE u.id = s.user_id
  AND (s.matric_id = '2025161705' OR u.name = 'Alif Mukhlis bin Mohd Tarmizi');

UPDATE public.users u
SET email = lower(s.matric_id) || '@student.uitm.edu.my'
FROM public.students s
WHERE u.id = s.user_id;

WITH coordinator_names AS (
    SELECT u.id,
           regexp_replace(
               regexp_replace(
                   regexp_replace(lower(u.name), '^(dr\.?|prof\.?|ir\.?)\s+', '', 'i'),
                   '\s+(bin|binti)\s+.*$', '', 'i'
               ),
               '[^a-z0-9]', '', 'g'
           ) AS base_name
    FROM public.users u
    JOIN public.admins a ON a.user_id = u.id
), ranked AS (
    SELECT id, base_name,
           row_number() OVER (PARTITION BY base_name ORDER BY id) AS duplicate_number
    FROM coordinator_names
)
UPDATE public.users u
SET email = ranked.base_name
    || CASE WHEN ranked.duplicate_number > 1 THEN ranked.duplicate_number::text ELSE '' END
    || '@uitm.edu.my'
FROM ranked
WHERE u.id = ranked.id;

UPDATE public.users
SET phone = '+6011' || lpad(id::text, 8, '0')
WHERE role IN ('student', 'admin', 'employer');

ALTER TABLE public.admins ALTER COLUMN staff_id SET NOT NULL;
ALTER TABLE public.admins ALTER COLUMN campus SET NOT NULL;
ALTER TABLE public.students ALTER COLUMN campus SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.admins'::regclass
          AND conname = 'admins_staff_id_format_check'
    ) THEN
        ALTER TABLE public.admins
            ADD CONSTRAINT admins_staff_id_format_check
            CHECK (staff_id ~ '^[0-9]{6}$');
    END IF;

    IF EXISTS (
        SELECT 1 FROM public.students s
        JOIN public.users u ON u.id = s.user_id
        WHERE lower(u.email) <> lower(s.matric_id) || '@student.uitm.edu.my'
    ) THEN
        RAISE EXCEPTION 'Student email normalization failed';
    END IF;

    IF EXISTS (
        SELECT 1 FROM public.admins a
        JOIN public.users u ON u.id = a.user_id
        WHERE a.staff_id !~ '^[0-9]{6}$'
           OR u.email !~ '^[a-z][a-z0-9]*@uitm[.]edu[.]my$'
    ) OR EXISTS (
        SELECT staff_id FROM public.admins GROUP BY staff_id HAVING count(*) > 1
    ) THEN
        RAISE EXCEPTION 'Coordinator account normalization failed';
    END IF;

    IF EXISTS (
        SELECT 1 FROM public.users
        WHERE role IN ('student', 'admin', 'employer')
          AND phone !~ '^\+6011[0-9]{8}$'
    ) OR EXISTS (
        SELECT phone FROM public.users GROUP BY phone HAVING count(*) > 1
    ) THEN
        RAISE EXCEPTION 'Synthetic phone normalization failed';
    END IF;

    IF EXISTS (
        SELECT 1 FROM public.students s
        LEFT JOIN public.users u ON u.id = s.user_id AND u.role = 'student'
        LEFT JOIN public.admins a ON a.user_id = s.coordinator_id
        WHERE u.id IS NULL OR a.user_id IS NULL
    ) OR EXISTS (
        SELECT 1 FROM public.admins a
        LEFT JOIN public.users u ON u.id = a.user_id AND u.role = 'admin'
        WHERE u.id IS NULL
    ) THEN
        RAISE EXCEPTION 'Role profile relationship validation failed';
    END IF;

    IF EXISTS (
        SELECT 1 FROM public.students s
        JOIN public.users u ON u.id = s.user_id
        WHERE (s.matric_id = '2025161705' OR u.name = 'Alif Mukhlis bin Mohd Tarmizi')
          AND s.campus <> 'Arau'
    ) THEN
        RAISE EXCEPTION 'Alif campus validation failed';
    END IF;
END;
$$;

COMMIT;
