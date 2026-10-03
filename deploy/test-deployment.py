"""Exercise packaging and real shell activation with stubbed PHP/HTTP services."""
import hashlib
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
import shutil

DEPLOY = Path(__file__).resolve().parent
SITE = "/home/ploi/equi-app.online"
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
        self.previous_env = "APP_ENV=staging\nMAIL_HOST=old.example.test\n"
        self.next_env = "APP_ENV=staging\nMAIL_HOST=new.example.test\n"
        (self.shared / ".env").write_text(self.previous_env)
        (self.shared / ".env").chmod(0o600)
        (self.shared / "storage/keep.txt").write_text("persistent upload")
        for name in ["powersync_private.pem", "powersync_public.pem"]:
            (self.shared / "storage/app/private" / name).write_text("persistent key")
        self.old = self.site / "releases" / OLD
        self.make_release(self.old, OLD)
        (self.old / "storage").symlink_to(self.shared / "storage")
        (self.old / ".env").symlink_to(self.shared / ".env")
        (self.site / "current").symlink_to(self.old)
        self.script = self.root / "activate.sh"
        # Substitute only the test site's path guard; shipped activation validates
        # its path against the selected hostname and has no test bypass.
        self.script.write_text((DEPLOY / "activate-release.sh").read_text().replace(
            '"$site" == "/home/ploi/$hostname"', '"$site" == "' + str(self.site) + '"'))
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
    if args.startswith('.deploy/render-nginx.php '): Path(sys.argv[3]).write_text(Path(sys.argv[2]).read_text())
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
        self.env = {**os.environ, "PATH": f"{self.bin}:{os.environ['PATH']}", "CALLS": str(self.root / "calls"), "LIVE_NGINX": str(self.shared / "live-nginx.conf"),
                    "DEPLOY_ENVIRONMENT": "staging", "DEPLOY_HOSTNAME": "equi-app.online",
                    "DEPLOY_DATABASE": "equi_app_staging", "DEPLOY_PLOI_SERVER_ID": "121767", "DEPLOY_PLOI_SITE_ID": "406977"}
        source = self.root / "artifact"
        self.make_release(source, NEW)
        archive = self.site / "incoming" / f"{NEW}.tar.gz"
        with tarfile.open(archive, "w:gz") as tar:
            tar.add(source, arcname=".")
        checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
        archive.with_suffix(".gz.sha256").write_text(f"{checksum}  {archive.name}\n")
        (self.site / "incoming" / f"{NEW}.env").write_text(self.next_env)

    def make_release(self, path, identifier):
        (path / ".deploy").mkdir(parents=True)
        (path / ".deploy/check-release.php").touch()
        (path / ".deploy/render-nginx.php").touch()
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
        self.assertEqual((self.shared / ".env").read_text(), self.next_env)
        self.assertEqual((self.shared / ".env").stat().st_mode & 0o777, 0o600)
        self.assertEqual((self.shared / "env-releases" / f"{NEW}.previous.env").read_text(), self.previous_env)
        self.assertFalse((self.site / "incoming" / f"{NEW}.env").exists())

    def test_backup_failure_never_enters_maintenance_or_switches(self):
        self.assertNotEqual(self.run_deploy("backup-database.php").returncode, 0)
        self.assertEqual((self.site / "current").resolve(), self.old)
        self.assertNotIn("artisan down", self.calls())
        self.assertNotIn("artisan migrate", self.calls())
        self.assertEqual((self.shared / ".env").read_text(), self.previous_env)

    def test_migration_failure_brings_old_release_back_up(self):
        self.assertNotEqual(self.run_deploy("artisan migrate").returncode, 0)
        self.assertEqual((self.site / "current").resolve(), self.old)
        self.assertFalse((self.shared / "storage/down").exists())
        self.assertEqual((self.shared / ".env").read_text(), self.previous_env)

    def test_failed_health_check_restores_old_code(self):
        self.assertNotEqual(self.run_deploy("curl").returncode, 0)
        self.assertEqual((self.site / "current").resolve(), self.old)
        self.assertFalse((self.shared / "last-successful-release").exists())
        self.assertEqual((self.shared / "live-nginx.conf").read_text(), "original config")
        self.assertEqual((self.shared / ".env").read_text(), self.previous_env)

    def test_explicit_rollback_does_not_run_migrations_or_imports(self):
        self.assertEqual(self.run_deploy().returncode, 0)
        (self.root / "calls").unlink()
        result = self.run_deploy(mode="rollback", identifier=OLD)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual((self.site / "current").resolve(), self.old)
        self.assertNotIn("artisan migrate", self.calls())
        self.assertNotIn("db:seed", self.calls())
        self.assertEqual((self.shared / ".env").read_text(), self.next_env)

    def test_missing_environment_file_never_changes_running_release(self):
        (self.site / "incoming" / f"{NEW}.env").unlink()
        result = self.run_deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Missing deployment environment file', result.stderr)
        self.assertEqual((self.site / 'current').resolve(), self.old)
        self.assertEqual((self.shared / '.env').read_text(), self.previous_env)
        self.assertNotIn('artisan migrate', self.calls())

    def test_invalid_candidate_configuration_preserves_old_environment(self):
        self.assertNotEqual(self.run_deploy('.deploy/check-release.php').returncode, 0)
        self.assertEqual((self.shared / '.env').read_text(), self.previous_env)
        self.assertEqual((self.site / 'current').resolve(), self.old)
        self.assertNotIn('artisan migrate', self.calls())

    def test_production_health_checks_use_the_selected_domain(self):
        self.env.update(DEPLOY_ENVIRONMENT='production', DEPLOY_HOSTNAME='app.example.test')
        result = self.run_deploy()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('--resolve app.example.test:443:127.0.0.1', self.calls())
        self.assertIn('https://app.example.test/web-session/csrf', self.calls())
        self.assertNotIn('equi-app.online', self.calls())
        self.assertEqual((self.site / 'current/public/robots.txt').read_text(), 'User-agent: *\nDisallow:\n')

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
            "web": "index.js app.json app.config.js package.json package-lock.json babel.config.js metro.config.js tailwind.config.js tsconfig.json global.css nativewind-env.d.ts",
            "expo-app": "package.json app.json tailwind.config.js tsconfig.json global.css nativewind-env.d.ts",
        }
        directories = {
            "web": "components db scripts public",
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
        (source / "web/public/site.webmanifest").write_text('{"name":"EquiApp"}')
        (source / "web/public/apple-touch-icon.png").write_bytes(b"icon")
        (source / "web/public/powersync").mkdir()
        (source / "web/public/powersync/generated-worker.js").write_text("generated")
        packed = self.root / "packed-web"
        result = subprocess.run(["bash", str(DEPLOY / "package-web.sh"), str(source), str(packed)], text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((packed / "expo-app/app/screen.tsx").read_text(), "shared screen")
        self.assertEqual((packed / "web/components/provider.tsx").read_text(), "web provider")
        self.assertTrue((packed / "web/index.js").is_file())
        self.assertEqual((packed / "web/public/site.webmanifest").read_text(), '{"name":"EquiApp"}')
        self.assertEqual((packed / "web/public/apple-touch-icon.png").read_bytes(), b"icon")
        self.assertFalse((packed / "web/public/powersync").exists())
        for project in files:
            for name in [".env", "components/.env.local", "components/private.pem", "components/private.key", "node_modules", "android", "dist"]:
                self.assertFalse((packed / project / name).exists(), name)

    def test_packaging_excludes_local_state_and_secrets(self):
        source = self.root / "backend"
        for directory in ["app", "bootstrap/cache", "config", "database/data", "public", "resources", "routes", "powersync"]:
            (source / directory).mkdir(parents=True, exist_ok=True)
        for name in ["artisan", "composer.json", "composer.lock", "package.json", "package-lock.json", "vite.config.js"]:
            (source / name).touch()
        for name in [".env", "bootstrap/cache/config.php", "database/database.sqlite", "database/key.pem", "public/hot"]:
            (source / name).write_text("secret")
        (source / "database/data/catalog.json").write_text("catalog")
        (source / "powersync/sync_rules.yaml").write_text("streams: {}")
        (source / "powersync/runtime.env").write_text("secret")
        (source / "public/storage").symlink_to(self.shared / "storage")
        result = subprocess.run(["bash", str(DEPLOY / "package-backend.sh"), str(source), str(self.root / "packed")], text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        packed = self.root / "packed"
        self.assertEqual((packed / "database/data/catalog.json").read_text(), "catalog")
        self.assertEqual((packed / "powersync/sync_rules.yaml").read_text(), "streams: {}")
        self.assertFalse((packed / "powersync/runtime.env").exists())
        for name in [".env", "bootstrap/cache/config.php", "database/database.sqlite", "database/key.pem", "public/hot", "public/storage"]:
            self.assertFalse((packed / name).exists(), name)


class DeploymentEnvironmentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'deploy').mkdir()
        for name in ['deploy-to', 'deploy-staging', 'deploy-production']:
            shutil.copy2(DEPLOY.parent / name, self.root / name)
        for name in ['environment.py', 'activate-release.sh', 'ploi-nginx.php']:
            shutil.copy2(DEPLOY / name, self.root / 'deploy' / name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        stub = '''#!/usr/bin/env python3
import os, sys, shutil
from pathlib import Path
name = Path(sys.argv[0]).name
root = Path(os.environ['TEST_ROOT'])
with open(root / 'calls', 'a') as f: f.write(name + ' ' + ' '.join(sys.argv[1:]) + '\\n')
if name == 'ssh' and 'bash' in sys.argv: (root / 'remote-script').write_text(sys.stdin.read())
if name == 'rsync' and sys.argv[-1].endswith('.env'): shutil.copyfile(sys.argv[-2], root / 'uploaded.env')
'''
        for name in ['ssh', 'rsync']:
            path = self.bin / name
            path.write_text(stub)
            path.chmod(0o755)
        self.env = {**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'],
                    'TEST_ROOT': str(self.root), 'PLOI_API_TOKEN': 'test-token'}
        self.artifact = self.root / f'{NEW}.tar.gz'
        self.artifact.write_bytes(b'build without runtime configuration')
        self.artifact.with_suffix('.gz.sha256').write_text(
            hashlib.sha256(self.artifact.read_bytes()).hexdigest() + '  ' + self.artifact.name + '\n')

    def configure(self, environment='staging', extra=''):
        host = 'equi-app.online' if environment == 'staging' else 'app.example.test'
        path = self.root / 'deploy' / f'{environment}.env'
        path.write_text(f'''APP_ENV={environment}
APP_URL=https://{host}
APP_DEBUG=false
APP_KEY="base64:test-key"
DB_CONNECTION=pgsql
DB_DATABASE=equi_{environment}
DB_PASSWORD="fake-secret"
SESSION_SECURE_COOKIE=true
DEPLOY_SSH_HOST=ploi@192.0.2.1
DEPLOY_SITE_PATH=/home/ploi/{host}
DEPLOY_PLOI_SERVER_ID=123
DEPLOY_PLOI_SITE_ID=456
''' + extra)
        path.chmod(0o600)
        return path

    def run_cli(self, environment='staging', *arguments, wrapper=False):
        command = ['bash', str(self.root / ('deploy-' + environment if wrapper else 'deploy-to'))]
        if not wrapper:
            command.append(environment)
        return subprocess.run(command + list(arguments), env=self.env, capture_output=True, text=True)

    def test_selected_file_is_uploaded_separately_without_interpreting_secrets(self):
        for environment in ['staging', 'production']:
            with self.subTest(environment=environment):
                marker = self.root / 'should-not-exist'
                password = f'MAIL_PASSWORD="$(touch {marker})`echo secret`$literal"\n'
                self.configure(environment, password)
                result = self.run_cli(environment, '--artifact', str(self.artifact), wrapper=True)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                payload = (self.root / 'uploaded.env').read_text()
                self.assertIn(f'APP_ENV={environment}', payload)
                self.assertIn(password, payload)
                self.assertNotIn('DEPLOY_', payload)
                self.assertFalse(marker.exists())
                self.assertNotIn('fake-secret', result.stdout + result.stderr)
                self.assertIn('DEPLOY_ENVIRONMENT=' + environment, (self.root / 'remote-script').read_text())
                self.assertEqual(self.artifact.read_bytes(), b'build without runtime configuration')

    def test_check_validates_without_contacting_server_or_requiring_a_token(self):
        self.configure()
        self.env['PLOI_API_TOKEN'] = ''
        result = self.run_cli('staging', '--check')
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('equi-app.online', result.stdout)
        self.assertFalse((self.root / 'calls').exists())

    def test_missing_file_fails_before_network_operations(self):
        self.assertNotEqual(self.run_cli('production', '--check').returncode, 0)
        self.assertFalse((self.root / 'calls').exists())

    def test_wrong_environment_and_target_injection_fail_before_network(self):
        for old, new in [('APP_ENV=production', 'APP_ENV=staging'),
                         ('DEPLOY_SSH_HOST=ploi@192.0.2.1', 'DEPLOY_SSH_HOST="ploi@192.0.2.1; echo bad"'),
                         ('DEPLOY_SITE_PATH=/home/ploi/app.example.test', 'DEPLOY_SITE_PATH=/home/ploi/staging.example.test')]:
            with self.subTest(setting=old):
                path = self.configure('production')
                path.write_text(path.read_text().replace(old, new))
                self.assertNotEqual(self.run_cli('production', '--artifact', str(self.artifact)).returncode, 0)
                self.assertFalse((self.root / 'calls').exists())

    def test_public_file_or_embedded_deployment_token_is_rejected(self):
        path = self.configure()
        path.chmod(0o644)
        self.assertNotEqual(self.run_cli('staging', '--check').returncode, 0)
        self.configure(extra='PLOI_API_TOKEN=must-not-be-uploaded\n')
        self.assertNotEqual(self.run_cli('staging', '--check').returncode, 0)
        self.assertFalse((self.root / 'calls').exists())

    def test_rollback_keeps_server_runtime_configuration(self):
        self.configure()
        result = self.run_cli('staging', '--rollback', OLD)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse((self.root / 'uploaded.env').exists())
        self.assertIn(' rollback ', (self.root / 'calls').read_text())


if __name__ == "__main__":
    unittest.main()
