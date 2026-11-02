import { Pressable, StyleSheet, View } from 'react-native';

import { Icon, type IconName } from '@/components/icon';
import { useTheme } from '@/hooks/use-theme';

type IconButtonProps = {
  icon: IconName;
  label: string;
  onPress: () => void;
  size?: number;
  /** A red dot in the corner: something unread behind it. */
  badge?: boolean;
};

/** A round charcoal button with one icon. */
export function IconButton({ icon, label, onPress, size = 48, badge = false }: IconButtonProps) {
  const theme = useTheme();

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      hitSlop={6}
      onPress={onPress}
      style={({ pressed }) => [
        styles.button,
        { width: size, height: size, borderRadius: size / 2, backgroundColor: pressed ? theme.elevatedPressed : theme.section },
      ]}>
      <Icon name={icon} size={size * 0.44} color={theme.text} />
      {badge ? <View style={[styles.badge, { backgroundColor: theme.badge, borderColor: theme.section }]} /> : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  badge: {
    position: 'absolute',
    top: 10,
    right: 11,
    width: 10,
    height: 10,
    borderRadius: 5,
    borderWidth: 2,
  },
});
