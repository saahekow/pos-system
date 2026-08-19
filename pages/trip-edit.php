<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
ensure_destination_visit_schema();

$id=max(0,(int)($_GET['id']??$_POST['trip_id']??0));
$statement=db()->prepare('SELECT st.*,v.plate_number FROM sales_trips st LEFT JOIN vehicles v ON v.id=st.vehicle_id WHERE st.id=? LIMIT 1');
$statement->execute([$id]);$trip=$statement->fetch();
if(!$trip){http_response_code(404);exit('Trip not found.');}

$isAdmin=is_admin_user();
$isCreator=(int)($trip['recorded_by_user_id']??0)===current_user_id();
if(!$isAdmin&&!$isCreator){http_response_code(403);exit('Only an administrator or the person who started this trip can edit it.');}

$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=(string)($_POST['form_action']??'update_trip');
    if(!verify_csrf_token((string)($_POST['csrf_token']??''))){$error='Your session expired. Please try again.';}
    elseif($action==='delete_trip'){
        if(!$isAdmin){$error='Only an administrator can delete a trip.';}
        else{
            try{
                db()->beginTransaction();
                foreach(['destination_visits','retailer_visits','retailers','taxi_rank_visits','sales_trip_vendor_assignments','sales_trip_staff_assignments'] as $table){
                    if(db_table_exists($table)){db()->prepare("DELETE FROM `$table` WHERE sales_trip_id=?")->execute([$id]);}
                }
                db()->prepare('DELETE FROM sales_trips WHERE id=?')->execute([$id]);
                db()->commit();
                header('Location: '.app_url('activity-log.php?trip_deleted=1'));exit;
            }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error='The trip could not be deleted because related records still depend on it.';}
        }
    }else{
        $vehicleId=max(0,(int)($_POST['vehicle_id']??0));
        $tripDate=trim((string)($_POST['trip_date']??''));
        $startTime=trim((string)($_POST['journey_start_time']??''));
        $startKm=trim((string)($_POST['journey_start_kilometers']??''));
        $notes=trim((string)($_POST['notes']??''));
        $vehicleCheck=db()->prepare("SELECT COUNT(*) FROM vehicles WHERE id=? AND status='active'");$vehicleCheck->execute([$vehicleId]);
        $conflict=db()->prepare("SELECT trip_code FROM sales_trips WHERE vehicle_id=? AND status='in_progress' AND id<>? LIMIT 1");$conflict->execute([$vehicleId,$id]);$conflictCode=(string)($conflict->fetchColumn()?:'');
        if(!(int)$vehicleCheck->fetchColumn())$error='Select a valid active car number.';
        elseif($conflictCode!=='')$error='This car is already being used by active trip '.$conflictCode.'.';
        elseif(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$tripDate))$error='Select a valid trip date.';
        elseif(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$startTime))$error='Enter a valid journey start time.';
        elseif(!is_numeric($startKm)||(float)$startKm<0)$error='Enter a valid journey start kilometer reading.';
        else{
            db()->prepare('UPDATE sales_trips SET vehicle_id=?,trip_date=?,journey_start_time=?,journey_start_kilometers=?,notes=? WHERE id=?')
                ->execute([$vehicleId,$tripDate,$startTime,(float)$startKm,$notes!==''?$notes:null,$id]);
            header('Location: '.app_url('trip-edit.php?id='.$id.'&saved=1'));exit;
        }
        $trip['vehicle_id']=$vehicleId;$trip['trip_date']=$tripDate;$trip['journey_start_time']=$startTime;$trip['journey_start_kilometers']=$startKm;$trip['notes']=$notes;
    }
}

$vehicles=db()->query("SELECT id,plate_number FROM vehicles WHERE status='active' ORDER BY plate_number")->fetchAll();
$pageTitle='Edit Trip';$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Activity Log','url'=>app_url('activity-log.php')],['label'=>'Edit Trip']];
require_once __DIR__.'/../includes/header.php';
?>
<section class="management-panel"><div class="management-heading"><div><span class="section-kicker"><?=e((string)$trip['trip_code'])?></span><h1>Edit Trip</h1><p>Administrators and the person who started this trip can update its details.</p></div><div class="management-icon"><i class="fa-solid fa-route"></i></div></div>
<?php if(isset($_GET['saved'])):?><div class="profile-message is-success">Trip updated successfully.</div><?php endif;?>
<?php if($error!==''):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
<form id="tripUpdateForm" class="record-form" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="form_action" value="update_trip"><div class="form-grid">
<div class="form-field"><label for="vehicle_id">Car number</label><select id="vehicle_id" name="vehicle_id" required><option value="">Select car number</option><?php foreach($vehicles as $vehicle):?><option value="<?=(int)$vehicle['id']?>" <?=(int)$trip['vehicle_id']===(int)$vehicle['id']?'selected':''?>><?=e((string)$vehicle['plate_number'])?></option><?php endforeach;?></select></div>
<div class="form-field"><label for="trip_date">Trip date</label><input id="trip_date" name="trip_date" type="date" value="<?=e((string)$trip['trip_date'])?>" required></div>
<div class="form-field"><label for="journey_start_time">Journey start time</label><input id="journey_start_time" name="journey_start_time" value="<?=e(substr((string)$trip['journey_start_time'],0,5))?>" data-time-picker readonly required></div>
<div class="form-field"><label for="journey_start_kilometers">Start kilometers</label><input id="journey_start_kilometers" name="journey_start_kilometers" type="number" min="0" step="0.01" value="<?=e((string)$trip['journey_start_kilometers'])?>" required></div>
<div class="form-field form-field--wide"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="4"><?=e((string)($trip['notes']??''))?></textarea></div></div></form>
<div class="trip-edit-actions">
    <a class="secondary-button trip-edit-actions__back" href="<?=e(app_url('activity-log.php'))?>"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a>
    <div class="trip-edit-actions__primary">
        <?php if($isAdmin):?><form class="trip-edit-delete-form" method="post" data-confirm-title="Delete trip" data-confirm-message="This permanently deletes the trip and all records recorded under it."><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="form_action" value="delete_trip"><button class="danger-button" type="submit"><i class="fa-solid fa-trash"></i><span>Delete Trip</span></button></form><?php endif;?>
        <button class="login-button" type="submit" form="tripUpdateForm"><span>Update Trip</span><i class="fa-solid fa-floppy-disk"></i></button>
    </div>
</div>
</section>
<?php require_once __DIR__.'/../includes/footer.php';
