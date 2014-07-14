#!/usr/local/bin/php -q
<?php
/*    
    autoupdate.php
    Copyright (C) 2014 Frank Wall <fw@moov.de>

    Based on system_firmware_auto.php
    Copyright (C) 2008 Scott Ullrich <sullrich@gmail.com>
    Copyright (C) 2005 Scott Ullrich

    Based originally on system_firmware.php
    (C)2003-2004 Manuel Kasper
    All rights reserved.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
       this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright
       notice, this list of conditions and the following disclaimer in the
       documentation and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
    POSSIBILITY OF SUCH DAMAGE.
*/

require_once("config.inc");
require_once("config.lib.inc");
require_once("util.inc");
require_once("pfsense-utils.inc");

/* TODO
	- check available disk space
	- use a lock file
*/

/* parse optional config file */
$autoupdate_file = '/usr/local/etc/autoupdate.ini';
if (file_exists($autoupdate_file) && is_readable($autoupdate_file)) {
    $aucfg = parse_ini_file($autoupdate_file);
} else {
    /* set defaults */
    $aucfg = array(
        'quiet' => 0,
        'major_updates' => 1,
        'random_sleep' => 1,
        'sig_verification' => 1,
    );
}

/* sleep a random amount of time to protect official pfSense mirrors */
if ( $aucfg['random_sleep'] ) {
    $sleep = rand(1,600);
    verbose("Sleeping $sleep seconds...");
    sleep($sleep);
}

/* get current firmware information */
$curcfg = $config['system']['firmware'];

/* evaluate update_url */
if (isset($aucfg['firmware_url'])) {
    $updater_url = $aucfg['firmware_url'];
} else if (isset($curcfg['alturl']['enable'])) {
    $updater_url = "{$config['system']['firmware']['alturl']['firmwareurl']}";
} else {
    $updater_url = $g['update_url'];
}
verbose("Update URL set to $updater_url.");

/* retrieve latest firmware information */
verbose("Getting latest firmware information...");
@unlink("/tmp/{$g['product_name']}_version");
download_file("{$updater_url}/version{$nanosize}", "/tmp/{$g['product_name']}_version");
$latest_version = str_replace("\n", "", @file_get_contents("/tmp/{$g['product_name']}_version"));
if(!$latest_version) {
    error("Unable to check for updates.");
}

/* extract version details */
verbose("Extracting firmware version details.");
$current_installed_buildtime = trim(file_get_contents("/etc/version.buildtime"));
$current_installed_version = trim(file_get_contents("/etc/version"));
$latest_version = trim(@file_get_contents("/tmp/{$g['product_name']}_version"));
$latest_version_pfsense = strtotime($latest_version);
if(!$latest_version) {
    error("Unable to check for updates.");
}

/* compare versions */
verbose("Comparing firmware version.");
if (pfs_version_compare($current_installed_buildtime, $current_installed_version, $latest_version) == -1) {
    verbose("An update is available: $current_installed_version => $latest_version");

    /* check if major update is allowed */
    if (is_major_update($current_installed_version, $latest_version) && !$aucfg['major_updates']) {
        error("A major update is available, but not allowed. Exiting.");
    }

    verbose("Downloading updates...");
    conf_mount_rw();
    if ($g['platform'] == "nanobsd") {
        $update_filename = "latest{$nanosize}.img.gz";
    } else {
        $update_filename = "latest.tgz";
    }
    if (download_file("{$updater_url}/{$update_filename}", 
        "{$g['upload_path']}/latest.tgz", "read_body_firmware") !== true) {
        error("Download firmware failed.");
    }
    if (download_file("{$updater_url}/{$update_filename}.sha256", 
        "{$g['upload_path']}/latest.tgz.sha256") !== true) {
        error("Download firmware checksum failed.");
        exit;
    }
    conf_mount_ro();
    verbose("Download complete.");
} else {
    verbose("You are on the latest version.");
    exit;
}

/* verify digital signature */
$sigchk = 0;

if(!isset($curcfg['alturl']['enable'])) {
    $sigchk = verify_digital_signature("{$g['upload_path']}/latest.tgz");
}

$exitstatus = 0;
if ($sigchk == 1) {
    $sig_warning = "The digital signature on this image is invalid.";
    $exitstatus = 1;
} else if ($sigchk == 2) {
    $sig_warning = "This image is not digitally signed.";
    if (!isset($config['system']['firmware']['allowinvalidsig']) && !$aucfg['sig_verification']) {
        $exitstatus = 1;
    }
} else if (($sigchk >= 3)) {
    $sig_warning = "There has been an error verifying the signature on this image.";
    $exitstatus = 1;
}

/* display results */
if ($exitstatus) {
    verbose($sig_warning);
    error("Update cannot continue. You can disable this check by setting 'sig_verification=false'.");
} else if ($sigchk == 2) {
    error("Upgrade Image does not contain a signature but the system has been configured to allow unsigned images.");
}

/* verify gzip file */
if (!verify_gzip_file("{$g['upload_path']}/latest.tgz")) {
    if (file_exists("{$g['upload_path']}/latest.tgz")) {
        verbose("Deleting corrupt file.");
        conf_mount_rw();
        unlink("{$g['upload_path']}/latest.tgz");
        conf_mount_ro();
    }
    error("The image file is corrupt. Update cannot continue.");
}

/* compare sha256 sums */
$downloaded_latest_tgz_sha256 = str_replace("\n", "", `/sbin/sha256 -q {$g['upload_path']}/latest.tgz`);
$upgrade_latest_tgz_sha256 = str_replace("\n", "", `/bin/cat {$g['upload_path']}/latest.tgz.sha256 | /usr/bin/awk '{ print $4 }'`);
if($downloaded_latest_tgz_sha256 <> $upgrade_latest_tgz_sha256) {
    error("The sha256 sum does not match. Update cannot continue.");
}

/* launch external upgrade helper */
verbose("Launching upgrade helper...");
$external_upgrade_helper_text = "/etc/rc.firmware pfSenseupgrade ";
$external_upgrade_helper_text .= "{$g['upload_path']}/latest.tgz";
verbose($g['product_name'] . " is now upgrading.");
verbose("The firewall will reboot once the operation is completed.");
mwexec_bg($external_upgrade_helper_text);

function verbose ($message) {
    global $aucfg;
    if ($aucfg['quiet']) {
      return;
    }
    echo "[INFO] $message\n";
}

function error ($message) {
    global $aucfg;
    if (!$aucfg['quiet']) {
        echo "[ERROR] $message\n";
    }
    exit;
}

function download_file($url_file, $destination_file, $connect_timeout=60, $timeout=0) {
    global $config;
    /* open destination file */
    $fout = fopen($destination_file, "w+");

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url_file);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    /* Don't verify SSL peers since we don't have the certificates to do so. */
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_NOPROGRESS, '1');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    if (!empty($config['system']['proxyurl'])) {
        curl_setopt($ch, CURLOPT_PROXY, $config['system']['proxyurl']);
        if (!empty($config['system']['proxyport']))
            curl_setopt($ch, CURLOPT_PROXYPORT, $config['system']['proxyport']);
        if (!empty($config['system']['proxyuser']) && !empty($config['system']['proxypass'])) {
            curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY | CURLAUTH_ANYSAFE);
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$config['system']['proxyuser']}:{$config['system']['proxypass']}");
        }
    }

    curl_setopt($ch, CURLOPT_FILE, $fout); // write curl response to file
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    fclose($fout);
    curl_close($ch);
    return ($http_code == 200) ? true : $http_code;
}

function verify_gzip_file($fname) {
    $returnvar = mwexec("/usr/bin/gzip -t " . escapeshellarg($fname));
    if ($returnvar != 0) {
        return 0;
    } else {
        return 1;
    }
}

function is_major_update($current_version, $latest_version) {
    list($cur_num, $cur_str) = explode('-', $current_version);
    list($rem_num, $rem_str) = explode('-', $latest_version);
    list($cur_major, $cur_minor, $cur_patchlevel) = explode('.', $cur_num);
    list($rem_major, $rem_minor, $rem_patchlevel) = explode('.', $rem_num);
    if (($rem_major > $cur_major || $rem_minor > $cur_minor)) {
        return true;
    } else {
        return false;
    }
}

?>
