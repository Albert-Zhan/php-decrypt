"""Release guards use actual temporary Git commits, branches and tags."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest


spec = importlib.util.spec_from_file_location('decrypt_release', Path(__file__).resolve().parents[1] / 'tools/release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleasePlan(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.git('init', '--initial-branch=master')
        self.git('config', 'user.name', 'Release tests')
        self.git('config', 'user.email', 'release-tests@example.invalid')
        self.version('0.2.1')
        self.commit()

    def git(self, *arguments):
        return release.git(self.root, *arguments)

    def version(self, value):
        (self.root / 'php_decrypt.h').write_text('#define PHP_DECRYPT_VERSION "' + value + '"\n')

    def commit(self):
        self.git('add', '.')
        self.git('commit', '-m', 'release fixture')
        sha = self.git('rev-parse', 'HEAD')
        self.git('update-ref', 'refs/remotes/origin/master', sha)
        return sha

    def test_stable_tag(self):
        self.git('tag', 'v0.2.1')
        result = release.plan(self.root, 'v0.2.1')
        self.assertEqual(result['sha'], self.git('rev-parse', 'HEAD'))
        self.assertEqual(result['prerelease'], 'false')

    def test_annotated_prerelease_tag(self):
        self.version('0.3.0-rc.1'); self.commit()
        self.git('tag', '-a', 'v0.3.0-rc.1', '-m', 'release candidate')
        self.assertEqual(release.plan(self.root, 'v0.3.0-rc.1')['prerelease'], 'true')

    def test_version_mismatch(self):
        with self.assertRaisesRegex(ValueError, 'exactly match'):
            release.plan(self.root, 'v0.2.2')

    def test_moved_tag(self):
        self.git('tag', 'v0.2.1')
        (self.root / 'change.txt').write_text('later commit')
        self.commit()
        with self.assertRaisesRegex(ValueError, 'checked-out commit'):
            release.plan(self.root, 'v0.2.1')

    def test_unmerged_commit(self):
        main = self.git('rev-parse', 'HEAD')
        (self.root / 'unmerged.txt').write_text('feature')
        self.commit()
        self.git('update-ref', 'refs/remotes/origin/master', main)
        self.git('tag', 'v0.2.1')
        with self.assertRaisesRegex(ValueError, 'origin/master'):
            release.plan(self.root, 'v0.2.1')

    def test_missing_tag(self):
        with self.assertRaises(subprocess.CalledProcessError):
            release.plan(self.root, 'v0.2.1')

    def test_dry_run_needs_no_tag(self):
        result = release.plan(self.root)
        self.assertEqual(result['tag'], 'v0.2.1')
        self.assertEqual(self.git('tag'), '')

    def test_invalid_versions(self):
        for value in ('0.2', '01.2.3', '0.2.1-rc.01', '0.2.1/../../bad', '0.2.1+build'):
            with self.subTest(version=value):
                self.version(value)
                with self.assertRaises(ValueError):
                    release.plan(self.root)

    def test_optional_notes(self):
        notes = self.root / 'docs/releases/v0.2.1.md'
        notes.parent.mkdir(parents=True)
        notes.write_text('Upgrade notes\n')
        self.assertEqual(release.plan(self.root)['notes'], 'docs/releases/v0.2.1.md')
        notes.write_text('  \n')
        with self.assertRaisesRegex(ValueError, 'empty'):
            release.plan(self.root)


if __name__ == '__main__':
    unittest.main()
