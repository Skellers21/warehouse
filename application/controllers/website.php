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
 * Controller providing CRUD access to the list of websites registered on this Warehouse instance.
 *
 * @package	Core
 * @subpackage Controllers
 */
class Website_Controller extends Gridview_Base_Controller
{
  /**
   * Constructor
   */
  public function __construct()
  {
    parent::__construct('website', 'website', 'website/index');

    $this->columns = array(
        'id'          => '',
        'title'       => '',
        'description' => '',
        'url'         => ''
    );

    $this->pagetitle = "Websites";
    // because the website id is the pk, we need a modified version of the general authorisation filter
    $this->auth_filter = $this->gen_auth_filter;
    if (isset($this->auth_filter['field']) && $this->auth_filter['field']=='website_id')
      $this->auth_filter['field']='id';
  }
    
  /**
   * Returns an array of all values from this model and its super models ready to be 
   * loaded into a form. For this controller, we need to double up the password field.
   */
  protected function getModelValues() {
    $r = parent::getModelValues();    
    $r['password2']=$r['website:password'];
    return $r;  
  }
  
  /**
   * If trying to edit an existing website record, ensure the user has rights to this website.
   */
  public function record_authorised ($id) {
    if (!is_null($id) AND !is_null($this->auth_filter)) {
      return (in_array($id, $this->auth_filter['values']));
    }
    return true;
  }

}

?>
