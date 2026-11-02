import { NativeTabs } from 'expo-router/native-tabs';

import { useTheme } from '@/hooks/use-theme';
import { useUnreadCount } from '@/hooks/use-unread-count';
import { useNotificationTaps } from '@/push/use-notification-taps';

/** Icon-only tabs; each label stays for VoiceOver and TalkBack. */
export default function AppTabs() {
  const theme = useTheme();
  const unreadCount = useUnreadCount();

  useNotificationTaps();

  return (
    <NativeTabs
      backgroundColor={theme.section}
      indicatorColor={theme.elevated}
      tintColor={theme.text}
      iconColor={{ default: theme.textSecondary, selected: theme.text }}
      labelVisibilityMode="unlabeled"
      badgeBackgroundColor={theme.badge}>
      <NativeTabs.Trigger name="index">
        <NativeTabs.Trigger.Label hidden>Beranda</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'house', selected: 'house.fill' }} md="home" />
      </NativeTabs.Trigger>

      <NativeTabs.Trigger name="classes">
        <NativeTabs.Trigger.Label hidden>Kelas</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'book.closed', selected: 'book.closed.fill' }} md="menu_book" />
      </NativeTabs.Trigger>

      <NativeTabs.Trigger name="notifications">
        <NativeTabs.Trigger.Label hidden>Notifikasi</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'bell', selected: 'bell.fill' }} md="notifications" />
        {unreadCount > 0 ? (
          <NativeTabs.Trigger.Badge>{unreadCount > 99 ? '99+' : String(unreadCount)}</NativeTabs.Trigger.Badge>
        ) : null}
      </NativeTabs.Trigger>

      <NativeTabs.Trigger name="more">
        <NativeTabs.Trigger.Label hidden>Profil</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'person.crop.circle', selected: 'person.crop.circle.fill' }} md="account_circle" />
      </NativeTabs.Trigger>
    </NativeTabs>
  );
}
