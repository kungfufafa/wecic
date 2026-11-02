import { fieldLabel, hrefOf, textOf } from '@/lib/exams';

describe('exam page parts', () => {
  it('reads text from strings and objects', () => {
    expect(textOf('  Unggah sebelum 10.00 ')).toBe('Unggah sebelum 10.00');
    expect(textOf({ message: 'Portal ditutup' })).toBe('Portal ditutup');
    expect(textOf({ label: 'Soal UTS' })).toBe('Soal UTS');
    expect(textOf({})).toBeNull();
    expect(textOf('')).toBeNull();
    expect(textOf(3)).toBeNull();
  });

  it('finds a file link', () => {
    expect(hrefOf({ href: '../files/soal/a.pdf' })).toBe('../files/soal/a.pdf');
    expect(hrefOf({ file_info: { href: '../files/tugasmhs/b.pdf' } })).toBe('../files/tugasmhs/b.pdf');
    expect(hrefOf({ text: 'x' })).toBeNull();
    expect(hrefOf('x')).toBeNull();
  });

  it('labels a form field from its name', () => {
    expect(fieldLabel('data[keterangan]')).toBe('Keterangan');
    expect(fieldLabel('catatan_tambahan')).toBe('Catatan tambahan');
  });
});
