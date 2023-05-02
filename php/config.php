<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'localhost';
$CFG->dbname    = 'db_18035406';
$CFG->dbuser    = '18035406';
$CFG->dbpass    = 'uEL9CvP3';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => '',
  'dbsocket' => '',
  'dbcollation' => 'utf8mb4_unicode_520_ci',
);

$CFG->wwwroot   = 'https://sfs-webdev.massey.ac.nz/site1983/moodle';
$CFG->dataroot  = '/home/students/home/18035406/moodledata';
$CFG->admin     = 'admin';
$CFG->slasharguments = false;

$CFG->directorypermissions = 02777;

require_once(__DIR__ . '/lib/setup.php');

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
