-- Run inside the same transaction as the data import.

-- Keep the imported value when it exists; initialize only a new/empty source.
INSERT INTO public.system_settings (setting_key, setting_value)
VALUES ('offer_response_period_days', '14')
ON CONFLICT (setting_key) DO NOTHING;

-- Fail the transaction if any imported relationship is orphaned.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.offers o
        LEFT JOIN public.internship_applications a ON a.id = o.application_id
        WHERE o.application_id IS NOT NULL AND a.id IS NULL
    ) OR EXISTS (
        SELECT 1
        FROM public.offers o
        LEFT JOIN public.users u ON u.id = o.placement_verification_updated_by
        WHERE o.placement_verification_updated_by IS NOT NULL AND u.id IS NULL
    ) OR EXISTS (
        SELECT 1
        FROM public.documents d
        LEFT JOIN public.users u ON u.id = d.reviewed_by
        WHERE d.reviewed_by IS NOT NULL AND u.id IS NULL
    ) OR EXISTS (
        SELECT 1
        FROM public.notifications n
        LEFT JOIN public.announcements a ON a.id = n.announcement_id
        WHERE n.announcement_id IS NOT NULL AND a.id IS NULL
    ) THEN
        RAISE EXCEPTION 'Post-import relationship verification failed';
    END IF;
END;
$$;

