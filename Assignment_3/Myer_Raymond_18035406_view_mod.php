<?php

//  Display the course home page.
	
    require_once('../config.php');
    require_once('lib.php');
    require_once($CFG->libdir.'/completionlib.php');

    $id          = optional_param('id', 0, PARAM_INT);
    $name        = optional_param('name', '', PARAM_TEXT);
    $edit        = optional_param('edit', -1, PARAM_BOOL);
    $hide        = optional_param('hide', 0, PARAM_INT);
    $show        = optional_param('show', 0, PARAM_INT);
    $idnumber    = optional_param('idnumber', '', PARAM_RAW);
    $sectionid   = optional_param('sectionid', 0, PARAM_INT);
    $section     = optional_param('section', 0, PARAM_INT);
    $move        = optional_param('move', 0, PARAM_INT);
    $marker      = optional_param('marker',-1 , PARAM_INT);
    $switchrole  = optional_param('switchrole',-1, PARAM_INT); // Deprecated, use course/switchrole.php instead.
    $return      = optional_param('return', 0, PARAM_LOCALURL);

    $params = array();
    if (!empty($name)) {
        $params = array('shortname' => $name);
    } else if (!empty($idnumber)) {
        $params = array('idnumber' => $idnumber);
    } else if (!empty($id)) {
        $params = array('id' => $id);
    }else {
        print_error('unspecifycourseid', 'error');
    }

    $course = $DB->get_record('course', $params, '*', MUST_EXIST);

    $urlparams = array('id' => $course->id);

    // Sectionid should get priority over section number
    if ($sectionid) {
        $section = $DB->get_field('course_sections', 'section', array('id' => $sectionid, 'course' => $course->id), MUST_EXIST);
    }
    if ($section) {
        $urlparams['section'] = $section;
    }

    $PAGE->set_url('/course/view.php', $urlparams); // Defined here to avoid notices on errors etc

    // Prevent caching of this page to stop confusion when changing page after making AJAX changes
    $PAGE->set_cacheable(false);

    context_helper::preload_course($course->id);
    $context = context_course::instance($course->id, MUST_EXIST);

    // Remove any switched roles before checking login
    if ($switchrole == 0 && confirm_sesskey()) {
        role_switch($switchrole, $context);
    }

    require_login($course);

    // Switchrole - sanity check in cost-order...
    $reset_user_allowed_editing = false;
    if ($switchrole > 0 && confirm_sesskey() &&
        has_capability('moodle/role:switchroles', $context)) {
        // is this role assignable in this context?
        // inquiring minds want to know...
        $aroles = get_switchable_roles($context);
        if (is_array($aroles) && isset($aroles[$switchrole])) {
            role_switch($switchrole, $context);
            // Double check that this role is allowed here
            require_login($course);
        }
        // reset course page state - this prevents some weird problems ;-)
        $USER->activitycopy = false;
        $USER->activitycopycourse = NULL;
        unset($USER->activitycopyname);
        unset($SESSION->modform);
        $USER->editing = 0;
        $reset_user_allowed_editing = true;
    }

    //If course is hosted on an external server, redirect to corresponding
    //url with appropriate authentication attached as parameter
    if (file_exists($CFG->dirroot .'/course/externservercourse.php')) {
        include $CFG->dirroot .'/course/externservercourse.php';
        if (function_exists('extern_server_course')) {
            if ($extern_url = extern_server_course($course)) {
                redirect($extern_url);
            }
        }
    }


    require_once($CFG->dirroot.'/calendar/lib.php');    /// This is after login because it needs $USER

    // Must set layout before gettting section info. See MDL-47555.
    $PAGE->set_pagelayout('course');

    if ($section and $section > 0) {

        // Get section details and check it exists.
        $modinfo = get_fast_modinfo($course);
        $coursesections = $modinfo->get_section_info($section, MUST_EXIST);

        // Check user is allowed to see it.
        if (!$coursesections->uservisible) {
            // Check if coursesection has conditions affecting availability and if
            // so, output availability info.
            if ($coursesections->visible && $coursesections->availableinfo) {
                $sectionname     = get_section_name($course, $coursesections);
                $message = get_string('notavailablecourse', '', $sectionname);
                redirect(course_get_url($course), $message, null, \core\output\notification::NOTIFY_ERROR);
            } else {
                // Note: We actually already know they don't have this capability
                // or uservisible would have been true; this is just to get the
                // correct error message shown.
                require_capability('moodle/course:viewhiddensections', $context);
            }
        }
    }

    // Fix course format if it is no longer installed
    $course->format = course_get_format($course)->get_format();

    $PAGE->set_pagetype('course-view-' . $course->format);
    $PAGE->set_other_editing_capability('moodle/course:update');
    $PAGE->set_other_editing_capability('moodle/course:manageactivities');
    $PAGE->set_other_editing_capability('moodle/course:activityvisibility');
    if (course_format_uses_sections($course->format)) {
        $PAGE->set_other_editing_capability('moodle/course:sectionvisibility');
        $PAGE->set_other_editing_capability('moodle/course:movesections');
    }

    // Preload course format renderer before output starts.
    // This is a little hacky but necessary since
    // format.php is not included until after output starts
    if (file_exists($CFG->dirroot.'/course/format/'.$course->format.'/renderer.php')) {
        require_once($CFG->dirroot.'/course/format/'.$course->format.'/renderer.php');
        if (class_exists('format_'.$course->format.'_renderer')) {
            // call get_renderer only if renderer is defined in format plugin
            // otherwise an exception would be thrown
            $PAGE->get_renderer('format_'. $course->format);
        }
    }

    if ($reset_user_allowed_editing) {
        // ugly hack
        unset($PAGE->_user_allowed_editing);
    }

    if (!isset($USER->editing)) {
        $USER->editing = 0;
    }
    if ($PAGE->user_allowed_editing()) {
        if (($edit == 1) and confirm_sesskey()) {
            $USER->editing = 1;
            // Redirect to site root if Editing is toggled on frontpage
            if ($course->id == SITEID) {
                redirect($CFG->wwwroot .'/?redirect=0');
            } else if (!empty($return)) {
                redirect($CFG->wwwroot . $return);
            } else {
                $url = new moodle_url($PAGE->url, array('notifyeditingon' => 1));
                redirect($url);
            }
        } else if (($edit == 0) and confirm_sesskey()) {
            $USER->editing = 0;
            if(!empty($USER->activitycopy) && $USER->activitycopycourse == $course->id) {
                $USER->activitycopy       = false;
                $USER->activitycopycourse = NULL;
            }
            // Redirect to site root if Editing is toggled on frontpage
            if ($course->id == SITEID) {
                redirect($CFG->wwwroot .'/?redirect=0');
            } else if (!empty($return)) {
                redirect($CFG->wwwroot . $return);
            } else {
                redirect($PAGE->url);
            }
        }

        if (has_capability('moodle/course:sectionvisibility', $context)) {
            if ($hide && confirm_sesskey()) {
                set_section_visible($course->id, $hide, '0');
                redirect($PAGE->url);
            }

            if ($show && confirm_sesskey()) {
                set_section_visible($course->id, $show, '1');
                redirect($PAGE->url);
            }
        }

        if (!empty($section) && !empty($move) &&
                has_capability('moodle/course:movesections', $context) && confirm_sesskey()) {
            $destsection = $section + $move;
            if (move_section_to($course, $section, $destsection)) {
                if ($course->id == SITEID) {
                    redirect($CFG->wwwroot . '/?redirect=0');
                } else {
                    redirect(course_get_url($course));
                }
            } else {
                echo $OUTPUT->notification('An error occurred while moving a section');
            }
        }
    } else {
        $USER->editing = 0;
    }

    $SESSION->fromdiscussion = $PAGE->url->out(false);


    if ($course->id == SITEID) {
        // This course is not a real course.
        redirect($CFG->wwwroot .'/');
    }

    $completion = new completion_info($course);
    if ($completion->is_enabled()) {
        $PAGE->requires->string_for_js('completion-alt-manual-y', 'completion');
        $PAGE->requires->string_for_js('completion-alt-manual-n', 'completion');

        $PAGE->requires->js_init_call('M.core_completion.init');
    }

    // We are currently keeping the button here from 1.x to help new teachers figure out
    // what to do, even though the link also appears in the course admin block.  It also
    // means you can back out of a situation where you removed the admin block. :)
    if ($PAGE->user_allowed_editing()) {
        $buttons = $OUTPUT->edit_button($PAGE->url);
        $PAGE->set_button($buttons);
    }

    // If viewing a section, make the title more specific
    if ($section and $section > 0 and course_format_uses_sections($course->format)) {
        $sectionname = get_string('sectionname', "format_$course->format");
        $sectiontitle = get_section_name($course, $section);
        $PAGE->set_title(get_string('coursesectiontitle', 'moodle', array('course' => $course->fullname, 'sectiontitle' => $sectiontitle, 'sectionname' => $sectionname)));
    } else {
        $PAGE->set_title(get_string('coursetitle', 'moodle', array('course' => $course->fullname)));
    }

    $PAGE->set_heading($course->fullname);
    // Supressed for 158.120 echo $OUTPUT->header();

    if ($USER->editing == 1 && !empty($CFG->enableasyncbackup)) {

        // MDL-65321 The backup libraries are quite heavy, only require the bare minimum.
        require_once($CFG->dirroot . '/backup/util/helper/async_helper.class.php');

        if (async_helper::is_async_pending($id, 'course', 'backup')) {
            echo $OUTPUT->notification(get_string('pendingasyncedit', 'backup'), 'warning');
        }
    }

    if ($completion->is_enabled()) {
        // This value tracks whether there has been a dynamic change to the page.
        // It is used so that if a user does this - (a) set some tickmarks, (b)
        // go to another page, (c) clicks Back button - the page will
        // automatically reload. Otherwise it would start with the wrong tick
        // values.
        // Supressed for 158.120 // echo html_writer::start_tag('form', array('action'=>'.', 'method'=>'get'));
        // Supressed for 158.120 // echo html_writer::start_tag('div');
        // Supressed for 158.120 // echo html_writer::empty_tag('input', array('type'=>'hidden', 'id'=>'completion_dynamic_change', 'name'=>'completion_dynamic_change', 'value'=>'0'));
        // Supressed for 158.120 // echo html_writer::end_tag('div');
        // Supressed for 158.120 // echo html_writer::end_tag('form');
    }

    // Course wrapper start.
    // Supressed for 158.120 // echo html_writer::start_tag('div', array('class'=>'course-content'));

    // make sure that section 0 exists (this function will create one if it is missing)
    course_create_sections_if_missing($course, 0);

    // get information about course modules and existing module types
    // format.php in course formats may rely on presence of these variables
    $modinfo = get_fast_modinfo($course);
    $modnames = get_module_types_names();
    $modnamesplural = get_module_types_names(true);
    $modnamesused = $modinfo->get_used_module_names();
    $mods = $modinfo->get_cms();
    $sections = $modinfo->get_section_info_all();

    // CAUTION, hacky fundamental variable defintion to follow!
    // Note that because of the way course fromats are constructed though
    // inclusion we pass parameters around this way..
    $displaysection = $section;

    // Include the actual course format.
    // Supressed for 158.120 // require($CFG->dirroot .'/course/format/'. $course->format .'/format.php');
    // Content wrapper end.

    // Supressed for 158.120 // echo html_writer::end_tag('div');

    // Trigger course viewed event.
    // We don't trust $context here. Course format inclusion above executes in the global space. We can't assume
    // anything after that point.
    course_view(context_course::instance($course->id), $section);

    // Include course AJAX
    // Supressed for 158.120 // include_course_ajax($course, $modnamesused);

    // Supressed for 158.120 // echo $OUTPUT->footer();
    // 
    	
	/* ***********************************************
	 * This is the start of our section for 158.120
	 * 
	 * We create our own HTML for the course page
	 *
	 * view_mod.php gives you the start
	 *
	 * To complete all tasks of the assignment, you might need the following
	 *
	 * $USER->firstname // the first name of the user (lastname and email accordindly)
	 * $mod->url// the url for the module (use in the foreach loop)
	 * $mod->name // the name of the module, e.g., 'Assignment on databases' (use in the foreach loop)
	 * $PAGE->url // the url for the course page
	 * $CFG->wwwroot // the url for the site
	 * $theCurrentSesskey = sesskey();  // retrieves the current sesskey value and assigns it to a new variable
	 *
	 * To construct a url you might use the following (there are different ways to do so)
	 *
	 * you can use the fullstop to concatenate two strings like in echo "<h2>This is a " . "heading</h2>";
	 * you can use ' inside " like in echo "<h2>The name is 'Betty' </h2>";
	 *
	 * To see information about a variable use print_r() 
	 * print_r($thissection->sequence);
	 *
	 *
	 * You can define a variable and modify or increment its value
	 * $myValue = 0;
	 * $myValue = $myValue +1;  or  $myValue++;
	 *
	 *
	 * **********************************************/

	// create the head section of the html
	// provide the link to the stylesheet
	// create the title to be shown in the browser tab


	echo "<!DOCTYPE html>";
	echo "<html>";
	// start the body section of the html using div elements and references to the classes defined in the stylesheet
	echo "<head><title>Assignment 3</title>";
	echo "<link rel='stylesheet' type='text/css' href='CSSfile.css'/>";
	echo "<div class='flex-settings'><h1 class='flex-leftright'><a href='$CFG->wwwroot'>$SITE->shortname</a>";
	echo "<a href=''> $USER->firstname $USER->lastname</a></h1></div>"; 
	echo "</head>";
	echo "<body class='flex-topdown'>";
	// this is for the top section with links to sitename and user logged in



	echo "<div class='flex-settings'><h1> $course->fullname </h1>";
    echo "<h3><a href='$CFG->wwwroot'>Dashboard,</a> My Courses, <a = href='$PAGE->url'>$course->shortname</a></h3></div>";
	

	// this is for the section with the course name as heading and the links below

	// from here we have the course content
	// we need to work through the sections and the details within each section


	// The code following does not do all the checks it should do
	// E.g., it does not check if sections/modules are hidden and should 
	// not be shown to all users
		
	// This is needed so we have access to all course sections
	$course = course_get_format($course)->get_course();
		

	echo "<div class='flex-settings'>";
	// go through the sections of the course 
	$num_sections = course_get_format($course)->get_last_section_number();
	for ($section = 0; $section <= $num_sections; $section++) { 
		echo "<div class='flex-settings'>";
    	if (($section >= 1) && ($section <= $num_sections)) {
    		echo "<h2>Topic: $section</h2>";
        	if ($section < $num_sections){
        		echo "<div class='flex-center'></div>";
        	}
  		}
    
		// this gets the details for the section we are currently working on
       	if (!empty($sections[$section])) {
			$thissection = $sections[$section];
		} else {
			continue;
		}
		// Are there modules in this section? If yes show the details, if not move on to the next section
		if (!empty($thissection->sequence)) {
			// $sectionmods becomes a list of module ids for this section
			$sectionmods = explode(",", $thissection->sequence);
			// Show the modules in this section
			foreach ($sectionmods as $modnumber) {
            	//echo "<p>In for each loop: $modnumber</p>";
				// get the details for the module we are currently working on
				// $mod->name provides the name, $mod->url provides the url
               	$mod = $mods[$modnumber];
				// get the name of the type of module in the correct language, e.g., 'Forum'
               	$modulenames[$mod->modname] = get_string('modulename', $mod->modname);
				$modulename = $modulenames[$mod->modname];
               	// display the module information
               	echo "<p> $modulename: <a href='$mod->url'> $mod->name</a> </p>";
			}
        }
    echo "</div>";
	}
	echo "</body></div>";

	// footer section
	echo "<div class='flex-settings'><p>Number of modules in the course: $num_sections </p>";
	echo "<a href='$PAGE->url'><p>Link to the real course page</a></p>";
	echo "<p>You are logged in as<a href=''> $USER->firstname $USER->lastname</a><a href='$logout'>(LOGOUT)</a></p>";
	// closing tags for the html document structure
	echo "</html>";