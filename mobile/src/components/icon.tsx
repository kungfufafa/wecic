import { SymbolView, type SymbolViewProps } from 'expo-symbols';
import type { StyleProp, ViewStyle } from 'react-native';

import { useTheme } from '@/hooks/use-theme';

type PlatformSymbol = Extract<SymbolViewProps['name'], object>;

/** Every icon the app draws, as an SF Symbol on iOS and a Material Symbol elsewhere. */
const ICONS = {
  home: { ios: 'house.fill', android: 'home', web: 'home' },
  calendar: { ios: 'calendar', android: 'calendar_today', web: 'calendar_today' },
  calendarEmpty: { ios: 'calendar.badge.minus', android: 'event_busy', web: 'event_busy' },
  clock: { ios: 'clock', android: 'schedule', web: 'schedule' },
  location: { ios: 'mappin', android: 'location_on', web: 'location_on' },
  person: { ios: 'person', android: 'person', web: 'person' },
  idCard: { ios: 'person.text.rectangle', android: 'badge', web: 'badge' },
  book: { ios: 'book.closed.fill', android: 'menu_book', web: 'menu_book' },
  graduation: { ios: 'graduationcap.fill', android: 'school', web: 'school' },
  bell: { ios: 'bell.fill', android: 'notifications', web: 'notifications' },
  bellOff: { ios: 'bell.slash', android: 'notifications_off', web: 'notifications_off' },
  check: { ios: 'checkmark.circle.fill', android: 'check_circle', web: 'check_circle' },
  checkAll: { ios: 'checkmark.circle', android: 'done_all', web: 'done_all' },
  chevronRight: { ios: 'chevron.right', android: 'chevron_right', web: 'chevron_right' },
  arrowRight: { ios: 'arrow.right', android: 'arrow_forward', web: 'arrow_forward' },
  lock: { ios: 'lock.fill', android: 'lock', web: 'lock' },
  eye: { ios: 'eye', android: 'visibility', web: 'visibility' },
  eyeOff: { ios: 'eye.slash', android: 'visibility_off', web: 'visibility_off' },
  phone: { ios: 'iphone', android: 'smartphone', web: 'smartphone' },
  server: { ios: 'server.rack', android: 'dns', web: 'dns' },
  signOut: { ios: 'rectangle.portrait.and.arrow.right', android: 'logout', web: 'logout' },
  offline: { ios: 'wifi.slash', android: 'cloud_off', web: 'cloud_off' },
  sync: { ios: 'arrow.triangle.2.circlepath', android: 'sync', web: 'sync' },
  warning: { ios: 'exclamationmark.triangle.fill', android: 'warning', web: 'warning' },
  info: { ios: 'info.circle.fill', android: 'info', web: 'info' },
  error: { ios: 'exclamationmark.octagon.fill', android: 'error', web: 'error' },
  inbox: { ios: 'tray', android: 'inbox', web: 'inbox' },
  bolt: { ios: 'bolt.fill', android: 'bolt', web: 'bolt' },
  bellOutline: { ios: 'bell', android: 'notifications', web: 'notifications' },
  bookOutline: { ios: 'book.closed', android: 'menu_book', web: 'menu_book' },
  personCircle: { ios: 'person.crop.circle', android: 'account_circle', web: 'account_circle' },
  search: { ios: 'magnifyingglass', android: 'search', web: 'search' },
  close: { ios: 'xmark', android: 'close', web: 'close' },
  more: { ios: 'ellipsis', android: 'more_horiz', web: 'more_horiz' },
  globe: { ios: 'globe', android: 'language', web: 'language' },
  arrowUpRight: { ios: 'arrow.up.right', android: 'north_east', web: 'north_east' },
  clockFill: { ios: 'clock.fill', android: 'schedule', web: 'schedule' },
  back: { ios: 'chevron.left', android: 'arrow_back', web: 'arrow_back' },
  call: { ios: 'phone.fill', android: 'call', web: 'call' },
  chat: { ios: 'message.fill', android: 'chat', web: 'chat' },
  mail: { ios: 'envelope.fill', android: 'mail', web: 'mail' },
  document: { ios: 'doc.text.fill', android: 'description', web: 'description' },
  download: { ios: 'arrow.down.circle', android: 'download', web: 'download' },
  upload: { ios: 'arrow.up.doc.fill', android: 'upload_file', web: 'upload_file' },
  wallet: { ios: 'creditcard.fill', android: 'credit_card', web: 'credit_card' },
  chart: { ios: 'chart.bar.fill', android: 'bar_chart', web: 'bar_chart' },
  list: { ios: 'list.bullet.rectangle.fill', android: 'list_alt', web: 'list_alt' },
  megaphone: { ios: 'megaphone.fill', android: 'campaign', web: 'campaign' },
  award: { ios: 'rosette', android: 'military_tech', web: 'military_tech' },
  graduationOutline: { ios: 'graduationcap', android: 'school', web: 'school' },
  library: { ios: 'books.vertical.fill', android: 'local_library', web: 'local_library' },
  sparkles: { ios: 'sparkles', android: 'auto_awesome', web: 'auto_awesome' },
  archive: { ios: 'archivebox.fill', android: 'inventory_2', web: 'inventory_2' },
  key: { ios: 'key.fill', android: 'key', web: 'key' },
  qr: { ios: 'qrcode.viewfinder', android: 'qr_code_scanner', web: 'qr_code_scanner' },
  checklist: { ios: 'checklist', android: 'checklist', web: 'checklist' },
  task: { ios: 'pencil.and.list.clipboard', android: 'assignment', web: 'assignment' },
  outbox: { ios: 'tray.and.arrow.up.fill', android: 'outbox', web: 'outbox' },
  biodata: { ios: 'person.text.rectangle.fill', android: 'badge', web: 'badge' },
  copy: { ios: 'doc.on.doc.fill', android: 'content_copy', web: 'content_copy' },
  grid: { ios: 'square.grid.2x2.fill', android: 'apps', web: 'apps' },
  trash: { ios: 'trash', android: 'delete', web: 'delete' },
  cancel: { ios: 'xmark.circle.fill', android: 'cancel', web: 'cancel' },
  alarm: { ios: 'clock.badge.exclamationmark', android: 'alarm', web: 'alarm' },
  link: { ios: 'link', android: 'link', web: 'link' },
  send: { ios: 'paperplane.fill', android: 'send', web: 'send' },
  bookText: { ios: 'text.book.closed.fill', android: 'book', web: 'book' },
  calendarClock: { ios: 'calendar.badge.clock', android: 'event_upcoming', web: 'event_upcoming' },
  alert: { ios: 'exclamationmark.circle.fill', android: 'error', web: 'error' },
  refresh: { ios: 'arrow.clockwise', android: 'refresh', web: 'refresh' },
  sun: { ios: 'sun.max.fill', android: 'light_mode', web: 'light_mode' },
  moon: { ios: 'moon.fill', android: 'dark_mode', web: 'dark_mode' },
  appearance: { ios: 'circle.lefthalf.filled', android: 'contrast', web: 'contrast' },
  checkmark: { ios: 'checkmark', android: 'check', web: 'check' },
} as const satisfies Record<string, PlatformSymbol>;

export type IconName = keyof typeof ICONS;

type IconProps = {
  name: IconName;
  size?: number;
  color?: string;
  style?: StyleProp<ViewStyle>;
};

export function Icon({ name, size = 20, color, style }: IconProps) {
  const theme = useTheme();

  return (
    <SymbolView
      name={ICONS[name]}
      size={size}
      tintColor={color ?? theme.textSecondary}
      resizeMode="scaleAspectFit"
      style={[{ width: size, height: size }, style]}
    />
  );
}
