<?php
/*
 * Copyright (c) 2015, Tribal Limited
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


class zenario_common_features__admin_boxes__import extends module_base_class {
	
	public function validateAdminBox($path, $settingGroup, &$box, &$fields, &$values, $changes, $saving) {
		// Handle tab naviagtion and validation
		$errors = &$box['tabs'][$box['tab']]['errors'];
		switch ($box['tab']) {
			case 'file':
				// --- Validate file tab --- 
				if (!empty($box['tabs']['file']['fields']['next']['pressed']) && $values['file/file']) {
					$box['tab'] = 'headers';
				}
				break;
			case 'headers':
				// --- Validate headers tab --- 
				if (!empty($box['tabs']['headers']['fields']['next']['pressed'])) {
					$updateMode = ($values['file/type'] == 'update_data');
					
					// Validate Key line
					if (!$values['headers/key_line']) {
						$errors[] = adminPhrase('Please select the key containing field names');
					
					// If updating ensure key field is selected
					} elseif ($updateMode) {
						if (!$values['headers/update_key_field']) {
							$errors[] = adminPhrase('Please select a field name to uniquely identify each line');
						}
					}
					if (!$errors) {
						$datasetId = $box['key']['dataset'];
						$datasetFieldDetails = self::getAllDatasetFieldDetails($datasetId);
						$requiredFieldsIncludedInImport = array();
						$datasetColumns = array();
						foreach ($datasetFieldDetails as $fieldId => $details) {
							$datasetColumns[$fieldId] = $details['db_column'];
							if ($details['required']) {
								$requiredFieldsIncludedInImport[$fieldId] = false;
							}
						}
						$noFieldsMatched = true;
						$IDColumn = false;
						$emailColumn = false;
						foreach ($box['tabs']['headers']['fields'] as $name => $field) {
							if (self::isGeneratedField($name, $field) && isset($field['type']) && ($field['type'] == 'select')) {
								$selectListFieldId = $values['headers/' . $name];
								if ($selectListFieldId) {
									$noFieldsMatched = false;
									if (isset($datasetFieldDetails[$selectListFieldId]) && ($datasetFieldDetails[$selectListFieldId]['db_column'] == 'email')) {
										$emailColumn = true;
									}
								}
								if ($updateMode && $selectListFieldId && ($name == ('database_column__' . $values['headers/update_key_field']))) {
									$IDColumn = $values['headers/' . $name];
								}
								$currentMatchedFields[$name] = $selectListFieldId;
								if (isset($requiredFieldsIncludedInImport[$selectListFieldId])) {
									$requiredFieldsIncludedInImport[$selectListFieldId] = true;
								}
							}
						}
						
						// Ensure at least one field is being imported
						if ($noFieldsMatched) {
							$errors[] = adminPhrase('You need to match at least one field to continue');
						} else {
							// If updating ensure key field is selected
							if ($updateMode) {
								if (!$IDColumn) {
									$errors[] = adminPhrase('You must match the key field to a field name');
								} elseif(isset($datasetFieldDetails[$IDColumn]) && !$datasetFieldDetails[$IDColumn]['is_system_field']) {
									$errors[] = adminPhrase('The database column chosen for the key field must be a system field');
								}
							}
							// Validate required fields
							if (!$updateMode) {
								$missingRequiredFields = array();
								foreach ($requiredFieldsIncludedInImport as $fieldId => $found) {
									if ($found === false) {
										$missingRequiredFields[] = $datasetColumns[$fieldId];
									}
								}
								if ($missingRequiredFields) {
									$missingFields = implode(', ', $missingRequiredFields);
									$errors[] = adminPhrase('The following required fields are missing: [[missingFields]]', array('missingFields' => $missingFields));
								}
								$datasetDetails = getDatasetDetails($datasetId);
								if (!$emailColumn && ($datasetDetails['system_table'] == 'users') && ($values['headers/insert_options'] != 'no_update')) {
									$errors[] = adminPhrase('You must include the email column to update matching fields on email');
								}
							}
						}
					}
					if (empty($errors)) {
						$box['tab'] = 'preview';
					}
				} elseif (!empty($box['tabs']['headers']['fields']['previous']['pressed'])) {
					$box['tab'] = 'file';
				}
				break;
			case 'preview':
				// --- Validate preview tab --- 
				if (!empty($box['tabs']['preview']['fields']['previous']['pressed'])) {
					$box['tab'] = 'headers';
				} elseif (!empty($box['tabs']['preview']['fields']['next']['pressed'])) {
					$box['tab'] = 'actions';
				} 
				break;
			case 'actions':
				// --- Validate actions tab --- 
				if ($saving) {
					$datasetId = $box['key']['dataset'];
					$datasetFieldDetails = self::getAllDatasetFieldDetails($datasetId);
					$childFields = array();
					foreach ($datasetFieldDetails as $fieldId => $details) {
						
						// Child fields validation 1. record all fields with parents
						if ($details['parent_id']) {
							$childFields[$fieldId] = $details['parent_id'];
						}
					}
					$foundChildFields = array();
					foreach ($box['tabs']['headers']['fields'] as $name => $field) {
						if (self::isGeneratedField($name, $field) && isset($field['type']) && ($field['type'] == 'select')) {
							$selectListFieldId = $values['headers/' . $name];
							$currentMatchedFields[$name] = $selectListFieldId;
							
							// Child fields validation 2. record child fields currently selected
							if (isset($childFields[$selectListFieldId])) {
								$foundChildFields[$selectListFieldId] = true;
							}
						}
					}
					
					// Child fields validation 3. make sure parent is also selected
					foreach ($foundChildFields as $childId => $val) {
						$parentId = $childFields[$childId];
						
						if (!in_array($parentId, $currentMatchedFields) && empty($values['value__'.$parentId])) {
							$errors[] = adminPhrase('You must include the column "[[parent]]" because the column "[[child]]" depends on it.', 
								array(
									// This looks really confusing
									'child' => $datasetFieldDetails[$childId]['db_column'],
									'parent' => $datasetFieldDetails[$parentId]['db_column'])
								);
						}
					}
					
				} elseif (!empty($box['tabs']['actions']['fields']['previous']['pressed'])) {
					$box['tab'] = 'preview';
				}
				break;
		}
	}
	
	private static function isGeneratedField($name, $field) {
		return !in_array($name, array('key_line', 'desc', 'desc2', 'insert_desc', 'insert_options', 'update_desc', 'update_key_field', 'next', 'previous'));
	}
	
	public function fillAdminBox($path, $settingGroup, &$box, &$fields, &$values) {
		
	}
	
	public function formatAdminBox($path, $settingGroup, &$box, &$fields, &$values, $changes) {
		// Get dataset details
		$datasetId = $box['key']['dataset'];
		$datasetDetails = getDatasetDetails($datasetId);
		$datasetFieldDetails = self::getAllDatasetFieldDetails($datasetId);
		
		// Handle navigation
		$step = 1;
		switch ($box['tab']) {
			case 'headers':
				$fields['headers/update_desc']['hidden'] = 
				$fields['headers/update_key_field']['hidden'] = 
					$values['file/type'] != 'update_data';
				
				$fields['headers/insert_desc']['hidden'] = 
				$fields['headers/insert_options']['hidden'] = 
					($values['file/type'] != 'insert_data') || ($datasetDetails['system_table'] != 'users');
				$step = 2;
				break;
			case 'preview':
				$step = 3;
				break;
			case 'actions':
				$step = 4;
				break;
		}
		
		// Set title
		$box['title'] = 'Dataset Import Wizard - Step ' . $step . ' of 4';
		
		// Set CSS class
		if ($box['tab'] == 'actions') {
			$box['css_class'] = '';
		} else {
			$box['css_class'] = 'zab_hide_save_button';
		}
		
		// Only proceed if file
		$file = $values['file/file'];
		if (!$file = $values['file/file']) {
			return;
		}
		$path = getPathOfUploadedFileInCacheDir($file);
		
		
		// Include modules if needed
		switch ($datasetDetails['extends_organizer_panel']) {
			case 'zenario__locations':
				inc('zenario_location_manager');
				break;
		}
		
		// Get list of values for header to DB column matching.
		$DBColumnSelectListValues = listCustomFields($datasetDetails['system_table'], $flat = false, $filter = false, $customOnly = false, $useOptGroups = true);
		
		// Show an ID field for updates
		$update = $values['file/type'] == 'update_data';
		if ($update) {
			$DBColumnSelectListValues['id'] = array('ord' => 0, 'label' => 'ID Column');
		}
		
		// Show special fields for users
		if ($datasetDetails['system_table'] == 'users') {
			$DBColumnSelectListValues['name_split_on_first_space'] = array('ord' => 0.1, 'label' => 'Name -> First Name, Last Name, split on first space');
			$DBColumnSelectListValues['name_split_on_last_space'] = array('ord' => 0.2, 'label' => 'Name -> First Name, Last Name, split on last space');
		}
		
		$box['lovs']['dataset_fields'] = $DBColumnSelectListValues;
		
		// Create an array of field IDs to database columns to use when trying to autoset headers to DB columns
		$datasetColumns = array();
		foreach ($datasetFieldDetails as $fieldId => $details) {
			$datasetColumns[$fieldId] = $details['db_column'];
		}
		
		$newFileUploaded = ($path != $box['key']['file_path']);
		$keyLine = ($values['headers/key_line'] && !$newFileUploaded) ? $values['headers/key_line'] : 0;
		
		// Clear old generated fields from fields tab
		$currentMatchedFields = array();
		foreach ($box['tabs']['headers']['fields'] as $name => $field) {
			if (self::isGeneratedField($name, $field)) {
				if (isset($fields['headers/'.$name]['type']) && ($fields['headers/'.$name]['type'] == 'select')) {
					$selectListFieldId = $values['headers/'.$name];
					$currentMatchedFields[$name] = $selectListFieldId;
				}
				unset($box['tabs']['headers']['fields'][$name]);
			}
		}
		
		$header = false;
		$headerCount = 0;
		
		$previewLinesLimit = 200;
		$filePreviewString = '';
		$problems = '';
		
		// Track error number and lines with errors
		$warningCount = 0;
		$errorCount = 0;
		$updateCount = 0;
		$warningLines = array();
		$errorLines = array();
		$blankLines = array();
		
		
		$IDColumnIndex = false;
		
		// Link between row number and field ID
		$rowFieldIdLink = array();
		if (pathinfo($path, PATHINFO_EXTENSION) == 'csv') {
			ini_set('auto_detect_line_endings', true);
			$f = fopen($path, 'r');
			if ($f) {
				$lineNumber = 0;
				// Loop through each line
				while ($line = fgets($f)) {
					$lineNumber++;
					if (trim($line) != '') {
						$lineValues = str_getcsv($line);
						
						$thisIsKeyLine = (!$keyLine && !$header) || ($keyLine && $keyLine == $lineNumber);
						if ($thisIsKeyLine) {
							$headerCount = count($lineValues);
							$keyLine = $lineNumber;
						}
						$warning = false;
						$error = false;
						// Loop through each value
						foreach ($lineValues as $dataCount => $value) {
							$value = trim($value);
							if ($thisIsKeyLine) {
								// Attempt to autoset db_columns on fields tab
								$fieldId = array_search(strtolower($value), $datasetColumns);
								if ($value == 'id') { 
									$fieldId = $value;
								}
								// Fill row field link
								if (isset($currentMatchedFields['database_column__'.$value]) && !empty($currentMatchedFields['database_column__'.$value])) {
									$rowFieldIdLink[$dataCount] = $currentMatchedFields['database_column__'.$value];
								}
								// Set column headers
								if ($dataCount == 0) {
									self::generateFieldHeaders($box, 'csv');
								}
								
								// Set columns table
								self::generateFieldRow($box, $dataCount, $value, $currentMatchedFields, $fieldId);
								
								// Get key field index
								if ($update && ($value == $values['headers/update_key_field'])) {
									$IDColumnIndex = $dataCount;
								}
								
								// Set key field values
								if ($update) {
									$fields['headers/update_key_field']['values'][$value] = array(
										'label' => $value,
										'ord' => $dataCount
									);
								}
							} elseif ($header) {
								/*
								// Field errors
								if (!empty($value) && isset($rowFieldIdLink[$dataCount]) && 
									!self::validateImportValue($problems, $datasetDetails['system_table'], $datasetFieldDetails, $rowFieldIdLink[$dataCount], $dataCount, $value, $lineNumber)) {
									$error = true;
								}
								*/
							}
						}
						if ($thisIsKeyLine) {
							$header = true;
							if ($box['tab'] == 'file' || $box['tab'] == 'headers') {
								break;
							}
							if ($IDColumnIndex !== false) {
								$box['key']['ID_column'] = $rowFieldIdLink[$IDColumnIndex];
							}
						} elseif ($header) {
							
							// Validate import line
							$lineProblems = '';
							$lineErrorCount = self::validateImportLine($lineProblems, $datasetDetails, $datasetFieldDetails, $rowFieldIdLink, $lineValues, $lineNumber, $IDColumnIndex, $update, $values['headers/insert_options'], $updateCount);
							$problems .= $lineProblems;
							$error = ($lineErrorCount > 0);
							
							// Line errors
							$dataCount = count($lineValues);
							if ($dataCount != $headerCount) {
								if ($dataCount < $headerCount) {
									$problems .= 'Error (Line '. $lineNumber. '): Too few fields';
								} else {
									$problems .= 'Error (Line '. $lineNumber. '): Too many fields';
								}
								$error = true;
								$problems .= "\r\n";
							}
							// Record lines with warnings and errors
							if ($error) {
								$errorCount++;
								$errorLines[] = $lineNumber;
							} elseif ($warning) {
								$warningCount++;
								$warningLines[] = $lineNumber;
							}
							if ($warning && $error) {
								$warningCount++;
							}
							
						} else {
							$blankLines[] = $lineNumber;
						}
					} else {
						$blankLines[] = $lineNumber;
					}
					if ($lineNumber <= $previewLinesLimit) {
						$filePreviewString .= $line;
					}
					// Stop if keyline isn't on the first 5 lines
					if (!$header && $lineNumber >= 5) {
						break;
					}
				}
			}
		} else {
			require_once CMS_ROOT.'zenario/libraries/lgpl/PHPExcel_1_7_8/Classes/PHPExcel.php';
			$inputFileType = PHPExcel_IOFactory::identify($path);
			$objReader = PHPExcel_IOFactory::createReader($inputFileType);
			$objReader->setReadDataOnly(true);
			$objPHPExcel = $objReader->load($path);
			$worksheet = $objPHPExcel->getSheet(0);
			// Columns that first and last headers are stored in
			$startingColumn = 0;
			$endingColumn = 0;
			foreach ($worksheet->getRowIterator() as $row) {
				$line = array();
				$lineNumber = $row->getRowIndex();
				$cellIterator = $row->getCellIterator();
				$cellIterator->setIterateOnlyExistingCells(false);
				$started = false;
				$dataCount = 0;
				$thisIsKeyLine = (!$keyLine && !$header) || ($keyLine && $keyLine == $lineNumber);
				$warning = false;
				$error = false;
				foreach ($cellIterator as $cell) {
					$value = $cell->getCalculatedValue();
					$columnIndex = PHPExcel_Cell::columnIndexFromString($cell->getColumn());
					// Check for blank rows
					if (!empty($value) && !$started) {
						$started = true;
					}
					// Set header columns if not set
					if ($thisIsKeyLine) {
						if (!is_null($value) && !$startingColumn) {
							$startingColumn = $endingColumn = $columnIndex;
							// Set column headers
							self::generateFieldHeaders($box, 'excel');
						}
						if ($startingColumn) {
							if (empty($value)) {
								break;
							}
							// Include headers in CSV preview
							$line[] = $value;
							if ($startingColumn != $columnIndex) {
								$endingColumn++;
							}
							// Attempt to autoset db_columns on fields tab
							$fieldId = array_search(strtolower($value), $datasetColumns);
							if ($value == 'id') {
								$fieldId = $value;
							}
							// Fill row field link
							if (isset($currentMatchedFields['database_column__'.$value]) && !empty($currentMatchedFields['database_column__'.$value])) {
								$rowFieldIdLink[$dataCount] = $currentMatchedFields['database_column__'.$value];
							}
							
							// Get key field index
							if ($update && ($value == $values['headers/update_key_field'])) {
								$IDColumnIndex = $dataCount;
							}
							
							// Set columns table
							self::generateFieldRow($box, $dataCount, $value, $currentMatchedFields, $fieldId);
							if ($update) {
								$fields['headers/update_key_field']['values'][$value] = array(
									'label' => $value,
									'ord' => $dataCount
								);
							}
							$dataCount++;
						}
					} elseif ($header && ($columnIndex >= $startingColumn) && ($columnIndex <= $endingColumn)) {
						
						// Field errors
						/*
						$dataCount++;
						if (!empty($value) && isset($rowFieldIdLink[$dataCount]) && 
							!self::validateImportValue($problems, $datasetDetails['system_table'], $datasetFieldDetails, $rowFieldIdLink[$dataCount], $dataCount, $value, $lineNumber)) {
							$error = true;
						}
						*/
						// Make CSV of line for preview
						if ($lineNumber <= $previewLinesLimit) {
							$line[] = $value;
						}
					}
				}
				if ($started) {
					if ($thisIsKeyLine) {
						$keyLine = $lineNumber;
						$header = true;
						if ($box['tab'] == 'file' || $box['tab'] == 'headers') {
							break;
						}
						if ($IDColumnIndex !== false) {
							$box['key']['ID_column'] = $rowFieldIdLink[$IDColumnIndex];
						}
					} elseif ($header) {
						// Validate import line
						$lineProblems = '';
						$lineErrorCount = self::validateImportLine($lineProblems, $datasetDetails, $datasetFieldDetails, $rowFieldIdLink, $line, $lineNumber, $IDColumnIndex, $update, $values['headers/insert_options'], $updateCount);
						$problems .= $lineProblems;
						$error = ($lineErrorCount > 0);
						
						// Record lines with warnings and errors
						if ($error) {
							$errorCount++;
							$errorLines[] = $lineNumber;
						} elseif ($warning) {
							$warningCount++;
							$warningLines[] = $lineNumber;
						}
						if ($warning && $error) {
							$warningCount++;
						}
					}
				}
				if (!$header || !$started) {
					$blankLines[] = $lineNumber;
				}
				if ($lineNumber <= $previewLinesLimit) {
					foreach ($line as $key => $value) {
						if (strpos($value, ',') !== false) {
							$value = '"'.$value.'"';
						}
						$filePreviewString .= $value.', ';
					}
					$filePreviewString = rtrim($filePreviewString, ', ');
					$filePreviewString .= "\n";
				}
			}
		}
		
		if ($box['tab'] == 'actions') {
			
			$userImport = ($datasetDetails['extends_organizer_panel'] == 'zenario__users/panels/users');
			
			foreach($box['tabs']['actions']['fields'] as $name => $field) {
				if (!in_array($name, array('records_statement', 'email_report', 'line_break', 'previous'))) {
					unset($box['tabs']['actions']['fields'][$name]);
				}
			}
			foreach ($rowFieldIdLink as $index => $fieldId) {
				unset($datasetFieldDetails[$fieldId]);
			}
			$ord = 1;
			foreach ($datasetFieldDetails as $fieldId => $datasetField) {
				
				// Hide certain fields when importing users
				if ($userImport) {
					if ($datasetField['is_system_field'] && $datasetField['db_column'] === 'screen_name_confirmed') {
						continue;
					}
				}
				
				$ord++;
				$valueFieldName = 'value__'.$fieldId;
				$fieldValuePicker = array(
					'ord' => $ord + 500.5,
					'same_row' => true,
					'post_field_html' => '<br/>',
					'type' => 'text',
					'style' => 'width: 20em;');
				if (isset($values[$valueFieldName]) && !empty($values[$valueFieldName])) {
					$fieldValuePicker['value'] = $values[$valueFieldName];
				}
				$valuesArray = false;
				switch ($datasetField['type']) {
					case 'group':
					case 'checkbox':
						$fieldValuePicker['type'] = 'checkbox';
						break;
					case 'date':
						$fieldValuePicker['type'] = 'date';
						break;
					case 'checkboxes':
						$fieldValuePicker['readonly'] = true;
						$fieldValuePicker['value'] = '"Multi-Checkboxes" cannot be imported';
					case 'editor':
					case 'textarea':
					case 'url':
						$fieldValuePicker['type'] = 'text';
						$fieldValuePicker['maxlength'] = 255;
						break;
					case 'text':
						
						// If importing users don't show system text fields for auto complete list
						if (($datasetDetails['system_table'] == 'users') && $datasetField['is_system_field']) {
							continue 2;
						}
						
						$fieldValuePicker['type'] = 'text';
						$fieldValuePicker['maxlength'] = 255;
						break;
					case 'radios':
					case 'select':
					case 'centralised_radios':
					case 'centralised_select':
						$valuesArray = getDatasetFieldLOV($datasetField);
						$fieldValuePicker['type'] = 'select';
						$fieldValuePicker['empty_value'] = "-- Don't import --";
						$fieldValuePicker['values'] = $valuesArray;
						break;
				}
				$validationArray = false;
				switch ($datasetField['validation']) {
					case 'email':
						$validationArray = array('email' => '"'.$datasetField['db_column'].'" is in incorrect format for email');
						break;
					case 'emails':
						$validationArray = array('emails' => '"'.$datasetField['db_column'].'" is in incorrect format for emails');
						break;
					case 'no_spaces':
						$validationArray = array('no_spaces' => '"'.$datasetField['db_column'].'" cannot contain spaces');
						break;
					case 'numeric':
						$validationArray = array('numeric' => '"'.$datasetField['db_column'].'" must be numeric');
						break;
					case 'screen_name':
						$validationArray = array('screen_name' => '"'.$datasetField['db_column'].'" is an invalid screen name');
						break;
				}
				if ($validationArray) {
					$fieldValuePicker['validation'] = $validationArray;
				}
				$box['tabs']['actions']['fields']['label__'.$fieldId] = array(
					'ord' => $ord + 500,
					'same_row' => true,
					'read_only' => true,
					'type' => 'text',
					'value' => $datasetField['db_column'],
					'style' => 'width: 15em;');
				$box['tabs']['actions']['fields'][$valueFieldName] = $fieldValuePicker;
			}
		}
		
		$values['preview/csv_preview'] = $filePreviewString;
		
		if (!$values['headers/key_line'] || $newFileUploaded) {
			$values['headers/key_line'] = $keyLine;
		}
		
		$totalLines = $lineNumber;
		$totalWarnings = $warningCount;
		$totalErrors = $errorCount;
		$totalBlanks = count($blankLines);
		
		$fields['preview/total_readable_lines']['snippet']['html'] = '<b>Total readable lines:</b> '. ($totalLines - $totalErrors - $totalBlanks - 1);
		$plural = ($totalErrors == 1) ? '' : 's';
		$errorsText = $totalErrors. ' error'.$plural;
		$plural = ($totalWarnings == 1) ? '' : 's';
		$warningsText = $totalWarnings. ' warning'.$plural;
		$fields['preview/problems']['label'] = 'Problems ('.$errorsText.', '.$warningsText.'):';
		$values['preview/problems'] = $problems;
		
		$fields['preview/error_options']['hidden'] = $fields['preview/desc2']['hidden'] = (count($warningLines) == 0);
		
		$effectedRecords = $totalLines - $totalErrors - $totalBlanks - 1 - $updateCount;
		if ($values['preview/error_options'] == 'skip_warning_lines') {
			$effectedRecords -= $totalWarnings;
		}
		
		$plural = ($effectedRecords == 1) ? '' : 's';
		if ($values['file/type'] == 'insert_data') {
			$recordStatement = '<b>'.$effectedRecords. '</b> new record'.$plural.' will be created.';
		} else {
			$recordStatement = '<b>'.$effectedRecords. '</b> record'.$plural.' will be updated.';
		}
		if ($updateCount) {
			$plural = ($updateCount == 1) ? '' : 's';
			$recordStatement .= ' <b>'.$updateCount.'</b> record'.$plural.' will be updated.';
		}
		$fields['actions/records_statement']['snippet']['html'] = $recordStatement;
		
		$box['key']['warning_lines'] = implode(',', $warningLines);
		$box['key']['error_lines'] = implode(',', $errorLines);
		$box['key']['blank_lines'] = implode(',', $blankLines);
		$box['key']['file_path'] = $path;
	}
	
	private static $step2FieldWidth = 20;
	private static function generateFieldHeaders(&$box, $type) {
		$value = 'Field names';
		if ($type === 'csv') {
			$value .= ' (from CSV file)';
		} else {
			$value .= ' (from spreadsheet)';
		}
		
		$box['tabs']['headers']['fields']['file_column_headers'] = array(
			'ord' => 3,
			'snippet' => array(
				'html' => '
					<div style="width:' . (self::$step2FieldWidth + 1) . 'em;float:left;"><b>' . $value . '</b></div>
					<div><b>Database columns</b></div>
				'
			),
			'post_field_html' => '<br/>'
		);
	}
	
	private static function generateFieldRow(&$box, $ord, $value, $currentMatchedFields, $fieldId) {
		$databaseColumnName = 'database_column__'.$value;
		if (isset($currentMatchedFields[$databaseColumnName])) {
			$fieldId = $currentMatchedFields[$databaseColumnName];
		}
		$box['tabs']['headers']['fields']['file_column__'.$value] = array(
			'ord' => $ord + 500,
			'same_row' => true,
			'read_only' => true,
			'type' => 'text',
			'value' => $value,
			'style' => 'width: ' . self::$step2FieldWidth . 'em;'
		);
		$box['tabs']['headers']['fields'][$databaseColumnName] = array(
			'ord' => $ord + 500.5,
			'same_row' => true,
			'post_field_html' => '<br/>',
			'type' => 'select',
			'empty_value' => "-- Don't import --",
			'values' => 'dataset_fields',
			'value' => $fieldId
		);
	}
	
	
	private static $ids = array();
	private static $emails = array();
	private static $screenNames = array();
	private static $systemDataIDColumn = false;
	
	
	private static function validateImportLine(&$problems, $datasetDetails, $datasetFieldDetails, $rowFieldIdLink, $lineValues, $lineNumber, $IDColumnIndex, $update, $insertOption, &$updateCount) {
		
		$userSystemFields = array();
		$DBColumnValueIndexLink = array();
		$errorCount = 0;
		
		$userImport = ($datasetDetails['extends_organizer_panel'] == 'zenario__users/panels/users');
		$mergeOrOverwriteRow = false;
		
		foreach ($rowFieldIdLink as $ord => $fieldId) {
			$field = false;
			$columnIndex = $ord + 1;
			
			if (isset($datasetFieldDetails[$fieldId])) {
				$field = $datasetFieldDetails[$fieldId];
				$DBColumnValueIndexLink[$field['db_column']] = $columnIndex;
			}
			
			// Validate fields
			if ($field && $field['db_column'] && isset($lineValues[$ord])) {
				
				$value = trim($lineValues[$ord]);
				
				// If updating, ensure there is a matching field, and not multiple entries
				if (($IDColumnIndex !== false) && ($ord == $IDColumnIndex)) {
					if ($value === '') {
						$errorMessage = 'ID field is blank';
						self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
					} else {
						// Record duplicates of chosen ID field in import
						if ($errorLines = self::recordUniqueImportValue(self::$ids, $value, $lineNumber)) {
							$errorMessage = 'More than one line in the file has a matching ID column ('.implode(', ',$errorLines).')';
							self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
						}
						
						// Find matching records to update
						$currentRows = getRowsArray($datasetDetails['system_table'], $field['db_column'], array($field['db_column'] => $value));
						$rowCount = count($currentRows);
						if ($rowCount == 0) {
							$errorMessage = 'No existing record found for ID column '.$field['db_column'];
							self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
						} elseif ($rowCount > 1) {
							// TODO this should be a warning, not an error.
							$errorMessage = 'More than one existing record found for ID column '.$field['db_column'];
							self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
						}
					}
				}
				
				// Custom users validation
				if ($userImport && $field['is_system_field']) {
					$userSystemFields[$field['db_column']] = $value;
					if (($field['db_column'] == 'email') && ($value !== '')) {
						if ($errorLines = self::recordUniqueImportValue(self::$emails, $value, $lineNumber)) {
							$errorMessage = 'More than one line in the file has the same email address ('.implode(', ',$errorLines).')';
							self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
						} else {
							// Check if this should be merged/overwrite an existing user
							if (!$update && ($insertOption != 'no_update')) {
								$sql = '
									SELECT COUNT(*)
									FROM ' . DB_NAME_PREFIX . 'users
									WHERE email = "' . sqlEscape($value) . '"';
								$result = sqlSelect($sql);
								$row = sqlFetchRow($result);
								$count = $row[0];
								
								if ($count == 1) {
									$mergeOrOverwriteRow = true;
								} elseif ($count > 1) {
									$errorMessage = 'More than one user has the same email address';
									self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
								}
							}
						}
					} elseif ($field['db_column'] == 'screen_name') {
						self::recordUniqueImportValue(self::$screenNames, $value, $lineNumber);
					}
				}
				
				// Validate fields with validation rules
				$validationError = false;
				if ($value !== '') {
					switch ($field['validation']) {
						case 'email':
							// Ignore this for users email as it's validated with isInvalidUser()
							if (!($userImport && ($field['db_column'] == 'email'))) {
								if (!validateEmailAddress($value)) {
									$validationError = true;
									if (!$field['validation_message'])  {
										$validationErrorMessages[] = 'Value is in incorrect format for email';
									} else {
										$validationErrorMessages[] = $field['validation_message'];
									}
								}
							}
							break;
						case 'emails':
							if (!validateEmailAddress($value, true)) {
								$validationError = true;
								if (!$field['validation_message']) {
									$validationErrorMessages[] = 'Value is in incorrect format for emails';
								} else {
									$validationErrorMessages[] = $field['validation_message'];
								}
							}
							break;
						case 'no_spaces':
							if (preg_replace('/\S/', '', $value)) {
								$validationError = true;
								if (!$field['validation_message']) {
									$validationErrorMessages[] = 'Value cannot contain spaces';
								} else {
									$validationErrorMessages[] = $field['validation_message'];
								}
							}
							break;
						case 'numeric':
							if (!is_numeric($value)) {
								$validationError = true;
								if (!$field['validation_message']) {
									$validationErrorMessages[] = 'Value must be numeric';
								} else {
									$validationErrorMessages[] = $field['validation_message'];
								}
							}
							break;
						case 'screen_name':
							if (!validateScreenName($value)) {
								$validationError = true;
								if (!$field['validation_message']) {
									$validationErrorMessages[] = 'Screen name is invalid';
								} else {
									$validationErrorMessages[] = $field['validation_message'];
								}
							}
							break;
					}
					if ($validationError) {
						foreach ($validationErrorMessages as $key => $errorMessage) {
							self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
						}
					}
				}
				
				// Validate required fields
				if ($field['required'] && ($value === '')) {
					if (!$errorMessage = $field['required_message']) {
						$errorMessage = 'Value is required but missing';
					}
					self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
				}
				
				// Validate fields with a values source
				if ($field['values_source']) {
					$lov = getCentralisedListValues($field['values_source']);
					if (!isset($lov[$value])) {
						$errorMessage = 'Unknown list value';
						self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
					}
				}
				
			
			} elseif (($fieldId == 'id') && isset($lineValues[$ord])) {
				
				if ($value = trim($lineValues[$ord])) {
					if (!self::$systemDataIDColumn) {
						self::$systemDataIDColumn = self::getTablePrimaryKeyName($datasetDetails['system_table']);
					}
					$currentRow = getRowsArray($datasetDetails['system_table'], self::$systemDataIDColumn, array(self::$systemDataIDColumn => $value));
					$rowCount = count($currentRow);
					if ($rowCount == 0) {
						$errorMessage = 'No existing record found for ID column '.self::$systemDataIDColumn;
						self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
					}
					
					if (($IDColumnIndex !== false) && ($ord == $IDColumnIndex)) {
						if ($value === '') {
							$errorMessage = 'ID field is blank';
							self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
						} else {
							// Record duplicates of chosen ID field in import
							if ($errorLines = self::recordUniqueImportValue(self::$ids, $value, $lineNumber)) {
								$errorMessage = 'More than one line in the file has a matching ID column ('.implode(', ',$errorLines).')';
								self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
							}
						}
					}
				}
				
			}
		}
		
		if (!$update && $userImport && !$mergeOrOverwriteRow) {
			
			$userErrors = isInvalidUser($userSystemFields);
			if (is_object($userErrors) && $userErrors->errors) {
				
				foreach ($userErrors->errors as $db_column => $errorMessage) {
					$columnIndex = false;
					if (!empty($DBColumnValueIndexLink[$db_column])) {
						$columnIndex = $DBColumnValueIndexLink[$db_column];
					}
					switch ($errorMessage) {
						case '_ERROR_SCREEN_NAME_INVALID':
							$errorMessage = 'Screen name invalid';
							break;
						case '_ERROR_SCREEN_NAME_IN_USE':
							$errorMessage = 'Screen name in use';
							break;
						case '_ERROR_EMAIL_INVALID':
							$errorMessage = 'Value is in incorrect format for email';
							break;
						case '_ERROR_EMAIL_NAME_IN_USE':
							$errorMessage = 'Email in use';
							break;
					}
					self::addErrorMessage($problems, $errorCount, $errorMessage, $lineNumber, $columnIndex);
				}
			}
		}
		
		if ($errorCount == 0 && $mergeOrOverwriteRow) {
			++$updateCount;
		}
		
		return $errorCount;
	}
	
	private static function recordUniqueImportValue(&$array, $value, $lineNumber) {
		if (!isset($array[$value])) {
			$array[$value] = $lineNumber;
		} elseif (!is_array($array[$value])) {
			$array[$value] = array($array[$value], $lineNumber);
			return $array[$value];
		} else {
			$array[$value][] = $lineNumber;
			return $array[$value];
		}
		return false;
	}
	
	private static function addErrorMessage(&$problems, &$errorCount, $errorMessage, $lineNumber, $columnNumber) {
		if ($columnNumber) {
			$message = 'Error (Line [[line]], Value [[value]]): [[message]][[EOL]]';
		} else {
			$message = 'Error (Line [[line]]): [[message]][[EOL]]';
		}
		$problems .= adminPhrase($message, array(
			'line' => $lineNumber,
			'value' => $columnNumber,
			'message' => $errorMessage,
			'EOL' => PHP_EOL
		));
		++$errorCount;
	}
	
	private static function getAllDatasetFieldDetails($dataset) {
		$datasetFieldDetails = array();
		$sql = '
			SELECT 
				f.id, 
				f.is_system_field,
				f.db_column, 
				f.validation, 
				f.validation_message, 
				f.required, 
				f.required_message, 
				f.type, 
				f.values_source, 
				f.parent_id,
				f.is_system_field
			FROM '.DB_NAME_PREFIX.'custom_dataset_fields f
			INNER JOIN '.DB_NAME_PREFIX.'custom_dataset_tabs t
				ON (f.dataset_id = t.dataset_id) AND (f.tab_name = t.name)
			WHERE f.dataset_id = '.(int)$dataset. '
			AND f.db_column != ""
			ORDER BY t.ord, f.ord';
		$result = sqlSelect($sql);
		while ($row = sqlFetchAssoc($result)) {
			$datasetFieldDetails[$row['id']] = $row;
		}
		return $datasetFieldDetails;
	}
	
	public function saveAdminBox($path, $settingGroup, &$box, &$fields, &$values, $changes) {
		$datasetDetails = getDatasetDetails($box['key']['dataset']);
		
		// Get fieldIDs and column index
		$keyValues = array();
		$dataCount = 0;
		$firstNameFieldDetails = $lastNameFieldDetails = false;
		foreach ($box['tabs']['headers']['fields'] as $fieldName => $field) {
			if (isset($field['type']) && ($field['type'] == 'select') && chopPrefixOffOfString($fieldName, 'database_column__')) {
				if (!empty($field['value'])) {
					if (($field['value'] == 'name_split_on_first_space' || $field['value'] == 'name_split_on_last_space') && !$firstNameFieldDetails && !$lastNameFieldDetails) {
						$firstNameFieldDetails = getDatasetFieldDetails('first_name', $datasetDetails);
						$lastNameFieldDetails = getDatasetFieldDetails('last_name', $datasetDetails);
					}
					$keyValues[$dataCount] = $field['value'];
				}
				$dataCount++;
			}
		}
		
		$datasetFieldDetails = self::getAllDatasetFieldDetails($box['key']['dataset']);
		$constantValues = array();
		foreach ($datasetFieldDetails as $fieldId => $fieldDetails) {
			$fieldName = 'actions/value__'.$fieldId;
			if (isset($values[$fieldName]) && ($values[$fieldName] != '') && ($fieldDetails['type'] != 'checkboxes')) {
				$constantValues[$fieldId] = $values[$fieldName];
			}
		}
		$errorLines = $box['key']['error_lines'] ? explode(',', $box['key']['error_lines']) : array();
		$blankLines = $box['key']['blank_lines'] ? explode(',', $box['key']['blank_lines']) : array();
		$keyLine = $values['headers/key_line'];
		$warningLines = array();
		if ($values['preview/error_options'] == 'skip_warning_lines') {
			$warningLines = $box['key']['warning_lines'] ? explode(',', $box['key']['warning_lines']) : array();
		}
		$linesToSkip = array_merge($errorLines, $blankLines, $warningLines);
		
		$unexpectedErrors = array();
		if ($file = $values['file/file']) {
			$path = getPathOfUploadedFileInCacheDir($file);
			$mode = ($values['file/type'] == 'insert_data') ? 'insert' : 'update';
			$importValues = array();
			if (pathinfo($path, PATHINFO_EXTENSION) == 'csv') {
				ini_set('auto_detect_line_endings', true);
				$f = fopen($path, 'r');
				$lineNumber = 0;
				while ($line = fgets($f)) {
					$lineNumber++;
					if (in_array($lineNumber, $linesToSkip) || $lineNumber == $keyLine) {
						continue;
					}
					$importValues[$lineNumber] = $constantValues;
					$data = str_getcsv($line);
					for ($dataCount = 0; $dataCount < count($data); $dataCount++) {
						if (isset($keyValues[$dataCount])) {
							$data[$dataCount] = trim($data[$dataCount]);
							if ($keyValues[$dataCount] == 'name_split_on_first_space') {
								if (($pos = strpos($data[$dataCount], ' ')) !== false) {
									$importValues[$lineNumber][$firstNameFieldDetails['id']] = substr($data[$dataCount], 0, $pos);
									$importValues[$lineNumber][$lastNameFieldDetails['id']] =  substr($data[$dataCount], $pos + 1);
								} else {
									$importValues[$lineNumber][$firstNameFieldDetails['id']] = $data[$dataCount];
								}
								
							} elseif ($keyValues[$dataCount] == 'name_split_on_last_space') {
								if (($pos = strrpos($data[$dataCount], ' ')) !== false) {
									$importValues[$lineNumber][$firstNameFieldDetails['id']] = substr($data[$dataCount], 0, $pos);
									$importValues[$lineNumber][$lastNameFieldDetails['id']] = substr($data[$dataCount], $pos + 1);
								} else {
									$importValues[$lineNumber][$firstNameFieldDetails['id']] = $data[$dataCount];
								}
							} else {
								$importValues[$lineNumber][$keyValues[$dataCount]] = $data[$dataCount];
							}
						}
					}
				}
				
			} else {
				require_once CMS_ROOT.'zenario/libraries/lgpl/PHPExcel_1_7_8/Classes/PHPExcel.php';
				$inputFileType = PHPExcel_IOFactory::identify($path);
				$objReader = PHPExcel_IOFactory::createReader($inputFileType);
				$objReader->setReadDataOnly(true);
				$objPHPExcel = $objReader->load($path);
				$worksheet = $objPHPExcel->getSheet(0);
				$startingColumn = 0;
				$endingColumn = 0;
				foreach ($worksheet->getRowIterator() as $row) {
					$lineNumber = $row->getRowIndex();
					// Skip errors, blanks and/or warning lines
					if (in_array($lineNumber, $linesToSkip)) {
						continue;
					}
					$dataCount = 0;
					// Get the list of matched column headers and db_columns
					$cellIterator = $row->getCellIterator();
					$cellIterator->setIterateOnlyExistingCells(false);
					// Set constant values
					if ($lineNumber > $keyLine) {
						$importValues[$lineNumber] = $constantValues;
					}
					foreach ($cellIterator as $cell) {
						$value = $cell->getCalculatedValue();
						$columnIndex = PHPExcel_Cell::columnIndexFromString($cell->getColumn());
						if ($lineNumber == $keyLine) {
							if (!is_null($value) && !$startingColumn) {
								$startingColumn = $endingColumn = $columnIndex;
							}
							if ($startingColumn) {
								if (empty($value)) {
									break;
								}
								if ($startingColumn != $columnIndex) {
									$endingColumn++;
								}
							}
						} else {
							if (($columnIndex >= $startingColumn) && ($columnIndex <= $endingColumn)) {
								if (!is_null($value) && isset($keyValues[$dataCount])) {
									$importValues[$lineNumber][$keyValues[$dataCount]] = trim($value);
								}
								$dataCount++;
							}
						}
					}
				}
			}
			
			// Import data
			$unexpectedErrors = self::setImportData($box['key']['dataset'], $importValues, $mode, $values['headers/insert_options'], $box['key']['ID_column']);
		}
		
		
		// Send report email
		if ($values['actions/email_report']) {
			$adminDetails = getAdminDetails(adminId());
			$path = getPathOfUploadedFileInCacheDir($values['file/file']);
			$filename = pathinfo($path, PATHINFO_BASENAME);
			$createOrUpdate = 'create';
			if ($values['file/type'] == 'update_data') {
				$createOrUpdate = 'update';
			}
			$body = "Import settings \n\n";
			$body .= 'File: '.$filename."\n";
			$body .= 'Mode: '.$createOrUpdate."\n";
			$body .= 'Key line: '.$values['headers/key_line']."\n";
			$body .= strip_tags($fields['actions/records_statement']['snippet']['html'])."\n\n";
			$body .= "Error log: \n\n";
			$errorLog = ($values['preview/problems'] ? $values['preview/problems'] : 'No errors or warnings');
			$body .= $errorLog;
			/*
			if ($unexpectedErrors) {
				$body .= "\n\nUnexpected Errors:\n\n";
				$body .= $unexpectedErrors;
			}
			*/
			sendEmail('Dataset Import Report', $body, $adminDetails['email'], $addressToOverriddenBy, false, false, false, array(), array(), 'bulk', false);
		}
	}
	
	private static function getTablePrimaryKeyName($tableName) {
		$sql = '
			SHOW KEYS
			FROM ' . DB_NAME_PREFIX . sqlEscape($tableName) . '
			WHERE Key_name = "PRIMARY"';
		$result = sqlSelect($sql);
		$row = sqlFetchAssoc($result);
		return $row['Column_name'];
	}
	
	private static function setImportData($datasetId, $importData, $mode, $insertMode, $keyFieldID) {
		
		$datasetDetails = getDatasetDetails($datasetId);
		$customDataIDColumn = self::getTablePrimaryKeyName($datasetDetails['table']);
		$systemDataIDColumn = self::getTablePrimaryKeyName($datasetDetails['system_table']);
		
		$fieldIdDetails = array();
		$errorMessage = '';
		
		foreach ($importData as $i => $record) {
			$error = false;
			$message = 'Line: '.($i+1)."\n";
			
			// Sort data into custom and non-custom
			$customData = array();
			$data = array();
			$id = false;
			
			foreach($record as $fieldId => $value) {
				if ($fieldId == 'id') {
					//
				} else {
					if (!isset($fieldIdDetails[$fieldId])) {
						$fieldIdDetails[$fieldId] = getRow('custom_dataset_fields', array('is_system_field', 'db_column'), $fieldId);
					}
					if ($fieldIdDetails[$fieldId]['is_system_field']) {
						$data[$fieldIdDetails[$fieldId]['db_column']] = $value;
					} else {
						$customData[$fieldIdDetails[$fieldId]['db_column']] = $value;
					}
					$message .= $fieldIdDetails[$fieldId]['db_column'].': '.$value. "\n";
					
					
					
				}
			}
			
			// Create or update records
			if ($mode == 'insert') {
				
				// Custom logic to save users
				if ($datasetDetails['extends_organizer_panel'] == 'zenario__users/panels/users') {
					
					// Attempt to update fields on email
					if ($insertMode != 'no_update') {
						$userId = getRow($datasetDetails['system_table'], $systemDataIDColumn, array('email' => $data['email']));
						if ($userId) {
							
							// Overwrite data
							if ($insertMode == 'overwrite') {
								updateRow($datasetDetails['system_table'], $data, $userId);
								setRow($datasetDetails['table'], $customData, $userId);
							
							// Merge data (only update blank fields)
							} elseif ($insertMode == 'merge') {
								$systemKeys = array_keys($data);
								$foundData = getRow($datasetDetails['system_table'], $systemKeys, $userId);
								foreach ($foundData as $col => $val) {
									if ($val) {
										unset($data[$col]);
									}
								}
								updateRow($datasetDetails['system_table'], $data, $userId);
								
								$customkeys = array_keys($customData);
								$foundData = getRow($datasetDetails['table'], $customkeys, $userId);
								foreach ($foundData as $col => $val) {
									if ($val) {
										unset($customData[$col]);
									}
								}
								setRow($datasetDetails['table'], $customData, $userId);
							}
							continue;
						}
					}
					
					if (!isset($data['email'])) {
						$data['email'] = '';
					}
					if (!isset($data['first_name'])) {
						$data['first_name'] = '';
					}
					if (!isset($data['last_name'])) {
						$data['last_name'] = '';
					}
					
					// Do not allow screen names to be imported to sites that don't use screen names
					if (!setting('user_use_screen_name')) {
						unset($data['screen_name']);
					}
					
					$id = saveUser($data);
					
					// If site uses screen names and no screen name is imported, use the identifier as a screen name
					if (empty($data['screen_name']) && setting('user_use_screen_name')) {
						$sql = '
							UPDATE ' . DB_NAME_PREFIX . 'users 
							SET screen_name = identifier
							WHERE id = ' . (int)$id;
						sqlQuery($sql);
					}
				
				// Custom logic to save locations
				} elseif ($datasetDetails['extends_organizer_panel'] == 'zenario__locations/panel') {
					$data['last_updated_via_import'] = now();
					$id = insertRow($datasetDetails['system_table'], $data);
				
				// Other datasets
				} else {
					$id = insertRow($datasetDetails['system_table'], $data);
				}
				// If record was created successfully, add custom data
				if ($id && is_numeric($id)) {
					if (!empty($customData)) {
						$customData[$customDataIDColumn] = $id;
						insertRow($datasetDetails['table'], $customData);
					}
				} elseif (is_object($id) && get_class($id) == 'zenario_error') {
					foreach ($id->errors as $errorField => $error) {
						$message .= 'Error code: '. phrase($error);
					}
					$error = true;
				} else {
					$message .= "Error: could not import";
					$error = true;
				}
				$message .= "\n\n";
			
			// Update records
			} elseif ($mode == 'update') {
				
				if ($keyFieldID == 'id') {
					$IDColumnValue = $record['id'];
					$IDColumn = $systemDataIDColumn;
				} else {
					$IDColumnValue = $data[$fieldIdDetails[$keyFieldID]['db_column']];
					$IDColumn = $fieldIdDetails[$keyFieldID]['db_column'];
				}
				
				// Custom logic to update users
				if ($datasetDetails['extends_organizer_panel'] == 'zenario__users/panels/users') {
					if (!setting('user_use_screen_name')) {
						unset($data['screen_name']);
					}
					$data['modified_date'] = now();
				} elseif ($datasetDetails['extends_organizer_panel'] == 'zenario__locations/panel') {
					$data['last_updated_via_import'] = now();
				}
				
				$rowsToUpdate = getRowsArray($datasetDetails['system_table'], $systemDataIDColumn, array($IDColumn => $IDColumnValue));
				foreach ($rowsToUpdate as $recordId) {
					// Update system data
					updateRow($datasetDetails['system_table'], $data, array($systemDataIDColumn => $recordId));
					// Update/create custom data
					setRow($datasetDetails['table'], $customData, array($customDataIDColumn => $recordId));
				}
			}
			if ($error) {
				$errorMessage .= $message;
			}
		}
		return $errorMessage;
	}
}