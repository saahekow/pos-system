<?php
require_once __DIR__ . '/../config/app.php';

require_module_access('admin');
ensure_destination_visit_schema();

$pageTitle = 'Destination Setup';
$breadcrumbs = [
    ['label' => 'Home', 'url' => app_url('index.php')],
    ['label' => 'Admin', 'url' => app_url('admin.php')],
    ['label' => 'Setup', 'url' => app_url('setup.php')],
    ['label' => 'Destination Setup'],
];
$message = '';
$error = '';
$editDestinationId = max(0, (int) ($_GET['edit'] ?? 0));
$destinationName = '';
$status = '1';

if ($editDestinationId > 0) {
    $editStatement = db()->prepare('SELECT id, destination_name, destination_key, is_active FROM destinations WHERE id = ? LIMIT 1');
    $editStatement->execute([$editDestinationId]);
    $editDestination = $editStatement->fetch();

    if ($editDestination) {
        $destinationName = (string) $editDestination['destination_name'];
        $status = (string) (int) $editDestination['is_active'];
    } else {
        $editDestinationId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['form_action'] ?? 'save_destination');
    $destinationName = trim((string) ($_POST['destination_name'] ?? ''));
    $status = (string) ($_POST['status'] ?? '1');
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!verify_csrf_token($token)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'delete_destination') {
        $destinationId = (int) ($_POST['destination_id'] ?? 0);
        $destinationStatement = db()->prepare('SELECT destination_key FROM destinations WHERE id = ? LIMIT 1');
        $destinationStatement->execute([$destinationId]);
        $destinationKey = (string) ($destinationStatement->fetchColumn() ?: '');

        if ($destinationId <= 0) {
            $error = 'Select a valid destination to delete.';
        } elseif ($destinationKey === taxi_rank_destination_key()) {
            $error = 'Taxi Rank is the default destination and cannot be deleted.';
        } else {
            try {
                $statement = db()->prepare('DELETE FROM destinations WHERE id = ?');
                $statement->execute([$destinationId]);
                $message = 'Destination deleted successfully.';
                $destinationName = '';
                $status = '1';
                $editDestinationId = 0;
            } catch (PDOException $exception) {
                $error = 'This destination is already linked to trip activity, so it cannot be deleted.';
            }
        }
    } elseif ($destinationName === '') {
        $error = 'Destination name is required.';
    } else {
        try {
            $postedDestinationId = (int) ($_POST['destination_id'] ?? 0);

            if ($postedDestinationId > 0) {
                $destinationStatement = db()->prepare('SELECT destination_key FROM destinations WHERE id = ? LIMIT 1');
                $destinationStatement->execute([$postedDestinationId]);
                $destinationKey = (string) ($destinationStatement->fetchColumn() ?: '');
                $isDefaultDestination = $destinationKey === taxi_rank_destination_key();

                if ($isDefaultDestination) {
                    $destinationName = 'Taxi Rank';
                }

                $statement = db()->prepare('UPDATE destinations SET destination_name = ?, is_active = ? WHERE id = ?');
                $statement->execute([
                    $destinationName,
                    $isDefaultDestination ? 1 : ($status === '0' ? 0 : 1),
                    $postedDestinationId,
                ]);
                $message = 'Destination updated successfully.';
                $editDestinationId = 0;
            } else {
                $statement = db()->prepare('INSERT INTO destinations (destination_name, is_active) VALUES (?, ?)');
                $statement->execute([$destinationName, $status === '0' ? 0 : 1]);
                $message = 'Destination added successfully.';
            }

            $destinationName = '';
            $status = '1';
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $error = 'That destination already exists.';
            } else {
                $error = 'The destination could not be saved.';
            }
        }
    }
}

$destinations = db()
    ->query('SELECT id, destination_name, destination_key, is_default, is_active, created_at FROM destinations ORDER BY is_default DESC, destination_name')
    ->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel" aria-labelledby="destination-setup-title">
    <div class="management-heading">
        <div>
            <span class="section-kicker">Setup</span>
            <h1 id="destination-setup-title">Destination Setup</h1>
            <p>Create the destination list used on Marketing Trip visit forms.</p>
        </div>
        <div class="management-icon" aria-hidden="true">
            <i class="fa-solid fa-map-location-dot"></i>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="profile-message is-success" role="status"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="profile-message is-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form class="record-form" method="post" action="<?= e(app_url('destination-setup.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form_action" value="save_destination">
        <input type="hidden" name="destination_id" value="<?= e((string) $editDestinationId) ?>">

        <div class="form-grid">
            <div class="form-field">
                <label for="destination_name">Destination</label>
                <input id="destination_name" name="destination_name" type="text" value="<?= e($destinationName) ?>" required>
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
                <span><?= $editDestinationId > 0 ? 'Update destination' : 'Save destination' ?></span>
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
            </button>
            <?php if ($editDestinationId > 0): ?>
                <a class="secondary-button" href="<?= e(app_url('destination-setup.php')) ?>">Cancel edit</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="management-panel management-panel--table" aria-labelledby="destination-list-title">
    <div class="management-heading management-heading--compact">
        <div>
            <span class="section-kicker">Lookup</span>
            <h2 id="destination-list-title">Destinations</h2>
        </div>
    </div>

    <?php if (!$destinations): ?>
        <p class="empty-state">No destinations available yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table data-table--compact">
                <thead>
                    <tr>
                        <th>Destination</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Date added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($destinations as $destination): ?>
                        <?php $isDefaultDestination = (string) ($destination['destination_key'] ?? '') === taxi_rank_destination_key(); ?>
                        <tr>
                            <td><?= e((string) $destination['destination_name']) ?></td>
                            <td>
                                <span class="status-badge <?= $isDefaultDestination ? 'is-warning' : 'is-active' ?>">
                                    <?= $isDefaultDestination ? 'Default' : 'Custom' ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?= (int) $destination['is_active'] === 1 ? 'is-active' : 'is-inactive' ?>">
                                    <?= (int) $destination['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= e(date('d M Y', strtotime((string) $destination['created_at']))) ?></td>
                            <td>
                                <div class="table-actions">
                                    <a class="action-button" href="<?= e(app_url('destination-setup.php?edit=' . (int) $destination['id'])) ?>">
                                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                        <span>Edit</span>
                                    </a>
                                    <?php if (!$isDefaultDestination): ?>
                                        <form method="post" action="<?= e(app_url('destination-setup.php')) ?>" data-confirm-title="Delete destination?" data-confirm-message="This will permanently remove this destination from the lookup list if it has not been used.">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="form_action" value="delete_destination">
                                            <input type="hidden" name="destination_id" value="<?= e((string) $destination['id']) ?>">
                                            <button class="action-button is-danger" type="submit">
                                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                                <span>Delete</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
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
