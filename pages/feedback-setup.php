<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('admin');
ensure_destination_visit_schema();

$pageTitle = 'Feedback Setup';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Admin', 'url' => app_url('admin.php')],
    ['label' => 'Setup', 'url' => app_url('setup.php')],
    ['label' => 'Feedback Setup'],
];
$message = '';
$error = '';
$editFeedbackId = max(0, (int) ($_GET['edit'] ?? 0));
$feedbackLabel = '';
$status = '1';

if ($editFeedbackId > 0) {
    $editStatement = db()->prepare('SELECT id, feedback_label, is_active FROM visit_feedback_options WHERE id = ? LIMIT 1');
    $editStatement->execute([$editFeedbackId]);
    $editFeedback = $editStatement->fetch();

    if ($editFeedback) {
        $feedbackLabel = (string) $editFeedback['feedback_label'];
        $status = (string) (int) $editFeedback['is_active'];
    } else {
        $editFeedbackId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['form_action'] ?? 'save_feedback');
    $feedbackLabel = trim((string) ($_POST['feedback_label'] ?? ''));
    $status = (string) ($_POST['status'] ?? '1');
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!verify_csrf_token($token)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'delete_feedback') {
        $feedbackId = (int) ($_POST['feedback_id'] ?? 0);

        if ($feedbackId <= 0) {
            $error = 'Select a valid feedback option to delete.';
        } else {
            $statement = db()->prepare('DELETE FROM visit_feedback_options WHERE id = ?');
            $statement->execute([$feedbackId]);
            $message = 'Feedback option deleted successfully.';
            $feedbackLabel = '';
            $status = '1';
            $editFeedbackId = 0;
        }
    } elseif ($feedbackLabel === '') {
        $error = 'Feedback option is required.';
    } else {
        try {
            $postedFeedbackId = (int) ($_POST['feedback_id'] ?? 0);

            if ($postedFeedbackId > 0) {
                $statement = db()->prepare('UPDATE visit_feedback_options SET feedback_label = ?, is_active = ? WHERE id = ?');
                $statement->execute([$feedbackLabel, $status === '0' ? 0 : 1, $postedFeedbackId]);
                $message = 'Feedback option updated successfully.';
                $editFeedbackId = 0;
            } else {
                $statement = db()->prepare('INSERT INTO visit_feedback_options (feedback_label, is_active) VALUES (?, ?)');
                $statement->execute([$feedbackLabel, $status === '0' ? 0 : 1]);
                $message = 'Feedback option added successfully.';
            }

            $feedbackLabel = '';
            $status = '1';
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $error = 'That feedback option already exists.';
            } else {
                $error = 'The feedback option could not be saved.';
            }
        }
    }
}

$feedbackOptions = db()
    ->query('SELECT id, feedback_label, is_active, created_at FROM visit_feedback_options ORDER BY feedback_label')
    ->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel" aria-labelledby="feedback-setup-title">
    <div class="management-heading">
        <div>
            <span class="section-kicker">Setup</span>
            <h1 id="feedback-setup-title">Feedback Setup</h1>
            <p>Create the feedback list used during destination visits.</p>
        </div>
        <div class="management-icon" aria-hidden="true">
            <i class="fa-solid fa-message"></i>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="profile-message is-success" role="status"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="profile-message is-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form class="record-form" method="post" action="<?= e(app_url('feedback-setup.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form_action" value="save_feedback">
        <input type="hidden" name="feedback_id" value="<?= e((string) $editFeedbackId) ?>">

        <div class="form-grid">
            <div class="form-field">
                <label for="feedback_label">Feedback option</label>
                <input id="feedback_label" name="feedback_label" type="text" value="<?= e($feedbackLabel) ?>" required>
            </div>

            <div class="form-field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button class="login-button" type="submit">
                <span><?= $editFeedbackId > 0 ? 'Update feedback' : 'Save feedback' ?></span>
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
            </button>
            <?php if ($editFeedbackId > 0): ?>
                <a class="secondary-button" href="<?= e(app_url('feedback-setup.php')) ?>">Cancel edit</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="management-panel management-panel--table" aria-labelledby="feedback-list-title">
    <div class="management-heading management-heading--compact">
        <div>
            <span class="section-kicker">Lookup</span>
            <h2 id="feedback-list-title">Feedback Options</h2>
        </div>
    </div>

    <?php if (!$feedbackOptions): ?>
        <p class="empty-state">No feedback options available yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table data-table--compact">
                <thead>
                    <tr>
                        <th>Feedback option</th>
                        <th>Status</th>
                        <th>Date added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feedbackOptions as $feedbackOption): ?>
                        <tr>
                            <td><?= e((string) $feedbackOption['feedback_label']) ?></td>
                            <td>
                                <span class="status-badge <?= (int) $feedbackOption['is_active'] === 1 ? 'is-active' : 'is-inactive' ?>">
                                    <?= (int) $feedbackOption['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= e(date('d M Y', strtotime((string) $feedbackOption['created_at']))) ?></td>
                            <td>
                                <div class="table-actions">
                                    <a class="action-button" href="<?= e(app_url('feedback-setup.php?edit=' . (int) $feedbackOption['id'])) ?>">
                                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                        <span>Edit</span>
                                    </a>
                                    <form method="post" action="<?= e(app_url('feedback-setup.php')) ?>" data-confirm-title="Delete feedback option?" data-confirm-message="This will permanently remove this feedback option from the lookup list. Existing visit records will keep their saved feedback text.">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="form_action" value="delete_feedback">
                                        <input type="hidden" name="feedback_id" value="<?= e((string) $feedbackOption['id']) ?>">
                                        <button class="action-button is-danger" type="submit">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                            <span>Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
