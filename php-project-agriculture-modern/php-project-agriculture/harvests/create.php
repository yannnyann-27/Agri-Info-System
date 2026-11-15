<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/database_access.php';
$root = '../'; $page_title = 'Add Harvest'; $active = 'harvests';

$db = new Database_access((new Database())->connect());

// dropdown options come from the database: [id stored, label shown]
$options = [
    'planting_id' => $db->getPlantings(),
    'grade_id'    => $db->getQualityGrades(),
    'remark_id'   => $db->getHarvestRemarks(),
];

$fields = [
    ['planting_id', 'Planting Record', 'select'],
    ['harvest_date', 'Harvest Date', 'date'],
    ['quantity_kg', 'Quantity (kg)', 'number'],
    ['selling_price_per_kg', 'Selling Price per kg', 'number'],
    ['grade_id', 'Quality Grade', 'select'],
    ['remark_id', 'Remarks', 'select'],
];
$errors = [];
$data   = $row ?? [];

// a real calendar date written as YYYY-MM-DD
$valid_date = fn($v) => ($d = DateTime::createFromFormat('Y-m-d', $v)) && $d->format('Y-m-d') === $v;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($fields as [$name, $label, $type]) {
        $value = (string) trim($_POST[$name] ?? '');
        $data[$name] = $value;

        if ($value === '') {
            $errors[$name] = "$label is required.";
        } elseif ($type === 'number' && (!is_numeric($value) || (float) $value <= 0)) {
            $errors[$name] = "$label must be a number greater than 0.";
        } elseif ($type === 'date' && !$valid_date($value)) {
            $errors[$name] = "$label must be a valid date.";
        }
    }

    // rules that match the database columns
    foreach ([] as $name => $max) {
        if (!isset($errors[$name]) && strlen($data[$name]) > $max) {
            $errors[$name] = "This field must be $max characters or less.";
        }
    }
    foreach (['quantity_kg' => 99999999.99, 'selling_price_per_kg' => 99999999.99] as $name => $max) {
        if (!isset($errors[$name]) && (float) $data[$name] > $max) {
            $errors[$name] = "This number is too large (maximum " . number_format($max, 2) . ").";
        }
    }
    foreach ($fields as [$name, $label, $type]) {
        // the posted value must be one of the real dropdown options
        if ($type === 'select' && !isset($errors[$name])
            && !in_array($data[$name], array_map('strval', array_column($options[$name], 0)), true)) {
            $errors[$name] = "Please choose a valid $label.";
        }
    }

    // logical rule (the database enforces the same rule with a trigger)
    if (!isset($errors['planting_id']) && !isset($errors['harvest_date'])) {
        try {
            $planting_date = $db->getPlantingDate((int) $data['planting_id']);
            if ($planting_date !== null && $data['harvest_date'] < $planting_date) {
                $errors['harvest_date'] = 'Harvest Date cannot be before the planting date (' . $planting_date . ') of the selected planting.';
            }
        } catch (PDOException $e) {
            error_log('getPlantingDate failed: ' . $e->getMessage());   // the database trigger still protects the data
        }
    }

    if (!$errors) {
        try {
            $db->createHarvest(
                (int) $data['planting_id'],
                (int) $data['grade_id'],
                (int) $data['remark_id'],
                $data['harvest_date'],
                $data['quantity_kg'],
                $data['selling_price_per_kg'],
                (int) $_SESSION['user_id']          // created_by and updated_by
            );
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Harvest added successfully.'];
            header("Location: index.php");
            exit;
        } catch (DataException $e) {
            // show the message under the right field, or at the top if it is about the whole form
            $target = in_array($e->getField(), array_column($fields, 0), true) ? $e->getField() : '_db';
            $errors[$target] = $e->getMessage();
        } catch (PDOException $e) {
            error_log('createHarvest failed: ' . $e->getMessage());   // details go to the server log only
            $errors['_db'] = 'The harvest could not be saved. Please try again.';
        }
    }
}

include '../includes/header.php';
?>
<div class="max-w-2xl mx-auto">
    <div class="page-head"><h1 class="page-title">Add Harvest</h1></div>
    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert"><?php icon('alert', 'w-5 h-5'); ?> <?= htmlspecialchars($errors['_db'] ?? 'Please fix the errors below.') ?></div>
    <?php endif; ?>

    <form method="POST" class="card card-pad grid sm:grid-cols-2 gap-x-4 gap-y-5">
        <?php foreach ($fields as [$name, $label, $type]): ?>
            <div>
                <label class="field-label"><?= $label ?></label>
                <?php if ($type === 'select'): ?>
                    <select name="<?= $name ?>" class="select <?= isset($errors[$name]) ? 'is-invalid' : '' ?>">
                        <option value="">-- Select <?= $label ?> --</option>
                        <?php foreach ($options[$name] as [$val, $text]): ?>
                            <option value="<?= htmlspecialchars($val) ?>" <?= (string) ($data[$name] ?? '') === (string) $val ? 'selected' : '' ?>><?= htmlspecialchars($text) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="<?= $type === 'number' ? 'number' : $type ?>" <?= $type === 'number' ? 'step="0.01" min="0"' : '' ?> name="<?= $name ?>" value="<?= htmlspecialchars($data[$name] ?? '') ?>" class="input <?= isset($errors[$name]) ? 'is-invalid' : '' ?>">
                <?php endif; ?>
                <?php if (isset($errors[$name])): ?><p class="field-error"><?php icon('alert', 'w-4 h-4'); ?> <?= htmlspecialchars($errors[$name]) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="sm:col-span-2 form-actions">
            <a href="index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Harvest</button>
        </div>
    </form>
</div>
<?php include '../includes/footer.php'; ?>