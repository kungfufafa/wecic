import { Image } from 'expo-image';
import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Switch, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Notice } from '@/components/notice';
import { Panel, Section } from '@/components/section';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { MaxContentWidth, Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

export default function SignInScreen() {
  const theme = useTheme();
  const { state, signIn, rememberedUsername } = useSession();
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
    setSubmitting(true);
    setError(null);
    setFieldErrors({});

    try {
      await signIn(username.trim(), password, remember);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : String(caught));
      setFieldErrors(caught instanceof ApiError ? caught.fieldErrors : {});
      setSubmitting(false);
    }
  }

  return (
    <SafeAreaView style={[styles.flex, { backgroundColor: theme.background }]}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
          <View style={styles.brand}>
            <View style={[styles.logoTile, { backgroundColor: theme.light }]}>
              <Image
                source={require('@/assets/images/ucic-shield.png')}
                style={styles.logo}
                contentFit="contain"
                accessibilityIgnoresInvertColors
              />
            </View>
            <ThemedText type="display">MyCIC</ThemedText>
            <ThemedText type="small" themeColor="textTertiary" style={styles.centered}>
              Portal mahasiswa Universitas Catur Insan Cendekia
            </ThemedText>
          </View>

          {notice ? <Notice message={notice} tone="warning" /> : null}
          {error ? <Notice message={error} tone="error" /> : null}

          <Section label="Masuk dengan akun portal">
            <Panel style={styles.form}>
              <TextField
                label="NIM"
                icon="idCard"
                placeholder="Nomor induk mahasiswa"
                value={username}
                onChangeText={setUsername}
                autoCapitalize="none"
                autoCorrect={false}
                autoComplete="username"
                textContentType="username"
                keyboardType="number-pad"
                returnKeyType="next"
                error={fieldErrors.username}
              />
              <TextField
                label="Kata sandi"
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
              <View style={styles.remember}>
                <View style={styles.rememberText}>
                  <ThemedText type="bodyMedium">Tetap masuk</ThemedText>
                  <ThemedText type="caption" themeColor="textTertiary">
                    Kata sandi disimpan aman di perangkat ini, jadi MyCIC bisa masuk lagi sendiri saat sesi kampus berakhir.
                  </ThemedText>
                </View>
                <Switch
                  value={remember}
                  onValueChange={setRemember}
                  trackColor={{ true: theme.success, false: theme.elevated }}
                  accessibilityLabel="Tetap masuk"
                />
              </View>
            </Panel>
          </Section>

          <Button
            label={submitting ? 'Memeriksa ke portal…' : 'Masuk'}
            loading={submitting}
            disabled={username.trim() === '' || password === ''}
            onPress={submit}
          />

          <ThemedText type="caption" themeColor="textQuaternary" style={styles.centered}>
            NIM dan kata sandi dipakai untuk masuk ke portal kampus my.cic.ac.id.
          </ThemedText>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  content: {
    flexGrow: 1,
    justifyContent: 'center',
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three,
    paddingBottom: Spacing.five,
    width: '100%',
    maxWidth: MaxContentWidth,
    alignSelf: 'center',
  },
  brand: {
    alignItems: 'center',
    gap: Spacing.two,
    paddingVertical: Spacing.four,
  },
  logoTile: {
    width: 76,
    height: 76,
    borderRadius: Radius.panel,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: Spacing.two,
  },
  logo: {
    width: 36,
    height: 55,
  },
  centered: {
    textAlign: 'center',
  },
  form: {
    gap: Spacing.three + Spacing.one,
  },
  remember: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    paddingHorizontal: Spacing.one,
  },
  rememberText: {
    flex: 1,
    gap: Spacing.half,
  },
});
