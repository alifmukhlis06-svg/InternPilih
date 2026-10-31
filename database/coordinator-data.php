<?php
/** One fictional coordinator per programme, ordered by the first student fixture. */
$names = [
    'Dr. Siti Norzahra binti Mahmud',
    'Dr. Hazim Farhan bin Zainal',
    'Dr. Nurul Izzah binti Rahman',
    'Dr. Amir Hakimi bin Ismail',
    'Dr. Farah Nabilah binti Idris',
    'Dr. Syafiq Danish bin Omar',
    'Dr. Aina Syuhada binti Hassan',
    'Dr. Luqman Harith bin Abdullah',
    'Dr. Fatin Aqilah binti Azman',
    'Dr. Rizwan Firdaus bin Jamal',
    'Dr. Siti Maryam binti Yusof',
    'Dr. Arif Izzuddin bin Kamarudin',
    'Dr. Nur Amalina binti Salleh',
    'Dr. Aiman Hakim bin Zulkifli',
    'Dr. Hana Farzana binti Mahmud',
    'Dr. Farhan Akmal bin Roslan',
    'Dr. Nabila Sofea binti Ibrahim',
    'Dr. Ikhwan Syahmi bin Rahim',
    'Dr. Nur Iman binti Fauzi',
    'Dr. Danial Fikri bin Latif',
    'Dr. Maisarah Balqis binti Khalid',
    'Dr. Muhammad Haziq bin Nasir',
    'Dr. Siti Alya binti Sulaiman',
    'Dr. Adam Firdaus bin Karim',
    'Dr. Nur Athirah binti Wahid',
];
$courses = [];
foreach (require __DIR__ . '/student-data.php' as $profile) {
    $courses[$profile[2]] = $profile[1];
}
if (count($courses) !== count($names)) {
    throw new RuntimeException('Coordinator names must match the number of distinct programmes.');
}
$coordinators = [];
$campuses = ['Arau', 'Shah Alam', 'Puncak Alam', 'Tapah', 'Jasin', 'Machang', 'Kuala Terengganu', 'Samarahan', 'Kota Kinabalu', 'Segamat'];
$index = 0;
foreach ($courses as $programme => $faculty) {
    $academicName = preg_replace('/^Dr\.?\s+/i', '', $names[$index]);
    $givenName = preg_split('/\s+(?:bin|binti)\s+/i', (string)$academicName)[0];
    $emailName = preg_replace('/[^a-z0-9]/', '', strtolower($givenName));
    $coordinators[$programme] = [
        $names[$index],
        $emailName . '@uitm.edu.my',
        $faculty,
        sprintf('%06d', 100001 + $index),
        $campuses[$index % count($campuses)],
    ];
    $index++;
}
return $coordinators;
