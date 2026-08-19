<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
if (!can_access_menu_item('admin_reports')) {
    header('Location: '.app_url('admin.php'));
    exit;
}

$staffRecords=db()->query(
    'SELECT staff.id,staff.staff_code,staff.full_name,staff.profile_image,staff.email,staff.phone,staff.ghana_card_no,
            COALESCE(staff_roles.role_name,staff.position) AS role_name,staff.is_active,staff.created_at,users.full_name AS added_by
     FROM staff
     LEFT JOIN staff_roles ON staff_roles.id=staff.role_id
     LEFT JOIN users ON users.id=staff.added_by_user_id
     ORDER BY staff.full_name,staff.id'
)->fetchAll();
$pageTitle='Staff Report';
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Admin Reports','url'=>app_url('reports.php')],['label'=>'Staff']];
$internalBackUrl=requested_return_url(app_url('reports.php'));
$staffListUrl=app_url('staff-report.php?return_to='.rawurlencode($internalBackUrl));
$viewStaffId=max(0,(int)($_GET['view']??0));
$viewStaff=null;
foreach($staffRecords as $record){if((int)$record['id']===$viewStaffId){$viewStaff=$record;break;}}
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel management-panel--table">
    <div class="management-heading"><div><span class="section-kicker">Admin Reports</span><h1>Staff</h1><p>View all staff accounts, roles, contact details, and account status.</p></div><div class="management-icon"><i class="fa-solid fa-users"></i></div></div>
    <form class="filter-bar marketing-notes-filter" data-staff-report-filter>
        <label class="form-field marketing-notes-filter__search"><span><i class="fa-solid fa-magnifying-glass"></i>Search staff</span><div><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Name, staff number, role, email or phone" data-staff-search></div></label>
        <label class="form-field"><span><i class="fa-solid fa-toggle-on"></i>Status</span><select data-staff-status><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
    </form>
    <div class="marketing-notes-result-bar"><span><i class="fa-solid fa-id-card"></i></span><p><strong data-staff-count><?=number_format(count($staffRecords))?></strong> staff found</p></div>
    <?php if($staffRecords):?><div class="table-wrap marketing-notes-table-wrap"><table class="data-table marketing-notes-table"><thead><tr><th>Staff</th><th>Staff Number</th><th>Role</th><th>Contact</th><th>Status</th><th>Date Added</th></tr></thead><tbody>
    <?php foreach($staffRecords as $staff):$searchText=strtolower(implode(' ',[(string)$staff['full_name'],(string)$staff['staff_code'],(string)$staff['role_name'],(string)$staff['email'],(string)$staff['phone']]));?><tr data-staff-row data-clickable-listing data-listing-url="<?=e(app_url('staff-report.php?view='.(int)$staff['id'].'&return_to='.rawurlencode($internalBackUrl)))?>" data-search="<?=e($searchText)?>" data-status="<?=$staff['is_active']?'active':'inactive'?>">
        <td data-label="Staff"><div class="marketing-note-entity"><span class="table-avatar"><?php if($staff['profile_image']):?><img src="<?=e(app_url((string)$staff['profile_image']))?>" alt=""><?php else:?><i class="fa-solid fa-user"></i><?php endif;?></span><span><strong><?=e((string)$staff['full_name'])?></strong><small><?=e((string)($staff['email']?:'No email'))?></small></span></div></td>
        <td data-label="Staff Number"><strong><?=e((string)$staff['staff_code'])?></strong></td><td data-label="Role"><?=e((string)($staff['role_name']?:'Not assigned'))?></td>
        <td data-label="Contact"><strong><?=e((string)($staff['phone']?:'No phone'))?></strong><span class="muted-text"><?=e((string)$staff['email'])?></span></td>
        <td data-label="Status"><span class="status-badge <?=$staff['is_active']?'is-active':'is-inactive'?>"><?=$staff['is_active']?'Active':'Inactive'?></span></td><td data-label="Date Added"><?=e(date('d M Y',strtotime((string)$staff['created_at'])))?></td>
    </tr><?php endforeach;?></tbody></table></div><div class="marketing-notes-empty" data-staff-empty hidden><span><i class="fa-solid fa-users-slash"></i></span><h2>No staff found</h2><p>No staff match the selected filters.</p></div><?php else:?><p class="empty-state">No staff records are available.</p><?php endif;?>
</section>
<?php if($viewStaff):?>
<div class="modal-backdrop" role="presentation"><section class="staff-modal" role="dialog" aria-modal="true" aria-labelledby="staff-report-view-title">
    <div class="staff-modal__header"><div class="staff-modal__title"><span class="section-kicker">Staff Details</span><h2 id="staff-report-view-title"><?=e((string)$viewStaff['full_name'])?></h2><p><?=e((string)$viewStaff['staff_code'])?></p></div><div class="staff-modal__header-actions"><span class="status-badge <?=$viewStaff['is_active']?'is-active':'is-inactive'?>"><?=$viewStaff['is_active']?'Active':'Inactive'?></span><a class="modal-close" href="<?=e($staffListUrl)?>" aria-label="Close staff details"><i class="fa-solid fa-xmark"></i></a></div></div>
    <div class="staff-view-profile"><div class="profile-photo profile-photo--small staff-view-profile__photo"><?php if($viewStaff['profile_image']):?><img src="<?=e(app_url((string)$viewStaff['profile_image']))?>" alt=""><?php else:?><i class="fa-solid fa-user"></i><?php endif;?></div><div class="staff-view-profile__summary"><span>Assigned role</span><strong><?=e((string)($viewStaff['role_name']?:'No role assigned'))?></strong><div class="staff-view-profile__chips"><span><i class="fa-solid fa-phone"></i><?=e((string)($viewStaff['phone']?:'No phone'))?></span><span><i class="fa-solid fa-envelope"></i><?=e((string)($viewStaff['email']?:'No email'))?></span></div></div></div>
    <div class="staff-view-details"><div><i class="fa-solid fa-id-card"></i><span><span class="staff-view-details__label">Ghana Card</span><strong><?=e((string)($viewStaff['ghana_card_no']?:'Not provided'))?></strong></span></div><div><i class="fa-solid fa-calendar-plus"></i><span><span class="staff-view-details__label">Date added</span><strong><?=e(date('d M Y',strtotime((string)$viewStaff['created_at'])))?></strong></span></div><div class="staff-view-details__wide"><i class="fa-solid fa-user-check"></i><span><span class="staff-view-details__label">Added by</span><strong><?=e((string)($viewStaff['added_by']?:'System'))?></strong></span></div></div>
</section></div>
<?php endif;?>
<script>
(()=>{const form=document.querySelector('[data-staff-report-filter]');if(!form)return;const search=form.querySelector('[data-staff-search]'),status=form.querySelector('[data-staff-status]'),rows=[...document.querySelectorAll('[data-staff-row]')],count=document.querySelector('[data-staff-count]'),empty=document.querySelector('[data-staff-empty]');const filter=()=>{const q=search.value.trim().toLowerCase(),s=status.value;let shown=0;rows.forEach(row=>{const visible=(!q||row.dataset.search.includes(q))&&(!s||row.dataset.status===s);row.hidden=!visible;if(visible)shown++;});count.textContent=shown.toLocaleString();if(empty)empty.hidden=shown!==0;};search.addEventListener('input',filter);status.addEventListener('change',filter);form.addEventListener('submit',event=>event.preventDefault());})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
