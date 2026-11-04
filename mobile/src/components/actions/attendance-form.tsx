import { useState } from 'react';
import { Platform, StyleSheet, View } from 'react-native';

import { ApiError } from '@/api/client';
import type { AttendancePayload, AttendanceResponse, AttendanceStatus } from '@/api/types/actions';
import { useSession } from '@/auth/session-provider';
import { QrScanner } from '@/components/actions/qr-scanner';
import { Button } from '@/components/button';
import { Notice } from '@/components/notice';
import { TextField } from '@/components/text-field';
import { Spacing } from '@/constants/theme';
import { extractAttendanceCode, markedAttendanceStatus } from '@/lib/attendance';
import { isConnectionFailure, isQueuedOffline } from '@/lib/operations';
import { scanQrFromPhoto } from '@/lib/scan-photo';

type AttendanceFormProps = {
  target: AttendancePayload;
  /** Called once the portal recorded the attendance (or already had it), with the status to show for it. */
  onMarked?: (status: AttendanceStatus) => void;
};

type Result = { tone: 'success' | 'error' | 'warning'; message: string };

const NO_CODE_IN_QR = 'QR terbaca, tetapi tidak berisi kode absensi. Pastikan yang dipindai QR absensi dari dosen.';

/**
 * Three ways to send attendance, as on the web: scan the lecturer's QR
 * with the camera, read it from a photo, or type the code. A code read
 * from a QR is sent at once; a typed one when the student sends it.
 */
export function AttendanceForm({ target, onMarked }: AttendanceFormProps) {
  const { api } = useSession();
  const [value, setValue] = useState('');
  const [sending, setSending] = useState(false);
  const [readingPhoto, setReadingPhoto] = useState(false);
  const [scanning, setScanning] = useState(false);
  const [result, setResult] = useState<Result | null>(null);
  const canScan = Platform.OS !== 'web';

  async function send(text: string) {
    const code = extractAttendanceCode(text);

    if (code === null) {
      setResult({ tone: 'error', message: 'Kode absensi tidak ditemukan. Kode berisi 8 huruf a-f atau angka.' });

      return;
    }

    setValue(code);
    setSending(true);
    setResult(null);

    try {
      const response = await api.post<AttendanceResponse>('attendance', {
        kode: code,
        ...(target.courseid ? { courseid: Number(target.courseid) } : {}),
        ...(target.modulesid ? { modulesid: Number(target.modulesid) } : {}),
        ...(target.pertemuan ? { pertemuan: Number(target.pertemuan) } : {}),
      });

      if (isQueuedOffline(response)) {
        setResult({ tone: 'warning', message: response.message });
      } else {
        setResult({
          tone: 'success',
          message: response.data?.status === 'already_marked' ? 'Kehadiran sudah tercatat sebelumnya.' : response.message || 'Absensi berhasil dikirim.',
        });
        setValue('');
        onMarked?.(markedAttendanceStatus(response));
      }
    } catch (error) {
      setResult({
        tone: 'error',
        message: isConnectionFailure(error)
          ? 'Absensi butuh koneksi internet. Kode belum terkirim dan masih ada di kotak; kirim lagi begitu tersambung.'
          : error instanceof ApiError || error instanceof Error
            ? error.message
            : String(error),
      });
    } finally {
      setSending(false);
    }
  }

  function scanned(text: string) {
    setScanning(false);

    if (extractAttendanceCode(text) === null) {
      setResult({ tone: 'error', message: NO_CODE_IN_QR });

      return;
    }

    void send(text);
  }

  async function readPhoto() {
    setResult(null);
    setReadingPhoto(true);

    try {
      const photo = await scanQrFromPhoto();

      if (photo.status === 'canceled') {
        return;
      }

      if (photo.status === 'unreadable') {
        setResult({ tone: 'error', message: 'QR tidak terbaca dari foto itu. Pilih foto yang QR-nya utuh dan tajam, atau ketik kodenya.' });

        return;
      }

      scanned(photo.text);
    } finally {
      setReadingPhoto(false);
    }
  }

  const busy = sending || readingPhoto;

  return (
    <View style={styles.form}>
      {canScan ? (
        <View style={styles.row}>
          <Button
            label="Pindai QR"
            icon="qr"
            size="small"
            variant="secondary"
            disabled={busy}
            onPress={() => {
              setResult(null);
              setScanning(true);
            }}
            style={styles.flex}
          />
          <Button label="Foto QR" icon="upload" size="small" variant="secondary" loading={readingPhoto} disabled={busy} onPress={readPhoto} style={styles.flex} />
        </View>
      ) : null}
      <TextField
        icon="key"
        placeholder="Atau ketik kode absensi"
        value={value}
        onChangeText={setValue}
        autoCapitalize="none"
        autoCorrect={false}
        returnKeyType="send"
        onSubmitEditing={() => void send(value)}
        clearable
      />
      {result ? <Notice tone={result.tone} message={result.message} /> : null}
      <Button label={sending ? 'Mengirim ke portal…' : 'Kirim absensi'} icon="send" loading={sending} disabled={value.trim() === '' || busy} onPress={() => void send(value)} />
      {canScan ? <QrScanner visible={scanning} onScanned={scanned} onClose={() => setScanning(false)} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  form: {
    gap: Spacing.three - Spacing.one,
  },
  row: {
    flexDirection: 'row',
    gap: Spacing.two,
  },
  flex: {
    flex: 1,
  },
});
