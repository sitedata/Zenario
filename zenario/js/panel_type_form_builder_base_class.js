/*
 * Copyright (c) 2016, Tribal Limited
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of Zenario, Tribal Limited nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL TRIBAL LTD BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

/*
	This file contains JavaScript source code.
	The code here is not the code you see in your browser. Before this file is downloaded:
	
		1. Compilation macros are applied (e.g. "foreach" is a macro for "for .. in ... hasOwnProperty").
		2. It is minified (e.g. using Google Closure Compiler).
		3. It may be wrapped togther with other files (this is to reduce the number of http requests on a page).
	
	For more information, see js_minify.shell.php for steps (1) and (2), and organizer.wrapper.js.php for step (3).
*/


zenario.lib(function(
	undefined,
	URLBasePath,
	document, window, windowOpener, windowParent,
	zenario, zenarioA, zenarioAB, zenarioAT, zenarioO,
	get, engToBoolean, htmlspecialchars, ifNull, jsEscape, phrase,
	extensionOf, methodsOf, has,
	panelTypes
) {
	"use strict";


//Note: extensionOf() and methodsOf() are our shortcut functions for class extension in JavaScript.
	//extensionOf() creates a new class (optionally as an extension of another class).
	//methodsOf() allows you to get to the methods of a class.
var methods = methodsOf(
	panelTypes.form_builder_base_class = extensionOf(panelTypes.base)
);

//Custom methods


methods.sortByOrd = function(a, b) {
	if (a.ord < b.ord) 
		return -1;
	if (a.ord > b.ord)
		return 1;
	return 0;
};

methods.initField = function($field) {
	var that = this;
	$field.on('click', function() {
		that.fieldClick($(this));
	});
	this.initDeleteButtons();
};

methods.showFieldDetailsSection = function(section, noValidation) {
	var that = this,
		cb,
		afterValidate = function(errors) {
			if (_.isEmpty(errors)) {
				that.currentFieldTab = section;
				
				// Mark current tab
				$('#organizer_field_details_tabs div.tab').removeClass('on');
				$('#field_tab__' + section).addClass('on');
				
				// Show current section
				$('#organizer_field_details div.section').hide();
				$('#field_section__' + section).show();
			}
		};
	
	if (!noValidation) {
		this.save();
		if (cb = this.validate()) {
			cb.after(afterValidate);
			return;
		}
	}
	afterValidate([]);
};

//TODO improve this!
methods.showDetailsSection = function(section) {
	var sections = ['organizer_field_type_list', 'organizer_field_details', 'organizer_tab_details'],
		index = $.inArray(section, sections);
	if (index > -1) {
		
		// Show selected sections and destroy other forms
		$('#' + section + '_outer').show();
		
		if (section == 'organizer_field_type_list') {
			this.current = false;
		}
		
		delete(sections[index]);
		
		// Hide other sections
		foreach (sections as key => section) {
			if (section) {
				$('#' + section + '_outer').hide();
			}
		}
	}
};

// Init the add new fields button
methods.initAddNewFieldsButton = function() {
	var that = this;
	$(this.formEditorSelector).find('input.add_new_field, span.add_new_field').on('click', function() {
		// Validate and save the current field, if the field is valid then show the new fields list and unselect the current field
		var afterValidate = function(errors) {
			if (_.isEmpty(errors)) {
				that.unselectField();
				that.showDetailsSection('organizer_field_type_list');
			}
		};
		that.save();
		if (cb = that.validate()) {
			cb.after(afterValidate);
		} else {
			afterValidate([]);
		}
	});
};

// Deselect a field
methods.unselectField = function() {
	this.current.type = this.current.id = false;
	$(this.formFieldInlineButtonsSelector).hide();
	$(this.formFieldsSelector).removeClass('selected');
};

// Send an AJAX request
methods.sendAJAXRequest = function(requests) {
	var that = this,
		actionRequests = zenarioO.getKey(),
		actionTarget = 
		'zenario/ajax.php?' +
			'__pluginClassName__=' + this.tuix.class_name +
			'&__path__=' + zenarioO.path +
			'&method_call=handleOrganizerPanelAJAX';
	
	$.extend(actionRequests, requests);
	
	get('organizer_preloader_circle').style.display = 'block';
	var result = zenario.ajax(
		URLBasePath + actionTarget,
		actionRequests
	).after(function() {
		get('organizer_preloader_circle').style.display = 'none';
	});
	return result;
};

// Marks when a change is made to the form, shows buttons and stops you navigating away
methods.changeMadeToPanel = function() {
	var that = this;
	if (!this.changingForm) {
		this.changingForm = true;
		window.onbeforeunload = function() {
			return that.saveChangesWarningMessage;
		}
		var warningMessage = 'You should either save your changes or click reset to discard them before leaving';
		zenarioO.disableInteraction(warningMessage);
		zenarioO.setButtons();
	}
};

// Save any local changes made
methods.saveChanges = function() {
	this.saveItemsOrder();
	
	var that = this,
		actionRequests = {
			mode: 'save',
			data: JSON.stringify(this.tuix.items)
		};
	
	this.sendAJAXRequest(actionRequests).after(function (message) {
		if (message) {
			zenarioA.showMessage(message);
		}
		zenarioA.nowDoingSomething();
		window.onbeforeunload = false;
		zenarioO.enableInteraction();
		
		that.changingForm = false;
		that.changesSaved = true;
		
		zenarioO.reload();
	});
};

// Format the field details section
methods.formatFieldDetails = function() {

	this.save();
	this.setCurrentFieldDetails();
};

// Create a list of values from an object that can be passed to a select list microtemplate
methods.createSelectList = function(options, selectedValue, emptyValue, optgroups) {
	var selectList = [];
	
	// Add empty value option at start
	if (emptyValue) {
		var label = '-- Select --';
		if (emptyValue !== true) {
			label = emptyValue;
		}
		emptyOption = {
			label: label,
			value: '',
			ord: -1
		};
		if (options.hasOwnProperty(selectedValue)) {
			emptyOption.selected = true;
		}
		selectList.push(emptyOption);
	}
	
	foreach (options as key => option) {
		var option = _.clone(option);
		
		if (optgroups === true) {
			option.options = [];
			option.isOptGroup = true;
			foreach (option.childOptions as subKey => subOption) {
				
				var subOption = _.clone(subOption);
				if (subKey == selectedValue) {
					subOption.selected = true;
				}
				subOption.value = subKey
				option.options.push(subOption);
			}
			option.options.sort(this.sortByOrd);
			selectList.push(option)
			
		} else {
			if (key == selectedValue) {
				option.selected = true;
			}
			option.value = key;
			selectList.push(option);
		}
	}
	selectList.sort(this.sortByOrd);
	
	
	/*
	// Add options
	for (key in options) {
		if (options.hasOwnProperty(key)) {
			var option = _.clone(options[key]);
			if (key == selectedValue) {
				option.selected = true;
			}
			option.value = key;
			selectList.push(option);
		}
	}
	selectList.sort(this.sortByOrd);
	*/
	return selectList;
};

// Create a list of values from an object that can be passeed to a radios microtemplate
methods.createRadioList = function(options, checkedValue, name, disabled) {
	var radioList = [];
	for (key in options) {
		if (options.hasOwnProperty(key)) {
			var option = _.clone(options[key]);
			if (key == checkedValue) {
				option.checked = true;
			}
			if (disabled === true) {
				option.disabled = true;
			}
			option.value = key;
			option.name = name;
			radioList.push(option);
		}
	}
	radioList.sort(this.sortByOrd);
	return radioList;
};


//Draw (or hide) the button toolbar
//This is called every time different items are selected, the panel is loaded, refreshed or when something in the header toolbar is changed.
methods.showButtons = function($buttons) {
	var that = this;
	
	if (this.changingForm) {
		//Change the buttons to apply/cancel buttons
		var mergeFields = {
			confirm_text: this.saveButtonText,
			confirm_css: 'form_editor',
			cancel_text: this.cancelButtonText
		};
		$buttons.html(zenarioA.microTemplate('zenario_organizer_apply_cancel_buttons', mergeFields));
		
		//Add an event to the Apply button to save the changes
		var lock = false
		$buttons.find('#organizer_applyButton')
			.click(function() {
				var afterValidate = function(errors) {
					if (_.isEmpty(errors)) {
						if (lock == true) {
							return false;
						}
						zenarioA.nowDoingSomething('saving', true);
						lock = true;
						that.saveChanges();
					}
				};
				
				that.save();
				if (cb = that.validate()) {
					cb.after(afterValidate);
				} else {
					afterValidate([]);
				}
			});
		
		$buttons.find('#organizer_cancelButton')
			.click(function() {
				if (confirm('Are you sure you want to discard all your changes?')) {
					window.onbeforeunload = false;
					zenarioO.enableInteraction();
					
					// Reset field tab
					that.currentFieldTab = false;
					
					that.changingForm = false;
					zenarioO.reload();
				}
			});
		
	} else {
		//Remove the buttons, but don't actually hide them as we want to keep some placeholder space there
		$buttons.html('').show();
	}
};



// Init list of field values for multi value field types
methods.initFieldValues = function(field) {
	var that = this;
	// Handle remove button
	$('#field_values_list .delete_icon').off().on('click', function() {
		var id = $(this).data('id');
		// Remove from stored values
		field.lov[id] = {remove: true};
		// Remove from preview
		$('#organizer_field_value_' + id).remove();
		// Remove from details section
		$(this).parent().parent().remove();
		that.valuesChanged = true;
		that.changeMadeToPanel();
	});
	
	// Update form labels
	$('#field_values_list input').off().on('keyup', function() {
		var id = $(this).data('id');
		$('#organizer_field_value_' + id + ' label').text($(this).val());
		that.valuesChanged = true;
		that.changeMadeToPanel();
	});
};

// Init button to add a new field value
methods.initAddNewFieldValuesButton = function(field) {
	var that = this;
	$('#organizer_add_a_field_value').on('click', function() {
		
		// Save current values
		that.save();
		
		// Save new value
		var id = 't' + that.maxNewCustomFieldValue++,
			value = {
				id: id,
				label: 'Untitled',
				ord: _.size(field['lov']) + 100,
				is_new_value: true
			};
		field.lov[id] = value;
		
		// Redraw list to include new field
		that.setCurrentFieldValues(field);
		that.changeMadeToPanel();
	});
};

// Set a fields values HTML
methods.setCurrentFieldValues = function(field) {
	var id = this.current.id,
		items = field.lov,
		mergeFields = this.getOrderedItems(items),
		html = '';
	
	this.valuesChanged = true;
	
	// Set values HTML
	var html = zenarioA.microTemplate('zenario_organizer_admin_box_builder_field_value', mergeFields);
	$('#field_values_list').html(html);
	
	// Init values
	this.initFieldValues(field);
	
	// Update preview
	if ($.inArray(field.type, ['checkboxes', 'radios']) > -1) {
		if (field.type == 'checkboxes') {
			html = zenarioA.microTemplate('zenario_organizer_admin_box_builder_checkbox_values_preview', mergeFields);
		} else if (field.type == 'radios') {
			html = zenarioA.microTemplate('zenario_organizer_admin_box_builder_radio_values_preview', mergeFields);
		}
		$('#organizer_form_field_values_' + id).html(html);
	}
};

// Get current field values from list
methods.getCurrentFieldValues = function(field) {
	var that = this,
		lov = _.clone(field.lov);
	
	$('#field_values_list div.field_value').each(function(i, value) {
		var id = $(this).data('id');
		lov[id] = {
			id: id,
			label: $(value).find('input').val(),
			ord: i + 1
		}
		if (field.lov[id]) {
			
			if (field.lov[id].is_new_value) {
				lov[id].is_new_value = true;
			}
			if (field.lov[id].crm_value) {
				lov[id].crm_value = field.lov[id].crm_value;
			}
		}
	});
	return lov;
};

methods.shakeBox = function(selector) {
	$(selector).effect({
		effect: 'bounce',
		duration: 125,
		direction: 'right',
		times: 2,
		distance: 5,
		mode: 'effect'
	});
};


// Base methods

//Called whenever Organizer has saved an item and wants to display a toast message to the administrator
methods.displayToastMessage = function(message, itemId) {
	//Do nothing, don't show the message!
};

//Called by Organizer whenever it needs to set the panel data.
methods.cmsSetsPanelTUIX = function(tuix) {
	this.tuix = tuix;
};

//Called by Organizer whenever it needs to set the current tag-path
methods.cmsSetsPath = function(path) {
	this.path = path;
};

//Called by Organizer whenever a panel is first loaded with a specific item requested
methods.cmsSetsRequestedItem = function(requestedItem) {
	this.lastItemClicked =
	this.requestedItem = requestedItem;
};


//If searching is enabled (i.e. your returnSearchingEnabled() method returns true)
//then the CMS will call this method to tell you what the search term was
methods.cmsSetsSearchTerm = function(searchTerm) {
	this.searchTerm = searchTerm;
};

//Never show the left hand nav; always show this panel using the full width
methods.returnShowLeftColumn = function() {
	return false;
};

//Use this function to set AJAX URL you want to use to load the panel.
//Initally the this.tuix variable will just contain a few important TUIX properties
//and not your the panel definition from TUIX.
//The default value here is a PHP script that will:
	//Load all of the TUIX properties
	//Call your preFillOrganizerPanel() method
	//Populate items from the database if you set the db_items property in TUIX
	//Call your fillOrganizerPanel() method
//You can skip these steps and not do an AJAX request by returning false instead,
//or do something different by returning a URL to a different PHP script
methods.returnAJAXURL = function() {
	return URLBasePath
		+ 'zenario/admin/organizer.ajax.php?path='
		+ encodeURIComponent(this.path)
		+ zenario.urlRequest(this.returnAJAXRequests());
};

//Returns the URL that the dev tools will use to load debug information.
//Don't override this function!
methods.returnDevToolsAJAXURL = function() {
	return methods.returnAJAXURL.call(this);
};

//Use this to add any requests you need to the AJAX URL used to call your panel
methods.returnAJAXRequests = function() {
	return {};
};

//You should return the page size you wish to use, or false to disable pagination
methods.returnPageSize = function() {
	return Math.max(20, Math.min(500, 1*zenarioA.adminSettings.organizer_page_size || 50));
};

//Sets the title shown above the panel.
//This is also shown in the back button when the back button would take you back to this panel.
methods.returnPanelTitle = function() {
	var title = this.tuix.title;
	
	if (window.zenarioOSelectMode && (this.path == window.zenarioOTargetPath || window.zenarioOTargetPath === false)) {
		if (window.zenarioOMultipleSelect && this.tuix.multiple_select_mode_title) {
			title = this.tuix.multiple_select_mode_title;
		} else if (this.tuix.select_mode_title) {
			title = this.tuix.select_mode_title;
		}
	}
	
	if (zenarioO.filteredView) {
		title += phrase.refined;
	}
	
	return title;
};


//Called whenever Organizer is resized - i.e. when the administrator resizes their window.
//It's also called on the first load of your panel after your showPanel() and setButtons() methods have been called.
methods.sizePanel = function($header, $panel, $footer, $buttons) {
	//...
};

//This is called when an admin navigates away from your panel, or your panel is about to be refreshed/reloaded.
methods.onUnload = function($header, $panel, $footer) {
	this.saveScrollPosition($panel);
};

//Remember where the admin had scrolled to.
//If we ever draw this panel again it would be nice to restore this to how it was
methods.saveScrollPosition = function($panel) {
	this.scrollTop = $panel.scrollTop();
	this.scrollLeft = $panel.scrollLeft();
};

//If this panel has been displayed before, try to restore the admin's previous scroll
//Otherwise show the top left (i.e. (0, 0))
methods.restoreScrollPosition = function($panel) {
	$panel
		.scrollTop(this.scrollTop || 0)
		.scrollLeft(this.scrollLeft || 0)
		.trigger('scroll');
};

methods.checkboxClick = function(id, e) {
	zenario.stop(e);
	
	var that = this;
	
	setTimeout(function() {
		that.itemClick(id, undefined, true);
	}, 0);
};


methods.itemClick = function(id, e, isCheckbox) {
	if (!this.tuix || !this.tuix.items[id]) {
		return false;
	}
	
	//If the admin is holding down the shift key...
	if (zenarioO.multipleSelectEnabled && !isCheckbox && (e || event).shiftKey && this.lastItemClicked) {
		//...select everything between the current item and the last item that they clicked on
		zenarioO.selectItemRange(id, this.lastItemClicked);
	
	//If multiple select is enabled and the checkbox was clicked...
	} else if (zenarioO.multipleSelectEnabled && isCheckbox) {
		//...toogle the item that they've clicked on
		if (this.selectedItems[id]) {
			this.deselectItem(id);
		} else {
			this.selectItem(id);
		}
		zenarioO.closeInspectionView();
		this.lastItemClicked = id;
	
	//If multiple select is not enabled and the checkbox was clicked
	} else if (!zenarioO.multipleSelectEnabled && isCheckbox && this.selectedItems[id]) {
		//...deselect everything if this row was already selected
		zenarioO.deselectAllItems();
		zenarioO.closeInspectionView();
		this.lastItemClicked = id;
	
	//Otherwise select the item that they've just clicked on, and nothing else
	} else {
		zenarioO.closeInspectionView();
		zenarioO.deselectAllItems();
		this.selectItem(id);
		this.lastItemClicked = id;
	}
	
	
	zenarioO.setButtons();
	zenarioO.setHash();
	
	return false;
};

//Return an object of currently selected item ids
//This should be an object in the format {1: true, 6: true, 18: true}
methods.returnSelectedItems = function() {
	return this.selectedItems;
};

//This method will be called when the CMS sets the items that are selected,
//e.g. when your panel is initially loaded.
//This is an object in the format {1: true, 6: true, 18: true}
//It is usually called before your panel is drawn so you do not need to update the state
//of the items on the page.
methods.cmsSetsSelectedItems = function(selectedItems) {
	this.selectedItems = selectedItems;
};

//This method should cause an item to be selected.
//It is called after your panel is drawn so you should update the state of your items
//on the page.
methods.selectItem = function(id) {
	this.selectedItems[id] = true;
	$(get('organizer_item_' + id)).addClass('organizer_selected');
	this.updateItemCheckbox(id, true);
};

//This method should cause an item to be deselected
//It is called after your panel is drawn so you should update the state of your items
//on the page.
methods.deselectItem = function(id) {
	delete this.selectedItems[id];
	$(get('organizer_item_' + id)).removeClass('organizer_selected');
	this.updateItemCheckbox(id, false);
};

//This updates the checkbox for an item, if you are showing checkboxes next to items,
//and the "all items selected" checkbox, if it is on the page.
methods.updateItemCheckbox = function(id, checked) {
	
	//Check to see if there is a checkbox next to this item first.
	var checkbox = get('organizer_itemcheckbox_' + id);
	
	if (checkbox) {
		$(get('organizer_itemcheckbox_' + id)).prop('checked', checked);
	}
	
	//Change the "all items selected" checkbox, if it is on the page.
	if (zenarioO.allItemsSelected()) {
		$('#organizer_toggle_all_items_checkbox').prop('checked', true);
	} else {
		$('#organizer_toggle_all_items_checkbox').prop('checked', false);
	}
};


//Return whether you are allowing multiple items to be selected in full and quick mode.
//(In select mode the opening picker will determine whether multiple select is allowed.)
methods.returnMultipleSelectEnabled = function() {
	return false;
};


//Whether to enable searching on a panel
methods.returnSearchingEnabled = function() {
	return false;
};

//Return whether you want to enable inspection view
methods.returnInspectionViewEnabled = function() {
	return false;
};

//Toggle inspection view
methods.toggleInspectionView = function(id) {
	if (id == zenarioO.inspectionViewItemId()) {
		this.closeInspectionView(id);
	
	} else {
		this.openInspectionView(id);
	}
};

//This method should open inspection view
methods.openInspectionView = function(id) {
	//...
};

//This method should close inspection view
methods.closeInspectionView = function(id) {
	//...
};

}, zenarioO.panelTypes);