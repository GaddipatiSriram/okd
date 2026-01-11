<?php
/**
 * Create HAProxy frontends for OKD on pfSense
 * Usage: php /tmp/create-haproxy-frontends.php <api_vip> <ingress_vip>
 */

require_once("config.inc");
require_once("util.inc");
require_once("haproxy/haproxy.inc");

global $config;

// Get VIPs from command line args
$api_vip = isset($argv[1]) ? $argv[1] : '10.10.2.50';
$ingress_vip = isset($argv[2]) ? $argv[2] : '10.10.2.51';

echo "Creating HAProxy frontends for OKD\n";
echo "API VIP: $api_vip\n";
echo "Ingress VIP: $ingress_vip\n";

// Initialize haproxy config structure if needed
if (!isset($config['installedpackages']['haproxy'])) {
    $config['installedpackages']['haproxy'] = array();
}

// Initialize ha_backends (frontends in pfSense terminology)
if (!isset($config['installedpackages']['haproxy']['ha_backends']) ||
    !is_array($config['installedpackages']['haproxy']['ha_backends'])) {
    $config['installedpackages']['haproxy']['ha_backends'] = array();
}
if (!isset($config['installedpackages']['haproxy']['ha_backends']['item']) ||
    !is_array($config['installedpackages']['haproxy']['ha_backends']['item'])) {
    $config['installedpackages']['haproxy']['ha_backends']['item'] = array();
}

// Verify backends exist (by name, not ID!)
$backend_names = array();
if (isset($config['installedpackages']['haproxy']['ha_pools']['item']) &&
    is_array($config['installedpackages']['haproxy']['ha_pools']['item'])) {
    foreach ($config['installedpackages']['haproxy']['ha_pools']['item'] as $pool) {
        if (isset($pool['name'])) {
            $backend_names[] = $pool['name'];
            echo "Found backend: {$pool['name']}\n";
        }
    }
}

if (empty($backend_names)) {
    echo "ERROR: No backends found! Run pfsensible modules first.\n";
    exit(1);
}

// Frontend definitions - backend_serverpool uses backend NAME, not ID
$frontends = array(
    array(
        'name' => 'okd-api-frontend',
        'desc' => 'OKD API Frontend',
        'type' => 'tcp',
        'ip' => $api_vip,
        'port' => '6443',
        'backend' => 'okd-api-backend'  // This is the backend NAME
    ),
    array(
        'name' => 'okd-mcs-frontend',
        'desc' => 'OKD Machine Config Frontend',
        'type' => 'tcp',
        'ip' => $api_vip,
        'port' => '22623',
        'backend' => 'okd-mcs-backend'
    ),
    array(
        'name' => 'okd-http-frontend',
        'desc' => 'OKD HTTP Ingress Frontend',
        'type' => 'tcp',
        'ip' => $ingress_vip,
        'port' => '80',
        'backend' => 'okd-http-backend'
    ),
    array(
        'name' => 'okd-https-frontend',
        'desc' => 'OKD HTTPS Ingress Frontend',
        'type' => 'tcp',
        'ip' => $ingress_vip,
        'port' => '443',
        'backend' => 'okd-https-backend'
    )
);

// Get max ID from existing frontends and backends
$max_id = 199;
foreach ($config['installedpackages']['haproxy']['ha_backends']['item'] as $fe) {
    if (isset($fe['id']) && intval($fe['id']) > $max_id) {
        $max_id = intval($fe['id']);
    }
}
if (isset($config['installedpackages']['haproxy']['ha_pools']['item']) &&
    is_array($config['installedpackages']['haproxy']['ha_pools']['item'])) {
    foreach ($config['installedpackages']['haproxy']['ha_pools']['item'] as $be) {
        if (isset($be['id']) && intval($be['id']) > $max_id) {
            $max_id = intval($be['id']);
        }
    }
}

// Clear existing OKD frontends
$new_items = array();
foreach ($config['installedpackages']['haproxy']['ha_backends']['item'] as $existing) {
    if (isset($existing['name']) && strpos($existing['name'], 'okd-') === 0) {
        echo "Removing existing frontend: {$existing['name']}\n";
        continue;
    }
    $new_items[] = $existing;
}
$config['installedpackages']['haproxy']['ha_backends']['item'] = $new_items;

// Create frontends
foreach ($frontends as $frontend) {
    // Verify the backend exists
    if (!in_array($frontend['backend'], $backend_names)) {
        echo "WARNING: Backend {$frontend['backend']} not found, skipping {$frontend['name']}\n";
        continue;
    }

    $max_id++;
    echo "Creating frontend: {$frontend['name']} (ID: $max_id) -> {$frontend['ip']}:{$frontend['port']} -> {$frontend['backend']}\n";

    // pfSense HAProxy frontend structure
    // backend_serverpool uses the backend NAME, not ID
    $new_frontend = array(
        'id' => strval($max_id),
        'name' => $frontend['name'],
        'desc' => $frontend['desc'],
        'status' => 'active',
        'type' => $frontend['type'],
        'backend_serverpool' => $frontend['backend'],  // Use backend NAME
        'a_extaddr' => array(
            'item' => array(
                array(
                    'extaddr' => 'custom',
                    'extaddr_custom' => $frontend['ip'],
                    'extaddr_port' => $frontend['port']
                )
            )
        )
    );
    $config['installedpackages']['haproxy']['ha_backends']['item'][] = $new_frontend;
}

// Write config
echo "Writing configuration...\n";
write_config("OKD HAProxy frontends configured via Ansible");

// Regenerate HAProxy config
echo "Regenerating HAProxy configuration...\n";
$savemsg = '';
haproxy_check_and_run($savemsg, true);

if (!empty($savemsg)) {
    echo "HAProxy result: $savemsg\n";
}

// Verify HAProxy is running
$running = shell_exec("pgrep haproxy");
if (!empty(trim($running))) {
    echo "OK: HAProxy is running (PID: " . trim($running) . ")\n";
} else {
    echo "WARNING: HAProxy may not be running - check logs\n";
}

echo "DONE: HAProxy frontends configured\n";
?>
