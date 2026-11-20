import * as WebBrowser from 'expo-web-browser';
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
import { useResolvedColorScheme, useTheme } from '@/hooks/use-theme';
import { BACKGROUND_SYNC_EXPLANATION, legalPageUrl, SIGN_IN_DISCLOSURE } from '@/lib/privacy';

/**
 * The login: the WeCIC wordmark on a soft blue glow, two fields, one light
 * button. Before the button it says what the WeCIC server does with the
 * password and offers background sync, off by default. WeCIC is
 * unofficial, so it never shows the university's logo and says it is not
 * affiliated with the campus.
 */
export default function SignInScreen() {
  const theme = useTheme();
  const scheme = useResolvedColorScheme();
  const { state, signIn, rememberedUsername } = useSession();
  const passwordRef = useRef<TextInputInstance>(null);
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [backgroundSync, setBackgroundSync] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const notice = state.status === 'signedOut' ? state.notice : null;
  const privacyUrl = legalPageUrl('privacy');

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
      await signIn(username.trim(), password, remember, backgroundSync);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : String(caught));
      setFieldErrors(caught instanceof ApiError ? caught.fieldErrors : {});
      setSubmitting(false);
    }
  }

  return (
    <View style={[styles.flex, { backgroundColor: theme.background }]}>
      <View pointerEvents="none" style={[styles.glow, { backgroundImage: Glow[scheme] }]} />
      <SafeAreaView style={styles.flex}>
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
          <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
            <View style={styles.brand}>
              <BrandLogo color={theme.text} />
              <View style={styles.heading}>
                <ThemedText type="display">Selamat datang</ThemedText>
                <ThemedText type="default" themeColor="textSecondary">
                  Masuk dengan akun portal kampus kamu.
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
                accessibilityHint="Masuk lagi otomatis saat sesi kampus berakhir. Kata sandi juga disimpan terenkripsi di penyimpanan aman perangkat ini."
                onPress={() => setRemember((current) => !current)}
                style={styles.remember}>
                <ThemedText type="bodyMedium" style={styles.flex}>
                  Tetap masuk
                </ThemedText>
                <Switch value={remember} onValueChange={setRemember} trackColor={{ true: theme.success, false: theme.elevated }} />
              </Pressable>

              <Pressable
                accessibilityRole="switch"
                accessibilityState={{ checked: backgroundSync }}
                accessibilityLabel="Nyalakan sinkronisasi di background"
                accessibilityHint={BACKGROUND_SYNC_EXPLANATION}
                onPress={() => setBackgroundSync((current) => !current)}
                style={styles.remember}>
                <View style={styles.optionText}>
                  <ThemedText type="bodyMedium">Nyalakan sinkronisasi di background</ThemedText>
                  <ThemedText type="caption" themeColor="textTertiary">
                    {BACKGROUND_SYNC_EXPLANATION}
                  </ThemedText>
                </View>
                <Switch value={backgroundSync} onValueChange={setBackgroundSync} trackColor={{ true: theme.success, false: theme.elevated }} />
              </Pressable>

              <View style={[styles.disclosure, { backgroundColor: theme.section }]} accessibilityRole="summary">
                {SIGN_IN_DISCLOSURE.map((line) => (
                  <View key={line} style={styles.disclosureRow}>
                    <Icon name="info" size={14} color={theme.textTertiary} />
                    <ThemedText type="caption" themeColor="textSecondary" style={styles.flex}>
                      {line}
                    </ThemedText>
                  </View>
                ))}
                {privacyUrl ? (
                  <Pressable
                    accessibilityRole="link"
                    accessibilityHint="Buka kebijakan privasi WeCIC di browser"
                    hitSlop={8}
                    onPress={() => void WebBrowser.openBrowserAsync(privacyUrl)}
                    style={styles.disclosureRow}>
                    <Icon name="arrowUpRight" size={14} color={theme.brand} />
                    <ThemedText type="smallBold" themeColor="brand">
                      Kebijakan privasi
                    </ThemedText>
                  </Pressable>
                ) : null}
              </View>

              <Button label={submitting ? 'Memeriksa ke portal…' : 'Masuk'} loading={submitting} onPress={submit} />
            </View>

            <View style={styles.footer}>
              <View style={styles.footerRow}>
                <Icon name="lock" size={13} color={theme.textQuaternary} />
                <ThemedText type="caption" themeColor="textQuaternary">
                  Data dari portal akademik kampus (my.cic.ac.id)
                </ThemedText>
              </View>
              <ThemedText type="caption" themeColor="textQuaternary" style={styles.centered}>
                Aplikasi unofficial, tidak berafiliasi dengan kampus mana pun.
              </ThemedText>
            </View>
          </ScrollView>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </View>
  );
}

/** A soft CIC-blue light from the top, fading into the canvas. */
const Glow = {
  dark: 'linear-gradient(180deg, rgba(3, 82, 161, 0.42) 0%, rgba(3, 82, 161, 0.12) 45%, rgba(3, 82, 161, 0) 100%)',
  light: 'linear-gradient(180deg, rgba(3, 82, 161, 0.16) 0%, rgba(3, 82, 161, 0.05) 45%, rgba(3, 82, 161, 0) 100%)',
} as const;

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
    gap: Spacing.four,
    paddingHorizontal: Spacing.four,
    paddingTop: Spacing.six,
    paddingBottom: Spacing.four,
    width: '100%',
    maxWidth: MaxContentWidth,
    alignSelf: 'center',
  },
  brand: {
    gap: Spacing.three,
  },
  heading: {
    gap: Spacing.one,
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
  optionText: {
    flex: 1,
    gap: Spacing.half,
  },
  disclosure: {
    gap: Spacing.two,
    padding: Spacing.three - Spacing.one,
    borderRadius: Radius.large,
  },
  disclosureRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.two,
  },
  footer: {
    alignItems: 'center',
    gap: Spacing.one,
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
