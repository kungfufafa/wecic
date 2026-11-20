import { useState } from 'react';
import { StyleSheet, Switch, View } from 'react-native';

import { ApiError } from '@/api/client';
import type { BackgroundSync } from '@/api/types/admin';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Icon } from '@/components/icon';
import { Notice } from '@/components/notice';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { formatDateTime } from '@/lib/format';
import { BACKGROUND_SYNC_EXPLANATION, backgroundSyncNeedsPassword, setBackgroundSync } from '@/lib/privacy';

type BackgroundSyncSettingProps = {
  status: BackgroundSync;
  /** Told the status after each change. */
  onChange: (status: BackgroundSync) => void;
};

/**
 * The background sync switch, inside a panel: off by default. Switching it
 * on stores the portal password encrypted on the WeCIC server, so the
 * password is asked again whenever none is stored; switching it off
 * deletes it at once.
 */
export function BackgroundSyncSetting({ status, onChange }: BackgroundSyncSettingProps) {
  const theme = useTheme();
  const { api } = useSession();
  const [askingPassword, setAskingPassword] = useState(false);
  const [password, setPassword] = useState('');
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);
  const [passwordError, setPasswordError] = useState<string | null>(null);

  async function apply(enabled: boolean, typedPassword?: string) {
    setSaving(true);
    setMessage(null);
    setPasswordError(null);

    try {
      const response = await setBackgroundSync(api, enabled, typedPassword);
      onChange(response.data);
      setMessage({ tone: 'success', text: response.message });
      setAskingPassword(false);
      setPassword('');
    } catch (error) {
      const fieldError = error instanceof ApiError ? error.fieldErrors.password : undefined;

      if (enabled && fieldError !== undefined) {
        setAskingPassword(true);
        setPasswordError(fieldError);
      } else {
        setMessage({ tone: 'error', text: error instanceof Error ? error.message : String(error) });
      }

      const current = error instanceof ApiError ? (error.body as { data?: BackgroundSync } | null)?.data : undefined;

      if (current && typeof current.available === 'boolean') {
        onChange(current);
      }
    } finally {
      setSaving(false);
    }
  }

  function toggle(enabled: boolean) {
    if (enabled && backgroundSyncNeedsPassword(status)) {
      setMessage(null);
      setPasswordError(null);
      setAskingPassword(true);

      return;
    }

    setAskingPassword(false);
    void apply(enabled);
  }

  const switchedOn = status.enabled || askingPassword;

  return (
    <View style={styles.panel}>
      <View style={styles.row}>
        <Icon name="sync" size={22} color={theme.text} />
        <View style={styles.flex}>
          <ThemedText style={styles.label} numberOfLines={1}>
            Sinkronisasi di background
          </ThemedText>
          <ThemedText type="caption" themeColor="textTertiary" numberOfLines={2}>
            {!status.available
              ? 'Tidak tersedia untuk akun ini'
              : status.enabled
                ? `Aktif · tiap ${status.interval_minutes ?? 180} menit`
                : 'Mati · kata sandi tidak disimpan di server'}
          </ThemedText>
        </View>
        <Switch
          value={switchedOn}
          disabled={!status.available || saving}
          onValueChange={toggle}
          trackColor={{ true: theme.success, false: theme.elevated }}
          accessibilityLabel="Sinkronisasi di background"
          accessibilityHint="Menyalakannya menyimpan kata sandi portal terenkripsi di server WeCIC. Mematikannya menghapus kata sandi itu."
        />
      </View>

      <ThemedText type="caption" themeColor="textTertiary">
        {BACKGROUND_SYNC_EXPLANATION}
        {status.credential_days ? ` Paling lama disimpan ${status.credential_days} hari sejak login online terakhir.` : ''}
      </ThemedText>

      {askingPassword ? (
        <View style={styles.form}>
          <TextField
            label="Kata sandi portal"
            icon="lock"
            value={password}
            onChangeText={setPassword}
            secureToggle
            autoComplete="current-password"
            textContentType="password"
            returnKeyType="go"
            onSubmitEditing={() => void apply(true, password)}
            error={passwordError}
          />
          <View style={styles.actions}>
            <Button
              size="small"
              variant="secondary"
              label="Batal"
              disabled={saving}
              onPress={() => {
                setAskingPassword(false);
                setPassword('');
                setPasswordError(null);
              }}
            />
            <Button size="small" icon="lock" label="Nyalakan" loading={saving} disabled={password === ''} onPress={() => void apply(true, password)} />
          </View>
        </View>
      ) : null}

      {status.pending_login ? (
        <ThemedText type="caption" themeColor="warning">
          Aktif setelah kamu masuk lagi saat portal online.
        </ThemedText>
      ) : null}
      {status.enabled && status.last_synced_at ? (
        <ThemedText type="caption" themeColor="textTertiary">
          Terakhir sinkron {formatDateTime(status.last_synced_at)}
        </ThemedText>
      ) : null}
      {status.enabled && status.last_error ? (
        <ThemedText type="caption" themeColor="danger" numberOfLines={3}>
          {status.last_error}
        </ThemedText>
      ) : null}
      {message ? <Notice tone={message.tone} message={message.text} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  panel: {
    gap: Spacing.two,
    paddingHorizontal: Spacing.three + Spacing.half,
    paddingVertical: Spacing.two + Spacing.one,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three - Spacing.half,
  },
  flex: {
    flex: 1,
    gap: Spacing.half,
  },
  label: {
    fontSize: 17,
    lineHeight: 22,
  },
  form: {
    gap: Spacing.two,
  },
  actions: {
    flexDirection: 'row',
    justifyContent: 'flex-end',
    gap: Spacing.two,
  },
});
