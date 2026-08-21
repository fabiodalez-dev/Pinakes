#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const root = path.resolve(__dirname, '..');
const testsDir = path.join(root, 'tests');
const policyPath = path.join(testsDir, 'ci-playwright-policy.json');
const policy = JSON.parse(fs.readFileSync(policyPath, 'utf8'));

function isGitIgnored(file) {
  const result = spawnSync('git', ['check-ignore', '-q', '--', path.join('tests', file)], {
    cwd: root,
    stdio: 'ignore',
  });
  return result.status === 0;
}

const specFiles = fs.readdirSync(testsDir)
  .filter((file) => file.endsWith('.spec.js') && !isGitIgnored(file))
  .sort();
const specSet = new Set(specFiles);

function namedEntries(key) {
  const entries = policy[key] || [];
  return entries.map((entry) => typeof entry === 'string' ? { file: entry, reason: '' } : entry);
}

const groups = {
  bootstrap: namedEntries('bootstrap'),
  setup: namedEntries('setup'),
  dedicated: namedEntries('dedicated'),
  excluded: namedEntries('excluded'),
};
const reserved = new Set(Object.values(groups).flat().map((entry) => entry.file));
const runnable = specFiles.filter((file) => !reserved.has(file));

function fail(message) {
  console.error(`✗ ${message}`);
  process.exitCode = 1;
}

/**
 * Detect workflows that can create, replace, edit, or delete GitHub Release
 * state. This is deliberately broader than `gh release`: a second publisher
 * can use the REST API, github-script, curl, or one of several release actions.
 * Read-only `gh api .../releases` calls remain allowed for upgrade tests.
 */
function mutatesGitHubReleases(source) {
  const normalized = source
    .replace(/\\\r?\n[\t ]*/g, ' ')
    .replace(/^[\t ]*#.*$/gm, ' ');

  if (/\bgh\s+release\s+(?:create|upload|edit|delete)\b/i.test(normalized)) return true;
  if (/uses:\s*(?:softprops\/action-gh-release|actions\/(?:create-release|upload-release-asset)|ncipollo\/release-action|marvinpinto\/action-automatic-releases|svenstaro\/upload-release-action)@/i.test(normalized)) {
    return true;
  }
  if (/\b(?:github|octokit)(?:\.rest)?\.repos\.(?:createRelease|updateRelease|deleteRelease|uploadReleaseAsset)\s*\(/i.test(normalized)) {
    return true;
  }
  if (/\b(?:github|octokit)\.request\s*\(\s*['"`](?:POST|PATCH|PUT|DELETE)\s+\/repos\/[^/]+\/[^/]+\/releases\b/i.test(normalized)) {
    return true;
  }
  // Same REST mutations expressed with an options object instead of the
  // route string: request({ method: 'POST', url: '/repos/o/r/releases' }).
  // Key order is free and URL templates carry their own braces ({owner}),
  // so inspect a bounded window after each call instead of brace-matching.
  for (const call of normalized.matchAll(/\b(?:github|octokit)\.request\s*\(\s*\{/gi)) {
    const options = normalized.slice(call.index, call.index + 500);
    if (/\bmethod\s*:\s*['"`](?:POST|PATCH|PUT|DELETE)['"`]/i.test(options)
      && /\burl\s*:\s*['"`][^'"`]*\/repos\/[^'"`]+\/releases\b/i.test(options)) {
      return true;
    }
  }
  if (/\bmutation\b[\s\S]*\b(?:createRelease|updateRelease|deleteRelease)\s*\(/i.test(normalized)) {
    return true;
  }

  for (const command of normalized.split(/[;\n]/)) {
    const releaseEndpoint = /(?:api\.github\.com\/)?repos\/(?:(?:\$\{?GITHUB_REPOSITORY\}?|\$\{\{\s*github\.repository\s*\}\})|[^/\s'"`]+\/[^/\s'"`]+)\/releases(?:\/|\?|\s|['"`]|$)/i;
    const mutatingMethod = /(?:^|\s)(?:-X\s*|--method(?:=|\s+))(?:POST|PATCH|PUT|DELETE)\b/i;
    // `gh api` defaults to POST as soon as a typed/raw field or --input is
    // supplied, so looking only for an explicit -X/--method leaves an easy
    // second-publisher escape hatch.
    const ghApiPayload = /(?:^|\s)(?:-f|--raw-field|-F|--field|--input)(?:=|\s+)/i;
    if (/\bgh\s+api\b/i.test(command)
      && releaseEndpoint.test(command)
      && (mutatingMethod.test(command) || ghApiPayload.test(command))) {
      return true;
    }
    const curlPayload = /(?:^|\s)(?:-d|--data(?:-ascii|-binary|-raw|-urlencode)?|-F|--form(?:-string)?|--json|--upload-file|-T)(?:=|\s)/i;
    if (/\bcurl\b/i.test(command)
      && /(?:api\.github\.com|\$\{?GITHUB_API_URL\}?)/i.test(command)
      && releaseEndpoint.test(command)
      && (mutatingMethod.test(command) || curlPayload.test(command))) {
      return true;
    }
    if (/\bInvoke-RestMethod\b/i.test(command)
      && releaseEndpoint.test(command)
      && /(?:^|\s)-Method\s+(?:Post|Patch|Put|Delete)\b/i.test(command)) {
      return true;
    }
  }

  return false;
}

/**
 * Return the top-level arguments of a JavaScript call. This deliberately small
 * lexer is enough for test sources and avoids adding a parser dependency just
 * for one API-contract check.
 */
function callArguments(source, openParen) {
  const args = [];
  let start = openParen + 1;
  let parens = 1;
  let braces = 0;
  let brackets = 0;
  let quote = '';
  let escaped = false;
  let lineComment = false;
  let blockComment = false;

  for (let index = start; index < source.length; index += 1) {
    const char = source[index];
    const next = source[index + 1];
    if (lineComment) {
      if (char === '\n') lineComment = false;
      continue;
    }
    if (blockComment) {
      if (char === '*' && next === '/') { blockComment = false; index += 1; }
      continue;
    }
    if (quote) {
      if (escaped) escaped = false;
      else if (char === '\\') escaped = true;
      else if (char === quote) quote = '';
      continue;
    }
    if (char === '/' && next === '/') { lineComment = true; index += 1; continue; }
    if (char === '/' && next === '*') { blockComment = true; index += 1; continue; }
    if (char === "'" || char === '"' || char === '`') { quote = char; continue; }
    if (char === '(') parens += 1;
    else if (char === ')') {
      parens -= 1;
      if (parens === 0) {
        args.push(source.slice(start, index).trim());
        return args;
      }
    } else if (char === '{') braces += 1;
    else if (char === '}') braces -= 1;
    else if (char === '[') brackets += 1;
    else if (char === ']') brackets -= 1;
    else if (char === ',' && parens === 1 && braces === 0 && brackets === 0) {
      args.push(source.slice(start, index).trim());
      start = index + 1;
    }
  }
  return [];
}

function checkWaitForFunctionOptions(file, source) {
  const marker = '.waitForFunction';
  let offset = 0;
  while ((offset = source.indexOf(marker, offset)) !== -1) {
    const openParen = source.indexOf('(', offset + marker.length);
    if (openParen === -1) break;
    const args = callArguments(source, openParen);
    if (args.length === 2 && /^\{[\s\S]*\btimeout\s*:/.test(args[1])) {
      const line = source.slice(0, offset).split('\n').length;
      fail(`${file}:${line} passes waitForFunction options as its browser argument; add null/undefined before { timeout }`);
    }
    offset = openParen + 1;
  }
}

function checkPolicy() {
  const seen = new Map();
  for (const [group, entries] of Object.entries(groups)) {
    for (const entry of entries) {
      if (!entry.file || !specSet.has(entry.file)) {
        fail(`${group}: missing Playwright spec ${entry.file || '<empty>'}`);
      }
      if (seen.has(entry.file)) {
        fail(`${entry.file} is classified twice (${seen.get(entry.file)}, ${group})`);
      }
      seen.set(entry.file, group);
      if ((group === 'dedicated' || group === 'excluded') && !String(entry.reason || '').trim()) {
        fail(`${group}: ${entry.file} needs an explicit reason`);
      }
    }
  }

  for (const file of specFiles) {
    const source = fs.readFileSync(path.join(testsDir, file), 'utf8');
    if (/(?:test|describe)\.only\s*\(/.test(source)) {
      fail(`${file} contains .only(), which would silently reduce CI coverage`);
    }
    if (/^[\t ]*test\.skip\s*\(\s*\)/m.test(source)) {
      fail(`${file} contains test.skip() without a reason`);
    }
    checkWaitForFunctionOptions(file, source);
  }

  const requiredWorkflows = ['ci-quality.yml', 'ci-e2e.yml', 'ci-deep-regression.yml'];
  for (const workflow of requiredWorkflows) {
    const workflowPath = path.join(root, '.github', 'workflows', workflow);
    if (!fs.existsSync(workflowPath)) {
      fail(`required workflow missing: ${workflow}`);
      continue;
    }
    const source = fs.readFileSync(workflowPath, 'utf8');
    if (/continue-on-error:\s*true/.test(source)) {
      fail(`${workflow} contains continue-on-error: true`);
    }
  }

  const mysqlWrapperPath = path.join(root, 'scripts', 'ci-bin', 'mysql');
  if (!fs.existsSync(mysqlWrapperPath)) {
    fail('CI MySQL wrapper is missing');
  } else {
    const wrapperEnv = {
      ...process.env,
      PINAKES_REAL_MYSQL: '/bin/echo',
      E2E_DB_HOST: '192.0.2.10',
      E2E_DB_PORT: '4406',
    };
    const tcpProbe = spawnSync(mysqlWrapperPath, ['probe'], { encoding: 'utf8', env: wrapperEnv });
    if (tcpProbe.status !== 0 || tcpProbe.stdout.trim() !== '-h 192.0.2.10 -P 4406 probe') {
      fail('CI MySQL wrapper does not inject the configured TCP endpoint');
    }
    const socketProbe = spawnSync(mysqlWrapperPath, ['-S', '/tmp/pinakes-test.sock', 'probe'], {
      encoding: 'utf8',
      env: wrapperEnv,
    });
    if (socketProbe.status !== 0 || socketProbe.stdout.trim() !== '-S /tmp/pinakes-test.sock probe') {
      fail('CI MySQL wrapper overrides an explicit socket endpoint');
    }
    const defaultsProbe = spawnSync(
      mysqlWrapperPath,
      ['--defaults-extra-file=/tmp/pinakes-test.cnf', 'probe'],
      { encoding: 'utf8', env: wrapperEnv },
    );
    if (
      defaultsProbe.status !== 0
      || defaultsProbe.stdout.trim() !== '--defaults-extra-file=/tmp/pinakes-test.cnf -h 192.0.2.10 -P 4406 probe'
    ) {
      fail('CI MySQL wrapper does not preserve defaults-file option ordering');
    }
  }

  const deepWorkflowPath = path.join(root, '.github', 'workflows', 'ci-deep-regression.yml');
  if (fs.existsSync(deepWorkflowPath)) {
    const deepWorkflow = fs.readFileSync(deepWorkflowPath, 'utf8');
    if (!/scripts\/ci-bin[^\n]*GITHUB_PATH/.test(deepWorkflow)) {
      fail('deep regression does not expose the CI MySQL wrapper on GITHUB_PATH');
    }
  }

  const releaseWorkflowPath = path.join(root, '.github', 'workflows', 'release.yml');
  const releaseOrchestratorPath = path.join(root, 'scripts', 'create-release.sh');
  const releasePolicyPath = path.join(root, 'scripts', 'ci-verify-release-source.sh');
  const releasePolicyTestPath = path.join(root, 'tests', 'release-source-policy.test.sh');
  if (
    !fs.existsSync(releaseOrchestratorPath)
    || !fs.existsSync(releasePolicyPath)
    || !fs.existsSync(releasePolicyTestPath)
  ) {
    // fail() only sets process.exitCode: return as well, or the
    // fs.readFileSync(releaseOrchestratorPath) below would throw a raw
    // ENOENT stack trace instead of this policy message.
    fail('release orchestrator, source policy, and regression test are required');
    return;
  }
  if (!fs.existsSync(releaseWorkflowPath)) {
    // Every contract below guards the sole-publisher pipeline; a deleted or
    // renamed release.yml must fail the policy, not skip it.
    fail('release workflow (.github/workflows/release.yml) is required');
  } else {
    const releaseWorkflow = fs.readFileSync(releaseWorkflowPath, 'utf8');
    if (!releaseWorkflow.includes('bash scripts/ci-verify-release-source.sh')) {
      fail('release workflow does not enforce the stable/prerelease source policy');
    }
    for (const permission of ['checks: read', 'statuses: read', 'pull-requests: read']) {
      if (!releaseWorkflow.includes(permission)) {
        fail(`release workflow is missing permission: ${permission}`);
      }
    }
    for (const contract of [
      'bash bin/build-release.sh --skip-build',
      'name: Verify and build release (read-only)',
      'name: Attest and publish verified release',
      'needs: build',
      'actions/download-artifact@',
      'gh release create',
      '--draft',
      '.uploader.login == "github-actions[bot]"',
      'gh release edit',
      '.immutable == true',
    ]) {
      if (!releaseWorkflow.includes(contract)) {
        fail(`release workflow is missing sole-publisher contract: ${contract}`);
      }
    }
    const buildJobStart = releaseWorkflow.indexOf('\n  build:');
    const publishJobStart = releaseWorkflow.indexOf('\n  publish:');
    const buildJob = buildJobStart >= 0 && publishJobStart > buildJobStart
      ? releaseWorkflow.slice(buildJobStart, publishJobStart)
      : '';
    const publishJob = publishJobStart >= 0 ? releaseWorkflow.slice(publishJobStart) : '';
    if (!buildJob.includes('contents: read')
      || /contents:\s*write|id-token:\s*write|attestations:\s*write/.test(buildJob)) {
      fail('release build job must remain read-only and must not receive OIDC/attestation write authority');
    }
    for (const permission of ['contents: write', 'id-token: write', 'attestations: write']) {
      if (!publishJob.includes(permission)) {
        fail(`release publish job is missing permission: ${permission}`);
      }
    }
  }

  const releaseOrchestrator = fs.readFileSync(releaseOrchestratorPath, 'utf8');
  for (const contract of [
    'bash scripts/ci-verify-release-source.sh',
    'git tag -a',
    'git push origin "refs/tags/${TAG_NAME}:refs/tags/${TAG_NAME}"',
    'gh run watch',
    '.uploader.login == "github-actions[bot]"',
    'immutable-releases',
    'workflow_run_floor',
    '.databaseId > $floor',
    "'.immutable'",
  ]) {
    if (!releaseOrchestrator.includes(contract)) {
      fail(`release orchestrator is missing contract: ${contract}`);
    }
  }
  if (/git\s+archive|bin\/build-release\.sh|gh\s+release\s+(?:create|upload|edit)/.test(releaseOrchestrator)) {
    fail('release orchestrator must not build or publish a competing artifact');
  }

  const upgradeSmokePath = path.join(root, '.github', 'workflows', 'ci-upgrade-smoke.yml');
  if (fs.existsSync(upgradeSmokePath)) {
    const upgradeSmoke = fs.readFileSync(upgradeSmokePath, 'utf8');
    for (const contract of [
      '$updater->runMigrations($from, $target)',
      'BASELINE_APP',
      'list-source-expectations.php plugins',
      'migrate_0.7.64.sql',
      'pickup_notification_sent',
      'Second Updater pass was not an idempotent no-op',
    ]) {
      if (!upgradeSmoke.includes(contract)) {
        fail(`upgrade smoke is missing production-migration contract: ${contract}`);
      }
    }
    if (/runMigrations\(\$target,\s*\$target\)/.test(upgradeSmoke)) {
      fail('upgrade smoke bypasses migration selection by using target as the baseline');
    }
    if (upgradeSmoke.includes('- name: Apply current branch migrations')) {
      fail('upgrade smoke must not pre-apply current SQL outside Updater');
    }
  }

  const qualityWorkflowPath = path.join(root, '.github', 'workflows', 'ci-quality.yml');
  if (fs.existsSync(qualityWorkflowPath)) {
    const qualityWorkflow = fs.readFileSync(qualityWorkflowPath, 'utf8');
    if (!qualityWorkflow.includes('bash tests/release-source-policy.test.sh')) {
      fail('code-quality workflow does not run release source policy regressions');
    }
  }

  const workflowDir = path.join(root, '.github', 'workflows');
  const releaseMutationFixtures = [
    'gh api -X POST repos/example/project/releases',
    'gh api repos/example/project/releases/1 --method DELETE',
    'gh api repos/example/project/releases -f tag_name=v1.2.3',
    'gh api repos/example/project/releases/1/assets --input payload.json',
    'gh api -X POST "repos/${GITHUB_REPOSITORY}/releases"',
    'gh api -X DELETE "repos/$GITHUB_REPOSITORY/releases/123"',
    'gh api --method PATCH "repos/${{ github.repository }}/releases/123"',
    'curl -X PATCH https://api.github.com/repos/example/project/releases/1',
    'curl --json @payload.json https://api.github.com/repos/example/project/releases',
    'curl -X POST --json @payload.json "$GITHUB_API_URL/repos/$GITHUB_REPOSITORY/releases"',
    'curl -X DELETE "${GITHUB_API_URL}/repos/${GITHUB_REPOSITORY}/releases/123"',
    'github.rest.repos.createRelease({ owner, repo })',
    'octokit.request("POST /repos/{owner}/{repo}/releases", payload)',
    'github.request({ method: "POST", url: "/repos/{owner}/{repo}/releases", data })',
    'octokit.request({ url: `/repos/${owner}/${repo}/releases/1`, method: "PATCH" })',
    'octokit.graphql(`mutation { createRelease(input: $input) { release { id } } }`)',
    'Invoke-RestMethod -Method Delete https://api.github.com/repos/example/project/releases/1',
    'uses: ncipollo/release-action@v1',
  ];
  const releaseReadFixtures = [
    'gh api repos/example/project/releases?per_page=100',
    'gh api -H "Accept: application/octet-stream" repos/example/project/releases/assets/1',
    'octokit.request({ method: "GET", url: "/repos/{owner}/{repo}/releases" })',
  ];
  for (const fixture of releaseMutationFixtures) {
    if (!mutatesGitHubReleases(fixture)) fail(`release publisher detector missed: ${fixture}`);
  }
  for (const fixture of releaseReadFixtures) {
    if (mutatesGitHubReleases(fixture)) fail(`release publisher detector rejected read-only API use: ${fixture}`);
  }
  for (const workflow of fs.readdirSync(workflowDir)) {
    const workflowPath = path.join(workflowDir, workflow);
    if (!fs.statSync(workflowPath).isFile()) continue;
    const source = fs.readFileSync(workflowPath, 'utf8');
    if (workflow !== 'release.yml' && mutatesGitHubReleases(source)) {
      fail(`${workflow} competes with release.yml as a GitHub Release publisher`);
    }
    if (/actions\/upload-artifact@v(?:[1-5])\b/.test(source)) {
      fail(`${workflow} uses an upload-artifact release with a deprecated Node runtime`);
    }
    if (/uses:\s*actions\/cache@(?:v[1-4]\b|[^\s#]+[^\n]*#\s*v[1-4]\b)/.test(source)) {
      fail(`${workflow} uses an actions/cache release with the deprecated Node 20 runtime`);
    }
    const checkoutSteps = source.split(/(?=^[ \t]*- (?:name:|uses:))/m)
      .filter((step) => /uses:\s*actions\/checkout@/.test(step));
    for (const step of checkoutSteps) {
      if (!/persist-credentials:\s*false/.test(step)) {
        fail(`${workflow} has an actions/checkout step that persists GitHub credentials`);
      }
    }
  }

  if (!fs.existsSync(path.join(root, 'package-lock.json'))) {
    fail('root package-lock.json is required for reproducible npm ci runs');
  }

  if (runnable.length === 0) fail('policy selected zero deep-regression specs');
  if (!process.exitCode) {
    console.log(`✓ Playwright policy: ${specFiles.length} total, ${runnable.length} deep, ${groups.bootstrap.length} bootstrap, ${groups.setup.length} setup, ${groups.dedicated.length} dedicated, ${groups.excluded.length} excluded`);
  }
}

function printFiles(files) {
  process.stdout.write(files.map((file) => `tests/${file}`).join('\n'));
  if (files.length) process.stdout.write('\n');
}

const command = process.argv[2] || 'check';
if (command === 'check') {
  checkPolicy();
} else if (command === 'list') {
  printFiles(runnable);
} else if (command === 'bootstrap' || command === 'setup') {
  printFiles(groups[command].map((entry) => entry.file));
} else if (command === 'shard') {
  const index = Number(process.argv[3]);
  const total = Number(process.argv[4]);
  if (!Number.isInteger(index) || !Number.isInteger(total) || index < 1 || index > total) {
    fail('usage: ci-playwright-policy.js shard <1-based-index> <total>');
  } else {
    printFiles(runnable.filter((_, position) => position % total === index - 1));
  }
} else {
  fail(`unknown command: ${command}`);
}
