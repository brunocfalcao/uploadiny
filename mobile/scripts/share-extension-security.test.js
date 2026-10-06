const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const mobileRoot = path.join(__dirname, '..');
const swift = fs.readFileSync(path.join(mobileRoot, 'native/ShareIntoViewController.swift'), 'utf8');
const plist = fs.readFileSync(path.join(mobileRoot, 'native/ShareExtensionInfo.plist'), 'utf8');
const deploy = fs.readFileSync(path.join(__dirname, 'deploy-iphone.sh'), 'utf8');

test('share extension persists only the limited device token in a ThisDeviceOnly Keychain item', () => {
  assert.match(swift, /SecItemAdd\(/);
  assert.match(swift, /kSecAttrAccessibleWhenUnlockedThisDeviceOnly/);
  assert.match(swift, /UploadinyDeviceTokenStore\.write\(result\.token\)/);
  assert.match(swift, /UploadinyDeviceTokenStore\.delete\(\)/);
  assert.doesNotMatch(swift, /UserDefaults|NSLog|print\(/);
});

test('the "Add to the last upload" choice lives in its own ThisDeviceOnly Keychain item and never holds the token', () => {
  const start = swift.indexOf('private enum UploadinyAppendPreferenceStore {');
  assert.ok(start >= 0, 'The append preference must be stored by its dedicated Keychain store');
  const store = swift.slice(start, swift.indexOf('\n}\n', start));
  assert.match(store, /account = "append-to-last-upload"/);
  assert.match(store, /attributes\[kSecAttrAccessible\] = kSecAttrAccessibleWhenUnlockedThisDeviceOnly/);
  assert.match(store, /kSecValueData\] = Data\(\(enabled \? "1" : "0"\)\.utf8\)/);
  assert.doesNotMatch(store, /kSecAttrSynchronizable|AfterFirstUnlock|AccessibleAlways|uploadiny-device-token|\btoken\b/);
  assert.match(swift, /UploadinyAppendPreferenceStore\.write\(appendSwitch\.isOn\)/);
  assert.equal(swift.match(/UploadinyDeviceTokenStore\.write\(/g).length, 1);
});

test('the last project slug lives in its own ThisDeviceOnly Keychain item, never holds the token, and is written only after a confirmed upload', () => {
  const start = swift.indexOf('private enum UploadinyLastProjectStore {');
  assert.ok(start >= 0, 'The last project must be stored by its dedicated Keychain store');
  const store = swift.slice(start, swift.indexOf('\n}\n', start));
  assert.match(store, /account = "last-project-slug"/);
  assert.match(store, /service = UploadinyDeviceTokenStore\.service/);
  assert.match(store, /attributes\[kSecAttrAccessible\] = kSecAttrAccessibleWhenUnlockedThisDeviceOnly/);
  assert.match(store, /kSecValueData\] = Data\(slug\.utf8\)/);
  assert.doesNotMatch(store, /kSecAttrSynchronizable|AfterFirstUnlock|AccessibleAlways|uploadiny-device-token|\btoken\b/);
  // Exactly one write site, inside completeChunk after the server confirmed every file.
  assert.equal(swift.match(/UploadinyLastProjectStore\.write\(/g).length, 1);
  const complete = swift.slice(swift.indexOf('private func completeChunk()'), swift.indexOf('private func serverError('));
  const confirmed = complete.indexOf('self.appendingTo != nil || result.images.count == self.review.files.count');
  const write = complete.indexOf('UploadinyLastProjectStore.write(');
  assert.ok(confirmed >= 0 && write > confirmed, 'The slug is written only after the upload is confirmed');
  assert.doesNotMatch(swift.slice(swift.indexOf('didSelectRowAt'), swift.indexOf('private func beginUpload')), /UploadinyLastProjectStore\.write/);
});

test('the remembered project is preselected through the normal selection path and can still be changed', () => {
  assert.match(swift, /UploadinyLastProjectStore\.read\(\)/);
  assert.match(swift, /self\.projects\.first\(where: \{ \$0\.slug == slug \}\)/);
  assert.match(swift, /selectProject\(projects\[indexPath\.row\], remembered: false\)/);
  assert.match(swift, /self\.selectProject\(project, remembered: self\.selectedProject == nil\)/);
  assert.match(swift, /changeProjectButton\.addTarget\(self, action: #selector\(changeProject\)/);
  assert.match(swift, /Tap to change/);
});

test('share extension accepts only the production HTTPS API and never follows credential redirects', () => {
  assert.match(swift, /base\.scheme == "https"/);
  assert.match(swift, /base\.host == "uploadiny\.com"/);
  assert.match(swift, /willPerformHTTPRedirection/);
  assert.match(swift, /completionHandler\(nil\)/);
  assert.match(swift, /configuration\.urlCache = nil/);
  assert.match(swift, /configuration\.requestCachePolicy = \.reloadIgnoringLocalCacheData/);
});

test('the signed extension receives no embedded credential or build-time credential injection', () => {
  assert.doesNotMatch(plist, /<key>UploadinyUploadToken<\/key>|UPLOADINY_UPLOAD_TOKEN/);
  assert.doesNotMatch(swift, /UploadinyUploadToken|UPLOADINY_UPLOAD_TOKEN/);
  assert.doesNotMatch(deploy, /UPLOADINY_UPLOAD_TOKEN/);
  assert.match(deploy, /plutil -extract UploadinyUploadToken raw/);
  assert.match(deploy, /https:\/\/uploadiny\.com\/api/);
});

test('only a confirmed upload closes the share sheet automatically; errors stay on screen', () => {
  const swift = fs.readFileSync(path.join(__dirname, '../native/ShareIntoViewController.swift'), 'utf8');
  const calls = swift.match(/self\.startAutoClose\(summary:/g) || [];
  assert.equal(calls.length, 1);
  const success = swift.indexOf('self.canDismiss = true');
  assert.ok(success > 0 && swift.indexOf('self.startAutoClose(summary:') > success);
  const start = swift.indexOf('private func showError');
  const showError = swift.slice(start, swift.indexOf('\n  private func ', start + 1) > 0 ? swift.indexOf('\n  private func ', start + 1) : undefined);
  assert.ok(showError.length > 100);
  assert.doesNotMatch(showError, /startAutoClose|canDismiss = true/);
  assert.match(swift, /autoCloseTimer\?\.invalidate\(\)[\s\S]{0,80}didClose = true/);
});
