<?php
/*
 * Copyright (c) 2021, Tribal Limited
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
if (!defined('NOT_ACCESSED_DIRECTLY')) exit('This file may not be directly accessed');


class zenario_common_features__organizer__layouts extends ze\moduleBaseClass {
	
	public function preFillOrganizerPanel($path, &$panel, $refinerName, $refinerId, $mode) {
		if ($path != 'zenario__layouts/panels/layouts') return;
		
		if (ze::in($mode, 'full', 'quick', 'select')) {
			if (!ze\skinAdm::checkForChangesInFiles($runInProductionMode = true)) {
				ze\layoutAdm::checkForMissingFiles();
			}
		}
		
		if (isset($_GET['refiner__archived'])) {
			$panel['title'] = ze\admin::phrase('Archived Layouts');
			$panel['no_items_message'] = ze\admin::phrase('This is the graveyard for layouts that shouldn\'t be used for new content.');
			$panel['item']['css_class'] = 'archived_layout';
			
			$panel['db_items']['where_statement'] = $panel['db_items']['custom_where_statement__archived'];
			
			unset($panel['columns']['archived']['title']);
			unset($panel['columns']['default']);
			unset($panel['collection_buttons']);
		
		} elseif ($refinerName == 'content_type') {
			unset($panel['columns']['archived']['title']);
			$panel['no_items_message'] = ze\admin::phrase('There are no active layouts for this content type.');

		} elseif ($refinerName == 'layouts_using_form') {
			$mrg = [];
			if (ze\module::inc('zenario_user_forms')) {
				$mrg['name'] = zenario_user_forms::getFormName($refinerId);
			}
			$panel['title'] = ze\admin::phrase('Layouts using the form "[[name]]"', $mrg);
			$panel['no_items_message'] = ze\admin::phrase('There are no layouts using the form "[[name]]"', $mrg);
		
		} elseif ($mode == 'typeahead_search') {
			$panel['db_items']['where_statement'] = $panel['db_items']['custom_where_statement__typeahead_search'];
		
		} elseif ($refinerName || ze::in($mode, 'get_item_name', 'get_item_links')) {
			
			if (isset($panel['db_items']['custom_where_statement__without_unregistered'])) {
				$panel['db_items']['where_statement'] = $panel['db_items']['custom_where_statement__without_unregistered'];
			} else {
				unset($panel['db_items']['where_statement']);
			}
		
		}
		
		if (isset($_GET['refiner__content_type'])) {
			unset($panel['columns']['content_type']['title']);
		}
		
		if (isset($_GET['refiner__template_family'])) {
			unset($panel['columns']['family_name']['title']);
		}
	}
	
	public function fillOrganizerPanel($path, &$panel, $refinerName, $refinerId, $mode) {
		if ($path != 'zenario__layouts/panels/layouts') return;
		
		$panel['key']['disableItemLayer'] = true;
		
		if ($refinerName == 'content_type') {
			$panel['title'] = ze\admin::phrase('Layouts available for the "[[name]]" content type', ['name' => ze\content::getContentTypeName($refinerId)]);
			$panel['no_items_message'] = ze\admin::phrase('There are no layouts available for the "[[name]]" content type', ['name' => ze\content::getContentTypeName($refinerId)]);
		
		} elseif ($_GET['refiner__module_usage'] ?? false) {
			$mrg = [
				'name' => ze\module::displayName($_GET['refiner__module_usage'] ?? false)];
			$panel['title'] = ze\admin::phrase('Layouts on which the module "[[name]]" is used (layout layer)', $mrg);
			$panel['no_items_message'] = ze\admin::phrase('There are no layouts using the module "[[name]]".', $mrg);
		
		} elseif ($_GET['refiner__plugin_instance_usage'] ?? false) {
			$mrg = [
				'name' => ze\plugin::name($_GET['refiner__plugin_instance_usage'] ?? false)];
			$panel['title'] = ze\admin::phrase('Layouts on which the plugin "[[name]]" is used (layout layer)', $mrg);
			$panel['no_items_message'] = ze\admin::phrase('There are no layouts using the plugin "[[name]]".', $mrg);
		
		}
		
		$panel['columns']['content_type']['values'] = [];
		foreach (ze\content::getContentTypes() as $cType) {
			$panel['columns']['content_type']['values'][$cType['content_type_id']] = $cType['content_type_name_en'];
		}
		
		$foundPaths = [];
		$defaultLayouts = ze\row::getValues('content_types', 'default_layout_id', []);
		
		$templatePreview = '';
		
		foreach ($panel['items'] as $id => &$item) {
			$item['traits'] = [];
			
			//Format the layout Id
			if ($item['code']) {
				$item['code'] = ze\layoutAdm::codeName($item['code']);
			}
			
			//For each Template file that's not missing, check its size and check the contents
			//to see if it has grid data saved inside it.
			//Multiple layouts could use the same file, so store the results of this to avoid
			//wasting time scanning the same file more than once.
			if (empty($item['missing']) && !isset($foundPaths[$item['path']])) {
				if ($fileContents = @file_get_contents($item['path'])) {
					$foundPaths[$item['path']] = [
						'filesize' => strlen($fileContents),
						'checksum' => md5($fileContents),
						'grid' => ze\gridAdm::readCode($fileContents, true, true)
					];
				} else {
					$foundPaths[$item['path']] = false;
				}
			}
			unset($fileContents);
			
			if (empty($item['missing']) && !empty($foundPaths[$item['path']])) {
				$item['filesize'] = $foundPaths[$item['path']]['filesize'];
				
				if ($foundPaths[$item['path']]['grid']) {
					$item['traits']['grid'] = true;
				}
			} else {
				$item['missing'] = 1;
				$item['usage_status'] = 'missing';
			}
			
			
			//Numeric ids are Layouts
			if (is_numeric($id)) {
				
				if ($item['family_name'] == 'grid_templates') {
					$layoutDetails = ze\gridAdm::readLayoutCode($id);
					$summary = 'Gridmaker layout / ';
					if (!empty($layoutDetails['fluid'])) {
						$summary .= 'Fluid ';
					} else {
						$summary .= 'Fixed width ';
					}
					if (!empty($layoutDetails['responsive'])) {
						$summary .= '/ Responsive ';
					}
					if (!empty($layoutDetails['gCols'])) {
						$summary .= '/ '. $layoutDetails['gCols']. ' columns';
					}
				} else {
					$summary = 'Static';
				}
				
				$summary .= ' / Skin: ' . $item['skin_name'];
				
				$item['summary'] = $summary;
				
				if (!ze\row::exists('content_types', ['default_layout_id' => $id]) && !ze\row::exists('content_item_versions', ['layout_id' => $id])) {
					$item['traits']['deletable'] = true;
				
				}
				
				//Show how many items use a specific layout, and display links if possible.
				$usageContentItems = ze\layoutAdm::usage($id, false, false, false, $countItems = false);
				$usage = [
					'content_item' => $usageContentItems[0] ?? null,
					'content_items' => count($usageContentItems)
				];
		
				$usageLinks = [
					'content_items' => 'zenario__layouts/panels/layouts/item_buttons/view_content//'. (int) $id. '//'
				];
				$item['where_used'] = implode('; ', ze\miscAdm::getUsageText($usage, $usageLinks));
				
				// Try to automatically add a thumbnail
				if (!empty($foundPaths[$item['path']])) {
					$item['image'] = 'zenario/admin/grid_maker/ajax.php?thumbnail=1&width=180&height=130&loadDataFromLayout='. $id. '&checksum='. $foundPaths[$item['path']]['checksum'];
				}
				
				$item['row_class'] = ' layout_status_' . $item['status'];
				
			//Non-numeric ids are the Family and Filenames of Template Files that have no layouts created
			} else {
				$item['name'] = str_replace('.tpl.php', '', $item['template_filename']);
				$item['usage_status'] = $item['status'];
				$item['traits']['unregistered'] = true;
				$item['traits']['deletable'] = true;
			}
		}
	}
	
	public function handleOrganizerPanelAJAX($path, $ids, $ids2, $refinerName, $refinerId) {
		if ($path != 'zenario__layouts/panels/layouts') return;
		
		//Delete a template if it is not in use
		if (($_POST['delete'] ?? false) && ze\priv::check('_PRIV_EDIT_TEMPLATE')) {
			foreach (ze\ray::explodeAndTrim($ids) as $id) {
				if (!ze\row::exists('content_types', ['default_layout_id' => $id])
				 && !ze\row::exists('content_item_versions', ['layout_id' => $id])) {
				 	ze\layoutAdm::delete($id, true);
				}
			}
			ze\skinAdm::checkForChangesInFiles($runInProductionMode = true, $forceScan = true);
		
		//Archive a template
		} elseif (($_POST['archive'] ?? false) && ze\priv::check('_PRIV_EDIT_TEMPLATE')) {
			foreach (ze\ray::explodeAndTrim($ids) as $id) {
				if (!ze\row::exists('content_types', ['default_layout_id' => $id])) {
					ze\row::update('layouts', ['status' => 'suspended'], $id);
				}
			}
		
		//Restore a template
		} elseif (($_POST['restore'] ?? false) && ze\priv::check('_PRIV_EDIT_TEMPLATE')) {
			foreach (ze\ray::explodeAndTrim($ids) as $id) {
				ze\row::update('layouts', ['status' => 'active'], $id);
			}
		}
	}
	
	public function organizerPanelDownload($path, $ids, $refinerName, $refinerId) {
		
	}
}
