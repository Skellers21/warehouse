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
 * @package Indicia
 * @subpackage Libraries
 * @author  Indicia Team
 * @license http://www.gnu.org/licenses/gpl.html GPL
 * @link    http://code.google.com/p/indicia/
 */

/**
* The report reader encapsulates logic for reading reports from a number of sources, and opens up * report 
* methods in a transparent way to the report controller.
*/
class XMLReportReader_Core implements ReportReader
{
  private $name;
  private $title;
  private $description;
  private $row_class;
  private $query;
  private $countQuery=null;
  private $field_sql;
  private $order_by;
  private $params = array();
  private $columns = array();
  private $tables = array();
  private $attributes = array();
  private $automagic = false;
  private $vagueDateProcessing = 'true';
  private $download = 'OFF';
  
  /**
   * @var boolean Identify if we have got SQL defined in the columns array. If so we are able to auto-generate the 
   * sql for the columns list.
   */
  private $hasColumnsSql = false;
  
  /**
   * @var boolean Identify if we have got SQL defined for aggregated fields. If so we need to implement a group by for
   * the other fields.
   */
  private $hasAggregates = false;
  
  /** 
   * Returns a simple array containing the title and description of a report. Static so you don't have to load the full report object to get this
   * information.
   */
  public static function loadMetadata($report) {
    $reader = new XMLReader();
    if ($reader->open($report)===false)
      throw new Exception("Report $report could not be opened.");
    $metadata = array();
    while($reader->read()) {
      if ($reader->nodeType==XMLREADER::ELEMENT && $reader->name=='report') {
        $metadata['title'] = $reader->getAttribute('title');
        $metadata['description'] = $reader->getAttribute('description');
        break;
      }
    }
    $reader->close();
    return $metadata;
  }

  /**
  * <p> Constructs a reader for the specified report. </p>
  */
  public function __construct($report, $websiteIds)
  {
    Kohana::log('debug', "Constructing XMLReportReader for report $report.");
    try
    {
      $a = explode('/', $report);
      $this->name = $a[count($a)-1];
      $reader = new XMLReader();
      $reader->open($report);
      $fieldsql = '';
      while($reader->read())
      {
        switch($reader->nodeType)
        {
          case (XMLREADER::ELEMENT):
            switch ($reader->name)
              {
              case 'report':
                $this->title = $reader->getAttribute('title');
                $this->description = $reader->getAttribute('description');
                $this->row_class = $reader->getAttribute('row_class');
                break;
              case 'query':
                if (!$websiteFilterField = $reader->getAttribute('website_filter_field'))
                  // default field name for filtering against websites
                  $websiteFilterField = 'w.id';
                if (!$this->samples_id_field = $reader->getAttribute('samples_id_field'))
                  // default table alias for the samples table, so we can join to the id
                  $this->samples_id_field = 's.id';
                if (!$this->occurrences_id_field = $reader->getAttribute('occurrences_id_field'))
                  // default table alias for the occurrences table, so we can join to the id
                  $this->occurrences_id_field = 'o.id';
                if (!$this->locations_id_field = $reader->getAttribute('locations_id_field'))
                  // default table alias for the locations table, so we can join to the id
                  $this->locations_id_field = 'l.id';
                $reader->read();
                $this->query = $reader->value;
                if ($websiteIds) {
                  if (in_array('', $websiteIds)) {
                    foreach($websiteIds as $key=>$value) {
                      if (empty($value))
                        unset($websiteIds[$key]);
                    }  
                  }
                  $idList = implode($websiteIds, ',');
                  $filter = "($websiteFilterField in ($idList) or $websiteFilterField is null)";
                  $this->query = str_replace('#website_filter#', $filter, $this->query);
                } else
                  // use a dummy filter to return all websites if core admin
                  $this->query = str_replace('#website_filter#', '1=1', $this->query);
                break;
              case 'field_sql':
                $reader->read();
                $field_sql = $reader->value;
                // drop a marker in so we can insert custom attr fields later
                $field_sql .= '#fields#';
                break;
              case 'order_by':
                $reader->read();
                $this->order_by[] = $reader->value;
                break;
              case 'param':
                $this->mergeParam(
                    $reader->getAttribute('name'),
                    $reader->getAttribute('display'),
                    $reader->getAttribute('datatype'),
                    $reader->getAttribute('allow_buffer'),
                    $reader->getAttribute('fieldname'),
                    $reader->getAttribute('alias'),
                    $reader->getAttribute('emptyvalue'),
                    $reader->getAttribute('description'),
                    $reader->getAttribute('query'),
                    $reader->getAttribute('lookup_values'),
                    $reader->getAttribute('population_call'));
                break;
              case 'column':
                $this->mergeXmlColumn($reader);
                break;
              case 'table':
                $this->automagic = true;
                $this->setTable(
                    $reader->getAttribute('tablename'),
                    $reader->getAttribute('where'));
                break;
              case 'subTable':
                $this->setSubTable(
                    $reader->getAttribute('tablename'),
                    $reader->getAttribute('parentKey'),
                    $reader->getAttribute('tableKey'),
                    $reader->getAttribute('join'),
                    $reader->getAttribute('where'));
                break;
              case 'tabColumn':
                 $this->mergeTabColumn(
                    $reader->getAttribute('name'),
                    $reader->getAttribute('func'),
                    $reader->getAttribute('display'),
                    $reader->getAttribute('style'),
                    $reader->getAttribute('feature_style'),
                    $reader->getAttribute('class'),
                    $reader->getAttribute('visible'),
                    false
                    );
                break;
              case 'attributes':
                $this->setAttributes(
                    $reader->getAttribute('where'),
                    $reader->getAttribute('separator'),
                    $reader->getAttribute('hideVagueDateFields'),// determines whether to hide the main vague date fields for attributes.
                    $reader->getAttribute('meaningIdLanguage'));//if not set, lookup lists use term_id. If set, look up lists use meaning_id, with value either 'preferred' or the 3 letter iso language to use.
                break;
              case 'vagueDate': // This switches off vague date processing.
                $this->vagueDateProcessing = $reader->getAttribute('enableProcessing');
                break;
              case 'download': // This enables download processing.. potentially dangerous as updates DB.
                $this->setDownload($reader->getAttribute('mode'));
                break;
              case 'mergeTabColumn':
                 $this->setMergeTabColumn(
                    $reader->getAttribute('name'),
                    $reader->getAttribute('tablename'),
                    $reader->getAttribute('separator'),
                    $reader->getAttribute('where'),
                    $reader->getAttribute('display'),
                    $reader->getAttribute('visible'));
                break;
              }
              break;
          case (XMLReader::END_ELEMENT):
            switch ($reader->name)
              {
                case 'subTable':
                  $this->tableIndex=$this->tables[$this->tableIndex]['parent'];
                break;
              }
             break;
        }
      }
      $reader->close();
      // Add a token to mark where additional filters can insert in the WHERE clause.
      if ($this->query && strpos($this->query, '#filters#')===false) {
        if (strpos($this->query, '#order_by#')!==false)
          $this->query = str_replace('#order_by#', "#filters#\n#order_by#", $this->query);
        else
            $this->query .= '#filters#';
      }
      // sort out the field list or use count(*) for the count query. Do this at the end so the queries are
      // otherwise the same.
      if (!empty($field_sql)) {
        $this->countQuery = str_replace('#field_sql#', ' count(*) ', $this->query);
        $this->query = str_replace('#field_sql#', $field_sql, $this->query);
      }
      if ($this->hasColumnsSql) {
        // column sql is defined in the list of column elements, so autogenerate the query.
        $this->autogenColumns();
        if ($this->hasAggregates) {
          $this->buildGroupBy();
        } 
      } elseif ($this->query)
        // column SQL is part of the SQL statement, or defined in a field_sql element.
        // Get any extra columns from the query data. Do this at the end so that the specified columns appear first, followed by any unspecified ones.
        $this->inferFromQuery();
    }
    catch (Exception $e)
    {
      throw new Exception("Report: $report\n".$e->getMessage());
    }
  }
  
  /**
   * Use the sql attributes from the list of columns to auto generate the columns SQL.
   */
  private function autogenColumns() {
    $sql = array();
    foreach ($this->columns as $col=>$def) {
      if (isset($def['sql'])) {
        $sql[] = $def['sql'] . ' as ' . $col;
      }
    }
    // merge this back into the query. Note we drop in a #fields# tag so that the query processor knows where to 
    // add custom attribute fields.
    $this->query = str_replace('#columns#', implode(', ', $sql) . '#fields#', $this->query);
  }
  
  /**
   * If there are columns marked with the aggregate attribute, then we can build a group by clause
   * using all the non-aggregate column sql. 
   * This is done dynamically leaving us with the ability to automatically extend the group by field list,
   * e.g. if some custom attribute columns have been added to the report.
   */
  private function buildGroupBy() {
    $sql = array();
    foreach ($this->columns as $col=>$def) {
      if (isset($def['sql']) && (!isset($def['aggregate']) || $def['aggregate']!='true')) {
        $sql[] = $def['sql'];
      }
    }
    // Add the non-aggregated fields to the end of the query. Leave a token so that the query processor
    // can add more, e.g. if there are custom attribute columns, and also has a suitable place for a HAVING clause.
    if (count($sql)>0)
      $this->query .= "\nGROUP BY " . implode(', ', $sql) . '#group_bys#';    
  }

  /**
  * <p> Returns the title of the report. </p>
  */
  public function getTitle()
  {
    return $this->title;
  }

  /**
  * <p> Returns the description of the report. </p>
  */
  public function getDescription()
  {
    return $this->description;
  }

  /**
  * <p> Returns the query specified. </p>
  */
  public function getQuery()
  {
    if ( $this->automagic == false) {
      return $this->query;
    }
    $query = "SELECT ";
    $j=0;
    for($i = 0; $i < count($this->tables); $i++){
    // In download mode make sure that the occurrences id is in the list

      foreach($this->tables[$i]['columns'] as $column){
    if ($j != 0) $query .= ",";
    if ($column['func']=='') {
        $query .= " lt".$i.".".$column['name']." AS lt".$i."_".$column['name'];
    } else {
          $query .= " ".preg_replace("/#parent#/", "lt".$this->tables[$i]['parent'], preg_replace("/#this#/", "lt".$i, $column['func']))." AS lt".$i."_".$column['name'];
    }
        $j++;
      }
  }
  // table list
  $query .= " FROM ";
  for($i = 0; $i < count($this->tables); $i++){
    if ($i == 0) {
        $query .= $this->tables[$i]['tablename']." lt".$i;
    } else {
        if ($this->tables[$i]['join'] != null) {
          $query .= " LEFT OUTER JOIN ";
           } else {
          $query .= " INNER JOIN ";
        }
        $query .= $this->tables[$i]['tablename']." lt".$i." ON (".$this->tables[$i]['tableKey']." = ".$this->tables[$i]['parentKey'];
        if($this->tables[$i]['where'] != null) {
          $query .= " AND ".preg_replace("/#this#/", "lt".$i, $this->tables[$i]['where']);
       }
        $query .= ") ";
    }
  }
  // where list
  $previous=false;
  if($this->tables[0]['where'] != null) {
    $query .= " WHERE ".preg_replace("/#this#/", "lt0", $this->tables[0]['where']);
    $previous = true;
  }
  // when in download mode set a where clause
  // only down load records which are complete or verified, and have not been downloaded before.
  // for the final download, only download thhose records which have gone through an initial download, and hence assumed been error checked.
  if($this->download != 'OFF'){
    for($i = 0; $i < count($this->tables); $i++){
      if ($this->tables[$i]['tablename'] == "occurrences") {
        $query .= ($previous ? " AND " : " WHERE ").
          " (lt".$i.".record_status in ('C'::bpchar, 'V'::bpchar) OR '".$this->download."'::text = 'OFF'::text) ".
            " AND (lt".$i.".downloaded_flag in ('N'::bpchar, 'I'::bpchar) OR '".$this->download."'::text != 'INITIAL'::text) ".
            " AND (lt".$i.".downloaded_flag = 'I'::bpchar OR ('".$this->download."'::text != 'CONFIRM'::text AND '".$this->download."'::text != 'FINAL'::text))";
        break;
      }
    }
  }
  return $query;
  }
  
  public function getCountQuery()
  {
    return $this->countQuery;
  }

  /**
  * <p> Uses source-specific validation methods to check whether the report query is valid. </p>
  */
  public function isValid(){}

  /**
  * <p> Returns the order by clause for the query. </p>
  */
  public function getOrderClause()
  {
    if ($this->order_by) {
      return implode(', ', $this->order_by);
    }
  }

  /**
  * <p> Gets a list of parameters (name => array('display' => display, ...)) </p>
  */
  public function getParams()
  {
    return $this->params;
  }

  /**
  * <p> Gets a list of the columns (name => array('display' => display, 'style' => style, 'visible' => visible)) </p>
  */
  public function getColumns()
  {
    return $this->columns;
  }

  /**
  * <p> Returns a description of the report appropriate to the level specified. </p>
  */
  public function describeReport($descLevel)
  {
    switch ($descLevel)
    {
      case (ReportReader::REPORT_DESCRIPTION_BRIEF):
        return array(
            'name' => $this->name,
            'title' => $this->getTitle(),
            'row_class' => $this->getRowClass(),
            'description' => $this->getDescription());
        break;
      case (ReportReader::REPORT_DESCRIPTION_FULL):
        // Everything
        return array
        (
          'name' => $this->name,
          'title' => $this->getTitle(),
          'description' => $this->getDescription(),
          'row_class' => $this->getRowClass(),
          'columns' => $this->columns,
          'parameters' => $this->params,
          'query' => $this->query,
          'order_by' => $this->order_by
        );
        break;
      case (ReportReader::REPORT_DESCRIPTION_DEFAULT):
      default:
        // At this report level, we include most of the useful stuff.
        return array
        (
          'name' => $this->name,
          'title' => $this->getTitle(),
          'description' => $this->getDescription(),
          'row_class' => $this->getRowClass(),
          'columns' => $this->columns,
          'parameters' => $this->params
        );
    }
  }

  /**
   */
  public function getAttributeDefns()
  {
     return $this->attributes;
  }

  public function getVagueDateProcessing()
  {
    return $this->vagueDateProcessing;
  }

  public function getDownloadDetails()
  {
   $thisDefn = new stdClass;
   $thisDefn->mode = $this->download;
   $thisDefn->id = 'occurrences_id';
   if($this->automagic) {
     for($i = 0; $i < count($this->tables); $i++){
      if($this->tables[$i]['tablename'] == 'occurrences'){ // Warning, will not work with multiple occurrence tables
         $thisDefn->id = "lt".$i."_id";
         break;
      }
     }
   }
   return $thisDefn;
  }
  //* PRIVATE FUNCTIONS *//

  /**
   * Returns the css class to apply to rows in the report.
   */
  private function getRowClass()
  {
    return $this->row_class;
  }
  private function buildAttributeQuery($attributes)
  {
    $parentSingular = inflector::singular($this->tables[$attributes->parentTableIndex]['tablename']);
    // This processing assumes some properties of the attribute tables - eg columns the data is stored in and deleted columns
    $query = "SELECT vt.".$parentSingular."_id as main_id,
      vt.text_value, vt.float_value, vt.int_value, vt.date_start_value, vt.date_end_value, vt.date_type_value,
      at.id, at.caption, at.data_type, at.termlist_id, at.multi_value ";
    $j=0;
    // table list
    $query .= " FROM ";
    for($i = 0; $i <= $attributes->parentTableIndex; $i++){
      if ($i == 0) {
          $query .= $this->tables[$i]['tablename']." lt".$i;
      } else { // making assumption to reduce the size of the query that all left outer join tables can be excluded, but make sure parent is included!
          if ($this->tables[$i]['join'] == null || $i == $attributes->parentTableIndex) {
            $query .= " INNER JOIN ".$this->tables[$i]['tablename']." lt".$i." ON (".$this->tables[$i]['tableKey']." = ".$this->tables[$i]['parentKey'];
              if($this->tables[$i]['where'] != null) {
                $query .= " AND ".preg_replace("/#this#/", "lt".$i, $this->tables[$i]['where']);
             }
              $query .= ") ";
          }
      }
    }
    $query .= " INNER JOIN ".$parentSingular."_attribute_values vt ON (vt.".$parentSingular."_id = "." lt".$attributes->parentTableIndex.".id and vt.deleted = FALSE) ";
    $query .= " INNER JOIN ".$parentSingular."_attributes at ON (vt.".$parentSingular."_attribute_id = at.id and at.deleted = FALSE) ";
    $query .= " INNER JOIN ".$parentSingular."_attributes_websites rt ON (rt.".$parentSingular."_attribute_id = at.id and rt.deleted = FALSE) ";
    // where list
  $previous=false;
  if($this->tables[0]['where'] != null) {
    $query .= " WHERE ".preg_replace("/#this#/", "lt0", $this->tables[0]['where']);
    $previous = true;
  }
  if($attributes->where != null) {
    $query .= ($previous ? " AND " : " WHERE ").$attributes->where;
  }
    $query .= " ORDER BY lt".$attributes->parentTableIndex.".id";
    return $query;
  }

  private function mergeParam($name, $display = '', $type = '', $allow_buffer='', $fieldname='', $alias='', $emptyvalue='', $description = '', $query='', $lookup_values='', $population_call='')
  {
    if (array_key_exists($name, $this->params))
    {
      if ($display != '') $this->params[$name]['display'] = $display;
      if ($type != '') $this->params[$name]['datatype'] = $type;
      if ($allow_buffer != '') $this->params[$name]['allow_buffer'] = $allow_buffer;
      if ($fieldname != '') $this->params[$name]['fieldname'] = $fieldname;
      if ($alias != '') $this->params[$name]['alias'] = $alias;
      if ($emptyvalue != '') $this->params[$name]['emptyvalue'] = $emptyvalue;
      if ($description != '') $this->params[$name]['description'] = $description;
      if ($query != '') $this->params[$name]['query'] = $query;
      if ($lookup_values != '') $this->params[$name]['lookup_values'] = $lookup_values;
      if ($population_call != '') $this->params[$name]['population_call'] = $population_call;
    }
    else
    {
      $this->params[$name] = array(
        'datatype'=>$type,
        'allow_buffer'=>$allow_buffer,
        'fieldname'=>$fieldname,
        'alias'=>$alias,
        'emptyvalue'=>$emptyvalue,
        'display'=>$display, 
        'description'=>$description, 
        'query' => $query, 
        'lookup_values' => $lookup_values,
        'population_call' => $population_call
      );
    }
  }
  
  /**
   * Merges a column definition pointed to by an XML reader into the list of columns.  
   */
  private function mergeXmlColumn($reader) {
    $name = $reader->getAttribute('name');
    if (!array_key_exists($name, $this->columns))
    {
      // set a default column setup
      $this->columns[$name] = array(
        'visible' => 'provisional_true',
        'img' => 'false',
        'autodef' => false
      );
    }
    // build a definition from the XML
    $def = array();
    if ($reader->moveToFirstAttribute()) {
      do {
        if ($reader->name!='name')
          $def[$reader->name] = $reader->value;
      } while ($reader->moveToNextAttribute());
    }
    // move back up to where we started
    $reader->moveToElement();
    $this->columns[$name] = array_merge($this->columns[$name], $def);
    // remember if we have info required to auto-build the column SQL, plus aggregate fields
    $this->hasColumnsSql = $this->hasColumnsSql || isset($this->columns[$name]['sql']); 
    $this->hasAggregates = $this->hasAggregates || (isset($this->columns[$name]['aggregate']) && $this->columns[$name]['aggregate']=='true'); 
  }

  private function mergeColumn($name, $display = '', $style = '', $feature_style='', $class='', $visible='', $img='', $orderby='', $mappable='', $autodef=true)
  {
    if (array_key_exists($name, $this->columns))
    {
      if ($display != '') $this->columns[$name]['display'] = $display;
      if ($style != '') $this->columns[$name]['style'] = $style;
      if ($feature_style != '') $this->columns[$name]['feature_style'] = $feature_style;
      if ($class != '') $this->columns[$name]['class'] = $class;
      if ($visible == 'false') {
        if($this->columns[$name]['visible'] != 'true') // allows a false to override a provisional_true, but not a true.
          $this->columns[$name]['visible'] = 'false'; 
      } else
        $this->columns[$name]['visible'] = 'true';
      if ($img == 'true' || $this->columns[$name]['img'] == 'true') $this->columns[$name]['img'] = 'true';
      if ($orderby != '') $this->columns[$name]['orderby'] = $orderby;
      if ($mappable != '') $this->columns[$name]['mappable'] = $mappable;
      if ($autodef != '') $this->columns[$name]['autodef'] = $autodef;
    }
    else
    {    
      $this->columns[$name] = array(
          'display' => $display,
          'style' => $style,
          'feature_style' => $feature_style,
          'class' => $class,
          'visible' => $visible == '' ? 'true' : $visible,
          'img' => $img == '' ? 'false' : $img,
          'orderby' => $orderby,
          'mappable' => empty($mappable) ? 'false' : $mappable,
          'autodef' => $autodef);
    }
  }

  private function setTable($tablename, $where)
  {
    $this->tables = array();
    $this->tableIndex = 0;
    $this->nextTableIndex = 1;
    $this->tables[$this->tableIndex] = array(
          'tablename' => $tablename,
          'parent' => -1,
          'parentKey' => '',
          'tableKey' => '',
          'join' => '',
        'attributes' => '',
          'where' => $where,
          'columns' => array());
  }

  private function setSubTable($tablename, $parentKey, $tableKey, $join, $where)
  {
    if($tableKey == ''){
      if($parentKey == 'id'){
        $tableKey = 'lt'.$this->nextTableIndex.".".(inflector::singular($this->tables[$this->tableIndex]['tablename'])).'_id';
      } else {
        $tableKey = 'lt'.$this->nextTableIndex.'.id';
      }
    } else {
      $tableKey = 'lt'.$this->nextTableIndex.".".$tableKey;
    }
    if($parentKey == ''){
      $parentKey = 'lt'.$this->tableIndex.".".(inflector::singular($tablename)).'_id';
    } else { // force the link as this table has foreign key to parent table, standard naming convention.
      $parentKey = 'lt'.$this->tableIndex.".".$parentKey;
    }
    $this->tables[$this->nextTableIndex] = array(
          'tablename' => $tablename,
           'parent' => $this->tableIndex,
          'parentKey' => $parentKey,
          'tableKey' => $tableKey,
           'join' => $join,
          'attributes' => '',
          'where' => $where,
           'columns' => array());
    $this->tableIndex=$this->nextTableIndex;
    $this->nextTableIndex++;
  }

  private function mergeTabColumn($name, $func = '', $display = '', $style = '', $feature_style = '', $class='', $visible='', $autodef=false)
  {
    $found = false;
    for($r = 0; $r < count($this->tables[$this->tableIndex]['columns']); $r++){
      if($this->tables[$this->tableIndex]['columns'][$r]['name'] == $name) {
        $found = true;
        if($func != '') {
          $this->tables[$this->tableIndex]['columns'][$r]['func'] = $func;
        }
      }
    }
    if (!$found) {
      $this->tables[$this->tableIndex]['columns'][] = array(
            'name' => $name,
            'func' => $func);
      if($display == '') {
        $display = $this->tables[$this->tableIndex]['tablename']." ".$name;
      }
    }
    // force visible if the column is already declared as visible. This prevents the id field from being forced to hidden if explicitly included.
    if (isset($this->columns['lt'.$this->tableIndex."_".$name]['visible']) && $this->columns['lt'.$this->tableIndex."_".$name]['visible']=='true')
      $visible = 'true';
    $this->mergeColumn('lt'.$this->tableIndex."_".$name, $display, $style, $feature_style, $class, $visible, 'false', $autodef);
  }

  private function setMergeTabColumn($name, $tablename, $separator, $where = '', $display = '')
  {
    // in this case the data for the column in merged into one, if there are more than one records
    // To do this we highjack the attribute handling functionality.
    $tableKey = (inflector::singular($this->tables[$this->tableIndex]['tablename'])).'_id';

    $thisDefn = new stdClass;
    $thisDefn->caption = 'caption';
    $thisDefn->main_id = $tableKey; // main_id is the name of the column in the subquery holding the PK value of the parent table.
     $thisDefn->parentKey = "lt".$this->tableIndex."_id"; // parentKey holds the column in the main query to compare the main_id against.
    $thisDefn->id = 'id'; // id is the name of the column in the subquery holding the attribute id.
     $thisDefn->separator = $separator;
    $thisDefn->hideVagueDateFields = 'false';
     $thisDefn->columnPrefix = 'merge_'.count($this->attributes);

    if($display == ''){
      $display = $tablename.' '.$name;
    }

    $thisDefn->query =  "SELECT ".$tableKey.", '".$display."' as caption, '' as id, 'T' as data_type, ".$name." as text_value, 't' as multi_value FROM ".$tablename.($where == '' ? '' : " WHERE ".$where);
    $this->attributes[] = $thisDefn;
    // Make sure id column of parent table is in list of columns returned from query.
    $this->mergeTabColumn('id', '', '', '', '', 'false', true);
  }

  private function setAttributes($where, $separator, $hideVagueDateFields, $meaningIdLanguage)
  {
    $thisDefn = new stdClass;
    $thisDefn->caption = 'caption'; // caption is the name of the column in the subquery holding the attribute caption.
    $thisDefn->main_id = 'main_id'; // main_id is the name of the column in the subquery holding the PK value of the parent table.
     $thisDefn->parentKey = "lt".$this->tableIndex."_id"; // parentKey holds the column in the main query to compare the main_id against.
    $thisDefn->id = 'id'; // id is the name of the column in the subquery holding the attribute id.
    $thisDefn->separator = $separator;
    $thisDefn->hideVagueDateFields = $hideVagueDateFields;
    $thisDefn->columnPrefix = 'attr_'.$this->tableIndex.'_';
    // folowing is used the query builder only
    $thisDefn->parentTableIndex = $this->tableIndex;
    $thisDefn->where = $where;
    $thisDefn->meaningIdLanguage = $meaningIdLanguage;
    $thisDefn->query = $this->buildAttributeQuery($thisDefn);
    $this->attributes[] = $thisDefn;
    // Make sure id column of parent table is in list of columns returned from query.
    $this->mergeTabColumn('id', '', '', '', '', 'false', true);
  }

  private function setDownload($mode)
  {
    $this->download = $mode;
  }

 /**
  * Infers parameters such as column names and parameters from the query string.
  * Column inference can handle queries where there is a nested select provided it has a
  * matching from. Commas that are part of nested selects or function calls are ignored
  * provided they are enclosed in brackets.
  */
  private function inferFromQuery()
  {
    // Find the columns we're searching for - nested between a SELECT and a FROM.
    // To ensure we can detect the words FROM, SELECT and AS, use a regex to wrap
    // spaces around them, then can do a regular string search
    $this->query=preg_replace("/\b(select)\b/i", ' select ', $this->query);
    $this->query=preg_replace("/\b(from)\b/i", ' from ', $this->query);
    $this->query=preg_replace("/\b(as)\b/i", ' as ', $this->query);
    $i0 = strpos($this->query, ' select ') + 7;
    $nesting = 1;
    $offset = $i0;
    do {
      $nextSelect = strpos($this->query, ' select ', $offset);
      $nextFrom = strpos($this->query, ' from ', $offset);
      if ($nextSelect !== false && $nextSelect < $nextFrom) {
        //found start of sub-query
        $nesting++;
        $offset = $nextSelect + 7;
      } else {
        $nesting--;
        if ($nesting != 0) {
          //found end of sub-query
          $offset = $nextFrom + 5;
        }
      }
    }
    while ($nesting > 0);

    $i1 = $nextFrom - $i0;
    // get the columns list, ignoring the marker to show where additional columns can be inserted
    $colString = str_replace('#fields#', '', substr($this->query, $i0, $i1));

    // Now divide up the list of columns, which are comma separated, but ignore
    // commas nested in brackets
    $colStart = 0;
    $nextComma =  strpos($colString, ',', $colStart);
    while ($nextComma !== false)
    {//loop through columns
      $nextOpen =  strpos($colString, '(', $colStart);
      while ($nextOpen !== false && $nextComma !==false && $nextOpen < $nextComma)
      { //skipping commas in brackets
        $offset = $this->strposclose($colString, $nextOpen) + 1;
        $nextComma =  strpos($colString, ',', $offset);
        $nextOpen =  strpos($colString, '(', $offset);
      }
      if ($nextComma !== false) {
        //extract column and move on to next
        $cols[] = substr($colString, $colStart, ($nextComma - $colStart));
        $colStart = $nextComma + 1;
        $nextComma =  strpos($colString, ',', $colStart);
     }
    }
    //extract final column
    $cols[] = substr($colString, $colStart);
    
    // We have cols, which may either be of the form 'x', 'table.x' or 'x as y'. Either way the column name is the part after the last 
    // space and full stop.
    foreach ($cols as $col)
    {
      // break down by spaces
      $b = explode(' ' , trim($col));
      // break down the part after the last space, by 
      $c = explode('.' , array_pop($b));
      $d = array_pop($c);
      $this->mergeColumn(trim($d));
    }

    // Okay, now we need to find parameters, which we do with regex.
    preg_match_all('/#([a-z0-9_]+)#%/i', $this->query, $matches);
    // Here is why I remember (yet again) why I hate PHP...
    foreach ($matches[1] as $param)
    {
      $this->mergeParam($param);
    }
  }

  /**
   * Returns the numeric position of the closing bracket matching the opening bracket
   * @param <string> $haystack The string to search
   * @param <int> $open The numeric position of the opening bracket
   * @return The numeric position of the closing bracket or false if not present
   */
  private function strposclose($haystack, $open) {
    $nesting = 1;
    $offset = $open + 1;
    do {
      $nextOpen =  strpos($haystack, '(', $offset);
      $nextClose =  strpos($haystack, ')', $offset);
      if ($nextClose === false) return false;
      if ($nextOpen !== false and $nextOpen < $nextClose) {
        $nesting++;
        $offset = $nextOpen + 1;
      } else {
        $nesting--;
        $offset = $nextClose + 1;
      }
    }
    while ($nesting > 0);
    return $offset -1;
  }
}