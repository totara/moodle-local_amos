<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Prepares language packages for Moodle 2.0 in ZIP format to be published
 *
 * @package   local_amos
 * @copyright 2010 David Mudrak <david.mudrak@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once($CFG->libdir  . '/filelib.php');
require_once($CFG->dirroot . '/local/amos/cli/config.php');
require_once($CFG->dirroot . '/local/amos/mlanglib.php');
require_once($CFG->dirroot . '/local/amos/locallib.php');
require_once($CFG->dirroot . '/local/amos/renderer.php');

// Let us get an information about existing components
list($branchsql, $params) = $DB->get_in_or_equal(array(
    mlang_version::MOODLE_20,
    mlang_version::MOODLE_21,
    mlang_version::MOODLE_22,
    mlang_version::MOODLE_23,
    mlang_version::MOODLE_24,
));
$sql = "SELECT branch,lang,component,COUNT(stringid) AS numofstrings
          FROM {amos_repository}
         WHERE deleted=0 and branch $branchsql
      GROUP BY branch,lang,component
      ORDER BY branch,lang,component";
$rs = $DB->get_recordset_sql($sql, $params);
$tree = array();    // [branch][language][component] => numofstrings
foreach ($rs as $record) {
    $tree[$record->branch][$record->lang][$record->component] = $record->numofstrings;
}
$rs->close();
$packer = get_file_packer('application/zip');
$status = true; // success indicator

// setup.php sets umask to 0000 due to recursion issues in mkdir()
// let us try to set it to more sane value
umask(0022);

// prepare the final directory to be rsynced with download.moodle.org
if (!is_dir(AMOS_EXPORT_ZIP_DIR)) {
    if (!mkdir(AMOS_EXPORT_ZIP_DIR, 0755)) {
        throw new moodle_exception('unable_to_create_export_directory');
    }
}

// cleanup a temporary area where new ZIP files will be generated and their MD5 calculated
fulldelete($CFG->dataroot.'/amos/temp/export-zip');

foreach ($tree as $vercode => $languages) {
    $version = mlang_version::by_code($vercode);
    $packinfo = array(); // holds MD5 and timestamps of newly generated ZIP packs
    foreach ($languages as $langcode => $components) {
        /*if ($langcode == 'en') {
            // do not export English strings
            continue;
        }*/
        if (!mkdir($CFG->dataroot.'/amos/temp/export-zip/'.$version->dir.'/'.$langcode, 0755, true)) {
            throw new moodle_exception('unable_to_create_export_version_directory');
        }
        $zipfiles = array();
        $packinfo[$langcode]['modified'] = 0; // timestamp of the most recently modified component in the pack
        $packinfo[$langcode]['numofstrings'] = array(); // number of translated strings, per-component
        $langname = $langcode; // fallback to be replaced by localized name
        foreach ($components as $componentname => $unused) {
            $component = mlang_component::from_snapshot($componentname, $langcode, $version);
            $modified = $component->get_recent_timemodified();
            $packinfo[$langcode]['numofstrings'][$componentname] = $component->get_number_of_strings();
            if ($packinfo[$langcode]['modified'] < $modified) {
                $packinfo[$langcode]['modified'] = $modified;
            }
            if ($component->has_string()) {
                $file = $CFG->dataroot.'/amos/temp/export-zip/'.$version->dir.'/'.$langcode.'/'.$component->name.'.php';
                $component->export_phpfile($file);
                $zipfiles[$langcode . '/' . $component->name . '.php'] = $file;
            }
            if ($component->name == 'langconfig' and $component->has_string('thislanguage')) {
                $langname = $component->get_string('thislanguage')->text;
            }
            if ($component->name == 'langconfig' and $component->has_string('parentlanguage')) {
                $packinfo[$langcode]['parent'] = $component->get_string('parentlanguage')->text;
            }
            $component->clear();
        }
        $zipfile = $CFG->dataroot.'/amos/temp/export-zip/'.$version->dir.'/'.$langcode.'.zip';
        $status = $status and $packer->archive_to_pathname($zipfiles, $zipfile);
        if (!$status) {
            fputs(STDERR, "ERROR Unable to ZIP $zipfile\n");
            exit(1);
        }
        $packinfo[$langcode]['md5'] = md5_file($zipfile);
        $packinfo[$langcode]['filesize'] = filesize($zipfile);
        $packinfo[$langcode]['langname'] = $langname;
    }
    if (!file_exists($CFG->dataroot.'/amos/var/export-zip/'.$version->dir.'/packinfo.ser')) {
        if (!is_dir($CFG->dataroot.'/amos/var/export-zip/'.$version->dir)) {
            mkdir($CFG->dataroot.'/amos/var/export-zip/'.$version->dir, 0755, true);
        }
        $oldpackinfo = array();
    } else {
        $oldpackinfo = unserialize(file_get_contents($CFG->dataroot.'/amos/var/export-zip/'.$version->dir.'/packinfo.ser'));
    }

    // find the updated packages and move them into the folder for rsync
    $md5 = ''; // the contents of languages.md5
    $md5updated = false; // is the rebuild of languages.md5 needed?
    $newpackinfo = array();
    foreach ($packinfo as $langcode => $info) {
        $updated = false;
        if (!file_exists(AMOS_EXPORT_ZIP_DIR.'/'.$version->dir.'/'.$langcode.'.zip')) {
            $updated = true;
        } elseif (!isset($oldpackinfo[$langcode])) {
            $updated = true;
        } else {
            $oldinfo = $oldpackinfo[$langcode];
            if ($info['modified'] != $oldinfo['modified']) {
                $updated = true;
            }
        }

        // ZIP file created during this run of script
        $newzip = $CFG->dataroot.'/amos/temp/export-zip/'.$version->dir.'/'.$langcode.'.zip';
        // currently published ZIP file
        $currentzip = AMOS_EXPORT_ZIP_DIR.'/'.$version->dir.'/'.$langcode.'.zip';
        if ($updated) {
            fputs(STDOUT, "UPDATE $version->dir/$langcode.zip\n");
            // replace the current file with the updated one
            if (!is_dir(dirname($currentzip))) {
                mkdir(dirname($currentzip), 0755, true);
            }
            rename($newzip, $currentzip);
            // update the MD5 record
            $md5 .= $langcode . ',' . $info['md5'] . ',' . $info['langname'] . "\n";
            $md5updated = true;
            $newpackinfo[$langcode] = $info;
        } else {
            fputs(STDOUT, "KEEP $version->dir/$langcode.zip\n");
            // keep the currently published ZIP file
            unlink($newzip);
            // keep the current MD5 record
            $md5 .= $langcode . ',' . $oldinfo['md5'] . ',' . $oldinfo['langname'] . "\n";
            $newpackinfo[$langcode] = $oldinfo;
        }
    }
    // store the packinfo
    if (!is_dir($CFG->dataroot.'/amos/var/export-zip/'.$version->dir)) {
        mkdir($CFG->dataroot.'/amos/var/export-zip/'.$version->dir, 0755, true);
    }
    file_put_contents($CFG->dataroot.'/amos/var/export-zip/'.$version->dir.'/packinfo.ser', serialize($newpackinfo));

    // store md5's of packages
    if (!is_dir(AMOS_EXPORT_ZIP_DIR.'/'.$version->dir)) {
        mkdir(AMOS_EXPORT_ZIP_DIR.'/'.$version->dir, 0755, true);
    }
    if ($md5updated) {
        file_put_contents(AMOS_EXPORT_ZIP_DIR.'/'.$version->dir.'/'.'languages.md5', $md5);
    }

    // prepare new index.php for the download server
    $indexpage = new local_amos_index_page($version, $newpackinfo);
    $output = $PAGE->get_renderer('local_amos', null, RENDERER_TARGET_GENERAL);
    $indexpagehtml = $output->render($indexpage);
    file_put_contents(AMOS_EXPORT_ZIP_DIR.'/'.$version->dir.'/'.'index.php', $indexpagehtml);
}
exit(0);
