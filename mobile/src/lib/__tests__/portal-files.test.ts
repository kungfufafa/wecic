import { downloadErrorMessage, downloadPath, downloadStatus, localFileName } from '@/lib/portal-files';

describe('portal files', () => {
  it('asks the download endpoint for the raw href and a name', () => {
    expect(downloadPath(' ../files/sap/SI032022-rps83b.pdf ', 'RPS')).toBe('support/download?path=..%2Ffiles%2Fsap%2FSI032022-rps83b.pdf&name=RPS');
    expect(downloadPath('files/skkm/a b.pdf')).toBe('support/download?path=files%2Fskkm%2Fa%20b.pdf');
  });

  it('reads the HTTP status of a failed download', () => {
    expect(downloadStatus(new Error('Unable to download file: response has status code 404'))).toBe(404);
    expect(downloadStatus(new Error('The Internet connection appears to be offline.'))).toBe(0);
    expect(downloadStatus(null)).toBe(0);
  });

  it('words the failure for the student', () => {
    expect(downloadErrorMessage(404)).toBe('File tidak ditemukan di portal kampus.');
    expect(downloadErrorMessage(422)).toBe('Tautan file ini tidak bisa dibuka dari MyCIC.');
    expect(downloadErrorMessage(0)).toMatch(/koneksi internet/);
    expect(downloadErrorMessage(500)).toBe('Server sedang bermasalah. Coba lagi sebentar lagi.');
  });
});

describe('localFileName', () => {
  it('keeps the portal file name and its extension', () => {
    expect(localFileName('../files/sap/SIE053004-rps47a.pdf ')).toBe('SIE053004-rps47a.pdf');
    expect(localFileName('files/skripsi/kolokium/TUGAS%20AKHIR-1.pdf')).toBe('TUGAS AKHIR-1.pdf');
    expect(localFileName('https://my.cic.ac.id/portal/files/modul/a.pdf?x=1')).toBe('a.pdf');
  });

  it('never gives an empty or unsafe name', () => {
    expect(localFileName('')).toBe('file');
    expect(localFileName('../')).toBe('file');
    expect(localFileName('files/a:b*.pdf')).toBe('a-b-.pdf');
  });
});
