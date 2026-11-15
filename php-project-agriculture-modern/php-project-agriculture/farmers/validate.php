<?php
// =============================================================================
// farmers/validate.php  |  Form fields and validation shared by create.php and edit.php
// The rules match the database columns / constraints, so most mistakes are caught
// here with a clear message before the database is ever asked.
// =============================================================================

// [field name, label, input type]
function farmer_fields(): array {
    return [
        ['first_name', 'First Name', 'text'],
        ['last_name', 'Last Name', 'text'],
        ['contact_number', 'Contact Number', 'text'],
        ['address', 'Address', 'text'],
        ['date_registered', 'Date Registered', 'date'],
    ];
}

// Reads the posted values and checks them. $today is the DATABASE's current date
// (Database_access::getToday()), because that is what ck_farmer_registered compares with.
// Returns [$data, $errors]: $data holds the trimmed values (to refill the form),
// $errors holds one message per field that is wrong.
function validate_farmer(array $input, string $today): array {
    $data   = [];
    $errors = [];
    $max    = ['first_name' => 50, 'last_name' => 50, 'address' => 150];

    foreach (farmer_fields() as [$name, $label, $type]) {
        $value = trim((string) ($input[$name] ?? ''));
        $data[$name] = $value;

        if ($value === '') {
            $errors[$name] = "$label is required.";
            continue;
        }
        if (isset($max[$name]) && (function_exists("mb_strlen") ? mb_strlen($value) : strlen($value)) > $max[$name]) {
            $errors[$name] = "$label must be {$max[$name]} characters or less.";
            continue;
        }
        if ($name === 'contact_number' && !preg_match('/^[0-9+\-() ]{7,20}$/', $value)) {
            $errors[$name] = "$label may only contain digits, +, -, spaces and parentheses (7 to 20 characters).";
            continue;
        }
        if ($type === 'date') {
            $d = DateTime::createFromFormat('Y-m-d', $value);
            if (!$d || $d->format('Y-m-d') !== $value) {
                $errors[$name] = "$label must be a valid date.";
            } elseif ($value > $today) {
                $errors[$name] = "$label cannot be in the future.";
            }
        }
    }
    return [$data, $errors];
}
