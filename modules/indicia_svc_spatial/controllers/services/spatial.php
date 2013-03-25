<?php
class Spatial_Controller extends Service_Base_Controller {

  /**
   * Handle a service request to convert a spatial reference into WKT representing the reference
   * using the internal SRID (normally spherical mercator since it is compatible with Google Maps).
   * The response is in JSON. Provide a callback in the GET request to use JSONP.
   */
  public function sref_to_wkt()
  {
    try
    {
      $r = array('wkt'=>spatial_ref::sref_to_internal_wkt($_GET['sref'], $_GET['system']));
      if (array_key_exists('mapsystem', $_GET)){
        $r['mapwkt'] = spatial_ref::internal_wkt_to_wkt($r['wkt'], $_GET['mapsystem']);
      }
      $r = json_encode($r);
      // enable a JSONP request
      if (array_key_exists('callback', $_GET)){
        $r = $_GET['callback']."(".$r.")";
      }
      echo $r;
    }
    catch (Exception $e)
    {
      $this->handle_error($e);
    }
  }

  /**
   * Handle a service request to convert a WKT representing the reference
   * using the internal SRID (normally spherical mercator since it is compatible with Google Maps)
   * into a spatial reference, though this can optionally be overriden by providing a wktsystem.
   * Returns the sref, plus new WKTs representing the returned sref in the internal SRID and an optional map system.
   * Note that if you pass in a point and convert it to a grid square, then the returned
   * wkts will reflect the grid square not the point. GET parameters allowed are wkt, system, precision
   * wktsystem, mapsystem, and callback (for JSONP).
   */
  public function wkt_to_sref()
  {
    try
    {
      if (array_key_exists('precision',$_GET))
        $precision = $_GET['precision'];
      else
        $precision = null;
      if (array_key_exists('metresAccuracy',$_GET))
        $metresAccuracy = $_GET['metresAccuracy'];
      else
        $metresAccuracy = null;
      if (array_key_exists('output',$_GET))
        $output = $_GET['output'];
      else
        $output = null;
      if (array_key_exists('wktsystem',$_GET))
        $wkt = spatial_ref::wkt_to_internal_wkt($_GET['wkt'], $_GET['wktsystem']);
      else
        $wkt = $_GET['wkt'];
      $sref = spatial_ref::internal_wkt_to_sref($wkt, $_GET['system'], $precision, $output, $metresAccuracy);
      // Note we also need to return the wkt of the actual sref, which may be a square now.
      $wkt = spatial_ref::sref_to_internal_wkt($sref, $_GET['system']);
      $r = array('sref'=>$sref,'wkt'=>$wkt);
      if (array_key_exists('mapsystem', $_GET)){
        $r['mapwkt'] = spatial_ref::internal_wkt_to_wkt($r['wkt'], $_GET['mapsystem']);
      }
      $r = json_encode($r);
      // enable a JSONP request
      if (array_key_exists('callback', $_GET)){
        $r = $_GET['callback']."(".$r.")";
      }
      echo $r;
    }
    catch (Exception $e)
    {
      $this->handle_error($e);
    }
  }

  /**
   * Allow a service request to triangulate between 2 systems. GET parameters are:
   * 	from_sref
   * 	from_system
   * 	to_system
   *  to_precision (optional)
   */
  public function convert_sref()
  {
    try
    {
      $wkt = spatial_ref::sref_to_internal_wkt($_GET['from_sref'], $_GET['from_system']);
      if (array_key_exists('precision',$_GET))
        $precision = $_GET['precision'];
      else
        $precision = null;
      if (array_key_exists('metresAccuracy',$_GET))
        $metresAccuracy = $_GET['metresAccuracy'];
      else
        $metresAccuracy = null;
      echo spatial_ref::internal_wkt_to_sref($wkt, $_GET['to_system'], $precision, null, $metresAccuracy);
    }
    catch (Exception $e)
    {
      $this->handle_error($e);
    }
  }

  /**
   * Service method to buffer a provided wkt.
   * Provide GET parameters for wkt (string) and buffer (a number of metres). Will 
   * return the well known text for the buffered geometry. 
   * If a callback function name is given in the GET parameters then returns a JSONP
   * response with a json object that has a single response property.
   */
  public function buffer()
  {
    $params = array_merge($_GET, $_POST);
    if (array_key_exists('wkt', $params) && array_key_exists('buffer', $params)) {
      if ($params['buffer']==0)
        // no need to buffer if width set to zero
        $r = $params['wkt'];
      else {
        $db = new Database;
        $wkt = $params['wkt'];
        $buffer = $params['buffer'];
        kohana::log('debug', "SELECT st_astext(st_buffer(st_geomfromtext('$wkt'),$buffer)) AS wkt;");
        $result = $db->query("SELECT st_astext(st_buffer(st_geomfromtext('$wkt'),$buffer)) AS wkt;")->current();
        $r = $result->wkt;
      }
    } else {
      $r = 'No wkt or buffer to process';
    }
    if (array_key_exists('callback', $_REQUEST))
    {
      $json=json_encode(array('response'=>$r));
      $r = $_REQUEST['callback']."(".$json.")";
      $this->content_type = 'Content-Type: application/javascript';
    }
    echo $r;    
  }


}
?>
