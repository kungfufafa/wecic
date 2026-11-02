import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { ApiError } from '@/api/client';
import type { AttendancePayload, AttendanceResponse } from '@/api/types/actions';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Notice } from '@/components/notice';
import { TextField } from '@/components/text-field';
import { Spacing } from '@/constants/theme';
import { extractAttendanceCode } from '@/lib/attendance';
import { isQueuedOffline } from '@/lib/operations';

type AttendanceFormProps = {
  target: AttendancePayload;
  /** Called once the portal recorded the attendance (or already had it). */
  onMarked?: () => void;
};

/**
 * The attendance code box: the student types or pastes the code (or the
 * text a QR holds; the code is read out of it) and sends it to the portal.
 */
export function AttendanceForm({ target, onMarked }: AttendanceFormProps) {
  const { api } = useSession();
  const [value, setValue] = useState('');
  const [sending, setSending] = useState(false);
  const [result, setResult] = useState<{ tone: 'success' | 'error' | 'warning'; message: string } | null>(null);

  async function send() {
    const code = extractAttendanceCode(value);

    if (code === null) {
      setResult({ tone: 'error', message: 'Kode absensi tidak ditemukan. Kode berisi 8 huruf a-f atau angka.' });

      return;
    }

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
        onMarked?.();
      }
    } catch (error) {
      setResult({ tone: 'error', message: error instanceof ApiError || error instanceof Error ? error.message : String(error) });
    } finally {
      setSending(false);
    }
  }

  return (
    <View style={styles.form}>
      <TextField
        icon="qr"
        placeholder="Kode absensi dari dosen"
        value={value}
        onChangeText={setValue}
        autoCapitalize="none"
        autoCorrect={false}
        returnKeyType="send"
        onSubmitEditing={send}
        clearable
      />
      {result ? <Notice tone={result.tone} message={result.message} /> : null}
      <Button label={sending ? 'Mengirim ke portal…' : 'Kirim absensi'} icon="send" loading={sending} disabled={value.trim() === ''} onPress={send} />
    </View>
  );
}

const styles = StyleSheet.create({
  form: {
    gap: Spacing.three - Spacing.one,
  },
});
