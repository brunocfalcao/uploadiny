const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { transformAppDelegate } = require('../plugins/with-scene-lifecycle');
const original = fs.readFileSync(path.join(__dirname, 'fixtures/AppDelegate.swift'), 'utf8');

test('moves React Native window creation into the connected scene and preserves URL and foreground forwarding', () => {
  assert.ok(original.includes('window = UIWindow(frame: UIScreen.main.bounds)'));
  const transformed = transformAppDelegate(original);
  assert.ok(!transformed.includes('window = UIWindow(frame: UIScreen.main.bounds)'));
  assert.ok(transformed.includes('let window = UIWindow(windowScene: windowScene)'));
  assert.ok(transformed.includes('appDelegate.startReactNative(in: window)'));
  assert.ok(transformed.includes('appDelegate.configureSceneConnectionOptions(connectionOptions)'));
  assert.ok(transformed.includes('sceneLaunchOptions[.url] = urlContext.url'));
  assert.ok(transformed.includes('appDelegate.applicationDidBecomeActive(UIApplication.shared)'));
  assert.ok(transformed.includes('appDelegate.applicationDidEnterBackground(UIApplication.shared)'));
  assert.ok(transformed.includes('openURLContexts URLContexts: Set<UIOpenURLContext>'));
  assert.ok(transformed.includes('Bundle.main.url(forResource: "main", withExtension: "jsbundle")'));
});

test('repeated prebuilds retain exactly one scene delegate and connection bridge', () => {
  const transformed = transformAppDelegate(original);
  assert.equal(transformAppDelegate(transformed), transformed);
  assert.equal(transformed.split('class SceneDelegate:').length - 1, 1);
  assert.equal(transformed.split('func configureSceneConnectionOptions').length - 1, 1);
});

test('fails explicitly when an unsupported Expo template would prevent migration', () => {
  assert.throws(() => transformAppDelegate(original.replace('  var window: UIWindow?', '  var renamedWindow: UIWindow?')), /Uploadiny scene lifecycle plugin/);
});
