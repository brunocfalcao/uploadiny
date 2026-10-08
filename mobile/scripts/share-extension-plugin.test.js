const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const plist = require('@expo/plist').default;

function plugin() {
  const mods = {};
  const module = { exports: {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../plugins/with-uploadiny-share-extension.js'), 'utf8'), {
    module, require(name) {
      if (name !== 'expo/config-plugins') return require(name);
      return {
        withXcodeProject(config, callback) { mods.xcode = callback; return config; },
        withEntitlementsPlist(config, callback) { mods.entitlements = callback; return config; },
        withDangerousMod(config, [, callback]) { mods.sources = callback; return config; },
      };
    },
  });
  return { run: module.exports, mods };
}

test('extension identity comes from the manifest and unrelated build settings and entitlements survive', async t => {
  const { run, mods } = plugin();
  run({ plugins: [['expo-sharing', { ios: { extensionBundleIdentifier: 'fixture.app.share' } }]] });
  const configurations = {
    extension: { buildSettings: { PRODUCT_BUNDLE_IDENTIFIER: '"fixture.app.share"', OTHER: 'keep' } },
    app: { buildSettings: { PRODUCT_BUNDLE_IDENTIFIER: 'fixture.app', INFOPLIST_KEY_CFBundleDisplayName: 'keep' } },
    comment: 'keep',
  };
  mods.xcode({ modResults: { pbxXCBuildConfigurationSection: () => configurations } });
  assert.equal(configurations.extension.buildSettings.INFOPLIST_KEY_CFBundleDisplayName, '"Uploadiny"');
  assert.equal(configurations.extension.buildSettings.OTHER, 'keep');
  assert.equal(configurations.app.buildSettings.INFOPLIST_KEY_CFBundleDisplayName, 'keep');
  const entitlement = { modResults: { 'com.apple.security.application-groups': ['unused'], unrelated: true } };
  mods.entitlements(entitlement);
  assert.deepEqual(entitlement.modResults, { unrelated: true });
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'uploadiny-plugin-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  const native = path.join(directory, 'native');
  const ios = path.join(directory, 'ios');
  const target = path.join(ios, 'expo-sharing-extension');
  fs.mkdirSync(native); fs.mkdirSync(target, { recursive: true });
  fs.writeFileSync(path.join(native, 'ShareIntoViewController.swift'), 'fixture source');
  fs.writeFileSync(path.join(native, 'ShareExtensionInfo.plist'), 'fixture plist');
  const entitlements = path.join(target, 'expo-sharing-extension.entitlements');
  fs.writeFileSync(entitlements, plist.build({ 'com.apple.security.application-groups': ['unused'], unrelated: 'keep' }));
  const config = { modRequest: { projectRoot: directory, platformProjectRoot: ios } };
  await mods.sources(config); await mods.sources(config);
  assert.equal(fs.readFileSync(path.join(target, 'ShareIntoViewController.swift'), 'utf8'), 'fixture source');
  assert.equal(fs.readFileSync(path.join(target, 'Info.plist'), 'utf8'), 'fixture plist');
  assert.deepEqual({ ...plist.parse(fs.readFileSync(entitlements, 'utf8')) }, { unrelated: 'keep' });
});

test('missing configured extension identity fails explicitly', () => {
  assert.throws(() => plugin().run({ plugins: [] }), /bundle identifier/);
});
