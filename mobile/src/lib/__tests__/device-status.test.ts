import { portalStatusTag, pushStatusTag, realtimeStatusTag } from '@/lib/device-status';

describe('portalStatusTag', () => {
  it('says Online while the portal answers and Offline on the archive', () => {
    expect(portalStatusTag(false)).toEqual({ label: 'Online', tone: 'positive' });
    expect(portalStatusTag(true)).toEqual({ label: 'Offline', tone: 'attention' });
  });
});

describe('pushStatusTag', () => {
  it('has a short tag for every registration state', () => {
    expect(pushStatusTag(null)).toEqual({ label: 'Mendaftar…', tone: 'attention' });
    expect(pushStatusTag({ status: 'registered', token: 'ExponentPushToken[abc]' })).toEqual({ label: 'Aktif', tone: 'positive' });
    expect(pushStatusTag({ status: 'denied' })).toEqual({ label: 'Ditolak', tone: 'danger' });
    expect(pushStatusTag({ status: 'unavailable', reason: 'Push hanya bisa di perangkat fisik, bukan simulator.' })).toEqual({
      label: 'Tidak tersedia',
      tone: 'muted',
    });
  });
});

describe('realtimeStatusTag', () => {
  it('has a short tag for every connection state', () => {
    expect(realtimeStatusTag('connected')).toEqual({ label: 'Aktif', tone: 'positive' });
    expect(realtimeStatusTag('connecting')).toEqual({ label: 'Menyambung…', tone: 'attention' });
    expect(realtimeStatusTag('unavailable')).toEqual({ label: 'Tidak tersedia', tone: 'muted' });
    expect(realtimeStatusTag('off')).toEqual({ label: 'Belum aktif', tone: 'muted' });
  });
});
