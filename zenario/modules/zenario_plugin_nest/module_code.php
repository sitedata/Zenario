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


//Define a class for holding information on conductor links
//(N.b. everything is public so it can be passed through json_encode() and sent to the client.)
class zenario_conductor__link {
	 public $command;
	 public $toState;
	 public $hVar = '';
	 public $bVar = '';
	 public $vars = [];
	 public $dRequests = [];
	 public $descendants = [];
	 public $cID;
	 public $cType;
	 
	 public function __construct($command, $toState, $vars = [], $descendants = '', $hVar = '') {
		$this->command = $command;
		$this->toState = $toState;
		$this->hVar = $hVar;
		
		if ($vars !== []) {
			//For each variable requested by the destination slide, check if we have it set here,
			//and if so, add it to a list of default requests.
			foreach ($vars as $var) {
				if (isset(ze::$vars[$var])) {
					$this->dRequests[$var] = ze::$vars[$var];
				
				} elseif (isset(ze::$importantGetRequests[$var])) {
					if (isset($_REQUEST[$var])) {
						$this->dRequests[$var] = $_REQUEST[$var];
					}
				
				} else {
					$this->dRequests[$var] = '';
				}
				
				
				//If this command has a hierarchical variable, e.g. dataPoolId,
				//and has a variable that matches, e.g. dataPoolId3, then note that down.
				if ($hVar !== ''
				 && ze\ring::chopPrefix($hVar, $var)) {
					$this->bVar = $var;
				}
			}
		}
		if ($descendants !== '') {
			$this->descendants = ze\ray::explodeAndTrim($descendants);
		}
	 }
	 
	 public function link($requests, $itemId = null) {
	 	
		//Handle links to other content items
	 	if ($this->cID) {
	 		//Clear any requests that point to this nest/slide/state
			unset($requests['state']);
			unset($requests['slideId']);
			unset($requests['slideNum']);
			
			//Set the state or slide that we're linking to
			if (is_numeric($this->toState)) {
				$requests['slideNum'] = $this->toState;
			} else {
				$requests['state'] = $this->toState;
			}
			
			return ze\link::toItem($this->cID, $this->cType, false, $requests);
		
		//Handle links to other states/slides
		} else {
			
			$dRequests = $this->dRequests;
			
			//Ignore any requests if this is a back link
			if (!empty($requests)
			 && $this->command != 'back') {
				//Look through the requests this slide takes, and override the defaults
				//with any specific values set here.
				foreach ($dRequests as $var => &$val) {
					if (isset($requests[$var])) {
						$var = $requests[$var];
					}
				}
				unset($val);
			
				//Catch the case where a basic variable (e.g. dataPoolId) is in the request,
				//but we need a hierarchical variable (e.g. dataPoolId3).
				if ($this->bVar !== ''
				 && empty($dRequests[$this->bVar])
				 && isset($requests[$this->hVar])) {
					$dRequests[$this->bVar] = $requests[$this->hVar];
				}
			}
			
			$dRequests['state'] = $this->toState;
			unset($dRequests['slideId']);
			unset($dRequests['slideNum']);
			
			//Automatically unset any empty requests
			foreach ($dRequests as $var => $val) {
				if (empty($val)) {
					unset($dRequests[$var]);
				}
			}
			
			return ze\link::toItem(ze::$cID, ze::$cType, false, $dRequests, ze::$alias);
		}
	}
}


class zenario_plugin_nest extends ze\moduleBaseClass {
	
	protected static $addedSubtitle = false;
	
	protected $firstTab = false;
	protected $lastTab = false;
	protected $slideNum = false;
	protected $slideId = false;
	protected $slideLayoutId = false;
	protected $state = false;
	protected $usesConductor = false;
	protected $commands = [];
	protected $forwardCommand = false;
	protected $statesToSlides = [];
	protected $editingTabNum = false;
	protected $mergeFields = [];
	protected $sections = [];
	protected $tabs = [];
	protected $modules = [];
	protected $show = false;
	protected $minigrid = [];
	protected $minigridInUse = false;
	protected $usedColumns = 0;
	protected $groupingColumns = 0;
	protected $maxColumns = false;
	protected $sameHeight = false;
	protected $showSlideInfo = false;
	
	public $banner_canvas = false;
	public $banner_width = 0;
	public $banner_height = 0;
	public $banner__enlarge_image = false;
	public $banner__enlarge_canvas = false;
	public $banner__enlarge_width = 0;
	public $banner__enlarge_height = 0;
	
	protected $currentRequests = [];

	public function getSlides() {
		return $this->slides;
	}
	public function getSlideNum() {
		return $this->slideNum;
	}
	public function getState() {
		return $this->state;
	}
	
	
	public function init() {
		
		$hideBackButtonIfNeeded = false;
		
		//Flag that this plugin is actually a nest
		ze::$slotContents[$this->slotName]['is_nest'] = true;
		
		$conductorEnabled = (bool) $this->setting('enable_conductor');
		$this->sameHeight = (bool) $this->setting('eggs_equal_height');
		
		$this->allowCaching(
			$atAll = true, $ifUserLoggedIn = false, $ifGetSet = false, $ifPostSet = false, $ifSessionSet = true, $ifCookieSet = true);
		$this->clearCacheBy(
			$clearByContent = false, $clearByMenu = false, $clearByUser = false, $clearByFile = false, $clearByModuleData = false);
		
		$specificEgg = $this->specificEgg();
		
		if ($specificEgg && $specificEgg > 0) {
			
			$this->slideNum = ze\row::get('nested_plugins', 'slide_num', $specificEgg) ?: 1;
			
			$slide = ze\row::get('nested_plugins', ['id', 'states'], ['is_slide' => 1, 'slide_num' => $this->slideNum, 'instance_id' => $this->instanceId]);
			$this->slideId = $slide['id'];
			
			if ($slide['states'] !== '') {
				$states = explode(',', $slide['states']);
				if (isset($_REQUEST['state'])
				 && in_array($_REQUEST['state'], $states)) {
					$this->state = $_REQUEST['state'];
				} else {
					$this->state = $states[0];
				}
			}
			
			$this->loadTabs();
		
		} else {
		
			if ($this->loadTabs()) {
				
				//Check to see if a slide or a state is requested in the URL
				$lookForState =
				$lookForSlideId =
				$lookForSlideNum = 
				$defaultState = false;
				
				if ($conductorEnabled
				 && !empty($_REQUEST['state'])
				 && preg_match('/^[ab]?[a-z]$/i', $_REQUEST['state'])) {
					$lookForState = strtolower($_REQUEST['state']);
				
				} elseif ($lookForSlideId = (int) ($_REQUEST['slideId'] ?? false)) {
				} elseif ($lookForSlideNum = (int) ($_REQUEST['slideNum'] ?? false)) {
				}
				
				
				$tabOrd = 0;
				foreach ($this->slides as $slide) {
					++$tabOrd;
					$this->lastTab = $slide['id'];
					
					//By default, show the first slide that the visitor can see...
					if ($tabOrd == 1) {
						$this->firstSlide = $slide['id'];
						$this->slideNum = $slide['slide_num'];
						$this->slideId = $slide['id'];
						$this->state = $slide['states'][0];
						$defaultState = $slide['states'][0];
					}
					
					//...but change this to the one mentioned in the request, if we see it
					if ($lookForState && in_array($lookForState, $slide['states'])) {
						$this->slideNum = $slide['slide_num'];
						$this->slideId = $slide['id'];
						$this->state = $lookForState;
					
					} elseif ($lookForSlideId == $slide['id']) {
						$this->slideNum = $slide['slide_num'];
						$this->slideId = $slide['id'];
						$this->state = $slide['states'][0];
					
					} elseif ($lookForSlideNum == $slide['slide_num']) {
						$this->slideNum = $slide['slide_num'];
						$this->slideId = $slide['id'];
						$this->state = $slide['states'][0];
					}
					
					$tabIds[$slide['slide_num']] = $slide['id'];
					
					
					if (!isset($this->sections['Tab'])) {
						$this->sections['Tab'] = [];
					}
					
					$tabMergeFields = [
						'TAB_ORDINAL' => $tabOrd];
					
					if (!$slide['invisible_in_nav']) {
						$tabMergeFields['Class'] = 'tab_'. $tabOrd. ' tab';
						$tabMergeFields['Tab_Link'] = $this->refreshPluginSlotTabAnchor('slideId='. $slide['id'], false);
						$tabMergeFields['Tab_Name'] = $this->formatTitleText($slide['name_or_title'], true);
					}
					
					if ($conductorEnabled
					 && $this->slideNum == $slide['slide_num']) {
					 	
					 	if ($slide['show_back']) {
							$tabMergeFields['Show_Back'] = true;
							$hideBackButtonIfNeeded = (bool) $slide['no_choice_no_going_back'];
					 	}
						$tabMergeFields['Show_Refresh'] = (bool) $slide['show_refresh'];
						$tabMergeFields['Show_Auto_Refresh'] = (bool) $slide['show_auto_refresh'];
						$tabMergeFields['Auto_Refresh_Interval'] = (int) $slide['auto_refresh_interval'];
						$tabMergeFields['Last_Updated'] = ze\date::formatTime(time(), '%H:%i:%S');
					}
					
					//Set up the embed link
					if ($slide['show_embed']) {
						
						if (!ze::in(ze::setting('xframe_options'), 'all', 'specific')) {
							$tabMergeFields['Show_Embed_Disabled'] = true;
						
						} else {
							$embedLink = ze\link::toItem(
								ze::$cID, ze::$cType, $fullPath = true, $request = '&zembedded=1&method_call=showSingleSlot&slotName='. $this->slotName,
								ze::$alias, $autoAddImportantRequests = true, $forceAliasInAdminMode = true);
							
							$mergefields = [
								'title' => $this->phrase('Embed this plugin on a third-party website'),
								'desc' => $this->phrase('You can display this plugin (part of this page) on another website.'),
								'link' => $embedLink,
								'copy' => $this->phrase('Copy'),
								'copied' => $this->phrase('Copied to clipboard')
							];
							
							if ('public' != $slide['privacy']
							 || 'public' != ze\sql::fetchValue("
												SELECT privacy
												FROM ". DB_PREFIX. "translation_chains
												WHERE equiv_id = ". (int) ze::$equivId. "
												  AND type = '". ze\escape::sql(ze::$cType). "'")
							) {
								$mergefields['auth_warning'] = $this->phrase('Warning: this page is password-protected, so users will need to be authenticated to this site before they can view the content.');
							}
							
							
							$tabMergeFields['Show_Embed'] = true;
							$tabMergeFields['Embed'] = json_encode($mergefields);
							
							ze::requireJsLib('zenario/libs/yarn/toastr/toastr.min.js', 'zenario/libs/yarn/toastr/build/toastr.min.css');
						}
					}
					
					$tabMergeFields['Slide_Class'] = 'slide_'. $slide['slide_num']. ' '. $slide['css_class'];
					
					$this->sections['Tab'][$slide['slide_num']] = $tabMergeFields;
				}
				
				if (isset($this->sections['Tab'][$this->slideNum]['Class'])) {
					$this->sections['Tab'][$this->slideNum]['Class'] .= '_on';
				}
				
				
				$nextSlideId = false;
				if ($this->lastTab == $this->slideId) {
					if (!$this->setting('next_prev_buttons_loop')) {
						$this->mergeFields['Next_Disabled'] = '_disabled';
					} else {
						$nextSlideId = $this->firstSlide;
					}
				} else {
					foreach ($this->slides as $slideNum => $slide) {
						if ($slideNum > $this->slideNum) {
							$nextSlideId = $slide['id'];
							break;
						}
					}
				}
				
				if ($nextSlideId) {
					$this->mergeFields['Next_Link'] = $this->refreshPluginSlotTabAnchor('slideId='. $nextSlideId, false);
				}
				
				
				$prevSlideId = false;
				if ($this->firstSlide == $this->slideId) {
					if (!$this->setting('next_prev_buttons_loop')) {
						$this->mergeFields['Prev_Disabled'] = '_disabled';
					} else {
						$prevSlideId = $this->lastTab;
					}
				} else {
					foreach ($this->slides as $slideNum => $slide) {
						if ($slideNum >= $this->slideNum) {
							break;
						} else {
							$prevSlideId = $slide['id'];
						}
					}
				}
				
				if ($prevSlideId) {
					$this->mergeFields['Prev_Link'] = $this->refreshPluginSlotTabAnchor('slideId='. $prevSlideId, false);
				}
				
				$this->registerGetRequest('slideId', $this->firstSlide);
				$this->registerGetRequest('state', $defaultState);
			}
			
			if (!empty($this->slides[$this->slideNum]['request_vars'])) {
				foreach ($this->slides[$this->slideNum]['request_vars'] as $var) {
					$this->registerGetRequest($var);
				}
			}
		}
		
		//Load all of the paths from the current state
		if ($conductorEnabled && $this->state) {
			
			//Add a refresh command to the current state
			$this->commands['refresh'] = new zenario_conductor__link(
				'refresh', $this->state,
				$this->slides[$this->slideNum]['request_vars'], '',
				$this->slides[$this->slideNum]['hierarchical_var']
			);
			
			
			//Loop through each slide, checking if they have any states or global commands
			$hadCommands = [];
			foreach ($this->slides as $slideNum => $slide) {
				
				//If a global command is set on a slide, it should point to the first state on that slide.
				$first = true;
				foreach ($slide['states'] as $state) {
					if ($state) {
						if ($first) {
							$first = false;
						
							//If this slide has a global command set, note it down
							//N.b. if two slides have the same global command, then go to the slide with the lowest ordinal.
							if (($command = $slide['global_command'])
							 && !isset($hadCommands[$command])) {
								
								//Don't allow the link if we're already in that state...
								if ($state != $this->state) {
									
									$this->commands[$command] = new zenario_conductor__link(
										$command, $state, $slide['request_vars'], '', $slide['hierarchical_var']
									);
									$this->usesConductor = true;
								}
								
								//...but do block it, so we get consistent logic if two slides have the same global command.
								$hadCommands[$command] = true;
							}
						}
					
					
						//Note down which states are on which slides
						$this->statesToSlides[$state] = $slideNum;
					}
				}
			}
			unset($hadCommands);
			
			//Look through the nested paths that lead from this slide, and note each down
			//as long as it leads to another slide that we can see.
			$sql = "
				SELECT to_state, equiv_id, content_type, command, descendants, hierarchical_var, is_custom, is_forwards
				FROM ". DB_PREFIX. "nested_paths
				WHERE instance_id = ". (int) $this->instanceId. "
				  AND from_state = '". ze\escape::sql($this->state). "'
				ORDER BY to_state";
			
			foreach (ze\sql::fetchAssocs($sql) as $path) {
				$state = $path['to_state'];
				
					
				//Handle links to other content items
				if ($path['equiv_id']) {
					
					$cID = $path['equiv_id'];
					$cType = $path['content_type'];
					ze\content::langEquivalentItem($cID, $cType);
					
					if (!ze\content::checkPerm($cID, $cType)) {
						continue;
					}
					
					foreach (ze\ray::explodeAndTrim($path['command']) as $command) {
						$this->commands[$command] = new zenario_conductor__link($command, $state);
						$this->commands[$command]->cID = $cID;
						$this->commands[$command]->cType = $cType;
					}
				
				//Handle links to other slides
				} elseif (isset($this->statesToSlides[$state])) {
					
					$slideNum = $this->statesToSlides[$state];
					
					foreach (ze\ray::explodeAndTrim($path['command']) as $command) {
						
						//If this is a custom path or a back button, use the pathing info from the slide,
						//as this is more likely to be accurate.
						if ($path['is_custom'] || $command == 'back') {
							$path['hierarchical_var'] = $this->slides[$slideNum]['hierarchical_var'];
						}
					
						$this->commands[$command] = new zenario_conductor__link(
							$command,
							$state,
							$this->slides[$slideNum]['request_vars'],
							$path['descendants'],
							$path['hierarchical_var']
						);
					
						if ($path['is_forwards']) {
							$this->forwardCommand = $command;
						}
					}
				}
				$this->usesConductor = true;
			}
			
			if ($this->usesConductor && !$specificEgg) {
				
				$vars = array_filter(array_merge($_GET, ze::$vars));
				
				unset(
					//Clear any standard content item variables
					$vars['cID'], $vars['cType'], $vars['cVersion'], $vars['visLang'],
					
					//Clear any standard plugin variables
					$vars['slotName'], $vars['instanceId'], $vars['method_call'],
					
					//Clear any requests that point to this nest/slide/state
					$vars['state'], $vars['slideId'], $vars['slideNum'],
					
					//Clear some FEA variables
					$vars['mode'], $vars['path']
				);
				
				$this->callScript('zenario_conductor', 'setCommands', $this->slotName, $this->commands, $this->state, $vars);
				
				//Add the current title of the current conductor slide to the page title
				//(Though use a static variable to stop this happening twice if there are two nests on the same page.)
				if (!self::$addedSubtitle) {
					self::$addedSubtitle = true;
					$this->setPageTitle(ze::$pageTitle. ': '. $this->formatTitleText($this->slides[$this->slideNum]['name_or_title']));
				}
				
				
				if ($hideBackButtonIfNeeded) {
					if (($backToState = $this->getBackState())
					 && ($backs = $this->getBackLinks())
					 && (!empty($backs[$backToState]['smart']))
					 && (count($backs[$backToState]['smart']) > 1)) {
					} else {
						$this->sections['Tab'][$this->slideNum]['Show_Back'] = false;
					}
				}
				
			}
		}
		
		
		//If all tabs were hidden, don't show anything
		if ($this->slideNum !== false && $this->loadTab($this->slideNum)) {
			$this->show = true;
		
		//...except if no tabs exist, don't hide anything
		} elseif (!ze\row::exists('nested_plugins', ['instance_id' => $this->instanceId, 'is_slide' => 1]) && $this->loadTab($this->slideNum = 1)) {
			$this->show = true;
		}
		
		return $this->show;
	}
	
	//This function gets all of the breadcrumbs leading up to the current slide.
	//It also gets the smart breadcrumbs at each stage.
	protected $cachedBackLinks = null;
	public function getBackLinks() {
		
		if ($this->cachedBackLinks === null) {
			$this->cachedBackLinks = require ze::funIncPath(__FILE__, __FUNCTION__);
		}
		return $this->cachedBackLinks;
	}
	
	protected function getBackState() {
		if (!empty($this->commands['back'])
		 && empty($this->commands['back']->cID)) {
			return $this->commands['back']->toState;
		}
		return false;
	}
	
	public function formatTitleText($text, $htmlescape = false) {
		
		//The old Tribiq frameworks need things escaped, so put this case in for them.
		//(Note that for backwards compatability reasons the new Twig frameworks are also working like this)
		if ($htmlescape) {
			$text = htmlspecialchars($text);
		}
		
		//If this is a library plugin, and therefore multilingual, we need to translate the text here
		if ($this->inLibrary) {
			$text = $this->phrase($text);
		}
		
		//Break the title up by mergefields, using the [[merge_field_name]] syntax
		$frags = explode('[[', $text);
		$count = count($frags);
	
		if ($count > 1) {
			$text = $frags[0];
			for ($i = 1; $i < $count; ++$i) {
			
				$part = explode(']]', $frags[$i], 2);
			
				if (isset($part[1])) {
					
					
					//Look for variables from modules, using the syntax [[module_class_name:var_name]]
					$details = explode(':', $part[0], 2);
					
					if (isset($details[1])
					 && ze\module::inc($details[0])) {
						
						$val = call_user_func([$details[0], 'requestVarMergeField'], $details[1]);
					
					//Allow any id from the $_REQUEST or core vars to be displayed
					} elseif (isset(ze::$vars[$details[0]])) {
						$val = ze::$vars[$details[0]];
					
					} elseif (isset($_REQUEST[$details[0]])) {
						$val = $_REQUEST[$details[0]];
					
					} else {
						$val = '';
					}
				
					if ($htmlescape) {
						$text .= htmlspecialchars($val);
					} else {
						$text .= $val;
					}
					
					//Anything that's not a mergefield should be left as-is
					$text .= $part[1];
				} else {
					$text .= $part[0];
				}
			}
		}
		
		return $text;
	}
	
	public static function formatTitleTextAdmin($text, $htmlescape = false) {
		if ($htmlescape) {
			$text = htmlspecialchars($text);
		}
		
		$frags = explode('[[', $text);
		$count = count($frags);
	
		if ($count > 1) {
			$text = $frags[0];
			for ($i = 1; $i < $count; ++$i) {
			
				$part = explode(']]', $frags[$i], 2);
			
				if (isset($part[1])) {
					//Look for variables from modules, using the syntax [[module_class_name:var_name]]
					$details = explode(':', $part[0], 2);
					
					if (isset($details[1])
					 && ze\module::inc($details[0])) {
						
						$val = call_user_func([$details[0], 'requestVarDisplayName'], $details[1]);
						
						if ($htmlescape) {
							$text .= '<i>'. htmlspecialchars($val). '</i>';
						} else {
							$text .= $val;
						}
							
					
					} else {
						$text .= $part[0];
					}
				
					
					//Anything that's not a mergefield should be left as-is
					$text .= $part[1];
				} else {
					$text .= $part[0];
				}
			}
		}
		
		return $text;
	}
	
	//Allow FABs to call the formatTitleTextAdmin() function via AJAX
	public function handleAJAX() {
		if (isset($_REQUEST['formatTitleTextAdmin']) && ze\priv::check()) {
			echo self::formatTitleTextAdmin($_REQUEST['formatTitleTextAdmin'], (bool) ze::request('htmlescape'));
		}
	}
	
	public function showSlot() {
		
		//For super-users, when using slide designer, show a button at the top to show info about the selected slide,
		//and some other tools.
		if ($this->showSlideInfo) {
			echo '
				<x-zenario-superuser-slide-controls>
					<x-zenario-superuser-slide-info
						onclick="zenario_plugin_nest.initSlideInfoPopout(
							\'', ze\escape::js($this->containerId), '\',
							', (int) $this->slideLayoutId, '
						);"
						title="', htmlspecialchars($this->phrase('Slide info')), '"
					></x-zenario-superuser-slide-info>
				</x-zenario-superuser-slide-controls>
			';
			
			//I can't work out how to center-align the thing using CSS, so I'm using JavaScript to do it instead.
			$this->callScript('zenario_plugin_nest', 'setSlideControlWidth', $this->containerId);
		}
		
		
		
		$this->mergeFields['TAB_ORDINAL'] = $this->slideNum;
		
		//Show a single plugin in the nest
		if ($this->checkShowInFloatingBoxVar()) {
			if ($this->show) {
				
				$ord = 0;
				foreach ($this->modules[$this->slideNum] as $eggId => $slotNameNestId) {
					$this->mergeFields['PLUGIN_ORDINAL'] = ++$ord;
					
					if (!empty(ze::$slotContents[$slotNameNestId]['class'])) {
						if (ze::$slotContents[$slotNameNestId]['class']->checkShowInFloatingBoxVar()) {
							$this->showPlugin($slotNameNestId);
						}
					}
				}
			}
		
		//Show all of the plugins on this slideId
		} else {
			$this->mergeFields['Tabs'] = $this->sections['Tab'] ?? null;
			
			if ($this->show) {
				$this->mergeFields['Tabs'][$this->slideNum]['Plugins'] = $this->modules[$this->slideNum];
			}
			$this->twigFramework($this->mergeFields);
		}
	}
	
	
	protected function loadTabs() {
		
		$sql = "
			SELECT
				id, id AS slide_id,
				slide_num, css_class, name_or_title, use_slide_layout,
				states, show_back, no_choice_no_going_back, show_embed, show_refresh, show_auto_refresh, auto_refresh_interval,
				request_vars, hierarchical_var, global_command,
				invisible_in_nav,
				privacy, at_location, smart_group_id, module_class_name, method_name, param_1, param_2, always_visible_to_admins
			FROM ". DB_PREFIX. "nested_plugins
			WHERE instance_id = ". (int) $this->instanceId. "
			  AND is_slide = 1
			ORDER BY slide_num";
		
		$result = ze\sql::select($sql);
		$sqlNumRows = ze\sql::numRows($result);
		
		if (!$sqlNumRows) {
			//When a nest is first inserted, it will be empty.
			//This also sometimes happens after a site migration.
			//In this case, call the resyncNest function,
			//e.g. to ensure there is at least one slide and fix any other possibly invalid date
			self::resyncNest($this->instanceId);
			$result = ze\sql::select($sql);
			$sqlNumRows = ze\sql::numRows($result);
		}
		
		if (!$sqlNumRows) {
			return false;
		} else {
			while ($row = ze\sql::fetchAssoc($result)) {
				$row['states'] = explode(',', $row['states']);
				$row['request_vars'] = ze\ray::explodeAndTrim($row['request_vars']);
				
				$this->slides[$row['slide_num']] = $row;
			}
			
			
			$this->mergeFields['Nest'] = '';
			
			$this->removeHiddenTabs($this->slides, $this->cID, $this->cType, $this->cVersion, $this->instanceId);
			
			
			if ($this->setting('banner_canvas')
			 && $this->setting('banner_canvas') != 'unlimited') {
				$this->banner_canvas = $this->setting('banner_canvas');
				$this->banner_width = (int) $this->setting('banner_width');
				$this->banner_height = (int) $this->setting('banner_height');
			}
			
			if ($this->setting('enlarge_image')) {
				$this->banner__enlarge_image = true;
				$this->banner__enlarge_canvas = $this->setting('enlarge_canvas');
				$this->banner__enlarge_width = (int) $this->setting('enlarge_width');
				$this->banner__enlarge_height = (int) $this->setting('enlarge_height');
			}
			
			
			return !empty($this->slides);
		}
	}
	
	protected function loadTab($slideNum) {
		
		
		//This bit of code prevents a small bug/exploit, where a user could show the wrong data pool
		//on the wrong level slide by hacking the URL in a very specific way.
		if ($this->usesConductor && $this->slideNum) {
			if ($this->slides[$this->slideNum]['hierarchical_var'] === 'dataPoolId') {
				foreach ($this->slides[$this->slideNum]['request_vars'] as $var) {
					if (false !== ze\ring::chopPrefix('dataPoolId', $var)) {
						if (isset(ze::$vars[$var])) {
							ze::$vars['dataPoolId'] = ze::$vars[$var];
						}
					}
				}
			}
		}
		
		
		$slEggId = 0;
		$eggs = [];
		$bespokeSettings = [];
		
		//Automatically add a breadcrumb plugin to every slide, if requested in the overall-nest's plugin settings
		if ($this->setting('bc_add') && ($moduleId = ze\row::get('modules', 'id', ['class_name' => 'zenario_breadcrumbs', 'status' => 'module_running']))) {
			
			--$slEggId;
			
			$egg = [
				'id' => $slEggId,
				'slide_num' => $slideNum,
				'ord' => -1,
				'module_id' => $moduleId,
				'framework' => 'standard',
				'css_class' => '',
				'cols' => (int) $this->setting('bc_cols'),
				'small_screens' => 'show'
			];
			
			$bespokeSettings[$slEggId] = [
				'menu_section' => $this->setting('bc_menu_section'),
				'breadcrumb_trail' => $this->setting('bc_breadcrumb_trail'),
				'breadcrumb_prefix_menu' => $this->setting('bc_breadcrumb_prefix_menu'),
				'breadcrumb_trail_separator' => $this->setting('bc_breadcrumb_trail_separator'),
				'add_conductor_slides' => $this->setting('enable_conductor')
			];
			
			$eggs[] = $egg;
		}
		
		# I was playing around with adding a timezone plugin to each slide in a conductor,
		# but we changed our minds.
		//if ($this->setting('tz_add') && ($moduleId = ze\row::get('modules', 'id', ['class_name' => 'zenario_timezones', 'status' => 'module_running']))) {
		//	
		//	foreach ($eggs as &$prEgg) {
		//		--$prEgg['ord'];
		//	}
		//	
		//	--$slEggId;
		//	
		//	$egg = [
		//		'id' => $slEggId,
		//		'slide_num' => $slideNum,
		//		'ord' => -1,
		//		'module_id' => $moduleId,
		//		'framework' => 'standard',
		//		'css_class' => '',
		//		'cols' => (int) $this->setting('tz_cols'),
		//		'small_screens' => 'show'
		//	];
		//	
		//	$bespokeSettings[$slEggId] = [];
		//	
		//	$eggs[] = $egg;
		//}
		
		
		$ord = 0;
		$specificEgg = $this->specificEgg();
		
		//Don't look up details on the other plugins if this is an AJAX request for one of the slide-designer plugins
		if (!$specificEgg || $specificEgg > 0) {
			
			//Look up every nested plugin in this slide
			$sql = "
				SELECT np.id, np.slide_num, np.ord, np.module_id, np.framework, np.css_class, np.cols, np.small_screens
				FROM ". DB_PREFIX. "nested_plugins AS np
				WHERE np.instance_id = ". (int) $this->instanceId. "
				  AND np.is_slide = 0
				  AND np.slide_num = ". (int) $slideNum;
			
			//If this is an AJAX request for a specific plugin, don't load any of the others
			if ($specificEgg = $this->specificEgg()) {
				$sql .= "
				  AND np.id = ". (int) $specificEgg;
			}
		
			//N.b. exclude plugins with the "Hidden, breadcrumbs only" option set
			$sql .= "
				  AND np.makes_breadcrumbs != 3
				ORDER BY np.ord";
		
			$result = ze\sql::select($sql);
			while ($egg = ze\sql::fetchAssoc($result)) {
				$ord = $egg['ord'];
				$eggs[] = $egg;
			}
		}
		
		//Don't look up details on the slide-designer plugins if this is an AJAX request for one of the regular ones
		if (!$specificEgg || $specificEgg < 0) {
		
			//Check if this slide is flagged as using a slide layout
			if ('' !== $this->slides[$slideNum]['use_slide_layout']) {
			
				//Work out which slide layout this should be for
				$sLayoutFor = $sLayoutForId = $showSlideInfoPerms = false;
			
				switch ($this->slides[$slideNum]['use_slide_layout']) {
					case 'asset_schema':
						if ($a2Prefix = ze\module::prefix('assetwolf_2')) {
							$sLayoutFor = 'schema';
							$sLayoutForId = ze\row::get($a2Prefix. 'nodes', 'schema_id', ze::$vars['assetId']);
							$showSlideInfoPerms = ze\user::can('design', 'schema', $sLayoutForId);
						}
						break;
				
					case 'datapool_schema':
						if ($a2Prefix = ze\module::prefix('assetwolf_2')) {
							$sLayoutFor = 'schema';
							$sLayoutForId = ze\row::get($a2Prefix. 'nodes', 'schema_id', ze::$vars['dataPoolId']);
							$showSlideInfoPerms = ze\user::can('design', 'schema', $sLayoutForId);
						}
						break;
				}
			
				if ($sLayoutForId !== false) {
					$slData = false;
					
					//Look for slide layouts for this slide
					$sql = "
						SELECT id AS slide_layout_id, ord, privacy, at_location
						FROM ". DB_PREFIX. "slide_layouts
						WHERE layout_for = '". ze\escape::sql($sLayoutFor). "'
						  AND layout_for_id = ". (int) $sLayoutForId. "
						ORDER BY";
					
					//Have the option to request a specific one.
					if (isset($_REQUEST['useSlideLayoutId'])) {
						$sql .= "
							id = ". (int) $_REQUEST['useSlideLayoutId']. " DESC,";
					}
					
					//Otherwise just use the first one that we find...
					$sql .= "
						ord";
					
					//...that the current user has permissions to see.
					foreach (ze\sql::select($sql) as $sl) {
						if (ze\content::checkItemPrivacy($sl, $sl, ze::$cID, ze::$cType, ze::$cVersion)) {
							$slData = ze\sql::fetchValue("
								SELECT data
								FROM ". DB_PREFIX. "slide_layouts
								WHERE id = ". (int) $sl['slide_layout_id']
							);
							
							$this->showSlideInfo = $showSlideInfoPerms;
							$this->slideLayoutId = $sl['slide_layout_id'];
							
							break;
						}
					}
					
					//If we found a slide layout that the current user can see,
					//read through the JSON data looking for all of the plugins and plugin settings,
					//and add them as eggs with negative eggIds.
					if ($slData
					 && ($slData = json_decode($slData, true))
					 && (is_array($slData))) {
					
						foreach ($slData as $slEgg) {
							if (is_array($slEgg)
							 && isset($slEgg['class_name'])) {
							
								if ($moduleId = ze\row::get('modules', 'id', ['class_name' => $slEgg['class_name'], 'status' => 'module_running'])) {
									$eggs[] = [
										'id' => --$slEggId,
										'slide_num' => $slideNum,
										'ord' => ++$ord,
										'module_id' => $moduleId,
										'cols' => $slEgg['cols'] ?? 1,
										'framework' => $slEgg['framework'] ?? 'standard',
										'css_class' => $slEgg['css_class'] ?? '',
										'small_screens' => $slEgg['small_screens'] ?? 'show'
									];
									$bespokeSettings[$slEggId] = $slEgg['settings'] ?? [];
								}
							}
						}
					}
				}
			}
		}
		
		
		//Start getting information on all of the plugins we need to load
		$lastSlotNameNestId = false;
		$this->modules[$slideNum] = [];
		
		foreach ($eggs as $egg) {
			$eggId = $egg['id'];
			
			//If this is an AJAX request for a specific plugin, don't load any of the others
			if (!$specificEgg || $specificEgg == $eggId) {
				
				//Come up with a container id for this nested plugin.
				if ($eggId < 0) {
					//If it's from Slide Designer, use the slot name, slide id and plugin ordinal
					$slotNameNestId = $this->slotName. '-'. $this->slides[$slideNum]['id']. $eggId;
				} else {
					//If it has an id, we can just use that with the slot name.
					$slotNameNestId = $this->slotName. '-'. $eggId;
				}
				
				if ($this->initEgg($slotNameNestId, $egg)) {
				
					//Read the minigrid information
					$egg['cols'] = (int) $egg['cols'];
				
					//If this plugin should be grouped with the previous plugin (-1)...
					if ($egg['cols'] < 0) {
						if ($lastSlotNameNestId && isset($this->minigrid[$lastSlotNameNestId])) {
							//...flag it on the previous plugin so we know to open the grouping
							$this->minigrid[$lastSlotNameNestId]['group_with_next'] = true;
						} else {
							//...catch the case where there was no previous plugin by converting this to a full-width plugin
							$egg['cols'] = 0;
						}
					}
				
					//If there are nothing but "full width" and "show on small screens" plugins,
					//then we don't actually need to use a grid and can just leave the HTML alone.
					//But as soon as we see a column that's not full width, or has responsive options,
					//then enable the grid!
					if (!$this->minigridInUse && ($egg['cols'] > 0 || $egg['small_screens'] != 'show')) {
						$this->minigridInUse = true;
					
						//Look up how many columns the current slot has, or just guess 12 if we can't find out
						$this->maxColumns = 
							(int) ze\row::get('template_slot_link',
								'cols',
								[
									'family_name' => ze::$templateFamily,
									'file_base_name' => ze::$templateFileBaseName,
									'slot_name' => $this->slotName]
							) ?: 12;
					}
				
					$this->minigrid[$slotNameNestId] = [
						'cols' => min($egg['cols'], $this->maxColumns),
						'small_screens' => $egg['small_screens'],
						'group_with_next' => false
					];
				
					$lastSlotNameNestId = $slotNameNestId;
				}
			}
		}
		
		$beingEdited =
		$showInMenuMode =
		$addedJavaScript = false;
		foreach ($this->modules[$slideNum] as $eggId => $slotNameNestId) {
			
			$overrideSettings = false;
			
			//Add settings from any auto-generated plugin (e.g. the breadcrumbs at the top)
			if ($eggId < 0 && isset($bespokeSettings[$eggId])) {
				$overrideSettings = $bespokeSettings[$eggId];
			}
			
			
			if ($this->initEggInstance($slotNameNestId, $overrideSettings, $eggId, $this->slideId)) {
				
				//Check for the forcePageReload and headerRedirect options in modules
				if ($reload = ze::$slotContents[$slotNameNestId]['class']->checkForcePageReloadVar()) {
					$this->forcePageReload($reload);
				}
				if ($url = ze::$slotContents[$slotNameNestId]['class']->checkHeaderRedirectLocation()) {
					$this->headerRedirect($url);
				}
				
				//Ensure that the JavaScript libraries is there for modules on reloads
				if ($this->needToAddCSSAndJS()) {
					$this->callScriptBeforeAJAXReload('zenario_plugin_nest', 'addJavaScript', ze::$slotContents[$slotNameNestId]['class_name'], ze::$slotContents[$slotNameNestId]['module_id']);
					$addedJavaScript = true;
				}
			}
			
			if (ze\priv::check() && !empty(ze::$slotContents[$slotNameNestId]['class'])) {
				if (!$beingEdited) {
					if ($beingEdited = ze::$slotContents[$slotNameNestId]['class']->beingEdited()) {
						$this->editingTabNum = $slideNum;
					}
				}
				if (!$showInMenuMode) {
					$showInMenuMode = ze::$slotContents[$slotNameNestId]['class']->shownInMenuMode();
				}
			}
		}
		
		//Add any Plugin JavaScript calls
		foreach ($this->modules[$slideNum] as $eggId => $slotNameNestId) {
			if (!empty(ze::$slotContents[$slotNameNestId]['class'])) {
				//Check to see if any Eggs want to scroll to the top of the slot
				$scrollToTop = ze::$slotContents[$slotNameNestId]['class']->checkScrollToTopVar();
				if ($scrollToTop !== null) {
					$this->scrollToTopOfSlot($scrollToTop);
				}
				
				//Check to see if any Eggs want to show themselves in a Floating Box, or stop showing themselves in a Floating Box
				if (ze::$slotContents[$slotNameNestId]['class']->checkShowInFloatingBoxVar()) {
					$this->showInFloatingBox(true);
				}
			}
		}
		
		//If an Egg wanted to show themselves in a Floating Box, hide the ones that didn't want this.
		if ($this->checkShowInFloatingBoxVar()) {
			$unsets = [];
			foreach ($this->modules[$slideNum] as $eggId => $slotNameNestId) {
				if (!empty(ze::$slotContents[$slotNameNestId]['class'])) {
					if (!ze::$slotContents[$slotNameNestId]['class']->checkShowInFloatingBoxVar()) {
						unset(ze::$slotContents[$slotNameNestId]);
						$unsets[] = $eggId;
					}
				}
			}
			foreach ($unsets as $eggId) {
				unset($this->modules[$slideNum][$eggId]);
			}
		}
		
		
		if (ze\priv::check()) {
			$this->markSlotAsBeingEdited($beingEdited);
			$this->showInMenuMode($showInMenuMode);
		}
		
		return true;
	}
	
	
	
	protected function initEgg($slotNameNestId, &$egg) {
	
		$missingPlugin = false;
		if (($details = ze\module::details($egg['module_id']))
		 && (ze\module::incWithDependencies($details['class_name'], $missingPlugin))
		 && (method_exists($details['class_name'], 'showSlot'))) {
			
			$eggId = $egg['id'];
			$slideNum = $egg['slide_num'];
			$baseCSSName = $details['css_class_name'];
			
			$this->modules[$slideNum][$eggId] = $slotNameNestId;
			
			ze::$slotContents[$slotNameNestId] = $details;
			ze::$slotContents[$slotNameNestId]['instance_id'] = $this->instanceId;
			ze::$slotContents[$slotNameNestId]['egg_id'] = $eggId;
			ze::$slotContents[$slotNameNestId]['egg_ord'] = $egg['ord'];
			ze::$slotContents[$slotNameNestId]['slide_num'] = $slideNum;
			ze::$slotContents[$slotNameNestId]['framework'] = ($egg['framework'] ?: $details['default_framework']);
			ze::$slotContents[$slotNameNestId]['css_class'] = $details['css_class_name'];
			
			
			if ($egg['css_class']) {
				ze::$slotContents[$slotNameNestId]['css_class'] .= ' '. $egg['css_class'];
			} else {
				ze::$slotContents[$slotNameNestId]['css_class'] .= ' '. $baseCSSName. '__default_style';
			}
			
			
			//Add a CSS class for this version controller plugin, or this library plugin
			if ($this->isVersionControlled) {
				if (ze::$cID !== -1) {
					ze::$slotContents[$slotNameNestId]['css_class'] .=
						' '. ze::$cType. '_'. ze::$cID. '_'. $this->slotName.
						'_'. $baseCSSName.
						'_'. $slideNum. '_'. $egg['ord'];
				}
			} else {
				ze::$slotContents[$slotNameNestId]['css_class'] .=
					' '. $baseCSSName.
					'_'. $this->instanceId.
					'_'. $eggId;
			}
			
			if ($this->isVersionControlled) {
				ze::$slotContents[$slotNameNestId]['content_id'] = $this->cID;
				ze::$slotContents[$slotNameNestId]['content_type'] = $this->cType;
				ze::$slotContents[$slotNameNestId]['content_version'] = $this->cVersion;
				ze::$slotContents[$slotNameNestId]['slot_name'] = $this->slotName;
			} else {
				ze::$slotContents[$slotNameNestId]['content_id'] = 0;
				ze::$slotContents[$slotNameNestId]['content_type'] = '';
				ze::$slotContents[$slotNameNestId]['content_version'] = 0;
				ze::$slotContents[$slotNameNestId]['slot_name'] = '';
			}
			
			ze::$slotContents[$slotNameNestId]['cache_if'] = [];
			ze::$slotContents[$slotNameNestId]['clear_cache_by'] = [];
			
			return true;
		}
		
		return false;
	}
	
	protected function initEggInstance($slotNameNestId, $overrideSettings, $eggId, $slideId, $beingDisplayed = true) {
		
		ze::$slotContents[$slotNameNestId]['instance_id'] = $this->instanceId;
		ze\plugin::setInstance(ze::$slotContents[$slotNameNestId], $this->cID, $this->cType, $this->cVersion, $this->slotName, true, $overrideSettings, $eggId, $slideId, $beingDisplayed);
		
		return ze\plugin::initInstance(ze::$slotContents[$slotNameNestId]);
	}
	
	
	public function showPlugin($slotNameNestId, $includeAdminControlsIfInAdminMode = false) {
		
		//Flag that we're no longer running Twig code, if this was called from a Twig Framework
		if ($wasTwig = ze::$isTwig) {
			ze::$isTwig = false;
			ze::noteErrors();
		}
		
		if ($this->minigridInUse) {
			$minigrid = $this->minigrid[$slotNameNestId];
			$cols = $minigrid['cols'];
			$groupWithNext = $minigrid['group_with_next'];
			
			//"-1" means group with the previous plugin
			$groupWithPrevious = $cols < 0;
			
			//"0" means max-width
			if ($cols == 0
			 || $cols > $this->maxColumns) {
				$cols = $this->maxColumns;
			}
			
			//If we are not in the grouping, or are just starting a grouping,
			//we need to output a grid-slot.
			if (!$groupWithPrevious) {
			
				//Was there a previous cell?
				if ($this->usedColumns) {
					//Is this cell too big to fit the line..?
					if ($this->usedColumns + $cols > $this->maxColumns) {
						//Put a line break in
						$this->usedColumns = 0;
						echo '
				<div class="grid_clear"></div>';
					}
				}
			
				//Output the div for this 
				echo '
				<div class="minigrid '. ze\content::rationalNumberGridClass($cols, $this->maxColumns);
			
				//Add the "alpha" class for the first cell on a line
				if ($this->usedColumns == 0) {
					echo ' alpha';
				}
				
				//Increase the number of columns that we have used by the width of this plugin
				$this->usedColumns += $cols;
			
				//Add the "omega" class if the cell goes right up to the end of a line
				if ($this->usedColumns >= $this->maxColumns) {
					echo ' omega';
				}
				
				//Add responsive classes on max-width columns
				//(Unless this is the start of a grouping, in which case the classes should be
				// added on to the nested-grid-slot.)
				if (!$groupWithNext) {
					if ($cols == $this->maxColumns) {
						switch ($minigrid['small_screens']) {
							case 'hide':
								echo ' responsive';
								break;
							case 'only':
								echo ' responsive_only';
								break;
						}
					}
				
				//If this is the start of a grouping, note down how many columns it has
				} else {
					$this->groupingColumns = $cols;
				}
				echo '">';
			
			} else {
				//Nested slots in minigrids are always full-width,
				//so if we are in a grouping, always put a line break in between slots.
				echo '
					<div class="grid_clear"></div>';
			}
			
			//If we are in a grouping, output a nested grid-slot
			if ($groupWithPrevious || $groupWithNext) {
				echo '
					<div class="minigrid '. ze\content::rationalNumberGridClass($this->groupingColumns, $this->groupingColumns);
				
				//Add responsive classes
				switch ($minigrid['small_screens']) {
					case 'hide':
						echo ' responsive';
						break;
					case 'only':
						echo ' responsive_only';
						break;
				}
				
				//At the moment, nested grid-slots in minigrids are always full width
				echo ' alpha omega">';
			}
		}
		
		if ($this->sameHeight) {
			echo '
				<div class="nest_egg_equal_height tothesameheight">';
		}
		
		
		$p = ze\priv::check();
		$status = false;
		if (isset(ze::$slotContents[$slotNameNestId]['init'])) {
			$status = ze::$slotContents[$slotNameNestId]['init'];
		}
		
		$noPermsMsg = !$status && (!empty(ze::$slotContents[$slotNameNestId]['error']) || $status === ZENARIO_401_NOT_LOGGED_IN || $status === ZENARIO_403_NO_PERMISSION) && $p;
		
		if ($p) {
			if ($noPermsMsg) {
				ze::$slotContents[$slotNameNestId]['class']->start('zenario_nestSlot zenario_slotWithNoPermission');
			} else {
				ze::$slotContents[$slotNameNestId]['class']->start('zenario_nestSlot');
			}
		}
		
		if ($status || $p) {
			ze::$slotContents[$slotNameNestId]['class']->show($includeAdminControlsIfInAdminMode, 'showSlot');
		}
		
		if ($p) {
			echo '
				</x-zenario-admin-slot-wrapper>';
		}
		
		
		if ($this->sameHeight) {
			echo '
				</div>';
		}
		
		if ($this->minigridInUse) {
			//We'll need various different closing divs, depending on whether this is the
			//end of a normal slot, the end of a nested slot, or the end of both.
			if ($groupWithPrevious || $groupWithNext) {
				echo '
				</div>';
			}
			
			if (!$groupWithNext) {
				echo '
			</div>';
			}
		}
		
		
		if ($this->needToAddCSSAndJS()
		 && !empty(ze::$slotContents[$slotNameNestId]['class'])) {
			//Add the script of a Nested Plugin to the Nest
			$scriptTypes = [];
			ze::$slotContents[$slotNameNestId]['class']->zAPICheckRequestedScripts($scriptTypes);
			
			foreach ($scriptTypes as $scriptType => &$scripts) {
				foreach ($scripts as &$script) {
					$this->zAPICallScriptWhenLoaded($scriptType, $script);
				}
			}
		}
		
		//Flag that we're going back to running Twig code, if this was called from a Twig Framework
		if ($wasTwig) {
			ze::$isTwig = true;
			ze::ignoreErrors();
		}
	}
	
	
	//Allow one specific Egg to be shown for the showFloatingBox/showRSS methods
	protected function specificEgg() {
		
		if (isset($_REQUEST['method_call'])) {
			switch ($_REQUEST['method_call']) {
				case 'handlePluginAJAX':
				case 'showFile':
				case 'showImage':
				case 'showStandalonePage':
				case 'showFloatingBox':
				case 'showRSS':
				case 'fillVisitorTUIX':
				case 'formatVisitorTUIX':
				case 'validateVisitorTUIX':
				case 'saveVisitorTUIX':
				case 'typeaheadSearchAJAX':
					return (int) ($_REQUEST['eggId'] ?? false);
			}
		}
		
		return false;
	}
	
	//Version of refreshPluginSlotAnchor, that doesn't automatically set the slide id
	public function refreshPluginSlotTabAnchor($requests = '', $scrollToTopOfSlot = true, $fadeOutAndIn = false) {
		return
			$this->linkToItemAnchor($this->cID, $this->cType, $fullPath = false, '&slotName='. $this->slotName. ze\ring::urlRequest($requests)).
			' onclick="'.
				$this->refreshPluginSlotJS($requests, $scrollToTopOfSlot, $fadeOutAndIn).
				' return false;"';
	}
	//To show roles and sub-roles
	public static function getRoleTypesIndexedByIdOrderedByName(){
		$ZENARIO_ORGANIZATION_MANAGER_PREFIX = ze\module::prefix('zenario_organization_manager'); 
		$rv = [];
		$ord = 0;
		$sql = "SELECT 
					id,
					parent_id,
					name
				FROM " . 
					DB_PREFIX . $ZENARIO_ORGANIZATION_MANAGER_PREFIX . "user_location_roles
				ORDER BY name";
		$result = ze\sql::select($sql);
		while($row = ze\sql::fetchAssoc($result)){
			$rv[$row['id']] = ['label' => $row['name'], 'parent' => $row['parent_id'], 'ord' => ++$ord];
		}
		return $rv;
		
	
	}
	
	public function showFile() {
		if ($class = $this->getSpecificEgg()) {
			return $class->showFile();
		}
	}
	public function showImage() {
		if ($class = $this->getSpecificEgg()) {
			return $class->showImage();
		}
	}
	public function showStandalonePage() {
		if ($class = $this->getSpecificEgg()) {
			return $class->showStandalonePage();
		}
	}
	public function showFloatingBox() {
		if ($class = $this->getSpecificEgg()) {
			return $class->showFloatingBox();
		}
	}
	public function showRSS() {
		if ($class = $this->getSpecificEgg()) {
			return $class->showRSS();
		}
	}
	public function handlePluginAJAX() {
		if ($class = $this->getSpecificEgg()) {
			return $class->handlePluginAJAX();
		}
	}
	
	public function returnVisitorTUIXEnabled($path) {
		if ($class = $this->getSpecificEgg(true, $path)) {
			return $class->returnVisitorTUIXEnabled($path);
		}
	}
	
	public function fillVisitorTUIX($path, &$tags, &$fields, &$values) {
		if ($class = $this->getSpecificEgg(true, $path)) {
			return $class->fillVisitorTUIX($path, $tags, $fields, $values);
		}
	}
	
	public function formatVisitorTUIX($path, &$tags, &$fields, &$values, &$changes) {
		if ($class = $this->getSpecificEgg(true, $path)) {
			return $class->formatVisitorTUIX($path, $tags, $fields, $values, $changes);
		}
	}
	
	public function validateVisitorTUIX($path, &$tags, &$fields, &$values, &$changes, $saving) {
		if ($class = $this->getSpecificEgg(true, $path)) {
			return $class->validateVisitorTUIX($path, $tags, $fields, $values, $changes, $saving);
		}
	}
	
	public function saveVisitorTUIX($path, &$tags, &$fields, &$values, &$changes) {
		if ($class = $this->getSpecificEgg(true, $path)) {
			return $class->saveVisitorTUIX($path, $tags, $fields, $values, $changes);
		}
	}
	
	public function typeaheadSearchAJAX($path, $tab, $searchField, $searchTerm, &$searchResults) {
		if ($class = $this->getSpecificEgg(true, $path)) {
			return $class->typeaheadSearchAJAX($path, $tab, $searchField, $searchTerm, $searchResults);
		}
	}
	
	public function returnGlobalName() {
		if ($class = $this->getSpecificEgg()) {
			return $class->returnGlobalName();
		}
	}
	
	protected function getSpecificEgg($checkSubClass = false, $path = false) {
		if ($this->show
		 && ($specificEgg = $this->specificEgg())
		 && ($slotNameNestId = ze\ray::value($this->modules[$this->slideNum], $specificEgg))
		 && (!empty(ze::$slotContents[$slotNameNestId]['init']))) {
			$class = ze::$slotContents[$slotNameNestId]['class'];
			
			if ($checkSubClass) {
				$class = $class->runSubClass(get_class($class), false, $path) ?: $class;
			}
			
			return $class;
		}
		return false;
	}
	
	
	protected function needToAddCSSAndJS() {
		return $this->methodCallIs('refreshPlugin');
	}
	
	public function fillAdminSlotControls(&$controls) {
		require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	
	
	protected function addPluginConfirm($addId, $instanceId, $copyingInstance = false) {
		return require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	protected function removePluginConfirm($eggIds, $instanceId) {
		return require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	protected function duplicatePluginConfirm($eggId) {
		return require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	protected function removeSlideConfirm($eggIds, $instanceId) {
		return require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	
	protected static function addPluginInstance($addPluginInstance, $instanceId, $slideNum = false, $inputIsSlideId = false) {
		return require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	
	protected static function addPlugin($addPlugin, $instanceId, $slideNum = false, $displayName = false, $inputIsSlideId = false) {
		return require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	public static function addBanner($imageId, $instanceId, $slideNum = false, $inputIsSlideId = false) {
		return require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	protected static function addTwigSnippet($moduleClassName, $snippetName, $instanceId, $slideNum = false, $inputIsSlideId = false) {
		return require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	//Create a new, empty slide at the end of the nest
	public static function addSlide($instanceId, $title = false, $slideNum = false) {
		
		if ($slideNum === false) {
			$slideNum = 1 + (int) self::maxTab($instanceId);
		}
		
		if ($title === false) {
			$title = ze\admin::phrase('Slide [[num]]', ['num' => $slideNum]);
		}
		
		return ze\row::insert(
			'nested_plugins',
			[
				'instance_id' => $instanceId,
				'slide_num' => $slideNum,
				'ord' => 0,
				'module_id' => 0,
				'is_slide' => 1,
				'name_or_title' => $title]);
	}
	
	public static function duplicatePlugin($eggId, $instanceId) {
		return require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	public static function removePlugin($eggId, $instanceId, $resync = true) {
		require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	protected static function removeSlide($slideId, $instanceId, $resync = true) {
		require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	

	public static function reorderNest($instanceId, $ids, $ordinals, $parentIds) {
		require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	public static function resyncNest($instanceId) {
		require ze::funIncPath(__FILE__, __FUNCTION__);
	}
	
	
	protected static function maxTab($instanceId) {
		return ze\sql::fetchValue("
			SELECT MAX(slide_num) AS slide_num
			FROM ". DB_PREFIX. "nested_plugins
			WHERE is_slide = 1
			  AND instance_id = ". (int) $instanceId);
	}
	
	protected static function maxOrd($instanceId, $slideNum) {
		return ze\sql::fetchValue("
			SELECT MAX(ord) AS ord
			FROM ". DB_PREFIX. "nested_plugins
			WHERE slide_num = ". (int) $slideNum. "
			  AND is_slide = 0
			  AND instance_id = ". (int) $instanceId);
	}
	
	
	
	
	protected function removeHiddenTabs(&$tabs, $cID, $cType, $cVersion, $instanceId) {
		
		$unsets = [];
		foreach ($tabs as $slideNum => $slide) {
			if (!($slide['always_visible_to_admins'] && ze\priv::check())) {
				
				switch ($slide['privacy']) {
					case 'call_static_method':
					case 'send_signal':
						$this->allowCaching(false);
				}
				
				if (!ze\content::checkItemPrivacy($slide, $slide, ze::$cID, ze::$cType, ze::$cVersion)) {
					$unsets[] = $slideNum;
				}
			}
		}
		
		foreach ($unsets as $unset) {
			unset($tabs[$unset]);
		}
	}
	
	
	
	public function cEnabled() {
		return $this->usesConductor;
	}
	
	public function cCommandEnabled($command) {
		return isset($this->commands[$command]) && !empty($this->commands[$command]->toState);
	}
	
	public function cLink($command, $requests = []) {
		if (isset($this->commands[$command]) && !empty($this->commands[$command]->toState)) {
			return $this->commands[$command]->link($requests);
		}
		return false;
	}
	
	public function cBackLink() {
		return $this->cLink('back');
	}
	
	protected static function deletePath($instanceId, $fromState, $toState = false, $equivId = 0, $contentType = '') {
		
		//If a from & to are both specified, delete that specific path
		if ($toState) {
			ze\row::delete('nested_paths', ['instance_id' => $instanceId, 'from_state' => $fromState, 'to_state' => $toState, 'equiv_id' => $equivId, 'content_type' => $contentType]);
		
		//If just one state is specified, delete all paths from and to that state
		} else {
			if (!$equivId) {
				$equivId = 0;
			}
			if (!$contentType) {
				$contentType = '';
			}
			
			ze\row::delete('nested_paths', ['instance_id' => $instanceId, 'from_state' => $fromState]);
			ze\row::delete('nested_paths', ['instance_id' => $instanceId, 'to_state' => $fromState]);
		}
		
	}
	
}
