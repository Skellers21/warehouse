/* Indicia, the OPAL Online Recording Toolkit.
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
 * Method which copies the features on a layer into a WKT in a form input.
 */
function storeGeomsInHiddenInput(layer, inputId) {
  "use strict";
  var geoms=[], featureClass='', geom;
  $.each(layer.features, function(i, feature) {
    if (feature.geometry.CLASS_NAME.contains('Multi')) {
      geoms = geoms.concat(feature.geometry.components);
    } else {
      geoms.push(feature.geometry);
    }
  });
  if (geoms.length===0) {
    $('#'+inputId).val('');
  } else {
    if (geoms[0].CLASS_NAME === 'OpenLayers.Geometry.Polygon') {
      geom = new OpenLayers.Geometry.MultiPolygon(geoms);
    } else if (geoms[0].CLASS_NAME === 'OpenLayers.Geometry.LineString') {
      geom = new OpenLayers.Geometry.MultiLineString(geoms);
    } else if (geoms[0].CLASS_NAME === 'OpenLayers.Geometry.Point') {
      geom = new OpenLayers.Geometry.MultiPoint(geoms);
    }
    if (layer.map.projection.getCode() != 'EPSG:3857') {
      geom.transform(layer.map.projection, new OpenLayers.Projection('EPSG:3857'));
    }
    $('#'+inputId).val(geom.toString());
  }
}

function bufferFeature(feature) {
  if (typeof feature.geometry!=="undefined" && feature.geometry!==null) {
    $.ajax({
      url: mapDiv.settings.indiciaSvc + 'index.php/services/spatial/buffer'
          +'?wkt='+feature.geometry.toString()+'&buffer='+$('#geom_buffer').val()+'&callback=?',
      dataType: 'json',
      success: function(buffered) {
        var buffer = new OpenLayers.Feature.Vector(OpenLayers.Geometry.fromWKT(buffered.response));
        // link the feature to its buffer, for easy removal
        feature.buffer = buffer;
        bufferLayer.addFeatures([buffer]);
      },
      async: false
    });
  }
}

function rebuildBuffer(div) {
  if (!$('#geom_buffer').val().match(/^\d+$/)) {
    $('#geom_buffer').val(0);
  }
  bufferLayer.removeAllFeatures();
  // re-add each object from the edit layer using the spatial buffering service
  $.each(div.map.editLayer.features, function(idx, feature) {
    bufferFeature(feature);
  });
}

function storeGeomsInForm(div) {
  if (typeof bufferLayer==="undefined") {
    storeGeomsInHiddenInput(div.map.editLayer, 'hidden-wkt');
  } else {
    storeGeomsInHiddenInput(div.map.editLayer, 'orig-wkt');
    storeGeomsInHiddenInput(bufferLayer, 'hidden-wkt');
  }
}

function enableBuffering() {
  // add a mapinitialisation hook to add a layer for buffered versions of polygons
  mapInitialisationHooks.push(function(div) {
    var style = $.extend({}, div.settings.boundaryStyle);
    style.strokeDashstyle = 'dash';
    style.strokeColor = '#777777';
    style.fillOpacity = 0.2;
    style.fillColor = '#777777';
    bufferLayer = new OpenLayers.Layer.Vector(
        'buffer outlines',
        {style: style, 'sphericalMercator': true, displayInLayerSwitcher: false}
    );
    div.map.addLayer(bufferLayer);
    div.map.editLayer.events.register('featureadded', div.map.editLayer, function(evt) {
      bufferFeature(evt.feature);
    });
    div.map.editLayer.events.register('featuresremoved', div.map.editLayer, function(evt) {
      buffers = [];
      $.each(evt.features, function(idx, feature) {
        if (typeof feature.buffer!=="undefined") {
          buffers.push(feature.buffer);
        }
      });
      bufferLayer.removeFeatures(buffers);
    });
    // When exiting the buffer input, recreate all the buffer polygons.
    $('#geom_buffer').blur(function() {rebuildBuffer(div);});
    $('#run-report').click(function(evt) {
      // rebuild the buffer if the user is changing it.
      if (document.activeElement.id==='geom_buffer') {
        rebuildBuffer(div);
      }
      storeGeomsInForm(div);      
    });
  });
}