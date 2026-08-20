<?php
require_once __DIR__ . '/../config/app.php';
require_module_access('admin');
ensure_location_schema();

$pageTitle='Location Setup';
$breadcrumbs=[['label'=>'Home','url'=>app_url('index.php')],['label'=>'Setup','url'=>app_url('setup.php')],['label'=>'Location Setup']];
$internalBackUrl=requested_return_url(app_url('marketing.php?view=setup'));
$view=(string)($_GET['view']??'regions');
if(!in_array($view,['regions','mmdas','towns','all'],true))$view='regions';
$message=$error='';
$editId=max(0,(int)($_GET['edit']??0));

function location_setup_redirect(string $view,string $message=''): never
{
    $url=app_url('location-setup.php?view='.rawurlencode($view));
    if($message!=='')$url.='&message='.rawurlencode($message);
    header('Location: '.$url);exit;
}

function location_setup_next_code(string $column): int
{
    if(!in_array($column,['region_code','mmda_code'],true))throw new InvalidArgumentException('Invalid location code column.');
    return (int)db()->query("SELECT COALESCE(MAX(`$column`),0)+1 FROM locations")->fetchColumn();
}

function location_setup_bool(mixed $value,bool $default=true): int
{
    $value=strtolower(trim((string)$value));
    if($value==='')return $default?1:0;
    return in_array($value,['1','yes','true','active','y'],true)?1:0;
}

function location_setup_csv_rows(string $field='csv_file'): array
{
    if(!isset($_FILES[$field])||($_FILES[$field]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Choose a CSV file to upload.');
    $handle=fopen((string)$_FILES[$field]['tmp_name'],'rb');
    if(!$handle)throw new RuntimeException('The CSV file could not be opened.');
    $headers=fgetcsv($handle);
    if(!$headers){fclose($handle);throw new RuntimeException('The CSV file is empty.');}
    $headers=array_map(static fn($header)=>strtolower(trim((string)$header)), $headers);
    $rows=[];
    while(($values=fgetcsv($handle))!==false){
        if(!array_filter($values,static fn($value)=>trim((string)$value)!==''))continue;
        $values=array_pad($values,count($headers),'');
        $rows[]=array_combine($headers,array_slice($values,0,count($headers)));
    }
    fclose($handle);
    return $rows;
}

function location_setup_sync_parents(): void
{
    db()->exec("INSERT INTO locations(entry_type,region_code,region_name,mmda_code,mmda_name,town_name,is_capital,is_active)
        SELECT 'region',source.region_code,source.region_name,NULL,NULL,NULL,0,MAX(source.is_active)
        FROM locations source
        WHERE source.entry_type='town' AND NOT EXISTS(SELECT 1 FROM locations parent WHERE parent.entry_type='region' AND parent.region_code=source.region_code)
        GROUP BY source.region_code,source.region_name");
    db()->exec("INSERT INTO locations(entry_type,region_code,region_name,mmda_code,mmda_name,town_name,is_capital,is_active)
        SELECT 'mmda',source.region_code,source.region_name,source.mmda_code,source.mmda_name,NULL,0,MAX(source.is_active)
        FROM locations source
        WHERE source.entry_type='town' AND source.mmda_code IS NOT NULL
          AND NOT EXISTS(SELECT 1 FROM locations parent WHERE parent.entry_type='mmda' AND parent.region_code=source.region_code AND parent.mmda_code=source.mmda_code)
        GROUP BY source.region_code,source.region_name,source.mmda_code,source.mmda_name");
}

location_setup_sync_parents();

if(isset($_GET['template'])){
    $template=(string)$_GET['template'];
    $headers=[
        'regions'=>['region_name','region_code','is_active'],
        'mmdas'=>['region_code','mmda_name','mmda_code','is_active'],
        'towns'=>['region_code','mmda_code','town_name','is_capital','is_active'],
        'all'=>['region_name','region_code','mmda_name','mmda_code','town_name','is_capital','is_active'],
    ][$template]??null;
    if(!$headers){http_response_code(404);exit('Template not found.');}
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="location-'.$template.'-template.csv"');
    $output=fopen('php://output','wb');fputcsv($output,$headers);fclose($output);exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf_token((string)($_POST['csrf_token']??'')))$error='Your session expired. Please try again.';
    else{
        $action=(string)($_POST['form_action']??'save');
        $recordId=max(0,(int)($_POST['record_id']??0));
        try{
            if($action==='save_region'){
                $name=trim((string)($_POST['region_name']??''));
                if($name==='')throw new RuntimeException('Enter the region name.');
                if($recordId){
                    $old=db()->prepare("SELECT region_code FROM locations WHERE id=? AND entry_type='region'");$old->execute([$recordId]);$code=(int)$old->fetchColumn();
                    if(!$code)throw new RuntimeException('Region not found.');
                    db()->prepare('UPDATE locations SET region_name=? WHERE region_code=?')->execute([$name,$code]);
                }else{
                    $code=location_setup_next_code('region_code');
                    db()->prepare("INSERT INTO locations(entry_type,region_code,region_name,is_active) VALUES('region',?,?,1)")->execute([$code,$name]);
                }
                location_setup_redirect('regions','Region saved successfully.');
            }
            if($action==='save_mmda'){
                $regionCode=max(0,(int)($_POST['region_code']??0));$name=trim((string)($_POST['mmda_name']??''));
                $region=db()->prepare("SELECT region_name FROM locations WHERE entry_type='region' AND region_code=? LIMIT 1");$region->execute([$regionCode]);$regionName=(string)($region->fetchColumn()?:'');
                if(!$regionCode||$regionName===''||$name==='')throw new RuntimeException('Select a region and enter the MMDA name.');
                if($recordId){
                    $old=db()->prepare("SELECT region_code,mmda_code FROM locations WHERE id=? AND entry_type='mmda'");$old->execute([$recordId]);$old=$old->fetch();
                    if(!$old)throw new RuntimeException('MMDA not found.');
                    db()->prepare('UPDATE locations SET region_code=?,region_name=?,mmda_name=? WHERE region_code=? AND mmda_code=?')->execute([$regionCode,$regionName,$name,$old['region_code'],$old['mmda_code']]);
                }else{
                    $code=location_setup_next_code('mmda_code');
                    db()->prepare("INSERT INTO locations(entry_type,region_code,region_name,mmda_code,mmda_name,is_active) VALUES('mmda',?,?,?,?,1)")->execute([$regionCode,$regionName,$code,$name]);
                }
                location_setup_redirect('mmdas','MMDA saved successfully.');
            }
            if($action==='save_town'){
                $mmdaId=max(0,(int)($_POST['mmda_id']??0));$town=trim((string)($_POST['town_name']??''));
                $parent=db()->prepare("SELECT region_code,region_name,mmda_code,mmda_name FROM locations WHERE id=? AND entry_type='mmda'");$parent->execute([$mmdaId]);$parent=$parent->fetch();
                if(!$parent||$town==='')throw new RuntimeException('Select an MMDA and enter the town name.');
                $capital=isset($_POST['is_capital'])?1:0;$active=isset($_POST['is_active'])?1:0;
                if($recordId)db()->prepare("UPDATE locations SET region_code=?,region_name=?,mmda_code=?,mmda_name=?,town_name=?,is_capital=?,is_active=? WHERE id=? AND entry_type='town'")->execute([$parent['region_code'],$parent['region_name'],$parent['mmda_code'],$parent['mmda_name'],$town,$capital,$active,$recordId]);
                else db()->prepare("INSERT INTO locations(entry_type,region_code,region_name,mmda_code,mmda_name,town_name,is_capital,is_active) VALUES('town',?,?,?,?,?,?,?)")->execute([$parent['region_code'],$parent['region_name'],$parent['mmda_code'],$parent['mmda_name'],$town,$capital,$active]);
                location_setup_redirect('towns','Town saved successfully.');
            }
            if($action==='toggle'){
                $level=(string)($_POST['level']??'town');
                $record=db()->prepare('SELECT * FROM locations WHERE id=? AND entry_type=?');$record->execute([$recordId,$level]);$record=$record->fetch();
                if(!$record)throw new RuntimeException('Location record not found.');
                $newStatus=(int)$record['is_active']===1?0:1;
                if($level==='region')db()->prepare('UPDATE locations SET is_active=? WHERE region_code=?')->execute([$newStatus,$record['region_code']]);
                elseif($level==='mmda')db()->prepare('UPDATE locations SET is_active=? WHERE region_code=? AND mmda_code=?')->execute([$newStatus,$record['region_code'],$record['mmda_code']]);
                else db()->prepare("UPDATE locations SET is_active=? WHERE id=? AND entry_type='town'")->execute([$newStatus,$recordId]);
                location_setup_redirect($view,'Status updated successfully.');
            }
            if($action==='csv_import'){
                $level=(string)($_POST['level']??$view);$rows=location_setup_csv_rows();$count=0;
                db()->beginTransaction();
                foreach($rows as $row){
                    if($level==='regions'){
                        $name=trim((string)($row['region_name']??''));if($name==='')throw new RuntimeException('Every Region CSV row requires region_name.');
                        $code=max(0,(int)($row['region_code']??0))?:location_setup_next_code('region_code');
                        $existing=db()->prepare("SELECT id FROM locations WHERE entry_type='region' AND region_code=?");$existing->execute([$code]);$id=(int)$existing->fetchColumn();
                        if($id)db()->prepare('UPDATE locations SET region_name=?,is_active=? WHERE region_code=?')->execute([$name,location_setup_bool($row['is_active']??'',true),$code]);
                        else db()->prepare("INSERT INTO locations(entry_type,region_code,region_name,is_active) VALUES('region',?,?,?)")->execute([$code,$name,location_setup_bool($row['is_active']??'',true)]);
                    }elseif($level==='mmdas'){
                        $regionCode=max(0,(int)($row['region_code']??0));$name=trim((string)($row['mmda_name']??''));
                        $parent=db()->prepare("SELECT region_name FROM locations WHERE entry_type='region' AND region_code=?");$parent->execute([$regionCode]);$regionName=(string)($parent->fetchColumn()?:'');
                        if(!$regionCode||$regionName===''||$name==='')throw new RuntimeException('Every MMDA CSV row requires a valid region_code and mmda_name.');
                        $code=max(0,(int)($row['mmda_code']??0))?:location_setup_next_code('mmda_code');
                        $existing=db()->prepare("SELECT id FROM locations WHERE entry_type='mmda' AND region_code=? AND mmda_code=?");$existing->execute([$regionCode,$code]);
                        if($existing->fetchColumn())db()->prepare('UPDATE locations SET mmda_name=?,is_active=? WHERE region_code=? AND mmda_code=?')->execute([$name,location_setup_bool($row['is_active']??'',true),$regionCode,$code]);
                        else db()->prepare("INSERT INTO locations(entry_type,region_code,region_name,mmda_code,mmda_name,is_active) VALUES('mmda',?,?,?,?,?)")->execute([$regionCode,$regionName,$code,$name,location_setup_bool($row['is_active']??'',true)]);
                    }else{
                        $regionCode=max(0,(int)($row['region_code']??0));$mmdaCode=max(0,(int)($row['mmda_code']??0));$town=trim((string)($row['town_name']??''));
                        $parent=db()->prepare("SELECT region_name,mmda_name FROM locations WHERE entry_type='mmda' AND region_code=? AND mmda_code=?");$parent->execute([$regionCode,$mmdaCode]);$parent=$parent->fetch();
                        if(!$parent&&$level==='all'){
                            $regionName=trim((string)($row['region_name']??''));$mmdaName=trim((string)($row['mmda_name']??''));
                            if($regionName!==''&&$mmdaName!=='')$parent=['region_name'=>$regionName,'mmda_name'=>$mmdaName];
                        }
                        if(!$parent||$town==='')throw new RuntimeException('Every Town CSV row requires valid Region/MMDA codes and town_name.');
                        db()->prepare("INSERT INTO locations(entry_type,region_code,region_name,mmda_code,mmda_name,town_name,is_capital,is_active) VALUES('town',?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE region_name=VALUES(region_name),mmda_name=VALUES(mmda_name),is_capital=VALUES(is_capital),is_active=VALUES(is_active)")->execute([$regionCode,$parent['region_name'],$mmdaCode,$parent['mmda_name'],$town,location_setup_bool($row['is_capital']??'',false),location_setup_bool($row['is_active']??'',true)]);
                    }
                    $count++;
                }
                db()->commit();location_setup_sync_parents();location_setup_redirect($level,"$count CSV row(s) imported successfully.");
            }
        }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error=$exception instanceof PDOException&&$exception->getCode()==='23000'?'That location already exists.':$exception->getMessage();}
    }
}

if(isset($_GET['message']))$message=trim((string)$_GET['message']);
$regions=db()->query("SELECT * FROM locations WHERE entry_type='region' ORDER BY region_name")->fetchAll();
$mmdas=db()->query("SELECT * FROM locations WHERE entry_type='mmda' ORDER BY region_name,mmda_name")->fetchAll();
$towns=db()->query("SELECT * FROM locations WHERE entry_type='town' ORDER BY town_name,region_name,mmda_name")->fetchAll();
$editing=null;
if($editId){$statement=db()->prepare('SELECT * FROM locations WHERE id=?');$statement->execute([$editId]);$editing=$statement->fetch()?:null;}
require_once __DIR__.'/../includes/header.php';
?>
<section class="management-panel">
 <div class="management-heading"><div><span class="section-kicker">Setup</span><h1>Location Setup</h1><p>Manage Regions, MMDAs and Towns in the unified location directory.</p></div><div class="management-icon"><i class="fa-solid fa-map-location-dot"></i></div></div>
 <?php if($message):?><div class="profile-message is-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="profile-message is-error"><?=e($error)?></div><?php endif;?>
 <nav class="report-subnav location-setup-tabs"><?php foreach(['regions'=>'Regions','mmdas'=>'MMDAs','towns'=>'Towns','all'=>'All Locations'] as $key=>$label):?><a class="secondary-button <?=$view===$key?'is-active':''?>" href="<?=e(app_url('location-setup.php?view='.$key))?>"><?=e($label)?></a><?php endforeach;?></nav>
</section>

<?php if($view!=='all'):?>
<section class="management-panel location-entry-panel">
 <div class="management-heading management-heading--compact"><div><span class="section-kicker"><?=$editing?'Edit':'Add'?></span><h2><?=$view==='regions'?'Region':($view==='mmdas'?'MMDA':'Town')?></h2><p>Codes are generated automatically.</p></div></div>
 <form class="record-form" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_id" value="<?=(int)($editing['id']??0)?>"><input type="hidden" name="form_action" value="<?=$view==='regions'?'save_region':($view==='mmdas'?'save_mmda':'save_town')?>">
  <div class="form-grid">
   <?php if($view==='regions'):?><div class="form-field"><label>Region name</label><input name="region_name" value="<?=e((string)($editing['region_name']??''))?>" required></div><?php endif;?>
   <?php if($view==='mmdas'):?><div class="form-field"><label>Region</label><select name="region_code" required><option value="">Select region</option><?php foreach($regions as $region):?><option value="<?=(int)$region['region_code']?>" <?=(int)($editing['region_code']??0)===(int)$region['region_code']?'selected':''?>><?=e((string)$region['region_name'])?></option><?php endforeach;?></select></div><div class="form-field"><label>MMDA name</label><input name="mmda_name" value="<?=e((string)($editing['mmda_name']??''))?>" required></div><?php endif;?>
   <?php if($view==='towns'):?><div class="form-field"><label>Region</label><select data-location-setup-region required><option value="">Select region</option><?php foreach($regions as $region):?><option value="<?=(int)$region['region_code']?>" <?=(int)($editing['region_code']??0)===(int)$region['region_code']?'selected':''?>><?=e((string)$region['region_name'])?></option><?php endforeach;?></select></div><div class="form-field"><label>MMDA</label><select name="mmda_id" data-location-setup-mmda required><option value="">Select MMDA</option><?php foreach($mmdas as $mmda):?><option value="<?=(int)$mmda['id']?>" data-region-code="<?=(int)$mmda['region_code']?>" <?=(int)($editing['mmda_code']??0)===(int)$mmda['mmda_code']?'selected':''?>><?=e((string)$mmda['mmda_name'])?></option><?php endforeach;?></select></div><div class="form-field"><label>Town name</label><input name="town_name" value="<?=e((string)($editing['town_name']??''))?>" required></div><div class="form-field location-toggle-field"><label class="location-boolean-toggle"><span>Capital town</span><input type="checkbox" name="is_capital" value="1" <?=(int)($editing['is_capital']??0)===1?'checked':''?>><i aria-hidden="true"></i></label></div><div class="form-field location-toggle-field"><label class="location-boolean-toggle"><span>Active</span><input type="checkbox" name="is_active" value="1" <?=$editing?((int)$editing['is_active']===1?'checked':''):'checked'?>><i aria-hidden="true"></i></label></div><?php endif;?>
  </div><div class="form-actions"><button class="login-button"><span>Save</span></button><?php if($editing):?><a class="secondary-button" href="<?=e(app_url('location-setup.php?view='.$view))?>">Cancel</a><?php endif;?></div>
 </form>
</section>
<?php endif;?>

<section class="management-panel location-import-panel">
 <div class="management-heading management-heading--compact"><div><span class="section-kicker">CSV Import</span><h2>Upload <?=$view==='all'?'combined locations':ucfirst($view)?></h2><p>Download the template, complete it, then upload the CSV.</p></div></div>
 <form class="record-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="form_action" value="csv_import"><input type="hidden" name="level" value="<?=e($view)?>"><div class="form-grid"><div class="form-field"><label>CSV file</label><input type="file" name="csv_file" accept=".csv,text/csv" required></div></div><div class="form-actions"><button class="login-button"><span>Upload</span></button><a class="secondary-button" href="<?=e(app_url('location-setup.php?template='.$view))?>"><span>Template</span></a></div></form>
</section>

<section class="management-panel management-panel--table location-directory" data-location-directory>
 <div class="management-heading management-heading--compact"><div><span class="section-kicker">Directory</span><h2><?=number_format($view==='regions'?count($regions):($view==='mmdas'?count($mmdas):count($towns)))?> <?=$view==='all'?'locations':$view?></h2></div></div>
 <div class="location-directory-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><label class="sr-only" for="location_directory_search">Search locations</label><input id="location_directory_search" type="search" placeholder="Search <?=e($view==='all'?'all locations':$view)?>..." autocomplete="off" data-location-directory-search><span data-location-directory-count></span></div>
 <p class="empty-state is-hidden" data-location-directory-empty>No matching records found.</p>
 <div class="table-wrap location-directory-scroll" data-location-directory-scroll><table class="data-table"><thead><tr><?php if($view==='regions'):?><th>Region</th><th>Code</th><?php elseif($view==='mmdas'):?><th>Region</th><th>MMDA</th><th>Code</th><?php else:?><th>Region</th><th>MMDA</th><th>Town</th><th>Capital</th><?php endif;?><th>Status</th><th>Actions</th></tr></thead><tbody>
 <?php $rows=$view==='regions'?$regions:($view==='mmdas'?$mmdas:$towns);foreach($rows as $row):?><tr data-location-directory-row data-search="<?=e(strtolower(implode(' ',array_filter([(string)$row['region_name'],(string)($row['region_code']??''),(string)($row['mmda_name']??''),(string)($row['mmda_code']??''),(string)($row['town_name']??''),(int)$row['is_active']===1?'active':'inactive',(int)($row['is_capital']??0)===1?'capital':'']))))?>"><?php if($view==='regions'):?><td><strong><?=e((string)$row['region_name'])?></strong></td><td><?=e((string)$row['region_code'])?></td><?php elseif($view==='mmdas'):?><td><?=e((string)$row['region_name'])?></td><td><strong><?=e((string)$row['mmda_name'])?></strong></td><td><?=e((string)$row['mmda_code'])?></td><?php else:?><td><?=e((string)$row['region_name'])?></td><td><?=e((string)$row['mmda_name'])?></td><td><?=town_name_html((string)$row['town_name'],$row['is_capital'])?></td><td><?=(int)$row['is_capital']?'Yes':'No'?></td><?php endif;?><td><span class="status-badge <?=(int)$row['is_active']?'is-active':'is-inactive'?>"><?=(int)$row['is_active']?'Active':'Inactive'?></span></td><td><div class="location-row-actions"><a class="location-row-action location-row-action--edit" href="<?=e(app_url('location-setup.php?view='.($view==='all'?'towns':$view).'&edit='.(int)$row['id']))?>"><i class="fa-solid fa-pen"></i><span>Edit</span></a><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_id" value="<?=(int)$row['id']?>"><input type="hidden" name="form_action" value="toggle"><input type="hidden" name="level" value="<?=e((string)$row['entry_type'])?>"><button class="location-row-action <?=(int)$row['is_active']?'location-row-action--disable':'location-row-action--enable'?>"><i class="fa-solid <?=(int)$row['is_active']?'fa-ban':'fa-check'?>"></i><span><?=(int)$row['is_active']?'Disable':'Enable'?></span></button></form></div></td></tr><?php endforeach;?>
 </tbody></table></div>
</section>
<script>
document.querySelectorAll('[data-location-setup-region]').forEach((region)=>{const form=region.closest('form');const mmda=form?.querySelector('[data-location-setup-mmda]');if(!mmda)return;const options=Array.from(mmda.options);const sync=(clear=false)=>{options.forEach((option)=>{if(!option.value)return;const match=option.dataset.regionCode===region.value;option.hidden=!match;option.disabled=!match;});if(clear&&mmda.selectedOptions[0]?.disabled)mmda.value='';};region.addEventListener('change',()=>sync(true));sync(false);});
document.querySelectorAll('[data-location-directory]').forEach((directory)=>{const search=directory.querySelector('[data-location-directory-search]');const rows=Array.from(directory.querySelectorAll('[data-location-directory-row]'));const count=directory.querySelector('[data-location-directory-count]');const empty=directory.querySelector('[data-location-directory-empty]');const scroll=directory.querySelector('[data-location-directory-scroll]');const filter=()=>{const terms=(search?.value||'').toLowerCase().trim().split(/\s+/).filter(Boolean);let visible=0;rows.forEach((row)=>{const text=row.dataset.search||'';const match=terms.every((term)=>text.includes(term));row.hidden=!match;if(match)visible++;});if(count)count.textContent=`${visible} result${visible===1?'':'s'}`;empty?.classList.toggle('is-hidden',visible!==0);scroll?.classList.toggle('is-hidden',visible===0);if(scroll)scroll.scrollTop=0;};search?.addEventListener('input',filter);filter();});
</script>
<?php require_once __DIR__.'/../includes/footer.php';?>
