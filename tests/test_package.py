#!/usr/bin/env python3
"""Unit tests for distributable file selection; no third-party modules needed."""
from __future__ import annotations

import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "package_release.py"
NEEDED = [
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
    "vendor/composer/installed.php",
    "vendor/mpdf/mpdf/composer.json",
    "vendor/phpoffice/phpspreadsheet/composer.json",
    "storage/.gitkeep",
    "storage/.htaccess",
    "storage/fonts/Vazirmatn-Regular.ttf",
    "storage/fonts/Vazirmatn-Bold.ttf",
    "INSTALL-FA.md",
]


class PackageReleaseTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.workspace = Path(self.temporary.name)
        self.root = self.workspace / "project"
        self.out = self.workspace / "release"
        for name in NEEDED:
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(f"content:{name}".encode())

    def run_packager(self, version: str = "abcd1234") -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(SCRIPT), "--root", str(self.root),
             "--output", str(self.out), "--version", version],
            text=True, capture_output=True, check=False,
        )

    def test_includes_runtime_and_excludes_dev_files(self) -> None:
        (self.root / ".env").write_text("DB_PASSWORD=secret")
        (self.root / "tests").mkdir()
        (self.root / "tests" / "dummy.txt").write_text("private test data")
        (self.root / "node_modules").mkdir()
        (self.root / "node_modules" / "huge.js").write_text("unused")
        (self.root / "storage" / "config.php").write_text("SECRET")
        (self.root / "storage" / "installed.lock").write_text("SECRET")
        (self.root / "storage" / "sessions.txt").write_text("SECRET")
        result = self.run_packager()
        self.assertEqual(result.returncode, 0, result.stderr)
        archive = self.out / "apple-id-management-abcd1234.zip"
        checksum = self.out / (archive.name + ".sha256")
        meta = self.out / (archive.name + ".json")
        self.assertTrue(archive.exists())
        self.assertEqual(
            checksum.read_text().split()[0],
            hashlib.sha256(archive.read_bytes()).hexdigest()
        )
        self.assertEqual(json.loads(meta.read_text())["version"], "abcd1234")
        with zipfile.ZipFile(archive) as zipfile_obj:
            names = zipfile_obj.namelist()
            self.assertIsNone(zipfile_obj.testzip())
        self.assertTrue(all(n.startswith("apple-id-management/") for n in names))
        self.assertIn("apple-id-management/public/install.php", names)
        self.assertIn("apple-id-management/vendor/autoload.php", names)
        self.assertNotIn("apple-id-management/storage/config.php", names)
        self.assertNotIn("apple-id-management/storage/installed.lock", names)
        self.assertFalse(any("node_modules" in n or "/tests/" in n or ".env" in n for n in names))

    def test_aborts_when_local_assets_are_missing(self) -> None:
        (self.root / "public/assets/vendor/tabler/tabler.rtl.min.css").unlink()
        result = self.run_packager()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Missing required runtime files", result.stderr)

    def test_rejects_secrets_inside_public(self) -> None:
        (self.root / "public" / ".env").write_text("DB_PASSWORD=secret")
        result = self.run_packager()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("confidential file", result.stderr)

    def test_rejects_unsafe_version(self) -> None:
        result = self.run_packager("../escape")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.out.exists())

    def test_rejects_symlinks(self) -> None:
        target = self.root / "public" / "private.sql"
        (self.root / "public" / "alias.txt").symlink_to(target)
        result = self.run_packager()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("symlink", result.stderr)


if __name__ == "__main__":
    unittest.main()
