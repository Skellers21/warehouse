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
 * @package Client
 * @author  Indicia Team
 * @license http://www.gnu.org/licenses/gpl.html GPL 3.0
 * @link    http://code.google.com/p/indicia/
 */
 
var loadFilter, applyFilterToReports;

jQuery(document).ready(function($) {
  "use strict";
  indiciaData.filter = {"def":{},"id":null,"title":null};
  
  function disableIfPresent(context, fields, ctrlIds) {
    var disable=false;
    $.each(fields, function(idx, field) {
      if (context && context[field]) {
        disable=true;
      }
    });
    $.each(ctrlIds, function(idx, ctrlId) {
      if (disable) {
        $(ctrlId).attr('disabled', true);
      } else {
        $(ctrlId).removeAttr('disabled');
      }
    });
  }
  
  function disableSourceControlsForContext(type, context) {
    if (context && context[type+'_list_op'] && context[type+'_list']) {
      $('#filter-'+type+'s-mode').attr('disabled', true);
      if (context[type+'_list_op']==='not in') {
        // website, survey or form must not be in a list, so disable the list so that they can't be unchecked
        $('#'+type+'-list-checklist input').removeAttr('disabled');
        $.each(context[type+'_list'].split(','), function(idx, website_id) {
          $('#check-'+type+'_id').attr('disabled', true);
        });
      } else {
        // website, survey or form must be in a list, so may as well disable the others
        $('#'+type+'-list-checklist input').attr('disabled', true);
        $.each(context[type+'_list'].split(','), function(idx, website_id) {
          $('#check-'+type+'_id').removeAttr('disabled');
        });
      }
    } else {
      $('#filter-'+type+'s-mode').removeAttr('disabled');
      $('#'+type+'-list-checklist input').removeAttr('disabled');
    }
  }
  
  function limitSourceToContext (type, filterDef, context) {
    var context_idlist, idlist;
    if (context[type + '_list']) {
      if (!filterDef[type + '_list']) {
        filterDef[type + '_list']=context[type + '_list'];
      } else if (context[type + '_list_op']==='not in') {
        // if excluding these websites, then we combine the selected filter list with the context list            
        filterDef[type + '_list']=filterDef[type + '_list'] + ',' + context[type + '_list'];
      } else {
        // including context websites, so we want an intersection
        context_idlist=context[type + '_list'].split(',');
        idlist=$.grep(filterDef[type + '_list'].split(','), function(elem) {
          return $.inArray(elem, context_idlist)!==-1;
        });
        filterDef[type + '_list']=idlist.join(',');
      }
    }
  }
  
  // functions that drive each of the filter panes, e.g. to obtain the description from the controls.
  var paneObjList = {
    what:{
      getDescription:function() {
        var groups=[], taxa=[], r=[];
        if (typeof indiciaData.filter.def.taxon_group_names!=="undefined") {
          $.each(indiciaData.filter.def.taxon_group_names, function(idx, group) {
            groups.push(group);
          });
        }
        if (typeof indiciaData.filter.def.taxa_taxon_list_names!=="undefined") {
          $.each(indiciaData.filter.def.taxa_taxon_list_names, function(idx, taxon) {
            taxa.push(taxon);
          });
        }
        if (groups.length>0) {
          r.push(groups.join(', '));
        }
        if (taxa.length>0) {
          r.push(taxa.join(', '));
        }
        return r.join('<br/>');
      },
      limitToContext:function(filterDef,context) {
        var idlist, context_idlist;
        if (context.taxon_group_list) {
          if (filterDef.taxon_group_list) {
            context_idlist=context.taxon_group_list.split(',');
            idlist=$.grep(filterDef.taxon_group_list.split(','), function(elem) {
              return $.inArray(elem, context_idlist)!==-1;
            });
            filterDef.taxon_group_list=idlist.join(',');
            $.each(filterDef.taxon_group_names, function(id, name) {
              if ($.inArray(id, context_idlist)===-1) {
                delete filterDef.taxon_group_names[id];
              }
            });
            
          } else {
            filterDef.taxon_group_list=context.taxon_group_list;
            filterDef.taxon_group_names=$.extend({}, context.taxon_group_names);
          }
        } else if (context.taxa_taxon_list_list) {
          // No point having any sort of group level filter
          filterDef.taxon_group_list='';
          filterDef.taxon_group_names={}; 
          // the species that remain must be an intersection of the context and the picked list
          if (filterDef.taxa_taxon_names_list) {
            context_idlist=context.taxa_taxon_names_list.split(',');
            idlist=$.grep(filterDef.taxa_taxon_names_list.split(','), function(elem) {
              return $.inArray(elem, context_idlist)!==-1;
            });
            filterDef.taxa_taxon_names_list=idlist.join(',');
            $.each(filterDef.taxa_taxon_list_names, function(id, name) {
              if (!$.inArray(id, context_idlist)===-1) {
                delete filterDef.taxa_taxon_list_names[id];
              }
            });
          } else {
            filterDef.taxa_taxon_names_list=context.taxa_taxon_names_list;
            filterDef.taxa_taxon_list_names=$.extend({}, context.taxa_taxon_list_names);
          }
        }
      },
      applyFormToDefinition:function() {
        // don't send unnecessary stuff
        delete indiciaData.filter.def['taxon_group_list:search'];
        delete indiciaData.filter.def['taxon_group_list:search:title'];
        delete indiciaData.filter.def['taxa_taxon_list_list:search'];
        delete indiciaData.filter.def['taxa_taxon_list_list:search:title'];
        // reset the list of group names and species
        indiciaData.filter.def.taxon_group_names={};
        indiciaData.filter.def.taxa_taxon_list_names={};
        // if nothing selected, clean up the def
        if ($('input[name=taxon_group_list[]]').length===0) {
          indiciaData.filter.def.taxon_group_list='';
        } else {
          // store the list of names in the def, though not used for the report they save web service hits later
          $.each($('input[name=taxon_group_list[]]'), function(idx, ctrl) {
            indiciaData.filter.def.taxon_group_names[$(ctrl).val()] = $.trim($(ctrl).parent().text());
          });
        }
        if ($('input[name=taxa_taxon_list_list[]]').length===0) {
          indiciaData.filter.def.taxa_taxon_list_list='';
        } else {
          // store the list of names in the def, though not used for the report they save web service hits later
          $.each($('input[name=taxa_taxon_list_list[]]'), function(idx, ctrl) {
            indiciaData.filter.def.taxa_taxon_list_names[$(ctrl).val()] = $.trim($(ctrl).parent().text());
          });
        }
      },
      loadForm:function(context) {
        if (context && context.taxa_taxon_list_list) {
          // got a species level context. So may as well disable the group level tab, it won't be useful.
          $('#what-filter-instruct').hide();
          $( "#what-tabs" ).tabs("option", "active", 1);
          $( "#what-tabs" ).tabs("option", "disabled", [0]);
          $('input#taxon_group_list\\:search\\:title').unsetExtraParams('query');
          $('input#taxa_taxon_list_list\\:search\\:taxon').setExtraParams({"query":'{"in":{"id":['+context.taxa_taxon_list_list+']}}'});
          $('#species-tab .context-instruct').show();
        } else {
          $('#what-filter-instruct').show();
          $( "#what-tabs" ).tabs('enable', 0);
          $( "#what-tabs" ).tabs("option", "active", 0);
          $('input#taxa_taxon_list_list\\:search\\:taxon').unsetExtraParams('query');
          if (context && context.taxon_group_list) {
            $('input#taxon_group_list\\:search\\:title').setExtraParams({"query":'{"in":{"id":['+context.taxon_group_list+']}}'});
            $('#species-group-tab .context-instruct').show();
          }
        }
        // need to load the sub list control for taxon groups.
        $('#taxon_group_list\\:sublist').children().remove();
        if (typeof indiciaData.filter.def.taxon_group_names!=="undefined") {
          $.each(indiciaData.filter.def.taxon_group_names, function(id, name) {
            $('#taxon_group_list\\:sublist').append('<li class="ui-widget-content ui-corner-all"><span class="ind-delete-icon"> </span>' + name + 
                '<input type="hidden" value="' + id + '" name="taxon_group_list[]"/></li>');
          });
        }
        $('#taxa_taxon_list_list\\:sublist').children().remove();
        if (typeof indiciaData.filter.def.taxa_taxon_list_names!=="undefined") {
          $.each(indiciaData.filter.def.taxa_taxon_list_names, function(id, name) {
            $('#taxa_taxon_list_list\\:sublist').append('<li class="ui-widget-content ui-corner-all"><span class="ind-delete-icon"> </span>' + name + 
                '<input type="hidden" value="' + id + '" name="taxa_taxon_list_list[]"/></li>');
          });
        }
        // these auto-disable on form submission
        $('#taxon_group_list\\:search\\:title').removeAttr('disabled');
        $('#taxa_taxon_list_list\\:search\\:taxon').removeAttr('disabled');
      }
    },
    when:{
      getDescription:function() {
        var r=[];
        if (indiciaData.filter.def.date_from && indiciaData.filter.def.date_to) {
          r.push('Records between ' + indiciaData.filter.def.date_from + ' and ' + indiciaData.filter.def.date_to);
        } else if (indiciaData.filter.def.date_from) {
          r.push('Records on or after ' + indiciaData.filter.def.date_from);
        } else if (indiciaData.filter.def.date_to) {
          r.push('Records on or before ' + indiciaData.filter.def.date_to);
        }
        if (indiciaData.filter.def.date_age) {
          r.push('Last ' + indiciaData.filter.def.date_age);
        }
        return r.join('<br/>');
      },
      limitToContext:function(filterDef,context) {
        // context limits earliest date
        if (context.date_from && (!filterDef.date_from || dateStrIsGreater(context.date_from, filterDef.date_from))) {
          filterDef.date_from = context.date_from; 
        }
        // context limits latest date
        if (context.date_to && (!filterDef.date_to || dateStrIsGreater(filterDef.date_to, context.date_to))) {
          filterDef.date_to = context.date_to; 
        }
        // context limits age
        if (context.date_age && (!filterDef.date_age || ageStrIsGreater(filterDef.date_age, context.date_age))) {
          filterDef.date_age = context.date_age; 
        }
      },
      loadForm:function(context) {
        // limit the date range that can be selected to the context's date range
        var format=$("#date_from").datepicker( "option", "dateFormat");
        if (context && context.date_from) {
          $("#date_from").datepicker("option", "minDate", context.date_from);
          $("#date_to").datepicker("option", "minDate", context.date_from);
        } else {
          $("#date_from").datepicker("option", "minDate", null);
          $("#date_to").datepicker("option", "minDate", null);
        }
        if (context && context.date_to) {
          $("#date_from").datepicker("option", "maxDate", jQuery.datepicker.parseDate(format, context.date_to));
          $("#date_to").datepicker("option", "maxDate", jQuery.datepicker.parseDate(format, context.date_to));
        } else {
          $("#date_from").datepicker("option", "maxDate", 0);
          $("#date_to").datepicker("option", "maxDate", 0);
        }
        if (context && context.date_age) {
          $('#age-help span').html(context.date_age);
          $('#age .context-instruct').show();
        }
      }
    },
    where:{
      getDescription:function() {
        if (indiciaData.filter.def.location_id) {
          return 'Records in ' + indiciaData.filter.def['imp-location:name'];
        } else if (indiciaData.filter.def.location_name) {
          return 'Records in places containing "' + indiciaData.filter.def.location_name + '"';
        } else if (indiciaData.filter.def.indexed_location_id) {
          return 'Records in ' + indiciaData.myLocality;
        } else if (indiciaData.filter.def.sref) {
          return 'Records in square ' + indiciaData.filter.def.sref;
        } else if (indiciaData.filter.def.searchArea) {
          return 'Records within a freehand boundary';
        } else {
          return '';
        }        
      },
      limitToContext:function(filterDef,context) {
        // context limits override the filter def
        if (context.location_id) {
          filterDef.location_id=context.location_id;
        }
        if (context.indexed_location_id) {
          filterDef.indexed_location_id=context.indexed_location_id;
        }
        if (context.location_name) {
          filterDef.location_name=context.location_name;
        }
        if (context.searchArea) {
          filterDef.sref=context.sref;
        }
        if (context.sref) {
          filterDef.sref=context.sref;
        }
      },
      applyFormToDefinition:function() {
        var geoms=[], geom;
        $.each(indiciaData.mapdiv.map.editLayer.features, function(i, feature) {
          // ignore features with a special purpose, e.g. the selected record when verifying
          if (typeof feature.tag==="undefined") {
            if (feature.geometry.CLASS_NAME.indexOf('Multi')!==-1) {
              geoms = geoms.concat(feature.geometry.components);
            } else {
              geoms.push(feature.geometry);
            }
          }
        });
        if (geoms.length===0 || indiciaData.filter.def.location || indiciaData.filter.def.location_id) {
          indiciaData.filter.def.searchArea = '';
        } else {
          if (geoms[0].CLASS_NAME === 'OpenLayers.Geometry.Polygon') {
            geom = new OpenLayers.Geometry.MultiPolygon(geoms);
          } else if (geoms[0].CLASS_NAME === 'OpenLayers.Geometry.LineString') {
            geom = new OpenLayers.Geometry.MultiLineString(geoms);
          } else if (geoms[0].CLASS_NAME === 'OpenLayers.Geometry.Point') {
            geom = new OpenLayers.Geometry.MultiPoint(geoms);
          }
          if (indiciaData.mapdiv.map.projection.getCode() !== 'EPSG:3857') {
            geom.transform(indiciaData.mapdiv.map.projection, new OpenLayers.Projection('EPSG:3857'));
          }
          if (indiciaData.filter.def.searchArea !== geom.toString()) {
            indiciaData.filter.def.searchArea = geom.toString();
            filterParamsChanged();
          }
        }
      },
      loadForm:function(context) {
        indiciaData.disableMapDataLoading=true;
        indiciaData.mapOrigCentre=indiciaData.mapdiv.map.getCenter();
        indiciaData.mapOrigZoom=indiciaData.mapdiv.map.getZoom();
        $('#location_id').attr('checked', indiciaData.filter.def.location_id ? true : false);
        // max size the map
        $('#filter-map-container').css('width', $(window).width()-160);
        $('#filter-map-container').css('height', $(window).height()-280);
        if (typeof indiciaData.linkToMapDiv!=="undefined") {
          // move the map div to our container so it appears on the popup
          var element=$('#' + indiciaData.linkToMapDiv);
          indiciaData.origMapParent = element.parent();
          indiciaData.origMapSize = {"width": $(indiciaData.mapdiv).css('width'), "height": $(indiciaData.mapdiv).css('height')};
          $(indiciaData.mapdiv).css('width', '100%');
          $(indiciaData.mapdiv).css('height', '100%');
          $('#filter-map-container').append(element);
        }
        // select the first draw... tool if allowed to draw on the map by permissions, else select navigate
        $.each(indiciaData.mapdiv.map.controls, function(idx, ctrl) {        
          if (context && (((context.sref || context.searchArea) && ctrl.CLASS_NAME.indexOf('Control.Navigate')>-1) ||
              ((!context.sref && !context.searchArea) && ctrl.CLASS_NAME.indexOf('Control.Draw')>-1)))
          {
            ctrl.activate();
            return false;
          }
        });
        if (context && (context.location_id || context.indexed_location_id || context.location_name || context.searchArea)) {
          $('#controls-filter_where .context-instruct').show();
        }        
        disableIfPresent(context, ['location_id','location_name'], ['#imp-location\\:name','#location_name']);
        disableIfPresent(context, ['indexed_location_id'], ['#indexed_location_id']);
        disableIfPresent(context, ['sref','searchArea'], ['#imp-sref']);
        if (context && (context.sref || context.searchArea)) {
          $('#controls-filter_where legend').hide();
          $('.olControlDrawFeaturePolygonItemInactive').addClass('disabled');
          $('.olControlDrawFeaturePathItemInactive').addClass('disabled');
          $('.olControlDrawFeaturePointItemInactive').addClass('disabled');
        } else {
          $('#controls-filter_where legend').show();
        }
        
      },
      loadFilter: function() {
        if (indiciaData.filter.def.searchArea && indiciaData.mapdiv) {
          var parser = new OpenLayers.Format.WKT(), feature = parser.read(indiciaData.filter.def.searchArea);
          if (indiciaData.mapdiv.map.projection.getCode() !== indiciaData.mapdiv.indiciaProjection.getCode()) {
            feature.geometry.transform(indiciaData.mapdiv.indiciaProjection, indiciaData.mapdiv.map.projection);
          }
          indiciaData.mapdiv.map.editLayer.addFeatures([feature]);
        }
      }
    },
    who:{
      getDescription:function() {
        if (indiciaData.filter.def.my_records) {
          return indiciaData.lang.MyRecords;
        } else {
          return '';
        }
      },
      limitToContext:function(filterDef,context) {
        // context limits override the filter def
        if (context.my_records) {
          filterDef.my_records=context.my_records;
        }
      },
      loadForm:function(context) {
        if (context && context.my_records) {
          $('#my_records').attr('disabled', true);
          $('#controls-filter_who .context-instruct').show();
          $('#controls-filter_who button').hide();
        } else {
          $('#my_records').removeAttr('disabled');
          $('#controls-filter_who button').show();
        }
      }
    },
    occurrence_id:{
      getDescription:function() {
        if (indiciaData.filter.def.occurrence_id) {
          return $('#occurrence_id_op option[value='+indiciaData.filter.def.occurrence_id_op+']').html()
              + ' ' + indiciaData.filter.def.occurrence_id;
        } else {
          return '';
        }
      },
      limitToContext:function(filterDef,context) {
        // context limits override the filter def
        if (context.occurrence_id) {
          filterDef.occurrence_id=context.occurrence_id;
          filterDef.occurrence_id_op=context.occurrence_id_op;
        }
      },
      loadForm:function(context) {
      }
    },
    quality:{
      getDescription:function() {
        var r=[];
        if (indiciaData.filter.def.quality!=='all') {
          r.push($('#quality-filter option[value='+indiciaData.filter.def.quality+']').html());
        }
        if (indiciaData.filter.def.autochecks==='F') {
          r.push(indiciaData.lang.AutochecksFailed);
        } else if (indiciaData.filter.def.autochecks==='P') {
          r.push(indiciaData.lang.AutochecksPassed);
        }
        if (indiciaData.filter.def.has_photos) {
          r.push(indiciaData.lang.HasPhotos);
        }
        return r.join('<br/>');
      },
      getDefaults:function() {
        return {
          "quality" : "!R"
        };
      },
      limitToContext:function(filterDef,context) {
        if (context.quality && context.quality!=='all') {
          filterDef.quality=context.quality;
        }
        if (context.autochecks) {
          filterDef.autochecks=context.autochecks;
        }
        if (context.has_photos) {
          filterDef.has_photos=context.has_photos;
        }
      },
      loadForm:function(context) {
        if (context && context.quality && context.quality!=='all') {
          $('#quality-filter').attr('disabled', true);
        } else {
          $('#quality-filter').removeAttr('disabled');
        }
        if (context && context.autochecks) {
          $('#autochecks').attr('disabled', true);
        } else {
          $('#autochecks').removeAttr('disabled');
        }
        if (context && context.has_photos) {
          $('#has_photos').attr('disabled', true);
        } else {
          $('#has_photos').removeAttr('disabled');
        }
        if (context && ((context.quality && context.quality!=='all') || context.autochecks || context.has_photos)) {
          $('#controls-filter_quality .context-instruct').show();
        }
      }
    },
    source:{
      getDescription:function() {
        var r=[], list=[];
        if (indiciaData.filter.def.website_list) {
          $.each(indiciaData.filter.def.website_list.split(','), function(idx, id) {
            list.push($('#check-'+id).next('label').html());
          });
          r.push((indiciaData.filter.def.website_list_op==='not in' ? 'Exclude ' : '') + list.join(', '));
        }
        if (indiciaData.filter.def.survey_list) {
          $.each(indiciaData.filter.def.survey_list.split(','), function(idx, id) {
            list.push($('#check-survey-'+id).next('label').html());
          });
          r.push((indiciaData.filter.def.survey_list_op==='not in' ? 'Exclude ' : '') + list.join(', '));
        }
        if (indiciaData.filter.def.input_form_list) {
          $.each(indiciaData.filter.def.input_form_list.split(','), function(idx, id) {
            list.push($('#check-input_form-'+id).next('label').html());
          });
          r.push((indiciaData.filter.def.input_form_list_op==='not in' ? 'Exclude ' : '') + list.join(', '));
        }
        return r.join('<br/>');
      },
      limitToContext:function(filterDef,context) {
        if (context.website_list_op && context.website_list) {
          filterDef.website_list_op=context.website_list_op;
        }
        if (context.survey_list_op && context.survey_list) {
          filterDef.survey_list_op=context.survey_list_op;
        }
        // ensure that the selected websites, surveys and forms are in the context limits
        limitSourceToContext('website', filterDef, context);
        limitSourceToContext('survey', filterDef, context);
        limitSourceToContext('input_form', filterDef, context);
      },
      loadForm: function(context) {
        if (context && ((context.website_list && context.website_list_op) || 
            (context.survey_list && context.survey_list_op) || (context.input_form_list && context.input_form_list_op))) {
          $('#controls-filter_source .context-instruct').show();
        }
        if (indiciaData.filter.def.website_list) {
          $('#website-list-checklist input').attr('checked', false);
          $.each(indiciaData.filter.def.website_list.split(','), function(idx, id) {
            $('#check-'+id).attr('checked', true);
          });
          updateWebsiteSelection();
        }
        if (indiciaData.filter.def.survey_list) {
          $('#survey_list input').attr('checked', false);
          $.each(indiciaData.filter.def.survey_list.split(','), function(idx, id) {
            $('#check-survey-'+id).attr('checked', true);
          });
        }
        if (indiciaData.filter.def.input_form_list) {
          $('#input_form_list input').attr('checked', false);
          $.each(indiciaData.filter.def.input_form_list.split(','), function(idx, form) {
            $('#check-form-'+indiciaData.formsList[form]).attr('checked', true);
          });
        }
        disableSourceControlsForContext('website', context);
        disableSourceControlsForContext('survey', context);
        disableSourceControlsForContext('input_form', context);
      },
      applyFormToDefinition:function() {
        var website_ids = [];
        $.each($('#filter-websites input:checked').filter(":visible"), function(idx, ctrl) {
          website_ids.push($(ctrl).val());
        });
        indiciaData.filter.def.website_list = website_ids.join(',');
        var survey_ids = [];
        $.each($('#filter-surveys input:checked').filter(":visible"), function(idx, ctrl) {
          survey_ids.push($(ctrl).val());
        });
        indiciaData.filter.def.survey_list = survey_ids.join(',');
      }
    }
  };
  
  // Event handler for a draw tool boundary being added which clears the other controls on the map pane.
  function addedFeature() {
    $('#controls-filter_where').find(':input').not('#imp-sref-system,:checkbox,[type=button]').val('');
    $('#controls-filter_where').find(':checkbox').attr('checked', false);
  }
  
  // Hook the addedFeature handler up to the draw controls on the map
  mapInitialisationHooks.push(function(mapdiv) {
    $.each(mapdiv.map.controls, function(idx, ctrl) {
      if (ctrl.CLASS_NAME.indexOf('Control.Draw')>-1) {
        ctrl.events.register('featureadded', ctrl, addedFeature);
      }
    });
    // ensures that if part of a loaded filter description is a boundary, it gets loaded onto the map only when the map is ready
    updateFilterDescriptions();
  });
  
  // Ensure that pane controls that are exclusive of others are only filled in one at a time
  $('.filter-controls fieldset :input').change(function(e) {
    var form=$(e.currentTarget).parents('.filter-controls'),
      thisFieldset=$(e.currentTarget).parents('fieldset')[0];
    if ($(form)[0].id==='controls-filter_where') {      
      indiciaData.mapdiv.map.editLayer.removeAllFeatures();
    }
    $.each($(form).find('fieldset.exclusive'), function(idx, fieldset) {
      if (fieldset!==thisFieldset) {
        $(fieldset).find(':input').not('#imp-sref-system,:checkbox,[type=button]').val('');
        $(fieldset).find(':checkbox').attr('checked', false);
      }
    });
  });
  
  // Ensure that only one of species and species groups are picked on the what filter
  $('#taxa_taxon_list_list\\:search\\:taxon').keypress(function(e) {
    if (e.which===13) {
      $('#taxon_group_list\\:sublist').children().remove();
    }
  });
  $('#taxa_taxon_list_list\\:add').click(function() {$('#taxon_group_list\\:sublist').children().remove();});
  $('#taxon_group_list\\:search\\:title').keypress(function(e) {
    if (e.which===13) {
      $('#taxa_taxon_list_list\\:sublist').children().remove();
    }
  });
  $('#taxon_group_list\\:add').click(function() {$('#taxa_taxon_list_list\\:sublist').children().remove();});
  
  // Checking the checkbox linked to my locality loads the locality boundary onto the map.
  $('#location_id').change(function() {
    if ($('#location_id').attr('checked')) {
      // selected to load the preferred locality. So load the boundary...
      $.getJSON(indiciaData.read.url + 'index.php/services/data/location/' + $('#location_id').val() +
              '?mode=json&view=detail&auth_token='+indiciaData.read.auth_token+'&nonce='+indiciaData.read.nonce+'&callback=?', function(data) {
        var parser = new OpenLayers.Format.WKT(), feature = parser.read(data[0].boundary_geom);
        if (indiciaData.mapdiv.map.projection.getCode() !== indiciaData.mapdiv.indiciaProjection.getCode()) {
          feature.geometry.transform(indiciaData.mapdiv.indiciaProjection, indiciaData.mapdiv.map.projection);
        }
        indiciaData.mapdiv.map.editLayer.addFeatures([feature]);
      });
    }
  });
  
  function updateSurveySelection() {
    var surveys=[];
    $.each($('#filter-surveys input:checked'), function(idx, checkbox) {
      surveys.push('.vis-survey-' + $(checkbox).val());
    });
    if (surveys.length===0) {
      // no websites picked, so can pick any survey
      $('#filter-input_forms li').show();
    } 
    else if ($('#filter-surveys-mode').val()==='in') {
      // list only the forms that can be picked
      $('#filter-input_forms li').filter(surveys.join(',')).removeClass('survey-hide');
      $('#filter-input_forms li').not(surveys.join(',')).addClass('survey-hide');
      $('#filter-input_forms li').not(surveys.join(',')).find('input').attr('checked', false);
    } else {
      // list only the forms that can be picked - based on an exclusion of surveys
      $('#filter-input_forms li').filter(surveys.join(',')).addClass('survey-hide');
      $('#filter-input_forms li').not(surveys.join(',')).removeClass('survey-hide');
      $('#filter-input_forms li').filter(surveys.join(',')).find('input').attr('checked', false);
    }
  }
  
  function updateWebsiteSelection() {
    var websites=[], lis=$('#filter-surveys li, #filter-input_forms li');
    $.each($('#filter-websites input:checked'), function(idx, checkbox) {
      websites.push('.vis-website-' + $(checkbox).val());
    });
    
    if (websites.length===0) {
      // no websites picked, so can pick any survey
      lis.removeClass('website-hide');
    } 
    else if ($('#filter-websites-mode').val()==='in') {
      // list only the surveys that can be picked
      lis.filter(websites.join(',')).removeClass('website-hide');
      lis.not(websites.join(',')).addClass('website-hide');
      lis.not(websites.join(',')).find('input').attr('checked', false);
    } else {
      // list only the surveys that can be picked - based on an exclusion of websites
      lis.filter(websites.join(',')).addClass('website-hide');
      lis.not(websites.join(',')).removeClass('website-hide');
      lis.filter(websites.join(',')).find('input').attr('checked', false);
    }
  }
  
  $('#filter-websites :input').change(updateWebsiteSelection);
  
  $('#filter-surveys :input').change(updateSurveySelection);
  
  $('#my_groups').click(function() {
    $.each(indiciaData.myGroups, function(idx, group) {
      if ($('#taxon_group_list\\:sublist input[value=' + group[0] + ']').length===0) {
        $('#taxon_group_list\\:sublist').append('<li><span class="ind-delete-icon"> </span>' + group[1] + 
            '<input type="hidden" value="' + group[0] + '" name="taxon_group_list[]"></li>');
      }
    });
  });
  
  // Event handler for selecting a filter from the drop down. Enables the apply filter button when appropriate.
  var filterChange=function() {
    if ($('#select-filter').val()) {
      $('#filter-apply').removeClass('disabled');
    } else {
      $('#filter-apply').addClass('disabled');
    }
  };
  
  // Hook the above event handler to the select filter dropdown.
  $('#select-filter').change(filterChange);
  
  function dateStrIsGreater(d1, d2) {
    var format=$("#date_from").datepicker( "option", "dateFormat");
    d1 = jQuery.datepicker.parseDate(format, d1);
    d2 = jQuery.datepicker.parseDate(format, d2);
    return d1>d2;
  }
  
  function ageStrIsGreater(d1, d2) {
    var days1, days2;
    d1 = d1.split(/\s/);
    switch ($.trim(d1[1]).substr(0,3).toUpperCase()) {
      case 'WEE': days1=d1[0]*7; break;
      case 'MON': days1=d1[0]*30; break;
      case 'YEA': days1=d1[0]*365; break;
      default: days1=d1[0];
    }
    d2 = d2.split(/\s/);
    switch ($.trim(d2[1]).substr(0,3).toUpperCase()) {
      case 'WEE': days2=d2[0]*7; break;
      case 'MON': days2=d2[0]*30; break;
      case 'YEA': days2=d2[0]*365; break;
      default: days2=d2[0];
    }
    return days1>days2;
  }
  
  function applyContextLimits() {
    // if a context is loaded, need to limit the filter to the records in the context
    if ($('#context-filter').length) {
      var context = indiciaData.filterContextDefs[$('#context-filter').val()];
      $.each(paneObjList, function(name, obj) {
        if (typeof obj.limitToContext!=="undefined") {
          obj.limitToContext(indiciaData.filter.def, context);
        }
      });
    }
  }
  
  applyFilterToReports = function(applyNow) {
    applyContextLimits();
    var filterDef = indiciaData.filter.def;
    if (indiciaData.reports) {
      // apply the filter to any reports on the page
      $.each(indiciaData.reports, function(i, group) {
        $.each(group, function(j, grid) {
          // store a copy of the original params before any reset, so we can revert.
          if (typeof grid[0].settings.origParams==="undefined") {
            grid[0].settings.origParams = $.extend({}, grid[0].settings.extraParams);
          }
          // merge in the filter
          $.extend(grid[0].settings.extraParams, filterDef);
          if (applyNow) {
            // reload the report grid
            grid.ajaxload();
            if (grid[0].settings.linkFilterToMap) {
              grid.mapRecords(grid[0].settings.mapDataSource, grid[0].settings.mapDataSourceLoRes);
            }
          }
        });
      });
    }
  };
  
  function applyDefaults() {
    $.each(paneObjList, function(name, obj) {
      if (typeof obj.getDefaults!=="undefined") {
        $.extend(indiciaData.filter.def, obj.getDefaults());
      }
    });
  }
  
  function resetFilter() {
    indiciaData.filter.def={};
    applyDefaults();
    if (typeof indiciaData.filter.orig!=="undefined") {
      indiciaData.filter.def = $.extend(indiciaData.filter.def, indiciaData.filter.orig);
    }
    indiciaData.filter.id = null;
    $('#filter-name').val('');
    $('#select-filter').val('');
    $.each(indiciaData.reports, function(i, group) {
      $.each(group, function(j, grid) {
        // revert any stored original params for the grid.
        if (typeof grid[0].settings.origParams!=="undefined") {
          grid[0].settings.extraParams = $.extend({}, grid[0].settings.origParams);
        }
      });
    });
    applyFilterToReports(true);
    $.each(indiciaData.reports, function(i, group) {
      $.each(group, function(j, grid) {
        // reload the report grid
        grid.ajaxload();
        if (grid[0].settings.linkFilterToMap) {
          grid.mapRecords(grid[0].settings.mapDataSource);
        }
      });
    });
    // clear map edit layer
    if (indiciaData.mapdiv) {
      indiciaData.mapdiv.map.editLayer.removeAllFeatures();
    }
    updateFilterDescriptions();
    $('#filter-reset').addClass('disabled');
    $('#filter-delete').addClass('disabled');
    // reset the filter label
    $('#standard-params .header h2').html(indiciaData.lang.FilterReport);
    $('#standard-params .header span.changed').hide();
  }
  
  function updateFilterDescriptions() {
    var description, name;
    $.each($('#filter-panes .pane'), function(idx, pane) {
      name=pane.id.replace(/^pane-filter_/,'');
      if (paneObjList[name].loadFilter) {
        paneObjList[name].loadFilter();
      }
      description = paneObjList[name].getDescription();
      if (description==='') {
        description=indiciaData.lang['NoDescription' + name];
      }
      $(pane).find('span.filter-desc').html(description);
    });
  }
  
  loadFilter = function(id, applyNow) {
    if ($('#standard-params .header span.changed:visible').length===0 || confirm(indiciaData.lang.ConfirmFilterChangedLoad)) {
      $.ajax({
        dataType: "json",
        url: indiciaData.read.url + 'index.php/services/data/filter/' + id,
        data: 'mode=json&view=list&auth_token='+indiciaData.read.auth_token+'&nonce='+indiciaData.read.nonce+'&callback=?',
        success: function(data) {
          indiciaData.filter.def = JSON.parse(data[0].definition);
          indiciaData.filter.id = data[0].id;
          indiciaData.filter.title = data[0].title;
          $('#filter-name').val(data[0].title);
          applyFilterToReports(applyNow);
          $('#filter-reset').removeClass('disabled');
          $('#filter-delete').removeClass('disabled');
          $('#standard-params .header h2').html('Active filter: '+data[0].title);
          updateFilterDescriptions();
          $('#standard-params .header span.changed').hide();
          // can't delete a filter you didn't create.
          if (data[0].created_by_id===indiciaData.user_id) {
            $('#filter-delete').show();
          } else {
            $('#filter-delete').hide();
          }
        },
        async: applyNow // if not applying the filter, then we are expecting immediate load so that something else can apply the filter in a moment
      });
    }
  };
  
  function filterParamsChanged() {
    $('#standard-params .header span.changed').show();
    $('#filter-reset').removeClass('disabled');
  }
  
  $('.fb-filter-link').fancybox({
    onStart: function(e) {
      var pane=$(e[0].href.replace(/^[^#]+/, '')), context;
      // reset
      pane.find('.fb-apply').data('clicked', false);
      // regexp extracts the pane ID from the href. Loop through the controls in the pane      
      $.each(pane.find(':input').not(':checkbox,[type=button]'), function(idx, ctrl) {
        // set control value to the stored filter setting
        $(ctrl).val(indiciaData.filter.def[$(ctrl).attr('name')]);        
      });
      $.each(pane.find(':checkbox'), function(idx, ctrl) {
        $(ctrl).attr('checked', typeof indiciaData.filter.def[$(ctrl).attr('name')]!=="undefined" && indiciaData.filter.def[$(ctrl).attr('name')]==$(ctrl).val());
      });
      var paneName=$(pane).attr('id').replace('controls-filter_', '');
      // if a context is loaded, need to let the forms configure themselves on this basis
      context = $('#context-filter').length ? indiciaData.filterContextDefs[$('#context-filter').val()] : null;
      $('.context-instruct').hide();
      // Does the pane have any special code for loading the definition into the form?
      if (typeof paneObjList[paneName].loadForm!=="undefined") {
        paneObjList[paneName].loadForm(context);
      }
    },
    onComplete: function(e) {
      var pane=$(e[0].href.replace(/^[^#]+/, ''));
      if (pane[0].id==='controls-filter_where') {
        indiciaData.mapdiv.map.updateSize();
        indiciaData.mapdiv.settings.drawObjectType='queryPolygon';
      }
    },
    onClosed: function(e) {
      var pane=$(e[0].href.replace(/^[^#]+/, ''));
      if (pane[0].id==='controls-filter_where' && typeof indiciaData.linkToMapDiv!=="undefined") {
        var element=$('#' + indiciaData.linkToMapDiv);
        $(indiciaData.mapdiv).css('width', indiciaData.origMapSize.width);
        $(indiciaData.mapdiv).css('height', indiciaData.origMapSize.height);
        $(indiciaData.origMapParent).append(element);
        indiciaData.mapdiv.map.setCenter(indiciaData.mapOrigCentre, indiciaData.mapOrigZoom);
        indiciaData.mapdiv.map.updateSize();
        indiciaData.mapdiv.settings.drawObjectType='boundary';
        indiciaData.disableMapDataLoading=false;
      }
    }
  }); 
  
  $('form.filter-controls :input').change(function(){
    filterParamsChanged();
  });

  $('#filter-apply').click(function() {
    loadFilter($('#select-filter').val(), true);
  });  
  
  $('#filter-reset').click(function() {
    resetFilter();
  }); 
  
  $('#filter-build').click(function() {
    var desc;
    $.each(paneObjList, function(name, obj) {
      desc=obj.getDescription();
      if (desc==='') {
        desc=indiciaData.lang['NoDescription' + name];
      }
      $('#pane-filter_'+name+' span.filter-desc').html(desc);
    });    
    $('#filter-details').slideDown();
    $('#filter-build').addClass('disabled');
  });
  
  $('#filter-delete').click(function(e) {
    if ($(e.currentTarget).hasClass('disabled')) {
      return;
    }
    if (confirm(indiciaData.lang.ConfirmFilterDelete.replace('{title}', indiciaData.filter.title))) {
      var filter = {"id": indiciaData.filter.id, "website_id":indiciaData.website_id, "user_id":indiciaData.user_id, "deleted":"t"};
      $.post(indiciaData.filterPostUrl, 
        filter,
        function (data) {
          if (typeof data.error === 'undefined') {
            alert(indiciaData.lang.FilterDeleted);
            $('#select-filter').val('');
            $('#select-filter').find('option[value="'+indiciaData.filter.id+'"]').remove();
            resetFilter();
          } else {
            alert(data.error);
          }
        }
      );
    }
  });
  
  $('#filter-done').click(function() {
    $('#filter-details').slideUp();
    $('#filter-build').removeClass('disabled');
  });
  
  $('.fb-close').click(function() {
    $.fancybox.close();
  });  
  
  $('form.filter-controls').submit(function(e){
    e.preventDefault();
    if (!$(e.currentTarget).valid() || $(e.currentTarget).find('.fb-apply').data('clicked')) {
      return false;
    }
    $(e.currentTarget).find('.fb-apply').data('clicked', true);
    var arrays={}, arrayName;
    // persist each control value into the stored settings
    $.each($(e.currentTarget).find(':input'), function(idx, ctrl) {
      if ($(ctrl).attr('type')!=='checkbox' || $(ctrl).attr('checked')) {
        // array control?
        if ($(ctrl).attr('name').match(/\[\]$/)) {
          // store array control data to handle later
          arrayName=$(ctrl).attr('name').substring(0, $(ctrl).attr('name').length-2);
          if (typeof arrays[arrayName]==="undefined") {
            arrays[arrayName] = [];
          }
          arrays[arrayName].push($(ctrl).val());
        } else {
          // normal control
          indiciaData.filter.def[$(ctrl).attr('name')]=$(ctrl).val();
        }
      }
      else {
        // an unchecked checkbox so clear it's value
        indiciaData.filter.def[$(ctrl).attr('name')]='';
      }
    });
    // convert array values to comma lists
    $.each(arrays, function(name, arr) {
      indiciaData.filter.def[name] = arr.join(',');
    });
    var pane=e.currentTarget.id.replace('controls-filter_', '');
    // Does the pane have any special code for applying it's settings to the definition?
    if (typeof paneObjList[pane].applyFormToDefinition!=="undefined") {
      paneObjList[pane].applyFormToDefinition();
    }
    applyFilterToReports(true);
    updateFilterDescriptions();
    $.fancybox.close();
  });
  
  var saveFilter = function() {
    if ($.trim($('#filter-name').val())==='') {
      alert('Please provide a name for your filter');
      return;
    }    
    var url, filter = {"website_id":indiciaData.website_id, "user_id":indiciaData.user_id, "title":$('#filter-name').val(),
          "definition":JSON.stringify(indiciaData.filter.def), "sharing":indiciaData.filterSharing};
    if (indiciaData.filter.id && $('#filter-name').val()===indiciaData.filter.title) {
      filter.id = indiciaData.filter.id;
    }
    // If a new filter, then also need to create a filters_users record.
    url = typeof filter.id==="undefined" ? indiciaData.filterAndUserPostUrl : indiciaData.filterPostUrl;
    $.post(url, filter,
      function (data) {
        if (typeof data.error === 'undefined') {
          alert(indiciaData.lang.FilterSaved);
          indiciaData.filter.id = data.outer_id;
          indiciaData.filter.title = $('#filter-name').val();
          $('#standard-params .header h2').html('Active filter: '+$('#filter-name').val());
          $('#standard-params .header span.changed').hide();
          $('#select-filter').val(indiciaData.filter.id);
          if ($('#select-filter').val()==='') {
            // this is a new filter, so add to the select list
            $('#select-filter').append('<option val="' + indiciaData.filter.id + '" selected="selected">' + indiciaData.filter.title + '</option>');
          }
        } else {
          var handled = false;
          if (typeof data.errors!=="undefined") {
            $.each(data.errors, function(key, msg) {
              if (key==='filter:general' && msg.indexOf('uc_filter_name')>-1) {
                if (confirm(indiciaData.lang.FilterExistsOverwrite)) {
                  // need to load the existing filter to get it's ID, then resave
                  $.getJSON(indiciaData.read.url + 'index.php/services/data/filter?created_by_id='+indiciaData.user_id+'&title='+
                      encodeURIComponent($('#filter-name').val())+'&sharing='+indiciaData.filterSharing+
                      '&mode=json&view=list&auth_token='+indiciaData.read.auth_token+'&nonce='+indiciaData.read.nonce+'&callback=?', function(data) {
                    indiciaData.filter.id = data[0].id;
                    indiciaData.filter.title = $('#filter-name').val();
                    saveFilter();
                  });
                }
                handled = true;
              }
            });
          }
          if (!handled) {
            alert(data.error);
          }
        }
      },
      'json'
    );
  };
  
  $('#filter-save').click(saveFilter);
  
  filterChange();
  applyDefaults();
  $('form.filter-controls').validate();
 
});
