import { useRouter } from 'expo-router';
import type { ReactNode } from 'react';
import { Platform, RefreshControl, ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { IconButton } from '@/components/icon-button';
import { OfflineStrip } from '@/components/offline-strip';
import { ThemedText } from '@/components/themed-text';
import { BottomTabInset, MaxContentWidth, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type ScreenProps = {
  /** A large title; leave it out when `header` draws its own. */
  title?: string;
  /** A quiet line under the title. */
  subtitle?: string | null;
  /** Drawn to the right of the title. */
  accessory?: ReactNode;
  /** A header of the screen's own in place of the title row. */
  header?: ReactNode;
  /** A screen pushed over the tabs: a round back button before the title. */
  back?: boolean;
  refreshing?: boolean;
  onRefresh?: () => void;
  /** When the main data on screen came from the server, for the offline strip. */
  dataSavedAt?: number | null;
  children: ReactNode;
};

/** A page on the black canvas: its header, then sections that scroll and pull to refresh. */
export function Screen({ title, subtitle, accessory, header, back = false, refreshing = false, onRefresh, dataSavedAt, children }: ScreenProps) {
  const theme = useTheme();
  const router = useRouter();

  function goBack() {
    if (router.canGoBack()) {
      router.back();
    } else {
      router.replace('/');
    }
  }

  return (
    <SafeAreaView edges={['top']} style={[styles.safeArea, { backgroundColor: theme.background }]}>
      <ScrollView
        contentContainerStyle={[styles.content, back && styles.pushedContent]}
        contentInsetAdjustmentBehavior="automatic"
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="on-drag"
        refreshControl={
          onRefresh ? (
            <RefreshControl
              refreshing={refreshing}
              onRefresh={onRefresh}
              tintColor={theme.textSecondary}
              colors={[theme.onLight]}
              progressBackgroundColor={theme.light}
            />
          ) : undefined
        }>
        {header ??
          (title || back ? (
            <View style={styles.header}>
              {back ? (
                <View style={styles.backRow}>
                  <IconButton icon="back" label="Kembali" size={44} onPress={goBack} />
                  {accessory ? <View style={styles.backAccessory}>{accessory}</View> : null}
                </View>
              ) : null}
              <View style={styles.titleRow}>
                <View style={styles.titleText}>
                  {title ? (
                    <ThemedText type={back ? 'heading' : 'title'} accessibilityRole="header">
                      {title}
                    </ThemedText>
                  ) : null}
                  {subtitle ? (
                    <ThemedText type="small" themeColor="textTertiary">
                      {subtitle}
                    </ThemedText>
                  ) : null}
                </View>
                {back ? null : accessory}
              </View>
            </View>
          ) : null)}
        <OfflineStrip savedAt={dataSavedAt} onRetry={onRefresh} />
        {children}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: {
    flex: 1,
  },
  content: {
    gap: Spacing.three - Spacing.one,
    paddingHorizontal: Spacing.three,
    // The web preview's tab bar floats over the top of the page.
    paddingTop: Platform.OS === 'web' ? Spacing.six + Spacing.three : Spacing.two,
    paddingBottom: BottomTabInset + Spacing.five,
    width: '100%',
    maxWidth: MaxContentWidth,
    alignSelf: 'center',
  },
  pushedContent: {
    paddingTop: Spacing.two,
    paddingBottom: Spacing.six,
  },
  header: {
    gap: Spacing.three - Spacing.one,
    paddingBottom: Spacing.one,
  },
  backRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  backAccessory: {
    flexShrink: 1,
  },
  titleRow: {
    minHeight: 44,
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    paddingHorizontal: Spacing.one,
  },
  titleText: {
    flex: 1,
    gap: Spacing.half,
  },
});
