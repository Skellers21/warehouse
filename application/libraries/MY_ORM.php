<?php

/**
 * Indicia, the OPAL Online Recording Toolkit.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see http://www.gnu.org/licenses/gpl.html.
 *
 * @package    Core
 * @subpackage Libraries
 * @author    Indicia Team
 * @license    http://www.gnu.org/licenses/gpl.html GPL
 * @link     http://code.google.com/p/indicia/
 */

/**
 * Override of the Kohana core ORM class which provides Indicia specific functionality for submission of data.
 * ORM objects are normally instantiated by calling ORM::Factory(modelname[, id]). For Indicia ORM objects, 
 * there is an option to pass -1 as the ID indicating that the ORM object should not be initialised. This
 * allows access to variables such as the lookup table and search field without full instantiation of the ORM
 * object, saving hits on the database etc.
 */
class ORM extends ORM_Core {
  /**
  * @var bool Should foreign key lookups be cached? Set to true during import for example.
  */
  public static $cacheFkLookups = false;

  public function last_query() {
    return $this->db->last_query();
  }
  
  public $submission = array();
  
  /** 
   * @var array Describes the list of nested models that are present after a submission. E.g. the list of 
   * occurrences in a sample.
   */
  private $nestedModelIds = array();
  
  /**
   * @var string The default field that is searchable is called title. Override this when a different field name is used.
   */
  public $search_field='title';

  protected $errors = array();
  protected $identifiers = array('website_id'=>null,'survey_id'=>null);

  /**
   * @var array unvalidatedFields allows a list of fields which are not validated in anyway to be declared
   * by a model. If not declared then the model will not transfer them to the saved data when
   * posting a record.
   */
  protected $unvalidatedFields = array();
  
  /**
   * @var array An array which a model can populate to declare additional fields that can be submitted for csv upload.
   */
  protected $additional_csv_fields=array();
  
  /**
   * @var boolean Does the model have custom attributes? Defaults to false.
   */
  protected $has_attributes = false;
  
  private $cache;
  
  /**
   * Constructor allows plugins to modify the data model.
   * @var int $id ID of the record to load. If null then creates a new record. If -1 then the ORM 
   * object is not initialised, providing access to the variables only.
   */
  public function __construct($id = NULL)
  {
    if (is_object($id) || $id!=-1) {
      // use caching, so things don't slow down if there are lots of plugins. the object_name does not 
      // exist yet as we haven't called the parent construct, so we build our own.
      $object_name = strtolower(substr(get_class($this), 0, -6));
      $cacheId = 'orm-'.$object_name;
      $this->cache = Cache::instance();
      $ormRelations = $this->cache->get($cacheId);
      if ($ormRelations === null) {
        // now look for modules which plugin to tweak the orm relationships.
        foreach (Kohana::config('config.modules') as $path) {      
          $plugin = basename($path);
          if (file_exists("$path/plugins/$plugin.php")) {
            require_once("$path/plugins/$plugin.php");
            if (function_exists($plugin.'_extend_orm')) {
              $extends = call_user_func($plugin.'_extend_orm');
              if (isset($extends[$object_name])) {
                if (isset($extends[$object_name]['has_one']))
                  $this->has_one = array_merge($this->has_one, $extends[$object_name]['has_one']);
                if (isset($extends[$object_name]['has_many']))
                  $this->has_many = array_merge($this->has_many, $extends[$object_name]['has_many']);
                if (isset($extends[$object_name]['belongs_to']))
                  $this->belongs_to = array_merge($this->belongs_to, $extends[$object_name]['belongs_to']);
                if (isset($extends[$object_name]['has_and_belongs_to_many']))
                  $this->has_and_belongs_to_many = array_merge($this->has_and_belongs_to_many, $extends[$object_name]['has_and_belongs_to_many']);
              }
            }
          }
        }
        $cacheArray = array(
          'has_one' => $this->has_one,
          'has_many' => $this->has_many,
          'belongs_to' => $this->belongs_to,
          'has_and_belongs_to_many' => $this->has_and_belongs_to_many
        );
        $this->cache->set($cacheId, $cacheArray);
      } else {
        $this->has_one = $ormRelations['has_one'];
        $this->has_many = $ormRelations['has_many'];
        $this->belongs_to = $ormRelations['belongs_to'];
        $this->has_and_belongs_to_many = $ormRelations['has_and_belongs_to_many'];
      }
      parent::__construct($id);
    }
  }
  
  /**
   * Returns an array structure which describes this model and saved ID, plus the saved child models that were created
   * during a submission operation.
   */
  public function get_submitted_ids() {
    $r = array(
      'model' => $this->object_name,
      'id' => $this->id,
    );
    if (count($this->nestedModelIds))
      $r['children'] = $this->nestedModelIds;
    return $r;
  }

  /**
   * Override load_values to add in a vague date field. Also strips out any custom attribute values which don't go into this model.
   */
  public function load_values(array $values)
  {
    // clear out any values which match this attribute field prefix
    if (isset($this->attrs_field_prefix)) {
      foreach ($values as $key=>$value) {
        if (substr($key, 0, strlen($this->attrs_field_prefix)+1)==$this->attrs_field_prefix.':') {
          unset($values[$key]);
        }
      }
    }
    parent::load_values($values);
    // Add in date field
    if (array_key_exists('date_type', $this->object) && !empty($this->object['date_type']))
    {
      $vd = vague_date::vague_date_to_string(array
      (
        $this->object['date_start'],
        $this->object['date_end'],
        $this->object['date_type']
      ));
      $this->object['date'] = $vd;
    }
    return $this;
  }

  /**
   * Override the reload_columns method to add the vague_date virtual field
   */
  public function reload_columns($force = FALSE)
  {
    if ($force === TRUE OR empty($this->table_columns))
    {
      // Load table columns
      $this->table_columns = $this->db->list_fields($this->table_name);
      // Vague date
      if (array_key_exists('date_type', $this->table_columns))
      {
        $this->table_columns['date']['type'] = 'String';
      }
    }

    return $this;
  }

  /**
   * Provide an accessor so that the view helper can retrieve the for the model by field name.
   * Will also retrieve errors from linked models (models that were posted in the same submission)
   * if the field name is of the form model:fieldname.
   *
   * @param string $fieldname Name of the field to retrieve errors for. The fieldname can either be
   * simple, or of the form model:fieldname in which linked models can also be checked for errors. If the
   * submission structure defines the fieldPrefix for the model then this is used instead of the model name.
   */
  public function getError($fieldname) {
    $r='';
    if (array_key_exists($fieldname, $this->errors)) {
      // model is unspecified, so load error from this model.
      $r=$this->errors[$fieldname];
    } elseif (strpos($fieldname, ':')!==false) {
      list($model, $field)=explode(':', $fieldname);
      // model is specified
      $struct=$this->get_submission_structure();
      $fieldPrefix = array_key_exists('fieldPrefix', $struct) ? $struct['fieldPrefix'] : $this->object_name;
      if ($model==$fieldPrefix) {
        // model is this model
        if (array_key_exists($field, $this->errors)) {
          $r=$this->errors[$field];
        }
      }
    }
    return $r;
  }

  /**
   * Retrieve an array containing all errors.
   * The array entries are of the form 'entity:field => value'.
   */
  public function getAllErrors()
  {
    // Get this model's errors, ensuring array keys have prefixes identifying the entity
    foreach ($this->errors as $key => $value) {
      if (strpos($key, ':')===false) {
        $this->errors[$this->object_name.':'.$key]=$value;
        unset($this->errors[$key]);
      }
    }
    return $this->errors;
  }

  /**
   * Retrieve an array containing all page level errors which are marked with the key general.
   */
  public function getPageErrors() {
    $r = array();
    if (array_key_exists('general', $this->errors)) {
      array_push($r, $this->errors['general']);
    }
    return $r;
  }

  /**
   * Override the ORM validate method to store the validation errors in an array, making
   * them accessible to the views.
   *
   * @param Validation $array Validation array object.
   * @param boolean $save Optional. True if this call also saves the data, false to just validate. Default is false.
   */
  public function validate(Validation $array, $save = FALSE) {
    // the created_by_id field can be specified by web service calls if the caller knows which Indicia user
    // is making the post.
    $fields_to_copy=array_merge(array('created_by_id'), $this->unvalidatedFields);
    foreach ($fields_to_copy as $a)
    {
      if (array_key_exists($a, $array->as_array())) {
        $this->__set($a, $array[$a]);
      }
    }
    $this->set_metadata();
    try {
      if (parent::validate($array, $save)) {
        return TRUE;
      }
      else {
        // put the trimmed and processed data back into the model
        $arr = $array->as_array();
        if (array_key_exists('created_on', $this->table_columns)) {
          $arr['created_on'] = $this->created_on;
        }
        if (array_key_exists('updated_on', $this->table_columns)) {
          $arr['updated_on'] = $this->updated_on;
        }
        $this->load_values($arr);
        $this->errors = $array->errors('form_error_messages');
        return FALSE;
      }
    } catch (Exception $e) {
      if (strpos($e->getMessage(), '_unique')!==false) {
        // duplicate key violation
        $this->errors = array('You cannot add the record as it would create a duplicate.');
        return FALSE;
      } else 
        throw ($e);
    }
  }

  /**
   * For a model that is about to be saved, sets the metadata created and
   * updated field values.
   * @param object $obj The object which will have metadata set on it. Defaults to this model.
   */
  public function set_metadata($obj=null) {
    if ($obj==null) $obj=$this;
    $defaultUserId = Kohana::config('indicia.defaultPersonId');
    $force=false;
    // At this point we determine the id of the logged in user,
    // and use this in preference to the default id if possible.
    if (isset($_SESSION['auth_user'])) {
      $force = true;
      $userId = $_SESSION['auth_user']->id;
    } else
      $userId = ($defaultUserId ? $defaultUserId : 1);
    // Set up the created and updated metadata for the record
    if (!$obj->id && array_key_exists('created_on', $obj->table_columns)) {
      $obj->created_on = date("Ymd H:i:s");
      if ($force or !$obj->created_by_id) $obj->created_by_id = $userId;
    }
    // TODO: Check if updated metadata present in this entity,
    // and also use correct user.
    if (array_key_exists('updated_on', $obj->table_columns)) {
      $obj->updated_on = date("Ymd H:i:s");
      if ($force or !$obj->updated_by_id) {
        if ($obj->id)
          $obj->updated_by_id = $userId;
        else 
          // creating a new record, so it must be the same updator as creator.
          $obj->updated_by_id = $obj->created_by_id;
      }
    }
  }

  /**
   * Do a default search for an item using the search_field setup for this model.
   */
  public function lookup($search_text)
  {
    return $this->where($this->search_field, $search_text)->find();
  }

  /**
   * Return a displayable caption for the item, defined as the content of the field with the
   * same name as search_field.
   */
  public function caption()
  {
    if ($this->id) {
      return $this->__get($this->search_field);
    } else {
      return $this->getNewItemCaption();
    }
  }

  /**
   * Retrieve the caption of a new entry of this model type. Overrideable as required.
   * @return string Caption for a new entry.
   */
  protected function getNewItemCaption() {
    return ucwords(str_replace('_', ' ', $this->object_name));
  }

  /**
   * Ensures that the save array is validated before submission. Classes overriding
   * this method should call this parent method after their changes to perform necessary
   * checks unless they really want to skip them.
   */
  protected function preSubmit() {
    // Where fields are numeric, ensure that we don't try to submit strings to
    // them.
    foreach ($this->submission['fields'] as $field => $content) {
      if (isset($content['value']) && $content['value'] == '' && array_key_exists($field, $this->table_columns)) {
        $type = $this->table_columns[$field];
        switch ($type) {
          case 'int':
            $this->submission['fields'][$field]['value'] = null;
            break;
          }
      }
    }
  }
  
  /**
   * Grab the survey id and website id if they are in the submission, as they are used to check
   * attributes that apply and other permissions.
   */
  protected function populateIdentifiers() {
    if (array_key_exists('website_id', $this->submission['fields'])) {
      if (is_array($this->submission['fields']['website_id']))
        $this->identifiers['website_id']=$this->submission['fields']['website_id']['value'];
      else
        $this->identifiers['website_id']=$this->submission['fields']['website_id'];
    }
    if (array_key_exists('survey_id', $this->submission['fields'])) {
      if (is_array($this->submission['fields']['survey_id']))
        $this->identifiers['survey_id']=$this->submission['fields']['survey_id']['value'];
      else
        $this->identifiers['survey_id']=$this->submission['fields']['survey_id'];
    }
  }

  /**
   * Wraps the process of submission in a transaction.
   * @return integer If successful, returns the id of the created/found record. If not, returns null - errors are embedded in the model.
   */
  public function submit() {
    Kohana::log('debug', 'Commencing new transaction.');
    $this->db->query('BEGIN;');
    try {
      $res = $this->inner_submit();
    } catch (Exception $e) {
      $this->errors['general']='<strong>An error occurred</strong><br/>'.$e->getMessage();
      error::log_error('Exception during inner_submit.', $e);
      $res = null;
    }
    if ($res) {
      Kohana::log('debug', 'Committing transaction.');
      $this->db->query('COMMIT;');
    } else {
      Kohana::log('debug', 'Rolling back transaction.');
      $this->db->query('ROLLBACK;');
    }
    return $res;
  }

  /**
   * Submits the data by:
   * - For each entry in the "supermodels" array, calling the submit function
   *   for that model and linking in the resultant object.
   * - Calling the preSubmit function to clean data.
   * - Linking in any foreign fields specified in the "fk-fields" array.
   * - Checking (by a where clause for all set fields) that an existing
   *   record does not exist. If it does, return that.
   * - Calling the validate method for the "fields" array.
   * If successful, returns the id of the created/found record.
   * If not, returns null - errors are embedded in the model.
   */
  public function inner_submit(){
    $return = $this->populateFkLookups();
    $this->populateIdentifiers();
    $return = $this->createParentRecords() && $return;
    // No point doing any more if the parent records did not post
    if ($return) {
      $this->preSubmit();      
      $this->removeUnwantedFields();
      $return = $this->validateAndSubmit();
      $return = $this->checkRequiredAttributes() ? $return : null;
      if ($this->id) {
        // Make sure we got a record to save against before attempting to post children
        $return = $this->createChildRecords() ? $return : null;
        $return = $this->createJoinRecords() ? $return : null;
        $return = $this->createAttributes() ? $return : null;
      }
      // Call postSubmit
      if ($return) {
        $ps = $this->postSubmit();
        if ($ps == null) {
          $return = null;
        }
      }
      if (kohana::config('config.log_threshold')=='4') {
        kohana::log('debug', 'Done inner submit of model '.$this->object_name.' with result '.$return);
      }
    }
    if (!$return) kohana::log('debug', kohana::debug($this->getAllErrors()));
    return $return;
  }

  /**
   * Remove any fields from the submission that are not in the model or are custom attributes.
   */
  private function removeUnwantedFields() {
    foreach($this->submission['fields'] as $field => $content) {
      if (!array_key_exists($field, $this->table_columns) && (!isset($this->attrs_field_prefix) || !preg_match('/^'.$this->attrs_field_prefix.'\:/', $field))) 
        unset($this->submission['fields'][$field]);
    }
  }

  /**
   * Actually validate and submit the inner submission.
   *
   * @return int Id of the submitted record, or null if this failed.
   */
  protected function validateAndSubmit() {
    $return = null;
    $collapseVals = create_function('$arr',
        'if (is_array($arr)) {
           return $arr["value"];
         } else {
           return $arr;
         }');
    // Flatten the array to one that can be validated
    $vArray = array_map($collapseVals, $this->submission['fields']);
    // If we're editing an existing record, merge with the existing data.
    if (array_key_exists('id', $vArray) && $vArray['id'] != null) {
      $this->find($vArray['id']);
      $vArray = array_merge($this->as_array(), $vArray);
    }
    Kohana::log("debug", "About to validate the following array in model ".$this->object_name);
    Kohana::log("debug", kohana::debug($this->sanitise($vArray)));
    try {
      if (array_key_exists('deleted', $vArray) && $vArray['deleted']=='t') {
        // For a record deletion, we don't want to validate and save anything. Just mark delete it.
        $this->deleted='t';
        $v=$this->save();
      } else {
        // Create a new record by calling the validate method
        $v=$this->validate(new Validation($vArray), true);
      }
    } catch (Exception $e) {
        $v=false;
        $this->errors['general']=$e->getMessage();
        error::log_error('Exception during validation', $e);
    }
    if ($v) {
      // Record has successfully validated so return the id.
      Kohana::log("debug", "Record ".$this->id." has validated successfully");
      $return = $this->id;
    } else {
      // Errors.
      Kohana::log("debug", "Record did not validate");
      // Log more detailed information on why
      foreach ($this->errors as $f => $e) {
        Kohana::log("debug", "Field ".$f.": ".$e);
      }
    }
    return $return;
  }


  /**
   * When a field is present in the model that is an fkField, this means it contains a lookup
   * caption that must be searched for in the fk entity. This method does the searching and
   * puts the fk id back into the main model so when it is saved, it links to the correct fk
   * record.
   * Respects the setting $cacheFkLookups to use the cache if possible.
   *
   * @return boolean True if all lookups populated successfully.
   */
  private function populateFkLookups() {
    $r=true;
    if (array_key_exists('fkFields', $this->submission)) {
      foreach ($this->submission['fkFields'] as $a => $b) {
        $cache = false;
        if (ORM::$cacheFkLookups) {
          $keyArr=array('lookup', $b['fkTable'], $b['fkSearchField'], $b['fkSearchValue']);
          // cache must be unique per filtered value (e.g. when lookup up a taxa in a taxon list).
          if (isset($b['fkSearchFilterValue']))
            $keyArr[] = $b['fkSearchFilterValue'];
          $key = implode('-', $keyArr);
          $cache = $this->cache->get($key);
        }
        if ($cache) {
          $this->submission['fields'][$b['fkIdField']] = $cache;
        } else {
          $where = array($b['fkSearchField'] => $b['fkSearchValue']);
          // does the lookup need to be filtered, e.g. to a taxon or term list?
          if (isset($b['fkSearchFilterField']) && $b['fkSearchFilterField']) {
            $where[$b['fkSearchFilterField']] = $b['fkSearchFilterValue'];
          }
          $matches = $this->db
              ->select('id')
              ->from(inflector::plural($b['fkTable']))
              ->where($where)
              ->limit(1)
              ->get();
          if (count($matches)<1) {
            $this->errors[$a] = 'Could not find a '.$b['readableTableName'].' by looking for "'.$b['fkSearchValue'].
                '" in the '.ucwords($b['fkSearchField']).' field.';
            $r=false;
          } else {
            $this->submission['fields'][$b['fkIdField']] = $matches[0]->id;
            if (ORM::$cacheFkLookups) {
              $this->cache->set($key, $matches[0]->id, array('lookup'));
            }
          }
        }
      }
    }
    return $r;
  }

  /**
   * Generate any records that this model contains an FK reference to in the
   * Supermodels part of the submission.
   */
  private function createParentRecords() {
    // Iterate through supermodels, calling their submit methods with subarrays
    if (array_key_exists('superModels', $this->submission)) {
      foreach ($this->submission['superModels'] as &$a) {
        // Establish the right model - either an existing one or create a new one
        $id = array_key_exists('id', $a['model']['fields']) ? $a['model']['fields']['id']['value'] : null;
        if ($id) {
          $m = ORM::factory($a['model']['id'], $id);
        } else {
          $m = ORM::factory($a['model']['id']);
        }

        // Call the submit method for that model and
        // check whether it returns correctly
        $m->submission = $a['model'];
        // copy up the website id and survey id
        $m->identifiers = array_merge($this->identifiers);
        $result = $m->inner_submit();
        // copy the submission back so we pick up updated foreign keys that have been looked up. E.g. if submitting a taxa taxon list, and the 
        // taxon supermodel has an fk lookup, we need to keep it so that it gets copied into common names and synonyms
        $a['model'] = $m->submission;
        if ($result) {
          $this->submission['fields'][$a['fkId']]['value'] = $result;
        } else {
          $fieldPrefix = (array_key_exists('field_prefix',$a['model'])) ? $a['model']['field_prefix'].':' : '';
          foreach($m->errors as $key=>$value) {
            $this->errors[$fieldPrefix.$key]=$value;
          }
          return false;
        }
      }
    }
    return true;
  }

  /**
   * Generate any records that refer to this model in the subModela part of the
   * submission.
   */
  private function createChildRecords() {
    $r=true;
    if (array_key_exists('subModels', $this->submission)) {
      // Iterate through the subModel array, linking them to this model
      foreach ($this->submission['subModels'] as $a) {

        Kohana::log("debug", "Submitting submodel ".$a['model']['id'].".");

        // Establish the right model
        $m = ORM::factory($a['model']['id']);

        // Set the correct parent key in the subModel
        $fkId = $a['fkId'];
        $a['model']['fields'][$fkId]['value'] = $this->id;

        // Call the submit method for that model and
        // check whether it returns correctly
        $m->submission = $a['model'];
        // copy down the website id and survey id
        $m->identifiers = array_merge($this->identifiers);
        $result = $m->inner_submit();
        $this->nestedModelIds[] = $m->get_submitted_ids();

        if (!$result) {
          $fieldPrefix = (array_key_exists('field_prefix',$a['model'])) ? $a['model']['field_prefix'].':' : '';
          // Remember this model so that its errors can be reported
          foreach($m->errors as $key=>$value) {
            $this->errors[$fieldPrefix.$key]=$value;
          }
          $r=false;
        }
      }
    }
    return $r;
  }

  /**
   * Generate any records that represent joins from this model to another.
   */
  private function createJoinRecords() {
    if (array_key_exists('joinsTo', $this->submission)) {
      foreach($this->submission['joinsTo'] as $model=>$ids) {
        // $ids is now a list of the related ids that should be linked to this model via
        // a join table.
        $table = inflector::plural($model);
        // Get the list of ids that are missing from the current state
        $to_add = array_diff($ids, $this->$table->as_array());
        // Get the list of ids that are currently joined but need to be disconnected
        $to_delete = array_diff($this->$table->as_array(), $ids);
        $joinModel = inflector::singular($this->join_table($table));
        // Remove any joins that are to records that should no longer be joined.
        foreach ($to_delete as $id) {
          // @todo: This could be optimised by not using ORM to do the deletion.
          $delModel = ORM::factory($joinModel,
            array($this->object_name.'_id' => $this->id, $model.'_id' => $id));
          $delModel->delete();
        }
        // And add any new joins
        foreach ($to_add as $id) {
          $joinModel = ORM::factory($joinModel);
          $joinModel->validate(new Validation(array(
              $this->object_name.'_id' => $this->id, $model.'_id' => $id
          )), true);
        }
      }
      $this->save();
    }
    return true;
  }

  /**
   * Function that iterates through the required attributes of the current model, and
   * ensures that each of them has a submodel in the submission.
   */
  private function checkRequiredAttributes() {
    $r = true;
    $typeFilter = null;
    // Test if this model has an attributes sub-table. Also to have required attributes, we must be posting into a
    // specified survey or website at least.
    if ($this->has_attributes && ($this->identifiers['website_id'] || $this->identifiers['survey_id'])) {
      $got_values=array();
      $empties = array();
      if (isset($this->submission['metaFields'][$this->attrs_submission_name]))
      {
        // Old way of submitting attribute values but still supported - attributes are stored in a metafield. Find the ones we actually have a value for
        // Provided for backwards compatibility only
        foreach ($this->submission['metaFields'][$this->attrs_submission_name]['value'] as $idx => $attr) {
          if ($attr['fields']['value']) {
            array_push($got_values, $attr['fields'][$this->object_name.'_attribute_id']);
          }
        }
        // check for location type or sample method which can be used to filter the attributes available
        foreach($this->submission['fields'] as $field => $content)
          // if we have a location type or sample method, we will use it as a filter on the attribute list
          if ($field=='location_type_id' || $field=='sample_method_id')
            $typeFilter = $content['value'];
      } else {
        // New way of submitting attributes embeds attr values direct in the main table submission values.
        foreach($this->submission['fields'] as $field => $content) {
          // look for pattern smpAttr:nn (or occAttr or locAttr)
          $isAttribute = preg_match('/^'.$this->attrs_field_prefix.'\:[0-9]+/', $field, $baseAttrName);   
          if ($isAttribute) {
            // extract the nn, this is the attribute id
            preg_match('/[0-9]+/', $baseAttrName[0], $attrId);
            if ($content['value'])  
              array_push($got_values, $attrId[0]);
            else {
              // keep track of the empty field names, so we can attach any required validation errors 
              // directly to the exact field name
              $empties[$baseAttrName[0]] = $field;
            }
          }
          // if we have a location type or sample method, we will use it as a filter on the attribute list
          if ($field=='location_type_id' || $field=='sample_method_id')
            $typeFilter = $content['value'];
        }
      }
      $fieldPrefix = (array_key_exists('field_prefix',$this->submission)) ? $this->submission['field_prefix'].':' : '';
      // as the required fields list is relatively static, we use the cache. This cache entry gets cleared when 
      // a custom attribute is saved so it should always be up to date.
      $keyArr = array_merge(array('required', $this->object_name), $this->identifiers);
      if ($typeFilter) $keyArr[] = $typeFilter;
      $key = implode('-', $keyArr);
      $result = $this->cache->get($key);
      if ($result===null) {
        // setup basic query to get custom attrs
        $this->setupDbToQueryAttributes(false, $typeFilter);
        $attr_entity = $this->object_name.'_attribute';
        // We only want globally or locally required ones
        if ($this->identifiers['website_id'] || $this->identifiers['survey_id']) 
          $this->db->where('('.$attr_entity."s_websites.validation_rules like '%required%' or ".$attr_entity."s.validation_rules like '%required%')");
        else 
          $this->db->like($attr_entity.'s.validation_rules','%required%');
        $result=$this->db->get()->result_array(true);
        $this->cache->set($key, $result, array('required-fields'));
      }
      foreach($result as $row) {
        if (!in_array($row->id, $got_values)) {
          // There is a required attr which we don't have a value for the submission for. But if posting an existing occurrence, the
          // value may already exist in the db, so only validate any submitted blank attributes which will be in the empties array and
          // skip any attributes that were not in the submission.
          $fieldname = $fieldPrefix.$this->attrs_field_prefix.':'.$row->id;
          if (empty($this->submission['fields']['id']['value']) || isset($empties[$fieldname])) {            
            // map to the exact name of the field if it is available
            if (isset($empties[$fieldname])) $fieldname = $empties[$fieldname];
            $this->errors[$fieldname]='Please specify a value for the '.$row->caption;
            kohana::log('debug', 'No value for '.$row->caption . ' in '.print_r($got_values, true));
            $r=false;
          }
        }
      }
    }
    return $r;
  }
  
  /** 
   * Prepares the db object query builder to query the list of custom attributes for this model.
   * @param boolean $required Optional. Set to true to only return required attributes (requires 
   * the website and survey identifier to be set).
   * @param int @typeFilter Specify a location type meaning id or a sample method meaning id to
   * filter the returned attributes to those which apply to the given type or method.
   */
  protected function setupDbToQueryAttributes($required = false, $typeFilter = null) {
    $attr_entity = $this->object_name.'_attribute';
    $this->db->select($attr_entity.'s.id', $attr_entity.'s.caption');
    $this->db->from($attr_entity.'s');
    $this->db->where($attr_entity.'s.deleted', 'f');
    if ($this->identifiers['website_id'] || $this->identifiers['survey_id']) {
      $this->db->join($attr_entity.'s_websites', $attr_entity.'s_websites.'.$attr_entity.'_id', $attr_entity.'s.id');
      $this->db->where($attr_entity.'s_websites.deleted', 'f');
      if ($this->identifiers['website_id'])
        $this->db->where($attr_entity.'s_websites.website_id', $this->identifiers['website_id']);
      if ($this->identifiers['survey_id'])
        $this->db->in($attr_entity.'s_websites.restrict_to_survey_id', array($this->identifiers['survey_id'], null));
      if ($required) {
        $this->db->like($attr_entity.'s_websites.validation_rules', '%required%');
      }
      // ensure that only attrs for the record's sample method or location type, or unrestricted attrs,
      // are returned
      if ($this->object_name=='location' || $this->object_name=='sample') {
        if ($this->object_name=='location')
          $this->db->join('termlists_terms as tlt', 'tlt.id',
              'location_attributes_websites.restrict_to_location_type_id', 'left');
        elseif ($this->object_name=='sample') {
          $this->db->join('termlists_terms as tlt', 'tlt.id',
              'sample_attributes_websites.restrict_to_sample_method_id', 'left');
        }
        $this->db->join('termlists_terms as tlt2', 'tlt2.meaning_id', 'tlt.meaning_id', 'left');
        $ttlIds = array(null);
        if ($typeFilter)
          $ttlIds[] = $typeFilter;
        $this->db->in('tlt2.id', $ttlIds);
      }
    }
  }

  /**
   * Returns an array of fields that this model will take when submitting.
   * By default, this will return the fields of the underlying table, but where
   * supermodels are involved this may be overridden to include those also.
   *
   * When called with true, this will also add fk_ columns for any _id columns
   * in the model unless the column refers to a model in the submission structure
   * supermodels list. For example, when adding an occurrence via import, you supply
   * the fields for the sample to create rather than a lookup value for the existing 
   * samples.
   * @param boolean $fk
   * @param integer $website_id If set then custom attributes are limited to those for this website.
   * @param integer $survey_id If set then custom attributes are limited to those for this survey.
   * @param int @attrTypeFilter Specify a location type meaning id or a sample method meaning id to
   * filter the returned attributes to those which apply to the given type or method.
   */
  public function getSubmittableFields($fk = false, $website_id=null, $survey_id=null, $attrTypeFilter=null) {
    if ($website_id!==null) 
      $this->identifiers['website_id']=$website_id;
    if ($website_id!==null) 
      $this->identifiers['survey_id']=$survey_id;
    $fields = $this->getPrefixedColumnsArray($fk);
    $struct = $this->get_submission_structure();
    if (array_key_exists('superModels', $struct)) {
      foreach ($struct['superModels'] as $super=>$content) {
        $fields = array_merge($fields, ORM::factory($super)->getSubmittableFields($fk, $website_id, $survey_id, $attrTypeFilter));
      }
    }
    if (array_key_exists('metaFields', $struct)) {
      foreach ($struct['metaFields'] as $metaField) {
        $fields["metaFields:$metaField"]='';
      }
    }    
    if ($this->has_attributes) {
      $this->setupDbToQueryAttributes(false, $attrTypeFilter);
      $result = $this->db->get();
      foreach($result as $row) {
        $fieldname = $this->attrs_field_prefix.':'.$row->id;
        $fields[$fieldname] = $row->caption;
      }
    }
    $fields = array_merge($fields, $this->additional_csv_fields);
    return $fields;
  }

  /**
   * Retrieves a list of the required fields for this model and its related models.
   * @param <type> $fk
   * @param int $website_id
   * @param int $survey_id
   *
   * @return array List of the fields which are required.
   */
  public function getRequiredFields($fk = false, $website_id=null, $survey_id=null) {
    if ($website_id!==null) 
      $this->identifiers['website_id']=$website_id;
    if ($website_id!==null) 
      $this->identifiers['survey_id']=$survey_id;
    $sub = $this->get_submission_structure();
    $arr = new Validation(array('id'=>1));
    $this->validate($arr, false);
    $fields = array();  
    foreach ($arr->errors() as $column=>$error) {
      if ($error=='required') {
        if ($fk && substr($column, -3) == "_id") {
          // don't include the fk link field if the submission is supposed to contain full data
          // for the supermodel record rather than just a link
          if (!isset($sub['superModels'][substr($column, 0, -3)]))
            $fields[] = $this->object_name.":fk_".substr($column, 0, -3);
        } else {
          $fields[] = $this->object_name.":$column";
        }
      }
    }
    if ($this->has_attributes) {    
      $this->setupDbToQueryAttributes(true);
      $result = $this->db->get();
      foreach($result as $row) {
        $fields[] = $this->attrs_field_prefix.':'.$row->id;
      }
    }
    
    if (array_key_exists('superModels', $sub)) {
      foreach ($sub['superModels'] as $super=>$content) {
        $fields = array_merge($fields, ORM::factory($super)->getRequiredFields($website_id, $survey_id));
      }
    }
    return $fields;
  }

  /**
   * Returns the array of values, with each key prefixed by the model name then :.
   *
   * @param string $prefix Optional prefix, only required when overriding the model name
   * being used as a prefix.
   * @return array Prefixed key value pairs.
   */
  public function getPrefixedValuesArray($prefix=null) {
    $r = array();
    if (!$prefix) {
      $prefix=$this->object_name;
    }
    foreach ($this->as_array() as $key=>$val) {
      $r["$prefix:$key"]=$val;
    }
    return $r;
  }

  /**
   * Returns the array of columns, with each column prefixed by the model name then :.
   *
   * @return array Prefixed columns.
   */
  protected function getPrefixedColumnsArray($fk=false, $skipHiddenFields=true) {
    $r = array();
    $prefix=$this->object_name;
    $sub = $this->get_submission_structure();
    foreach ($this->table_columns as $column=>$type) {
      if ($skipHiddenFields && isset($this->hidden_fields) && in_array($column, $this->hidden_fields))
        continue;
      if ($fk && substr($column, -3) == "_id") {
        // don't include the fk link field if the submission is supposed to contain full data
        // for the supermodel record rather than just a link
        if (!isset($sub['superModels'][substr($column, 0, -3)]))
          $r["$prefix:fk_".substr($column, 0, -3)]='';
      } else {
        $r["$prefix:$column"]='';
      }
    }
    return $r;
  }

 /**
  * Create the records for any attributes attached to the current submission.
  */
  protected function createAttributes() {
    if ($this->has_attributes) {
      // Deprecated submission format attributes are stored in a metafield.
      if (isset($this->submission['metaFields'][$this->attrs_submission_name])) {
        return self::createAttributesFromMetafields();
      } else {
        // loop to find the custom attributes embedded in the table fields
        foreach ($this->submission['fields'] as $field => $content) {
          if (preg_match('/^'.$this->attrs_field_prefix.'\:/', $field)) {
            $value = $content['value'];
            // Attribute name is of form tblAttr:attrId:valId:uniqueIdx
            $arr = explode(':', $field);
            $attrId = $arr[1];
            $valueId = count($arr)>2 ? $arr[2] : null;
            if (!$this->createAttributeRecord($attrId, $valueId, $value)) 
              return false;
          }
        }
      }
    }
    return true;
  }

  /**
   * Up to Indicia v0.4, the custom attributes associated with a submission where held in a sub-structure of the submission
   * called metafields. This code is used to provide backwards compatibility with this submission format.
   */
  protected function createAttributesFromMetafields() {
    foreach ($this->submission['metaFields'][$this->attrs_submission_name]['value'] as $idx => $attr)
    {
      $value = $attr['fields']['value'];
      if ($value != '') {
        // work out the *_attribute this is attached to, to figure out the field(s) to store the value in.
        $attrId = $attr['fields'][$this->object_name.'_attribute_id'];
        // If this is an existing attribute value, get the record id to overwrite
        $valueId = (array_key_exists('id', $attr['fields'])) ? $attr['fields']['id'] : null;
        if (!$this->createAttributeRecord($attrId, $valueId, $value)) 
          return false;
      }
    }
    return true;
  }
  
  protected function createAttributeRecord($attrId, $valueId, $value) {
    // There are particular circumstances when $value is actually an array: when a attribute is multi value,
    // AND has yet to be created, AND is passed in as multiple ***Attr:<n>[] POST variables. This should only happen when
    // the attribute has yet to be created, as after this point the $valueID is filled in and that specific attribute POST variable
    // is no longer multivalue - only one value is stored per attribute value record, though more than one record may exist
    // for a given attribute. There may be others with th same <n> without a $valueID.
    if (is_array($value)){
      if (is_null($valueId)) {
        $retVal = true;
        foreach($value as $singlevalue) { // recurse over array.
          $retVal = $this->createAttributeRecord($attrId, $valueId, $singlevalue) && $retVal;
        }
        return $retVal;	
      } else {
        $this->errors['general']='INTERNAL ERROR: multiple values passed in for '.$this->object_name.' '.$valueId;
        return false;
      }
    }
    // Create a attribute value, loading the existing value id if it exists
    $attrValueModel = ORM::factory($this->object_name.'_attribute_value', $valueId);
    $dt = $this->db
        ->select('data_type')
        ->from($this->object_name.'_attributes')
        ->where(array('id'=>$attrId))
        ->get();
    $dataType = $dt[0]->data_type;
    $vf = null;
    
    $fieldPrefix = (array_key_exists('field_prefix',$this->submission)) ? $this->submission['field_prefix'].':' : '';
    // For attribute value errors, we need to report e.g smpAttr:attrId[:attrValId] as the error key name, not
    // the table and field name as normal.
    $fieldId = $fieldPrefix.$this->attrs_field_prefix.':'.$attrId;
    if ($attrValueModel->id) {
      $fieldId .= ':' . $attrValueModel->id;
    }
    
    switch ($dataType) {
      case 'T':
        $vf = 'text_value';
        break;
      case 'F':
        $vf = 'float_value';
        break;
      case 'D':
      case 'V':
        // Date
        if (!empty($value)) {
          $vd=vague_date::string_to_vague_date($value);
          if ($vd) {
            $attrValueModel->date_start_value = $vd[0];
            $attrValueModel->date_end_value = $vd[1];
            $attrValueModel->date_type_value = $vd[2];
          } else {
            $this->errors[$fieldId] = "Invalid value $value for attribute";
            kohana::log('debug', "Could not accept value $value into field $vf for attribute $fieldId.");
            return false;
          }
        } else {
          $attrValueModel->date_start_value = null;
          $attrValueModel->date_end_value = null;
          $attrValueModel->date_type_value = null;
        }
        break;
      default:
        // Lookup in list, int or boolean
        $vf = 'int_value';
        break;
    }    

    if ($vf != null) {
      $attrValueModel->$vf = $value;
      // Test that ORM accepted the new value - it will reject if the wrong data type for example. Use a string compare to get a
      // proper test but with type tolerance.
      if (strcmp($attrValueModel->$vf,$value)!==0) {
        $this->errors[$fieldId] = "Invalid value $value for attribute";
        kohana::log('debug', "Could not accept value $value into field $vf for attribute $fieldId.");
        return false;
      } else {
        kohana::log('debug', "Accepted value $value into field $vf for attribute $fieldId. Value=".$attrValueModel->$vf);
      }
    }

    // Hook to the owning entity (the sample, location or occurrence)
    $thisFk = $this->object_name.'_id';
    $attrValueModel->$thisFk = $this->id;
    // and hook to the attribute
    $attrFk = $this->object_name.'_attribute_id';
    $attrValueModel->$attrFk = $attrId;
    // set metadata
    $this->set_metadata($attrValueModel);

    try {
      $v=$attrValueModel->validate(new Validation($attrValueModel->as_array()));
    } catch (Exception $e) {
        $v=false;
        $this->errors[$fieldId]=$e->getMessage();
        error::log_error('Exception during validation', $e);
    }
    if (!$v) {
      foreach($attrValueModel->errors as $key=>$value) {
        // concatenate the errors if more than one per field.
        $this->errors[$fieldId] = array_key_exists($fieldId, $this->errors) ? $this->errors[$fieldId] . '  ' . $value : $value;
      }
      return false;
    }
    $attrValueModel->save();
    $this->nestedModelIds[] = $attrValueModel->get_submitted_ids();

    return true;
  }

  /**
   * Overrideable function to allow some models to handle additional records created on submission.
   * @return boolean True if successful.
   */
  protected function postSubmit() {
    return true;
  }

  /**
   * Accessor for children.
   * @return The children in this model or an empty string.
   */
  public function getChildren() {
    if (isset($this->ORM_Tree_children)) {
      return $this->ORM_Tree_children;
    } else {
      return '';
    }
  }

  /**
   * Set the submission data for the model using an associative array (normally the
   * form post data). The submission is built as a wrapped structure ready to be
   * saved.
   *
   * @param array $array Associative array of data to submit.
   * @param boolean $fklink
   */
  public function set_submission_data($array, $fklink=false) {
    $this->submission = $this->wrap($array, $fklink);
  }

  /**
  * Wraps a standard $_POST type array into a save array suitable for use in saving
  * records.
  *
  * @param array $array Array to wrap
  * @param bool $fkLink=false Link foreign keys?
  * @return array Wrapped array
  */
  protected function wrap($array, $fkLink = false)
  {
    // share the wrapping library with the client helpers
    require_once(DOCROOT.'client_helpers/submission_builder.php');
    $r = submission_builder::build_submission($array, $this->get_submission_structure(), $fkLink);
      // Map fk_* fields to the looked up id
    if ($fkLink) {
      $r = $this->getFkFields($r, $array);
    }
    if (array_key_exists('superModels', $r)) {
      $idx=0;
      foreach ($r['superModels'] as $super) {
        $r['superModels'][$idx]['model'] = $this->getFkFields($super['model'], $array);
        $idx++;
      }
    }
    return $r;
  }

  /**
   * Converts any fk_* fields in a save array into the fkFields structure ready to be looked up.
   *
   * @param $submission Submission containing the foreign key field definitions to convert
   * @param $saveArray Original form data being wrapped, which can contain filters to operate against the lookup table 
   * of the form fkFilter:table:field=value.
   */
  private function getFkFields($submission, $saveArray) {
    foreach ($submission['fields'] as $field=>$value) {
      if (substr($field, 0, 3)=='fk_') {
        // This field is a fk_* field which contains the text caption of a record which we need to lookup.
        // First work out the model to lookup against
        $fieldName = substr($field,3);
        if (array_key_exists($fieldName, $this->belongs_to)) {
          $fkTable = $this->belongs_to[$fieldName];
        } elseif ($this instanceof ORM_Tree && $fieldName == 'parent') {
          $fkTable = inflector::singular($this->getChildren());
        } else {
           $fkTable = $fieldName;
        }
        // Create model without initialisting, so we can just check the lookup variables
        $fkModel = ORM::Factory($fkTable, -1);
        // let the model map the lookup against a view if necessary
        $lookupAgainst = isset($fkModel->lookup_against) ? $fkModel->lookup_against : $fkTable;
        // Generate a foreign key instance
        $submission['fkFields'][$field] = array
        (
          // Foreign key id field is table_id
          'fkIdField' => "$fieldName"."_id",
          'fkTable' => $lookupAgainst,
          'fkSearchField' => $fkModel->search_field,
          'fkSearchValue' => trim($value['value']),
          'readableTableName' => ucwords(preg_replace('/[\s_]+/', ' ', $fkTable))
        );
        // if the save array defines a filter against the lookup table then also store that. E.g.
        // a search in the taxa_taxon_list table may want to filter by the taxon list. This is done
        // by adding a value such as fkFilter:taxa_taxon_list:taxon_list_id=2.
        // Search through the save array for a filter value
        foreach ($saveArray as $filterfield=>$filtervalue) {
          if (substr($filterfield, 0, strlen("fkFilter:$fkTable:")) == "fkFilter:$fkTable:") {
            // found a filter for this fkTable. So extract the field name as the 3rd part
            $arr = explode(':', $filterfield);
            $submission['fkFields'][$field]['fkSearchFilterField'] = $arr[2];
            // and remember the value
            $submission['fkFields'][$field]['fkSearchFilterValue'] = $filtervalue;
          }
        }
      }
    }
    return $submission;
  }

  /**
   * Returns the structure which defines the relationship between the records that can
   * be submitted when submitting this model. This is the default, which just submits the
   * model and no related records, but it is overrideable to define more complex structures.
   *
   * @return array Submission structure array
   */
  public function get_submission_structure() {
    return array('model'=>$this->object_name);
  }


  /**
   * Overrideable method allowing models to declare any default values for loading into a form
   * on creation of a new record.
   */
  public function getDefaults() {
    return array();
  }

  /**
  * Convert an array of field data (a record) into a sanitised version, with email and password hidden.
  */
  private function sanitise($array) {
    // make a copy of the array
    $r = $array;
    if (array_key_exists('password', $r)) $r['password'] = '********';
    if (array_key_exists('email', $r)) $r['email'] = '********';
    return $r;
  }
  
  /**
   * Override the ORM clear method to clean up errors and identifier tracking.
   */
  public function clear() {
    parent::clear();
    $this->errors=array();
    $this->identifiers = array('website_id'=>null,'survey_id'=>null);
  }
  
 /**
   * Override the ORM load_type method: modifies float behaviour.
   * Loads a value according to the types defined by the column metadata.
   *
   * @param   string  column name
   * @param   mixed   value to load
   * @return  mixed
   */
  protected function load_type($column, $value)
  {
    $type = gettype($value);
    if ($type == 'object' OR $type == 'array' OR ! isset($this->table_columns[$column]))
      return $value;

    // Load column data
    $column = $this->table_columns[$column];

    if ($value === NULL AND ! empty($column['null']))
      return $value;

    if ( ! empty($column['binary']) AND ! empty($column['exact']) AND (int) $column['length'] === 1)
    {
      // Use boolean for BINARY(1) fields
      $column['type'] = 'boolean';
    }

    switch ($column['type'])
    {
      case 'int':
        if ($value === '' AND ! empty($column['null']))
        {
          // Forms will only submit strings, so empty integer values must be null
          $value = NULL;
        }
        elseif ((float) $value > PHP_INT_MAX)
        {
          // This number cannot be represented by a PHP integer, so we convert it to a string
          $value = (string) $value;
        }
        else
        {
          $value = (int) $value;
        }
      break;
      case 'float':
        if ($value === '' AND ! empty($column['null']))
        {
          // Forms will only submit strings, so empty float values must be null
          $value = NULL;
        }
        else
        {
          $value = (float) $value;
        }
        break;
      case 'boolean':
        $value = (bool) $value;
      break;
      case 'string':
        $value = (string) $value;
      break;
    }

    return $value;
  }
  
}

?>