const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const source = fs.readFileSync(path.join(__dirname, '../native/ShareIntoViewController.swift'), 'utf8');
function method(name, next) {
  const start = source.indexOf(name);
  assert.ok(start >= 0, `${name} must exist`);
  return source.slice(start, source.indexOf(next, start + name.length)).replaceAll('@objc ', '').replaceAll('private func ', 'func ');
}
test('preparation retry resumes the failed provider, preserves notes and never starts a partial upload', { skip: process.platform !== 'darwin' }, () => {
  const primary = method('  @objc private func primaryButtonTapped()', '  @objc private func beginUpload()');
  const prepare = source.includes('  private func prepareRemainingFiles()') ? method('  private func prepareRemainingFiles()', '  @objc private func primaryButtonTapped()') : '';
  const error = method('  private func showError(', '  private func cleanupFiles()');
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'uploadiny-preparation-retry-'));
  try {
    const fixture = path.join(directory, 'main.swift');
    fs.writeFileSync(fixture, String.raw`
import Foundation
struct Configuration { var title: String? }
final class View {
  var isHidden = false; var text: String?; var isEnabled = false; var alpha = 1.0
  var configuration: Configuration? = Configuration()
  func stopAnimating() {}; func startAnimating() {}; func cancelAllCGImageGeneration() {}
}
struct File { var comments: String }
struct Review { var files: [File] = [] }
final class Controller {
  var retryProjectLoading = false; var retryPreparation = false; var didClose = false
  var isPreparing = false; var previewGeneration = 0; var review = Review(); var providers = [0, 1, 2]
  let spinner = View(), uploadProgress = View(), table = View(), reviewPanel = View(), changeProjectButton = View()
  let uploadButton = View(), footerSummary = View(), detailLabel = View(), titleLabel = View()
  var previewGenerator: View? = View(); var starts: [Int] = []; var uploads = 0
  func loadProjects() {}; func showAsset() {}; func cancelDraft(_ done: () -> Void) { done() }
  func beginUpload() { uploads += 1 }
  func prepareProvider(at index: Int) { starts.append(index) }
  func primaryButtonConfiguration(title: String, symbol: String) -> Configuration { Configuration(title: title) }
` + prepare + primary + error + String.raw`
}
func drain() { RunLoop.main.run(until: Date().addingTimeInterval(0.02)) }
for prepared in [0, 1, 2] {
  let controller = Controller()
  controller.review.files = Array(repeating: File(comments: "retained note"), count: prepared)
  controller.isPreparing = true
  controller.showError("Temporary read failure")
  drain()
  precondition(!controller.uploadButton.isHidden, "A preparation failure must offer retry")
  precondition(controller.uploadButton.configuration?.title == "Try preparing again")
  controller.primaryButtonTapped()
  precondition(controller.starts == [prepared], "Retry must resume only the failed attachment")
  precondition(controller.uploads == 0 && controller.isPreparing)
  precondition(controller.review.files.count == prepared && controller.review.files.allSatisfy { $0.comments == "retained note" })
  controller.showError("Fails again")
  drain()
  controller.primaryButtonTapped()
  precondition(controller.starts == [prepared, prepared] && controller.uploads == 0)
}
let unsupported = Controller(); unsupported.isPreparing = true
unsupported.showError("Unsupported", allowRetry: false); drain()
precondition(unsupported.uploadButton.isHidden && !unsupported.retryPreparation)
let completed = Controller(); completed.review.files = Array(repeating: File(comments: "saved"), count: 3)
completed.showError("Upload failed"); drain(); completed.primaryButtonTapped()
precondition(completed.uploads == 1 && completed.starts.isEmpty)
let closed = Controller(); closed.didClose = true; closed.isPreparing = true
closed.showError("Late failure"); drain()
precondition(!closed.retryPreparation)
`);
    const executable = path.join(directory, 'verify');
    const build = spawnSync('xcrun', ['swiftc', '-module-cache-path', path.join(directory, 'cache'), fixture, '-o', executable], { encoding: 'utf8', timeout: 60000 });
    assert.equal(build.status, 0, build.stderr || build.error?.message);
    const run = spawnSync(executable, [], { encoding: 'utf8', timeout: 10000 });
    assert.equal(run.status, 0, run.stderr || run.error?.message);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});
