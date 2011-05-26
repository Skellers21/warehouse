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
 * @package	Core
 * @subpackage Controllers
 * @author	Indicia Team
 * @license	http://www.gnu.org/licenses/gpl.html GPL
 * @link 	http://code.google.com/p/indicia/
 */

/**
 * Controller providing CRUD access to the surveys list.
 *
 * @package	Core
 * @subpackage Controllers
 */
class Survey_Controller extends Gridview_Base_Controller {

  public function __construct() {
    parent::__construct('survey', 'gv_survey', 'survey/index');
    $this->columns = array(
      'id'          => '',
      'title'       => '',
      'description' => '',
      'website'     => ''
    );
    $this->pagetitle = "Surveys";
    $this->auth_filter = $this->gen_auth_filter;
  }
  
  /**
   * Override the default action columns for a grid - just an edit link - to 
   * add a link to the attributes list for othe survey.
   */
  protected function get_action_columns() {
    return array(
        'edit' => $this->controllerpath."/edit/£id£",
        'setup attributes' => "/attribute_by_survey/£id£?type=sample"
    );
  }

  /**
   * Check access to a survey when editing. The survey's website must be in the list
   * of websites the user is authorised to administer.   
   */
  protected function record_authorised ($id)
  {
    if (!is_null($id) AND !is_null($this->auth_filter))
    {
      $survey = new Survey_Model($id);
      return (in_array($survey->website_id, $this->auth_filter['values']));
    }
    return true;
  }
}

?>
