"""Private PHPIDC production-stack acceptance environment; no business targets.

Uses cached Docker images, Unix sockets, standalone loopback TLS Nginx/PHP-FPM,
and PHP 8.4 workers as www. The empty database is installed by the caller through
HTTPS. Context exit removes only the processes/containers this instance created;
private evidence remains in its temporary directory.
"""

import base64
import json
import os
import pathlib
import pwd
import shutil
import signal
import socket
import ssl
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import uuid


class RuntimeEnvironment:
    def __init__(self, repo=None):
        self.repo = pathlib.Path(repo or pathlib.Path(__file__).resolve().parents[1]).resolve()
        self.php = '/www/server/php/84/bin/php'
        self.fpm = '/www/server/php/84/sbin/php-fpm'
        self.nginx = shutil.which('nginx')
        self.openssl = shutil.which('openssl')
        self.runuser = shutil.which('runuser')
        self.www = pwd.getpwnam('www')
        self.root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-production-runtime-'))
        os.chown(self.root, self.www.pw_uid, self.www.pw_gid)
        self.root.chmod(0o700)
        self.application = self.root / 'application'
        self.console = self.root / 'console.php'
        self.bootstrap = self.root / 'bootstrap.php'
        self.ca_file = self.root / 'ca.crt'
        self.containers = {}
        self.container_metadata = {}
        self.processes = {}
        self.process_history = {}
        self.logs = {}
        self.cleanup_result = None
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            self.port = listener.getsockname()[1]
        self.base_url = 'https://127.0.0.1:' + str(self.port)
        suffix = uuid.uuid4().hex[:12]
        self.instance_id = suffix
        self.container_names = {kind: 'laravel13idc-runtime-' + kind + '-' + suffix for kind in ['mysql', 'redis']}
        self.database_password = 'Private-' + os.urandom(20).hex() + '!'
        clean = {key: value for key, value in os.environ.items() if key.lower() not in
                 ['http_proxy', 'https_proxy', 'all_proxy', 'no_proxy'] and not key.startswith(
            ('APP_', 'DB_', 'REDIS_', 'LARAVEL_', 'VIEW_', 'QUEUE_', 'CACHE_', 'SESSION_', 'MAIL_', 'LOG_',
             'BROADCAST_', 'MAINTENANCE_', 'RECEIVABLE_', 'B5_'))}
        storage = self.root / 'storage'
        self.env = dict(clean, APP_ENV='production', APP_DEBUG='false', APP_URL=self.base_url,
                        APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(), APP_PREVIOUS_KEYS='',
                        BCRYPT_ROUNDS='4', CACHE_STORE='redis', SESSION_DRIVER='redis',
                        SESSION_COOKIE='b5_runtime_session', SESSION_SECURE_COOKIE='true', SESSION_DOMAIN='',
                        QUEUE_CONNECTION='redis', DB_QUEUE_CONNECTION='mysql', REDIS_QUEUE_RETRY_AFTER='240',
                        MAINTENANCE_CACHE_STORE='redis', RECEIVABLE_CHECK_CACHE_STORE='redis',
                        MAIL_MAILER='array', LOG_CHANNEL='single', DB_URL='', DB_CONNECTION='mysql',
                        DB_HOST='localhost', DB_PORT='3306', DB_SOCKET=str(self.root / 'mysql-socket/mysqld.sock'),
                        DB_DATABASE='production_runtime_only', DB_USERNAME='runtime_fixture',
                        DB_PASSWORD=self.database_password, REDIS_URL='', REDIS_CLIENT='phpredis',
                        REDIS_HOST=str(self.root / 'redis-socket/redis.sock'), REDIS_PORT='0',
                        REDIS_USERNAME='', REDIS_PASSWORD='', REDIS_DB='0', REDIS_CACHE_DB='1',
                        REDIS_PREFIX='b5-runtime:', CACHE_PREFIX='b5-runtime-cache:',
                        LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
                        APP_CONFIG_CACHE=str(storage / 'config.php'), APP_ROUTES_CACHE=str(storage / 'routes.php'),
                        APP_EVENTS_CACHE=str(storage / 'events.php'), APP_SERVICES_CACHE=str(storage / 'services.php'),
                        APP_PACKAGES_CACHE=str(storage / 'packages.php'), B5_ROOT=str(self.root), B5_REPO=str(self.repo),
                        B5_APP=str(self.application), B5_BOOTSTRAP=str(self.bootstrap))

    def _mkdir(self, path, mode=0o700):
        path.mkdir(parents=True, exist_ok=True)
        path.chmod(mode)
        os.chown(path, self.www.pw_uid, self.www.pw_gid)

    def _write(self, path, contents, www=False):
        path.write_text(contents)
        path.chmod(0o600)
        if www:
            os.chown(path, self.www.pw_uid, self.www.pw_gid)

    def _command(self, arguments, timeout=30, input=None, env=None):
        completed = subprocess.run(arguments, cwd=self.repo, env=env, input=input,
                                   capture_output=True, text=True, timeout=timeout)
        if completed.returncode:
            # Detailed output stays private: SQL/connection errors may include credentials.
            self._write(self.root / 'command-failure.log', completed.stdout + completed.stderr)
            raise RuntimeError('Private runtime command failed; inspect ' + str(self.root / 'command-failure.log'))
        return completed

    def _prepare_files(self):
        for folder in ['storage', 'storage/framework', 'storage/framework/views', 'storage/framework/sessions',
                       'storage/framework/cache', 'storage/framework/cache/data', 'storage/logs',
                       'storage/app', 'storage/app/private', 'application', 'application/bootstrap',
                       'application/bootstrap/cache', 'nginx-client', 'nginx-fastcgi', 'redis-data', 'logs']:
            self._mkdir(self.root / folder)
        for folder in ['mysql-socket', 'redis-socket']:
            self._mkdir(self.root / folder, 0o777)
        # The private parent remains 0700; the container's redis uid owns AOF files here.
        (self.root / 'redis-data').chmod(0o777)
        shutil.copytree(self.repo / 'public', self.root / 'public', symlinks=False,
                        ignore=shutil.ignore_patterns('.user.ini', '.env', '.git'))
        for entry in (self.root / 'public').rglob('*'):
            os.chown(entry, self.www.pw_uid, self.www.pw_gid)
        os.chown(self.root / 'public', self.www.pw_uid, self.www.pw_gid)
        for entry in self.repo.iterdir():
            if entry.name in ['bootstrap', 'public', 'storage'] or entry.name.startswith('.'):
                continue
            (self.application / entry.name).symlink_to(entry, target_is_directory=entry.is_dir())
        (self.application / 'public').symlink_to(self.root / 'public', target_is_directory=True)
        (self.application / 'storage').symlink_to(self.root / 'storage', target_is_directory=True)
        for name in ['app.php', 'providers.php']:
            shutil.copyfile(self.repo / 'bootstrap' / name, self.application / 'bootstrap' / name)
        bootstrap = "<?php\nrequire getenv('B5_REPO').'/vendor/autoload.php';\n$app=require getenv('B5_APP').'/bootstrap/app.php';\n$app->usePublicPath(getenv('B5_ROOT').'/public');\nreturn $app;\n"
        self._write(self.bootstrap, bootstrap, www=True)
        self._write(self.console, "<?php\n$app=require getenv('B5_BOOTSTRAP');\nexit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n", www=True)
        self._write(self.root / 'public/index.php', "<?php\n$app=require getenv('B5_BOOTSTRAP');\n$app->handleRequest(Illuminate\\Http\\Request::capture());\n", www=True)
        self._write(self.root / 'environment.json', json.dumps(self.env, indent=2))

    def _start_databases(self):
        for kind, image in [('mysql', 'mysql:8.0.35'), ('redis', 'redis:7-alpine')]:
            name = self.container_names[kind]
            command = ['docker', 'run', '--detach', '--pull=never', '--name', name, '--network', 'none',
                       '--label', 'laravel13idc.review=production-runtime',
                       '--label', 'laravel13idc.runtime-id=' + self.instance_id]
            if kind == 'mysql':
                command += ['--tmpfs', '/var/lib/mysql:rw,size=768m', '--mount',
                            'type=bind,source=' + str(self.root / 'mysql-socket') + ',target=/var/run/mysqld',
                            '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', image, '--performance-schema=OFF',
                            '--innodb-buffer-pool-size=32M', '--mysqlx=OFF', '--skip-log-bin']
            else:
                command += ['--mount', 'type=bind,source=' + str(self.root / 'redis-socket') + ',target=/socket',
                            '--mount', 'type=bind,source=' + str(self.root / 'redis-data') + ',target=/data',
                            image, 'redis-server', '--port', '0', '--unixsocket', '/socket/redis.sock',
                            '--unixsocketperm', '777', '--save', '', '--appendonly', 'yes',
                            '--appendfsync', 'always', '--maxmemory-policy', 'noeviction']
            self.containers[kind] = name
            self._command(command, timeout=30)
            info = json.loads(self._command(['docker', 'inspect', name]).stdout)[0]
            if info['HostConfig']['NetworkMode'] != 'none' or info['HostConfig']['PortBindings']:
                raise RuntimeError('Private container isolation failed')
            self.container_metadata[kind] = {'name': name, 'image': image, 'network': 'none', 'published_ports': 0}
        deadline = time.monotonic() + 90
        while True:
            probe = subprocess.run(['docker', 'exec', self.containers['mysql'], 'mysql', '-uroot', '-Nse', 'SELECT VERSION()'],
                                   capture_output=True, text=True, timeout=5)
            comm = subprocess.run(['docker', 'exec', self.containers['mysql'], 'cat', '/proc/1/comm'],
                                  capture_output=True, text=True, timeout=5)
            if probe.returncode == 0 and comm.stdout.strip() == 'mysqld':
                self.mysql_version = probe.stdout.strip()
                break
            if time.monotonic() >= deadline:
                raise RuntimeError('Private MySQL did not become ready')
            time.sleep(0.5)
        sql = ("CREATE DATABASE production_runtime_only; CREATE USER 'runtime_fixture'@'localhost' IDENTIFIED BY '"
               + self.database_password + "'; GRANT ALL ON production_runtime_only.* TO 'runtime_fixture'@'localhost';")
        self._command(['docker', 'exec', '-i', self.containers['mysql'], 'mysql', '-uroot'], input=sql)
        self._command(['docker', 'exec', self.containers['redis'], 'redis-cli', '-s', '/socket/redis.sock', 'PING'])
        settings = self._command(['docker', 'exec', self.containers['redis'], 'redis-cli', '-s', '/socket/redis.sock',
                                  'CONFIG', 'GET', 'appendonly', 'appendfsync', 'maxmemory-policy']).stdout.splitlines()
        self.redis_settings = dict(zip(settings[::2], settings[1::2]))
        if self.redis_settings != {'appendonly': 'yes', 'appendfsync': 'always', 'maxmemory-policy': 'noeviction'}:
            raise RuntimeError('Private Redis durability configuration mismatch')

    def _certificates(self):
        self._command([self.openssl, 'req', '-x509', '-newkey', 'rsa:2048', '-noenc', '-days', '2',
                       '-subj', '/CN=PHPIDC isolated runtime CA', '-keyout', str(self.root / 'ca.key'),
                       '-out', str(self.ca_file)])
        self._command([self.openssl, 'req', '-new', '-newkey', 'rsa:2048', '-noenc', '-subj', '/CN=127.0.0.1',
                       '-keyout', str(self.root / 'tls.key'), '-out', str(self.root / 'tls.csr')])
        extension = self.root / 'tls.extensions'
        self._write(extension, 'subjectAltName=IP:127.0.0.1\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n')
        self._command([self.openssl, 'x509', '-req', '-days', '2', '-in', str(self.root / 'tls.csr'),
                       '-CA', str(self.ca_file), '-CAkey', str(self.root / 'ca.key'), '-CAcreateserial',
                       '-extfile', str(extension), '-out', str(self.root / 'tls.crt')])
        for path in self.root.glob('*.key'):
            path.chmod(0o600)

    def start_process(self, name, arguments, as_www=True):
        if name in self.processes and self.processes[name].poll() is None:
            raise ValueError('Process is already running: ' + name)
        generation = 1 + sum(key.startswith(name + '#') for key in self.process_history)
        identity = name + '#' + str(generation)
        output = open(self.root / (name + '-' + str(generation) + '.log'), 'w')
        self.logs[identity] = output
        command = [self.runuser, '--user', 'www', '--', *map(str, arguments)] if as_www else list(map(str, arguments))
        process = subprocess.Popen(command, cwd=self.repo, env=self.env, stdout=output, stderr=output, start_new_session=True)
        self.processes[name] = process
        self.process_history[identity] = process
        return process

    def _start_web(self):
        fpm_conf = self.root / 'php-fpm.conf'
        self._write(fpm_conf, f'''[global]
pid = {self.root}/php-fpm.pid
error_log = {self.root}/php-fpm-error.log
daemonize = no
[isolated-runtime]
user = www
group = www
listen = {self.root}/php-fpm.sock
listen.owner = www
listen.group = www
listen.mode = 0600
pm = static
pm.max_children = 3
clear_env = no
catch_workers_output = yes
php_admin_flag[log_errors] = on
php_admin_value[error_log] = {self.root}/php-errors.log
php_admin_value[memory_limit] = 512M
''')
        nginx_conf = self.root / 'nginx.conf'
        fastcgi = pathlib.Path('/www/server/nginx/conf/fastcgi_params')
        if not fastcgi.is_file():
            raise RuntimeError('Expected installed Nginx fastcgi_params')
        self._write(nginx_conf, f'''user www www;
worker_processes 1;
pid {self.root}/nginx.pid;
error_log {self.root}/nginx-error.log info;
events {{ worker_connections 128; }}
http {{
    lua_package_path "/www/server/nginx/lib/lua/?.lua;;";
    include /www/server/nginx/conf/mime.types;
    default_type application/octet-stream;
    access_log {self.root}/nginx-access.log;
    client_body_temp_path {self.root}/nginx-client;
    fastcgi_temp_path {self.root}/nginx-fastcgi;
    server {{
        listen 127.0.0.1:{self.port} ssl;
        server_name 127.0.0.1;
        ssl_certificate {self.root}/tls.crt;
        ssl_certificate_key {self.root}/tls.key;
        root {self.root}/public;
        index index.php;
        location / {{ try_files $uri $uri/ /index.php?$query_string; }}
        location = /index.php {{
            include {fastcgi};
            fastcgi_param SCRIPT_FILENAME $document_root/index.php;
            fastcgi_param HTTPS on;
            fastcgi_param HTTP_PROXY "";
            fastcgi_pass unix:{self.root}/php-fpm.sock;
        }}
        location ~ \\.php$ {{ return 404; }}
        location ~ /\\. {{ deny all; }}
    }}
}}
''')
        self._command([self.fpm, '-t', '-y', str(fpm_conf)], env=self.env)
        self._command([self.nginx, '-t', '-c', str(nginx_conf), '-p', str(self.root)], env=self.env)
        self.start_process('php-fpm', [self.fpm, '-F', '-y', fpm_conf], as_www=False)
        self.start_process('nginx', [self.nginx, '-c', nginx_conf, '-p', self.root, '-g', 'daemon off;'], as_www=False)
        deadline = time.monotonic() + 30
        while True:
            try:
                with urllib.request.urlopen(self.base_url + '/up', context=self.ssl_context(), timeout=2) as response:
                    if response.status == 200:
                        return
            except (OSError, urllib.error.URLError):
                pass
            if time.monotonic() >= deadline or any(p.poll() is not None for p in self.processes.values()):
                raise RuntimeError('Private HTTPS/FPM did not become ready; inspect ' + str(self.root))
            time.sleep(0.2)

    def __enter__(self):
        try:
            if os.geteuid() != 0 or not all([self.nginx, self.openssl, self.runuser, pathlib.Path(self.fpm).is_file()]):
                raise RuntimeError('Root, www, Nginx, OpenSSL and PHP84 FPM are required')
            for image in ['mysql:8.0.35', 'redis:7-alpine']:
                self._command(['docker', 'image', 'inspect', image])
            self._prepare_files()
            self._start_databases()
            self._certificates()
            self._start_web()
            return self
        except BaseException:
            self.cleanup()
            raise

    def ssl_context(self):
        return ssl.create_default_context(cafile=str(self.ca_file))

    def run_php(self, path, *arguments, timeout=90):
        source = pathlib.Path(path).resolve()
        if source.is_relative_to(self.root) and source.is_file():
            os.chown(source, self.www.pw_uid, self.www.pw_gid)
            source.chmod(0o600)
        return subprocess.run([self.runuser, '--user', 'www', '--', self.php, str(path), *map(str, arguments)],
                              cwd=self.repo, env=self.env, capture_output=True, text=True, timeout=timeout)

    def run_cli(self, *arguments, expected=0, timeout=90):
        result = self.run_php(self.console, *arguments, timeout=timeout)
        if result.returncode != expected:
            self._write(self.root / 'cli-failure.log', result.stdout + result.stderr)
            raise RuntimeError('Private CLI failed; inspect ' + str(self.root / 'cli-failure.log'))
        return result.stdout + result.stderr

    def start_worker(self, name, queue, timeout):
        return self.start_process(name, [self.php, self.console, 'queue:work', 'redis', '--queue=' + queue,
                                        '--sleep=1', '--tries=' + ('1' if queue in ['maintenance', 'receivable-check'] else '3'),
                                        '--timeout=' + str(timeout), '--max-time=600'])

    def start_scheduler(self):
        return self.start_process('scheduler', [self.php, self.console, 'schedule:work', '--no-interaction'])

    def process_info(self):
        """Actual owned process groups, including PHP children behind runuser."""
        result = {}
        for name, process in self.process_history.items():
            result[name] = {'leader_pid': process.pid, 'running': process.poll() is None,
                            'exit_code': process.returncode, 'members': self._group_members(process.pid)}
        return result

    def _group_members(self, group_id):
        members = []
        for path in pathlib.Path('/proc').iterdir():
            if not path.name.isdigit():
                continue
            try:
                stat = (path / 'stat').read_text()
                fields = stat[stat.rfind(')') + 2:].split()
                # start_new_session made both group and session IDs this owned leader's PID.
                if fields[0] in ['Z', 'X'] or int(fields[2]) != group_id or int(fields[3]) != group_id:
                    continue
                members.append({'pid': int(path.name), 'uid': path.stat().st_uid, 'state': fields[0],
                                'pgid': group_id, 'sid': int(fields[3]), 'exe': os.readlink(path / 'exe'),
                                'cwd': os.readlink(path / 'cwd'),
                                'argv': [arg.decode(errors='replace') for arg in
                                         (path / 'cmdline').read_bytes().split(b'\0') if arg]})
            except (OSError, ProcessLookupError, ValueError, IndexError):
                continue
        return members

    def workers_alive(self):
        return {name: process.poll() is None for name, process in self.processes.items()}

    def stop_redis(self):
        self._command(['docker', 'stop', '--time', '10', self.containers['redis']], timeout=20)

    def start_redis(self):
        self._command(['docker', 'start', self.containers['redis']], timeout=20)
        deadline = time.monotonic() + 20
        while True:
            probe = subprocess.run(['docker', 'exec', self.containers['redis'], 'redis-cli', '-s',
                                    '/socket/redis.sock', 'PING'], capture_output=True, text=True, timeout=5)
            if probe.returncode == 0 and probe.stdout.strip() == 'PONG':
                return
            if time.monotonic() >= deadline:
                raise RuntimeError('Private Redis did not restart')
            time.sleep(0.2)

    def stop_process(self, name):
        process = self.processes[name]
        result = self._stop_group(process)
        if result['remaining_pids']:
            raise RuntimeError('Private owned process group did not exit: ' + name)
        return process.returncode

    def _stop_group(self, process):
        before = self._group_members(process.pid)
        for signum, grace in [(signal.SIGTERM, 8), (signal.SIGKILL, 5)]:
            if process.poll() is not None and not self._group_members(process.pid):
                break
            try:
                os.killpg(process.pid, signum)
            except ProcessLookupError:
                break
            deadline = time.monotonic() + grace
            while time.monotonic() < deadline:
                process.poll()
                if not self._group_members(process.pid):
                    break
                time.sleep(0.1)
        process.poll()
        remaining = self._group_members(process.pid)
        return {'pid': process.pid, 'pgid': process.pid, 'sid': process.pid,
                'exit_code': process.returncode, 'observed_pids_before': [row['pid'] for row in before],
                'remaining_pids': [row['pid'] for row in remaining]}

    def write_result(self, data):
        result = dict(data)
        result['runtime_environment'] = {'mysql_version': getattr(self, 'mysql_version', None),
                                         'redis_settings': getattr(self, 'redis_settings', None),
                                         'containers': self.container_metadata}
        self._write(self.root / 'result.json', json.dumps(result, ensure_ascii=False, indent=2))

    def cleanup(self):
        if self.cleanup_result is not None:
            return self.cleanup_result
        result = {'processes': {}, 'containers': {}}
        for name, process in reversed(list(self.process_history.items())):
            try:
                result['processes'][name] = self._stop_group(process)
            except (OSError, subprocess.TimeoutExpired) as error:
                result['processes'][name] = {'error': type(error).__name__}
        result['remaining_pids'] = sorted({row['pid'] for process in self.process_history.values()
                                          for row in self._group_members(process.pid)})
        with socket.socket() as probe:
            probe.settimeout(1)
            result['https_port_closed'] = probe.connect_ex(('127.0.0.1', self.port)) != 0
        result['https_port'] = self.port
        for handle in self.logs.values():
            handle.close()
        for kind, name in self.containers.items():
            inspected = subprocess.run(['docker', 'inspect', name], capture_output=True, text=True, timeout=10)
            if inspected.returncode != 0:
                result['containers'][kind] = {'name': name, 'removed': True, 'absent': True}
                continue
            labels = json.loads(inspected.stdout)[0]['Config']['Labels'] or {}
            if labels.get('laravel13idc.runtime-id') != self.instance_id:
                result['containers'][kind] = {'name': name, 'removed': False, 'absent': False, 'error': 'ownership_mismatch'}
                continue
            logs = subprocess.run(['docker', 'logs', name], capture_output=True, text=True, timeout=10)
            self._write(self.root / (kind + '-container.log'), logs.stdout + logs.stderr)
            removed = subprocess.run(['docker', 'rm', '--force', name], capture_output=True, text=True, timeout=20)
            absent = subprocess.run(['docker', 'inspect', name], capture_output=True, timeout=10).returncode != 0
            result['containers'][kind] = {'name': name, 'removed': removed.returncode == 0, 'absent': absent}
        self.cleanup_result = result
        self._write(self.root / 'cleanup.json', json.dumps(result, indent=2))
        return result

    def __exit__(self, exception_type, exception, traceback):
        result = self.cleanup()
        if exception_type is None and (any('error' in row for row in result['processes'].values())
                                       or result['remaining_pids'] or not result['https_port_closed']
                                       or any(not row['removed'] or not row['absent'] for row in result['containers'].values())):
            raise RuntimeError('Private runtime cleanup failed; inspect ' + str(self.root / 'cleanup.json'))
