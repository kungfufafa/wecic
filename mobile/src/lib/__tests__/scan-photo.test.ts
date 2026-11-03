import { scanFromURLAsync } from 'expo-camera';
import * as ImagePicker from 'expo-image-picker';

import { scanQrFromPhoto } from '@/lib/scan-photo';

jest.mock('expo-camera', () => ({ scanFromURLAsync: jest.fn() }));
jest.mock('expo-image-picker', () => ({ launchImageLibraryAsync: jest.fn() }));

const pick = ImagePicker.launchImageLibraryAsync as jest.Mock;
const scan = scanFromURLAsync as jest.Mock;

describe('scanQrFromPhoto', () => {
  beforeEach(() => {
    pick.mockReset();
    scan.mockReset();
  });

  it('reads the QR of the chosen photo', async () => {
    pick.mockResolvedValue({ canceled: false, assets: [{ uri: 'file:///qr.png' }] });
    scan.mockResolvedValue([{ type: 'qr', data: 'https://my.cic.ac.id/absen?kode=5f3a9c21' }]);

    await expect(scanQrFromPhoto()).resolves.toEqual({ status: 'read', text: 'https://my.cic.ac.id/absen?kode=5f3a9c21' });
    expect(scan).toHaveBeenCalledWith('file:///qr.png', ['qr']);
  });

  it('says when the student chose nothing', async () => {
    pick.mockResolvedValue({ canceled: true, assets: null });

    await expect(scanQrFromPhoto()).resolves.toEqual({ status: 'canceled' });
    expect(scan).not.toHaveBeenCalled();
  });

  it('says when the photo holds no readable QR', async () => {
    pick.mockResolvedValue({ canceled: false, assets: [{ uri: 'file:///cat.jpg' }] });
    scan.mockResolvedValue([]);
    await expect(scanQrFromPhoto()).resolves.toEqual({ status: 'unreadable' });

    scan.mockRejectedValue(new Error('decode failed'));
    await expect(scanQrFromPhoto()).resolves.toEqual({ status: 'unreadable' });
  });
});
