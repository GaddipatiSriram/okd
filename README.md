# OKD Agent-based Installation on oVirt

Ansible playbooks for deploying OKD (OpenShift Kubernetes Distribution) on oVirt using the Agent-based Installer approach.

## Overview

This project uses the **Agent-based Installer** instead of the traditional IPI (Installer Provisioned Infrastructure) method. This approach avoids the keepalived bootstrap issues present in oVirt IPI installations.

### Key Features

- **No bootstrap chicken-and-egg problem** - VMs boot from self-contained ISO
- **External VIP management** - pfSense HAProxy handles API/Ingress VIPs
- **Fully automated** - Single `site.yml` playbook deploys entire cluster
- **OKD 4.16 SCOS** - Latest stable OKD on CentOS Stream CoreOS
- **3-node HA compact cluster** - Masters are schedulable (no separate workers)
- **Robust pfSense automation** - Uses `ansible.builtin.raw` for reliable SSH execution

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                OKD 3-Node Compact Cluster Architecture              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                    pfSense (HAProxy)                         │   │
│  │                    192.168.0.101 (WAN)                       │   │
│  │                                                              │   │
│  │  VIPs on MGMT_DEVOPS (vtnet1/opt1):                         │   │
│  │    API VIP:     10.10.2.50:6443, :22623                     │   │
│  │    Ingress VIP: 10.10.2.51:80, :443                         │   │
│  └──────────────────────┬──────────────────────────────────────┘   │
│                         │                                          │
│           ┌─────────────┼─────────────┐                            │
│           │             │             │                            │
│           ▼             ▼             ▼                            │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐                   │
│  │  Master-0   │ │  Master-1   │ │  Master-2   │                   │
│  │ 10.10.2.110 │ │ 10.10.2.111 │ │ 10.10.2.112 │                   │
│  │             │ │             │ │             │                   │
│  │ Control +   │ │ Control +   │ │ Control +   │                   │
│  │ Worker      │ │ Worker      │ │ Worker      │                   │
│  └─────────────┘ └─────────────┘ └─────────────┘                   │
│                                                                     │
│  Network: Mgmt-DevOps-Net (10.10.2.0/24, VLAN 11)                  │
└─────────────────────────────────────────────────────────────────────┘
```

## Quick Start

```bash
cd /root/learning/okd

# 1. Install Ansible collections
ansible-galaxy collection install -r requirements.yml -p ./collections

# 2. Full installation
ansible-playbook site.yml -e @vars/okd-agent.yml

# Or run individual phases:
ansible-playbook site.yml -e @vars/okd-agent.yml --tags prerequisites
ansible-playbook site.yml -e @vars/okd-agent.yml --tags dns
ansible-playbook site.yml -e @vars/okd-agent.yml --tags iso
ansible-playbook site.yml -e @vars/okd-agent.yml --tags deploy
ansible-playbook site.yml -e @vars/okd-agent.yml --tags wait
```

## Project Structure

```
okd/
├── ansible.cfg                    # Ansible configuration
├── requirements.yml               # Ansible collection dependencies
├── site.yml                       # Master playbook (orchestrates all phases)
├── inventory/
│   ├── hosts.ini                  # Inventory (localhost + pfSense)
│   └── host_vars/
│       └── pfsense-gw.yml         # pfSense SSH settings (no ControlMaster)
├── vars/
│   ├── okd-agent.yml              # Cluster configuration
│   └── ovirt-ids.yml              # Auto-generated oVirt IDs
├── playbooks/
│   ├── 00-destroy-cluster.yml     # Destroy existing cluster
│   ├── 00-setup-pfsense.yml       # Setup pfSense VIPs & HAProxy package
│   ├── 01-prerequisites.yml       # Validate environment
│   ├── 02-setup-dns.yml           # Configure DNS records
│   ├── 02a-setup-haproxy.yml      # Configure pfSense HAProxy backends/frontends
│   ├── 03-generate-agent-iso.yml  # Generate agent installer ISO
│   ├── 04-deploy-vms.yml          # Create VMs and boot from ISO
│   ├── 05-wait-install.yml        # Wait for cluster installation
│   └── 06-post-install.yml        # Post-install configuration
├── templates/
│   ├── install-config.yaml.j2     # Install config template
│   └── agent-config.yaml.j2       # Agent config template
├── scripts/
│   ├── create-vips.php            # Create Virtual IPs on pfSense
│   ├── create-haproxy-frontends.php  # Create HAProxy frontends
│   ├── delete-vips.php            # Remove Virtual IPs
│   └── delete-haproxy-okd.php     # Remove HAProxy configuration
├── tools/                         # OKD binaries (oc, openshift-install)
├── install/                       # Generated installation files
└── pull-secret.json               # OKD pull secret
```

## Configuration

Edit `vars/okd-agent.yml`:

```yaml
# Cluster Identity
cluster_name: "mgmt-devops-okd"
base_domain: "engatwork.com"

# OKD Version
okd_version: "4.16.0-0.okd-scos-2024-08-21-155613"

# Network
api_vip: "10.10.2.50"
ingress_vip: "10.10.2.51"
machine_network_cidr: "10.10.2.0/24"

# Cluster Sizing (3-node compact cluster - masters are schedulable)
control_plane_replicas: 3
compute_replicas: 0

# Master Nodes with static MAC addresses
master_nodes:
  - name: "master-0"
    ip: "10.10.2.110"
    mac: "56:6f:1c:a3:01:01"
  - name: "master-1"
    ip: "10.10.2.111"
    mac: "56:6f:1c:a3:01:02"
  - name: "master-2"
    ip: "10.10.2.112"
    mac: "56:6f:1c:a3:01:03"

# pfSense Configuration
pfsense_host: "192.168.0.101"
pfsense_user: "admin"
pfsense_password: "unix"
```

## Prerequisites

| Requirement | Details |
|-------------|---------|
| oVirt | 4.4+ with sufficient resources |
| pfSense | HAProxy package installed, SSH enabled |
| Network | DHCP enabled on target network |
| DNS | CoreDNS configured with OKD records |
| Ansible | 2.14+ with required collections |

### Required DNS Records

| Record | IP | Purpose |
|--------|----|---------|
| `api.mgmt-devops-okd.engatwork.com` | 10.10.2.50 | API endpoint |
| `api-int.mgmt-devops-okd.engatwork.com` | 10.10.2.50 | Internal API |
| `*.apps.mgmt-devops-okd.engatwork.com` | 10.10.2.51 | Application ingress |

### pfSense HAProxy Setup

The playbook automatically configures HAProxy on pfSense using the `pfsensible.core` collection:

1. **Virtual IPs** - Creates IP aliases on the MGMT_DEVOPS interface (opt1)
2. **Backends** - Creates backend pools with health checks
3. **Backend Servers** - Adds master/worker nodes to backend pools
4. **Frontends** - Creates TCP frontends bound to VIPs

| Frontend | Bind | Backend |
|----------|------|---------|
| okd-api-frontend | 10.10.2.50:6443 | okd-api-backend (master nodes) |
| okd-mcs-frontend | 10.10.2.50:22623 | okd-mcs-backend (master nodes) |
| okd-http-frontend | 10.10.2.51:80 | okd-http-backend (ingress nodes) |
| okd-https-frontend | 10.10.2.51:443 | okd-https-backend (ingress nodes) |

#### pfSense Requirements

- HAProxy package installed (`pfSense-pkg-haproxy-devel`)
- SSH access enabled
- Python 3.11 installed (`/usr/local/bin/python3.11`)

## Playbook Phases

The `site.yml` master playbook orchestrates all phases in order:

| Phase | Playbook | Tag | Description |
|-------|----------|-----|-------------|
| 0 | `00-destroy-cluster.yml` | destroy | Destroy existing cluster (run separately) |
| 1 | `01-prerequisites.yml` | prerequisites | Validate oVirt, pfSense, DNS connectivity |
| 1a | `00-setup-pfsense.yml` | pfsense | Install HAProxy, create VIPs |
| 2 | `02a-setup-haproxy.yml` | haproxy | Configure HAProxy backends/frontends |
| 3 | `02-setup-dns.yml` | dns | Add OKD DNS records to CoreDNS |
| 4 | `03-generate-agent-iso.yml` | iso | Generate agent installer ISO |
| 5 | `04-deploy-vms.yml` | deploy | Create VMs on oVirt, boot from ISO |
| 6 | `05-wait-install.yml` | wait | Monitor bootstrap & installation |
| 7 | `06-post-install.yml` | post-install | Configure OperatorHub, verify cluster |

## HAProxy Configuration

### Manual HAProxy Setup

To configure HAProxy separately:

```bash
# Configure HAProxy with specific backend IPs
ansible-playbook playbooks/02a-setup-haproxy.yml \
  -i inventory/hosts.ini \
  -e @vars/okd-agent.yml \
  -e 'backend_ips=["10.10.2.100","10.10.2.101","10.10.2.102"]'
```

### pfSense Automation

The playbooks use `ansible.builtin.raw` with inline PHP for pfSense configuration instead of `pfsensible.core.pfsense_phpshell`. This approach is more reliable because:

- **No stderr sensitivity** - The `pfsense_phpshell` module fails on any stderr output
- **No SSH ControlMaster issues** - pfSense SSH doesn't handle multiplexed connections well
- **Better error handling** - Uses `DONE` markers for reliable success detection

The `inventory/host_vars/pfsense-gw.yml` configures SSH settings:

```yaml
# Disable ControlMaster (pfSense SSH compatibility)
ansible_ssh_common_args: "-o ControlMaster=no -o ControlPersist=no -o PreferredAuthentications=password -o PubkeyAuthentication=no"
ansible_ssh_pipelining: false
```

### PHP Scripts (Reference)

The `scripts/` directory contains standalone PHP scripts for manual pfSense configuration:

| Script | Purpose |
|--------|---------|
| `create-vips.php` | Create Virtual IPs on pfSense interface |
| `create-haproxy-frontends.php` | Create HAProxy frontends |
| `delete-vips.php` | Remove OKD Virtual IPs |
| `delete-haproxy-okd.php` | Remove OKD HAProxy configuration |

## Destroy Cluster

```bash
ansible-playbook playbooks/00-destroy-cluster.yml \
  -i inventory/hosts.ini \
  -e @vars/okd-agent.yml \
  -e confirm_destroy=true
```

This will:
- Stop and remove OKD VMs from oVirt
- Remove OKD disks
- Remove HAProxy configuration from pfSense
- Remove Virtual IPs from pfSense
- Clean up DNS records
- Clean local install directory

## Accessing the Cluster

After installation completes:

```bash
# Set kubeconfig
export KUBECONFIG=/root/learning/okd/install/auth/kubeconfig

# Check nodes
./tools/oc get nodes

# Check cluster operators
./tools/oc get clusteroperators

# Get console URL
./tools/oc get route -n openshift-console console
```

### Credentials

| Access | Location |
|--------|----------|
| Console URL | `https://console-openshift-console.apps.mgmt-devops-okd.engatwork.com` |
| Username | `kubeadmin` |
| Password | `install/auth/kubeadmin-password` |
| Kubeconfig | `install/auth/kubeconfig` |

#### Fetching Credentials

```bash
# Get kubeadmin password
cat /root/learning/okd/install/auth/kubeadmin-password

# Get console URL
export KUBECONFIG=/root/learning/okd/install/auth/kubeconfig
./tools/oc get route console -n openshift-console -o jsonpath='{.spec.host}'

# Get API URL
echo "https://api.mgmt-devops-okd.engatwork.com:6443"

# Login with oc CLI
./tools/oc login -u kubeadmin -p $(cat install/auth/kubeadmin-password) \
  https://api.mgmt-devops-okd.engatwork.com:6443 --insecure-skip-tls-verify
```

The post-install playbook automatically prints credentials at the end of installation.

### ArgoCD Credentials

ArgoCD is deployed in the `argo` namespace.

| Access | Value |
|--------|-------|
| URL | `https://argocd-server-argo.apps.mgmt-devops-okd.engatwork.com` |
| Username | `admin` |
| Password | Retrieved from secret (see below) |

#### Fetching ArgoCD Password

```bash
# Get ArgoCD admin password
oc extract secret/argocd-cluster -n argo --to=-

# Or using kubectl
kubectl get secret argocd-cluster -n argo -o jsonpath='{.data.admin\.password}' | base64 -d; echo
```

## Troubleshooting

### Check VM Status in oVirt
```bash
curl -sk -u admin@internal:unix \
  https://ovirt.engatwork.com/ovirt-engine/api/vms \
  -H 'Accept: application/json' | jq '.vm[] | {name, status}'
```

### Check HAProxy on pfSense
```bash
# SSH to pfSense (from oVirt engine or management network)
ssh admin@192.168.0.101

# Check HAProxy config
cat /var/etc/haproxy/haproxy.cfg

# Check HAProxy is running
pgrep haproxy
sockstat -4 -l | grep haproxy

# Check VIPs are active
ifconfig vtnet1 | grep inet
```

### Check Installation Progress
```bash
tail -f /root/learning/okd/install/.openshift_install.log
```

### SSH to Nodes
```bash
# Get node IP from oVirt or DHCP leases
ssh core@<node-ip>
journalctl -u kubelet -f
```

## Components

| Component | Version | Purpose |
|-----------|---------|---------|
| OKD | 4.16.0-0.okd-scos | Kubernetes distribution |
| SCOS | CentOS Stream 9 | Node operating system |
| OVN-Kubernetes | - | Pod networking |
| HAProxy | 2.9-dev6 | Load balancer (on pfSense) |

## Network Configuration

| Network | CIDR | Purpose |
|---------|------|---------|
| Machine Network | 10.10.2.0/24 | Node IPs (DHCP) |
| Cluster Network | 10.128.0.0/14 | Pod IPs |
| Service Network | 172.30.0.0/16 | Service IPs |

## Why Agent-based Instead of IPI?

The oVirt IPI installer has a known **keepalived bootstrap issue**:

1. Bootstrap VM starts keepalived to manage VIPs
2. Keepalived-monitor tries to verify API via VIP
3. API isn't up yet → verification fails
4. Keepalived removes VIP → API can never be reached
5. **Deadlock** - installation hangs

The Agent-based Installer avoids this by:

1. Using external load balancer (pfSense HAProxy) for VIPs
2. VMs boot from self-contained ISO
3. No dependency on keepalived during bootstrap
4. VIPs are always available through HAProxy

## Ansible Collections

| Collection | Version | Purpose |
|------------|---------|---------|
| `ovirt.ovirt` | >=3.2.0 | oVirt API operations |
| `kubernetes.core` | >=6.0.0 | Kubernetes operations |
| `community.okd` | >=5.0.0 | OpenShift operations |
| `pfsensible.core` | >=0.7.0 | pfSense configuration |

## License

MIT
# okd
