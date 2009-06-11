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
 */

/**
 * Helper methods for accessing the Google AJAX Search API
 */

google.load("search", "1");

/**
* Function to decode an entered postcode using the Google Search API
* to get locality information and lat/long info.
* postcodeField - The id of the control which contains the postcode
* srefField - Optional, the id of the control which receives the lat/long
* systemField - Optional, the id of the control identifying the system of the spatial reference
* geomField - Optional, the id of the control which receives the geometry (WKT).
* addressField - Optional, the id of the control which receives the address locality information.
*/
function decodePostcode(postcodeField, srefField, systemField, geomField, addressField) {
  usePointFromPostcode(
      document.getElementById(postcodeField).value,
      function(place) {
        if (addressField) {
          document.getElementById(addressField).value="\n" + place.city + "\n" + place.region;
        }
        if (srefField) {
          document.getElementById(srefField).value=place.lat + ', ' + place.lng;
        }
        if (systemField) {
          document.getElementById(systemField).value='4326'; // SRID for WGS84 lat long
        }
        if (geomField) {
          document.getElementById(geomField).value='POINT(' + place.lng + ' ' + place.lat + ')';
        }
      }
  );
};

// Private method
function usePointFromPostcode(postcode, callbackFunction) {
  var localSearch = new google.search.LocalSearch();
  localSearch.setSearchCompleteCallback(null,
    function() {
      if (localSearch.results[0])
      {
        callbackFunction(localSearch.results[0]);
      }else{
        alert("Postcode not found!");
      }
    });

  localSearch.execute(postcode + ", UK");
};
