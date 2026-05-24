// Local Expo config plugin. Two unrelated jobs the standard schema
// can't express cleanly:
//
//   1. Per-variant launcher label. The Android debug variant gets a
//      separate `app_name` string ("Equi App (dev)") so the dev build
//      can sit next to a release build ("Equi App", from app.json's
//      `expo.name`) without being confused for the same app.
//
//   2. Gradle JVM heap. R8/D8 dex-merging this project's deps OOMs at
//      the default 2 GB. We bump org.gradle.jvmargs in gradle.properties
//      so release builds finish. expo-build-properties doesn't expose
//      this knob, so we patch the file directly during prebuild.

const { withDangerousMod } = require('@expo/config-plugins');
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

module.exports = (config, opts = {}) => {
  const debugLabel = opts.debugLabel ?? 'Equi App (dev)';
  const jvmargs = opts.jvmargs ?? '-Xmx6144m -XX:MaxMetaspaceSize=1024m';
  return withDangerousMod(config, [
    'android',
    async (cfg) => {
      const projectRoot = cfg.modRequest.projectRoot;
      writeDebugAppName(projectRoot, debugLabel);
      patchGradleHeap(projectRoot, jvmargs);
      return cfg;
    },
  ]);
};
