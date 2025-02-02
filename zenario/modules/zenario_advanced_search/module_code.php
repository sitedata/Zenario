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

class zenario_advanced_search extends ze\moduleBaseClass {
	
	protected $mergeFields = [];
	protected $sections = [];
	protected $fields;
	protected $cTypeToSearch = '%all%';
	protected $searchString;
	protected $results;
	
	protected $category00_id = 0;
	protected $category01_id = 0;
	protected $category02_id = 0;
	protected $language_id = '';
	protected $keywords = '';
	
	public function init() {
		if ($_REQUEST['clearSearch'] ?? false) {
			$_REQUEST['language_id'] = $_POST['language_id'] = '0';
			$_REQUEST['category00_id'] = $_POST['category00_id'] = '0';
			$_REQUEST['category01_id'] = $_POST['category01_id'] = '0';
			$_REQUEST['category02_id'] = $_POST['category02_id'] = '0';
			$_REQUEST['category02_id'] = $_POST['category02_id'] = '0';
			$_REQUEST['searchString'] = $_POST['searchString'] = '';
			$_REQUEST['ctab'] = $_POST['ctab'] = $defaultTab;
		}
		
		$defaultTab = $this->setting('search_document')? 'document' : ($this->setting('search_html')? 'html' : 'news');
		$this->cTypeToSearch = (($_REQUEST['ctab'] ?? false) ?: $defaultTab);
		
		$this->allowCaching(
			$atAll = true, $ifUserLoggedIn = true, $ifGetSet = false, $ifPostSet = false, $ifSessionSet = true, $ifCookieSet = true);
		$this->clearCacheBy(
			$clearByContent = false, $clearByMenu = false, $clearByUser = false, $clearByFile = false, $clearByModuleData = false);
		
		$this->mergeFields = [];
		$this->sections = [];
		
		$this->sections['Search_Results'] = true;
		
		$this->mergeFields['Delay'] = (int) $this->setting('keyboard_delay_before_submit');
		
		$this->category00_id = (int)($_REQUEST['category00_id'] ?? false);
		if($this->category00_id) {
			$this->mergeFields['category00_id'] = $this->category00_id;
			if (count($this->getCategoryOptionsWithParentId($this->category00_id)) > 1) {
				$this->sections['HasCategory01'] = true;
			}
		}
		
		$this->category01_id = (int)($_REQUEST['category01_id'] ?? false);
		if($this->category01_id) {
			$this->mergeFields['category01_id'] = $this->category01_id;
			if (count($this->getCategoryOptionsWithParentId($this->category01_id)) > 1) {
				$this->sections['HasCategory02'] = true;
			}
		}
		
		$this->category02_id = (int)($_REQUEST['category02_id'] ?? false);
		if($this->category02_id) {
			$this->mergeFields['category02_id'] = $this->category02_id;
		}
		$this->language_id = $_REQUEST['language_id'] ?? false;
		$this->keywords = $_REQUEST['keywords'] ?? false;
		
		$this->category = false;
		if ($this->setting('enable_categories') && (int) ($_REQUEST['category'] ?? false)) {
			$this->category = $_REQUEST['category'] ?? false;
		}
		$this->searchString = substr($_REQUEST['searchString'] ?? '', 0, 100);
		$this->page = (int) ($_REQUEST['page'] ?? 1) ?: 1;
		
		return true;
	}
	
	public function getCategoryOptionsWithParentId($parentId = 0){
		
		$sql = "SELECT c.id, IFNULL(vp.local_text, c.name) as name
				FROM " . DB_PREFIX . "categories
					AS c LEFT JOIN " . DB_PREFIX . "visitor_phrases 
					AS vp ON CONCAT('_CATEGORY_', c.id) = vp.code
				WHERE parent_id=" . (int)$parentId . " AND c.public = 1 ORDER BY 2";
		$result = ze\sql::select($sql);
			
		$options = [];
		$options[0] =  $this->phrase('_ALL_CATEGORIES');
			
		while($row = ze\sql::fetchAssoc($result)){
			$options[$row['id']] = $row['name'];
		}
		return $options;
	}
	
	public function getCategory00Options(){
		return $this->getCategoryOptionsWithParentId(0);
	}
	
	public function getCategory01Options(){
		if(!$this->category00_id) return [];
		return $this->getCategoryOptionsWithParentId($this->category00_id);
	}
	
	public function getCategory02Options(){
		if(!$this->category01_id) return [];
		return $this->getCategoryOptionsWithParentId($this->category01_id);
	}
	
	public function getLanguagesOptions(){
		$options = [];
		$options[0] =  $this->phrase('_ALL_LANGUAGES');
		foreach (ze\lang::getLanguages() as $lang) {
			$options[$lang['id']] = $lang['language_local_name'];
		}
		return $options;
	}
	
	protected function setSearchFields() {
		$weights = 
			[
				'_NONE'		=> 0,
				'_LOW'		=> 1,
				'_MEDIUM'	=> 3,
				'_HIGH'		=> 12];
		
		$fields = [];
		$fields[] =	['name' => 'c.alias',				'weighting' => $weights[$this->setting('alias_weighting')]];
		$fields[] =	['name' => 'c.language_id',		'weighting' => 0];
		$fields[] =	['name' => 'v.title',				'weighting' => $weights[$this->setting('title_weighting')]];
		$fields[] =	['name' => 'v.keywords',			'weighting' => $weights[$this->setting('keywords_weighting')]];
		$fields[] =	['name' => 'v.description',		'weighting' => $weights[$this->setting('description_weighting')]];
		$fields[] =	['name' => 'v.filename',			'weighting' => $weights[$this->setting('filename_weighting')]];
		$fields[] =	['name' => 'v.content_summary',	'weighting' => $weights[$this->setting('content_summary_weighting')]];
		$fields[] =	['name' => 'v.feature_image_id',	'weighting' => 0];
		$fields[] =	['name' => 'cc.text',				'weighting' => $weights[$this->setting('content_weighting')]];
		$fields[] =	['name' => 'cc.extract',			'weighting' => $weights[$this->setting('extract_weighting')]];

		$this->fields = [];
		
		if ($this->setting('search_document')) {
			$this->fields['document'] = $fields;
		}
		
		if ($this->setting('search_html')) {
			$this->fields['html'] = $fields;
		}
		
		if ($this->setting('search_news')) {
			$this->fields['news'] = $fields;
		}
		
		
	}
	
	/* $parentId:
		false - do not check parentage 
		0 - select top level categories
		n - select children of n
	*/
	protected function getUsedCategories($parentId = false) {
		$sql = "
			SELECT DISTINCT
				c.id,
				c.parent_id,
				c.name,
				c.public
			FROM " . DB_PREFIX . "categories AS c
			INNER JOIN " . DB_PREFIX . "category_item_link AS cil
				ON c.id = cil.category_id AND c.public=1";

		if ($parentId!==false) {
			$sql .="
					WHERE 
						IFNULL(c.parent_id, 0)=" . (int) $parentId;
		}
		
		return ze\category::contentItemCategories($cID = false, $cType = false, $publicOnly = true, $langId = false, $sql);
	}
	
	protected function drawSubcategories($category, $fuse = 10) {
		$rv = "";
		if ($fuse) {
			$rv .= '<ul><li>
						<a class="search_category' . ($this->category  == $category['id']?'_on' :null) . ' ' .  
							$this->refreshPluginSlotAnchor('&category='. $category['id']. 
															'&ctab='. rawurlencode($this->cTypeToSearch). 
															'&searchString='. rawurlencode($this->searchString)) . 
							'> ' . htmlspecialchars($category['public_name']) . 
						'</a>';
			if ($childCategories = $this->getUsedCategories($category['id'])) {
				foreach ($childCategories as $childCategory) {
					$rv .=  $this->drawSubcategories($childCategory, $fuse - 1);
				}
			}
			$rv .= '</li></ul>';
		} 
		return $rv;
	}
	
	protected function drawCategories() {
		$cateoriesTree = "";
		if ($this->setting('enable_categories') && 
				($this->results) &&
					($this->sections['Search_Category'] = $this->getUsedCategories(0))) {
			foreach ($this->getUsedCategories(0) as $category) {
				$cateoriesTree .=  $this->drawSubcategories($category);
			}

			$this->sections['Search_Categories'] = true;
			$this->mergeFields['Search_Categories_Tree'] = $cateoriesTree;
			$this->mergeFields['All_Categories_Onclick'] = 
						$this->refreshPluginSlotAnchor(
							'&ctab='. rawurlencode($this->cTypeToSearch). '&searchString='. rawurlencode($this->searchString));
		}
	}
	
	
	public function showSlot() {
		
		if ($this->searchString || $this->setting('show_initial_results')) {
			$this->sections['Search_Result_Tabs'] = true;
		}
		
		$this->sections['HasCategory00'] = true;
		//$this->sections['HasCategory01'] = true;
		$languages = ze\lang::getLanguages();
		if (count($languages) > 1) {
			$this->sections['HasLanguageSelection'] = true;
		}
		
		if ($this->searchString) {
			$this->sections['Search_Result_Heading'] = true;
			$this->mergeFields['Search_Results_For'] = $this->phrase( '_SEARCH_RESULTS_FOR', ['term' => htmlspecialchars('"'. $this->searchString. '"')]);
		}
		
		$this->drawSearchBox();
		
		if ($this->searchString || $this->setting('show_initial_results')) {
			$this->doSearch();
			$type_to_search_results = &$this->results[$this->cTypeToSearch];
			
			switch ($this->cTypeToSearch) {
				case 'document':
					$Search_Result_Row = 'Search_Result_document_Row';
					$Search_No_Results = 'Search_No_Results_document';
					break;
				case 'news':
					$Search_Result_Row = 'Search_Result_news_Row';
					$Search_No_Results = 'Search_No_Results_news';
					break;
				default:
					$Search_Result_Row = 'Search_Result_Row';
					$Search_No_Results = 'Search_No_Results';
			}

			if ($type_to_search_results['Record_Count']) {
				$this->sections['Search_Result_Rows'] = true;
				
				$this->sections[$Search_Result_Row] = $type_to_search_results['search_results'];
				
				if ($type_to_search_results['pagination']) {
					$this->mergeFields['Search_Pagination'] = '';
					$this->pagination(
						'pagination_style',
						$this->page, $type_to_search_results['pagination'],
						$this->mergeFields['Search_Pagination']);
				}
			} else {
				$this->sections[$Search_No_Results] = true;
			}
		}
		
		if ($this->setting('search_placeholder')) {
			$this->sections['Placeholder'] = true;
			$this->sections['Placeholder_Phrase'] = $this->setting('search_placeholder_phrase');
		}
		
		$this->drawCategories();
			
		$this->framework('Outer', $this->mergeFields, $this->sections);
	}
	

	private function getSearchRequestParameters(){
		$request = '';
		if($this->category00_id ) $request .= '&category00_id=' . $this->category00_id;
		if($this->category01_id ) $request .= '&category01_id=' . $this->category01_id;
		if($this->category02_id ) $request .= '&category02_id=' . $this->category02_id;
		if($this->language_id ) $request .= '&language_id=' . $this->language_id;
		return $request;
	}


	function doSearch() {
		$this->setSearchFields();
		$this->results['Search_String'] = htmlspecialchars($this->searchString);
		
		//Launch a search on each Content Type in turn
		$this->results = [];
		foreach($this->fields as $cType => $fields) {
			$this->results[$cType] = $this->searchContent($cType, $fields);
		}
		
		//We'll only be displaying the details on one content type at a time
		//Sanitise the result set that we will be displaying, and prepare the url and other details
		$results_by_type = &$this->results[$this->cTypeToSearch];
		if ($results_by_type['Record_Count'] && $results_by_type['search_results']) {
			foreach($results_by_type['search_results'] as $i => &$result) {
				
				$result['Result_No'] = $results_by_type['offset'] + $i;
				
				$result['title']		= htmlspecialchars($result['title']);
				$result['keywords']		= htmlspecialchars($result['keywords']);
				$result['description']	= htmlspecialchars($result['description']);
				$result['language_id']	= htmlspecialchars($result['language_id']);
				$result['language_name']	= $this->setting('show_language_next_to_results') ? '<span>('.htmlspecialchars(ze\lang::name($result['language_id'], false)).')</span>': false;
				
				if ($this->setting('data_field') == 'description') {
					$result['content_bodymain'] = $result['description'];
				} elseif ($this->setting('data_field') == 'content_summary') {
					$result['content_bodymain'] = $result['content_summary'];
				}
				
				$requests = '';
				if($result['type'] == 'document') {
					$requests = 'download=1';
				}
				$result['url'] = htmlspecialchars($this->linkToItem($result['id'], $result['type'], false, $requests, $result['alias']));
				
				//breadcrumb
				$menu_item = ze\menu::getFromContentItem($result['id'], $result['type']);
				if($menu_item){
					$breadcrumb = ' &raquo; ' . $menu_item['name'];
					while($menu_item && $menu_item['parent_id']) {
						$menu_item = ze\menu::details($menu_item['parent_id'], ze::$visLang);
						if($menu_item ) $breadcrumb = ' &raquo; ' . $menu_item['name'] . $breadcrumb;
					}
					$result['Breadcrumb'] = $breadcrumb;
				}
				
				if($this->setting('show_default_stick_image')) {
					$img_tag = '';
					if($this->setting('sticky_image_show')) {
						$url_img = '';
						$width = (int)$this->setting('sticky_image_width');
						$height = (int)$this->setting('sticky_image_height');
							
						ze\file::imageLink($width, $height, $url_img, $result['feature_image_id'], $width, $height, $this->setting('sticky_image_canvas'));
						if ($url_img) {
							$img_tag =  '<img src="' . $url_img . '" />';
						}
					}
					$result['Sticky_image_HTML_tag'] = $img_tag;
					if (!$img_tag) {
						$this->getStyledExtensionIcon(pathinfo($result['filename'], PATHINFO_EXTENSION), $result);
					}
				}
			}
		}
		
		$this->sections['Search_Result_Tab'] = [];
		foreach($this->fields as $cType => $fields) {
			$results = &$this->results[$cType];
			$this->sections['Search_Result_Tab'][$cType] = [
					'Tab_On' => $results['Tab_On'],
					'Tab_Onclick' => $results['Tab_Onclick'],
					'Type' => $this->phrase('_'. $cType),
					'Record_Count' => $results['Record_Count']
			];
		}		
	}
	
	
	protected function drawSearchBox($cID = false, $cType = false) {
		
		$this->sections['Search_Box'] = true;
		$this->mergeFields['Open_Form'] = $this->openForm('', '', false, false, true, $usePost = true). $this->remember('ctab');
		$this->mergeFields['Close_Form'] = $this->closeForm();
		$this->mergeFields['Search_Field_ID'] = 'search_field_'. $this->containerId;
		$this->mergeFields['Search_String'] = htmlspecialchars($this->searchString);
	}
		
	protected function getLanguagesSQLFilter(){
		if($this->language_id) {
			return "
				AND c.language_id = '". ze\escape::sql($this->language_id). "' ";
		}
		return "";
	}
	
	
	protected function getCategoriesSQLFilter(){
		$category = $this->category02_id ? $this->category02_id : (
				$this->category01_id ? $this->category01_id : $this->category00_id);
		if($category) {
			return " INNER JOIN " . DB_PREFIX . "category_item_link cil 
				   ON cil.equiv_id = c.equiv_id
				  AND cil.content_type = c.type
				  AND cil.category_id = ". $category;
		}
		return "";
	}
	
	
	protected function searchContent($cType, $fields, $onlyShowFirstPage = false) {
	
		if (!is_array($fields)) {
			return false;
		}
		
		
		//Add fields to the query
		$sqlF = "SELECT v.id, v.type";
		foreach ($fields as $field) {
			if (substr($field['name'], 0, 3) != 'cc.') {
				$sqlF .= ", ". $field['name'];
			}
		}
		
		
		
		//Calculate the SQL needed for matching rows against the search terms
		$useCC = false;
		$sqlR = ", (";
		$sqlW = "";
		
		if($this->searchString) {
			//Calculate the search terms
			$searchTerms = ze\content::searchtermParts($this->searchString);
			foreach ($searchTerms as $searchTerm => $searchTermType) {
				
				$wildcard = "";
				$booleanSearch = "";
				if ($searchTermType == 'word') {
					$wildcard = "*";
					$searchTermTypeWeighting = 1;
				} else {
					$booleanSearch = " IN BOOLEAN MODE";
					$searchTermTypeWeighting = 2;
				}
				
				foreach ($fields as $field) {
					if ($field['weighting']) {
						if (substr($field['name'], 0, 3) == 'cc.') {
							$useCC = true;
						}
						
						if ($sqlW) {
							$sqlR .= " + ";
							$sqlW .= " OR ";
						}
							
						if ($field['name'] == 'c.alias' || $field['name'] == 'v.filename') {
							$sqlW .= "\n (". $field['name']. " LIKE '". ze\escape::sql($searchTerm). "%')";
							$sqlR .= "\n (". $field['name']. " LIKE '". ze\escape::sql($searchTerm). "%') * ". $searchTermTypeWeighting. " * ". $field['weighting'];
						} else {
							$sqlW .= "\n IF (l.search_type = 'simple', ". $field['name']. " LIKE '%". ze\escape::sql($this->searchString). "%', MATCH (". $field['name']. ") AGAINST ('". ze\escape::sql($searchTerm) .      $wildcard. "' IN BOOLEAN MODE) )";
							$sqlR .= "\n IF (l.search_type = 'simple', ". $field['name']. " LIKE '%". ze\escape::sql($this->searchString). "%', MATCH (". $field['name']. ") AGAINST ('". ze\escape::sql($searchTerm) . "'". $booleanSearch . ") ) * ". $searchTermTypeWeighting. " * ". $field['weighting'];
						}
					}
				}
			}
			
			$sqlR .= "
				) AS relevance";
		} else {
			$sqlR = "";
		}
		
		
		$joinSQL = "INNER JOIN 
						" . DB_PREFIX . "languages l
					ON
						c.language_id = l.id ";
		
		if ($useCC) {
			$joinSQL .= "
				INNER JOIN ". DB_PREFIX. "content_cache AS cc
				   ON cc.content_id = v.id
				  AND cc.content_type = v.type
				  AND cc.content_version = v.version";
		}
		
		$joinSQL .= $this->getCategoriesSQLFilter();
		
		
		if ($this->setting('show_private_items')) {
			$sql = ze\content::sqlToSearchContentTable($this->setting('hide_private_items'), '', $joinSQL);
		} else {
			$sql = ze\content::sqlToSearchContentTable(true, 'public', $joinSQL);
		}
		
		
		//Only select rows in the Visitor's language, and that match the search terms
		$sql .= $this->getLanguagesSQLFilter();
		if($sqlW) {
			$sql .= " AND (". $sqlW. ")";
		}
			  
		if ($cType != '%all%') {
			$sql .= "
			  AND v.type = '". ze\escape::sql($cType). "'";
		}
	
		$record_number = 1;
		$searchresults = false;
		$pagination = [];
		
		
		if (!$onlyShowFirstPage) {
			$result = ze\sql::select("SELECT COUNT(*) ". $sql);
			$row = ze\sql::fetchRow($result);
			$recordCount = $row[0];
			
			if ($recordCount > 0 && $cType == $this->cTypeToSearch) {
				
				$sql .= " ORDER BY ";
				if($this->searchString) {
					$sql .= " relevance DESC,";
				}
				$sql .= " c.id, c.type";
				
				if ($this->setting('use_pagination')) {
					$pageSize = (int) $this->setting('maximum_results_number') ?:999999;
					$numberOfPages = ceil($recordCount/$pageSize);
					
					for ($i=1;$i<=$numberOfPages;$i++) {
						$pagination[$i] = '&page='. $i. '&ctab='. rawurlencode($cType). $this->getSearchRequestParameters() . '&searchString='. rawurlencode($this->searchString);
					}
					
					if ($this->page == 1) {
						$record_number = 1;
					} else {
						$record_number = (($this->page - 1) * $pageSize) + 1;
					}
					
					$sql .= ze\sql::limit($this->page, $pageSize);
				
				} else {
					$pagination = false;
				}
				$result = ze\sql::select($sqlF. $sqlR. $sql);
			
				while ($row = ze\sql::fetchAssoc($result)) {
					if (!$searchresults) {
						$searchresults = [];
					}
					
					$searchresults[] = $row;
				}
			}
		
		} else {
			$recordCount = 0;
			$pagination = false;
			
			if ($cType == $this->cTypeToSearch) {

				$sql .= " ORDER BY ";
				if($this->searchString) {
					$sql .= " relevance DESC,";
				}
				$sql .= " c.id, c.type";
				
				if ($pageSize = $this->setting('maximum_results_number') ?:999999) {
					$sql .= "
						LIMIT ". (int)$pageSize;
				}
				
				$result = ze\sql::select($sqlF. $sqlR. $sql);
			
				while ($row = ze\sql::fetchAssoc($result)) {
					if (!$searchresults) {
						$searchresults = [];
					}
					
					$searchresults[] = $row;
					++$recordCount;
				}
			}
		}
		
		return [
			"Record_Count" => $recordCount,
			"search_results" => $searchresults,
			"pagination" => $pagination,
			"offset" => $record_number,
			"Tab_On" => $cType == $this->cTypeToSearch? '_on' :null,
			"Tab_Onclick" => $this->refreshPluginSlotAnchor('&ctab='. rawurlencode($cType). $this->getSearchRequestParameters() . '&searchString='. rawurlencode($this->searchString))
		];
		
		
	}
	
	
	
	
	public function fillAdminBox($path, $settingGroup, &$box, &$fields, &$values) {
		switch ($path) {
			case 'plugin_settings':
				$fields['first_tab/pagination_style']['values'] = ze\pluginAdm::paginationOptions();
				break;
		}
	}
	
	public function formatAdminBox($path, $settingGroup, &$box, &$fields, &$values, $changes) {
		switch ($path) {
			case 'plugin_settings':
				$fields['first_tab/hide_private_items']['hidden'] = 
					!$values['first_tab/show_private_items'];
				
				$fields['first_tab/sticky_image_show']['hidden'] = !$values['first_tab/show_default_stick_image'];
				$hidden = !($values['first_tab/show_default_stick_image'] && $values['first_tab/sticky_image_show']);
				$this->showHideImageOptions($fields, $values, 'first_tab', $hidden, 'sticky_image_');
				
				if ($box['tabs']['first_tab']['fields']['search_placeholder'] == true
					&& empty($box['tabs']['first_tab']['fields']['search_placeholder_phrase']['value'])) {
					$box['tabs']['first_tab']['fields']['search_placeholder_phrase']['value'] = 'Search the site';
				}
				
				break;
		}
	}
	
	public function validateAdminBox($path, $settingGroup, &$box, &$fields, &$values, $changes, $saving) {
		switch ($path) {
			case 'plugin_settings':
				$weightingSet = false;
				if (is_array($box['tabs']['weightings'])) {
					foreach ($box['tabs']['weightings'] as $weighting) {
						if ($weighting && $weighting != '_NONE') {
							$weightingSet = true;
							break;
						}
					}
				}
				
				if (!$weightingSet) {
					$box['tabs']['weightings']['errors'][] = ze\admin::phrase('Please choose at least one weighting.');
				}
				
				if ($values['first_tab/page_size'] == 'maximum_of' && $values['first_tab/maximum_results_number'] < 0) {
					$box['tabs']['first_tab']['fields']['maximum_results_number']['error'] = ze\admin::phrase('The page size cannot be a negative number.');
				} elseif ($values['first_tab/page_size'] == 'maximum_of' && $values['first_tab/maximum_results_number'] > 999) {
					$box['tabs']['first_tab']['fields']['maximum_results_number']['error'] = ze\admin::phrase('The page size cannot exceed 999.');
				}

				break;
		}
	}
	
}
