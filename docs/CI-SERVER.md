# GitHub remains primary; jobs run on Fabio's server

GitHub remains the authoritative repository for pull requests, issues,
discussions, reviews, Actions logs/checks/artifacts and releases. GitLab is only
a private, one-way Git backup; it does not replace GitHub or its release workflow.

All Linux jobs select `[self-hosted, Linux, X64, fabio-ci]` for the repository
owner and Dependabot, provided a pull request belongs to this repository.
Other actors and fork pull requests select GitHub-hosted Ubuntu runners.
Do not remove this routing when editing a workflow.

The SSH host `fabiodalez` runs one job at a time in a new Ubuntu 24.04 KVM VM
(4 vCPU, 8 GiB RAM). Docker and test databases run inside that VM. There is no
access to the host/LAN or host credentials, and the overlay is destroyed after
each job. The global supervisor allows the existing 120-minute regression jobs
plus startup/cleanup. The queue is shared with other repositories.

Workflows explicitly prepare required Linux tools; PHP, Node and browsers keep
their existing setup steps. Existing tests, release gates and artifact checks
are retained. Release jobs still attest and publish on GitHub after a version
tag; migrating runners does not create a release or change a version.

Public self-hosted repositories require review of the entire workflow queue:
labels and actor expressions are routing, not a security boundary against a
maliciously edited workflow. Isolation must remain enforced outside the VM.
Never add production credentials to the image or allow untrusted code to use
a persistent runner. Do not enable `pull_request_target` execution of PR code.

Before releasing, inspect the checks on the exact commit and `Release` workflow.
A migration commit alone is not evidence that every suite has passed on the new
runner. Operational configuration and tests are versioned in the `eventi`
repository under `infra/ci/`; server allowlists are root-only configuration.
