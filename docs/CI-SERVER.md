# GitHub-hosted CI and private Git backups

GitHub is authoritative for code, pull requests, issues, discussions, reviews,
Actions checks/logs/artifacts and releases. Every workflow, including release
build and publishing, uses standard GitHub-hosted Ubuntu runners. Do not route
jobs to the personal server or reintroduce actor-dependent runner selection.

The self-hosted migration was withdrawn on 7 September 2026 at the owner's
request. The controller on SSH host `fabiodalez` is stopped and disabled at
boot. Existing job labels cannot provision a runner there. Superseded queued
runs must be cancelled; fresh runs must use the updated workflow revision.

All existing tests, version checks, release gates and pinned actions remain.
No tag, release or application version is created by this CI change. Check
the exact commit's required jobs before merging or releasing. New revisions
of a PR supersede its previous CI runs through the existing concurrency rules.

GitLab remains a private, one-way Git backup, not a second CI provider. The
server checks GitHub pushes every minute when its mirror queue is free and
copies changed repositories; an hourly full check is a recovery mechanism.
Backups retain replaced Git history and never delete destination refs. They
do not copy PRs, issues, uploads, databases or Git LFS objects.

Shared backup code lives in `eventi/infra/ci/`. Keep `fabio-git-mirror.timer`
and `fabio-git-mirror-poll.timer` active, but do not re-enable
`fabio-ci-controller`. Credentials stay outside Git. GitLab CI stays disabled.
