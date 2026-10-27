const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

test('Keychain stores preserve accounts, protection, defaults, failures and token-only sign-out', { skip: process.platform !== 'darwin' }, () => {
  const source = fs.readFileSync(path.join(__dirname, '../native/ShareIntoViewController.swift'), 'utf8');
  const helper = source.indexOf('private enum UploadinyKeychainStore {');
  const start = helper >= 0 ? helper : source.indexOf('private enum UploadinyDeviceTokenStore {');
  assert.ok(start >= 0);
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'uploadiny-keychain-stores-'));
  try {
    const fixture = path.join(directory, 'main.swift');
    fs.writeFileSync(fixture, String.raw`
import Foundation
typealias CFString = String
typealias CFDictionary = [String: Any]
typealias CFTypeRef = AnyObject
let kSecClass = "class", kSecClassGenericPassword = "generic", kSecAttrService = "service", kSecAttrAccount = "account"
let kSecReturnData = "return", kSecMatchLimit = "limit", kSecMatchLimitOne = "one"
let kSecAttrAccessible = "accessible", kSecAttrAccessibleWhenUnlockedThisDeviceOnly = "unlocked-device-only", kSecValueData = "data"
let errSecSuccess: Int32 = 0
let errSecItemNotFound: Int32 = -25300
let errSecDuplicateItem: Int32 = -25299
var items: [String: Data] = [:]
var failRead = false, failAdd = false, failUpdate = false, failDelete = false
func account(_ query: CFDictionary) -> String {
  precondition(query[kSecClass] as? String == kSecClassGenericPassword)
  precondition(query[kSecAttrService] as? String == "test.uploadiny.app.share")
  return query[kSecAttrAccount] as! String
}
func SecItemCopyMatching(_ query: CFDictionary, _ result: inout CFTypeRef?) -> Int32 {
  precondition(query[kSecReturnData] as? Bool == true && query[kSecMatchLimit] as? String == kSecMatchLimitOne)
  guard !failRead, let bytes = items[account(query)] else { return -1 }
  result = bytes as NSData
  return errSecSuccess
}
@discardableResult func SecItemDelete(_ query: CFDictionary) -> Int32 {
  if failDelete { return -1 }
  guard items.removeValue(forKey: account(query)) != nil else { return errSecItemNotFound }
  return errSecSuccess
}
@discardableResult func SecItemUpdate(_ query: CFDictionary, _ attributes: CFDictionary) -> Int32 {
  if failUpdate { return -1 }
  let key = account(query)
  guard items[key] != nil else { return errSecItemNotFound }
  items[key] = attributes[kSecValueData] as? Data
  return errSecSuccess
}
func SecItemAdd(_ attributes: CFDictionary, _ result: UnsafeMutableRawPointer?) -> Int32 {
  precondition(attributes[kSecAttrAccessible] as? String == kSecAttrAccessibleWhenUnlockedThisDeviceOnly)
  let key = account(attributes)
  guard !failAdd else { return -1 }
  guard items[key] == nil else { return errSecDuplicateItem }
  items[key] = attributes[kSecValueData] as? Data
  return errSecSuccess
}
` + source.slice(start) + String.raw`
precondition(UploadinyDeviceTokenStore.read() == nil && UploadinyLastProjectStore.read() == nil && UploadinyAppendPreferenceStore.read())
try UploadinyDeviceTokenStore.write("fixture-device-key")
precondition(UploadinyAppendPreferenceStore.write(false))
precondition(UploadinyLastProjectStore.write("fixture-project"))
precondition(UploadinyDeviceTokenStore.read() == "fixture-device-key")
precondition(!UploadinyAppendPreferenceStore.read() && UploadinyLastProjectStore.read() == "fixture-project")
precondition(Set(items.keys) == Set(["uploadiny-device-token", "append-to-last-upload", "last-project-slug"]))
UploadinyDeviceTokenStore.delete()
precondition(UploadinyDeviceTokenStore.read() == nil && !UploadinyAppendPreferenceStore.read() && UploadinyLastProjectStore.read() == "fixture-project")
items["uploadiny-device-token"] = Data(); items["last-project-slug"] = Data(); items["append-to-last-upload"] = Data()
precondition(UploadinyDeviceTokenStore.read() == nil && UploadinyLastProjectStore.read() == nil && UploadinyAppendPreferenceStore.read())
for key in items.keys { items[key] = Data([0xff]) }
precondition(UploadinyDeviceTokenStore.read() == nil && UploadinyLastProjectStore.read() == nil && UploadinyAppendPreferenceStore.read())
failRead = true
precondition(UploadinyDeviceTokenStore.read() == nil && UploadinyLastProjectStore.read() == nil && UploadinyAppendPreferenceStore.read())
failRead = false; failUpdate = true
do { try UploadinyDeviceTokenStore.write("unavailable"); preconditionFailure("Token write must throw") } catch UploadinyDeviceTokenStoreError.unavailable {}
precondition(!UploadinyAppendPreferenceStore.write(true) && !UploadinyLastProjectStore.write("unavailable"))
precondition(UploadinyAppendPreferenceStore.read() && UploadinyLastProjectStore.read() == nil)
failUpdate = false; failAdd = false
try UploadinyDeviceTokenStore.write("fixture-old-key")
precondition(UploadinyAppendPreferenceStore.write(false))
precondition(UploadinyLastProjectStore.write("fixture-project"))
failUpdate = true
do { try UploadinyDeviceTokenStore.write("replacement"); preconditionFailure("Failed replacement must throw") } catch UploadinyDeviceTokenStoreError.unavailable {}
precondition(UploadinyDeviceTokenStore.read() == "fixture-old-key")
precondition(!UploadinyAppendPreferenceStore.write(true) && !UploadinyLastProjectStore.write("replacement-project"))
precondition(!UploadinyAppendPreferenceStore.read() && UploadinyLastProjectStore.read() == "fixture-project")
failUpdate = false
failDelete = true
precondition(!UploadinyDeviceTokenStore.delete())
failDelete = false
precondition(UploadinyDeviceTokenStore.delete())
precondition(UploadinyDeviceTokenStore.delete())
precondition(UploadinyDeviceTokenStore.read() == nil)
`);
    const executable = path.join(directory, 'verify');
    const compile = spawnSync('xcrun', ['swiftc', '-module-cache-path', path.join(directory, 'cache'), fixture, '-o', executable], { encoding: 'utf8' });
    assert.equal(compile.status, 0, compile.stderr);
    const run = spawnSync(executable, [], { encoding: 'utf8' });
    assert.equal(run.status, 0, run.stderr);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});
