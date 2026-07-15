#!/usr/bin/env python3
"""Deploy a generated package over SFTP without opening FileZilla."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import posixpath
import socket
import sys
from pathlib import Path

import paramiko


ROOT = Path(__file__).resolve().parents[1]
KNOWN_HOSTS = ROOT / "tools" / "sftp_known_hosts"


def load_env(path: Path) -> None:
    if not path.is_file():
        raise RuntimeError(f"Missing environment file: {path}")
    for raw in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def setting(name: str, default: str | None = None) -> str:
    value = os.environ.get(name, default)
    if value is None or value == "":
        raise RuntimeError(f"Missing setting: {name}")
    return value


def server_details() -> tuple[str, int, str, str]:
    return (
        setting("ARTIST_SITE_SFTP_HOST"),
        int(setting("ARTIST_SITE_SFTP_PORT", "22")),
        setting("ARTIST_SITE_SFTP_USER"),
        setting("ARTIST_SITE_SFTP_PASSWORD"),
    )


def fingerprint(key: paramiko.PKey) -> str:
    digest = hashlib.sha256(key.asbytes()).digest()
    import base64
    return "SHA256:" + base64.b64encode(digest).decode("ascii").rstrip("=")


def scan_host_key() -> None:
    host, port, _, _ = server_details()
    sock = socket.create_connection((host, port), timeout=15)
    transport = paramiko.Transport(sock)
    try:
        transport.start_client(timeout=15)
        key = transport.get_remote_server_key()
        marker = host if port == 22 else f"[{host}]:{port}"
        KNOWN_HOSTS.write_text(
            f"{marker} {key.get_name()} {key.get_base64()}\n", encoding="utf-8"
        )
        print(f"Server key saved: {fingerprint(key)}")
    finally:
        transport.close()


def connect() -> paramiko.SSHClient:
    if not KNOWN_HOSTS.is_file():
        raise RuntimeError("Server key not registered. Run once with --scan-host-key.")
    host, port, user, password = server_details()
    client = paramiko.SSHClient()
    client.load_host_keys(str(KNOWN_HOSTS))
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(
        hostname=host,
        port=port,
        username=user,
        password=password,
        look_for_keys=False,
        allow_agent=False,
        timeout=20,
    )
    return client


def mkdirs(sftp: paramiko.SFTPClient, remote_dir: str) -> None:
    current = "/" if remote_dir.startswith("/") else ""
    for part in remote_dir.strip("/").split("/"):
        current = posixpath.join(current, part)
        try:
            sftp.stat(current)
        except FileNotFoundError:
            sftp.mkdir(current)


def remote_hash(sftp: paramiko.SFTPClient, path: str) -> str | None:
    try:
        digest = hashlib.sha256()
        with sftp.open(path, "rb") as handle:
            while chunk := handle.read(1024 * 1024):
                digest.update(chunk)
        return digest.hexdigest()
    except FileNotFoundError:
        return None


def deploy(package: Path, dry_run: bool) -> None:
    manifest_path = package / "deploy-manifest.json"
    entries = json.loads(manifest_path.read_text(encoding="utf-8-sig"))
    client = connect()
    changed = 0
    try:
        with client.open_sftp() as sftp:
            for entry in entries:
                local = package / Path(entry["local_path"])
                remote = entry["remote_path"]
                local_hash = hashlib.sha256(local.read_bytes()).hexdigest()
                if local_hash != entry["sha256"]:
                    raise RuntimeError(f"Package hash mismatch: {entry['local_path']}")
                if remote_hash(sftp, remote) == local_hash:
                    print(f"UNCHANGED {entry['local_path']}")
                    continue
                changed += 1
                if dry_run:
                    print(f"WOULD_UPLOAD {entry['local_path']}")
                    continue
                mkdirs(sftp, posixpath.dirname(remote))
                temporary = remote + ".uploading"
                sftp.put(str(local), temporary)
                try:
                    sftp.posix_rename(temporary, remote)
                except OSError:
                    try:
                        sftp.remove(remote)
                    except FileNotFoundError:
                        pass
                    sftp.rename(temporary, remote)
                if remote_hash(sftp, remote) != local_hash:
                    raise RuntimeError(f"Remote verification failed: {entry['local_path']}")
                print(f"UPLOADED {entry['local_path']}")
    finally:
        client.close()
    print(f"RESULT files={len(entries)} changed={changed} mode={'dry-run' if dry_run else 'deploy'}")


def sync_shared_secret() -> None:
    secret = setting("ARTWORK_SYNC_SHARED_SECRET")
    if len(secret) < 32:
        raise RuntimeError("ARTWORK_SYNC_SHARED_SECRET must contain at least 32 characters.")
    remote_root = setting("ARTIST_SITE_SFTP_ROOT").rstrip("/")
    remote_path = remote_root + "/.env"
    client = connect()
    try:
        with client.open_sftp() as sftp:
            try:
                with sftp.open(remote_path, "rb") as handle:
                    current = handle.read().decode("utf-8-sig")
            except FileNotFoundError:
                current = ""
            lines = current.splitlines()
            replacement = "ARTWORK_SYNC_SHARED_SECRET=" + secret
            found = False
            updated = []
            for line in lines:
                if line.strip().startswith("ARTWORK_SYNC_SHARED_SECRET="):
                    if not found:
                        updated.append(replacement)
                        found = True
                else:
                    updated.append(line)
            if not found:
                updated.append(replacement)
            body = ("\n".join(updated).rstrip() + "\n").encode("utf-8")
            temporary = remote_path + ".uploading"
            with sftp.open(temporary, "wb") as handle:
                handle.write(body)
            try:
                sftp.posix_rename(temporary, remote_path)
            except OSError:
                sftp.remove(remote_path)
                sftp.rename(temporary, remote_path)
        print("Shared secret installed in website production environment.")
    finally:
        client.close()


def main() -> int:
    parser = argparse.ArgumentParser()
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument("--scan-host-key", action="store_true")
    action.add_argument("--dry-run", action="store_true")
    action.add_argument("--deploy", action="store_true")
    action.add_argument("--sync-shared-secret", action="store_true")
    parser.add_argument("--package", type=Path, default=ROOT / "deploy" / "admin-v2")
    args = parser.parse_args()
    try:
        load_env(ROOT / ".env")
        if args.scan_host_key:
            scan_host_key()
        elif args.sync_shared_secret:
            sync_shared_secret()
        else:
            deploy(args.package.resolve(), dry_run=args.dry_run)
        return 0
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
