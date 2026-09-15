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
        for folder in ["releases", "incoming", "shared/backups", "shared/storage/app/private"]:
            (self.site / folder).mkdir(parents=True, exist_ok=True)
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
"""
        for name in ["php8.5", "sudo", "curl"]:
            path = self.bin / name
            path.write_text(stub)
            path.chmod(0o755)
        self.env = {**os.environ, "PATH": f"{self.bin}:{os.environ['PATH']}", "CALLS": str(self.root / "calls")}
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
