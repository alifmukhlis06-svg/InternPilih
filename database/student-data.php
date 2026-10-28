<?php
/** Fictional student profiles keyed by matric number. */
$fixtureStudents = [
    '2025161705' => ['Alif Mukhlis bin Ahmad', 'Faculty of Computer and Mathematical Sciences', 'Bachelor of Computer Science with Honours (Netcentric Computing)', 3.67],
    '2025161708' => ['Nurul Aisyah binti Hassan', 'Faculty of Computer and Mathematical Sciences', 'Bachelor of Computer Science with Honours', 3.55],
    '2025161712' => ['Muhammad Faris bin Abdullah', 'Faculty of Computer and Mathematical Sciences', 'Bachelor of Computer Science with Honours', 3.40],
    '2026124101' => ['Aina Sofea binti Rahman', 'Faculty of Accountancy', 'Bachelor of Accountancy with Honours', 3.82],
    '2026124102' => ['Haziq Danish bin Zulkifli', 'Faculty of Business and Management', 'Bachelor of Business Administration with Honours (Marketing)', 3.46],
    '2026124103' => ['Nur Izzati binti Hadi', 'Faculty of Applied Sciences', 'Bachelor of Science with Honours (Applied Chemistry)', 3.71],
    '2026124104' => ['Amirul Hakim bin Roslan', 'Faculty of Civil Engineering', 'Bachelor of Civil Engineering with Honours', 3.38],
    '2026124105' => ['Siti Maryam binti Azman', 'Faculty of Electrical Engineering', 'Bachelor of Electrical Engineering with Honours', 3.66],
    '2026124106' => ['Muhammad Irfan bin Idris', 'Faculty of Mechanical Engineering', 'Bachelor of Mechanical Engineering with Honours', 3.21],
    '2026124107' => ['Nadia Farhana binti Ismail', 'Faculty of Chemical Engineering', 'Bachelor of Chemical Engineering with Honours', 3.73],
    '2026124108' => ['Syafiqah Nabilah binti Omar', 'Faculty of Built Environment', 'Bachelor of Quantity Surveying with Honours', 3.54],
    '2026124109' => ['Adam Firdaus bin Kamal', 'Faculty of Health Sciences', 'Bachelor of Medical Laboratory Technology with Honours', 3.59],
    '2026124110' => ['Nur Amalina binti Yusof', 'Faculty of Pharmacy', 'Bachelor of Pharmacy with Honours', 3.77],
    '2026124111' => ['Fatin Balqis binti Jamal', 'Faculty of Communication and Media Studies', 'Bachelor of Mass Communication with Honours (Public Relations)', 3.62],
    '2026124112' => ['Danish Iskandar bin Latif', 'Faculty of Art and Design', 'Bachelor of Graphic Design with Honours', 3.84],
    '2026124113' => ['Nurul Huda binti Kamarudin', 'Faculty of Education', 'Bachelor of Education with Honours (Teaching English as a Second Language)', 3.69],
    '2026124114' => ['Aiman Harith bin Salleh', 'Faculty of Law', 'Bachelor of Laws with Honours', 3.51],
    '2026124115' => ['Siti Alya binti Razak', 'Faculty of Information Science', 'Bachelor of Information Science with Honours (Information Management)', 3.58],
    '2026124116' => ['Farhan Izzuddin bin Zainal', 'Faculty of Hotel and Tourism Management', 'Bachelor of Hotel Management with Honours', 3.41],
    '2026124117' => ['Nur Adibah binti Fauzi', 'Faculty of Administrative Science and Policy Studies', 'Bachelor of Administrative Science with Honours', 3.74],
    '2026124118' => ['Rizwan Hakimi bin Nordin', 'Faculty of Plantation and Agrotechnology', 'Bachelor of Agrotechnology with Honours', 3.27],
    '2026124119' => ['Maisarah Qistina binti Ahmad', 'Faculty of Sports Science and Recreation', 'Bachelor of Sports Science with Honours', 3.48],
    '2026124120' => ['Arif Muhaimin bin Hashim', 'Faculty of Film, Theatre and Animation', 'Bachelor of Animation with Honours', 3.63],
    '2026124121' => ['Nurin Aqilah binti Mahmud', 'Faculty of Music', 'Bachelor of Music with Honours (Performance)', 3.79],
    '2026124122' => ['Muhammad Danish bin Fikri', 'Faculty of Computer and Mathematical Sciences', 'Bachelor of Computer Science with Honours', 3.91],
    '2026124123' => ['Hana Sofea binti Abdullah', 'Faculty of Dentistry', 'Bachelor of Dental Surgery', 3.72],
    '2026124124' => ['Nur Afiqah binti Zamri', 'Faculty of Medicine', 'Bachelor of Medicine and Bachelor of Surgery', 3.83],
];

// Two additional fictional students in every programme, so each course has a cohort.
$cohorts = [];
foreach ($fixtureStudents as $profile) {
    $cohorts[$profile[2]] = [$profile[1], $profile[2]];
}
$additionalNames = [
    'Hakim Danish bin Yusof', 'Nur Athirah binti Zainudin',
    'Syazwan Amir bin Basri', 'Siti Aisyah binti Khalid',
    'Ikhwan Firdaus bin Rahman', 'Nur Iman binti Zulkifli',
    'Afiq Haziq bin Jamal', 'Nabila Farhana binti Osman',
    'Muhammad Arif bin Saiful', 'Sofea Balqis binti Hassan',
    'Danial Hakimi bin Latif', 'Nurul Syafiqah binti Nasir',
    'Amir Danish bin Ismail', 'Maisarah Nabila binti Azman',
    'Faris Izzuddin bin Hamzah', 'Nur Amira binti Razak',
    'Adam Syahmi bin Kamarudin', 'Siti Nurin binti Fauzi',
    'Aiman Irfan bin Idris', 'Hana Izzati binti Abdullah',
    'Rizqi Harith bin Mahmud', 'Nur Farah binti Sulaiman',
    'Zikri Afiq bin Omar', 'Fatin Aqilah binti Roslan',
    'Luqman Hakim bin Ibrahim', 'Nur Adilah binti Kamal',
    'Haziq Firdaus bin Nordin', 'Siti Alya binti Hakim',
    'Syafiq Amir bin Azmi', 'Nurul Huda binti Sabri',
    'Farhan Danish bin Karim', 'Aina Qistina binti Rahim',
    'Irfan Hakimi bin Ghazali', 'Nur Izzah binti Salleh',
    'Muhammad Akmal bin Zainal', 'Siti Maryam binti Johari',
    'Arif Muhaimin bin Bakri', 'Nurin Balqis binti Sani',
    'Amirul Syahmi bin Ramli', 'Nur Amalina binti Harun',
    'Fikri Adam bin Wahid', 'Hana Farzana binti Musa',
    'Aiman Hafiz bin Ishak', 'Nur Afiqah binti Mokhtar',
    'Danial Izzuddin bin Zubir', 'Sofea Iman binti Zulkarnain',
    'Hakim Irfan bin Hamid', 'Nur Nadia binti Anuar',
    'Rizwan Amir bin Yassin', 'Fatin Syuhada binti Jamil',
];
$nameIndex = 0;
foreach ($cohorts as [$faculty, $programme]) {
    for ($place = 0; $place < 2; $place++) {
        $matric = (string)(2026124201 + $nameIndex);
        $fixtureStudents[$matric] = [$additionalNames[$nameIndex], $faculty, $programme, round(3.15 + (($nameIndex * 17) % 70) / 100, 2)];
        $nameIndex++;
    }
}
return $fixtureStudents;
