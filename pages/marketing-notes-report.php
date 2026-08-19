<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
if(!can_access_menu_item('marketing_report_notes')){header('Location: '.app_url('marketing.php?view=reports'));exit;}
ensure_destination_visit_schema();
ensure_places_management_schema();

$search=trim((string)($_GET['q']??''));
$dateFrom=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['date_from']??''))?(string)$_GET['date_from']:'';
$dateTo=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['date_to']??''))?(string)$_GET['date_to']:'';
$conditions=["COALESCE(TRIM(n.note),'')<>''"];
$params=[];
if(current_user_role()==='vendor'){
    $vendor=current_vendor_profile();
    if(!$vendor){header('Location: '.app_url('marketing.php?view=reports'));exit;}
    $conditions[]='(n.vendor_id=? OR v.vendor_id=? OR n.recorded_by_user_id=?)';
    array_push($params,(int)$vendor['id'],(int)$vendor['id'],(int)current_user_id());
}
if($search!==''){
    $like='%'.$search.'%';
    $conditions[]='(n.note_ref LIKE ? OR n.note LIKE ? OR n.feedback LIKE ? OR c.customer_ref LIKE ? OR c.customer_name LIKE ? OR c.phone LIKE ? OR v.visit_ref LIKE ? OR p.bus_loc_ref LIKE ? OR p.business_name LIKE ? OR l.town_name LIKE ?)';
    array_push($params,$like,$like,$like,$like,$like,$like,$like,$like,$like,$like);
}
if($dateFrom!==''){$conditions[]='DATE(n.created_at)>=?';$params[]=$dateFrom;}
if($dateTo!==''){$conditions[]='DATE(n.created_at)<=?';$params[]=$dateTo;}
$sql="SELECT n.id,n.note_ref,n.note,n.feedback,n.created_at,n.visit_id,
             c.customer_ref,c.customer_name,c.phone,
             v.visit_ref,p.bus_loc_ref,p.business_name,p.area,l.town_name,l.region_name,
             u.full_name AS recorded_by
      FROM visit_notes n
      INNER JOIN visits v ON v.id=n.visit_id
      LEFT JOIN customers c ON c.id=n.customer_id
      LEFT JOIN business_locations p ON p.id=v.bus_loc_id
      LEFT JOIN locations l ON l.id=p.location_id
      LEFT JOIN users u ON u.id=n.recorded_by_user_id
      WHERE ".implode(' AND ',$conditions)."
      ORDER BY n.created_at DESC,n.id DESC";
$statement=db()->prepare($sql);$statement->execute($params);$notes=$statement->fetchAll();

$pageTitle='Marketing Notes';
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Marketing','url'=>app_url('marketing.php')],['label'=>'Reports','url'=>app_url('marketing.php?view=reports')],['label'=>'Notes']];
$internalBackUrl=app_url('marketing.php?view=reports');
require_once __DIR__ . '/../includes/header.php';
?>
<section class="management-panel management-panel--table">
    <div class="management-heading"><div><span class="section-kicker">Marketing Reports</span><h1>Notes</h1><p>Notes recorded against marketing customer visits.</p></div><div class="management-icon"><i class="fa-solid fa-note-sticky"></i></div></div>
    <form class="filter-bar marketing-notes-filter" method="get" data-marketing-notes-filter>
        <label class="form-field marketing-notes-filter__search"><span><i class="fa-solid fa-magnifying-glass"></i>Search notes</span><div><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?=e($search)?>" placeholder="Note, customer, location or reference"></div></label>
        <div class="date-range-row marketing-notes-filter__dates"><label class="form-field"><span><i class="fa-regular fa-calendar"></i>Date from</span><input type="date" name="date_from" value="<?=e($dateFrom)?>"></label><label class="form-field"><span><i class="fa-regular fa-calendar-check"></i>Date to</span><input type="date" name="date_to" value="<?=e($dateTo)?>"></label></div>
        <div class="marketing-notes-filter__actions"><button class="login-button" type="submit"><i class="fa-solid fa-filter"></i><span>Show Notes</span></button><a class="secondary-button" href="<?=e(app_url('marketing-notes-report.php'))?>" data-marketing-notes-clear><i class="fa-solid fa-rotate-left"></i><span>Clear</span></a></div>
    </form>
    <div data-marketing-notes-results aria-live="polite">
    <div class="marketing-notes-result-bar"><span><i class="fa-solid fa-note-sticky"></i></span><p><strong><?=number_format(count($notes))?></strong> note<?=count($notes)===1?'':'s'?> found</p></div>
    <?php if($notes):?><div class="table-wrap marketing-notes-table-wrap"><table class="data-table marketing-notes-table"><thead><tr><th>Date / Reference</th><th>Note</th><th>Customer</th><th>Location</th><th>Visit / Recorder</th><th>Action</th></tr></thead><tbody>
    <?php foreach($notes as $note):$returnUrl=app_url('marketing-notes-report.php?'.http_build_query(array_filter(['q'=>$search,'date_from'=>$dateFrom,'date_to'=>$dateTo],static fn($value):bool=>$value!=='')));?>
        <tr>
            <td data-label="Date / Reference"><div class="marketing-note-date"><span><i class="fa-regular fa-calendar"></i><?=e(date('d M Y',strtotime((string)$note['created_at'])))?></span><small><?=e(date('H:i',strtotime((string)$note['created_at'])))?></small><strong><?=e((string)$note['note_ref'])?></strong></div></td>
            <td data-label="Note"><div class="marketing-note-text"><p><?=nl2br(e((string)$note['note']))?></p><?php if(trim((string)$note['feedback'])!==''):?><span><i class="fa-regular fa-comment-dots"></i><b>Feedback:</b> <?=e((string)$note['feedback'])?></span><?php endif;?></div></td>
            <td data-label="Customer"><div class="marketing-note-entity"><span class="marketing-note-entity__icon"><i class="fa-solid fa-user"></i></span><span><strong><?=e((string)($note['customer_name']?:'Unknown customer'))?></strong><small><?=e(implode(' · ',array_filter([(string)$note['customer_ref'],(string)$note['phone']])))?></small></span></div></td>
            <td data-label="Location"><div class="marketing-note-entity"><span class="marketing-note-entity__icon"><i class="fa-solid fa-location-dot"></i></span><span><strong><?=e((string)($note['business_name']?:'Unknown location'))?></strong><small><?=e(implode(' · ',array_filter([(string)$note['bus_loc_ref'],(string)$note['town_name'],(string)$note['area']])))?></small></span></div></td>
            <td data-label="Visit / Recorder"><div class="marketing-note-visit"><strong><?=e((string)$note['visit_ref'])?></strong><small><i class="fa-regular fa-user"></i><?=e((string)($note['recorded_by']?:'Unknown'))?></small></div></td>
            <td data-label="Action"><a class="secondary-button secondary-button--small marketing-note-view" href="<?=e(app_url('normalized-visit-details.php?id='.(int)$note['visit_id'].'&return_to='.rawurlencode($returnUrl)))?>"><span>View</span><i class="fa-solid fa-arrow-right"></i></a></td>
        </tr>
    <?php endforeach;?></tbody></table></div><?php else:?><div class="marketing-notes-empty"><span><i class="fa-regular fa-note-sticky"></i></span><h2>No notes found</h2><p>No marketing notes match the selected search and date filters.</p></div><?php endif;?>
    </div>
</section>
<script>
(()=>{
    const form=document.querySelector('[data-marketing-notes-filter]');
    if(!form)return;
    const search=form.querySelector('input[type="search"]');
    const dates=[...form.querySelectorAll('input[type="date"]')];
    let timer=null,controller=null;
    const refresh=async()=>{
        controller?.abort();controller=new AbortController();
        const url=new URL(form.action||window.location.href,window.location.href);
        url.search=new URLSearchParams(new FormData(form)).toString();
        form.classList.add('is-loading');
        try{
            const response=await fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'},signal:controller.signal});
            if(!response.ok)throw new Error('Request failed');
            const html=await response.text();
            const documentCopy=new DOMParser().parseFromString(html,'text/html');
            const incoming=documentCopy.querySelector('[data-marketing-notes-results]');
            const current=document.querySelector('[data-marketing-notes-results]');
            if(incoming&&current){current.replaceWith(incoming);window.history.replaceState({},'',url);}
        }catch(error){if(error.name!=='AbortError')form.requestSubmit();}
        finally{form.classList.remove('is-loading');}
    };
    const queue=(delay)=>{window.clearTimeout(timer);timer=window.setTimeout(refresh,delay);};
    search?.addEventListener('input',()=>queue(350));
    dates.forEach(field=>field.addEventListener('change',()=>queue(80)));
    form.querySelector('[data-marketing-notes-clear]')?.addEventListener('click',event=>{event.preventDefault();if(search)search.value='';dates.forEach(field=>field.value='');refresh();});
    form.addEventListener('submit',event=>{event.preventDefault();refresh();});
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
