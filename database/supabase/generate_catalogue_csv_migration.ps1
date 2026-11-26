param(
  [string]$CsvPath = (Join-Path $PSScriptRoot 'uitm_academic_catalogue.csv'),
  [string]$OutputPath = (Join-Path $PSScriptRoot 'academic_catalogue_csv_import.sql')
)
$ErrorActionPreference = 'Stop'
$rows = @(Import-Csv -LiteralPath $CsvPath -Encoding UTF8)
if ($rows.Count -ne 435) { throw "Unexpected CSV row count: $($rows.Count)" }
function Q([string]$s) { "'" + $s.Replace("'", "''") + "'" }
function Url([string]$s) {
  $uri = [uri]$s
  if ($uri.Scheme -ne 'https' -or ($uri.Host -ne 'uitm.edu.my' -and !$uri.Host.EndsWith('.uitm.edu.my'))) { throw "Unverified source host: $s" }
  Q $s
}
$oldSql = Get-Content (Join-Path $PSScriptRoot 'academic_catalogue_migration.sql') -Raw -Encoding UTF8
$matches = [regex]::Matches($oldSql, "\('([A-Z]{1,4}\d{2,3})','(?:[^']|'')*','((?:[^']|'')*)','(?:diploma|degree)'")
$old = @{}; foreach ($m in $matches) { $old[$m.Groups[1].Value] = $m.Groups[2].Value.Replace("''", "'") }
$facultyNames = @{}
foreach ($row in $rows | Where-Object row_type -eq 'programme') {
  if ($old.ContainsKey($row.programme_code)) {
    $unit = $old[$row.programme_code]
    if ($facultyNames.ContainsKey($row.faculty) -and $facultyNames[$row.faculty] -ne $unit) { throw "Conflicting faculty: $($row.faculty)" }
    $facultyNames[$row.faculty] = $unit
  }
}
$facultyNames['Faculty of Medicine'] = 'Fakulti Perubatan'
$programmes = @($rows | Where-Object row_type -eq 'programme')
$campuses = @($rows | Where-Object row_type -eq 'campus')
$offerings = @($rows | Where-Object row_type -eq 'campus_programme')
$codeSet = @{}; foreach ($row in $programmes) {
  if ($row.programme_code -notmatch '^[A-Z]{1,4}[0-9]{2,3}$' -or $codeSet.ContainsKey($row.programme_code)) { throw "Invalid or duplicate code: $($row.programme_code)" }
  if (!$facultyNames.ContainsKey($row.faculty) -or $row.study_level -notin @('Diploma','Degree')) { throw "Invalid programme: $($row.programme_code)" }
  $codeSet[$row.programme_code] = $true
}
foreach ($row in $offerings) {
  if (!$codeSet.ContainsKey($row.programme_code) -or $row.campus_mapping_status -notin @('official_historical_mapping','listed_by_official_campus','listed_by_official_faculty')) { throw "Invalid offering: $($row.programme_code)" }
}
$sql = [System.Collections.Generic.List[string]]::new()
$sql.Add('-- Generated from the supplied CSV. Historical campus listings are stored separately from verified offerings.')
$sql.Add('BEGIN;')
$sql.Add('ALTER TABLE public.academic_campuses ADD COLUMN IF NOT EXISTS state varchar(80);')
$sql.Add('ALTER TABLE public.academic_units ADD COLUMN IF NOT EXISTS display_name varchar(150);')
$sql.Add('ALTER TABLE public.academic_programmes ADD COLUMN IF NOT EXISTS display_name varchar(150);')
$sql.Add('ALTER TABLE public.academic_programmes ADD COLUMN IF NOT EXISTS name_ms varchar(150);')
$sql.Add('ALTER TABLE public.academic_programmes ADD COLUMN IF NOT EXISTS upu_code varchar(30);')
$sql.Add('ALTER TABLE public.academic_programmes ADD COLUMN IF NOT EXISTS catalogue_status varchar(50);')
$sql.Add('ALTER TABLE public.students ADD COLUMN IF NOT EXISTS study_level varchar(12) CHECK (study_level IN (''diploma'',''degree''));')
$sql.Add('ALTER TABLE public.student_registration_requests ADD COLUMN IF NOT EXISTS study_level varchar(12) CHECK (study_level IN (''diploma'',''degree''));')
$sql.Add('CREATE TABLE IF NOT EXISTS public.academic_campus_aliases (campus_name varchar(100) NOT NULL REFERENCES public.academic_campuses(name), alias varchar(100) NOT NULL, source_url text NOT NULL, PRIMARY KEY(campus_name,alias));')
$sql.Add('CREATE TABLE IF NOT EXISTS public.academic_programme_aliases (programme_code varchar(12) NOT NULL REFERENCES public.academic_programmes(code), alias varchar(150) NOT NULL, source_url text NOT NULL, PRIMARY KEY(programme_code,alias));')
$sql.Add('CREATE TABLE IF NOT EXISTS public.academic_historical_offerings (campus_name varchar(100) NOT NULL REFERENCES public.academic_campuses(name), programme_code varchar(12) NOT NULL REFERENCES public.academic_programmes(code), source_url text NOT NULL, source_checked_date date, PRIMARY KEY(campus_name,programme_code));')
$sql.Add('CREATE INDEX IF NOT EXISTS idx_academic_historical_offerings_code ON public.academic_historical_offerings(programme_code);')
$sql.Add('ALTER TABLE public.academic_campus_aliases ENABLE ROW LEVEL SECURITY;')
$sql.Add('ALTER TABLE public.academic_programme_aliases ENABLE ROW LEVEL SECURITY;')
$sql.Add('ALTER TABLE public.academic_historical_offerings ENABLE ROW LEVEL SECURITY;')
$sql.Add('REVOKE ALL ON public.academic_campus_aliases,public.academic_programme_aliases,public.academic_historical_offerings FROM PUBLIC,anon,authenticated;')
$sql.Add('DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname=''internpilih_app'') THEN GRANT SELECT ON public.academic_campus_aliases,public.academic_programme_aliases,public.academic_historical_offerings TO internpilih_app; END IF; IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname=''internpilih_backend'') THEN GRANT SELECT ON public.academic_campus_aliases,public.academic_programme_aliases,public.academic_historical_offerings TO internpilih_backend; END IF; END $$;')
foreach ($row in $campuses) {
  # The UiTM Facts page omits three satellite campuses listed in UiTM Selangor's 2026 profile.
  $campusSource = if ($row.campus -in @('Jalan Othman','Seksyen 17','Selayang')) { 'https://selangor.uitm.edu.my/images/2026/UCS%20PROFIL%20OGOS%202026%20-%20FA.pdf' } else { $row.source_url }
  $sql.Add("INSERT INTO public.academic_campuses(name,source_url,state) VALUES ($(Q $row.campus),$(Url $campusSource),$(Q $row.state)) ON CONFLICT(name) DO UPDATE SET state=COALESCE(public.academic_campuses.state,EXCLUDED.state);")
  foreach ($alias in $row.campus_aliases.Split(';')) { if ($alias.Trim()) { $sql.Add("INSERT INTO public.academic_campus_aliases(campus_name,alias,source_url) VALUES ($(Q $row.campus),$(Q $alias.Trim()),$(Url $campusSource)) ON CONFLICT DO NOTHING;") } }
}
foreach ($faculty in $facultyNames.Keys | Sort-Object) {
  $sql.Add("UPDATE public.academic_units SET display_name=$(Q $faculty) WHERE name=$(Q $facultyNames[$faculty]) AND display_name IS NULL;")
}
foreach ($row in $programmes) {
  $unit = $facultyNames[$row.faculty]
  $level = $row.study_level.ToLowerInvariant()
  $nameMs = if ($row.programme_name_ms) { Q $row.programme_name_ms } else { 'NULL' }
  $upu = if ($row.upu_code) { Q $row.upu_code } else { 'NULL' }
  $sql.Add("INSERT INTO public.academic_programmes(code,name,unit_name,level,source_url,display_name,name_ms,upu_code,catalogue_status) VALUES ($(Q $row.programme_code),$(Q $row.programme_name),$(Q $unit),$(Q $level),$(Url $row.source_url),$(Q $row.programme_name),$nameMs,$upu,$(Q $row.programme_status)) ON CONFLICT(code) DO UPDATE SET display_name=EXCLUDED.display_name,name_ms=COALESCE(EXCLUDED.name_ms,public.academic_programmes.name_ms),upu_code=COALESCE(EXCLUDED.upu_code,public.academic_programmes.upu_code),catalogue_status=EXCLUDED.catalogue_status;")
  foreach ($alias in @($row.programme_name,$row.programme_name_ms)) { if ($alias) { $sql.Add("INSERT INTO public.academic_programme_aliases(programme_code,alias,source_url) VALUES ($(Q $row.programme_code),$(Q $alias),$(Url $row.source_url)) ON CONFLICT DO NOTHING;") } }
}
foreach ($row in $offerings) {
  if ($row.campus_mapping_status -eq 'official_historical_mapping') {
    $sql.Add("INSERT INTO public.academic_historical_offerings(campus_name,programme_code,source_url,source_checked_date) VALUES ($(Q $row.campus),$(Q $row.programme_code),$(Url $row.source_url),$(Q $row.source_checked_date)) ON CONFLICT DO NOTHING;")
  } else {
    $sql.Add("INSERT INTO public.academic_offerings(campus_name,programme_code,source_url) VALUES ($(Q $row.campus),$(Q $row.programme_code),$(Url $row.source_url)) ON CONFLICT DO NOTHING;")
  }
}
$sql.Add('COMMIT;')
[System.IO.File]::WriteAllLines($OutputPath,$sql,[System.Text.UTF8Encoding]::new($false))
Write-Output "Generated $OutputPath from $($rows.Count) CSV rows: $($campuses.Count) campuses, $($programmes.Count) programmes, $($offerings.Count) offering rows."
