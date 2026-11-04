import { useState } from 'react';
import { StyleSheet } from 'react-native';

import { ApiError } from '@/api/client';
import type { ApiEnvelope } from '@/api/types';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { Panel, Section } from '@/components/section';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';

/** Changes the portal password; the portal's own rules decide. */
export default function PasswordScreen() {
  const { api, state } = useSession();
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const offline = state.status === 'signedIn' && state.offline;
  const mismatch = confirmation !== '' && next !== confirmation;
  const tooShort = next !== '' && next.length < 6;
  const ready = current !== '' && next.length >= 6 && next === confirmation && next !== current;

  async function save() {
    setSaving(true);
    setResult(null);
    setFieldErrors({});

    try {
      const response = await api.post<ApiEnvelope>('auth/password', {
        current_password: current,
        new_password: next,
        new_password_confirmation: confirmation,
      });
      setResult({ ok: true, message: `${response.message || 'Kata sandi berhasil diganti.'} Jika "Tetap masuk" aktif, masuk ulang dengan sandi baru.` });
      setCurrent('');
      setNext('');
      setConfirmation('');
    } catch (error) {
      setResult({ ok: false, message: error instanceof Error ? error.message : String(error) });
      setFieldErrors(error instanceof ApiError ? error.fieldErrors : {});
    } finally {
      setSaving(false);
    }
  }

  return (
    <Screen back title="Ganti kata sandi">
      {offline ? <Notice tone="warning" message="Portal offline. Ganti kata sandi butuh portal online." /> : null}
      {result ? <Notice tone={result.ok ? 'success' : 'error'} message={result.message} /> : null}

      <Section label="Kata sandi portal">
        <Panel style={styles.form}>
          <TextField
            label="Kata sandi sekarang"
            icon="lock"
            value={current}
            onChangeText={setCurrent}
            secureToggle
            autoComplete="current-password"
            textContentType="password"
            error={fieldErrors.current_password}
          />
          <TextField
            label="Kata sandi baru"
            icon="key"
            value={next}
            onChangeText={setNext}
            secureToggle
            autoComplete="new-password"
            textContentType="newPassword"
            error={fieldErrors.new_password ?? (tooShort ? 'Minimal 6 karakter.' : next !== '' && next === current ? 'Harus berbeda dari kata sandi sekarang.' : null)}
          />
          <TextField
            label="Ulangi kata sandi baru"
            icon="key"
            value={confirmation}
            onChangeText={setConfirmation}
            secureToggle
            autoComplete="new-password"
            textContentType="newPassword"
            returnKeyType="done"
            onSubmitEditing={ready ? save : undefined}
            error={fieldErrors.new_password_confirmation ?? (mismatch ? 'Tidak sama dengan kata sandi baru.' : null)}
          />
          <ThemedText type="caption" themeColor="textTertiary">
            Minimal 6 karakter, beda dari yang sekarang.
          </ThemedText>
        </Panel>
      </Section>

      <Button label={saving ? 'Menyimpan ke portal…' : 'Simpan kata sandi'} loading={saving} disabled={!ready || offline} onPress={save} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  form: {
    gap: Spacing.three,
  },
});
