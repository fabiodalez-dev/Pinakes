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

  const workflowDir = path.join(root, '.github', 'workflows');
  for (const workflow of fs.readdirSync(workflowDir)) {
    const workflowPath = path.join(workflowDir, workflow);
    if (!fs.statSync(workflowPath).isFile()) continue;
    const source = fs.readFileSync(workflowPath, 'utf8');
    if (/actions\/upload-artifact@v(?:[1-5])\b/.test(source)) {
      fail(`${workflow} uses an upload-artifact release with a deprecated Node runtime`);
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
