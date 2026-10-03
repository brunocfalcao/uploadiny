const { withAppDelegate, withInfoPlist } = require('@expo/config-plugins');

const sceneManifest = {
  UIApplicationSupportsMultipleScenes: false,
  UISceneConfigurations: {
    UIWindowSceneSessionRoleApplication: [
      {
        UISceneConfigurationName: 'Default Configuration',
        UISceneDelegateClassName: '$(PRODUCT_MODULE_NAME).SceneDelegate',
      },
    ],
  },
};

const legacySceneDelegate = `class SceneDelegate: UIResponder, UIWindowSceneDelegate {
  var window: UIWindow?

  func scene(
    _ scene: UIScene,
    willConnectTo session: UISceneSession,
    options connectionOptions: UIScene.ConnectionOptions
  ) {
    guard let windowScene = scene as? UIWindowScene,
          let appDelegate = UIApplication.shared.delegate as? AppDelegate else {
      return
    }

    let window = UIWindow(windowScene: windowScene)
    self.window = window
    appDelegate.startReactNative(in: window)
  }
}`;

const sceneDelegate = `class SceneDelegate: UIResponder, UIWindowSceneDelegate {
  var window: UIWindow?

  func scene(
    _ scene: UIScene,
    willConnectTo session: UISceneSession,
    options connectionOptions: UIScene.ConnectionOptions
  ) {
    guard let windowScene = scene as? UIWindowScene,
          let appDelegate = UIApplication.shared.delegate as? AppDelegate else {
      return
    }

    appDelegate.configureSceneConnectionOptions(connectionOptions)

    let window = UIWindow(windowScene: windowScene)
    self.window = window
    appDelegate.startReactNative(in: window)
  }

  func scene(_ scene: UIScene, openURLContexts URLContexts: Set<UIOpenURLContext>) {
    guard let appDelegate = UIApplication.shared.delegate as? AppDelegate else {
      return
    }

    for urlContext in URLContexts {
      _ = appDelegate.application(UIApplication.shared, open: urlContext.url, options: urlOptions(from: urlContext))
    }
  }

  func scene(_ scene: UIScene, willContinueUserActivityWithType userActivityType: String) {
    guard let appDelegate = UIApplication.shared.delegate as? AppDelegate else {
      return
    }

    _ = appDelegate.application(UIApplication.shared, willContinueUserActivityWithType: userActivityType)
  }

  func scene(_ scene: UIScene, continue userActivity: NSUserActivity) {
    guard let appDelegate = UIApplication.shared.delegate as? AppDelegate else {
      return
    }

    _ = appDelegate.application(UIApplication.shared, continue: userActivity, restorationHandler: { _ in })
  }

  func scene(_ scene: UIScene, didUpdate userActivity: NSUserActivity) {
    guard let appDelegate = UIApplication.shared.delegate as? AppDelegate else {
      return
    }

    appDelegate.application(UIApplication.shared, didUpdate: userActivity)
  }

  func scene(_ scene: UIScene, didFailToContinueUserActivityWithType userActivityType: String, error: Error) {
    guard let appDelegate = UIApplication.shared.delegate as? AppDelegate else {
      return
    }

    appDelegate.application(UIApplication.shared, didFailToContinueUserActivityWithType: userActivityType, error: error)
  }

  func sceneDidBecomeActive(_ scene: UIScene) {
    guard let appDelegate = UIApplication.shared.delegate as? AppDelegate else {
      return
    }

    appDelegate.applicationDidBecomeActive(UIApplication.shared)
  }

  func sceneWillResignActive(_ scene: UIScene) {
    guard let appDelegate = UIApplication.shared.delegate as? AppDelegate else {
      return
    }

    appDelegate.applicationWillResignActive(UIApplication.shared)
  }

  func sceneWillEnterForeground(_ scene: UIScene) {
    guard let appDelegate = UIApplication.shared.delegate as? AppDelegate else {
      return
    }

    appDelegate.applicationWillEnterForeground(UIApplication.shared)
  }

  func sceneDidEnterBackground(_ scene: UIScene) {
    guard let appDelegate = UIApplication.shared.delegate as? AppDelegate else {
      return
    }

    appDelegate.applicationDidEnterBackground(UIApplication.shared)
  }

  private func urlOptions(from urlContext: UIOpenURLContext) -> [UIApplication.OpenURLOptionsKey: Any] {
    var options: [UIApplication.OpenURLOptionsKey: Any] = [.openInPlace: urlContext.options.openInPlace]

    if let sourceApplication = urlContext.options.sourceApplication {
      options[.sourceApplication] = sourceApplication
    }

    if let annotation = urlContext.options.annotation {
      options[.annotation] = annotation
    }

    return options
  }
}`;

const sceneConnectionBridge = `  func configureSceneConnectionOptions(_ connectionOptions: UIScene.ConnectionOptions) {
    var sceneLaunchOptions = launchOptions ?? [:]

    if let urlContext = connectionOptions.urlContexts.first {
      sceneLaunchOptions[.url] = urlContext.url

      if let sourceApplication = urlContext.options.sourceApplication {
        sceneLaunchOptions[.sourceApplication] = sourceApplication
      }

      if let annotation = urlContext.options.annotation {
        sceneLaunchOptions[.annotation] = annotation
      }
    } else if let userActivity = connectionOptions.userActivities.first {
      sceneLaunchOptions[.userActivityDictionary] = [
        UIApplication.LaunchOptionsKey.userActivityType.rawValue: userActivity.activityType,
        "UIApplicationLaunchOptionsUserActivityKey": userActivity,
      ]
    }

    launchOptions = sceneLaunchOptions
  }`;

const reactNativeStartMethod = `  func startReactNative(in window: UIWindow) {
    guard let reactNativeFactory else {
      fatalError("React Native factory was not initialized before the scene connected.")
    }

    reactNativeFactory.startReactNative(
      withModuleName: "main",
      in: window,
      launchOptions: launchOptions)
  }`;

function replaceRequired(source, before, after) {
  if (!source.includes(before)) {
    throw new Error('Uploadiny scene lifecycle plugin could not find the Expo iOS AppDelegate template it supports.');
  }

  return source.replace(before, after);
}

function withSceneConnectionBridge(contents) {
  return replaceRequired(
    contents,
    `${reactNativeStartMethod}\n\n  // Linking API`,
    `${reactNativeStartMethod}\n\n${sceneConnectionBridge}\n\n  // Linking API`,
  );
}

function transformAppDelegate(contents) {
  if (contents.includes(sceneConnectionBridge) && contents.includes(sceneDelegate)) {
    return contents;
  }

  if (contents.includes('class SceneDelegate: UIResponder, UIWindowSceneDelegate')) {
    return withSceneConnectionBridge(replaceRequired(contents, legacySceneDelegate, sceneDelegate));
  }

  const sceneEnabledContents = replaceRequired(
    replaceRequired(
      replaceRequired(
        replaceRequired(
          contents,
          '  var window: UIWindow?\n\n  var reactNativeDelegate: ExpoReactNativeFactoryDelegate?\n  var reactNativeFactory: RCTReactNativeFactory?',
          '  var reactNativeDelegate: ExpoReactNativeFactoryDelegate?\n  var reactNativeFactory: RCTReactNativeFactory?\n  private var launchOptions: [UIApplication.LaunchOptionsKey: Any]?',
        ),
        '  ) -> Bool {\n    let delegate = ReactNativeDelegate()',
        '  ) -> Bool {\n    self.launchOptions = launchOptions\n\n    let delegate = ReactNativeDelegate()',
      ),
      '#if os(iOS) || os(tvOS)\n    window = UIWindow(frame: UIScreen.main.bounds)\n    factory.startReactNative(\n      withModuleName: "main",\n      in: window,\n      launchOptions: launchOptions)\n#endif\n\n',
      '',
    ),
    '    return super.application(application, didFinishLaunchingWithOptions: launchOptions)\n  }\n\n  // Linking API',
    `    return super.application(application, didFinishLaunchingWithOptions: launchOptions)\n  }\n\n${reactNativeStartMethod}\n\n${sceneConnectionBridge}\n\n  // Linking API`,
  );

  return replaceRequired(
    sceneEnabledContents,
    '}\n\nclass ReactNativeDelegate: ExpoReactNativeFactoryDelegate {',
    `}\n\n${sceneDelegate}\n\nclass ReactNativeDelegate: ExpoReactNativeFactoryDelegate {`,
  );
}

function withSceneLifecycle(config) {
  config = withInfoPlist(config, (nextConfig) => {
    nextConfig.modResults.UIApplicationSceneManifest = sceneManifest;

    return nextConfig;
  });

  return withAppDelegate(config, (nextConfig) => {
    nextConfig.modResults.contents = transformAppDelegate(nextConfig.modResults.contents);

    return nextConfig;
  });
}

module.exports = withSceneLifecycle;
module.exports.transformAppDelegate = transformAppDelegate;
