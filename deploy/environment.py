#!/usr/bin/env python3
"""Read deployment metadata as dotenv data, never as executable shell code."""
import os
from pathlib import Path
import re
import shlex
import sys
from urllib.parse import urlsplit


def settings(path):
    values = {}
    # Only these literal scalar settings are interpreted here. The runtime
    # dotenv is otherwise preserved verbatim for Laravel's own dotenv parser.
    keys = {'APP_ENV', 'APP_URL', 'APP_KEY', 'APP_DEBUG', 'DB_CONNECTION',
            'DB_DATABASE', 'DB_PASSWORD', 'SESSION_SECURE_COOKIE', 'PLOI_API_TOKEN'}
    for line in Path(path).read_text().splitlines():
        match = re.match(r'^\s*(?:export\s+)?([A-Z_][A-Z_0-9]*)\s*=(.*)$', line)
        if not match or (match[1] not in keys and not match[1].startswith('DEPLOY_')):
            continue
        key, raw = match.groups()
        if key in values:
            raise ValueError(f'Duplicate setting: {key}')
        parts = shlex.split(raw, comments=True)
        if len(parts) > 1:
            raise ValueError(f'Quote the value of {key}')
        values[key] = parts[0] if parts else ''
    return values


def describe(environment, path):
    if environment not in ('staging', 'production'):
        raise ValueError('Environment must be staging or production')
    if Path(path).stat().st_mode & 0o077:
        raise ValueError(f'{path} must have private permissions (chmod 600)')
    values = settings(path)
    def required(key):
        value = values.get(key, '')
        if not value or value in ('null', '(null)') or '${' in value or '\n' in value or '\r' in value:
            raise ValueError(f'Configure {key} in {path}')
        return value
    if required('APP_ENV') != environment:
        raise ValueError(f'APP_ENV must match the selected {environment} environment')
    url = urlsplit(required('APP_URL'))
    host = url.hostname or ''
    if (url.scheme != 'https' or url.netloc != host or url.path not in ('', '/')
            or url.query or url.fragment or not re.fullmatch(r'[a-z0-9]+(?:[.-][a-z0-9]+)*', host)):
        raise ValueError('APP_URL must be an HTTPS domain without a port or path')
    if values.get('APP_DEBUG', '').lower() != 'false' or values.get('SESSION_SECURE_COOKIE', '').lower() != 'true':
        raise ValueError('Deployments require APP_DEBUG=false and SESSION_SECURE_COOKIE=true')
    if values.get('DB_CONNECTION') != 'pgsql':
        raise ValueError('Deployments require DB_CONNECTION=pgsql')
    required('APP_KEY')
    required('DB_PASSWORD')
    if 'PLOI_API_TOKEN' in values:
        raise ValueError('Keep PLOI_API_TOKEN outside Laravel environment files')
    server = required('DEPLOY_SSH_HOST')
    if not re.fullmatch(r'[a-z_][a-z0-9_-]*@[a-z0-9]+(?:[.-][a-z0-9]+)*', server):
        raise ValueError('DEPLOY_SSH_HOST must be user@host')
    site = required('DEPLOY_SITE_PATH')
    if site != '/home/ploi/' + host:
        raise ValueError('DEPLOY_SITE_PATH must be /home/ploi/<APP_URL domain>')
    database = required('DB_DATABASE')
    if not re.fullmatch(r'[a-zA-Z_][a-zA-Z_0-9]*', database):
        raise ValueError('DB_DATABASE must be a plain database name')
    ids = [required(key) for key in ('DEPLOY_PLOI_SERVER_ID', 'DEPLOY_PLOI_SITE_ID')]
    if not all(re.fullmatch(r'[1-9][0-9]*', value) for value in ids):
        raise ValueError('Ploi server and site IDs must be positive integers')
    return [server, site, host, database, *ids]


def runtime(source, destination):
    # Deployment settings are local tooling metadata, not Laravel settings.
    content = ''.join(line for line in Path(source).read_text().splitlines(keepends=True)
                      if not re.match(r'^\s*(?:export\s+)?DEPLOY_[A-Z_0-9]*\s*=', line))
    fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, 'w') as output:
        output.write(content)
    os.chmod(destination, 0o600)


if __name__ == '__main__':
    try:
        if len(sys.argv) != 4:
            raise ValueError('Usage: environment.py describe ENVIRONMENT FILE | runtime SOURCE DESTINATION')
        if sys.argv[1] == 'describe':
            print('\n'.join(describe(sys.argv[2], sys.argv[3])))
        elif sys.argv[1] == 'runtime':
            runtime(sys.argv[2], sys.argv[3])
        else:
            raise ValueError('Unknown command')
    except (ValueError, OSError) as error:
        # Never include parser input or dotenv values in errors.
        print('Deployment configuration error: ' + (str(error) if not isinstance(error, ValueError) or str(error) != 'No closing quotation' else 'Invalid quoting'), file=sys.stderr)
        sys.exit(1)
