const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const plist = require('@expo/plist').default;

const mobileRoot = path.join(__dirname, '..');
const extensionPlist = plist.parse(fs.readFileSync(path.join(mobileRoot, 'native/ShareExtensionInfo.plist'), 'utf8'));
const manifest = JSON.parse(fs.readFileSync(path.join(mobileRoot, 'app.json'), 'utf8')).expo;
const swift = fs.readFileSync(process.env.UPLOADINY_NATIVE_SOURCE || path.join(mobileRoot, 'native/ShareIntoViewController.swift'), 'utf8');
const rule = extensionPlist.NSExtension.NSExtensionAttributes.NSExtensionActivationRule;

function probeHostUTIs() {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'uploadiny-uti-host-'));
  try {
    const fixture = path.join(directory, 'main.swift');
    fs.writeFileSync(fixture, String.raw`
import Foundation
import UniformTypeIdentifiers
let checks = [("public.png", "public.image"), ("com.apple.quicktime-movie", "public.movie"), ("public.ndjson", "public.ndjson")]
for (identifier, parent) in checks {
  guard let type = UTType(identifier), let expected = UTType(parent), type.conforms(to: expected) else {
    FileHandle.standardError.write(Data("host UTI unavailable: \(identifier) -> \(parent)\n".utf8))
    exit(2)
  }
}
`);
    const executable = path.join(directory, 'verify');
    const compile = spawnSync('xcrun', ['swiftc', '-module-cache-path', path.join(directory, 'cache'), fixture, '-o', executable], { encoding: 'utf8', timeout: 60000 });
    if (compile.status !== 0) return compile;
    return spawnSync(executable, [], { encoding: 'utf8', timeout: 10000 });
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
}

test('the share sheet rule names images, movies and App Privacy Reports and nothing broader', () => {
  assert.equal(typeof rule, 'string');
  const types = [...rule.matchAll(/UTI-CONFORMS-TO "([^"]+)"/g)].map(match => match[1]);
  assert.deepEqual(types, ['public.image', 'public.movie', 'public.ndjson']);
  assert.doesNotMatch(rule, /TRUEPREDICATE|public\.(data|item|content|text|plain-text|json|file-url)"/);
  const sharing = manifest.plugins.find(entry => Array.isArray(entry) && entry[0] === 'expo-sharing');
  assert.equal(sharing[1].ios.activationRule, rule, 'expo-sharing and the copied extension plist must declare the same rule');
});

test('the containing app imports the ndjson type for systems that do not declare it', () => {
  const declarations = manifest.ios.infoPlist.UTImportedTypeDeclarations;
  assert.equal(declarations.length, 1);
  assert.deepEqual(declarations[0], {
    UTTypeIdentifier: 'public.ndjson',
    UTTypeDescription: 'NDJSON',
    UTTypeConformsTo: ['public.text'],
    UTTypeTagSpecification: { 'public.filename-extension': ['ndjson'], 'public.mime-type': ['application/x-ndjson'] },
  });
});

test('the native host UTI registry is checked separately from activation predicate behavior', { skip: process.platform !== 'darwin' }, (t) => {
  const result = probeHostUTIs();
  if (result.status === 2) {
    t.skip(`${result.stderr.trim()}; iOS target validation remains required`);
    return;
  }
  assert.equal(result.status, 0, result.stderr || result.error?.message);
});

test('the activation rule accepts reports and supported mixes and refuses other files', { skip: process.platform !== 'darwin' }, (t) => {
  const host = probeHostUTIs();
  if (host.status === 2) {
    t.skip(`${host.stderr.trim()}; iOS target validation remains required`);
    return;
  }
  assert.equal(host.status, 0, host.stderr || host.error?.message);
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'uploadiny-activation-'));
  try {
    const fixture = path.join(directory, 'main.swift');
    fs.writeFileSync(fixture, String.raw`
import Foundation
let predicate = NSPredicate(format: CommandLine.arguments[1])
func share(_ items: [[[String]]]) -> NSDictionary {
  ["extensionItems": items.map { ["attachments": $0.map { ["registeredTypeIdentifiers": $0] }] }] as NSDictionary
}
let cases: [(String, [[[String]]], Bool)] = [
  ("report", [[["public.ndjson"]]], true),
  ("report with file URL", [[["public.ndjson", "public.file-url"]]], true),
  ("screenshot", [[["public.png"]]], true),
  ("photo", [[["public.heic", "public.jpeg"]]], true),
  ("recording", [[["com.apple.quicktime-movie"]]], true),
  ("screenshot and report", [[["public.png"], ["public.ndjson"]]], true),
  ("separate items", [[["public.png"]], [["public.ndjson"]]], true),
  ("pdf", [[["com.adobe.pdf"]]], false),
  ("json", [[["public.json"]]], false),
  ("plain text", [[["public.plain-text"]]], false),
  ("web link", [[["public.url"]]], false),
  ("screenshot and pdf", [[["public.png"], ["com.adobe.pdf"]]], false),
  ("attachment without types", [[[]]], false),
  ("no items", [], false),
  ("1001 screenshots", [Array(repeating: ["public.png"], count: 1001)], false),
]
for (name, items, expected) in cases where predicate.evaluate(with: share(items)) != expected {
  FileHandle.standardError.write(Data("\(name) expected \(expected)\n".utf8))
  exit(1)
}
`);
    const executable = path.join(directory, 'verify');
    const compile = spawnSync('xcrun', ['swiftc', '-module-cache-path', path.join(directory, 'cache'), fixture, '-o', executable], { encoding: 'utf8', timeout: 60000 });
    assert.equal(compile.status, 0, compile.stderr);
    const run = spawnSync(executable, [rule], { encoding: 'utf8', timeout: 10000 });
    assert.equal(run.status, 0, run.stderr);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('reports load after movies and images, keep their .ndjson name and upload as ndjson text', () => {
  const prepare = swift.slice(swift.indexOf('  private func prepareProvider(at index: Int) {'), swift.indexOf('  private func loadRepresentation('));
  assert.match(prepare, /ShareMediaSupport\.preferredRepresentations\(from: registered\)/);
  assert.match(prepare, /guard !identifiers\.isEmpty else \{\s*showError\("Uploadiny accepts JPG, PNG, GIF, WEBP, BMP, MP4, MOV, M4V and App Privacy Reports\.[^"]*", allowRetry: false\)\s*return/);
  const load = swift.slice(swift.indexOf('  private func loadRepresentation('), swift.indexOf('  private func uploadChunk()'));
  assert.match(load, /let report = SharePrivacyReport\.isType\(identifier\)/);
  assert.match(load, /if report, URL\(fileURLWithPath: fileName\)\.pathExtension\.lowercased\(\) != SharePrivacyReport\.fileExtension \{ fileName \+= /);
  assert.ok(load.indexOf('let report = ') < load.indexOf('let copyURL'), 'The name is corrected before the owned copy is created');
  assert.match(load, /let mime = report \? SharePrivacyReport\.mime :/);
  assert.match(swift, /static let typeIdentifier = "public\.ndjson"/);
  assert.match(swift, /static let mime = "application\/x-ndjson"/);
  assert.match(swift, /if let ndjson = UTType\((?:SharePrivacyReport\.)?typeIdentifier\), type\.conforms\(to: ndjson\) \{ return true \}/);
});

test('unsupported provider representations are rejected before a draft while supported alternatives win', { skip: process.platform !== 'darwin' }, (t) => {
  const host = probeHostUTIs();
  if (host.status === 2) {
    t.skip(`${host.stderr.trim()}; iOS target validation remains required`);
    return;
  }
  assert.equal(host.status, 0, host.stderr || host.error?.message);
  const start = swift.indexOf('private enum ShareMediaSupport {');
  const privacyStart = swift.indexOf('private enum SharePrivacyReport {');
  const privacyEnd = swift.indexOf('\nprivate struct ShareReview', privacyStart);
  const end = privacyStart;
  assert.ok(start >= 0 && privacyStart > start && privacyEnd > privacyStart, 'Media support policy must be isolated for Foundation tests');
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'uploadiny-media-support-'));
  try {
    const fixture = path.join(directory, 'main.swift');
    fs.writeFileSync(fixture, 'import Foundation\nimport UniformTypeIdentifiers\n' + swift.slice(start, end) + swift.slice(privacyStart, privacyEnd) + String.raw`
precondition(ShareMediaSupport.preferredRepresentations(from: ["public.avif"]).isEmpty)
precondition(ShareMediaSupport.preferredRepresentations(from: ["public.avif", "public.png"]) == ["public.png"])
precondition(ShareMediaSupport.preferredRepresentations(from: ["public.movie", "public.mpeg-4"]) == ["public.mpeg-4"])
precondition(ShareMediaSupport.preferredRepresentations(from: ["public.image"]) == ["public.image"])
precondition(ShareMediaSupport.preferredRepresentations(from: ["public.movie"]) == ["public.movie"])
precondition(ShareMediaSupport.preferredRepresentations(from: ["public.ndjson"]) == ["public.ndjson"])
`);
    const executable = path.join(directory, 'verify');
    const compile = spawnSync('xcrun', ['swiftc', '-module-cache-path', path.join(directory, 'cache'), fixture, '-o', executable], { encoding: 'utf8', timeout: 60000 });
    assert.equal(compile.status, 0, compile.stderr);
    const run = spawnSync(executable, [], { encoding: 'utf8', timeout: 10000 });
    assert.equal(run.status, 0, run.stderr);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('generic movie resolution never trusts an image filename', () => {
  const start = swift.indexOf('  private func resolvePreparedType(');
  const end = swift.indexOf('\n  private func imagePreview(', start);
  const resolve = swift.slice(start, end);
  assert.match(resolve, /if registered\?\.conforms\(to: \.movie\) == true \{[\s\S]*extensionType\.flatMap \{ type in[\s\S]*guard type\.conforms\(to: \.movie\) else/);
});

test('report review shows a document preview with size and entries, never the recording or image paths', () => {
  const preview = swift.slice(swift.indexOf('  private func loadAssetPreview('), swift.indexOf('  private func saveCurrentFeedback()'));
  assert.ok(preview.indexOf('if file.isPrivacyReport {') < preview.indexOf('} else if file.isRecording {'));
  assert.match(preview, /SharePrivacyReport\.entryCount\(at: file\.url\)/);
  assert.match(preview, /self\.previewGeneration == generation else \{ return \}\s*self\.displayReportPreview/);
  assert.match(preview, /UIImage\(systemName: "doc\.text"/);
  assert.match(swift, /mediaLabel\.text = file\.isRecording \? "Recording" : file\.isPrivacyReport \? "Privacy report" : "Screenshot"/);
  assert.match(swift, /playButton\.isHidden = !file\.isRecording/);
  assert.match(swift, /if file\.isStillImage \{\s*DispatchQueue\.global/);
  assert.match(swift, /\$0\.isPrivacyReport \? nil : self\.imagePreview\(\$0\.url\)/);
});


test('generic movie filenames retain movie metadata and reject image extensions in the native resolver', { skip: process.platform !== 'darwin' }, t => {
  const host = probeHostUTIs();
  if (host.status === 2) { t.skip(host.stderr.trim()); return; }
  assert.equal(host.status, 0, host.stderr || host.error?.message);
  const method = swift.slice(swift.indexOf('  private func resolvePreparedType('), swift.indexOf('  private func imagePreview(')).replace('private func', 'func');
  const support = swift.slice(swift.indexOf('private enum ShareMediaSupport {'), swift.indexOf('private enum SharePrivacyReport {'));
  const reportStart = swift.indexOf('private enum SharePrivacyReport {');
  const reports = swift.slice(reportStart, swift.indexOf('\nprivate struct ShareReview', reportStart));
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'uploadiny-movie-family-'));
  try {
    const fixture = path.join(directory, 'main.swift');
    fs.writeFileSync(fixture, 'import Foundation\nimport UniformTypeIdentifiers\nimport ImageIO\n' + support + reports + '\nstruct Resolver { enum UploadError: Error { case unsupportedRepresentation }\n' + method + '\n}\n' + String.raw`
let resolver = Resolver()
let url = URL(fileURLWithPath: "/unused-provider-movie")
for original in ["clip.mov", "clip.mp4", "clip.m4v"] {
  var name = original
  let result = try resolver.resolvePreparedType(identifier: "public.movie", fileURL: url, fileName: &name)
  precondition(result?.conforms(to: .movie) == true)
}
var misleading = "clip.jpg"
do {
  _ = try resolver.resolvePreparedType(identifier: "public.movie", fileURL: url, fileName: &misleading)
  fatalError("A movie must never acquire image metadata")
} catch Resolver.UploadError.unsupportedRepresentation { }
precondition(misleading == "clip.jpg")
`);
    const executable = path.join(directory, 'verify');
    const compile = spawnSync('xcrun', ['swiftc', '-module-cache-path', path.join(directory, 'cache'), fixture, '-o', executable], { encoding: 'utf8', timeout: 60000 });
    assert.equal(compile.status, 0, compile.stderr || compile.error?.message);
    const run = spawnSync(executable, [], { encoding: 'utf8', timeout: 10000 });
    assert.equal(run.status, 0, run.stderr || run.error?.message);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});
