<?php
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
if (!defined('NOT_ACCESSED_DIRECTLY')) exit('This file may not be directly accessed');


class zenario_common_features__organizer__categories extends module_base_class {
	
	public function preFillOrganizerPanel($path, &$panel, $refinerName, $refinerId, $mode) {
		if ($path != 'zenario__content/panels/categories') return;
		
		if (!$refinerName && !in($mode, 'get_item_name', 'get_item_links')) {
			$panel['title'] = adminPhrase('Categories (top level)');
			$panel['db_items']['where_statement'] = $panel['db_items']['custom_where_statement_top_level'];
		}
		
		if ($refinerName && $refinerName != 'parent_category') {
			unset($panel['item']['link']);
		}
	}
	
	public function fillOrganizerPanel($path, &$panel, $refinerName, $refinerId, $mode) {
		if ($path != 'zenario__content/panels/categories') return;
		
		$langs = getLanguages();
		foreach($langs as $lang) {
			$panel['columns']['lang_'. $lang['id']] = array('title' => $lang['id']);
		}
		
		
		foreach ($panel['items'] as $id => &$item) {
			$item['traits'] = array();
			
			if ($item['public']) {
				$item['traits']['public'] = true;
				
				foreach($langs as $lang) {
						$item['lang_'. $lang['id']] =
							getRow('visitor_phrases', 'local_text',
										array('language_id' => $lang['id'], 'code' => '_CATEGORY_'. (int) $id, 'module_class_name' => 'zenario_common_features'));
				}
			}
			
			$item['children'] = countCategoryChildren($id);
			$item['path'] = getCategoryPath($id);
		}
		
		
		if (get('refiner__parent_category')) {
			$mrg = array(
				'category' => getCategoryName(get('refiner__parent_category')));
			$panel['title'] = adminPhrase('Sub-categories of "[[category]]"', $mrg);
			$panel['no_items_message'] = adminPhrase('Category "[[category]]" has no sub-categories.', $mrg);
		}
	}
	
	public function handleOrganizerPanelAJAX($path, $ids, $ids2, $refinerName, $refinerId) {
		if ($path != 'zenario__content/panels/categories') return;
		
		if (post('delete') && checkPriv('_PRIV_MANAGE_CATEGORY')) {
			foreach (explode(',', $ids) as $id) {
				zenario_common_features::deleteCategory($id);
			}
		}
	}
	
	public function organizerPanelDownload($path, $ids, $refinerName, $refinerId) {
		
	}
}