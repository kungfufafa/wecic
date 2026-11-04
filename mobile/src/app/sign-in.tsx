import { useEffect, useRef, useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Switch, View, type TextInputInstance } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { useSession } from '@/auth/session-provider';
import { BrandLogo } from '@/components/brand-logo';
import { Button } from '@/components/button';
import { Icon } from '@/components/icon';
import { Notice } from '@/components/notice';
import { OfflineStrip } from '@/components/offline-strip';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { MaxContentWidth, Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

/**
 * The login: the WeCIC wordmark on a soft blue glow, two fields, one light
 * button. WeCIC is unofficial, so it never shows the university's logo and
 * says it is not affiliated with UCIC.
 */
export default function SignInScreen() {
  const theme = useTheme();
  const { state, signIn, rememberedUsername } = useSession();
  const passwordRef = useRef<TextInputInstance>(null);
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const notice = state.status === 'signedOut' ? state.notice : null;

  useEffect(() => {
    void rememberedUsername().then((nim) => {
      if (nim !== null) {
        setUsername((current) => (current === '' ? nim : current));
      }
    });
  }, [rememberedUsername]);

  async function submit() {
    const missing: Record<string, string> = {};

    if (username.trim() === '') {
      missing.username = 'Isi NIM kamu.';
    }

    if (password === '') {
      missing.password = 'Isi kata sandi portal.';
    }

    setError(null);
    setFieldErrors(missing);

    if (Object.keys(missing).length > 0) {
      return;
    }

    setSubmitting(true);

    try {
      await signIn(username.trim(), password, remember);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : String(caught));
      setFieldErrors(caught instanceof ApiError ? caught.fieldErrors : {});
      setSubmitting(false);
    }
  }

  return (
    <View style={[styles.flex, { backgroundColor: theme.background }]}>
      <View pointerEvents="none" style={[styles.glow, { backgroundImage: GLOW }]} />
      <SafeAreaView style={styles.flex}>
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
          <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
            <View style={styles.brand}>
              <BrandLogo />
              <View style={styles.heading}>
                <ThemedText type="display">Selamat datang</ThemedText>
                <ThemedText type="default" themeColor="textSecondary">
                  Masuk dengan NIM dan kata sandi MyCIC, portal kampus kamu.
                </ThemedText>
              </View>
            </View>

            <View style={styles.form}>
              <OfflineStrip />
              {notice ? <Notice message={notice} tone="warning" /> : null}
              {error ? <Notice message={error} tone="error" /> : null}

              <TextField
                icon="idCard"
                placeholder="NIM"
                value={username}
                onChangeText={setUsername}
                autoCapitalize="none"
                autoCorrect={false}
                autoComplete="username"
                textContentType="username"
                keyboardType="number-pad"
                returnKeyType="next"
                onSubmitEditing={() => passwordRef.current?.focus()}
                submitBehavior="submit"
                error={fieldErrors.username}
              />
              <TextField
                ref={passwordRef}
                icon="lock"
                placeholder="Kata sandi portal"
                value={password}
                onChangeText={setPassword}
                secureToggle
                autoComplete="current-password"
                textContentType="password"
                returnKeyType="go"
                onSubmitEditing={submit}
                error={fieldErrors.password}
              />

              <Pressable
                accessibilityRole="switch"
                accessibilityState={{ checked: remember }}
                accessibilityHint="Kata sandi disimpan aman di perangkat ini, jadi WeCIC bisa masuk lagi sendiri."
                onPress={() => setRemember((current) => !current)}
                style={styles.remember}>
                <View style={styles.rememberText}>
                  <ThemedText type="bodyMedium">Tetap masuk</ThemedText>
                  <ThemedText type="caption" themeColor="textTertiary">
                    Masuk lagi otomatis saat sesi kampus berakhir.
                  </ThemedText>
                </View>
                <Switch value={remember} onValueChange={setRemember} trackColor={{ true: theme.success, false: theme.elevated }} />
              </Pressable>

              <Button label={submitting ? 'Memeriksa ke portal…' : 'Masuk'} loading={submitting} onPress={submit} />
            </View>

            <View style={styles.footer}>
              <View style={styles.footerRow}>
                <Icon name="lock" size={13} color={theme.textQuaternary} />
                <ThemedText type="caption" themeColor="textQuaternary">
                  Data bersumber langsung dari MyCIC (my.cic.ac.id)
                </ThemedText>
              </View>
              <ThemedText type="caption" themeColor="textQuaternary" style={styles.centered}>
                WeCIC adalah aplikasi tidak resmi buatan mahasiswa dan tidak berafiliasi dengan UCIC.
              </ThemedText>
            </View>
          </ScrollView>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </View>
  );
}

/** A soft CIC-blue light from the top, fading into the black canvas. */
const GLOW = 'linear-gradient(180deg, rgba(3, 82, 161, 0.42) 0%, rgba(3, 82, 161, 0.12) 45%, rgba(5, 5, 5, 0) 100%)';

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  glow: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    height: 420,
  },
  content: {
    flexGrow: 1,
    justifyContent: 'space-between',
    gap: Spacing.five,
    paddingHorizontal: Spacing.four,
    paddingTop: Spacing.six,
    paddingBottom: Spacing.four,
    width: '100%',
    maxWidth: MaxContentWidth,
    alignSelf: 'center',
  },
  brand: {
    gap: Spacing.four,
  },
  heading: {
    gap: Spacing.two,
  },
  form: {
    gap: Spacing.three - Spacing.one,
  },
  remember: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    paddingVertical: Spacing.two,
    paddingHorizontal: Spacing.one,
    borderRadius: Radius.medium,
  },
  rememberText: {
    flex: 1,
    gap: Spacing.half,
  },
  footer: {
    alignItems: 'center',
    gap: Spacing.one + Spacing.half,
    paddingHorizontal: Spacing.three,
  },
  footerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.one + Spacing.half,
  },
  centered: {
    textAlign: 'center',
  },
});
