import { NativeTabs } from 'expo-router/native-tabs';

import { useResolvedColorScheme, useTheme } from '@/hooks/use-theme';
import { useUnreadCount } from '@/hooks/use-unread-count';
import { useNotificationTaps } from '@/push/use-notification-taps';

/**
 * The tabs of the web's mobile navigation: Beranda, Kuliah, Absen in the
 * middle (on its own surface from iOS 27), Akademik and Lainnya. Icons
 * only; each label stays for VoiceOver and TalkBack. Unread
 * notifications show on Beranda, where the bell opens them.
 */
export default function AppTabs() {
  const theme = useTheme();
  const scheme = useResolvedColorScheme();
  const unreadCount = useUnreadCount();

  useNotificationTaps();

  return (
    <NativeTabs
      backgroundColor={theme.section}
      indicatorColor={scheme === 'dark' ? theme.panel : theme.elevatedPressed}
      tintColor={theme.text}
      iconColor={{ default: theme.textSecondary, selected: theme.text }}
      labelVisibilityMode="unlabeled"
      badgeBackgroundColor={theme.badge}>
      <NativeTabs.Trigger name="index">
        <NativeTabs.Trigger.Label hidden>Beranda</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'house', selected: 'house.fill' }} md="home" />
        {unreadCount > 0 ? <NativeTabs.Trigger.Badge>{unreadCount > 99 ? '99+' : String(unreadCount)}</NativeTabs.Trigger.Badge> : null}
      </NativeTabs.Trigger>

      <NativeTabs.Trigger name="classes">
        <NativeTabs.Trigger.Label hidden>Kuliah</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'book.closed', selected: 'book.closed.fill' }} md="menu_book" />
      </NativeTabs.Trigger>

      <NativeTabs.Trigger name="attendance" prominent>
        <NativeTabs.Trigger.Label hidden>Absen</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf="qrcode.viewfinder" md="qr_code_scanner" />
      </NativeTabs.Trigger>

      <NativeTabs.Trigger name="academic">
        <NativeTabs.Trigger.Label hidden>Akademik</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'graduationcap', selected: 'graduationcap.fill' }} md="school" />
      </NativeTabs.Trigger>

      <NativeTabs.Trigger name="more">
        <NativeTabs.Trigger.Label hidden>Lainnya</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'square.grid.2x2', selected: 'square.grid.2x2.fill' }} md="apps" />
      </NativeTabs.Trigger>
    </NativeTabs>
  );
}
