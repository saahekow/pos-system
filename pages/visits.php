<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('reports');
ensure_destination_visit_schema();

$pageTitle='Destination Visits';
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Reports','url'=>app_url('reports.php')],['label'=>'Destination Visits']];
$destinationId=max(0,(int)($_GET['destination_id']??0));
$q=trim((string)($_GET['q']??''));
$hasFilter=$destinationId>0||$q!=='';
$visits=[];
if($hasFilter){
    $sql="SELECT dv.id,dv.customer_id,dv.company_name,dv.owner_name,dv.phone,dv.area,dv.visit_type,dv.created_at,d.destination_name,l.region_name,l.town_name FROM destination_visits dv INNER JOIN destinations d ON d.id=dv.destination_id LEFT JOIN locations l ON l.id=dv.location_id WHERE dv.record_status='completed'";
    $params=[];
    if($destinationId){$sql.=' AND dv.destination_id=?';$params[]=$destinationId;}
    if($q!==''){$sql.=' AND (dv.company_name LIKE ? OR dv.owner_name LIKE ? OR dv.phone LIKE ? OR dv.area LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like,$like);}
    $sql.=' ORDER BY dv.created_at DESC,dv.id DESC';
    $s=db()->prepare($sql);$s->execute($params);$visits=$s->fetchAll();
}
$destinations=db()->query('SELECT id,destination_name FROM destinations WHERE is_active=1 ORDER BY destination_name')->fetchAll();
require_once __DIR__.'/../includes/header.php';
?>
<section class="management-panel management-panel--table">
    <div class="management-heading"><div><span class="section-kicker">Visits</span><h1>Destination Visits</h1><p>Every record is stored under its selected destination.</p></div><div class="management-icon"><i class="fa-solid fa-map-location-dot"></i></div></div>
    <form class="report-filter-form"><div class="report-filter-grid"><div class="form-field"><label for="destination_id">Destination</label><select id="destination_id" name="destination_id"><option value="">All destinations</option><?php foreach($destinations as $d):?><option value="<?=(int)$d['id']?>" <?=$destinationId===(int)$d['id']?'selected':''?>><?=e((string)$d['destination_name'])?></option><?php endforeach;?></select></div><div class="form-field"><label for="q">Search</label><input id="q" name="q" value="<?=e($q)?>"></div></div><div class="form-actions"><button class="login-button">Filter</button></div></form>
    <?php if(!$hasFilter):?><p class="empty-state">Search or choose a destination to view visits.</p><?php elseif(!$visits):?><p class="empty-state">No destination visits found.</p><?php else:?><div class="table-wrap"><table class="data-table"><thead><tr><th>Destination</th><th>Name</th><th>Contact</th><th>Location</th><th>Visit</th><th>POS Sales</th><th>Date</th></tr></thead><tbody><?php foreach($visits as $v):$sold=customer_has_completed_pos_sale((int)($v['customer_id']??0));?><tr class="customer-sales-row <?=$sold?'is-sold':'is-unsold'?>" data-customer-sales-row data-clickable-listing data-listing-url="<?=e(app_url('visit-details.php?id='.(int)$v['id']))?>"><td><?=e((string)$v['destination_name'])?></td><td><?=e((string)($v['company_name']??''))?></td><td><?=e((string)($v['owner_name']??''))?><span class="muted-text"><?=e((string)($v['phone']??''))?></span></td><td><?=e((string)($v['area']??''))?><span class="muted-text"><?=e(trim((string)($v['town_name']??'').', '.(string)($v['region_name']??''),', '))?></span></td><td><?=e(ucwords(str_replace('_',' ',(string)$v['visit_type'])))?></td><td><span class="status-badge <?=$sold?'is-success':'is-warning'?>"><?=$sold?'Yes':'No'?></span></td><td><?=e(date('d M Y',strtotime((string)$v['created_at'])))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</section>
<?php require_once __DIR__.'/../includes/footer.php';?>
