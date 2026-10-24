import { Image } from 'expo-image';
import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Switch, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { useSession } from '@/auth/session-provider';
import { Button } from '@/components/button';
import { Notice } from '@/components/notice';
import { TextField } from '@/components/text-field';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
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
    <SafeAreaView style={[styles.safeArea, { backgroundColor: theme.background }]}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.safeArea}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <View style={styles.brand}>
            <Image
              source={require('@/assets/images/ucic-shield.png')}
              style={styles.logo}
              contentFit="contain"
              accessibilityIgnoresInvertColors
            />
            <ThemedText type="title">MyCIC</ThemedText>
            <ThemedText type="small" themeColor="textSecondary" style={styles.centered}>
              Masuk dengan NIM dan kata sandi portal kampus.
            </ThemedText>
          </View>

          {notice ? <Notice message={notice} tone="warning" /> : null}
          {error ? <Notice message={error} tone="error" /> : null}

          <View style={styles.form}>
            <TextField
              label="NIM"
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
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              autoComplete="current-password"
              textContentType="password"
              returnKeyType="go"
              onSubmitEditing={submit}
              error={fieldErrors.password}
            />
            <View style={styles.remember}>
              <View style={styles.rememberText}>
                <ThemedText type="smallBold">Tetap masuk</ThemedText>
                <ThemedText type="caption" themeColor="textTertiary">
                  Kata sandi disimpan aman di perangkat ini, jadi MyCIC bisa masuk lagi sendiri saat sesi kampus
                  berakhir.
                </ThemedText>
              </View>
              <Switch
                value={remember}
                onValueChange={setRemember}
                trackColor={{ true: theme.primary }}
                accessibilityLabel="Tetap masuk"
              />
            </View>
            <Button
              label={submitting ? 'Masuk…' : 'Masuk'}
              loading={submitting}
              disabled={username.trim() === '' || password === ''}
              onPress={submit}
            />
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: {
    flex: 1,
  },
  content: {
    flexGrow: 1,
    justifyContent: 'center',
    gap: Spacing.four,
    padding: Spacing.four,
    width: '100%',
    maxWidth: 480,
    alignSelf: 'center',
  },
  brand: {
    alignItems: 'center',
    gap: Spacing.two,
  },
  logo: {
    width: 64,
    height: 98,
  },
  centered: {
    textAlign: 'center',
  },
  form: {
    gap: Spacing.three,
  },
  remember: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
  },
  rememberText: {
    flex: 1,
    gap: Spacing.half,
  },
});
