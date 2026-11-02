import { useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Fragment } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { webUrl } from '@/api/config';
import type { ActionItem } from '@/api/types';
import { Icon, type IconName } from '@/components/icon';
import { Divider } from '@/components/section';
import { ThemedText } from '@/components/themed-text';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { routeForPortalUrl } from '@/push/notification-links';
import { daysLeftLabel } from '@/lib/today';

/** What the student should do next, most urgent first, each opening where it is done. */
export function ActionItemRows({ items }: { items: ActionItem[] }) {
  return (
    <View>
      {items.map((item, index) => (
        <Fragment key={item.id}>
          {index > 0 ? <Divider inset={Spacing.three + 40 + Spacing.three - Spacing.one} /> : null}
          <ActionItemRow item={item} />
        </Fragment>
      ))}
    </View>
  );
}

function ActionItemRow({ item }: { item: ActionItem }) {
  const theme = useTheme();
  const router = useRouter();
  const look: Record<string, { icon: IconName; color: string; tint: string }> = {
    critical: { icon: 'alert', color: theme.danger, tint: theme.dangerTint },
    warning: { icon: 'warning', color: theme.warning, tint: theme.warningTint },
    info: { icon: 'info', color: theme.brand, tint: theme.brandTint },
  };
  const severity = look[item.severity] ?? look.info;
  const due = daysLeftLabel(item.days_left);
  const route = item.cta_external ? null : routeForPortalUrl(item.cta_url);
  const external = item.cta_external && item.cta_url ? item.cta_url : null;
  const website = webUrl();

  function open() {
    if (route) {
      router.push(route);
    } else if (external) {
      void WebBrowser.openBrowserAsync(external);
    } else if (item.cta_url && website) {
      void WebBrowser.openBrowserAsync(`${website}${item.cta_url.startsWith('/') ? '' : '/'}${item.cta_url}`);
    }
  }

  const pressable = route !== null || external !== null || (Boolean(item.cta_url) && website !== null);

  return (
    <Pressable
      accessibilityRole={pressable ? 'button' : undefined}
      disabled={!pressable}
      onPress={open}
      style={({ pressed }) => [styles.row, pressed && { backgroundColor: theme.section }]}>
      <View style={[styles.iconCircle, { backgroundColor: severity.tint }]}>
        <Icon name={severity.icon} size={18} color={severity.color} />
      </View>
      <View style={styles.text}>
        <ThemedText type="smallBold">{item.title}</ThemedText>
        {item.description ? (
          <ThemedText type="small" themeColor="textTertiary" numberOfLines={3}>
            {item.description}
          </ThemedText>
        ) : null}
        {due || item.cta_label ? (
          <View style={styles.meta}>
            {due ? (
              <ThemedText type="caption" style={{ color: severity.color }}>
                {due}
              </ThemedText>
            ) : null}
            {item.cta_label && pressable ? (
              <ThemedText type="caption" themeColor="brand">
                {item.cta_label}
              </ThemedText>
            ) : null}
          </View>
        ) : null}
      </View>
      {pressable ? <Icon name="chevronRight" size={14} color={theme.textQuaternary} /> : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.three - Spacing.one,
    padding: Spacing.three,
  },
  iconCircle: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
  },
  text: {
    flex: 1,
    gap: Spacing.one,
  },
  meta: {
    flexDirection: 'row',
    gap: Spacing.three,
    marginTop: Spacing.half,
  },
});
