# == Class: pfsense_autoupdate
#
# This module handles unattended updates of pfSense firewalls.
#
# === Examples
#
#  class { 'pfsense_autoupdate':
#    update_hours => ['2-4', '6-8', 22],
#    update_weekdays => ['6-7'],
#    firmware_url => 'http://example.com/pfsense/firmware/',
#    major_updates => true,
#    random_sleep => false,
#    sig_verification => false,
#  }
#
class pfsense_autoupdate(
  # class
  $update_hours     = ['*'],
  $update_weekdays  = ['*'],
  $firmware_url     = undef,
  $major_updates    = false,
  $quiet            = false,
  $random_sleep     = true,
  $sig_verification = true,
  # pfsense
  $real_group       = 'nobody',
) {

  # Input validation
  include stdlib
  validate_array($update_hours)
  validate_array($update_weekdays)
  validate_bool($major_updates)
  validate_bool($quiet)
  validate_bool($sig_verification)
  validate_string($firmware_url)

  case $::operatingsystem {
    'FreeBSD': { }
    default: { fail("OS $::operatingsystem is not supported") }
  }

  if ! $::pfsense {
    fail("Requires a pfSense appliance")
  }

  $directory = '/usr/local/sbin'
  $updater = 'autoupdate.php'

  file { "${directory}/${updater}":
    ensure  => file,
    source  => "puppet:///modules/${module_name}/${updater}",
    owner   => root,
    group   => wheel,
    mode    => '0744',
  }

  file { "/usr/local/etc/autoupdate.ini":
    ensure  => file,
    content => template('pfsense_autoupdate/autoupdate.ini.erb'),
    owner   => root,
    group   => wheel,
    mode    => '0644',
  }

  # XXX: I consider this to be a temporary workaround. Ideally we'd use
  #      a new 'pfsense_cron' provider to create proper pfSense cronjobs.
  cron { 'pfsense_autoupdate':
    command  => "${directory}/${updater}",
    user     => root,
    hour     => $update_hours,
    minute   => '10',
    month    => '*',
    monthday => '*',
    weekday  => $update_weekdays,
  }

}
