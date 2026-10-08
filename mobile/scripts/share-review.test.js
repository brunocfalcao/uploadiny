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
const start = source.indexOf('private struct ShareAppendDecision {');
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
func appendDecision() {
  var lookup = ShareAppendDecision()
  precondition(!lookup.canUpload)
  lookup.begin(projectID: 1, generation: 1)
  precondition(lookup.loading && !lookup.canUpload)
  lookup.resolve(projectID: 2, generation: 1, success: true)
  precondition(lookup.loading && !lookup.canUpload)
  lookup.resolve(projectID: 1, generation: 1, success: false)
  precondition(lookup.failed && !lookup.canUpload)
  lookup.begin(projectID: 1, generation: 2)
  lookup.resolve(projectID: 1, generation: 1, success: true)
  precondition(lookup.loading && !lookup.canUpload)
  lookup.resolve(projectID: 1, generation: 2, success: true)
  precondition(lookup.canUpload && !lookup.failed)
}

func backgroundStaging() throws {
  let input = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
  defer { try? FileManager.default.removeItem(at: input) }
  try Data(repeating: 42, count: 4 * 1024 * 1024).write(to: input)
  let coordinator = ShareUploadCoordinator()
  var finished = false
  coordinator.stage(file: SharedFile(url: input, name: "recording.mov", mime: "video/quicktime", comments: "exact note"), boundary: "fixture") { result in
    precondition(Thread.isMainThread)
    guard case .success(let url) = result else { fatalError("Staging failed") }
    defer { try? FileManager.default.removeItem(at: url) }
    let bytes = try! Data(contentsOf: url)
    precondition(bytes.count > 4 * 1024 * 1024)
    precondition(String(decoding: bytes.prefix(180), as: UTF8.self).contains("exact note"))
    finished = true
  }
  let deadline = Date().addingTimeInterval(10)
  while !finished && Date() < deadline { RunLoop.current.run(until: Date().addingTimeInterval(0.01)) }
  precondition(finished)
  coordinator.cancel()
}

final class FixtureProtocol: URLProtocol {
  static var mode = "ok"
  static var requests = 0
  override class func canInit(with request: URLRequest) -> Bool { true }
  override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }
  override func startLoading() {
    FixtureProtocol.requests += 1
    if FixtureProtocol.mode == "hold" { return }
    if FixtureProtocol.mode == "lost" {
      client?.urlProtocol(self, didFailWithError: URLError(.networkConnectionLost))
      return
    }
    let response = HTTPURLResponse(url: request.url!, statusCode: FixtureProtocol.mode == "401" ? 401 : 201, httpVersion: "HTTP/1.1", headerFields: ["Content-Type": "application/json"])!
    client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
    client?.urlProtocol(self, didLoad: Data(#"{"received_images":1,"image":{"id":"one","name":"one.png","comments":"exact note"}}"#.utf8))
    client?.urlProtocolDidFinishLoading(self)
  }
  override func stopLoading() {}
}

final class FixtureDelegate: NSObject, URLSessionDelegate {
  private let lock = NSLock()
  private var count = 0
  var invalidations: Int { lock.lock(); defer { lock.unlock() }; return count }
  func urlSession(_ session: URLSession, didBecomeInvalidWithError error: Error?) { lock.lock(); count += 1; lock.unlock() }
}

func waitFor(_ predicate: () -> Bool) {
  let deadline = Date().addingTimeInterval(3)
  while !predicate() && Date() < deadline { RunLoop.current.run(until: Date().addingTimeInterval(0.01)) }
  precondition(predicate(), "Native asynchronous boundary did not settle")
}

func sessionRecovery() throws {
  let coordinator = ShareUploadCoordinator(configuration: {
    let configuration = URLSessionConfiguration.ephemeral
    configuration.protocolClasses = [FixtureProtocol.self]
    return configuration
  })
  let input = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
  defer { try? FileManager.default.removeItem(at: input) }
  try Data("original for sign-in retry".utf8).write(to: input)
  var review = ShareReview()
  review.files = [SharedFile(url: input, name: "one.png", mime: "image/png", comments: "exact note"), SharedFile(url: input, name: "two.png", mime: "image/png")]
  for mode in ["ok", "401", "lost", "hold"] {
    FixtureProtocol.mode = mode
    FixtureProtocol.requests = 0
    let delegate = FixtureDelegate()
    let session = coordinator.session(delegate: delegate, requestTimeout: 30, resourceTimeout: 30)
    precondition(session.configuration.urlCache == nil && session.configuration.timeoutIntervalForRequest == 30)
    var finished = false
    var request = URLRequest(url: URL(string: "https://fixture.invalid/chunks/draft/complete")!)
    request.httpMethod = "POST"
    let task = coordinator.dataTask(session: session, with: request) { data, response, error in
      DispatchQueue.main.async {
        if mode == "ok" { precondition((response as? HTTPURLResponse)?.statusCode == 201 && data != nil && error == nil) }
        if mode == "401" { precondition((response as? HTTPURLResponse)?.statusCode == 401) }
        if mode == "lost" { precondition((error as? URLError)?.code == .networkConnectionLost) }
        if mode == "hold" { precondition((error as? URLError)?.code == .cancelled) }
        finished = true
      }
    }
    task.resume()
    if mode == "hold" { waitFor { FixtureProtocol.requests == 1 }; coordinator.cancel() }
    waitFor { finished && delegate.invalidations == 1 }
    precondition(FixtureProtocol.requests == 1, "A failed completion must not silently retry")
    let retained = try Data(contentsOf: input)
    precondition(retained == Data("original for sign-in retry".utf8))
    precondition(review.files[0].comments == "exact note" && review.files[1].comments.isEmpty)
  }
  FixtureProtocol.mode = "ok"
  let delegate = FixtureDelegate()
  let session = coordinator.session(delegate: delegate, requestTimeout: 600, resourceTimeout: 600)
  let body = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
  defer { try? FileManager.default.removeItem(at: body) }
  try review.files[0].writeMultipart(to: body, boundary: "fixture")
  var finished = false
  var request = URLRequest(url: URL(string: "https://fixture.invalid/chunks/draft/images")!)
  request.httpMethod = "POST"
  request.setValue("multipart/form-data; boundary=fixture", forHTTPHeaderField: "Content-Type")
  coordinator.uploadTask(session: session, with: request, fromFile: body) { data, response, error in
    DispatchQueue.main.async {
      precondition(error == nil && (response as? HTTPURLResponse)?.statusCode == 201)
      let receipt = try! JSONDecoder().decode(AppendResult.self, from: data!)
      precondition(receipt.confirms(review.files[0], receivedCount: 1))
      finished = true
    }
  }.resume()
  waitFor { finished && delegate.invalidations == 1 }
  precondition(session.configuration.timeoutIntervalForResource == 600)
  coordinator.cancel()
}

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
  let cancelledBody = directory.appendingPathComponent("cancelled-body")
  var probes = 0
  do {
    try SharedFile(url: input, name: "recording.mov", mime: "video/quicktime").writeMultipart(to: cancelledBody, boundary: boundary, cancelled: { probes += 1; return probes > 2 })
    fatalError("Cancelled staging must stop before copying the whole file")
  } catch let error as URLError { precondition(error.code == .cancelled) }
  let partial = try Data(contentsOf: cancelledBody)
  precondition(partial.count < payload.count)
  let original = try Data(contentsOf: input)
  precondition(original == payload)
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
case "appendDecision": appendDecision()
case "staging": try backgroundStaging()
case "sessions": try sessionRecovery()
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
  ['appendDecision', 'append waits for lookup, rejects failure and ignores stale project replies'],
  ['sessions', 'native requests invalidate sessions after success, 401, lost completion and cancellation while retaining retry assets'],
  ['staging', 'multipart staging returns exact media and feedback on the main queue'],
  ['navigation', 'asset navigation keeps independent notes, blanks and edits through retry'],
  ['multipart', 'each streamed upload carries its UTF-8 remark and safely quoted filename'],
  ['acknowledgement', 'publication requires confirmation of the saved note and expected upload position'],
]) {
  test(description, { skip: process.platform !== 'darwin' }, () => {
    const result = spawnSync(executable, [scenario], { encoding: 'utf8', timeout: 10000 });
    assert.equal(result.status, 0, result.stderr || result.error?.message);
  });
}
