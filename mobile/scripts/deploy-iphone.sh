#!/bin/bash
# Build, sign, install, and launch Uploadiny on Bruno's paired iPhone.
set -euo pipefail

DEVICE="C8A51380-2830-56FF-971F-8AAAE4681B93"
DESTINATION_ID="00008140-000215940C69801C"
TEAM_ID="FWCW3LG29Y"
IDENTITY="Apple Development: bruno.falcao@live.com"
DERIVED_DATA="$HOME/Library/Developer/Xcode/DerivedData/UploadinyDevice"
APP="$DERIVED_DATA/Build/Products/Release-iphoneos/Uploadiny.app"
EXTENSION_PLIST="$APP/PlugIns/expo-sharing-extension.appex/Info.plist"
MOBILE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PROJECT_ROOT="$(cd "$MOBILE_ROOT/.." && pwd)"
BUILD_LOG="$(mktemp)"

cleanup() {
  rm -f "$BUILD_LOG"
}

trap cleanup EXIT

if [[ -z "${UPLOADINY_UPLOAD_TOKEN:-}" && -f "$PROJECT_ROOT/.env" ]]; then
  UPLOADINY_UPLOAD_TOKEN="$(sed -n 's/^UPLOADINY_UPLOAD_TOKEN=//p' "$PROJECT_ROOT/.env" | tail -1)"
fi

if [[ -z "${UPLOADINY_UPLOAD_TOKEN:-}" ]]; then
  echo "UPLOADINY_UPLOAD_TOKEN is not configured."
  exit 1
fi

cd "$MOBILE_ROOT"
npx expo prebuild --platform ios --no-install
npx pod-install ios

cd "$MOBILE_ROOT/ios"

if ! xcodebuild -workspace Uploadiny.xcworkspace -scheme Uploadiny -configuration Release \
  -destination "platform=iOS,id=$DESTINATION_ID" -allowProvisioningUpdates \
  -derivedDataPath "$DERIVED_DATA" DEVELOPMENT_TEAM="$TEAM_ID" CODE_SIGN_STYLE=Automatic \
  UPLOADINY_UPLOAD_TOKEN="$UPLOADINY_UPLOAD_TOKEN" build >"$BUILD_LOG" 2>&1; then
  tail -80 "$BUILD_LOG" | sed -E 's/(UPLOADINY_UPLOAD_TOKEN( =|=) )[A-Za-z0-9._-]+/\1[redacted]/g'
  exit 1
fi

if [[ "$(plutil -extract UploadinyUploadToken raw "$EXTENSION_PLIST")" != "$UPLOADINY_UPLOAD_TOKEN" ]]; then
  echo "The Share Extension credential was not embedded correctly."
  exit 1
fi

for framework in "$APP"/Frameworks/*.framework; do
  codesign -f -s "$IDENTITY" "$framework" >/dev/null
done

codesign -f -s "$IDENTITY" --preserve-metadata=entitlements,identifier,flags "$APP" >/dev/null
codesign --verify --deep --strict "$APP"

xcrun devicectl device install app --device "$DEVICE" "$APP"
xcrun devicectl device process launch --device "$DEVICE" test.uploadiny.app
xcrun devicectl device info processes --device "$DEVICE" | grep -F "/Uploadiny.app/Uploadiny" >/dev/null

echo "Uploadiny deployed and running."
