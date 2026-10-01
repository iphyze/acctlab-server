<?php
$root = dirname(__DIR__);
$create = file_get_contents($root . '/routes/letter-format/fx/create.php') ?: '';
$edit = file_get_contents($root . '/routes/letter-format/fx/edit.php') ?: '';

$checks = [
    'create_normalizes_multiline_content' => str_contains($create, 'str_replace(["\\r\\n", "\\r"], "\\n"')
        && str_contains($create, '$normalizeMultiline($data[\'letter_header\'])'),
    'edit_normalizes_multiline_content' => str_contains($edit, 'str_replace(["\\r\\n", "\\r"], "\\n"')
        && str_contains($edit, '$normalizeMultiline($data[\'letter_header\'])'),
    'create_canonicalizes_and_validates_bank_format' => str_contains($create, '$letter_format   = strtoupper')
        && str_contains($create, '$allowedLetterFormats = [\'ZBN\', \'REGULAR\', \'REGULAR LEM\', \'PVB\'];')
        && str_contains($create, 'preg_match(\'/^[A-Z]{3}$/\', $bank_code)'),
    'edit_canonicalizes_and_validates_bank_format' => str_contains($edit, '$letter_format   = strtoupper')
        && str_contains($edit, '$allowedLetterFormats = [\'ZBN\', \'REGULAR\', \'REGULAR LEM\', \'PVB\'];')
        && str_contains($edit, 'preg_match(\'/^[A-Z]{3}$/\', $bank_code)'),
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " $name\n";
    if (!$passed) $failed = true;
}
exit($failed ? 1 : 0);
