-- Synthetic FYP/demo opportunities only. These are not real vacancies.
-- Idempotency key: active company + position_title + intake.
BEGIN;

WITH seed(company_name, position_title, department, description, location, status, intake, application_deadline) AS (
    VALUES
    ('Petronas ICT Sdn Bhd','Cloud Engineering Intern','Cloud Platforms','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','KLCC, Kuala Lumpur','open','March 2027 Demo','2027-01-31'::date),
    ('Petronas ICT Sdn Bhd','Corporate Communication Intern','Corporate Affairs','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','KLCC, Kuala Lumpur','open','March 2027 Demo','2027-02-05'::date),
    ('Tech Solutions Sdn Bhd','Software Development Intern','Software Engineering','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Mid Valley, KL','open','March 2027 Demo','2027-02-10'::date),
    ('Tech Solutions Sdn Bhd','IT Support Intern','Managed Services','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Mid Valley, KL','open','March 2027 Demo','2027-02-10'::date),
    ('Axiata Digital Sdn Bhd','Network Engineering Intern','Network Operations','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Cyberjaya, Selangor','open','March 2027 Demo','2027-02-07'::date),
    ('Axiata Digital Sdn Bhd','Marketing Intern','Digital Marketing','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Cyberjaya, Selangor','open','March 2027 Demo','2027-02-07'::date),
    ('Maybank IT Division','Accounting Intern','Finance Control','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Bangsar South, KL','open','March 2027 Demo','2027-01-31'::date),
    ('Maybank IT Division','Cybersecurity Intern','Information Security','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Bangsar South, KL','open','March 2027 Demo','2027-01-31'::date),
    ('Microsoft Malaysia','Cloud Engineering Intern','Cloud Solutions','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','KLCC, Kuala Lumpur','open','March 2027 Demo','2027-02-15'::date),
    ('Microsoft Malaysia','Data Analytics Intern','Customer Success Analytics','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','KLCC, Kuala Lumpur','open','March 2027 Demo','2027-02-15'::date),
    ('KPJ Healthcare IT','Healthcare IT Support Intern','Health Information Systems','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Damansara, KL','open','March 2027 Demo','2027-01-25'::date),
    ('KPJ Healthcare IT','Medical Laboratory Operations Intern','Clinical Laboratory','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Damansara, KL','open','March 2027 Demo','2027-01-25'::date),
    ('KPJ Healthcare IT','Wellness Programme Intern','Community Wellness','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Damansara, KL','closed','March 2027 Demo','2027-01-15'::date),
    ('Global Elite Ventures','Network Engineering Intern','Network Infrastructure','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','123, jalan merak','open','March 2027 Demo','2027-02-08'::date),
    ('Global Elite Ventures','Business Administration Intern','Business Operations','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','123, jalan merak','open','March 2027 Demo','2027-02-08'::date),
    ('Meridian Data Labs Sdn Bhd','Data Analytics Intern','Data Insights','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Shah Alam, Selangor','open','March 2027 Demo','2027-02-12'::date),
    ('Meridian Data Labs Sdn Bhd','Business Intelligence Intern','Business Intelligence','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Shah Alam, Selangor','open','March 2027 Demo','2027-02-12'::date),
    ('Cendana Creative Studio Sdn Bhd','Graphic Design Intern','Visual Design','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Petaling Jaya, Selangor','open','March 2027 Demo','2027-02-01'::date),
    ('Cendana Creative Studio Sdn Bhd','Multimedia Intern','Digital Media','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Petaling Jaya, Selangor','open','March 2027 Demo','2027-02-01'::date),
    ('Nusa Civil Consultants Sdn Bhd','Civil Engineering Intern','Civil Design','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Kuala Lumpur','open','March 2027 Demo','2027-01-30'::date),
    ('Nusa Civil Consultants Sdn Bhd','Quantity Surveying Intern','Cost Management','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Kuala Lumpur','open','March 2027 Demo','2027-01-30'::date),
    ('Sinaraya Health Services Sdn Bhd','Healthcare Administration Intern','Healthcare Operations','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Shah Alam, Selangor','open','March 2027 Demo','2027-01-28'::date),
    ('Sinaraya Health Services Sdn Bhd','Healthcare Services Observation Intern','Clinical Services','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Shah Alam, Selangor','closed','March 2027 Demo','2027-01-20'::date),
    ('Bayu Digital Commerce Sdn Bhd','Web Development Intern','Digital Product','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Subang Jaya, Selangor','open','March 2027 Demo','2027-02-10'::date),
    ('Bayu Digital Commerce Sdn Bhd','E-Commerce Marketing Intern','Growth Marketing','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Subang Jaya, Selangor','open','March 2027 Demo','2027-02-10'::date),
    ('Rimba Agrotech Sdn Bhd','Agrotechnology Intern','Crop Technology','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Klang, Selangor','open','March 2027 Demo','2027-01-31'::date),
    ('Rimba Agrotech Sdn Bhd','Data Operations Intern','Farm Data Operations','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Klang, Selangor','open','March 2027 Demo','2027-01-31'::date),
    ('Jelita Hospitality Group Sdn Bhd','Hotel Operations Intern','Hotel Operations','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Kuala Lumpur','open','March 2027 Demo','2027-02-03'::date),
    ('Jelita Hospitality Group Sdn Bhd','Marketing and Guest Relations Intern','Guest Relations','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Kuala Lumpur','open','March 2027 Demo','2027-02-03'::date),
    ('Awan Cloudworks Sdn Bhd','Cloud Engineering Intern','Cloud Infrastructure','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Cyberjaya, Selangor','open','March 2027 Demo','2027-02-14'::date),
    ('Awan Cloudworks Sdn Bhd','Cybersecurity Intern','Cloud Security','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Cyberjaya, Selangor','open','March 2027 Demo','2027-02-14'::date),
    ('Puncak Finance Advisory Sdn Bhd','Accounting Intern','Accounting Services','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Kuala Lumpur','open','March 2027 Demo','2027-01-31'::date),
    ('Puncak Finance Advisory Sdn Bhd','Finance Operations Intern','Finance Operations','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Kuala Lumpur','open','March 2027 Demo','2027-01-31'::date),
    ('Puncak Finance Advisory Sdn Bhd','Compliance and Legal Intern','Compliance','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Kuala Lumpur','open','March 2027 Demo','2027-01-31'::date),
    ('Lestari Learning Hub Sdn Bhd','Web Development Intern','Learning Platforms','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Shah Alam, Selangor','open','March 2027 Demo','2027-02-09'::date),
    ('Lestari Learning Hub Sdn Bhd','Education Content Intern','Learning Content','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Shah Alam, Selangor','open','March 2027 Demo','2027-02-09'::date),
    ('Teras Engineering Solutions Sdn Bhd','Mechanical Engineering Intern','Mechanical Design','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Bangi, Selangor','open','March 2027 Demo','2027-01-29'::date),
    ('Teras Engineering Solutions Sdn Bhd','Manufacturing Technology Intern','Manufacturing Engineering','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Bangi, Selangor','open','March 2027 Demo','2027-01-29'::date),
    ('Wira Media House Sdn Bhd','Content and Media Intern','Editorial Media','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Petaling Jaya, Selangor','closed','March 2027 Demo','2027-01-20'::date),
    ('Wira Media House Sdn Bhd','Graphic Design Intern','Creative Design','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Petaling Jaya, Selangor','inactive','March 2027 Demo','2027-01-20'::date),
    ('Wira Media House Sdn Bhd','Audio Production Intern','Audio and Production','[DEMO] Synthetic FYP opportunity for programme-matching demonstration only; not a real vacancy.','Petaling Jaya, Selangor','closed','March 2027 Demo','2027-01-20'::date)
)
INSERT INTO public.internship_opportunities
    (company_id, position_title, department, description, location, status, intake, application_deadline)
SELECT c.id, s.position_title, s.department, s.description, s.location, s.status, s.intake, s.application_deadline
FROM seed s
JOIN public.companies c ON c.name = s.company_name AND c.status = 'active'
WHERE NOT EXISTS (
    SELECT 1 FROM public.internship_opportunities io
    WHERE io.company_id = c.id
      AND lower(btrim(io.position_title)) = lower(btrim(s.position_title))
      AND lower(btrim(coalesce(io.intake, ''))) = lower(btrim(s.intake))
);

WITH mapping(company_name, position_title, programme, programme_normalized) AS (
    VALUES
    ('Petronas ICT Sdn Bhd','Cloud Engineering Intern','Bachelor of Computer Science with Honours (Netcentric Computing)','netcentric computing'),
    ('Petronas ICT Sdn Bhd','Cloud Engineering Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Petronas ICT Sdn Bhd','Cloud Engineering Intern','Bachelor of Electrical Engineering with Honours','bachelor of electrical engineering with honours'),
    ('Petronas ICT Sdn Bhd','Corporate Communication Intern','Bachelor of Mass Communication with Honours (Public Relations)','public relations'),
    ('Petronas ICT Sdn Bhd','Corporate Communication Intern','Bachelor of Administrative Science with Honours','bachelor of administrative science with honours'),
    ('Petronas ICT Sdn Bhd','Corporate Communication Intern','Bachelor of Business Administration with Honours (Marketing)','marketing'),
    ('Tech Solutions Sdn Bhd','Software Development Intern','Bachelor of Computer Science with Honours (Netcentric Computing)','netcentric computing'),
    ('Tech Solutions Sdn Bhd','Software Development Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Tech Solutions Sdn Bhd','IT Support Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Tech Solutions Sdn Bhd','IT Support Intern','Bachelor of Information Science with Honours (Information Management)','information management'),
    ('Axiata Digital Sdn Bhd','Network Engineering Intern','Bachelor of Computer Science with Honours (Netcentric Computing)','netcentric computing'),
    ('Axiata Digital Sdn Bhd','Network Engineering Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Axiata Digital Sdn Bhd','Network Engineering Intern','Bachelor of Electrical Engineering with Honours','bachelor of electrical engineering with honours'),
    ('Axiata Digital Sdn Bhd','Marketing Intern','Bachelor of Business Administration with Honours (Marketing)','marketing'),
    ('Axiata Digital Sdn Bhd','Marketing Intern','Bachelor of Administrative Science with Honours','bachelor of administrative science with honours'),
    ('Axiata Digital Sdn Bhd','Marketing Intern','Bachelor of Mass Communication with Honours (Public Relations)','public relations'),
    ('Maybank IT Division','Accounting Intern','Bachelor of Accountancy with Honours','bachelor of accountancy with honours'),
    ('Maybank IT Division','Cybersecurity Intern','Bachelor of Computer Science with Honours (Netcentric Computing)','netcentric computing'),
    ('Maybank IT Division','Cybersecurity Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Microsoft Malaysia','Cloud Engineering Intern','Bachelor of Computer Science with Honours (Netcentric Computing)','netcentric computing'),
    ('Microsoft Malaysia','Cloud Engineering Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Microsoft Malaysia','Cloud Engineering Intern','Bachelor of Electrical Engineering with Honours','bachelor of electrical engineering with honours'),
    ('Microsoft Malaysia','Data Analytics Intern','Bachelor of Computer Science with Honours (Netcentric Computing)','netcentric computing'),
    ('Microsoft Malaysia','Data Analytics Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Microsoft Malaysia','Data Analytics Intern','Bachelor of Information Science with Honours (Information Management)','information management'),
    ('KPJ Healthcare IT','Healthcare IT Support Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('KPJ Healthcare IT','Healthcare IT Support Intern','Bachelor of Information Science with Honours (Information Management)','information management'),
    ('KPJ Healthcare IT','Medical Laboratory Operations Intern','Bachelor of Medical Laboratory Technology with Honours','bachelor of medical laboratory technology with honours'),
    ('KPJ Healthcare IT','Medical Laboratory Operations Intern','Bachelor of Science with Honours (Applied Chemistry)','applied chemistry'),
    ('KPJ Healthcare IT','Wellness Programme Intern','Bachelor of Sports Science with Honours','bachelor of sports science with honours'),
    ('KPJ Healthcare IT','Wellness Programme Intern','Bachelor of Administrative Science with Honours','bachelor of administrative science with honours'),
    ('Global Elite Ventures','Network Engineering Intern','Bachelor of Computer Science with Honours (Netcentric Computing)','netcentric computing'),
    ('Global Elite Ventures','Network Engineering Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Global Elite Ventures','Network Engineering Intern','Bachelor of Electrical Engineering with Honours','bachelor of electrical engineering with honours'),
    ('Global Elite Ventures','Business Administration Intern','Bachelor of Administrative Science with Honours','bachelor of administrative science with honours'),
    ('Global Elite Ventures','Business Administration Intern','Bachelor of Business Administration with Honours (Marketing)','marketing'),
    ('Meridian Data Labs Sdn Bhd','Data Analytics Intern','Bachelor of Computer Science with Honours (Netcentric Computing)','netcentric computing'),
    ('Meridian Data Labs Sdn Bhd','Data Analytics Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Meridian Data Labs Sdn Bhd','Data Analytics Intern','Bachelor of Information Science with Honours (Information Management)','information management'),
    ('Meridian Data Labs Sdn Bhd','Business Intelligence Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Meridian Data Labs Sdn Bhd','Business Intelligence Intern','Bachelor of Administrative Science with Honours','bachelor of administrative science with honours'),
    ('Meridian Data Labs Sdn Bhd','Business Intelligence Intern','Bachelor of Accountancy with Honours','bachelor of accountancy with honours'),
    ('Cendana Creative Studio Sdn Bhd','Graphic Design Intern','Bachelor of Graphic Design with Honours','bachelor of graphic design with honours'),
    ('Cendana Creative Studio Sdn Bhd','Graphic Design Intern','Bachelor of Animation with Honours','bachelor of animation with honours'),
    ('Cendana Creative Studio Sdn Bhd','Multimedia Intern','Bachelor of Animation with Honours','bachelor of animation with honours'),
    ('Cendana Creative Studio Sdn Bhd','Multimedia Intern','Bachelor of Graphic Design with Honours','bachelor of graphic design with honours'),
    ('Cendana Creative Studio Sdn Bhd','Multimedia Intern','Bachelor of Mass Communication with Honours (Public Relations)','public relations'),
    ('Nusa Civil Consultants Sdn Bhd','Civil Engineering Intern','Bachelor of Civil Engineering with Honours','bachelor of civil engineering with honours'),
    ('Nusa Civil Consultants Sdn Bhd','Civil Engineering Intern','Bachelor of Quantity Surveying with Honours','bachelor of quantity surveying with honours'),
    ('Nusa Civil Consultants Sdn Bhd','Quantity Surveying Intern','Bachelor of Quantity Surveying with Honours','bachelor of quantity surveying with honours'),
    ('Nusa Civil Consultants Sdn Bhd','Quantity Surveying Intern','Bachelor of Civil Engineering with Honours','bachelor of civil engineering with honours'),
    ('Sinaraya Health Services Sdn Bhd','Healthcare Administration Intern','Bachelor of Administrative Science with Honours','bachelor of administrative science with honours'),
    ('Sinaraya Health Services Sdn Bhd','Healthcare Administration Intern','Bachelor of Information Science with Honours (Information Management)','information management'),
    ('Sinaraya Health Services Sdn Bhd','Healthcare Services Observation Intern','Bachelor of Medicine and Bachelor of Surgery','bachelor of medicine and bachelor of surgery'),
    ('Sinaraya Health Services Sdn Bhd','Healthcare Services Observation Intern','Bachelor of Dental Surgery','bachelor of dental surgery'),
    ('Sinaraya Health Services Sdn Bhd','Healthcare Services Observation Intern','Bachelor of Pharmacy with Honours','bachelor of pharmacy with honours'),
    ('Bayu Digital Commerce Sdn Bhd','Web Development Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Bayu Digital Commerce Sdn Bhd','E-Commerce Marketing Intern','Bachelor of Business Administration with Honours (Marketing)','marketing'),
    ('Bayu Digital Commerce Sdn Bhd','E-Commerce Marketing Intern','Bachelor of Administrative Science with Honours','bachelor of administrative science with honours'),
    ('Bayu Digital Commerce Sdn Bhd','E-Commerce Marketing Intern','Bachelor of Graphic Design with Honours','bachelor of graphic design with honours'),
    ('Rimba Agrotech Sdn Bhd','Agrotechnology Intern','Bachelor of Agrotechnology with Honours','bachelor of agrotechnology with honours'),
    ('Rimba Agrotech Sdn Bhd','Agrotechnology Intern','Bachelor of Science with Honours (Applied Chemistry)','applied chemistry'),
    ('Rimba Agrotech Sdn Bhd','Data Operations Intern','Bachelor of Information Science with Honours (Information Management)','information management'),
    ('Rimba Agrotech Sdn Bhd','Data Operations Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Jelita Hospitality Group Sdn Bhd','Hotel Operations Intern','Bachelor of Hotel Management with Honours','bachelor of hotel management with honours'),
    ('Jelita Hospitality Group Sdn Bhd','Hotel Operations Intern','Bachelor of Administrative Science with Honours','bachelor of administrative science with honours'),
    ('Jelita Hospitality Group Sdn Bhd','Marketing and Guest Relations Intern','Bachelor of Hotel Management with Honours','bachelor of hotel management with honours'),
    ('Jelita Hospitality Group Sdn Bhd','Marketing and Guest Relations Intern','Bachelor of Business Administration with Honours (Marketing)','marketing'),
    ('Jelita Hospitality Group Sdn Bhd','Marketing and Guest Relations Intern','Bachelor of Mass Communication with Honours (Public Relations)','public relations'),
    ('Awan Cloudworks Sdn Bhd','Cloud Engineering Intern','Bachelor of Computer Science with Honours (Netcentric Computing)','netcentric computing'),
    ('Awan Cloudworks Sdn Bhd','Cloud Engineering Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Awan Cloudworks Sdn Bhd','Cloud Engineering Intern','Bachelor of Electrical Engineering with Honours','bachelor of electrical engineering with honours'),
    ('Awan Cloudworks Sdn Bhd','Cybersecurity Intern','Bachelor of Computer Science with Honours (Netcentric Computing)','netcentric computing'),
    ('Awan Cloudworks Sdn Bhd','Cybersecurity Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Puncak Finance Advisory Sdn Bhd','Accounting Intern','Bachelor of Accountancy with Honours','bachelor of accountancy with honours'),
    ('Puncak Finance Advisory Sdn Bhd','Finance Operations Intern','Bachelor of Accountancy with Honours','bachelor of accountancy with honours'),
    ('Puncak Finance Advisory Sdn Bhd','Finance Operations Intern','Bachelor of Administrative Science with Honours','bachelor of administrative science with honours'),
    ('Puncak Finance Advisory Sdn Bhd','Compliance and Legal Intern','Bachelor of Laws with Honours','bachelor of laws with honours'),
    ('Puncak Finance Advisory Sdn Bhd','Compliance and Legal Intern','Bachelor of Administrative Science with Honours','bachelor of administrative science with honours'),
    ('Lestari Learning Hub Sdn Bhd','Web Development Intern','Bachelor of Computer Science with Honours (Netcentric Computing)','netcentric computing'),
    ('Lestari Learning Hub Sdn Bhd','Web Development Intern','Bachelor of Computer Science with Honours','bachelor of computer science with honours'),
    ('Lestari Learning Hub Sdn Bhd','Education Content Intern','Bachelor of Education with Honours (Teaching English as a Second Language)','teaching english as a second language'),
    ('Lestari Learning Hub Sdn Bhd','Education Content Intern','Bachelor of Mass Communication with Honours (Public Relations)','public relations'),
    ('Lestari Learning Hub Sdn Bhd','Education Content Intern','Bachelor of Graphic Design with Honours','bachelor of graphic design with honours'),
    ('Teras Engineering Solutions Sdn Bhd','Mechanical Engineering Intern','Bachelor of Mechanical Engineering with Honours','bachelor of mechanical engineering with honours'),
    ('Teras Engineering Solutions Sdn Bhd','Manufacturing Technology Intern','Bachelor of Mechanical Engineering with Honours','bachelor of mechanical engineering with honours'),
    ('Teras Engineering Solutions Sdn Bhd','Manufacturing Technology Intern','Bachelor of Electrical Engineering with Honours','bachelor of electrical engineering with honours'),
    ('Teras Engineering Solutions Sdn Bhd','Manufacturing Technology Intern','Bachelor of Chemical Engineering with Honours','bachelor of chemical engineering with honours'),
    ('Wira Media House Sdn Bhd','Content and Media Intern','Bachelor of Mass Communication with Honours (Public Relations)','public relations'),
    ('Wira Media House Sdn Bhd','Content and Media Intern','Bachelor of Animation with Honours','bachelor of animation with honours'),
    ('Wira Media House Sdn Bhd','Graphic Design Intern','Bachelor of Graphic Design with Honours','bachelor of graphic design with honours'),
    ('Wira Media House Sdn Bhd','Graphic Design Intern','Bachelor of Animation with Honours','bachelor of animation with honours'),
    ('Wira Media House Sdn Bhd','Audio Production Intern','Bachelor of Music with Honours (Performance)','performance'),
    ('Wira Media House Sdn Bhd','Audio Production Intern','Bachelor of Animation with Honours','bachelor of animation with honours')
)
INSERT INTO public.opportunity_programmes (opportunity_id, programme, programme_normalized)
SELECT io.id, m.programme, m.programme_normalized
FROM mapping m
JOIN public.companies c ON c.name = m.company_name
JOIN public.internship_opportunities io
  ON io.company_id = c.id
 AND io.position_title = m.position_title
 AND io.intake = 'March 2027 Demo'
ON CONFLICT (opportunity_id, programme_normalized)
DO UPDATE SET programme = EXCLUDED.programme;

COMMIT;
