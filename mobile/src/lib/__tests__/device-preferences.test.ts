import { clearDeviceCache, readOwnerPreference, writeOwnerPreference } from '@/lib/device-cache';
import { parseSourceSelection, withExcluded } from '@/lib/notebook-sources';

/** An in-memory stand-in for expo-file-system's File and Directory, keyed by path. */
jest.mock('expo-file-system', () => {
  const files = new Map<string, string>();
  const directories = new Set<string>();
  const join = (parent: { path: string } | string, name: string) => `${typeof parent === 'string' ? parent : parent.path}/${name}`;

  class Directory {
    path: string;
    name: string;

    constructor(parent: { path: string } | string, name: string) {
      this.path = join(parent, name);
      this.name = name;
    }

    get exists() {
      return directories.has(this.path);
    }

    /** With its parent folders, as `intermediates: true`. */
    create() {
      this.path.split('/').forEach((_, index, segments) => directories.add(segments.slice(0, index + 1).join('/')));
    }

    delete() {
      [...directories].filter((path) => path === this.path || path.startsWith(`${this.path}/`)).forEach((path) => directories.delete(path));
      [...files.keys()].filter((path) => path.startsWith(`${this.path}/`)).forEach((path) => files.delete(path));
    }

    list() {
      return [];
    }
  }

  class File {
    path: string;

    constructor(parent: { path: string } | string, name: string) {
      this.path = join(parent, name);
    }

    get exists() {
      return files.has(this.path);
    }

    async text() {
      return files.get(this.path) ?? '';
    }

    async write(content: string) {
      files.set(this.path, content);
    }

    delete() {
      files.delete(this.path);
    }
  }

  return { Directory, File, Paths: { document: { path: 'doc' } }, __files: files };
});

describe('owner preferences', () => {
  it('keeps the source selection per student and reads it back', async () => {
    const store = withExcluded(withExcluded({}, 7, [2, 3]), 9, [5]);

    await writeOwnerPreference('2099 0001', 'notebook-source-selection', store);

    expect(parseSourceSelection(await readOwnerPreference('2099 0001', 'notebook-source-selection'))).toEqual(store);
    expect(await readOwnerPreference('20990002', 'notebook-source-selection')).toBeNull();
  });

  it('keeps nothing without a student, and forgets everything at sign out', async () => {
    await writeOwnerPreference(null, 'notebook-source-selection', { '7': [2] });
    expect(await readOwnerPreference(null, 'notebook-source-selection')).toBeNull();

    await writeOwnerPreference('20990001', 'notebook-source-selection', { '7': [2] });
    clearDeviceCache();

    expect(await readOwnerPreference('20990001', 'notebook-source-selection')).toBeNull();
  });

  it('reads an unreadable file as nothing', async () => {
    const { __files: files } = jest.requireMock('expo-file-system') as { __files: Map<string, string> };

    await writeOwnerPreference('20990003', 'notebook-source-selection', {});
    const path = [...files.keys()].find((key) => key.includes('20990003'));
    files.set(path ?? '', '{not json');

    expect(await readOwnerPreference('20990003', 'notebook-source-selection')).toBeNull();
  });
});
