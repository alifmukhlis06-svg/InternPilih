<?php
// Server-only academic catalogue reader shared by registration and management screens.
function academicCatalogueData(): array {
    $campuses = db()->query('SELECT c.name,a.alias FROM academic_campuses c LEFT JOIN academic_campus_aliases a ON a.campus_name=c.name ORDER BY c.name,a.alias')->fetch_all();
    $campusOptions = [];
    foreach ($campuses as $campus) {
        $campusOptions[$campus['name']] ??= ['name' => $campus['name'], 'aliases' => []];
        if ($campus['alias'] !== null) $campusOptions[$campus['name']]['aliases'][] = $campus['alias'];
    }
    $unitRows = db()->query('SELECT name,display_name FROM academic_units')->fetch_all();
    $facultyAliases = [];
    foreach ($unitRows as $unit) $facultyAliases[$unit['name']] = $unit['display_name'] ? [$unit['display_name']] : [];
    $rows = db()->query('SELECT p.code,p.name,p.display_name,p.unit_name AS faculty,p.level,o.campus_name
        FROM academic_programmes p LEFT JOIN academic_offerings o ON o.programme_code=p.code
        ORDER BY p.unit_name,p.name,o.campus_name')->fetch_all();
    $programmes = [];
    foreach ($rows as $row) {
        $code = $row['code'];
        if (!isset($programmes[$code])) {
            $programmes[$code] = ['code' => $code, 'name' => $row['name'], 'display_name' => $row['display_name'],
                'faculty' => $row['faculty'], 'level' => $row['level'], 'campuses' => [], 'aliases' => []];
        }
        if ($row['campus_name'] !== null) $programmes[$code]['campuses'][] = $row['campus_name'];
    }
    $aliases = db()->query('SELECT programme_code,alias FROM academic_programme_aliases ORDER BY programme_code')->fetch_all();
    foreach ($aliases as $alias) if (isset($programmes[$alias['programme_code']])) $programmes[$alias['programme_code']]['aliases'][] = $alias['alias'];
    return ['campuses' => array_values($campusOptions), 'faculty_aliases' => $facultyAliases, 'programmes' => array_values($programmes)];
}
