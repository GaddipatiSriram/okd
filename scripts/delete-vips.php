<?php
/**
 * Delete OKD Virtual IPs from pfSense
 * Usage: php /tmp/delete-vips.php <api_vip> <ingress_vip>
 */

require_once("config.inc");
require_once("util.inc");
require_once("interfaces.inc");

global $config;

// Get VIPs from command line args
$api_vip = isset($argv[1]) ? $argv[1] : '10.10.2.50';
$ingress_vip = isset($argv[2]) ? $argv[2] : '10.10.2.51';

echo "Removing OKD Virtual IPs\n";
echo "API VIP: $api_vip\n";
echo "Ingress VIP: $ingress_vip\n";

$removed = 0;

// Remove OKD VIPs
if (isset($config['virtualip']['vip']) && is_array($config['virtualip']['vip'])) {
    $new_vips = array();
    foreach ($config['virtualip']['vip'] as $vip) {
        if (isset($vip['subnet']) && ($vip['subnet'] == $api_vip || $vip['subnet'] == $ingress_vip)) {
            echo "Removing VIP: {$vip['subnet']}\n";
            // Bring down the VIP
            if (function_exists('interface_vip_bring_down')) {
                interface_vip_bring_down($vip);
            }
            $removed++;
            continue;
        }
        $new_vips[] = $vip;
    }
    $config['virtualip']['vip'] = $new_vips;
}

if ($removed > 0) {
    echo "Writing configuration...\n";
    write_config("OKD Virtual IPs removed via Ansible");

    // Remove IP aliases via ifconfig
    echo "Removing IP aliases...\n";
    shell_exec("ifconfig vtnet1 -alias $api_vip 2>/dev/null");
    shell_exec("ifconfig vtnet1 -alias $ingress_vip 2>/dev/null");

    echo "Removed: $removed VIPs\n";
} else {
    echo "No OKD VIPs found\n";
}

echo "DONE\n";
?>
