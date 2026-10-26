#!/usr/bin/env python3
"""Build a dependency-complete runtime with no server state or credentials."""

import hashlib
import io
import json
from pathlib import Path
import re
import stat
import sys
import tarfile

ROOT_FILES = {"artisan", "composer.json", "composer.lock"}
ROOT_DIRS = {"app", "bootstrap", "config", "database", "lang", "public", "resources", "routes", "vendor"}
SKIP_COMPONENTS = {".git", ".svn", ".ssh", ".codex", ".npmrc", ".gitconfig", "auth.json", "id_rsa", "id_ed25519"}
REQUIRED = {"artisan", "bootstrap/app.php", "composer.lock", "public/index.php", "vendor/autoload.php", "resources/views/welcome.blade.php"}
MAX_FILE = 64 * 1024 * 1024
MAX_TOTAL = 128 * 1024 * 1024
PRIVATE_MARKER = re.compile(rb"-----BEGIN (?:[A-Z0-9]+ )?PRIVATE KEY-----")
# Cryptographic libraries contain PEM header/footer constants for parsing and
# serializing keys. A header plus an encoded body is key material; a formatting
# constant alone is not a credential. Application files remain conservative.
PRIVATE_MATERIAL = re.compile(
    rb"-----BEGIN (?:[A-Z0-9]+ )?PRIVATE KEY-----[ \t\r\n]*"
    rb"(?:(?:Proc-Type|DEK-Info):[^\r\n]*[\r\n]+[ \t\r\n]*)*"
    rb"[A-Za-z0-9+/]{32,}={0,2}"
)
VENDOR_READMES = {"readme", "readme.md", "readme.markdown", "readme.rst", "readme.txt"}


def contains_private_key(data, name):
    if not PRIVATE_MARKER.search(data):
        return False
    if not name.startswith("vendor/"):
        return True
    # Also reject actual PEM bodies embedded in JSON/PHP escaped strings.
    normalized = data
    for escaped in (b"\\\\r\\\\n", b"\\\\n", b"\\r\\n", b"\\n"):
        normalized = normalized.replace(escaped, b"\n")
    return PRIVATE_MATERIAL.search(normalized) is not None



def skip_path(relative):
    parts = relative.parts
    return (
        any(part.startswith(".env") or part in SKIP_COMPONENTS for part in parts)
        or parts[:2] == ("bootstrap", "cache")
        or parts[:3] == ("public", "vendor", "telescope")
        or parts[:2] == ("public", "storage")
        or (parts[0] == "vendor" and parts[-1].lower() in VENDOR_READMES)
    )


def runtime_files(root):
    result = []
    for name in sorted(ROOT_FILES | ROOT_DIRS):
        path = root / name
        if path.is_symlink():
            raise ValueError("Runtime roots must not be symbolic links: " + name)
        if not path.exists():
            continue
        candidates = [path] if name in ROOT_FILES else path.rglob("*")
        for candidate in candidates:
            relative = candidate.relative_to(root)
            if skip_path(relative):
                continue
            info = candidate.lstat()
            if stat.S_ISDIR(info.st_mode):
                continue
            if not stat.S_ISREG(info.st_mode):
                raise ValueError("Runtime links and special files are forbidden: " + relative.as_posix())
            if info.st_size > MAX_FILE:
                raise ValueError("Runtime member too large: " + relative.as_posix())
            result.append((relative.as_posix(), candidate))
    if not REQUIRED <= {name for name, _ in result}:
        raise ValueError("Required runtime files are missing; install locked production dependencies first.")
    return sorted(result)


def package(root, destination, revision):
    if not re.fullmatch(r"[0-9a-f]{40}", revision):
        raise ValueError("A full commit SHA is required.")
    if destination.resolve().is_relative_to(root.resolve()):
        raise ValueError("Write the runtime archive outside the checkout.")
    manifest = {"schema": 1, "revision": revision, "files": {}}
    total = 0
    try:
        with tarfile.open(destination, "w:gz", compresslevel=6) as archive:
            for name, source in runtime_files(root):
                data = source.read_bytes()
                if contains_private_key(data, name):
                    raise ValueError("A private key was found in a runtime file.")
                total += len(data)
                if total > MAX_TOTAL:
                    raise ValueError("Runtime expanded size limit exceeded.")
                manifest["files"][name] = hashlib.sha256(data).hexdigest()
                member = tarfile.TarInfo(name)
                member.size = len(data)
                member.mode = 0o644
                member.uid = member.gid = 0
                member.mtime = 0
                archive.addfile(member, io.BytesIO(data))
            data = json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode()
            member = tarfile.TarInfo("deploy-manifest.json")
            member.size = len(data)
            member.mode = 0o644
            member.mtime = 0
            archive.addfile(member, io.BytesIO(data))
        if destination.stat().st_size > 64 * 1024 * 1024:
            raise ValueError("Compressed runtime size limit exceeded.")
    except BaseException:
        destination.unlink(missing_ok=True)
        raise
    return {"revision": revision, "files": len(manifest["files"]),
            "expanded_bytes": total, "compressed_bytes": destination.stat().st_size}


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Usage: package-runtime.py OUTPUT.tar.gz COMMIT_SHA")
    print(json.dumps(package(Path.cwd(), Path(sys.argv[1]), sys.argv[2])))
