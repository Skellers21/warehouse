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
 * @package	Services
 * @subpackage Data
 * @author	Indicia Team
 * @license	http://www.gnu.org/licenses/gpl.html GPL
 * @link 	http://code.google.com/p/indicia/
 */

/**
* Class to provide access to reports generated by the Indicia core.
*
* A report will have a number of parameters that need to be completed by the requester. Because
* this interface is designed to be used by both the core module and the site module, we cannot
* directly request this information. As such, we do the following:
* <ol>
* <li> Grab the report and parse it for parameters. </li>
* <li> Cache the report (if it didn't exist on the server already) and assign it a unique id,
* which we store temporarily in the Kohana cache. </li>
* <li> Send a response back to the requester, inviting them to fill in the parameters. This
* reponse will include the id generated in step 3. </li>
* <li> The requester sends back the requested parameters, which are checked against the cache to
* ensure they're all there. If not, repeat these steps. </li>
* <li> The core retrieves the report from cache, merges the parameters in and executes the query
* against the core database. Results are formatted and returned to the requester. </li>
* </ol>
*
* We should also allow submission of parameters with the report, or a combination of this and
* requesting them as we go.
*
* We use XML reports roughly in keeping with the standard defined in Recorder (though with limited
* complexity compared to recorder's options). However, this module is written to easily allow
* reports written in other formats, and in keeping with the rest of the project we use JSON as our
* principal language for network communication - e.g. for parameter requests, delivery, and other
* messages.
*/

class Report_Controller extends Data_Service_Base_Controller {

  private $reportEngine;

  public function __construct($suppress = false)
  {
    $this->authenticate('read');
    $this->reportEngine = new ReportEngine(array($this->website_id));
    parent::__construct();
  }

  /**
  * Access the report - probably we will use routing to direct /report directly to /report/access
  * We can specify a request in a number of ways:
  * <ul>
  * <li> Predefined report on the core module. </li>
  * <li> Predefined report elsewhere (URI given). </li>
  * <li> Report passed with the query. </li>
  * </ul>
  * We also need to perform authentication at a read level for the data we're trying to access
  * (this might be fun, given the low level that the reports run at).
  *
  */
  public function requestReport()
  { 
    try {
      $this->entity = 'record';
      $this->handle_request();
      $mode = $this->get_output_mode();
      switch($mode) {
        case 'csv' :
          $extension='csv';
          break;
        case 'xml' :
          $extension='xml';
          break;
        default : $extension='txt';
      }
      if (array_key_exists('filename', $_GET))
        $downloadfilename = $_GET['filename'];
      elseif (array_key_exists('filename', $_POST))
        $downloadfilename = $_POST['filename'];
      else
        $downloadfilename='download';
      header('Content-Disposition: attachment; filename="'.$downloadfilename.'.'.$extension.'"');
      if ($mode=='csv') {
        // prepend a byte order marker, so Excel recognises the CSV file as UTF8
        echo chr(hexdec('EF')) . chr(hexdec('BB')) . chr(hexdec('BF'));
      }
      $this->send_response();
    }
    catch (Exception $e) {
      $this->handle_error($e);
    }
  }

  public function report_list() {
    echo json_encode($this->internal_report_list('/'));
  }

  public function internal_report_list($path) {
    $files = array();
    $fullPath = DOCROOT . Kohana::config('indicia.localReportDir') . $path;
    if (!is_dir($fullPath))
      throw new Exception("Failed to open reports folder ".DOCROOT . Kohana::config('indicia.localReportDir') . $path);
    $dir = opendir($fullPath);
    
    while (false !== ($file = readdir($dir))) {
      if ($file != '.' && $file != '..' && $file != '.svn' && is_dir("$fullPath$file"))
        $files[$file] = array('type'=>'folder','content'=>$this->internal_report_list("$path$file/"));
      elseif (substr($file, -4)=='.xml') {
        $metadata = XMLReportReader::loadMetadata("$fullPath$file");
        $file = basename($file, '.xml');
        $reportPath = ltrim("$path$file", '/');
        $files[$file] = array('type'=>'report','title'=>$metadata['title'],'description'=>$metadata['description'],'path'=>$reportPath);
      }
    }
    closedir($dir);
    return $files;
  }

  /**
   * Actually perform the task of reading the records. Called by the base class handle_read
   * method when it is ready to receive the data. As well as the returned records array, sets
   * $this->view_columns to the list of columns.
   *
   * @return array Array of records.
   */
  protected function read_records() {
    $src = $_REQUEST['reportSource'];
    $rep = $_REQUEST['report'];
    $params = json_decode($this->input->post('params', '{}'), true);
    // NB that for JSON requests (eg from datagrids) the parameters do not get posted, but appear in the url.
    if(empty($params)){
      // no params posted so look on URL
      $params = $this->getRawGET();
    }
    $data=$this->reportEngine->requestReport($rep, $src, 'xml', $params);
    if (isset($data['content']['columns']))
      $this->view_columns = $data['content']['columns'];
    if (isset($data['content']['data']))
      return $data['content']['data'];
    else 
      // A parameter request, since the report is being called without sufficient info
      return $data['content'];
  }
  
  /**
   * Report parameters can contain spaces in the names, for example smpattr:CMS User ID=3, which means filter on the attribute
   * called CMS User ID for value 3. Unfortunately PHP mangles incoming $_GET key names, replacing spaces and dots with underscores. So
   * rather than use $_GET we have to try the raw input from the $_SERVER variable.
   * @return array Assoc array matching $_GET without the name mangling.
   */
  private function getRawGET() {
    $vars = array();
    if (!empty($_SERVER['QUERY_STRING'])) {
      $pairs = explode('&', $_SERVER['QUERY_STRING']);
      foreach ($pairs as $pair) {
        if (!empty($pair)) {
          $nv = explode("=", $pair);
          $name = urldecode($nv[0]);
          $value = urldecode($nv[1]);
          $vars[$name] = $value;
        }
      }
    }
    return $vars;
}

  /**
   * When a report was requested, but the report needed parameter inputs which were requested,
   * this action allows the caller to restart the report having obtained the parameters.
   *
   * @param int $cacheid Id of the report, returned by the original call to requestReport.
   */
  public function resumeReport($cacheid = null)
  {
    // Check we have both a uid and a set of parameters given
    $uid = $cacheid ? $cacheid : $this->input->post('uid', null);
    $params = json_decode($this->input->post('params', '{}'), true);

    return $this->formatJSON($this->reportEngine->resumeReport($uid, $params));
  }

  public function listLocalReports($detail = ReportReader::REPORT_DESCRIPTION_DEFAULT)
  {
    return $this->formatJSON($this->reportEngine->listLocalReports($detail));
  }

  private function formatJSON($stuff)
  {
    // Set the correct MIME type
    header("Content-Type: application/json");
    echo json_encode($stuff);
  }
  
  protected function record_count() {
    return $this->reportEngine->record_count();
  }
}