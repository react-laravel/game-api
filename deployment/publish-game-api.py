#!/usr/bin/python3 -I
"""Non-root, single-application publisher. Root installs this file read-only."""

import base64
import errno
import fcntl
import hashlib
import http.client
import json
import os
from pathlib import Path, PurePosixPath
from urllib.parse import quote
import pwd
import re
import shutil
import socket
import ssl
import stat
import subprocess
import sys
import tarfile
import tempfile
import time

DEPLOY_USER = "game-api-deploy"
APP_ROOT = Path("/var/www/game-api")
RELEASES = APP_ROOT / "releases"
SHARED = APP_ROOT / "shared"
STORAGE = SHARED / "storage"
PUBLIC_HEALTH = Path("/etc/dogeow-tests/game-publish-health.json")
STATE = Path("/var/lib/game-api-deploy/state")
INCOMING = STATE / "incoming"
LEGACY_MARKER = Path("/etc/dogeow-tests/game-api-legacy-retired")
LEGACY_RELEASE = "source-20260724134615"
SUDO = "/usr/bin/sudo"
SYSTEMCTL = "/usr/bin/systemctl"
UNITS = (
    "dogeow-test-game-fpm.service",
    "dogeow-test-game-queue.service",
    "dogeow-test-game-reverb.service",
)
API_HOST = "game-api.dogeow.com"
WS_HOST = "game.dogeow.com"
MAX_ARCHIVE = 64 * 1024 * 1024
MAX_UNPACKED = 128 * 1024 * 1024
MAX_MEMBER = 64 * 1024 * 1024
MAX_MEMBERS = 100000
MIN_FREE_DISK = 1024 * 1024 * 1024
SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
RUNTIME_DIRS = {"app", "bootstrap", "config", "database", "lang", "public", "resources", "routes", "vendor"}
ROOT_FILES = {"artisan", "composer.json", "composer.lock"}
ROOT_REQUIRED = {"artisan", "bootstrap/app.php", "composer.lock", "public/index.php", "vendor/autoload.php", "resources/views/welcome.blade.php"}
PRIVATE_NAMES = {".git", ".ssh", ".codex", ".npmrc", ".gitconfig", "auth.json", "id_rsa", "id_ed25519"}


class DeployError(RuntimeError):
    pass


class RuntimeFiles(dict):
    def __init__(self, files, expanded_bytes):
        super().__init__(files)
        self.expanded_bytes = expanded_bytes


def require(condition, message):
    if not condition:
        raise DeployError(message)


def check_sha(revision):
    require(isinstance(revision, str) and SHA_RE.fullmatch(revision), "Invalid release SHA.")
    return revision


def allowed_member(name):
    path = PurePosixPath(name)
    parts = path.parts
    require(name and not path.is_absolute() and name == path.as_posix(), "Noncanonical archive path.")
    require(all(part not in ("", ".", "..") for part in parts), "Unsafe archive path.")
    require(not any(part.startswith(".env") or part in PRIVATE_NAMES for part in parts), "Private file in archive.")
    require(parts[0] in RUNTIME_DIRS or (len(parts) == 1 and name in ROOT_FILES), "Unexpected runtime path.")
    require(not (len(parts) >= 2 and parts[:2] == ("bootstrap", "cache")), "Cached bootstrap data is forbidden.")
    require(not (len(parts) >= 2 and parts[:2] == ("public", "storage")), "Persistent storage must not be uploaded.")
    require(not (len(parts) >= 3 and parts[:3] == ("public", "vendor", "telescope")), "Debug assets are forbidden.")
    return path


def checked_directory(path, owner=None, root_immutable=False):
    info = path.lstat()
    require(stat.S_ISDIR(info.st_mode), "Expected a real directory.")
    if owner is not None:
        require(info.st_uid == owner, "Directory owner mismatch.")
    if root_immutable:
        require(info.st_uid == 0 and not info.st_mode & 0o022, "Protected root directory is writable.")


def read_root_marker(path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(fd, "rb") as file:
        info = os.fstat(file.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
                "Legacy retirement approval is not root-protected.")
        return file.read(64).decode("ascii").strip()


def current_release():
    link = RELEASES / "current"
    require(link.is_symlink(), "The managed current link is missing.")
    value = os.readlink(link)
    require(value == LEGACY_RELEASE or SHA_RE.fullmatch(value), "Unexpected current release target.")
    checked_directory(RELEASES / value)
    return value


def switch_current(revision):
    # Only the inner releases/current link changes. Root owns the outer link.
    temporary = RELEASES / (".current-" + str(os.getpid()))
    require(not os.path.lexists(temporary), "Temporary activation path already exists.")
    os.symlink(revision, temporary)
    try:
        os.replace(temporary, RELEASES / "current")
    finally:
        if temporary.is_symlink():
            temporary.unlink()


def runtime_environment():
    return {
        "PATH": "/usr/bin:/bin",
        "HOME": str(STATE),
        "LANG": "C.UTF-8",
        "LC_ALL": "C.UTF-8",
    }


def run_silent(arguments, cwd=None, timeout=90):
    result = subprocess.run(arguments, cwd=cwd, env=runtime_environment(),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=timeout, check=False)
    require(result.returncode == 0, "A deployment command failed; private command output was suppressed.")
    return result.stdout


def restart_services():
    # The sudoers file authorizes exactly these three argument lists.
    for unit in UNITS:
        run_silent([SUDO, "-n", SYSTEMCTL, "restart", unit], timeout=750)
        run_silent([SYSTEMCTL, "is-active", "--quiet", unit], timeout=15)


def inspect_archive(archive, revision):
    files = {}
    members = []
    total = 0
    manifest = None
    with tarfile.open(archive, "r:gz") as source:
        for member in source:
            require(len(members) < MAX_MEMBERS, "Too many archive members.")
            require(member.isfile(), "Archive links, devices and directory entries are forbidden.")
            require(member.size >= 0 and member.size <= MAX_MEMBER, "Archive member is too large.")
            require(member.name not in files, "Duplicate archive member.")
            total += member.size
            require(total <= MAX_UNPACKED, "Expanded runtime exceeds its limit.")
            if member.name == "deploy-manifest.json":
                require(manifest is None and member.size <= 8 * 1024 * 1024, "Invalid manifest.")
                manifest = json.loads(source.extractfile(member).read())
            else:
                allowed_member(member.name)
            files[member.name] = member.size
            members.append(member.name)
    require(isinstance(manifest, dict) and manifest.get("schema") == 1 and manifest.get("revision") == revision,
            "Runtime manifest does not match this release.")
    expected = manifest.get("files")
    require(isinstance(expected, dict) and set(expected) == set(files) - {"deploy-manifest.json"},
            "Runtime manifest file list mismatch.")
    require(ROOT_REQUIRED <= set(expected), "Required runtime files are missing.")
    for name, digest in expected.items():
        require(isinstance(digest, str) and re.fullmatch(r"[0-9a-f]{64}", digest), "Invalid runtime checksum.")
    return RuntimeFiles(expected, total)


def extract_archive(archive, stage, expected):
    with tarfile.open(archive, "r:gz") as source:
        for member in source:
            if member.name == "deploy-manifest.json":
                continue
            require(member.isfile() and member.name in expected, "Runtime changed after inspection.")
            path = stage.joinpath(*allowed_member(member.name).parts)
            path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
            digest = hashlib.sha256()
            with source.extractfile(member) as content, os.fdopen(fd, "wb") as target:
                copied = 0
                while True:
                    chunk = content.read(1024 * 1024)
                    if not chunk:
                        break
                    copied += len(chunk)
                    require(copied <= member.size and copied <= MAX_MEMBER, "Runtime member size changed.")
                    digest.update(chunk)
                    target.write(chunk)
                os.fchmod(target.fileno(), 0o644)
            require(copied == member.size and digest.hexdigest() == expected[member.name], "Runtime checksum failed.")
    (stage / "bootstrap/cache").mkdir(mode=0o755, parents=True)
    os.symlink(SHARED / ".env", stage / ".env")
    os.symlink(STORAGE, stage / "storage")
    os.symlink(STORAGE / "app/public", stage / "public/storage")
    for directory, _, _ in os.walk(stage, followlinks=False):
        os.chmod(directory, 0o755)


class LocalHTTPS(http.client.HTTPSConnection):
    def connect(self):
        connection = socket.create_connection(("127.0.0.1", 443), timeout=10)
        self.sock = self._context.wrap_socket(connection, server_hostname=self.host)


def request(host, path, headers=None):
    connection = LocalHTTPS(host, timeout=10, context=ssl.create_default_context())
    try:
        connection.request("GET", path, headers={"Host": host, "Accept": "application/json", **(headers or {})})
        response = connection.getresponse()
        body = response.read(4 * 1024 * 1024 + 1)
        require(len(body) <= 4 * 1024 * 1024, "Health response exceeds its limit.")
        return response.status, {key.lower(): value for key, value in response.getheaders()}, body
    finally:
        connection.close()


def read_public_health():
    # Only this public frontend app key is exposed to the publisher. No .env,
    # PHP bootstrap, database client or private cache is read by this account.
    fd = os.open(PUBLIC_HEALTH, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(fd, "rb") as source:
        info = os.fstat(source.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
                "Public health parameters must be root-owned and read-only.")
        content = source.read(2049)
    require(len(content) <= 2048, "Public health parameters are too large.")
    payload = json.loads(content)
    require(isinstance(payload, dict) and set(payload) == {"ws_app_key"},
            "Health parameters may contain only the public WebSocket app key.")
    key = payload["ws_app_key"]
    require(isinstance(key, str) and 0 < len(key) <= 512 and not any(ord(c) < 32 for c in key),
            "Public WebSocket app key is unavailable.")
    return key


def websocket_health(key):
    connection = LocalHTTPS(WS_HOST, timeout=10, context=ssl.create_default_context())
    try:
        nonce = base64.b64encode(os.urandom(16)).decode("ascii")
        path = "/app/" + quote(key, safe="") + "?protocol=7&client=js&version=8.4.0&flash=false"
        connection.request("GET", path, headers={
            "Host": WS_HOST, "Origin": "https://game.dogeow.com",
            "Connection": "Upgrade", "Upgrade": "websocket",
            "Sec-WebSocket-Version": "13", "Sec-WebSocket-Key": nonce,
        })
        response = connection.getresponse()
        require(response.status == 101, "WebSocket upgrade failed.")
        expected = base64.b64encode(hashlib.sha1((nonce + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest()).decode()
        require(response.getheader("Sec-WebSocket-Accept") == expected, "WebSocket accept check failed.")
        # A valid key must receive Reverb's connection event, not a Pusher error.
        stream = response.fp
        prefix = stream.read(2)
        require(len(prefix) == 2 and prefix[0] & 0x0F == 1 and not prefix[1] & 0x80, "Invalid Reverb opening frame.")
        size = prefix[1] & 0x7F
        if size == 126:
            size = int.from_bytes(stream.read(2), "big")
        elif size == 127:
            size = int.from_bytes(stream.read(8), "big")
        require(size <= 16384, "Unexpected Reverb opening frame size.")
        message = json.loads(stream.read(size))
        require(message.get("event") == "pusher:connection_established", "Reverb did not accept the app key.")
    finally:
        connection.close()


def health(release):
    key = read_public_health()
    status, _, _ = request(API_HOST, "/")
    require(status == 200, "The real API root view must render from this release.")
    status, _, _ = request(API_HOST, "/.env")
    require(status == 403, "Private environment boundary failed.")
    status, _, _ = request(API_HOST, "/up")
    require(status == 200, "Laravel health endpoint failed.")
    status, headers, body = request(API_HOST, "/api/user", {"Origin": "https://game.dogeow.com"})
    require(status == 401, "Anonymous API authentication boundary failed.")
    require(headers.get("access-control-allow-origin") == "https://game.dogeow.com", "API CORS failed.")
    require(isinstance(json.loads(body), dict), "API authentication response is not JSON.")
    status, _, _ = request(WS_HOST, "/api/user")
    require(status == 401, "Frontend same-origin API rewrite failed.")
    status, _, _ = request(WS_HOST, "/sanctum/csrf-cookie")
    require(status == 204, "Frontend same-origin session path failed.")
    websocket_health(key)


def wait_for_health(release):
    last = None
    for attempt in range(8):
        try:
            health(release)
            return
        except (DeployError, OSError, ValueError, http.client.HTTPException) as error:
            last = error
            if attempt < 7:
                time.sleep(1)
    raise DeployError("API health checks failed after service startup.") from last


def remove_old_release(name):
    require(name == LEGACY_RELEASE or SHA_RE.fullmatch(name), "Unknown cleanup target.")
    path = RELEASES / name
    checked_directory(path)
    # Python uses fd-based symlink-safe rmtree; never follow the storage/.env links.
    require(shutil.rmtree.avoids_symlink_attacks, "Safe directory cleanup is unavailable.")
    shutil.rmtree(path)


def publish(revision):
    check_sha(revision)
    old = current_release()
    require(old != revision, "This release is already active.")
    if old == LEGACY_RELEASE:
        try:
            legacy_approval = read_root_marker(LEGACY_MARKER)
        except (OSError, UnicodeError) as error:
            raise DeployError("Original Game release retirement has not been approved by the administrator.") from error
        require(legacy_approval == LEGACY_RELEASE,
                "Original Game release may still be referenced by queued jobs; administrator approval is required.")
    require(not os.path.lexists(RELEASES / revision), "Release directory already exists.")
    # Unknown content must not be silently deleted to satisfy a release-count claim.
    allowed = {old, "current"}
    require(all(path.name in allowed or path.name.startswith(".") for path in RELEASES.iterdir()),
            "Unexpected existing release directory; review it before publishing.")
    archive = INCOMING / (revision + ".tar.gz")
    info = archive.lstat()
    require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid() and info.st_size <= MAX_ARCHIVE,
            "Incoming runtime permissions are invalid.")
    try:
        expected = inspect_archive(archive, revision)
        require(shutil.disk_usage(RELEASES).free >= expected.expanded_bytes + MIN_FREE_DISK,
                "Insufficient disk space: preserve at least 1 GiB free after extracting this runtime.")
    except BaseException:
        archive.unlink()
        raise
    stage = Path(tempfile.mkdtemp(prefix=".prepare-" + revision + "-", dir=RELEASES))
    accepted = False
    activated = False
    final = RELEASES / revision
    try:
        extract_archive(archive, stage, expected)
        os.rename(stage, final)
        # No PHP/Artisan execution by the publisher. The runtime-only service
        # preparer checks schema and builds private caches as the app user.
        switch_current(revision)
        activated = True
        restart_services()
        wait_for_health(final)
        accepted = True
        remove_old_release(old)
        print(json.dumps({"published": revision, "healthy": True, "retained_releases": 1,
                          "database_migrated": False, "storage_replaced": False}))
    except BaseException:
        if activated and not accepted:
            switch_current(old)
            restart_services()
        raise
    finally:
        if stage.exists():
            shutil.rmtree(stage)
        if not accepted and final.exists() and current_release() != revision:
            remove_old_release(revision)
        if archive.exists():
            archive.unlink()


def receive(revision, source):
    check_sha(revision)
    # Serialize commands with the publish lock and keep at most one upload.
    # A failed CI job must not accumulate arbitrary 200 MiB archives on C.
    for previous in INCOMING.iterdir():
        require(re.fullmatch(r"[0-9a-f]{40}\.tar\.gz", previous.name), "Unknown incoming entry.")
        info = previous.lstat()
        require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid(), "Unsafe incoming entry.")
        previous.unlink()
    require(shutil.disk_usage(INCOMING).free >= MAX_ARCHIVE + MIN_FREE_DISK,
            "Insufficient upload space: preserve the maximum upload allowance and 1 GiB free.")
    target = INCOMING / (revision + ".tar.gz")
    fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, "wb") as destination:
            total = 0
            while True:
                part = source.read(1024 * 1024)
                if not part:
                    break
                total += len(part)
                require(total <= MAX_ARCHIVE, "Compressed runtime exceeds its upload limit.")
                destination.write(part)
            require(total > 0, "Empty runtime upload.")
        print(json.dumps({"received": revision, "compressed_bytes": total}))
    except BaseException:
        target.unlink(missing_ok=True)
        raise


def main(arguments):
    os.umask(0o027)
    require(os.geteuid() != 0 and os.geteuid() == pwd.getpwnam(DEPLOY_USER).pw_uid,
            "This program only runs as the non-root API deploy account.")
    require(len(arguments) == 2 and arguments[0] in {"receive", "publish", "validate"}, "Invalid deployment command.")
    command, revision = arguments
    check_sha(revision)
    checked_directory(APP_ROOT, root_immutable=True)
    require((APP_ROOT / "current").is_symlink() and os.readlink(APP_ROOT / "current") == "releases/current",
            "The protected outer current link is not configured.")
    checked_directory(SHARED, root_immutable=True)
    checked_directory(INCOMING, owner=os.geteuid())
    lock = STATE / "publish.lock"
    fd = os.open(lock, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(fd, "r+") as file:
        require(stat.S_ISREG(os.fstat(file.fileno()).st_mode), "Invalid publish lock.")
        fcntl.flock(file, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if command == "receive":
            receive(revision, sys.stdin.buffer)
        elif command == "validate":
            require(current_release() == revision or current_release() == LEGACY_RELEASE,
                    "Validation SHA is not the current or bootstrap release.")
            wait_for_health(APP_ROOT / "current")
            print(json.dumps({"healthy": True, "current": current_release(), "database_migrated": False}))
        else:
            publish(revision)


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    except BaseException as error:
        if isinstance(error, DeployError):
            print(str(error), file=sys.stderr)
        else:
            print("API publish did not complete; inspect protected deployment logs.", file=sys.stderr)
        sys.exit(1)
