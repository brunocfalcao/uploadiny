const {
  withDangerousMod,
  withEntitlementsPlist,
  withXcodeProject,
} = require('expo/config-plugins');
const fs = require('fs');
const path = require('path');
const plist = require('@expo/plist').default;

const extensionBundleIdentifier = 'test.uploadiny.app.share';

function unquote(value) {
  return typeof value === 'string' ? value.replace(/^"|"$/g, '') : value;
}

function withUploadinyExtensionBuildSettings(config) {
  return withXcodeProject(config, (projectConfig) => {
    const configurations = projectConfig.modResults.pbxXCBuildConfigurationSection();

    for (const configuration of Object.values(configurations)) {
      if (!configuration || typeof configuration !== 'object' || !configuration.buildSettings) {
        continue;
      }

      const bundleIdentifier = unquote(configuration.buildSettings.PRODUCT_BUNDLE_IDENTIFIER);

      if (bundleIdentifier === extensionBundleIdentifier) {
        configuration.buildSettings.INFOPLIST_KEY_CFBundleDisplayName = '"Uploadiny"';
      }
    }

    return projectConfig;
  });
}

function withUploadinyExtensionSources(config) {
  return withDangerousMod(config, [
    'ios',
    async (modConfig) => {
      const sourceRoot = path.join(modConfig.modRequest.projectRoot, 'native');
      const targetRoot = path.join(
        modConfig.modRequest.platformProjectRoot,
        'expo-sharing-extension',
      );

      fs.mkdirSync(targetRoot, { recursive: true });

      fs.copyFileSync(
        path.join(sourceRoot, 'ShareIntoViewController.swift'),
        path.join(targetRoot, 'ShareIntoViewController.swift'),
      );
      fs.copyFileSync(
        path.join(sourceRoot, 'ShareExtensionInfo.plist'),
        path.join(targetRoot, 'Info.plist'),
      );

      const extensionEntitlementsPath = path.join(
        targetRoot,
        'expo-sharing-extension.entitlements',
      );

      if (fs.existsSync(extensionEntitlementsPath)) {
        const extensionEntitlements = plist.parse(
          fs.readFileSync(extensionEntitlementsPath, 'utf8'),
        );

        delete extensionEntitlements['com.apple.security.application-groups'];

        fs.writeFileSync(extensionEntitlementsPath, plist.build(extensionEntitlements));
      }

      return modConfig;
    },
  ]);
}

function withoutUnusedAppGroup(config) {
  return withEntitlementsPlist(config, (entitlementsConfig) => {
    delete entitlementsConfig.modResults['com.apple.security.application-groups'];

    return entitlementsConfig;
  });
}

module.exports = function withUploadinyShareExtension(config) {
  return withoutUnusedAppGroup(
    withUploadinyExtensionSources(withUploadinyExtensionBuildSettings(config)),
  );
};
