<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/database_access.php';
$root = '../'; $page_title = 'Edit Farm'; $active = 'farms';

$db = new Database_access((new Database())->connect());

$id  = (int) ($_GET['id'] ?? 0);
try {
    $row = $db->getFarm($id);
} catch (PDOException $e) {
    error_log('getFarm failed: ' . $e->getMessage());
    $row = null;
}
if (!$row) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Record not found.'];
    header("Location: index.php");
    exit;
}

// dropdown options come from the database: [id stored, label shown]
$options = [
    'farmer_id'          => $db->getFarmers(),
    'soil_type_id'       => $db->getSoilTypes(),
    'irrigation_type_id' => $db->getIrrigationTypes(),
];

$fields = [
    ['farmer_id', 'Farmer', 'select'],
    ['farm_name', 'Farm Name', 'text'],
    ['location', 'Location', 'text'],
    ['area_hectares', 'Area (hectares)', 'number'],
    ['soil_type_id', 'Soil Type', 'select'],
    ['irrigation_type_id', 'Irrigation Type', 'select'],
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
    foreach (['farm_name' => 100, 'location' => 150] as $name => $max) {
        if (!isset($errors[$name]) && strlen($data[$name]) > $max) {
            $errors[$name] = "This field must be $max characters or less.";
        }
    }
    foreach (['area_hectares' => 999999.99] as $name => $max) {
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

    if (!$errors) {
        try {
            $db->updateFarm(
                $id,
                (int) $data['farmer_id'],
                (int) $data['irrigation_type_id'],
                (int) $data['soil_type_id'],
                $data['farm_name'],
                $data['location'],
                $data['area_hectares'],
                (int) $_SESSION['user_id']          // updated_by (created_by is never changed)
            );
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Farm updated successfully.'];
            header("Location: index.php");
            exit;
        } catch (DataException $e) {
            // show the message under the right field, or at the top if it is about the whole form
            $target = in_array($e->getField(), array_column($fields, 0), true) ? $e->getField() : '_db';
            $errors[$target] = $e->getMessage();
        } catch (PDOException $e) {
            error_log('updateFarm failed: ' . $e->getMessage());   // details go to the server log only
            $errors['_db'] = 'The farm could not be saved. Please try again.';
        }
    }
}

include '../includes/header.php';
?>
<div class="max-w-2xl mx-auto">
    <div class="page-head"><h1 class="page-title">Edit Farm</h1></div>
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
            <button type="submit" class="btn btn-primary">Update Farm</button>
        </div>
    </form>
</div>
<?php include '../includes/footer.php'; ?>
