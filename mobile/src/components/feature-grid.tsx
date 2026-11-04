import { useRouter } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';

import { Icon } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useElevation, useTheme } from '@/hooks/use-theme';
import type { Feature } from '@/lib/features';

/** Services as round tiles, four to a row, each with its name under it. */
export function FeatureGrid({ features, badges = {} }: { features: Feature[]; badges?: Record<string, string | null> }) {
  const theme = useTheme();
  const elevation = useElevation();
  const router = useRouter();

  return (
    <View style={styles.grid}>
      {features.map((feature) => {
        const badge = badges[feature.key] ?? null;

        return (
          <Pressable
            key={feature.key}
            accessibilityRole="button"
            accessibilityLabel={badge ? `${feature.title}, ${badge}` : feature.title}
            accessibilityHint={feature.description}
            onPress={() => router.push(feature.href)}
            style={styles.item}>
            {({ pressed }) => (
              <>
                <View style={[styles.tile, { backgroundColor: pressed ? theme.elevatedPressed : theme.section, boxShadow: elevation.raised }]}>
                  <Icon name={feature.icon} size={24} color={theme.text} />
                </View>
                <ThemedText type="caption" style={styles.label} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.8}>
                  {feature.title}
                </ThemedText>
                {badge ? (
                  <View style={[styles.badge, { backgroundColor: theme.badge, borderColor: theme.background }]}>
                    <ThemedText type="caption" style={{ color: theme.onColor }}>
                      {badge}
                    </ThemedText>
                  </View>
                ) : null}
              </>
            )}
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    rowGap: Spacing.three,
  },
  item: {
    width: '25%',
    alignItems: 'center',
    gap: Spacing.two,
    paddingHorizontal: Spacing.one,
  },
  tile: {
    width: '100%',
    height: 60,
    borderRadius: Radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
  },
  label: {
    fontSize: 13,
  },
  badge: {
    position: 'absolute',
    top: -6,
    right: 2,
    minWidth: 22,
    paddingHorizontal: Spacing.two - Spacing.half,
    paddingVertical: Spacing.half,
    borderRadius: Radius.pill,
    borderWidth: 2,
    alignItems: 'center',
  },
});
