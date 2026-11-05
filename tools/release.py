#!/usr/bin/env python3
"""Validate a source release, or inspect the current checkout without publishing."""
import argparse
import json
from pathlib import Path
import re
import subprocess


VERSION = r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:alpha|beta|rc)\.(?:0|[1-9][0-9]*))?'


def git(root, *arguments):
    return subprocess.check_output(['git', '-C', str(root), *arguments], text=True, stderr=subprocess.PIPE).strip()


def plan(root, tag=None):
    root = Path(root)
    matches = re.findall(r'^#define\s+PHP_DECRYPT_VERSION\s+"([^"]+)"\s*$',
                         (root / 'php_decrypt.h').read_text(), re.MULTILINE)
    if len(matches) != 1 or not re.fullmatch(VERSION, matches[0]):
        raise ValueError('PHP_DECRYPT_VERSION must be X.Y.Z or X.Y.Z-alpha.N/beta.N/rc.N')
    version = matches[0]
    sha = git(root, 'rev-parse', 'HEAD^{commit}')
    if tag is not None:
        if tag != 'v' + version:
            raise ValueError('Release tag must exactly match v' + version)
        if git(root, 'rev-parse', '--verify', 'refs/tags/' + tag + '^{commit}') != sha:
            raise ValueError('Tag does not point to the checked-out commit')
        ancestor = subprocess.run(['git', '-C', str(root), 'merge-base', '--is-ancestor', sha,
                                   'refs/remotes/origin/master'], capture_output=True)
        if ancestor.returncode != 0:
            raise ValueError('Release commit must be contained in origin/master')
    else:
        tag = 'v' + version
    notes = Path('docs/releases') / (tag + '.md')
    if (root / notes).exists() and not (root / notes).read_text().strip():
        raise ValueError('Release notes file is empty: ' + str(notes))
    return {'tag': tag, 'sha': sha, 'prerelease': str('-' in version).lower(),
            'notes': str(notes) if (root / notes).is_file() else ''}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--tag', help='Require an existing tag on HEAD, reachable from origin/master')
    parser.add_argument('--output', type=Path, help='Append validated outputs to a GitHub Actions output file')
    args = parser.parse_args()
    try:
        result = plan(Path.cwd(), args.tag)
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        parser.exit(1, str(error) + '\n')
    print(json.dumps(result, indent=2))
    if args.output:
        with args.output.open('a') as output:
            for key, value in result.items():
                output.write(key + '=' + value + '\n')


if __name__ == '__main__':
    main()
