<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/database_access.php';
require_once __DIR__ . '/validate.php';
$root = '../'; $page_title = 'Add Farmer'; $active = 'farmers';

$db = new Database_access((new Database())->connect());

$today  = $db->getToday();
$fields = farmer_fields();
$errors = [];
$data   = ['date_registered' => $today];     // sensible default for a new record

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$data, $errors] = validate_farmer($_POST, $today);

    if (!$errors) {
        try {
            $db->createFarmer(
                $data['first_name'],
                $data['last_name'],
                $data['contact_number'],
                $data['address'],
                $data['date_registered'],
                (int) $_SESSION['user_id']          // created_by and updated_by
            );
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Farmer added successfully.'];
            header("Location: index.php");
            exit;
        } catch (DataException $e) {
            // show the message under the right field, or at the top if it is about the whole form
            $target = in_array($e->getField(), array_column($fields, 0), true) ? $e->getField() : '_db';
            $errors[$target] = $e->getMessage();
        } catch (PDOException $e) {
            error_log('createFarmer failed: ' . $e->getMessage());   // details go to the server log only
            $errors['_db'] = 'The farmer could not be saved. Please try again.';
        }
    }
}

include '../includes/header.php';
?>
<div class="max-w-2xl mx-auto">
    <div class="page-head"><h1 class="page-title">Add Farmer</h1></div>
    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert"><?php icon('alert', 'w-5 h-5'); ?> <?= htmlspecialchars($errors['_db'] ?? 'Please fix the errors below.') ?></div>
    <?php endif; ?>

    <form method="POST" class="card card-pad grid sm:grid-cols-2 gap-x-4 gap-y-5">
        <?php foreach ($fields as [$name, $label, $type]): ?>
            <div>
                <label class="field-label"><?= $label ?></label>
                <input type="<?= $type ?>" <?= $type === 'date' ? 'max="' . $today . '"' : '' ?> name="<?= $name ?>" value="<?= htmlspecialchars($data[$name] ?? '') ?>" class="input <?= isset($errors[$name]) ? 'is-invalid' : '' ?>">
                <?php if (isset($errors[$name])): ?><p class="field-error"><?php icon('alert', 'w-4 h-4'); ?> <?= htmlspecialchars($errors[$name]) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="sm:col-span-2 form-actions">
            <a href="index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Farmer</button>
        </div>
    </form>
</div>
<?php include '../includes/footer.php'; ?>
