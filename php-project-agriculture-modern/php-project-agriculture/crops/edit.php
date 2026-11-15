<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/database_access.php';
$root = '../'; $page_title = 'Edit Crop'; $active = 'crops';

$db = new Database_access((new Database())->connect());

$id  = (int) ($_GET['id'] ?? 0);            // variety_id
try {
    $row = $db->getCrop($id);
} catch (PDOException $e) {
    error_log('getCrop failed: ' . $e->getMessage());
    $row = null;
}
if (!$row) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Record not found.'];
    header("Location: index.php");
    exit;
}

// dropdown options come from the lookup tables: [id stored, label shown]
$options = [
    'category_id' => $db->getCropCategories(),
    'season_id'   => $db->getSeasons(),
];

$fields = [
    ['crop_name', 'Crop Name', 'text'],
    ['variety', 'Variety', 'text'],
    ['category_id', 'Category', 'select'],
    ['growing_period_days', 'Growing Period (days)', 'number'],
    ['season_id', 'Recommended Season', 'select'],
];
$errors = [];
$data   = $row ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($fields as [$name, $label, $type]) {
        $value = (string) trim($_POST[$name] ?? '');
        $data[$name] = $value;

        if ($value === '') {
            $errors[$name] = "$label is required.";
        } elseif ($type === 'number' && (!is_numeric($value) || (float) $value <= 0)) {
            $errors[$name] = "$label must be a number greater than 0.";
        } elseif ($type === 'date' && !strtotime($value)) {
            $errors[$name] = "$label must be a valid date.";
        }
    }

    // rules that match the database columns / constraints
    foreach (['crop_name' => 'Crop Name', 'variety' => 'Variety'] as $name => $label) {
        if (!isset($errors[$name]) && strlen($data[$name]) > 80) {
            $errors[$name] = "$label must be 80 characters or less.";
        }
    }
    if (!isset($errors['growing_period_days'])
        && (!ctype_digit($data['growing_period_days']) || (int) $data['growing_period_days'] < 1 || (int) $data['growing_period_days'] > 3650)) {
        $errors['growing_period_days'] = "Growing Period (days) must be a whole number between 1 and 3650.";
    }
    foreach (['category_id' => 'Category', 'season_id' => 'Recommended Season'] as $name => $label) {
        // the posted value must be one of the real dropdown options
        if (!isset($errors[$name]) && !in_array($data[$name], array_map('strval', array_column($options[$name], 0)), true)) {
            $errors[$name] = "Please choose a valid $label.";
        }
    }

    if (!$errors) {
        try {
            $db->updateCrop(
                $id,
                $data['crop_name'],
                $data['variety'],
                (int) $data['category_id'],
                (int) $data['season_id'],
                (int) $data['growing_period_days'],
                (int) $_SESSION['user_id']          // updated_by (created_by is never changed)
            );
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Crop updated successfully.'];
            header("Location: index.php");
            exit;
        } catch (DataException $e) {
            // show the message under the right field, or at the top if it is about the whole form
            $target = in_array($e->getField(), array_column($fields, 0), true) ? $e->getField() : '_db';
            $errors[$target] = $e->getMessage();
        } catch (PDOException $e) {
            error_log('updateCrop failed: ' . $e->getMessage());   // details go to the server log only
            $errors['_db'] = 'The crop could not be updated. Please try again.';
        }
    }
}

include '../includes/header.php';
?>
<div class="max-w-2xl mx-auto">
    <div class="page-head"><h1 class="page-title">Edit Crop</h1></div>
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
                    <input type="<?= $type === 'number' ? 'number' : $type ?>" <?= $type === 'number' ? 'step="' . ($name === 'growing_period_days' ? '1' : '0.01') . '" min="0"' : '' ?> name="<?= $name ?>" value="<?= htmlspecialchars($data[$name] ?? '') ?>" class="input <?= isset($errors[$name]) ? 'is-invalid' : '' ?>">
                <?php endif; ?>
                <?php if (isset($errors[$name])): ?><p class="field-error"><?php icon('alert', 'w-4 h-4'); ?> <?= htmlspecialchars($errors[$name]) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="sm:col-span-2 form-actions">
            <a href="index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Update Crop</button>
        </div>
    </form>
</div>
<?php include '../includes/footer.php'; ?>
