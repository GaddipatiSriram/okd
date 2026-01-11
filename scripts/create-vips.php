<?php
/**
 * Create Virtual IPs for OKD on pfSense
 * Usage: php /tmp/create-vips.php <api_vip> <ingress_vip> <interface>
 */

require_once("config.inc");
require_once("util.inc");
require_once("interfaces.inc");

global $config;

// Get VIPs and interface from command line args
$api_vip = isset($argv[1]) ? $argv[1] : '10.10.2.50';
$ingress_vip = isset($argv[2]) ? $argv[2] : '10.10.2.51';
$interface = isset($argv[3]) ? $argv[3] : 'opt1';  // MGMT_DEVOPS

echo "Creating Virtual IPs for OKD\n";
echo "API VIP: $api_vip\n";
echo "Ingress VIP: $ingress_vip\n";
echo "Interface: $interface\n";

// Initialize virtualip config if needed
if (!isset($config['virtualip'])) {
    $config['virtualip'] = array();
}
if (!isset($config['virtualip']['vip']) || !is_array($config['virtualip']['vip'])) {
    $config['virtualip']['vip'] = array();
}

// VIP definitions
$vips = array(
    array(
        'descr' => 'OKD API VIP',
        'subnet' => $api_vip,
        'subnet_bits' => '32',
        'interface' => $interface,
        'mode' => 'ipalias',
        'type' => 'single'
    ),
    array(
        'descr' => 'OKD Ingress VIP',
        'subnet' => $ingress_vip,
        'subnet_bits' => '32',
        'interface' => $interface,
        'mode' => 'ipalias',
        'type' => 'single'
    )
);

// Get max uniqid
$max_uniqid = 0;
foreach ($config['virtualip']['vip'] as $vip) {
    if (isset($vip['uniqid'])) {
        $id = intval(str_replace('vip', '', $vip['uniqid']));
        if ($id > $max_uniqid) {
            $max_uniqid = $id;
        }
    }
}

// Create or update VIPs
foreach ($vips as $vip_def) {
    // Check if VIP already exists
    $found = false;
    foreach ($config['virtualip']['vip'] as $idx => $existing) {
        if (isset($existing['subnet']) && $existing['subnet'] == $vip_def['subnet']) {
            echo "Updating existing VIP: {$vip_def['subnet']}\n";
            $config['virtualip']['vip'][$idx]['descr'] = $vip_def['descr'];
            $config['virtualip']['vip'][$idx]['interface'] = $vip_def['interface'];
            $found = true;
            break;
        }
    }

    if (!$found) {
        $max_uniqid++;
        echo "Creating VIP: {$vip_def['subnet']} ({$vip_def['descr']})\n";

        $new_vip = array(
            'uniqid' => 'vip' . $max_uniqid,
            'descr' => $vip_def['descr'],
            'type' => $vip_def['type'],
            'subnet_bits' => $vip_def['subnet_bits'],
            'subnet' => $vip_def['subnet'],
            'interface' => $vip_def['interface'],
            'mode' => $vip_def['mode']
        );
        $config['virtualip']['vip'][] = $new_vip;
    }
}

// Write config
echo "Writing configuration...\n";
write_config("OKD Virtual IPs configured via Ansible");

// Apply VIPs using interface_ipalias_configure
echo "Applying Virtual IPs...\n";
foreach ($config['virtualip']['vip'] as $vip) {
    if (isset($vip['subnet']) && ($vip['subnet'] == $api_vip || $vip['subnet'] == $ingress_vip)) {
        echo "Bringing up VIP: {$vip['subnet']}\n";
        interface_ipalias_configure($vip);
    }
}

// Verify VIPs are up
echo "Verifying VIPs...\n";
sleep(2);
$ifconfig = shell_exec("ifconfig -a 2>/dev/null | grep -E '$api_vip|$ingress_vip'");
if (!empty(trim($ifconfig))) {
    echo "OK: VIPs are active\n";
    echo trim($ifconfig) . "\n";
} else {
    // Try alternate approach - add IP alias directly
    echo "Applying VIPs via ifconfig...\n";
    shell_exec("ifconfig vtnet1 alias $api_vip/32");
    shell_exec("ifconfig vtnet1 alias $ingress_vip/32");
    $ifconfig = shell_exec("ifconfig vtnet1 | grep -E '$api_vip|$ingress_vip'");
    if (!empty(trim($ifconfig))) {
        echo "OK: VIPs applied via ifconfig\n";
        echo trim($ifconfig) . "\n";
    } else {
        echo "WARNING: VIPs may not be active\n";
    }
}

echo "DONE: Virtual IPs configured\n";
?>
