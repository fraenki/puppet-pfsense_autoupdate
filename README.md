#pfsense_autoupdate

##Table of Contents

- [Overview](#overview)
- [Module Description](#module-description)
- [Requirements](#requirements)
  - [Dependencies](#dependencies)
- [Usage](#usage)
  - [Simple example](#simple-example)
  - [Full example](#full-example)
- [Reference](#reference)
  - [How it works](#how-it-works)
- [Acknowledgement](#acknowledgement)
- [Development](#development)

##Overview

This module handles unattended updates of pfSense firewalls.

NOTE: This is NOT related to the pfSense project in any way. Do NOT ask the pfSense developers for support.

##Module Description

Updating pfSense firewalls is easy thanks to its proven upgrade mechanisms. Thus it can be automated and this modules does just that. 

WARNING! As with all updates this can go horribly wrong. You should test every update before installing it in production (i.e. auto-installing it one or two days earlier).

##Requirements

###Dependencies

Requires the puppetlabs/stdlib module.

##Usage

###Simple example

Enables automatic updates and checks hourly for new updates and install it (almost) instantly:

    class { 'pfsense_autoupdate': }

###Full example

Of course, you may want to customize it to match your needs:

    class { 'pfsense_autoupdate':
      major_updates => false,
      update_hours => ['22-23', '2-4', 6],
      update_weekdays => ['6-7'],
      random_sleep => false,
      firmware_url => 'http://example.com/pfsense/firmware/',
      sig_verification => false,
      quiet => true,
    }

In this examples quiet a lot is different from the default configuration:

* Major Updates are disabled. Only patch releases will be installed (e.g. 2.1.3 => 2.1.4).
* Updates will only be installed between 22-23, 2-4 and 6.
* Updates will only be installed on saturday and sunday.
* Random sleep before checking for updates is disabled. This is STRONGLY DISCOURAGED to protect pfSense mirrors servers against load peaks.
* A custom URL for firmware download is specified.
* The digital signature of the firmware will not be verified.
* The update script will suppress ANY output.

##Reference

###How it works

A portion of the firmware upgrade code was extracted from pfSense and put into a separate script. Some additional logic and a configuration file make sure that upgrades can be handled according to your needs. Finally a simple cronjob will run this script periodically to install updates automatically.

###Random delay

In default configuration the update script will wait a random amount of time between 1 and 600 seconds on startup. This avoids load spikes on the pfSense mirror servers. PLEASE do NOT disable this random delay as long as you use the official pfSense mirrors. I don't mind if you disable it when using your own private pfSense mirror server.

###CLI usage

For debug or testing purposes you may want to run the update script from the pfSense CLI:

    [2.1.3-RELEASE][admin@pfsense.example.com]/root(1): /usr/local/sbin/autoupdate.php
    [INFO] Sleeping 47 seconds...
    [INFO] Update URL set to https://updates.pfsense.org/_updaters/amd64.
    [INFO] Getting latest firmware information...
    [INFO] Extracting firmware version details.
    [INFO] Comparing firmware version.
    [INFO] An update is available: 2.1.3-RELEASE => 2.1.4-RELEASE
    [INFO] Downloading updates...
    [INFO] Download complete.
    [INFO] Launching upgrade helper...
    [INFO] pfSense is now upgrading.
    [INFO] The firewall will reboot once the operation is completed.

##Acknowledgement

The 'autoupdate.php' script is based on on system_firmware_auto.php (Copyright (C) 2008 Scott Ullrich <sullrich@gmail.com>, Copyright (C) 2005 Scott Ullrich). The latter is based originally on system_firmware.php (Copyright (C) 2003-2004 Manuel Kasper).

##Development

Please use the github issues functionality to report any bugs or requests for new features.
Feel free to fork and submit pull requests for potential contributions.
