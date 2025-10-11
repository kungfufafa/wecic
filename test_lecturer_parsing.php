<?php

// Test lecturer info parsing
$testCases = [
    'Mohammad Umar Fakhrudin, M.Pd Kontak Dosen : 085726669510 Email Dosen : mohammad.fakhurdin@cic.ac.id',
    'Muhammad Hatta, M.Kom. Kontak Dosen : 085224651895 Email Dosen : muhammad.hatta@cic.ac.id',
    'Lena Magdalena, MMSI. Kontak Dosen : 08128737323 Email Dosen : lena.magdalena@cic.ac.id',
    'Rifqi Fahrudin, M.Kom Kontak Dosen : 082119976155 Email Dosen : -',
    'Willy Eka Septian, M.Kom. Kontak Dosen : 081268923447 Email Dosen : willy.eka.septian@cic.ac.id',
    'Mesi Febima., M.Kom. Kontak Dosen : - Email Dosen : -',
    'Dr. Budi Santoso, M.T.', // Clean name without contact info
];

function parseLecturerName($text)
{
    // Clean name from contact info and email details
    $nameText = preg_replace('/\s*Kontak Dosen\s*:.*$/', '', $text);
    $nameText = preg_replace('/\s*Email Dosen\s*:.*$/', '', $nameText);

    return trim($nameText);
}

function parseLecturerInfo($text)
{
    $lecturerInfo = [];

    // Extract name (clean from contact info)
    $lecturerInfo['name'] = parseLecturerName($text);

    // Extract contact information
    if (preg_match('/Kontak Dosen\s*:\s*([\d\s-]+)/', $text, $matches)) {
        $contact = trim($matches[1]);
        $lecturerInfo['contact'] = $contact !== '-' ? $contact : null;
    }

    // Extract email
    if (preg_match('/Email Dosen\s*:\s*([^\s<]+)/', $text, $matches)) {
        $email = trim($matches[1]);
        $lecturerInfo['email'] = $email !== '-' ? $email : null;
    }

    return $lecturerInfo;
}

echo "Testing Lecturer Info Parsing\n";
echo "==============================\n\n";

foreach ($testCases as $i => $test) {
    echo 'Test '.($i + 1).": '{$test}'\n";
    $result = parseLecturerInfo($test);

    echo "✅ Name: '{$result['name']}'\n";
    if (isset($result['contact'])) {
        echo "✅ Contact: '{$result['contact']}'\n";
    }
    if (isset($result['email'])) {
        echo "✅ Email: '{$result['email']}'\n";
    }

    echo "---\n";
}

echo "\nExpected Results:\n";
echo "Test 1: Name: 'Mohammad Umar Fakhrudin, M.Pd', Contact: '085726669510', Email: 'mohammad.fakhurdin@cic.ac.id'\n";
echo "Test 2: Name: 'Muhammad Hatta, M.Kom.', Contact: '085224651895', Email: 'muhammad.hatta@cic.ac.id'\n";
echo "Test 3: Name: 'Lena Magdalena, MMSI.', Contact: '08128737323', Email: 'lena.magdalena@cic.ac.id'\n";
echo "Test 4: Name: 'Rifqi Fahrudin, M.Kom', Contact: '082119976155', Email: null\n";
echo "Test 5: Name: 'Willy Eka Septian, M.Kom.', Contact: '081268923447', Email: 'willy.eka.septian@cic.ac.id'\n";
echo "Test 6: Name: 'Mesi Febima., M.Kom.', Contact: null, Email: null\n";
echo "Test 7: Name: 'Dr. Budi Santoso, M.T.', Contact: null, Email: null\n";
