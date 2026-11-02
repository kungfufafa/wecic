import { announcementCategories, appendUnique, archiveStatusLook, fileSize, profileLabel, profileRows, rupiah, stageStatusLook, thesesQuery } from '@/lib/admin';

describe('payment stages', () => {
  it('labels each stage status with a tone', () => {
    expect(stageStatusLook('paid')).toEqual({ label: 'Lunas', tone: 'positive' });
    expect(stageStatusLook('unpaid').tone).toBe('attention');
    expect(stageStatusLook('upcoming').label).toBe('Akan datang');
    expect(stageStatusLook('unknown')).toEqual({ label: 'Belum tercatat', tone: 'muted' });
    expect(stageStatusLook(undefined).tone).toBe('muted');
  });

  it('writes rupiah with dots between thousands', () => {
    expect(rupiah(1250000)).toBe('Rp 1.250.000');
    expect(rupiah(0)).toBe('Rp 0');
    expect(rupiah(null)).toBeNull();
    expect(rupiah(Number.NaN)).toBeNull();
  });
});

describe('thesesQuery', () => {
  it('keeps only the filters that are set and encodes them', () => {
    expect(thesesQuery({ q: ' sistem informasi ', ta: '2023/2024', sort: 'terbaru' })).toBe('?q=sistem%20informasi&ta=2023%2F2024&sort=terbaru');
    expect(thesesQuery({ pembimbing: 'Dosen Alfa', page: 3 })).toBe('?pembimbing=Dosen%20Alfa&page=3');
  });

  it('is empty without filters and leaves out the first page', () => {
    expect(thesesQuery({})).toBe('');
    expect(thesesQuery({ q: '  ', page: 1 })).toBe('');
  });
});

describe('announcement lists', () => {
  it('lists each category once in order of appearance', () => {
    expect(
      announcementCategories([
        { category: 'krs', category_label: 'KRS & Her-Registrasi' },
        { category: 'surat', category_label: 'Surat' },
        { category: 'krs', category_label: 'KRS & Her-Registrasi' },
        { category: null },
        { category: 'lain', category_label: ' ' },
      ]),
    ).toEqual([
      { key: 'krs', label: 'KRS & Her-Registrasi' },
      { key: 'surat', label: 'Surat' },
      { key: 'lain', label: 'lain' },
    ]);
  });

  it('appends a page without repeating items', () => {
    expect(appendUnique([{ id: 'a' }, { id: 'b' }], [{ id: 'b' }, { id: 'c' }])).toEqual([{ id: 'a' }, { id: 'b' }, { id: 'c' }]);
  });
});

describe('archive', () => {
  it('labels version statuses', () => {
    expect(archiveStatusLook('trusted').tone).toBe('positive');
    expect(archiveStatusLook('suspect')).toEqual({ label: 'Perlu dicek', tone: 'attention' });
    expect(archiveStatusLook('rejected').label).toBe('Ditolak');
    expect(archiveStatusLook('dismissed').label).toBe('Diabaikan');
  });

  it('writes file sizes in the largest fitting unit', () => {
    expect(fileSize(0)).toBe('0 B');
    expect(fileSize(512)).toBe('512 B');
    expect(fileSize(2048)).toBe('2 KB');
    expect(fileSize(1572864)).toBe('1,5 MB');
    expect(fileSize(null)).toBe('0 B');
  });
});

describe('profile labels', () => {
  it('uses the known labels and turns other keys into words', () => {
    expect(profileLabel('program_studi')).toBe('Program studi');
    expect(profileLabel('hp')).toBe('Ponsel');
    expect(profileLabel('golongan_darah')).toBe('Golongan darah');
  });

  it('keeps the portal order and trims values', () => {
    expect(profileRows({ nim: ' 2099 ', email: null })).toEqual([
      { label: 'NIM', value: '2099' },
      { label: 'Email', value: null },
    ]);
    expect(profileRows(undefined)).toEqual([]);
  });
});
