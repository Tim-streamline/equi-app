"""Exercise packaging and real shell activation with stubbed PHP/HTTP services."""
import hashlib
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest

DEPLOY = Path(__file__).resolve().parent
SITE = "/home/ploi/equi-app.staging.optimize-it.nl"
OLD = "20260913T120000Z-abcdef123456-123456"
NEW = "20260913T130000Z-abcdef123456-654321"


class DeploymentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.site = self.root / "site"
        self.shared = self.site / "shared"
        for folder in ["releases", "incoming", "shared/backups", "shared/storage/app/private", "shared/deploy-tools"]:
            (self.site / folder).mkdir(parents=True, exist_ok=True)
        (self.shared / "deploy-tools/ploi-nginx.php").write_text("helper")
        (self.shared / "live-nginx.conf").write_text("original config")
        (self.shared / ".env").write_text("APP_ENV=staging\n")
        (self.shared / "storage/keep.txt").write_text("persistent upload")
        for name in ["powersync_private.pem", "powersync_public.pem"]:
            (self.shared / "storage/app/private" / name).write_text("persistent key")
        self.old = self.site / "releases" / OLD
        self.make_release(self.old, OLD)
        (self.old / "storage").symlink_to(self.shared / "storage")
        (self.site / "current").symlink_to(self.old)
        self.script = self.root / "activate.sh"
        # Substitute the fixed site only in the test copy; production has no bypass.
        self.script.write_text((DEPLOY / "activate-release.sh").read_text().replace(SITE, str(self.site)))
        self.bin = self.root / "bin"
        self.bin.mkdir()
        stub = """#!/usr/bin/env python3
import os, sys
from pathlib import Path
name = Path(sys.argv[0]).name
args = ' '.join(sys.argv[1:])
with open(os.environ['CALLS'], 'a') as f: f.write(name + ' ' + args + '\\n')
if os.environ.get('FAIL') and os.environ['FAIL'] in name + ' ' + args: sys.exit(1)
if name == 'php8.5':
    if args == 'artisan down --retry=15': Path('storage/down').touch()
    if args == 'artisan up': Path('storage/down').unlink(missing_ok=True)
    if args.startswith('.deploy/backup-database.php '): Path(sys.argv[2]).write_text('backup')
    if 'ploi-nginx.php snapshot ' in args: Path(sys.argv[3]).write_text(Path(os.environ['LIVE_NGINX']).read_text())
    if 'ploi-nginx.php apply ' in args: Path(os.environ['LIVE_NGINX']).write_text(Path(sys.argv[3]).read_text())
if name == 'curl':
    if '--write-out' in sys.argv:
        print('308 ' + sys.argv[-1].replace('http://', 'https://'), end='')
    elif '--output' not in sys.argv and sys.argv[-1].endswith(('/', '/onboarding/welcome', '/protocol')):
        print(Path('web-dist/index.html').read_text(), end='')
"""
        for name in ["php8.5", "sudo", "curl"]:
            path = self.bin / name
            path.write_text(stub)
            path.chmod(0o755)
        self.env = {**os.environ, "PATH": f"{self.bin}:{os.environ['PATH']}", "CALLS": str(self.root / "calls"), "LIVE_NGINX": str(self.shared / "live-nginx.conf")}
        source = self.root / "artifact"
        self.make_release(source, NEW)
        archive = self.site / "incoming" / f"{NEW}.tar.gz"
        with tarfile.open(archive, "w:gz") as tar:
            tar.add(source, arcname=".")
        checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
        archive.with_suffix(".gz.sha256").write_text(f"{checksum}  {archive.name}\n")

    def make_release(self, path, identifier):
        (path / ".deploy").mkdir(parents=True)
        (path / ".deploy/check-release.php").touch()
        (path / ".deploy/nginx-staging.conf").write_text("nginx for " + identifier)
        (path / "web-dist").mkdir()
        (path / "web-dist/index.html").write_text("customer app")
        (path / "public").mkdir()
        (path / "artisan").touch()
        (path / "RELEASE_ID").write_text(identifier + "\n")

    def run_deploy(self, failure="", mode="deploy", identifier=NEW, library="0", seed="0"):
        return subprocess.run(["bash", str(self.script), str(self.site), identifier, mode, library, seed],
                              env={**self.env, "FAIL": failure}, text=True, capture_output=True)

    def calls(self):
        path = self.root / "calls"
        return path.read_text() if path.exists() else ""

    def test_success_preserves_storage_and_backs_up_before_migration(self):
        result = self.run_deploy()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        current = (self.site / "current").resolve()
        self.assertEqual(current.name, NEW)
        self.assertEqual((current / "storage/keep.txt").read_text(), "persistent upload")
        self.assertEqual((current / ".env").resolve(), self.shared / ".env")
        self.assertEqual((current / "public/storage").resolve(), self.shared / "storage/app/public")
        self.assertLess(self.calls().index("backup-database.php"), self.calls().index("artisan migrate"))
        self.assertEqual((self.shared / "live-nginx.conf").read_text(), "nginx for " + NEW)
        self.assertNotIn("db:seed", self.calls())

    def test_backup_failure_never_enters_maintenance_or_switches(self):
        self.assertNotEqual(self.run_deploy("backup-database.php").returncode, 0)
        self.assertEqual((self.site / "current").resolve(), self.old)
        self.assertNotIn("artisan down", self.calls())
        self.assertNotIn("artisan migrate", self.calls())

    def test_migration_failure_brings_old_release_back_up(self):
        self.assertNotEqual(self.run_deploy("artisan migrate").returncode, 0)
        self.assertEqual((self.site / "current").resolve(), self.old)
        self.assertFalse((self.shared / "storage/down").exists())

    def test_failed_health_check_restores_old_code(self):
        self.assertNotEqual(self.run_deploy("curl").returncode, 0)
        self.assertEqual((self.site / "current").resolve(), self.old)
        self.assertFalse((self.shared / "last-successful-release").exists())
        self.assertEqual((self.shared / "live-nginx.conf").read_text(), "original config")

    def test_explicit_rollback_does_not_run_migrations_or_imports(self):
        self.assertEqual(self.run_deploy().returncode, 0)
        (self.root / "calls").unlink()
        result = self.run_deploy(mode="rollback", identifier=OLD)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual((self.site / "current").resolve(), self.old)
        self.assertNotIn("artisan migrate", self.calls())
        self.assertNotIn("db:seed", self.calls())

    def test_corrupt_transfer_stops_before_extracting_or_mutating(self):
        (self.site / "incoming" / f"{NEW}.tar.gz").write_text("broken")
        self.assertNotEqual(self.run_deploy().returncode, 0)
        self.assertFalse((self.site / "releases" / NEW).exists())
        self.assertEqual(self.calls(), "")

    def test_catalog_and_library_require_flags_and_no_demo_seeder_runs(self):
        result = self.run_deploy(library="1", seed="1")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("--class=IntakeQuestionnaireSeeder", self.calls())
        self.assertIn("--class=ProtocolTemplateSeeder", self.calls())
        self.assertIn("artisan deploy-library-items", self.calls())
        self.assertNotIn("--class=DatabaseSeeder", self.calls())
        self.assertNotIn("--class=AdminUserSeeder", self.calls())

    def test_invalid_release_identifier_is_rejected(self):
        self.assertNotEqual(self.run_deploy(identifier="../../shared").returncode, 0)
        self.assertEqual(self.calls(), "")

    def test_nginx_api_failure_restores_code_and_original_configuration(self):
        self.assertNotEqual(self.run_deploy("apply " + str(self.site / "releases" / NEW)).returncode, 0)
        self.assertEqual((self.site / "current").resolve(), self.old)
        self.assertEqual((self.shared / "live-nginx.conf").read_text(), "original config")
        self.assertIn("/site.conf", self.calls())

    def test_nginx_snapshot_failure_does_not_migrate_or_switch(self):
        self.assertNotEqual(self.run_deploy("snapshot ").returncode, 0)
        self.assertEqual((self.site / "current").resolve(), self.old)
        self.assertNotIn("artisan migrate", self.calls())

    def test_rollback_to_legacy_release_uses_saved_nginx_config(self):
        (self.old / ".deploy/nginx-staging.conf").unlink()
        self.assertEqual(self.run_deploy().returncode, 0)
        result = self.run_deploy(mode="rollback", identifier=OLD)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual((self.shared / "live-nginx.conf").read_text(), "original config")

    def test_failed_http_redirect_check_restores_code_and_nginx(self):
        self.assertNotEqual(self.run_deploy("--write-out").returncode, 0)
        self.assertEqual((self.site / "current").resolve(), self.old)
        self.assertEqual((self.shared / "live-nginx.conf").read_text(), "original config")
        self.assertFalse((self.shared / "last-successful-release").exists())

    def test_web_package_includes_shared_sources_without_local_state(self):
        source = self.root / "web-source"
        files = {
            "web": "app.json package.json package-lock.json babel.config.js metro.config.js tailwind.config.js tsconfig.json global.css nativewind-env.d.ts",
            "expo-app": "package.json app.json tailwind.config.js tsconfig.json global.css nativewind-env.d.ts",
        }
        directories = {
            "web": "components db scripts",
            "expo-app": "app assets components constants db hooks lib",
        }
        for project in files:
            base = source / project
            base.mkdir(parents=True)
            for name in files[project].split():
                (base / name).touch()
            for name in directories[project].split():
                (base / name).mkdir()
            for name in [".env", "components/.env.local", "components/private.pem", "components/private.key"]:
                (base / name).write_text("secret")
            for name in ["node_modules", "android", "dist"]:
                (base / name).mkdir()
                (base / name / "local").touch()
        (source / "expo-app/app/screen.tsx").write_text("shared screen")
        (source / "web/components/provider.tsx").write_text("web provider")
        packed = self.root / "packed-web"
        result = subprocess.run(["bash", str(DEPLOY / "package-web.sh"), str(source), str(packed)], text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((packed / "expo-app/app/screen.tsx").read_text(), "shared screen")
        self.assertEqual((packed / "web/components/provider.tsx").read_text(), "web provider")
        for project in files:
            for name in [".env", "components/.env.local", "components/private.pem", "components/private.key", "node_modules", "android", "dist"]:
                self.assertFalse((packed / project / name).exists(), name)

    def test_packaging_excludes_local_state_and_secrets(self):
        source = self.root / "backend"
        for directory in ["app", "bootstrap/cache", "config", "database/data", "public", "resources", "routes"]:
            (source / directory).mkdir(parents=True, exist_ok=True)
        for name in ["artisan", "composer.json", "composer.lock", "package.json", "package-lock.json", "vite.config.js"]:
            (source / name).touch()
        for name in [".env", "bootstrap/cache/config.php", "database/database.sqlite", "database/key.pem", "public/hot"]:
            (source / name).write_text("secret")
        (source / "database/data/catalog.json").write_text("catalog")
        (source / "public/storage").symlink_to(self.shared / "storage")
        result = subprocess.run(["bash", str(DEPLOY / "package-backend.sh"), str(source), str(self.root / "packed")], text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        packed = self.root / "packed"
        self.assertEqual((packed / "database/data/catalog.json").read_text(), "catalog")
        for name in [".env", "bootstrap/cache/config.php", "database/database.sqlite", "database/key.pem", "public/hot", "public/storage"]:
            self.assertFalse((packed / name).exists(), name)


if __name__ == "__main__":
    unittest.main()
