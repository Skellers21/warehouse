<?php

/**
 * Create a menu item for the list of taxon designations.
 */
function data_cleaner_alter_menu($menu, $auth) {
  if ($auth->logged_in('CoreAdmin') || $auth->has_any_website_access('admin')) 
    $menu['Admin']['Verification Rules']='verification_rule';
  return $menu;
}

/** 
 * Adds the verification_rule entity to the list available via data services.
 * @return array List of additional entities to expose via the data services.
 */
function data_cleaner_extend_data_services() {
  return array(
    'verification_rules'=>array('readOnly')
  );
}

/**
 * Hook into the task scheduler to run the rules against new records on the system.
 */
function data_cleaner_scheduled_task() {
  $db = new Database();
  $rules = data_cleaner::get_rules();
  $count = data_cleaner_get_occurrence_list($db);
  try {
    if ($count>0) {
      data_cleaner_cleanout_old_messages($rules, $db);
      data_cleaner_run_rules($rules, $db);
      data_cleaner_update_occurrence_metadata($db);
    }    
    $db->query('drop table occlist');
  } catch (Exception $e) {
    $db->query('drop table occlist');
    throw $e;
  }
}

/**
 * Build a temporary table with the list of occurrences we will process, so that we have
 * consistency if changes are happening concurrently. Uses cache_occurrences as a template for this
 * as the columns it contains are most likely to be useful in the rule checks.
 * @param type $db 
 */
function data_cleaner_get_occurrence_list($db) {
  $query = 'select co.*, now() as timepoint into temporary occlist 
from cache_occurrences co
join occurrences o on o.id=co.id
inner join samples s on s.id=o.sample_id and s.deleted=false
left join samples sp on sp.id=s.parent_id and sp.deleted=false
inner join websites w on w.id=o.website_id and w.deleted=false and w.verification_checks_enabled=true
where o.deleted=false and o.record_status not in (\'I\',\'V\',\'R\',\'D\')
and (o.last_verification_check_taxa_taxon_list_id<>o.taxa_taxon_list_id
or o.updated_on>o.last_verification_check_date
or s.updated_on>o.last_verification_check_date
or sp.updated_on>o.last_verification_check_date
or o.last_verification_check_date is null) limit 200';
  $db->query($query);
  $r = $db->query('select count(*) as count from occlist')->result_array(false);
  kohana::log('alert', "Data cleaning {$r[0]['count']} record(s)");
  echo "Data cleaning {$r[0]['count']} record(s).<br/>";
  return $r[0]['count'];
}


/**
 * Loop through the rules. For each distinct module, clean up any old messages for
 * occurrences we are about to rescan
 * @param array $rules List of rule definitions
 * @param object db Database object.
 */
function data_cleaner_cleanout_old_messages($rules, $db) {
  $modulesDone=array();
  foreach ($rules as $rule) {
    if (!in_array($rule['plugin'], $modulesDone)) {
      // mark delete any previous occurrence comments for this plugin for taxa we are rechecking
      $query = 'update occurrence_comments oc
        set deleted=true
        from occlist
        where oc.occurrence_id=occlist.id
        and oc.generated_by=\''.$rule['plugin'].'\'';
      $db->query($query);
      // and cleanup the notifications generated previously
      $query = "delete 
        from notifications
        using occlist o 
        where source='Verifications and comments'
        and linked_id = o.id";
      $db->query($query);
      $modulesDone[]=$rule['plugin'];
    }
  }
}

/**
 * Run through the list of data cleaner rules, and run the queries to generate
 * comments in the occurrences.
 */
function data_cleaner_run_rules($rules, $db) {
  $count=0;
  foreach ($rules as $rule) {
    $tm = microtime(true);
    if (isset($rule['errorMsgField'])) 
      // rules are able to specify a different field (e.g. from the verification rule data) to provide the error message.
      $errorField = $rule['errorMsgField'];
    else
      $errorField = 'error_message';
    foreach ($rule['queries'] as $query) {
      // queries can override the error message field.
      $ruleErrorField = isset($query['errorMsgField']) ? $query['errorMsgField'] : $errorField;
      $implies_manual_check_required = isset($query['implies_manual_check_required']) && !$query['implies_manual_check_required'] ? 'false' : 'true';
      $errorMsgSuffix = isset($query['errorMsgSuffix']) ? $query['errorMsgSuffix'] : (isset($rule['errorMsgSuffix']) ? $rule['errorMsgSuffix'] : '');
      $sql = 'insert into occurrence_comments (comment, created_by_id, created_on,  
      updated_by_id, updated_on, occurrence_id, auto_generated, generated_by, implies_manual_check_required) 
  select distinct '.$ruleErrorField.$errorMsgSuffix.', 1, now(), 1, now(), co.id, true, \''.$rule['plugin'].'\', '.$implies_manual_check_required.'
  from occlist co';
      if (isset($query['joins']))
        $sql .= "\n" . $query['joins'];
      if (isset($query['where']))
        $sql .= "\nwhere " . $query['where'];
      // we now have the query ready to run which will return a list of the occurrence ids that fail the check.
      try {
        $count += $db->query($sql)->count();
      } catch (Exception $e) {
        echo "Query failed<br/>";
        echo $e->getMessage().'<br/>';
        echo $db->last_query().'<br/>';  
      }
    }
    $tm = microtime(true) - $tm;  
    if ($tm>3) 
      kohana::log('alert', "Data cleaner rule ".$rule['testType']." took $tm seconds");
  }
  
  echo "Data cleaner generated $count messages.<br/>";
}

/**
 * Update the metadata associated with each occurrence so we know the rules have been run.
 * @param type $db Kohana database instance.
 */
function data_cleaner_update_occurrence_metadata($db) { 
  // Note we use the information from the point when we started the process, in case
  // any changes have happened in the meanwhile which might otherwise be missed.
  $query = 'update occurrences o
set last_verification_check_date=occlist.timepoint, 
    last_verification_check_taxa_taxon_list_id=occlist.taxa_taxon_list_id
from occlist
where occlist.id=o.id';
  $db->query($query);
}

?>
