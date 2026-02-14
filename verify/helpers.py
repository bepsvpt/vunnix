"""Shared verification utilities for Vunnix milestone checks.

Usage in milestone scripts:
    from helpers import Check, section, file_exists, file_contains, run_command

    checker = Check()
    section("T1: Laravel Project Scaffold")
    checker.check("composer.json exists", file_exists("composer.json"))
    ...
    checker.summary()
"""

import os
import re
import subprocess
import sys

# Project root is one level up from verify/
PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


class Check:
    """Accumulates check results and prints a summary."""

    def __init__(self):
        self.results = []

    def check(self, name, condition, detail=""):
        """Record and print a single check result."""
        status = "PASS" if condition else "FAIL"
        self.results.append((status, name, detail))
        print(f"  [{status}] {name}")
        if detail:
            print(f"         {detail}")
        return condition

    @property
    def passed(self):
        return sum(1 for s, _, _ in self.results if s == "PASS")

    @property
    def failed(self):
        return sum(1 for s, _, _ in self.results if s == "FAIL")

    @property
    def total(self):
        return len(self.results)

    def summary(self):
        """Print final summary and exit with appropriate code."""
        print(f"\n{'=' * 60}")
        print(f"  RESULTS: {self.passed}/{self.total} passed, {self.failed} failed")
        print(f"{'=' * 60}")

        if self.failed > 0:
            print("\n  Failed checks:")
            for status, name, detail in self.results:
                if status == "FAIL":
                    print(f"    - {name}" + (f" ({detail})" if detail else ""))
            sys.exit(1)
        else:
            print("\n  All checks passed.")
            sys.exit(0)


def section(title):
    """Print a section header."""
    print(f"\n{'=' * 60}")
    print(f"  {title}")
    print(f"{'=' * 60}")


def file_exists(path):
    """Check if a file exists relative to project root."""
    return os.path.isfile(os.path.join(PROJECT_ROOT, path))


def dir_exists(path):
    """Check if a directory exists relative to project root."""
    return os.path.isdir(os.path.join(PROJECT_ROOT, path))


def file_contains(path, pattern):
    """Check if a file contains a string pattern (relative to project root)."""
    full_path = os.path.join(PROJECT_ROOT, path)
    if not os.path.isfile(full_path):
        return False
    with open(full_path, "r") as f:
        return pattern in f.read()


def file_matches(path, regex):
    """Check if a file content matches a regex pattern."""
    full_path = os.path.join(PROJECT_ROOT, path)
    if not os.path.isfile(full_path):
        return False
    with open(full_path, "r") as f:
        return bool(re.search(regex, f.read()))


def run_command(cmd, cwd=None, timeout=120):
    """Run a shell command and return (success, stdout, stderr)."""
    try:
        result = subprocess.run(
            cmd,
            shell=True,
            capture_output=True,
            text=True,
            cwd=cwd or PROJECT_ROOT,
            timeout=timeout,
        )
        return result.returncode == 0, result.stdout.strip(), result.stderr.strip()
    except subprocess.TimeoutExpired:
        return False, "", "Command timed out"
    except FileNotFoundError:
        return False, "", "Command not found"


def count_migrations_matching(pattern):
    """Count migration files matching a pattern in database/migrations/."""
    migration_dir = os.path.join(PROJECT_ROOT, "database", "migrations")
    if not os.path.isdir(migration_dir):
        return 0
    return sum(
        1
        for f in os.listdir(migration_dir)
        if re.search(pattern, f, re.IGNORECASE)
    )


def list_files_in(path):
    """List files in a directory relative to project root."""
    full_path = os.path.join(PROJECT_ROOT, path)
    if not os.path.isdir(full_path):
        return []
    return os.listdir(full_path)
