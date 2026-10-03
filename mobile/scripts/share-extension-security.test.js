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
