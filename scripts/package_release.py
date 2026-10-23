#!/usr/bin/env python3
"""Build a deployable, self-contained PHP application ZIP from an already-built tree.

Run only after Composer and npm have completed successfully. This script uses a
strict runtime-file allowlist; it never archives database configuration, sessions,
.git metadata, node_modules, test data, or the build cache.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
import zipfile
from pathlib import Path, PurePosixPath

ARCHIVE_ROOT = "apple-id-management"
REQUIRED = (
    "public/index.php",
    "public/install.php",
    "public/.htaccess",
    "public/assets/css/app.css",
    "public/assets/js/app.js",
    "public/assets/js/setup.js",
    "public/assets/vendor/tabler/tabler.rtl.min.css",
    "public/assets/vendor/tabler/tabler.min.js",
    "public/assets/vendor/apexcharts/apexcharts.min.js",
    "public/assets/vendor/vazirmatn/Vazirmatn-Regular.woff2",
    "public/assets/vendor/persian-datepicker/persian-datepicker.min.js",
    "public/assets/vendor/hcg/hcg-select.js",
    "src/common.php",
    "src/ExportService.php",
    "src/vendor/jdf.php",
    "vendor/autoload.php",
    "vendor/mpdf/mpdf/composer.json",
    "vendor/phpoffice/phpspreadsheet/composer.json",
    "storage/fonts/Vazirmatn-Regular.ttf",
    "storage/fonts/Vazirmatn-Bold.ttf",
    "INSTALL-FA.md",
)
BANNED_NAMES = {
    ".env", ".env.local", ".env.production", "config.php", "installed.lock",
    "install.mutex", "id_rsa", "id_ed25519", "session.save_path",
}
BANNED_SUFFIXES = {".log", ".sql", ".sqlite", ".db", ".bak", ".pem", ".key"}
# Keys/passwords are not copied from storage; only the explicitly listed fonts
# and directory markers are allowed inside the distributable storage folder.


def files_for_release(root: Path) -> list[Path]:
    paths: list[Path] = []
    for folder in ("public", "src", "vendor"):
        directory = root / folder
        if not directory.is_dir() or directory.is_symlink():
            raise RuntimeError(f"Required runtime directory is missing/unsafe: {folder}")
        for candidate in directory.rglob("*"):
            relative = candidate.relative_to(root)
            if candidate.is_symlink():
                raise RuntimeError(f"Refusing to package a symlink: {relative}")
            if not candidate.is_file():
                continue
            if any(part in {".git", "node_modules", "__pycache__"} for part in relative.parts):
                raise RuntimeError(f"Unexpected build directory inside runtime tree: {relative}")
            if candidate.name.lower() in BANNED_NAMES or candidate.suffix.lower() in BANNED_SUFFIXES:
                raise RuntimeError(f"Refusing to package possible confidential file: {relative}")
            paths.append(candidate)

    storage = root / "storage"
    if not storage.is_dir() or storage.is_symlink():
        raise RuntimeError("Expected storage directory is missing/unsafe")
    for relative in ("storage/.htaccess", "storage/.gitkeep"):
        file = root / relative
        if file.is_file():
            if file.is_symlink():
                raise RuntimeError(f"Unsafe storage symlink: {relative}")
            paths.append(file)
    fonts = storage / "fonts"
    if not fonts.is_dir() or fonts.is_symlink():
        raise RuntimeError("Build did not generate the storage/fonts directory")
    for font in fonts.iterdir():
        if font.is_symlink() or not font.is_file() or font.name not in {
            "Vazirmatn-Regular.ttf", "Vazirmatn-Bold.ttf",
        }:
            raise RuntimeError(f"Unexpected file in storage/fonts: {font.name}")
        paths.append(font)

    guide = root / "INSTALL-FA.md"
    if guide.is_symlink() or not guide.is_file():
        raise RuntimeError("Deployment guide INSTALL-FA.md is missing")
    paths.append(guide)

    paths = sorted(set(paths), key=lambda path: path.relative_to(root).as_posix())
    included = {path.relative_to(root).as_posix() for path in paths}
    missing = [path for path in REQUIRED if path not in included]
    if missing:
        raise RuntimeError("Build incomplete. Missing required runtime files:\n- " + "\n- ".join(missing))
    if not any(path.startswith("vendor/composer/") for path in included):
        raise RuntimeError("Composer autoloader metadata missing")
    return paths


def main() -> int:
    parser = argparse.ArgumentParser(description="Create a prebuilt deployment ZIP and SHA-256 checksum.")
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--output", type=Path, default=Path("dist"))
    parser.add_argument("--version", required=True, help="Commit SHA or trusted release version")
    args = parser.parse_args()
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", args.version):
        parser.error("Version contains unsupported filename characters")

    root = args.root.resolve(strict=True)
    paths = files_for_release(root)
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    filename = f"apple-id-management-{args.version}.zip"
    destination = output / filename
    with zipfile.ZipFile(destination, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6,
                         allowZip64=True, strict_timestamps=False) as archive:
        for file in paths:
            name = PurePosixPath(ARCHIVE_ROOT) / file.relative_to(root).as_posix()
            archive.write(file, str(name))

    with zipfile.ZipFile(destination) as archive:
        bad_member = archive.testzip()
        if bad_member:
            raise RuntimeError(f"ZIP integrity check failed: {bad_member}")
        if len(archive.namelist()) != len(paths):
            raise RuntimeError("ZIP contains duplicate/missing entries")
    checksum = hashlib.sha256(destination.read_bytes()).hexdigest()
    (output / (filename + ".sha256")).write_text(f"{checksum}  {filename}\n", encoding="utf-8")
    summary = {
        "package": filename,
        "sha256": checksum,
        "files": len(paths),
        "bytes": destination.stat().st_size,
        "version": args.version,
        "runtime": "PHP 8.2+ with MySQL; no Node.js/Composer needed on target",
    }
    (output / (filename + ".json")).write_text(
        json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (RuntimeError, OSError, zipfile.BadZipFile) as exc:
        print(f"[package-release] ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)
