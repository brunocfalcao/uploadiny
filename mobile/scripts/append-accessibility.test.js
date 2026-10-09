const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

test('append spoken context follows loading, failed, hidden and successful project lookups', { skip: process.platform !== 'darwin' }, () => {
  const source = fs.readFileSync(path.join(__dirname, '../native/ShareIntoViewController.swift'), 'utf8');
  const start = source.indexOf('  private func updateAppendRow() {');
  const end = source.indexOf('  private func lastUploadDate(', start);
  assert.ok(start >= 0 && end > start);
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'uploadiny-append-accessibility-'));
  try {
    const fixture = path.join(directory, 'main.swift');
    fs.writeFileSync(fixture, 'import Foundation\n' + String.raw`
struct Lookup { var loading = false; var failed = false }
struct Project { let id: Int }
struct Summary { let projectID: Int; let completedAt: Date; let fileCount: Int }
class Control { var isHidden = false; var text: String?; var accessibilityHint: String?; var isOn = false }
class Fixture {
  let appendRow = Control(), appendCaption = Control(), appendSwitch = Control()
  var appendLookup = Lookup(), selectedProject: Project? = Project(id: 1), lastUpload: Summary?
  var appendToLastUpload = true
  private func updateUploadAvailability() {}
  private func lastUploadDate(_ date: Date) -> String { "Today 12:00" }
` + source.slice(start, end) + String.raw`
  func verify() {
    lastUpload = Summary(projectID: 1, completedAt: Date(), fileCount: 2)
    updateAppendRow()
    precondition(appendSwitch.accessibilityHint == "Last upload: Today 12:00 · 2 files")
    selectedProject = Project(id: 2); lastUpload = nil; appendLookup.loading = true
    updateAppendRow()
    precondition(appendSwitch.accessibilityHint == "Checking the last upload…", "Previous project must not be announced during loading")
    appendLookup.loading = false; appendLookup.failed = true
    updateAppendRow()
    precondition(appendSwitch.accessibilityHint == "Lookup failed. Retry before adding to the last upload.")
    appendLookup.failed = false
    updateAppendRow()
    precondition(appendRow.isHidden && appendSwitch.accessibilityHint == nil)
    lastUpload = Summary(projectID: 2, completedAt: Date(), fileCount: 1)
    updateAppendRow()
    precondition(!appendRow.isHidden && appendSwitch.isOn)
    precondition(appendSwitch.accessibilityHint == "Last upload: Today 12:00 · 1 file")
  }
}
Fixture().verify()
`);
    const executable = path.join(directory, 'verify');
    const compile = spawnSync('xcrun', ['swiftc', '-module-cache-path', path.join(directory, 'cache'), fixture, '-o', executable], { encoding: 'utf8' });
    assert.equal(compile.status, 0, compile.stderr);
    const run = spawnSync(executable, [], { encoding: 'utf8' });
    assert.equal(run.status, 0, run.stderr);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});
