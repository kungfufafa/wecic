import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';

import { Icon } from '@/components/icon';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useElevation, useTheme } from '@/hooks/use-theme';
import type { Feature } from '@/lib/features';

const PerPage = 4;

/**
 * Services as round tiles, one row of four at a time; more rows sit on
 * pages to swipe to, with dots under them.
 */
export function FeatureGrid({ features, badges = {} }: { features: Feature[]; badges?: Record<string, string | null> }) {
  const theme = useTheme();
  const [width, setWidth] = useState(0);
  const [page, setPage] = useState(0);
  const pages = Array.from({ length: Math.ceil(features.length / PerPage) }, (_, index) => features.slice(index * PerPage, (index + 1) * PerPage));

  return (
    <View style={styles.wrap} onLayout={(event) => setWidth(event.nativeEvent.layout.width)}>
      <ScrollView
        horizontal
        pagingEnabled
        showsHorizontalScrollIndicator={false}
        scrollEnabled={pages.length > 1}
        onMomentumScrollEnd={(event) => {
          if (width > 0) {
            setPage(Math.round(event.nativeEvent.contentOffset.x / width));
          }
        }}>
        {pages.map((pageFeatures, index) => (
          <View key={index} style={[styles.page, { width: width || undefined }]}>
            {pageFeatures.map((feature) => (
              <FeatureTile key={feature.key} feature={feature} badge={badges[feature.key] ?? null} />
            ))}
          </View>
        ))}
      </ScrollView>
      {pages.length > 1 ? (
        <View style={styles.dots} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
          {pages.map((_, index) => (
            <View
              key={index}
              style={[styles.dot, index === page && styles.dotActive, { backgroundColor: index === page ? theme.text : theme.textQuaternary }]}
            />
          ))}
        </View>
      ) : null}
    </View>
  );
}

function FeatureTile({ feature, badge }: { feature: Feature; badge: string | null }) {
  const theme = useTheme();
  const elevation = useElevation();
  const router = useRouter();

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={badge ? `${feature.title}, ${badge}` : feature.title}
      accessibilityHint={feature.description}
      onPress={() => router.push(feature.href)}
      style={styles.item}>
      {({ pressed }) => (
        <>
          <View style={[styles.tile, { backgroundColor: pressed ? theme.elevatedPressed : theme.section, boxShadow: elevation.raised }]}>
            <Icon name={feature.icon} size={22} color={theme.text} />
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
}

const styles = StyleSheet.create({
  wrap: {
    gap: Spacing.two,
  },
  page: {
    flexDirection: 'row',
  },
  item: {
    width: '25%',
    alignItems: 'center',
    gap: Spacing.one + Spacing.half,
    paddingHorizontal: Spacing.one,
    paddingTop: Spacing.one + Spacing.half,
  },
  tile: {
    width: '100%',
    height: 48,
    borderRadius: Radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
  },
  label: {
    fontSize: 13,
  },
  badge: {
    position: 'absolute',
    top: 0,
    right: 2,
    minWidth: 22,
    paddingHorizontal: Spacing.two - Spacing.half,
    paddingVertical: Spacing.half,
    borderRadius: Radius.pill,
    borderWidth: 2,
    alignItems: 'center',
  },
  dots: {
    flexDirection: 'row',
    justifyContent: 'center',
    gap: Spacing.one + Spacing.half,
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  dotActive: {
    width: 18,
  },
});
