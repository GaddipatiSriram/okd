# OKD Cluster Health — Known Issues & Fix Plan

Captured 2026-04-13 while attempting to install Jenkins + Tekton on `mgmt-devops-okd`.
The cluster is usable but has accumulated baseline problems that block platform work
(CI/CD, monitoring, any app needing a PVC). This document lists them and the intended fix.

Corresponding tasks: #1–#5.

---

## 1. Kubelet-serving CSRs are not auto-approved

**Symptom**
- `oc logs`, `oc exec`, `oc port-forward`, `oc rsh` fail with
  `tls: internal error` against kubelet (port 10250).
- Hundreds of `kubernetes.io/kubelet-serving` CSRs stuck `Pending`.

**Cause**
OKD's `machine-approver` only auto-approves `kubelet-client` CSRs.
Kubelet-serving CSRs are never approved automatically, so serving certs
eventually expire and kubelet TLS breaks.

**Stopgap (manual)**
```bash
oc get csr -o go-template='{{range .items}}{{if not .status}}{{.metadata.name}}{{"\n"}}{{end}}{{end}}' \
  | xargs -n1 oc adm certificate approve
```

**Proper fix**
Deploy an auto-approver (e.g. `postfinance/kubelet-csr-approver`) that
validates CSRs against known node IPs/names before approving.
Pin allowed nodes to the three masters in `vars/okd-agent.yml`.

---

## 2. No working default StorageClass

**Symptom**
- `oc get sc` → previously empty; now `local-path (default)` but helper pods fail.
- Any PVC stays `Pending` forever.
- Tekton Results PostgreSQL, Jenkins, ArgoCD workloads, etc. all blocked.

**Cause**
Rancher local-path-provisioner helper pods try to create directories under
`/opt/local-path-provisioner`. SCOS has a read-only `/opt`, so the helper
pod gets `mkdir: Permission denied` even with the `privileged` SCC.

**Fix options**
- **Reconfigure local-path** to use `/var/mnt/local-path-provisioner`
  and set the helper pod with `privileged: true` + SELinux `spc_t`.
- **NFS from pfSense** + `nfs-subdir-external-provisioner`
  (matches how we already use pfSense for HAProxy).
- **OpenEBS hostpath** on `/var`.

Pick one, mark it `storageclass.kubernetes.io/is-default-class=true`,
validate with a scratch `PersistentVolumeClaim`.

---

## 3. metrics-server / prometheus-adapter missing endpoints

**Symptom**
- `APIService v1beta1.metrics.k8s.io` → `MissingEndpoints` (93 days).
- Namespace deletions stall on `NamespaceDeletionDiscoveryFailure`.
- `oc top node` / `oc top pod` fail.
- HPAs cannot scale.

**Cause**
Service `openshift-monitoring/metrics-server` has no endpoints with the
`https` port. Likely prometheus-adapter not deployed or cluster-monitoring-operator
degraded.

**Fix**
```bash
oc get clusteroperator monitoring
oc -n openshift-monitoring get pods -l app.kubernetes.io/name=prometheus-adapter
oc -n openshift-monitoring describe deploy prometheus-adapter
```
If the deployment is missing, the `ClusterMonitoringConfig` ConfigMap in
`openshift-monitoring` may have been set to disable it, or CMO is degraded.

---

## 4. Manual drift — catalogs, operators, Helm releases not in playbooks

Manually applied during this session and **not** in any playbook:

| Resource | State |
|---|---|
| `Secret/pull-secret` in `openshift-config` | patched with Red Hat registry service account (user `8080277|automate`). **Rotate this token.** |
| `CatalogSource/redhat-operators` in `openshift-marketplace` | added (image `registry.redhat.io/redhat/redhat-operator-index:v4.16`) |
| `CatalogSource/community-operators` in `openshift-marketplace` | added but **not needed** (no Jenkins) — should be removed |
| `Subscription/openshift-pipelines-operator` in `openshift-operators` | installed v1.21.1, working |
| `TektonConfig/config.spec.result.disabled=true` | patched (Tekton Results disabled until storage works) |
| `Namespace/jenkins` + Helm release `jenkins` (chart `jenkinsci/jenkins`) | installed, pod stuck (storage + SCC) |
| `StorageClass/local-path` + `local-path-storage` ns | installed, **broken** (see #2) |

**Action**
Roll forward into new phases (`07-cluster-health.yml`, `08-cicd-stack.yml`)
or revert the manual state so git = truth. Also update the README (the
current ArgoCD section claims it's installed by the playbook — it isn't).

---

## 5. Jenkins Helm install needs retry after storage fix

**State**
`helm install jenkins jenkinsci/jenkins -n jenkins` ran; `StatefulSet/jenkins`
exists, pod `jenkins-0` is `Pending`:
- PVC `jenkins` waits on StorageClass to actually provision.
- SCC admission selected `restricted-v2` (not `anyuid`) despite the SCC grant —
  likely because the pod was created before the SCC binding or because the
  binding was applied to the wrong SA.

**Fix sequence (after #2 is green)**
1. `helm uninstall jenkins -n jenkins`
2. `oc delete pvc jenkins -n jenkins`
3. Verify `oc get clusterrolebinding -o wide | grep 'scc:anyuid' | grep jenkins`
4. `helm install jenkins jenkinsci/jenkins -n jenkins -f jenkins-values.yaml`
   with explicit `serviceAccount.name: jenkins` and the SCC granted first.
5. Expose via `oc create route edge --service=jenkins -n jenkins`.

---

## Why OpenShift Jenkins *operator* from OperatorHub.io won't work

For the record (so nobody tries it again):

- `operatorhubio-catalog` ships `jenkins-operator.v0.3.0`. Its CRDs use
  `apiextensions.k8s.io/v1beta1`, removed in k8s 1.22. OKD 4.16 = k8s 1.29 → InstallPlan fails.
- Red Hat `community-operators` catalog does **not** carry a Jenkins operator.
- Upstream `jenkinsci/kubernetes-operator` v0.8/v0.9 uses `v1` CRDs but the
  project publishes only Helm charts and raw manifests — **no OLM bundle**.
- Even if installed via Helm, `jenkinsci/kubernetes-operator` hard-requires
  `Pod.spec.securityContext == nil`. OpenShift SCC admission unconditionally
  injects SELinux, fsGroup, and seccomp → operator deletes the pod on every
  reconcile. **Fundamentally incompatible with OKD.**

Use the classic `jenkinsci/jenkins` Helm chart (StatefulSet) or OpenShift Pipelines (Tekton) instead.
