import { isConnectionFailure, isFinished, isQueuedOffline, operationIdOf, operationOutcome } from '@/lib/operations';
import { formatBytes, mimeTypeOf } from '@/lib/pick-file';

describe('operations', () => {
  it('knows the terminal states', () => {
    expect(isFinished('completed')).toBe(true);
    expect(isFinished('failed')).toBe(true);
    expect(isFinished('unverified')).toBe(true);
    expect(isFinished('portal_uploading')).toBe(false);
  });

  it('words the outcome with the next step on failure', () => {
    expect(operationOutcome({ operation_id: 'a', status: 'completed', message: 'Tugas terkirim.' })).toEqual({ ok: true, message: 'Tugas terkirim.' });
    expect(operationOutcome({ operation_id: 'a', status: 'failed', message: 'Gagal.', next_step: 'Coba lagi.' })).toEqual({ ok: false, message: 'Gagal. Coba lagi.' });
    expect(operationOutcome({ operation_id: 'a', status: 'unverified' }).ok).toBe(false);
  });

  it('reads the operation id and the offline queue from an answer', () => {
    expect(operationIdOf({ data: { operation_id: 'uuid-1', status: 'queued' } })).toBe('uuid-1');
    expect(operationIdOf({ data: {} })).toBeNull();
    expect(operationIdOf(null)).toBeNull();
    expect(isQueuedOffline({ data: { status: 'queued_offline' } })).toBe(true);
    expect(isQueuedOffline({ data: { status: 'queued' } })).toBe(false);
  });
});

describe('formatBytes', () => {
  it('shows kilobytes and megabytes', () => {
    expect(formatBytes(2048)).toBe('2 KB');
    expect(formatBytes(1.5 * 1024 * 1024)).toBe('1,5 MB');
    expect(formatBytes(0)).toBe('');
    expect(formatBytes(null)).toBe('');
  });
});

describe('mimeTypeOf', () => {
  it('knows the upload types by extension', () => {
    expect(mimeTypeOf('Tugas 3.PDF')).toBe('application/pdf');
    expect(mimeTypeOf('jawaban.zip')).toBe('application/zip');
    expect(mimeTypeOf('jawaban.rar')).toBe('application/vnd.rar');
    expect(mimeTypeOf('catatan')).toBe('application/octet-stream');
  });
});

describe('isConnectionFailure', () => {
  it('tells a missing connection from a slow portal and a refusal', () => {
    expect(isConnectionFailure({ status: 0, message: 'Tidak bisa terhubung ke MyCIC.' })).toBe(true);
    expect(isConnectionFailure({ status: 0, message: 'Portal kampus terlalu lama menjawab.' })).toBe(false);
    expect(isConnectionFailure({ status: 422, message: 'Kode salah' })).toBe(false);
    expect(isConnectionFailure(null)).toBe(false);
  });
});
