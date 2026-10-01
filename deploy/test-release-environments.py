"""Exercise a built release's real Laravel guards against local Sail PostgreSQL.

Usage: python3 deploy/test-release-environments.py deploy/builds/RELEASE_ID.tar.gz
No migrations, mail delivery, remote deployment, or application data changes.
"""
from pathlib import Path
import os
import re
import subprocess
import sys
import tarfile
import tempfile


def main(artifact):
    repo = Path(__file__).resolve().parent.parent
    original = (repo / 'backend/.env').read_text()
    database = re.search(r'^DB_DATABASE=(.+)$', original, re.M)[1].strip().strip('\"\'')
    with tempfile.TemporaryDirectory(prefix='equi-release-environments-') as directory:
        root = Path(directory)
        root.chmod(0o700)
        with tarfile.open(artifact) as archive:
            assert not any(Path(member.name).name in
                           ['.env', 'staging.env', 'production.env', 'powersync_private.pem']
                           for member in archive.getmembers()), 'Runtime secrets found in artifact'
            archive.extractall(root, filter='data')
        assert (root / '.deploy/render-nginx.php').is_file(), 'Activation helper missing'
        assert (root / 'public/build/manifest.json').is_file(), 'Built admin assets missing'
        assert (root / 'web-dist/powersync/worker').is_dir(), 'Built web workers missing'
        for name in ['logs', 'framework/cache/data', 'framework/sessions', 'framework/views']:
            (root / 'storage' / name).mkdir(parents=True, exist_ok=True)

        for environment in ['staging', 'production']:
            host = environment + '.example.test'
            values = {'APP_ENV': environment, 'APP_URL': 'https://' + host,
                      'APP_DEBUG': 'false', 'SESSION_SECURE_COOKIE': 'true'}
            content = original
            for key, value in values.items():
                if re.search(r'^' + key + '=', content, re.M):
                    content = re.sub(r'^' + key + r'=.*$', key + '=' + value, content, flags=re.M)
                else:
                    content += '\n' + key + '=' + value + '\n'
            envfile = root / '.env'
            # Open with private permissions before writing local credentials.
            fd = os.open(envfile, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
            with os.fdopen(fd, 'w') as output:
                output.write(content)
            command = ['docker', 'run', '--rm', '--network', 'backend_sail',
                       '-v', str(root) + ':/build', '-w', '/build',
                       '-e', 'DEPLOY_ENVIRONMENT=' + environment, '-e', 'DEPLOY_HOSTNAME=' + host,
                       '-e', 'DEPLOY_DATABASE=' + database, '--entrypoint', 'php',
                       'sail-8.5/app', '.deploy/check-release.php']
            result = subprocess.run(command, capture_output=True, text=True)
            assert result.returncode == 0, environment + ': valid release check failed'

            wrong = command.copy()
            wrong[wrong.index('DEPLOY_DATABASE=' + database)] = 'DEPLOY_DATABASE=wrong_database'
            result = subprocess.run(wrong, capture_output=True, text=True)
            assert result.returncode != 0 and 'unexpected database' in result.stdout + result.stderr, \
                environment + ': database guard did not stop deployment'

            wrong = command.copy()
            other = 'production' if environment == 'staging' else 'staging'
            wrong[wrong.index('DEPLOY_ENVIRONMENT=' + environment)] = 'DEPLOY_ENVIRONMENT=' + other
            result = subprocess.run(wrong, capture_output=True, text=True)
            assert result.returncode != 0 and 'selected environment' in result.stdout + result.stderr, \
                environment + ': environment guard did not stop deployment'

            # pg_dump cannot create this file: exercise the real helper's failure
            # path without exporting any application data or changing the DB.
            backup = command[:-1] + ['.deploy/backup-database.php', '/missing-equi-test-directory/backup.dump']
            result = subprocess.run(backup, capture_output=True, text=True)
            assert result.returncode != 0 and 'Database backup failed:' in result.stdout + result.stderr, \
                environment + ': failed backup did not stop deployment'
            print(environment + ': valid release passes; wrong database, environment, and failed backup stop deployment')
        print('Artifact contains both builds and activation helpers without deployment environment files.')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit('Usage: test-release-environments.py deploy/builds/RELEASE_ID.tar.gz')
    main(Path(sys.argv[1]))
