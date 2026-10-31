// Local Expo config plugin. Two unrelated jobs the standard schema
// can't express cleanly:
//
//   1. Per-variant launcher label. The Android debug variant gets a
//      separate `app_name` string ("EquiApp (dev)") so the dev build
//      can sit next to a release build ("EquiApp", from app.json's
//      `expo.name`) without being confused for the same app.
//
//   2. Gradle JVM heap. R8/D8 dex-merging this project's deps OOMs at
//      the default 2 GB. We bump org.gradle.jvmargs in gradle.properties
//      so release builds finish. expo-build-properties doesn't expose
//      this knob, so we patch the file directly during prebuild.
//
//   3. Fresh expo-updates asset manifest. Its create*UpdatesResources
//      Gradle tasks declare no file inputs, so Gradle keeps reusing the
//      first app.manifest; images added later are missing from the
//      embedded update and render blank in release builds. Marking the
//      tasks never up to date fixes every build path (CLI, Studio, CI).

const { withAppBuildGradle, withDangerousMod } = require('@expo/config-plugins');
const fs = require('fs');
const path = require('path');

function writeDebugAppName(projectRoot, label) {
  const dir = path.join(projectRoot, 'android/app/src/debug/res/values');
  fs.mkdirSync(dir, { recursive: true });
  const xml = `<?xml version="1.0" encoding="utf-8"?>
<resources>
  <string name="app_name">${label}</string>
</resources>
`;
  fs.writeFileSync(path.join(dir, 'strings.xml'), xml);
}

function patchGradleHeap(projectRoot, jvmargs) {
  const file = path.join(projectRoot, 'android/gradle.properties');
  if (!fs.existsSync(file)) return;
  let contents = fs.readFileSync(file, 'utf8');
  if (/^org\.gradle\.jvmargs=.*/m.test(contents)) {
    contents = contents.replace(/^org\.gradle\.jvmargs=.*/m, `org.gradle.jvmargs=${jvmargs}`);
  } else {
    contents += `\norg.gradle.jvmargs=${jvmargs}\n`;
  }
  fs.writeFileSync(file, contents);
}

const FRESH_MANIFEST_MARKER = '// expo-updates: always regenerate app.manifest';
const FRESH_MANIFEST = `
${FRESH_MANIFEST_MARKER} (see plugins/withCustomAndroidBuild.js)
tasks.matching { it.name.startsWith('create') && it.name.endsWith('UpdatesResources') }.configureEach {
    outputs.upToDateWhen { false }
}
`;

function addFreshUpdatesManifest(buildGradle) {
  return buildGradle.includes(FRESH_MANIFEST_MARKER) ? buildGradle : buildGradle + FRESH_MANIFEST;
}

const withCustomAndroidBuild = (config, opts = {}) => {
  const debugLabel = opts.debugLabel ?? `${require('../constants/brand.json').name} (dev)`;
  const jvmargs = opts.jvmargs ?? '-Xmx6144m -XX:MaxMetaspaceSize=1024m';
  const withFiles = withDangerousMod(config, [
    'android',
    async (cfg) => {
      const projectRoot = cfg.modRequest.projectRoot;
      writeDebugAppName(projectRoot, debugLabel);
      patchGradleHeap(projectRoot, jvmargs);
      return cfg;
    },
  ]);
  return withAppBuildGradle(withFiles, (cfg) => {
    cfg.modResults.contents = addFreshUpdatesManifest(cfg.modResults.contents);
    return cfg;
  });
};

module.exports = withCustomAndroidBuild;
module.exports.addFreshUpdatesManifest = addFreshUpdatesManifest;
