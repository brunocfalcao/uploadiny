const test = require('node:test');
const { before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

// Execute the Foundation-only state and multipart code shipped in the extension.
// UIKit compilation and rendered checks remain separate native verification.
const source = fs.readFileSync(path.join(__dirname, '../native/ShareIntoViewController.swift'), 'utf8');
const start = source.indexOf('private struct SharedFile {');
const end = source.indexOf('private struct DraftResult:', start);
assert.ok(start >= 0 && end > start, 'Native upload types must be available for behavioral tests');
let directory;
let executable;

before(() => {
  if (process.platform !== 'darwin') return;
  directory = fs.mkdtempSync(path.join(os.tmpdir(), 'uploadiny-share-review-'));
  executable = path.join(directory, 'review-tests');
  const fixture = path.join(directory, 'main.swift');
  fs.writeFileSync(fixture, 'import Foundation\n' + source.slice(start, end) + String.raw`
func navigation() {
  var review = ShareReview()
  review.updateComments("No asset yet")
  review.select(1)
  precondition(review.files.isEmpty && review.selectedIndex == 0)
  let url = URL(fileURLWithPath: "/unused")
  review.files = [SharedFile(url: url, name: "screen.png", mime: "image/png"), SharedFile(url: url, name: "recording.mov", mime: "video/quicktime"), SharedFile(url: url, name: "blank.png", mime: "image/png")]
  review.updateComments("  Make the buttons bigger.\nKeep the labels. 👋  ")
  review.select(1)
  precondition(review.files[1].comments == "")
  review.updateComments("At 00:03, fix the tap target.")
  review.select(2)
  review.select(3)
  precondition(review.selectedIndex == 2)
  review.select(-1)
  precondition(review.selectedIndex == 2)
  review.select(0)
  precondition(review.files[0].comments == "  Make the buttons bigger.\nKeep the labels. 👋  ")
  precondition(review.files[1].comments == "At 00:03, fix the tap target.")
  precondition(review.files[2].comments == "")
  review.prepareForUpload()
  precondition(review.files[0].comments == "Make the buttons bigger.\nKeep the labels. 👋")
  precondition(review.files[1].comments == "At 00:03, fix the tap target.")
  precondition(review.files[2].comments == "")
  review.select(1)
  review.updateComments("Revised note after retry")
  precondition(review.files[0].comments == "Make the buttons bigger.\nKeep the labels. 👋")
  precondition(review.files[1].comments == "Revised note after retry")
}

func multipart() throws {
  let directory = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
  try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
  defer { try? FileManager.default.removeItem(at: directory) }
  let input = directory.appendingPathComponent("asset")
  let payload = Data((0..<200000).map { UInt8($0 % 256) })
  try payload.write(to: input)
  let boundary = "Uploadiny-Fixture"
  for note in ["", "Écran trop petit.\nBigger buttons 👋", "At 00:03, fix this"] {
    let file = SharedFile(url: input, name: "bad\"\r\n.png", mime: "image/png", comments: note)
    let bodyURL = directory.appendingPathComponent("body")
    try file.writeMultipart(to: bodyURL, boundary: boundary)
    var expected = Data("--Uploadiny-Fixture\r\nContent-Disposition: form-data; name=\"comments\"\r\n\r\n\(note)\r\n--Uploadiny-Fixture\r\nContent-Disposition: form-data; name=\"file\"; filename=\"bad___.png\"\r\nContent-Type: image/png\r\n\r\n".utf8)
    expected.append(payload)
    expected.append(Data("\r\n--Uploadiny-Fixture--\r\n".utf8))
    let actual = try Data(contentsOf: bodyURL)
    precondition(actual == expected, "Multipart must preserve this asset's exact note and streamed bytes")
  }
}

func acknowledgement() throws {
  let decoder = JSONDecoder()
  let file = SharedFile(url: URL(fileURLWithPath: "/unused"), name: "screen.png", mime: "image/png", comments: "Bigger buttons")
  let saved = try decoder.decode(AppendResult.self, from: Data(#"{"received_images":1,"image":{"id":"one","name":"upload-1.png","comments":"Bigger buttons"}}"#.utf8))
  precondition(saved.confirms(file, receivedCount: 1))
  precondition(!saved.confirms(file, receivedCount: 2))
  let dropped = try decoder.decode(AppendResult.self, from: Data(#"{"received_images":1,"image":{"id":"one","name":"upload-1.png","comments":""}}"#.utf8))
  precondition(!dropped.confirms(file, receivedCount: 1))
  let legacy = try decoder.decode(AppendResult.self, from: Data(#"{"received_images":1}"#.utf8))
  precondition(!legacy.confirms(file, receivedCount: 1))
  let swapped = SharedFile(url: file.url, name: "second.png", mime: "image/png", comments: "Different asset note")
  precondition(!saved.confirms(swapped, receivedCount: 1))
  let blank = SharedFile(url: file.url, name: "blank.png", mime: "image/png")
  precondition(dropped.confirms(blank, receivedCount: 1))
}

switch CommandLine.arguments[1] {
case "navigation": navigation()
case "multipart": try multipart()
case "acknowledgement": try acknowledgement()
default: fatalError("Unknown fixture")
}
`);
  const build = spawnSync('xcrun', ['swiftc', fixture, '-o', executable], { encoding: 'utf8', timeout: 60000 });
  assert.equal(build.status, 0, build.stderr || build.error?.message);
});

after(() => {
  if (directory) fs.rmSync(directory, { recursive: true, force: true });
});

for (const [scenario, description] of [
  ['navigation', 'asset navigation keeps independent notes, blanks and edits through retry'],
  ['multipart', 'each streamed upload carries its UTF-8 remark and safely quoted filename'],
  ['acknowledgement', 'publication requires confirmation of the saved note and expected upload position'],
]) {
  test(description, { skip: process.platform !== 'darwin' }, () => {
    const result = spawnSync(executable, [scenario], { encoding: 'utf8', timeout: 10000 });
    assert.equal(result.status, 0, result.stderr || result.error?.message);
  });
}
