<?php
/**
 * Delete HAProxy OKD configuration from pfSense
 * Usage: php /tmp/delete-haproxy-okd.php
 */

require_once("config.inc");
require_once("util.inc");
require_once("haproxy/haproxy.inc");

global $config;

echo "Removing HAProxy OKD configuration\n";

// Remove OKD frontends
$removed_frontends = 0;
if (isset($config['installedpackages']['haproxy']['ha_backends']['item']) &&
    is_array($config['installedpackages']['haproxy']['ha_backends']['item'])) {
    $new_items = array();
    foreach ($config['installedpackages']['haproxy']['ha_backends']['item'] as $frontend) {
        if (isset($frontend['name']) && strpos($frontend['name'], 'okd-') === 0) {
            echo "Removing frontend: {$frontend['name']}\n";
            $removed_frontends++;
            continue;
        }
        $new_items[] = $frontend;
    }
    $config['installedpackages']['haproxy']['ha_backends']['item'] = $new_items;
}

// Remove OKD backends
$removed_backends = 0;
if (isset($config['installedpackages']['haproxy']['ha_pools']['item']) &&
    is_array($config['installedpackages']['haproxy']['ha_pools']['item'])) {
    $new_items = array();
    foreach ($config['installedpackages']['haproxy']['ha_pools']['item'] as $backend) {
        if (isset($backend['name']) && strpos($backend['name'], 'okd-') === 0) {
            echo "Removing backend: {$backend['name']}\n";
            $removed_backends++;
            continue;
        }
        $new_items[] = $backend;
    }
    $config['installedpackages']['haproxy']['ha_pools']['item'] = $new_items;
}

if ($removed_frontends > 0 || $removed_backends > 0) {
    echo "Writing configuration...\n";
    write_config("OKD HAProxy configuration removed via Ansible");

    echo "Regenerating HAProxy configuration...\n";
    $savemsg = '';
    haproxy_check_and_run($savemsg, true);

    echo "Removed: $removed_frontends frontends, $removed_backends backends\n";
} else {
    echo "No OKD HAProxy configuration found\n";
}

echo "DONE\n";
?>
