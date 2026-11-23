import { ageLabel, cacheFileName, cacheKeyPath, isFresh, isRetained, ownerFolder, policyFor } from '@/lib/cache-policies';

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

describe('policyFor', () => {
  it('keeps the pages the web keeps', () => {
    expect(policyFor('dashboard')?.label).toBe('ringkasan dashboard');
    expect(policyFor('/class/2265?dosen_id=5240&course_id=8810')?.label).toBe('detail kelas');
    expect(policyFor('meeting/30061?courses_id=8810&fresh=1')?.label).toBe('detail pertemuan');
    expect(policyFor('academic/krs/90004')?.label).toBe('detail KRS');
    expect(policyFor('announcement?page=2')?.label).toBe('pengumuman');
    expect(policyFor('notifications?limit=30')?.label).toBe('notifikasi');
    expect(policyFor('finance/payments')?.retainFor).toBe(7 * DAY);
  });

  it('never keeps the session, notebooks, archives, searches or later pages', () => {
    expect(policyFor('auth/session')).toBeNull();
    expect(policyFor('notebooks/11')).toBeNull();
    expect(policyFor('notebooks/11/sources/4/chunks?around=12')).toBeNull();
    expect(policyFor('archive')).toBeNull();
    expect(policyFor('library/theses?q=sistem')).toBeNull();
    expect(policyFor('notifications?limit=30&before=12')).toBeNull();
    expect(policyFor('support/download?path=x')).toBeNull();
  });
});

describe('cache keys', () => {
  it('drops the leading slash and fresh=1, keeping the rest', () => {
    expect(cacheKeyPath('/dashboard?fresh=1')).toBe('dashboard');
    expect(cacheKeyPath('meeting/1?courses_id=2&fresh=1')).toBe('meeting/1?courses_id=2');
    expect(cacheKeyPath('meeting/1?fresh=1&courses_id=2')).toBe('meeting/1?courses_id=2');
  });

  it('names a file per path, the same each time', () => {
    expect(cacheFileName('dashboard')).toMatch(/^[0-9a-f]{16}\.json$/);
    expect(cacheFileName('dashboard')).toBe(cacheFileName('dashboard'));
    expect(cacheFileName('class/1')).not.toBe(cacheFileName('class/2'));
  });

  it('keeps each student apart by NIM', () => {
    expect(ownerFolder(' 20251030030 ')).toBe('20251030030');
    expect(ownerFolder('Mhs/../X')).toBe('mhs-..-x');
    expect(ownerFolder('')).toBeNull();
    expect(ownerFolder(null)).toBeNull();
  });
});

describe('age', () => {
  const policy = policyFor('dashboard')!;
  const now = Date.UTC(2026, 10, 3, 8);

  it('is fresh, then retained, then gone', () => {
    expect(isFresh(policy, now - 10 * MINUTE, now)).toBe(true);
    expect(isFresh(policy, now - 31 * MINUTE, now)).toBe(false);
    expect(isRetained(policy, now - 6 * DAY, now)).toBe(true);
    expect(isRetained(policy, now - 8 * DAY, now)).toBe(false);
    expect(isRetained(policy, now + MINUTE, now)).toBe(false);
  });

  it('words the age of a copy', () => {
    expect(ageLabel(now - 20 * 1000, now)).toBe('baru saja');
    expect(ageLabel(now - 12 * MINUTE, now)).toBe('12 menit lalu');
    expect(ageLabel(now - 3 * HOUR, now)).toBe('3 jam lalu');
    expect(ageLabel(now - 2 * DAY, now)).toBe('2 hari lalu');
  });
});
