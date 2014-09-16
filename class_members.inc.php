<?php

if (!class_exists('WPCW_Members'))
{
	/**
	 * The class used to create support for membership plugins.
	 *
	 */
	class WPCW_Members
	{
		/**
		 * Stores the name of the extension, i.e. the membership plugin that this is for.
		 * @var String 
		 */
		public $extensionName;
		
		/**
		 * Stores the version of this extension.
		 * @var Float 
		 */
		public $version;
		
		/**
		 * Stores the full unique string ID for this extension.
		 * @var String 
		 */
		public $extensionID;
		
		/**
		 * The cached storage of the membership levels.
		 * @var Array
		 */
		private $membershipLevelData;
		
		
		/**
		 * Initialise the membership plugin.
		 */
		function __construct($extensionName, $extensionID, $version)
		{
			$this->extensionName 	= $extensionName;
			$this->extensionID 		= $extensionID;
			$this->version 			= $version;		

			$this->membershipLevelData = false;
		}
		
		/**
		 * Create a menu for this plugin for configuration.
		 */
		function attachToTools() 
		{
			// Disable new user registration action (which otherwise sets course access).
			add_filter('wpcw_extensions_ignore_new_user', array($this, 'filter_disableNewUserHook'));			
			
			// Add items to the menu
			add_filter('wpcw_extensions_menu_items', array($this, 'init_menu'));
			
			// Indicate that extension is handling access control.
			add_filter('wpcw_extensions_access_control_override', array($this, 'filter_accessControlForUsers'));
			
			// Call child classes to handle user updates.
			$this->attach_updateUserCourseAccess();
		}
		
		
		/**
		 * Creates the page that shows the level mapping for users.
		 */
		function showMembershipMappingLevels()
		{
			// Page Intro
			$page = new PageBuilder(false);
			$page->showPageHeader($this->extensionName . ' ' . __('&amp; Automatic Course Access Settings', 'wp_courseware'), false, WPCW_icon_getPageIconURL());
			
			// Check for parameters for modifying the levels for a specific level
			$levelID = false;
			$showSummary = true;
			if (isset($_GET['level_id']))
			{
				// Seem to have a level, check it exists in the list
				$levelID = trim($_GET['level_id']);
				if ($levelID) 
				{
					$levelList = $this->getMembershipLevels();
					
					// Found the level in the list of levels we have.
					if (isset($levelList[$levelID])) 
					{
						// Show the page for editing those specific level settings.
						$showSummary = false;
						$this->showMembershipMappingLevels_specificLevel($page, $levelList[$levelID]);
					}
					
					// Not found in list, show an error.
					else {
						$page->showMessage(__('That membership level does not appear to exist.', 'wp_courseware'), true);
					}
					
				} // end if ($levelID)
			} // end  if (isset($_GET['level_id']))
			
			// Showing summary, as not editing a specific level above.
			if ($showSummary) {
				$this->showMembershipMappingLevels_overview($page);
			}
			
			
			// Page Footer
			$page->showPageFooter();
		}
		
		
		
		/**
		 * Show the form for editing the specific courses a user can access based on what level that they have access to.
		 * @param PageBuilder $page The page rendering object.
		 * @param Array $levelDetails The list of level details
		 */
		private function showMembershipMappingLevels_specificLevel($page, $levelDetails)
		{
			// Show a nice summary of what level is being edited.
			printf('<div id="wpcw_member_level_name_title">%s</div>', 
				sprintf(__('Editing permissions for <b>%s</b> level with <b>%s</b>:', 'wp_courseware'), $levelDetails['name'], $this->extensionName)
			);
			
			// Get a list of course IDs that exist
			$courses = WPCW_courses_getCourseList(false);
			
			
			// Get list of courses already associated with level.
			$courseListInDB = $this->getCourseAccessListForLevel($levelDetails['id']);
			
			// Create the summary URL to return
			$summaryURL = admin_url('admin.php?page=' . $this->extensionID);			
			
			
			// Update form...
			$form = new FormBuilder('wpcw_member_levels_edit');
			$form->setSubmitLabel(__('Save Changes', 'wp_courseware'));
			
			// Create list of courses using checkboxes (max of 2 columns)
			$elem = new FormElement('level_courses', __('Courses user can access at this level', 'wp_courseware'), false);
			$elem->setTypeAsCheckboxList($courses);
			$elem->checkboxListCols = 2;
			$form->addFormElement($elem);

			// Create retroactive option
			$elem = new FormElement('retroactive_assignment', __('Do you want to retroactively assign these courses to current active members?', 'wp_courseware'), true);
			$elem->setTypeAsRadioButtons(array(
		'Yes'				=> __('Yes', 'wp_courseware'),
		'No'				=> __('No', 'wp_courseware'),	
		));
			$form->addFormElement($elem);

			$form->setDefaultValues(array(
			'retroactive_assignment' => 'No'
			));
			
						
			// Normally would check for errors too, but there's not a lot to check here.
			if ($form->formSubmitted())
			{
				if ($form->formValid())
				{
					$mapplingList = $form->getValue('level_courses');

									
					global $wpdb, $wpcwdb;
					$wpdb->show_errors();
					
					// Remove all previous level mappings (as some will have been removed)
					$wpdb->query($wpdb->prepare("
						DELETE 
						FROM $wpcwdb->map_member_levels 
						WHERE member_level_id = %s
					", $levelDetails['id']));
					
					// Add all of the new mappings the user has chosen.
					if ($mapplingList && count($mapplingList) > 0)
					{
						foreach ($mapplingList as $courseID => $itemState)
						{
							$wpdb->query($wpdb->prepare("
								INSERT INTO $wpcwdb->map_member_levels 
								(course_id, member_level_id)  
								VALUES (%d, %s)
							", $courseID, $levelDetails['id']));

						}
						
					}

					// Get retroactive selection
					$retroactive_assignment = $form->getValue('retroactive_assignment');	

					// Call the retroactive assignment function passing the member level ID 
					if ($retroactive_assignment == 'Yes' && count($mapplingList) >= 0){
						$level_ID = $levelDetails['id'];
						$this->retroactive_assignment($level_ID);
						//$page->showMessage(__('All members were successfully retroactively enrolled into the selected courses.', 'wp_courseware'));
					}					
					
					// Show a success message.
					$page->showMessage(
						__('Level and course permissions successfully updated.', 'wp_courseware') . '<br/><br/>' .
						sprintf(__('Want to return to the <a href="%s">Course Access Settings summary</a>?', 'wp_courseware'), $summaryURL) 
					);
				
				} // if ($form->formValid())
				
			} // if ($form->formSubmitted()
			
			// Show the defaults that already exist in the database.
			else {
				$form->setDefaultValues(array('level_courses' => $courseListInDB));
			}
			
			
			// Show the form
			echo $form->toString();
			
			printf('<a href="%s" class="button-secondary">%s</a>', $summaryURL, __('&laquo; Return to Course Access Settings summary', 'wp_courseware'));
		
		}  
		
		
		/**
		 * Page that shows the overview of mapping for the levels to courses.
		 * @param PageBuilder $page The current page object.
		 */
		private function showMembershipMappingLevels_overview($page)
		{
			// Handle the detection of the membership plugin before doing anything else.
			if (!$this->found_membershipTool())
			{
				$page->showPageFooter();
				return;
			}
			
			
			// Try to show the level data
			$levelData = $this->getMembershipLevels_cached();
			if ($levelData)
			{
				// Create the table to show the data
				$table = new TableBuilder();
				$table->attributes = array('class' => 'wpcw_tbl widefat', 'id' => 'wpcw_members_tbl');
				
				$col = new TableColumn(__('Level ID', 'wp_courseware'), 'wpcw_members_id');
				$table->addColumn($col);
				
				$col = new TableColumn(__('Level Name', 'wp_courseware'), 'wpcw_members_name');
				$table->addColumn($col);
				
				$col = new TableColumn(__('Users at this level can access:', 'wp_courseware'), 'wpcw_members_levels');
				$table->addColumn($col);
				
				$col = new TableColumn(__('Actions', 'wp_courseware'), 'wpcw_members_actions');
				$table->addColumn($col);
				
				$odd = false;
				
				// Work out the base URL for the overview page 
				$baseURL = admin_url('admin.php?page=' . $this->extensionID);
				
				// The list of courses that are currently on the system.
				$courses = WPCW_courses_getCourseList(false);
									
				
				// Add actual level data
				foreach ($levelData as $id => $levelDatum)
				{
					$data = array();
					$data['wpcw_members_id'] 		= $levelDatum['id'];
					$data['wpcw_members_name'] 		= $levelDatum['name'];
					
					
					// Get list of courses already associated with level.
					$courseListInDB = $this->getCourseAccessListForLevel($levelDatum['id']);
					
					if ($courses)
					{
						$data['wpcw_members_levels']  = '<ul class="wpcw_tickitems">';
						
						// Show which courses will be added to users created at this level.
						foreach ($courses as $courseID => $courseName)
						{
							$data['wpcw_members_levels'] .= sprintf('<li class="wpcw_%s">%s</li>', (isset($courseListInDB[$courseID]) ? 'enabled' : 'disabled'), $courseName);							
						}
						
						$data['wpcw_members_levels'] .= '</ul>';
					}
					
					// No courses yet
					else {
						$data['wpcw_members_levels'] = __('There are no courses yet.', 'wp_courseware');	
					}
					
					
					
					// Buttons to edit the permissions
					$data['wpcw_members_actions'] 	= sprintf('<a href="%s&level_id=%s" class="button-secondary">%s</a>', 
						$baseURL, $levelDatum['id'], __('Edit Course Access Settings', 'wp_courseware')
					);					
					
					$odd = !$odd;
					$table->addRow($data, ($odd ? 'alternate' : ''));
				}
				
				echo $table->toString();
			}
			
			// Nothing found, show nice error message.
			else {
				$page->showMessage(sprintf(__('No membership levels were found for %s.', 'wp_courseware'), $this->extensionName), true);
			}			
		}
		
		
		/**
		 * Gets the membership levels if we've not already got them, and then
		 * caches them locally in this object to minimise database calls.
		 * 
		 * @return Array The membership data.
		 */
		protected function getMembershipLevels_cached()
		{
			if ($this->membershipLevelData) {
				return $this->membershipLevelData;
			}
			
			$this->membershipLevelData = $this->getMembershipLevels();
			return $this->membershipLevelData;
		}
		
		
		/**
		 * Get a list of the courses a user can access based on a specified level ID. Does not
		 * check that the level ID is valid.
		 * 
		 * @param String $levelID The ID of the level that determines which courses can be accessed.
		 * @return Array The list of courses that a user can access for the membership level ($courseID => $levelID).
		 */
		protected function getCourseAccessListForLevel($levelID)
		{
			global $wpcwdb, $wpdb;
			$wpdb->show_errors();
			
			$SQL = $wpdb->prepare("
				SELECT course_id 
				FROM $wpcwdb->map_member_levels
				WHERE member_level_id = %s
			", $levelID);
			
			$result = $wpdb->get_col($SQL);
			$courseList = false;
			
			if ($result)
			{
				$courseList = array();
				foreach ($result as $courseID)
				{
					$courseList[$courseID] = $levelID;
				}
			}
			
			return $courseList;
		}

		
		/**
		 * Adds the details for the extension to the menu for WP Courseware.
		 * 
		 * @param Array $menuItems The list of menu items to add this extension to.
		 * @return Array The list of menu items that has been modififed.
		 */
		public function init_menu($menuItems)
		{
			// Add our menu
			$menuDetails = array();
			$menuDetails['page_title'] 		= $this->extensionName;
			$menuDetails['menu_label'] 		= $this->extensionName;
			$menuDetails['id']				= $this->extensionID;
			
			// Use the function in this object to create the page that shows the level mapping.
			$menuDetails['menu_function']	= array($this, 'showMembershipMappingLevels');
			
			$menuItems[] = $menuDetails;
			
			return $menuItems;
		}
		
					
		/**
		 * Attaches method to WP hooks to show that the plugin has not been detected.
		 */
		public function attach_showToolNotDetectedMessage()
		{
			add_action('admin_notices', array($this, 'showToolNotDetectedMessage'));
		}
		
		
		/**
		 * Show the message that the tool has not been detected 
		 */
		public function showToolNotDetectedMessage()
		{
			printf('<div class="error"><p><b>%s - %s %s:</b> %s</p></div>', 'WP Courseware', $this->extensionName, __('addon', 'wp_courseware'), 
				sprintf(__('The %s plugin has not been detected. Is it installed and activated?', 'wp_courseware'), $this->extensionName)
			);
		}
		
		/**
		 * Attaches method to WP hooks to show that WP Courseware has not been detected.
		 */
		public function attach_showWPCWNotDetectedMessage()
		{
			add_action('admin_notices', array($this, 'showWPCWNotDetectedMessage'));
		}
		
		
		/**
		 * Show the message that WP Courseware has not been detected 
		 */
		public function showWPCWNotDetectedMessage()
		{
			printf('<div class="error"><p><b>%s</b> %s</p></div>', __('WP Courseware', 'wp_courseware'), __('has not been detected. Is it installed and activated?', 'wp_courseware'));
		}
		
		
		/**
		 * Changes the message indicating there's an override for the access control due to this extension.
		 * @param String $existing The HTML for the existing message.
		 * @return String The replacement HTML
		 */
		public function filter_accessControlForUsers($existing)
		{
			// Handle when there are multiple addons detected. If no other add ons are
			// detected, clear out the existing HTML.
			if (stripos($existing, 'wpcw_override') === FALSE) {
				$existing = false;
			}
			
			return $existing.sprintf('<li class="wpcw_bullet wpcw_override">%s <a href="%s">%s</a> %s</li>', 
				__('New users given access based on', 'wp_courseware'), 
				admin_url('admin.php?page=' . $this->extensionID),
				$this->extensionName,
				__('level', 'wp_courseware'));
		}
		
		/**
		 * By returning true, this disables the normal hook that would
		 * set the course access controls when a user is created, rather
		 * than relying on the user levels for membership.
		 */
		public function filter_disableNewUserHook() 
		{
			return true;
		}
		
		
		/**
		 * Method that gets the full list of user levels for the current membership plugin.
		 * 
		 * This is intended to be overridden.
		 */
		protected function getMembershipLevels()
		{
			return false;
		}
		
		

		
		
		/**
		 * Function called to attach hooks for handling when a user is updated or created.
		 * 
		 * This is intended to be overridden to handle different hooks for different membership plugins.
		 */
		protected function attach_updateUserCourseAccess()
		{
			return false;
		}
		
		
		/**
		 * Function called when updating a user for their course access.
		 */
		public function handle_courseSync($userID, $levelList)
		{
			global $wpdb, $wpcwdb;
			$wpdb->show_errors();
			
			$courseIDList = array();
			
			// Might not have any levels to process
			if ($levelList && count($levelList) > 0)
			{
				// Assume that there might be multiple levels per user.
				foreach ($levelList as $aLevelID)
				{
					// Got courses for this level
					$courses = $this->getCourseAccessListForLevel($aLevelID);
					if ($courses)
					{
						foreach ($courses as $courseIDToKeep => $levelID) 
						{
							// Use array index to build a list of valid course IDs
							// $levelID not needed, just used to assign something interesting. It's
							// the $courseIDToKeep that's the valuable bit.
							$courseIDList[$courseIDToKeep] = $levelID; 
						}
					}
				} // end foreach 
				
			} // end if ($levelList && count($levelList) > 0)
			
			// By this point, $courseIDList may or may not contain a list of courses.
			WPCW_courses_syncUserAccess($userID, array_keys($courseIDList), 'sync');
		}
		
		
		/**
		 * Function called to determine if WP Courseware is installed.
		 * @return Boolean True if it's found, false otherwise.
		 */
		public function found_wpcourseware()
		{
			return function_exists('WPCW_plugin_init');
		}

		
		/**
		 * Method to detect if the membership tool has been found or not. If false is returned,
		 * a need error message is shown to the user.
		 * 
		 * This is intended to be overridden.
		 */
		public function found_membershipTool()
		{
			return false;
		}		
	}
}
